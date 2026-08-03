# External Apply Target Authorization — Design

Date: 2026-08-02
Status: Implemented and locally verified; integration and final-SHA gates remain open

## Goal

Prevent a Flavor Agent activity row from authorizing one WordPress entity through its `document` fields while an external apply or undo reads or writes a different entity named by `target`.

The fix belongs at executor dispatch because that is the last shared boundary that knows the concrete target a surface implementation will use. Existing request, REST, and ability permission callbacks remain necessary, but they cannot substitute for authorization of the actual write target.

## Scope

This slice changes only target identity and capability authorization for the four governed executor families:

- Global Styles and Style Book;
- templates;
- template parts;
- post blocks.

It covers approval-time execution through `PendingApplyDecision` and server-side undo through `ApplyAbilities::undo_activity()` on current `master`.

It does not import PR #72's broader undo coordinator, attestation architecture, provider, UI, or release-document changes. The narrow apply/revert attestation call-site edits required to consume the canonical target are in scope. It does not change operation validation, drift comparison, activity visibility, ranking, or the permissions required to request a pending apply.

This design defines the scope of any separately authorized local implementation. It does not itself authorize implementation, committing, pushing, opening or updating a pull request, merging, deploying, tagging, or publishing. Each action requires the separate approval appropriate to it.

## Current Failure

Activity access resolves authorization from normalized `document.scopeKey`, `document.postType`, and `document.entityId`. Executors resolve and mutate from surface-specific `target` fields. Both structures are persisted data, and current dispatch does not prove they name the same subject.

For post blocks this is an object-level privilege escalation: `edit_post:100` can authorize the row while `target.postId = 200` drives the executor against post 200. A matching attacker-supplied baseline can satisfy drift checks because freshness establishes state equality, not authority.

Theme-territory surfaces use the site-wide `edit_theme_options` capability, so divergent targets do not widen capability reach in the same way. They still corrupt the audit/approval contract, so identity coherence is enforced consistently across every executor.

## Contract

Extend `ExternalApplyExecutor` with one canonical identity seam and one authorization seam:

```php
/**
 * @return array{
 *     target: array<string, int|string>,
 *     document: array{entityId: string, postType: string, scopeKey: string}
 * }|\WP_Error
 */
public static function resolve_target_identity( array $entry ): array|\WP_Error;

public static function authorize_target( array $entry ): true|\WP_Error;
```

`resolve_target_identity()` is the only parser for security-relevant target fields in an executor. `authorize_target()`, `resolve_baseline()`, `execute()`, and `undo()` must all call that same resolver and use its returned `target`; none may independently select an alias or fall back to a different identifier. Successful execution returns the canonical target from this resolver, optionally enriched with non-authoritative labels such as title, slug, or area.

Apply and revert attestation construction must also consume the executor's canonical identity. For template parts, both attestation call sites use the returned `target.templatePartRef` directly; the existing `templatePartRef ?? templatePartId` fallback is removed. Public subject-state resolution receives that same canonical ref.

Each executor's resolver and authorizer must:

1. Parse its concrete target using the same normalization its read/write methods use.
2. Derive the canonical target plus the exact document identity expected for that target and surface.
3. Reject missing, malformed, contradictory, or non-canonical security fields rather than repairing the persisted row.
4. Have `authorize_target()` compare all three returned document fields with the persisted document using exact canonical strings.
5. Authorize the current user against the concrete target.
6. Return `true` only when identity and capability checks both pass.

Neither method may ask a Flavor Agent content collector for target state or inspect, parse, hash, compare, or mutate target content. WordPress core metadata and meta-capability calls needed to establish post type and `edit_post` authority are allowed; core may internally hydrate a complete post object, but the authorization code must not consume its `post_content`.

### Template-Part Alias Rule

`TemplatePartApplyExecutor::resolve_target_identity()` treats `target.templatePartId` as the required concrete executor target and `target.templatePartRef` as an optional alias. It trims both with the existing ref normalizer:

- `templatePartId` must be present and non-empty, preserving the current executor's target requirement;
- when `templatePartRef` is also present, it must be exactly equal to the ID after normalization;
- when `templatePartRef` is absent, the ID becomes the canonical ref and the returned alias is populated from it;
- a ref-only row, missing/empty ID, or unequal dual aliases fails with `flavor_agent_apply_target_mismatch`;
- the returned canonical target always contains both `templatePartRef` and `templatePartId` set to that one ref.

There is no fallback from a missing ID to a display-oriented ref and no preference between conflicting aliases. This single resolver supplies the ref used by authorization, baseline resolution, execute, undo, stored execution results, apply/revert attestations, and subject-state verification. A row can therefore never authorize ref A while an executor or attestation operates on ID B.

