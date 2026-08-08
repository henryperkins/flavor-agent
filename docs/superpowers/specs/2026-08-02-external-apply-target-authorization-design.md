# External Apply Target Authorization — Design

Date: 2026-08-02
Status: Implemented; PR #76 is the focused integration unit and final-head evidence is mandatory

## Goal

Prevent a Flavor Agent activity row from authorizing one WordPress entity through its `document` fields while an external apply or undo reads or writes a different entity named by `target`.

The fix belongs at executor dispatch because that is the last shared boundary that knows the concrete target a surface implementation will use. Existing request, REST, and ability permission callbacks remain necessary, but they cannot substitute for authorization of the actual write target.

## Scope

This slice changes target identity and capability authorization for the four governed executor families, plus the bounded persistence and decision atomicity required for those authorization results to remain truthful:

- Global Styles and Style Book;
- templates;
- template parts;
- post blocks.

It covers approval-time execution through `PendingApplyDecision` and server-side undo through `ApplyAbilities::undo_activity()` on current `master`.

For templates and template parts, the slice also serializes canonical-target persistence through a strict database mutex and verifies the authoritative post-write canonical identity before snapshots or attestation are recorded. One immutable write context binds the database object, posts, postmeta, taxonomy, and options tables plus blog ID from lock acquisition through preparation, mutation, readback, compensation, and release. At the activity boundary, each approve or reject atomically claims the pending row before target work and binds that claim to the database object, activity and option tables, prefix, blog ID, and site URL on which it was acquired, so competing decisions, expiry, or a hook-time site switch cannot redirect its terminal transition.

It does not import PR #72's broader undo coordinator, provider, UI, or release-document changes. The existing attestation service, key registry, and repository accept the captured activity storage context only so apply/revert evidence stays bound to the authorized site and remains publicly verifiable after ambient context drift. It does not change operation validation, drift comparison, ranking, or the permissions required to request a pending apply. The successful rejection outcome is unchanged, but rejection now consumes the same atomic decision claim as approval.

This design records the implemented contract. It does not itself authorize committing, pushing, merging, deploying, tagging, or publishing; each action still requires the separate approval appropriate to it.

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

`PendingApplyDecision::decide()` preserves the successful rejection outcome but serializes every decision in the activity row itself. After validating the row and decision, it atomically replaces `execution_result = pending` with a bounded opaque claim owner. All ordinary transitions, including lazy and scheduled expiry, still compare-and-swap from `pending` and therefore lose once execution is claimed. Approval and rejection can finish only by consuming the exact claim value they acquired.

For approval:

1. Load and validate the pending row and requested decision.
2. Atomically claim the row from `pending` with an owner-qualified execution marker.
3. Resolve the executor.
4. Call `authorize_target()`, which resolves the canonical identity and compares the complete document identity.
5. Only after success, resolve the live baseline and execute.
6. Build any attestation subject from the canonical execution target.
7. Persist the existing applied or failed transition by consuming the exact claim owner.

Authorization therefore occurs before the first Flavor Agent target-content collection, state comparison, or mutation in `resolve_baseline()`.

### Undo

`ApplyAbilities::undo_activity()` keeps row lookup, surface support, executed-state, undo-state, and ordered-undo checks. Those lifecycle checks intentionally retain precedence: a non-executed, terminal, invalid-state, or non-topmost row returns its existing error without invoking target authorization. After those checks pass and immediately before calling the executor's `undo()` method, the service calls `authorize_target()`.

Authorization therefore occurs before the first Flavor Agent undo-content collection, state comparison, or write. Successful undo builds any revert-attestation subject from the same canonical identity before transitioning the row. The terminal `available -> undone` write compares the exact previously read raw undo state, rechecks the captured storage context after persistence/hydration, and treats every post-mutation finalization error as recovery-required. This slice deliberately uses the current `master` call site and does not pull in PR #72's `UndoCoordinator` refactor.

## Failure Semantics

Target-authorization failures use two stable error families:

- `flavor_agent_apply_target_mismatch`, HTTP 409: target/document identity is missing, malformed, or divergent.
- `flavor_agent_apply_target_forbidden`, HTTP 403: identity is coherent but the current user lacks the target capability.

Operational serialization failures retain their existing public families:

