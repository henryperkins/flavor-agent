# External Apply Target Authorization — Design

Date: 2026-08-02
Status: Approved direction; awaiting written-spec review

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

It does not import PR #72's broader undo coordinator, attestation, provider, UI, or release-document changes. It does not change operation validation, drift comparison, activity visibility, ranking, or the permissions required to request a pending apply.

## Current Failure

Activity access resolves authorization from normalized `document.scopeKey`, `document.postType`, and `document.entityId`. Executors resolve and mutate from surface-specific `target` fields. Both structures are persisted data, and current dispatch does not prove they name the same subject.

For post blocks this is an object-level privilege escalation: `edit_post:100` can authorize the row while `target.postId = 200` drives the executor against post 200. A matching attacker-supplied baseline can satisfy drift checks because freshness establishes state equality, not authority.

Theme-territory surfaces use the site-wide `edit_theme_options` capability, so divergent targets do not widen capability reach in the same way. They still corrupt the audit/approval contract, so identity coherence is enforced consistently across every executor.

## Contract

Extend `ExternalApplyExecutor` with:

```php
public static function authorize_target( array $entry ): true|\WP_Error;
```

Each executor must:

1. Parse its concrete target using the same normalization its read/write methods use.
2. Derive the canonical document identity expected for that target and surface.
3. Compare the expected identity with the persisted document identity using exact normalized values.
4. Authorize the current user against the concrete target.
5. Return `true` only when identity and capability checks both pass.

The method must not read or mutate target content. It is a pure authorization/coherence gate.

## Canonical Identity And Capability Rules

| Surface | Concrete target | Required document identity | Capability |
| --- | --- | --- | --- |
| `global-styles` | `target.globalStylesId` | `document.entityId` equals the ID and `document.scopeKey` equals `StyleAbilities::canonical_scope_key_for('global-styles', id)` | `edit_theme_options` |
| `style-book` | `target.globalStylesId` + `target.blockName` | ID equality and canonical Style Book scope-key equality including the block name | `edit_theme_options` |
| `template` | `target.templateRef` | `document.entityId` equals the normalized ref and `document.scopeKey` equals `wp_template:<ref>` | `edit_theme_options` |
| `template-part` | `target.templatePartRef`, falling back to `target.templatePartId` | `document.entityId` equals the normalized ref and `document.scopeKey` equals `wp_template_part:<ref>` | `edit_theme_options` |
| `post-blocks` | positive integer `target.postId` plus normalized `target.postType` | `document.entityId` equals the canonical decimal ID, `document.postType` equals the target post type, and `document.scopeKey` equals `<postType>:<id>` | `edit_post` for the target post ID |

Missing, malformed, non-canonical, or contradictory identity fields fail closed. Existing correctly formed activity rows already use these canonical shapes.

## Dispatch Order

### Approval

`PendingApplyDecision::decide()` keeps rejection behavior unchanged. For approval:

1. Load and validate the pending row.
2. Resolve the executor.
3. Call `authorize_target()`.
4. Only after success, resolve the live baseline and execute.
5. Persist the existing applied or failed transition.

Authorization therefore occurs before the first target read in `resolve_baseline()`.

### Undo

`ApplyAbilities::undo_activity()` keeps row lookup, surface support, executed-state, undo-state, and ordered-undo checks. Immediately before calling the executor's `undo()` method it calls `authorize_target()`.

Authorization therefore occurs before the first undo target read or write. This slice deliberately uses the current `master` call site and does not pull in PR #72's `UndoCoordinator` refactor.

## Failure Semantics

Use two stable error families:

- `flavor_agent_apply_target_mismatch`, HTTP 409: target/document identity is missing, malformed, or divergent.
- `flavor_agent_apply_target_forbidden`, HTTP 403: identity is coherent but the current user lacks the target capability.

Identity validation runs first. A divergent row always returns `flavor_agent_apply_target_mismatch`; capability is evaluated only after the row proves it describes the target consistently.

On approval, either error transitions the still-pending row to `apply.status = failed` through the existing transition API, preserving decision attribution and recording the bounded error code/message. No target content is read or written.

On undo, either error is returned and the row remains `available`; live target state and undo metadata remain byte-for-byte unchanged. A user who lacks permission must not be able to terminalize another operator's undo. A malformed historical row remains visibly unresolved rather than being misreported as undone.

Messages identify the surface and corrective action without exposing private target content. Error codes, not prose, are the stable test and integration contract.

## Defense Boundaries

- Existing ability/REST permission callbacks continue to protect activity discovery and route access.
- Request-time capability, signature, validation, and baseline gates remain unchanged.
- Target authorization never treats a valid baseline, signature, approval, or administrator-authored row as authority.
- Executor read/write methods may retain their own validation; the new shared call-site requirement prevents callers from accidentally skipping target authorization.
- Adding a new executor will fail interface conformance until it supplies target authorization.

## Testing

Write regression tests before implementation.

### Post-blocks adversarial coverage

- Create an available activity whose document names editable post 100 and target names denied post 200. Undo returns `flavor_agent_apply_target_mismatch`, post 200 is byte-identical, and undo remains available.
- Create a pending activity with the same divergence. Approval records a failed row and neither post changes.
- Grant access to both posts while keeping identities divergent. The row still fails with `flavor_agent_apply_target_mismatch`, proving capability alone cannot bless a dishonest audit target.
- Exercise a coherent post-200 row directly under a user denied `edit_post:200`; target authorization returns `flavor_agent_apply_target_forbidden` and reads or writes nothing.
- Use a coherent post-100 row and prove apply and undo still succeed.

### Theme-territory coverage

- Each executor accepts its canonical identity when `edit_theme_options` is present.
- Each executor rejects a divergent or missing document identity even when the capability is present.
- Each executor rejects a canonical identity when `edit_theme_options` is absent.
- Existing applied-state, drift, materialization, and attestation tests remain green.

### Contract and boundary coverage

- The registry returns only classes implementing the expanded interface.
- Approval invokes authorization before baseline resolution; a denied fixture proves the resolver/executor was not called.
- Undo invokes authorization before executor undo; a denied fixture proves no write occurred.
- Error persistence contains bounded codes/messages and no target content.

Primary suites:

```bash
vendor/bin/phpunit.bat --configuration phpunit.xml.dist tests/phpunit/ExternalApplyLifecycleTest.php tests/phpunit/ApplyAbilitiesTest.php tests/phpunit/ActivityPermissionsTest.php
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
- the closest existing ability/permission test if a narrower fixture belongs there
- release/security documentation that currently lists the blocker

## Compatibility And Rollout

No database migration is required. Canonical rows created by current request abilities already duplicate the same subject in `target`, `document.entityId`, and `document.scopeKey`.

Older or manually corrupted rows that do not carry a coherent identity become non-executable. This fail-closed behavior is intentional. The release record should state that such rows require manual inspection rather than offering an unsafe compatibility fallback.

Land this change as a focused security pull request based on current `master`. Do not merge or cherry-pick the diverged PR #72 branch wholesale. After merge, rerun verification on the actual merge SHA before using it as the base for the corpus slice.

## Acceptance Criteria

- Every executor authorizes the exact target it will use.
- Target/document divergence fails independently of capability possession.
- The post-blocks regression proves an authorized post cannot proxy a write to another post.
- Approval denial records an honest failed row without a target read/write.
- Undo denial performs no target or activity transition.
- All legitimate executor paths and existing permission defenses remain green.
- Verification evidence belongs to the final focused security SHA.