## Canonical Identity And Capability Rules

| Surface | Concrete target | Required document identity | Capability |
| --- | --- | --- | --- |
| `global-styles` | `target.globalStylesId` | `document.entityId` equals the ID, `document.postType` equals `global_styles`, and `document.scopeKey` equals `StyleAbilities::canonical_scope_key_for('global-styles', id)` | `edit_theme_options` |
| `style-book` | `target.globalStylesId` + `target.blockName` | ID equality, `document.postType` equals `global_styles`, and the scope key equals `StyleAbilities::canonical_scope_key_for('style-book', id, blockName)` | `edit_theme_options` |
| `template` | `target.templateRef` | `document.entityId` equals the normalized ref, `document.postType` equals `wp_template`, and `document.scopeKey` equals `wp_template:<ref>` | `edit_theme_options` |
| `template-part` | required `target.templatePartId`, with optional equal `target.templatePartRef` alias | `document.entityId` equals the canonical ref, `document.postType` equals `wp_template_part`, and `document.scopeKey` equals `wp_template_part:<ref>` | `edit_theme_options` |
| `post-blocks` | positive integer `target.postId`; `target.postType` must equal the actual post type for that ID | `document.entityId` equals the canonical decimal ID, `document.postType` equals the actual post type, and `document.scopeKey` equals `<actualPostType>:<id>` | `edit_post` for the target post ID |

Template and template-part baseline, execute, undo, and attestation-state reads must resolve the exact theme-qualified canonical ref. The existing repository's `wp_id`/slug compatibility fallback must not allow ref A to consume or mutate an entity whose resolved `id` is B; use an exact resolver or verify exact resolved-ID equality before reading content, and return `flavor_agent_apply_target_mismatch` on disagreement.

For post blocks, the resolver parses the positive target ID first, then obtains its actual post type through one narrowly wrapped core metadata seam such as `get_post_type( $post_id )`. That core call may hydrate the underlying post row; the enforceable boundary is that Flavor Agent does not call `ServerCollector::resolve_post_for_apply()`, inspect or transform `post_content`, compute a baseline, or mutate state. An absent target, an empty/non-canonical stored post type, or disagreement among `target.postType`, actual metadata, `document.postType`, and the scope-key prefix fails closed before `current_user_can( 'edit_post', $post_id )` is evaluated.

All surfaces require `document.postType`; it is never inferred from `surface` or `scopeKey` at this gate. Missing, malformed, non-canonical, or contradictory identity fields fail closed.

## Dispatch Order

### Approval

`PendingApplyDecision::decide()` keeps rejection behavior unchanged. For approval:

1. Load and validate the pending row.
2. Resolve the executor.
3. Call `authorize_target()`, which resolves the canonical identity and compares the complete document identity.
4. Only after success, resolve the live baseline and execute.
5. Build any attestation subject from the canonical execution target.
6. Persist the existing applied or failed transition.

Authorization therefore occurs before the first Flavor Agent target-content collection, state comparison, or mutation in `resolve_baseline()`.

### Undo

`ApplyAbilities::undo_activity()` keeps row lookup, surface support, executed-state, undo-state, and ordered-undo checks. Those lifecycle checks intentionally retain precedence: a non-executed, terminal, invalid-state, or non-topmost row returns its existing error without invoking target authorization. After those checks pass and immediately before calling the executor's `undo()` method, the service calls `authorize_target()`.

Authorization therefore occurs before the first Flavor Agent undo-content collection, state comparison, or write. Successful undo builds any revert-attestation subject from the same canonical identity before transitioning the row. This slice deliberately uses the current `master` call site and does not pull in PR #72's `UndoCoordinator` refactor.

## Failure Semantics

Use two stable error families:

- `flavor_agent_apply_target_mismatch`, HTTP 409: target/document identity is missing, malformed, or divergent.
- `flavor_agent_apply_target_forbidden`, HTTP 403: identity is coherent but the current user lacks the target capability.

Within `authorize_target()`, identity validation runs first. A divergent row returns `flavor_agent_apply_target_mismatch`; capability is evaluated only after the row proves it describes the target consistently.

That precedence is the contract of `authorize_target()` when the service reaches it. Public REST and Ability permission callbacks remain an outer layer and may reject first with their existing activity/permission 403 response. An outer denial does not call the decision or undo service, does not reveal whether persisted target fields mismatch, and does not transition the row.

On approval, when `PendingApplyDecision::decide()` reaches `authorize_target()`, either target error transitions the still-pending row to `apply.status = failed` through the existing transition API, preserving decision attribution and recording the bounded error code/message. No target content is read or written. If the outer permission callback rejects first, the row remains pending because the service never runs.