- `flavor_agent_apply_materialization_locked`, HTTP 409: another decision owner or target mutex currently excludes this request. Despite the historical name, this code also represents decision-claim contention on non-materializing surfaces.
- `flavor_agent_apply_lock_unavailable`, HTTP 500: the target mutex could not be acquired or its store result could not be proven.
- `flavor_agent_apply_recovery_required`, HTTP 409 or 500 according to phase: mutation may have begun, an owner-qualified release or terminal transition could not be proven, or post-write ownership became uncertain and requires operator reconciliation.
- `flavor_agent_undo_recovery_required`, HTTP 500: an undo already changed the target, but its revert attestation, exact activity transition, final hydration, or storage-context restoration could not be proven.

Within `authorize_target()`, identity validation runs first. A divergent row returns `flavor_agent_apply_target_mismatch`; capability is evaluated only after the row proves it describes the target consistently.

That precedence is the contract of `authorize_target()` when the service reaches it. Public REST and Ability permission callbacks remain an outer layer and may reject first with their existing activity/permission 403 response. An outer denial does not call the decision or undo service, does not reveal whether persisted target fields mismatch, and does not transition the row.

On approval, when `PendingApplyDecision::decide()` reaches `authorize_target()`, either target error transitions the claimed row to `apply.status = failed` by consuming the exact decision claim through the transition API, preserving decision attribution and recording the bounded error code/message. No target content is read or written. If the outer permission callback rejects first, the row remains pending because the service never runs.

Decision-claim or target-mutex contention returns `flavor_agent_apply_materialization_locked` with HTTP 409. Decision contention includes only the activity ID and claim start time; target-mutex contention includes only the deterministic lock option name and a strictly validated canonical acquisition time. Legacy or corrupt lock values omit the time rather than reflecting untrusted owner metadata. Neither response exposes its owner token. A target-lock-store failure returns the separate `flavor_agent_apply_lock_unavailable` family with HTTP 500. Target-lock contention and target-lock-store failure occur before target writes, so the service owner-qualifies the claim release back to `pending` and returns the original 409 or 500 for retry. If that release cannot be proven, the response becomes `flavor_agent_apply_recovery_required` instead of claiming safe retryability.

A claim is never released once target mutation may have begun. Expected non-contention failures proven to precede mutation consume it in a terminal failed transition; successful execution consumes it in the applied transition. An unexpected exception during executor lookup, authorization, or baseline resolution is known to occur before the mutation boundary, so the service records a bounded `flavor_agent_apply_unexpected_failure` row by consuming the claim. An exception, malformed result, target-context drift, or recovery-required result from `execute()` can follow a target write: while the exact raw claim still exists, the service preserves that claim and returns `flavor_agent_apply_recovery_required` with only activity ID, phase, and public-safe processing time. It does not misreport the uncertain operation as a terminal failure or release it for retry. The operator must inspect the committed activity and target state; owner recovery applies only to an extant exact raw claim because a terminal database update may already have consumed it before a later read/projection failure. Attestation-recorder exceptions are captured as bounded attestation-failure metadata; they do not erase a proven target apply, and the terminal applied transition still consumes the claim. Exception messages and owner tokens are never returned.

Every claim read, diagnostic, release, and terminal compare-and-swap uses the activity storage context captured before the first row lookup. Activity rows, attestation rows, and key-registry writes remain bound to the acquisition database and tables even if the ambient site changes. Ambient-site diagnostics and transient/cache side effects are skipped after drift. The public JWKS path reads the current site's authoritative options table rather than trusting a possibly stale `notoptions` entry, so a bound key registration remains visible after the origin site is restored. An execution whose target provenance or terminal activity state cannot be proven after such drift remains claimed for operator recovery.

An abandoned claim or target mutex has no automatic expiry, lease takeover, or reset-to-pending path. Exact raw lowercase decision claims project outward as `executionResult = pending`, remain included in public pending filters, the per-user pending cap, and the admin pending notice, and cannot be expired by the ordinary `pending` compare-and-swap. The reserved `claim:` storage namespace, in any ASCII case, is rejected at activity-entry ingestion before event-specific normalization so clients cannot forge an internal owner marker. Malformed, uppercase, or whitespace-wrapped historical claim-prefixed values are not active claims and serialize publicly as `invalid`; their owner-like suffix is never exposed.

Operator recovery is explicit and owner-qualified. For a decision claim, an administrator first uses the activity ID and `processingSince` diagnostic to confirm the request is inactive, reconciles the live target, reads the exact raw claim through a trusted database/operator channel, and passes that observed value to `PendingApplyDecision::recover_abandoned_claim()`. The exact claim can transition only once to terminal `failed` with `flavor_agent_apply_recovery_required`; it is never restored to pending, and a stale owner cannot overwrite the terminal row. For a target mutex, the administrator uses `lockOptionName` plus the validated `acquiredAt` when available; a legacy/corrupt owner instead requires trusted raw-store inspection before quiescence can be established. The administrator reconciles the target, reads the exact option value, and calls `MaterializationLock::recover_abandoned()` with the surface, canonical ref, and observed value. Recovery deletes only that exact owner; a successor is protected. Operators must never delete by prefix or age alone.