On undo, an outer permission denial, ordered-undo failure, or target-authorization error leaves the row and live target unchanged. When `authorize_target()` is reached, either target error is returned and the row remains `available`; live target state and undo metadata remain byte-for-byte unchanged. A user who lacks permission must not be able to terminalize another operator's undo. A malformed historical row remains visibly unresolved rather than being misreported as undone.

Messages identify the surface and corrective action without exposing private target content. Error codes, not prose, are the stable test and integration contract.

## Defense Boundaries

- Existing ability/REST permission callbacks continue to protect activity discovery and route access.
- Request-time capability, signature, validation, and baseline gates remain unchanged.
- Target authorization never treats a valid baseline, signature, approval, or administrator-authored row as authority.
- Executor read/write methods retain their own validation and must consume the same canonical target resolver even after the shared call-site authorization succeeds.
- No WordPress filter or caller-supplied executor override is added to the production authorization path. Tests observe the real registered executors through test-harness counters/hooks only.
- Adding a new executor will fail interface conformance until it supplies both canonical identity resolution and target authorization.

## Testing

Write regression tests before implementation.

### Post-blocks adversarial coverage

- Create an available activity whose document names editable post 100 and target names denied post 200. Undo returns `flavor_agent_apply_target_mismatch`, post 200 is byte-identical, and undo remains available.
- Create a pending activity with the same divergence. Approval records a failed row and neither post changes.
- Grant access to both posts while keeping identities divergent. The row still fails with `flavor_agent_apply_target_mismatch`, proving capability alone cannot bless a dishonest audit target.
- Give target post 200 an actual type of `page` while persisting `target.postType = post` and a matching dishonest `document`. Identity resolution fails before capability evaluation, baseline/content collection, or mutation.
- Exercise a coherent post-200 row directly under a user denied `edit_post:200`; target authorization returns `flavor_agent_apply_target_forbidden`, performs no Flavor Agent content collection or content use, and writes nothing.
- Use a coherent post-100 row and prove apply and undo still succeed.

The post-block tests count the core post-type lookup separately from Flavor Agent content resolution/use. A denied fixture may perform core post hydration for metadata or meta-cap mapping, but counters for `ServerCollector::resolve_post_for_apply()`, Flavor Agent inspection/parsing/hashing of `post_content`, and `wp_update_post` must remain zero.

### Theme-territory coverage

- Each executor accepts its canonical identity when `edit_theme_options` is present.
- Each executor rejects a divergent or missing `document.entityId`, `document.postType`, or `document.scopeKey` even when the capability is present.
- Each executor rejects a canonical identity when `edit_theme_options` is absent.
- Template-part cases cover equal dual aliases, accepted `templatePartId`-only rows, rejected `templatePartRef`-only rows, missing aliases, and unequal aliases. The ref-only and unequal-alias cases prove neither baseline/content resolver nor attestation recorder is called.
- Template and template-part cases seed a different theme-qualified entity with the same slug and prove repository compatibility fallback cannot satisfy the canonical target; baseline, execute, and undo fail before consuming or mutating that entity's content.
- Existing applied-state, drift, materialization, and attestation tests remain green.

### Contract and boundary coverage

- The registry returns only classes implementing the expanded interface.
- Approval invokes authorization before baseline resolution. Add test-harness-only counters or one-shot hooks around target-content collection, executor writes, and attestation recording; a denied fixture must record zero calls to all three while persisting the expected failed decision.
- Undo invokes authorization after ordered-undo validation but before executor undo. A blocked older row proves ordered-undo retains precedence and the authorizer is not reached; a topmost denied row proves content resolution, mutation, attestation, and activity transition are not reached.
- Exercise the outer REST/Ability permission callback separately from direct service calls: outer denial returns the existing permission error and leaves a pending/available row unchanged, while a permission-passed direct service path exposes the stable target error and its documented transition semantics.
- Error persistence contains bounded codes/messages and no target content.

The call-order seam belongs only in `tests/phpunit/bootstrap.php` or test fixtures. Do not add a production `apply_filters()` bypass around executor lookup, identity resolution, or authorization merely to make ordering mockable.

### Compatibility matrix