On undo, an outer permission denial, initial ordered-undo failure, or target-authorization error leaves the row and live target unchanged. When `authorize_target()` is reached, either target error is returned and the row remains `available`; live target state and undo metadata remain byte-for-byte unchanged. After the executor changes the target, a newer action, storage failure, same-row race, attestation failure with context drift, or unreadable final row cannot be returned as an ordinary 409/500 or success: the service returns `flavor_agent_undo_recovery_required`, identifies the bounded finalization phase, and leaves the persisted row for reconciliation. A user who lacks permission must not be able to terminalize another operator's undo. A malformed historical row remains visibly unresolved rather than being misreported as undone.

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
- Materialization scans same-slug results for the exact canonical ref, but an unrelated-only result remains fail-closed. It preserves the exact core stylesheet identity, rejects an active-theme switch or slug normalization that would change the canonical ref before insertion, then re-resolves the exact ref after insertion and requires its exact ref plus `wp_id` to match before recording success or attestation.
- Template and template-part persistence acquires a strict database-backed mutex keyed by site, surface, and exact canonical ref before the final live-content hash gate, then holds it through path selection, preparation, every write, raw persisted-content readback, compensation, and final identity resolution. Contention returns a retryable `409` with zero target writes and leaves the approval row pending; lock-store failures return `500`. The lock uses a plain conditional insert plus owner-qualified delete against the database object and options table captured at acquisition, so a hook-time blog-context switch cannot release or strand a row in the wrong table. Lock decisions use direct SQL and do not depend on option-cache invalidation. There is no automatic lease takeover, so an abandoned or corrupt owner remains fail-closed for operator recovery.
- The activity decision service atomically replaces `execution_result = pending` with a bounded opaque claim before authorization or target reads and requires that exact claim in the terminal update. One shared claim-format contract supplies strict PHP classification and the byte-exact SQL regex. The acquisition database object, table, prefix, and blog ID remain bound to every claim diagnostic, release, and transition. A second approve or reject in the post-write/pre-transition gap receives the retryable 409, while expiry and every ordinary transition lose their `pending` compare-and-swap; none can double-apply operations, terminalize the first writer, or replace the first approver's attribution. Public reads normalize only exact active claims to pending, redact every other claim-prefixed value to `invalid`, and keep owner tokens out of queues and notices.
- Existing template and template-part rows still pass through WordPress's normal `wp_update_post()` filters and hooks. The writer captures Core's complete filtered post-data array and authorizes only `post_content` plus Core-computed `post_modified` and `post_modified_gmt` changes; a filter that changes, removes, or adds another database field fails before SQL. It then installs a one-shot guard that recognizes only the exact outer Core posts-table write in the expected site and call context. The reconstructed write preserves the filtered content and both modified timestamps while requiring the old post type, name, status, password, modified timestamps, and content to match byte-for-byte. Content predicates use byte length plus two independently salted MD5 digests over `HEX(post_content)` without embedding the expected content bytes wholesale. A changed row evaluates a deliberate database-error arm before any content assignment, so Core returns before downstream post-update hooks; a missing row or uncertain result is detected by immediate bound-table readback and returns recovery-required. Nested, hook-owned, annotated, or wrong-table writes cannot consume the one-shot guard. Deliberate guard errors are suppressed and matching retained wpdb diagnostics are redacted before normal error settings are restored. Query filters can still observe the ordinary content-bearing save query, as they can for any WordPress post write. This boundary does not roll back independent side effects performed by third-party save filters before the database query.
- Before either persistence path, the executor runs WordPress Core's ignored-hooked-block preparation. Existing-row updates pass the prepared `meta_input` through the same guarded `wp_update_post()` call so Core retains its normal metadata hooks and ordering. A companion guard recognizes only Core's in-order update of an already existing, single metadata row, reconstructs the exact postmeta update from trusted state, binds it to the written post identity/content/modified timestamps, and verifies the final serialized raw value. Absent or duplicate metadata rows fail closed before the post write because WordPress has no portable uniqueness constraint for `(post_id, meta_key)`; Flavor Agent does not guess at an insertion race. A deleted row therefore cannot acquire orphaned Block Hooks metadata through Core's post-update tail, and concurrent metadata wins with recovery-required instead of being overwritten. Materialization passes the prepared metadata at insert time together with scalar taxonomy terms, `origin`, the source description in `post_excerpt`, and a Core-normalized template-part area.
- After every successful write, the executor re-resolves the exact canonical ref, requires its `wp_id` to equal the row just written, and uses that fresh `WP_Block_Template` entity's semantic content for activity snapshots, undo, and attestation. Raw database bytes remain confined to the primary compare-and-swap, exact readback, materialization ownership proof, and owner-safe compensation boundary. A same-ref authoritative result bound to another `wp_id` fails closed; a direct `wp_id` query cannot override that result. When an existing row fails this post-write identity gate, the executor first proves the observed raw content byte-equals the exact filtered content captured for this request. Only then may a guarded SQL compare-and-swap restore the raw pre-write `post_content`; its byte length and salted `HEX` digests avoid text-collation equivalence without retaining the expected bytes in error diagnostics. An editor change before or during compensation, including a case- or accent-only change, wins. The result remains `flavor_agent_apply_recovery_required` even when content restoration succeeds because identity or metadata side effects still require reconciliation.
- Materialization binds the actual outer posts-table insert to the captured context, then recognizes Core-owned postmeta and taxonomy side effects only when they target the captured tables. Hook-owned writes remain free to switch sites and restore them; a stale table captured by Core cannot mutate a same-ID row on another site. The inserted row must match the exact post ID, type, slug, content, and excerpt, and required raw metadata/taxonomy rows must match before canonical resolution. If any raw ownership or canonical identity fact is unavailable or divergent, Flavor Agent does not call `wp_delete_post()`, does not update a possible concurrent winner, and leaves the uncertain row unchanged for operator reconciliation. Pre-insert discovery of a proven canonical row can still take the normal existing-row guarded path.
- Existing applied-state, drift, materialization, and attestation tests remain green except the legacy slug-renormalization cases, which now assert the stricter fail-closed canonical-identity contract.

### Contract and boundary coverage

- The registry returns only classes implementing the expanded interface.
- Approval invokes authorization before baseline resolution. Add test-harness-only counters or one-shot hooks around target-content collection, executor writes, and attestation recording; a denied fixture must record zero calls to all three while persisting the expected failed decision.
- Undo invokes authorization after ordered-undo validation but before executor undo. A blocked older row proves ordered-undo retains precedence and the authorizer is not reached; a topmost denied row proves content resolution, mutation, attestation, and activity transition are not reached.
- Exercise the outer REST/Ability permission callback separately from direct service calls: outer denial returns the existing permission error and leaves a pending/available row unchanged, while a permission-passed direct service path exposes the stable target error and its documented transition semantics.
- Force a second approval into the interval after target-lock release but before the first terminal activity transition. It must receive the retryable lock error while the first approval remains `available`, owns the decision attribution, and writes the target exactly once.
- Force expiry into the target-write interval. Its ordinary pending transition must lose to the owner-qualified decision claim, while the approval records `available` with the freshly resolved semantic snapshot. A syntactically valid but different claim owner cannot release or terminalize the row.
- Force unexpected failures before and during executor execution. Pre-execution failures must consume the claim into a bounded failed row; execution uncertainty must preserve the raw claim, return only public-safe recovery diagnostics, and never retry automatically.
- Replace the current database object, prefix, table properties, and blog ID after claiming. Prove every diagnostic, release, and terminal compare-and-swap remains bound to the acquisition table while wrong-site caches, transients, and attestations are skipped.
- Prove abandoned-claim recovery requires administrator authority and the exact observed claim, terminalizes once as recovery-required, and fences the stale original owner. Prove target-lock recovery requires the exact observed option value and cannot remove a successor.
- Force target-lock deletion failure and prove normal release retries, reports failure while its owner remains stored, and succeeds on a later retry. Contention diagnostics expose deterministic identifiers and times but never either owner token.
- On template and template-part existing-row writes, interleave content immediately after the final live gate and prove the next raw read cannot adopt it as a fresh baseline. Then interleave a content or identity change after save filters are finalized but before the Core posts-table query; prove the guarded assignment fails atomically before downstream post-update hooks, preserves the concurrent state, and returns target-changed. Change protected or unknown filtered post data and prove no SQL runs. Prove content, `post_modified`, and `post_modified_gmt` update together, and bind subsequent metadata to that exact post version. Interleave deletion at the same boundary and prove no orphan metadata is created and the result is recovery-required. Exercise the guarded query shape under MySQL, MariaDB, and the supported Playground SQLite driver; assert the expected raw content hex is absent from retained diagnostics. Prove absent/duplicate metadata fails before content, concurrent metadata is preserved, and later hook-owned post writes still run. On a later identity mismatch, prove exact just-written raw content is restored; insert a concurrent post-content change before compensation and prove it is preserved while the executor returns recovery-required.
- On materialization, switch sites immediately before the Core insert and prove the bound insert guard prevents a wrong-table write. Restore ambient context while Core retains a wrong postmeta or taxonomy table and prove no wrong-site mutation occurs. Prove an independent hook can switch, write its own table, and restore without being blocked. Interleave slug, taxonomy, identity, and same-ref winner races after insertion and prove Flavor Agent neither deletes the uncertain inserted row nor updates the winner. Verify scalar taxonomy input, origin metadata, source description, Core-normalized template-part area, and exact raw type/slug/content/excerpt ownership.
- Force a same-row undo-state race, a newer action before post-mutation finalization, a final activity-storage failure, and a site switch during final hydration. The exact prior-state CAS must not overwrite a successor, and every uncertain post-mutation result must be `flavor_agent_undo_recovery_required` rather than ordinary success or an ordinary lifecycle/storage error. Apply finalization performs the equivalent post-call context check.
- Prewarm a missing attestation-key registry, register/sign against a captured origin context while the ambient site is different, restore the origin site, and prove the public JWKS exposes the key and verifies the stored statement.
- Run `tests/e2e/flavor-agent.block-hooks-parity.spec.js` against the WordPress 7.0 Site Editor harness. It must exercise real Core Block Hooks for both a template and template part, compare raw persisted metadata with freshly resolved semantic content and digests, verify materialization metadata/taxonomies, observe updated Core timestamps in the pre/post update hooks, and complete both undo round trips.
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
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.block-hooks-parity.spec.js --project=wp70-site-editor
npm run verify -- --skip-e2e
npm run check:docs
git diff --check
```

Before merging the focused security PR, run the full canonical verifier with Plugin Check prerequisites available and the matching WordPress 7.0 approvals/undo browser harness. Inspect `output/verify/summary.json`; `incomplete` is not a passing release signal.

## Implementation Files

- `inc/Apply/ExternalApplyExecutor.php`
- `inc/Apply/StyleApplyExecutor.php`
- `inc/Apply/MaterializationLock.php`
- `inc/Apply/MaterializationWriteContext.php`
- `inc/Apply/BlockTemplatePostInserter.php`
- `inc/Apply/BlockTemplateWritePreparer.php`
- `inc/Apply/ExistingPostContentWriter.php`
- `inc/Apply/ExistingPostContentCompensator.php`
- `inc/Apply/ExistingPostMetaWriter.php`
- `inc/Apply/GuardedQueryDiagnostics.php`
- `inc/Apply/MaterializedTemplateSideEffectVerifier.php`
- `inc/Apply/TemplateApplyExecutor.php`
- `inc/Apply/TemplatePartApplyExecutor.php`
- `inc/Apply/PostBlocksApplyExecutor.php`
- `inc/Apply/PendingApplyDecision.php`
- `inc/Abilities/ApplyAbilities.php`
- `inc/Activity/ExternalApplyDecisionClaim.php`
- `inc/Activity/Repository.php`
- `inc/Activity/Serializer.php`
- `inc/Activity/ActivityStorageContext.php`
- `inc/Attestation/AttestationService.php`
- `inc/Attestation/KeyManager.php`
- `inc/Attestation/Repository.php`
- `inc/Attestation/Signer.php`
- `flavor-agent.php`
- `tests/phpunit/ExternalApplyLifecycleTest.php`
- `tests/phpunit/ApplyAbilitiesTest.php`
- `tests/phpunit/ActivityRepositoryTest.php`
- `tests/phpunit/ActivitySerializerTest.php`
- `tests/phpunit/ActivityPermissionsTest.php`
- `tests/phpunit/AttestationServiceTest.php`
- `tests/phpunit/StyleApplyExecutorTest.php`
- `tests/phpunit/MaterializationLockTest.php`
- `tests/phpunit/TemplateApplyExecutorTest.php`
- `tests/phpunit/TemplatePartApplyExecutorTest.php`
- `tests/phpunit/PostBlocksApplyExecutorTest.php`
- `tests/phpunit/PatternIndexTest.php`
- `tests/phpunit/PluginLifecycleTest.php`
- `tests/phpunit/bootstrap.php`
- `tests/e2e/flavor-agent.block-hooks-parity.spec.js`
- release/security documentation that currently lists the blocker

## Compatibility And Rollout

No database migration is required. Canonical rows created by current request abilities already duplicate the same subject in `target`, `document.entityId`, `document.postType`, and `document.scopeKey`. The transient owner marker fits the existing `execution_result varchar(32)` column. Public and admin projections use byte- and case-exact active-claim matching, pending caps and notices continue to include exact claims, and ordered-undo logic treats them as active newer operations. Activity ingestion reserves the entire `claim:` namespace case-insensitively before outcome normalization; malformed historical claim-prefixed values are public-redacted as `invalid` but never promoted to active ownership. A request interrupted after claiming remains visible and fail-closed for operator recovery rather than being silently retried.

Compatibility is governed by the matrix above. A legacy template-part row containing only `templatePartId` is safe only when the complete document identity agrees; a ref-only, incomplete, or contradictory historical row remains non-executable. This fail-closed behavior is intentional. The release record should state that such rows require manual inspection rather than offering an unsafe compatibility fallback.

The integration unit is focused security PR #76, based on current `master`; do not merge or cherry-pick the diverged PR #72 branch wholesale. Publication and merge remain separately authorized actions. Verification must run on PR #76's final immutable head, and the merge commit must be verified before it becomes the base for the corpus slice.

## Acceptance Criteria

- Every executor has one canonical identity resolver used by authorization, baseline, execute, undo, and attestation subject construction.
- Missing executor-owned template-part IDs and unequal aliases cannot authorize, read target content/state, write, or attest either named subject.
- Theme-qualified template and template-part refs cannot fall through to a different entity with the same slug or numeric WordPress ID.
- Every surface requires the exact canonical `document.postType`; post blocks additionally bind that type to actual metadata for the target post ID without Flavor Agent collecting, inspecting, parsing, hashing, or comparing target content.
- Target/document divergence fails independently of capability possession.
- The post-blocks regression proves an authorized post cannot proxy a write to another post.
- When the decision service reaches target authorization, approval denial records an honest failed row without a target content read/write; an outer permission denial leaves it pending.
- Approval and rejection claim `pending` before target work and consume only their own claim; concurrent decisions and expiry cannot win between a target write and its activity transition.
- Exact active claims stay publicly pending in get/list activity responses, pending filters, queue limits, and notices without revealing the claim owner; malformed historical values are redacted as `invalid`, and client-supplied claim-prefixed values cannot enter storage through activity creation.
- Claim diagnostics, release, and terminal transitions remain bound to the database/table/blog context on which the claim was acquired; site drift cannot redirect storage, cache, or attestation side effects.
- Target persistence contention and lock-store failure release only the caller-owned no-write claim back to pending, preserving a safe retry.
- Existing template and template-part writes preserve WordPress save filters/hooks, filtered content, Core modified timestamps, revisions, and Block Hooks preparation while a portable byte-length and independently salted digest guard prevents a concurrent deletion, identity change, or content change from being overwritten at the primary write boundary; unauthorized filtered fields fail before SQL and exact raw readback gates every success.
- Existing-row Block Hooks metadata remains in Core's `meta_input` hook order but only an exact single-row, owner-qualified update can persist; absent/duplicate rows, deletion, or concurrent metadata fail closed without being silently overwritten or orphaned.
- Materialization binds the actual Core insert and direct Core metadata/taxonomy side effects to captured site tables, preserves description, origin, scalar taxonomy terms, and normalized area, permits independent restored hook writes, and never automatically deletes an inserted row whose raw ownership or canonical identity is uncertain.
- Template and template-part activity snapshots, attestation, and undo use freshly resolved semantic block-template content; raw database bytes are used only for guarded comparison, exact readback, ownership proof, and owner-safe compensation.
- A context-drifted origin key registration remains available through the public JWKS and verifies its stored attestation; wrong-site key, attestation, activity, metadata, and taxonomy writes remain absent.
- The WordPress 7.0 Block Hooks parity harness passes for template and template-part apply plus undo.
- Undo preserves pre-mutation lifecycle/ordered-error precedence, target denial performs no content read/write, attestation, or target/activity transition, and every uncertain post-mutation activity finalization returns recovery-required without overwriting a raced undo state.
- All legitimate executor paths and existing permission defenses remain green.
- Verification evidence belongs to the final focused security SHA.