| Stored row class | Security-relevant shape | Result |
| --- | --- | --- |
| Current external pending/apply row | Complete document identity; template-part aliases both present and equal | Approval and later undo remain executable when capability and lifecycle checks pass. |
| Current external execution result | Canonical executor target and complete document identity | Undo and apply/revert attestation remain executable. |
| Editor-authored activity | It may carry only display-oriented `templatePartRef`, with no executor-owned `templatePartId` | Existing display, filtering, outcome reporting, and client-side undo behavior are unchanged. It remains outside the external executor contract: lifecycle or ordered-undo checks may reject first, and if the service reaches target authorization, the missing ID yields a mismatch before Flavor Agent executor content/state resolution. |
| Legacy governed template-part row with only `templatePartId` | One non-empty executor ID plus complete, matching canonical document identity | The resolver accepts it, emits both aliases with the same canonical ref, and applies all normal capability/lifecycle checks. |
| Historical template-part row with only `templatePartRef` | No executor-owned ID | Fails closed before target content/state resolution; the slice does not broaden it into a server-executable row. |
| Legacy governed row missing `document.postType`, entity ID, or canonical scope key | Incomplete security identity | Fails closed with `flavor_agent_apply_target_mismatch`; it remains stored for manual inspection and is not silently migrated. |
| Corrupted template-part row | Both aliases present but unequal | Fails closed before content resolution, capability evaluation, mutation, or attestation. |
| Post-block row whose stored type differs from actual target metadata | Internally coherent attacker-controlled fields but wrong actual post type | Fails closed before capability evaluation or content resolution. |

Primary suites:

```bash
composer run test:php -- tests/phpunit/ExternalApplyLifecycleTest.php
composer run test:php -- tests/phpunit/ApplyAbilitiesTest.php
composer run test:php -- tests/phpunit/ActivityPermissionsTest.php
composer run test:php -- tests/phpunit/StyleApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplateApplyExecutorTest.php
composer run test:php -- tests/phpunit/TemplatePartApplyExecutorTest.php
composer run test:php -- tests/phpunit/PostBlocksApplyExecutorTest.php
npm run verify -- --skip-e2e
npm run check:docs
git diff --check
```

Before merging the focused security PR, run the full canonical verifier with Plugin Check prerequisites available and the matching WordPress 7.0 approvals/undo browser harness. Inspect `output/verify/summary.json`; `incomplete` is not a passing release signal.

## Likely Files

- `inc/Apply/ExternalApplyExecutor.php`
- `inc/Apply/StyleApplyExecutor.php`
- `inc/Apply/TemplateApplyExecutor.php`
- `inc/Apply/TemplatePartApplyExecutor.php`
- `inc/Apply/PostBlocksApplyExecutor.php`
- `inc/Apply/PendingApplyDecision.php`
- `inc/Abilities/ApplyAbilities.php`
- `tests/phpunit/ExternalApplyLifecycleTest.php`
- `tests/phpunit/ApplyAbilitiesTest.php`
- `tests/phpunit/ActivityPermissionsTest.php`
- `tests/phpunit/StyleApplyExecutorTest.php`
- `tests/phpunit/TemplateApplyExecutorTest.php`
- `tests/phpunit/TemplatePartApplyExecutorTest.php`
- `tests/phpunit/PostBlocksApplyExecutorTest.php`
- `tests/phpunit/bootstrap.php` only if a test-only read/write counter is needed
- release/security documentation that currently lists the blocker

## Compatibility And Rollout

No database migration is required. Canonical rows created by current request abilities already duplicate the same subject in `target`, `document.entityId`, `document.postType`, and `document.scopeKey`.

Compatibility is governed by the matrix above. A legacy template-part row containing only `templatePartId` is safe only when the complete document identity agrees; a ref-only, incomplete, or contradictory historical row remains non-executable. This fail-closed behavior is intentional. The release record should state that such rows require manual inspection rather than offering an unsafe compatibility fallback.

The intended integration unit is a focused security pull request based on current `master`; do not merge or cherry-pick the diverged PR #72 branch wholesale. Creating commits, pushing, opening the pull request, or merging it are explicit follow-up actions outside this design's authority. If separately authorized and merged, rerun verification on the actual merge SHA before using it as the base for the corpus slice.

## Acceptance Criteria

- Every executor has one canonical identity resolver used by authorization, baseline, execute, undo, and attestation subject construction.
- Missing executor-owned template-part IDs and unequal aliases cannot authorize, read target content/state, write, or attest either named subject.
- Theme-qualified template and template-part refs cannot fall through to a different entity with the same slug or numeric WordPress ID.
- Every surface requires the exact canonical `document.postType`; post blocks additionally bind that type to actual metadata for the target post ID without Flavor Agent collecting, inspecting, parsing, hashing, or comparing target content.
- Target/document divergence fails independently of capability possession.
- The post-blocks regression proves an authorized post cannot proxy a write to another post.
- When the decision service reaches target authorization, approval denial records an honest failed row without a target content read/write; an outer permission denial leaves it pending.
- Undo preserves existing lifecycle/ordered-error precedence, and target denial performs no content read/write, attestation, or target/activity transition.
- All legitimate executor paths and existing permission defenses remain green.
- Verification evidence belongs to the final focused security SHA.
