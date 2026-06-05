# Pattern Recommendation Warm-Up & Inserter Badge — Design

- **Date:** 2026-06-04
- **Status:** Approved design; ready for implementation planning.
- **Scope:** Pattern recommender only. Client trigger behavior, target-scoped ranking cache, request logging semantics for no-op/no-model pattern probes, pattern runtime-signature exposure, and inserter badge DOM anchoring. No changes to ranking prompts, pattern retrieval backends, or insertion rollback behavior. Apply-time freshness semantics are preserved, but the live ranking/apply input contract is intentionally tightened by adding `blockContext.blockName` where the selected block is known.

## Problem

Pattern recommendations currently run a passive request when the editor loads. That request is keyed to whatever `getBlockInsertionPoint()` reports at the time, even though a document can have many valid insertion points: each block-list root has `child count + 1` positions, and nested containers multiply that count. The initial insertion point can therefore be stale before the user opens the inserter.

The passive request also creates misleading Activity rows when Gutenberg has not exposed a useful visible pattern scope yet. `PatternAbilities::recommend_patterns()` correctly short-circuits when `visiblePatternNames` is missing or empty, but `RecommendationAbilityExecution` still records a local `request_diagnostic` with a request token and no linked core AI request log. The admin detail then says the AI request log is unavailable even though no model request was attempted.

The first real ranking is not fast enough to hide behind a just-in-time request. Recent linked pattern ranking rows on the local stack show about 10.7s fastest, 14.9s average, and 19.5s slowest. Moving real ranking to inserter-open therefore needs an explicit loading state and a cache/warm-up strategy.

Separately, the inserter badge is intended to appear on the `+` inserter button, but current DOM discovery can anchor it to the neighboring list-view/document-outline control. The helper first accepts any `button.block-editor-inserter__toggle`, then falls back to broad aria-label matching, and `InserterBadge` portals into the matched button's parent. That is too loose for current Gutenberg toolbar markup.

## Goals

1. Treat the first real ranking as insertion-point-specific, not editor-load-specific.
2. Keep useful early work, but make it non-ranking and non-intent-assuming.
3. Avoid local Activity rows that imply a missing core AI request log when no model request was made.
4. Show the badge only on the actual block inserter `+` control.
5. Preserve existing freshness/apply safeguards, including insertion-target signature checks before insertion.

## Non-Goals

- No ranking prompt rewrite.
- No backend swap or model tuning.
- No attempt to pre-rank every possible insertion point.
- No change to direct/external ability behavior: the server still gracefully returns empty recommendations when called with missing or empty `visiblePatternNames`.
- No change to pattern insertion rollback or the requirement that direct insertion revalidates the server `resolvedContextSignature`.

## Decisions

### 1. Split warm-up from ranking

Editor load may run a lightweight pattern recommendation warm-up, but warm-up must not call the model ranker or the `recommend-patterns` ability. It may prepare or inspect shared prerequisites:

- feature/capability readiness;
- selected backend and pattern-index state;
- connector approval/readiness state;
- cached docs-grounding state if already available;
- current Gutenberg pattern availability hydration.

Warm-up must not persist a `request_diagnostic` row in this phase. Keeping warm-up outside the normal `fetchPatternRecommendations()` / `RecommendationAbilityExecution` diagnostic path is the selected behavior, not a preference. `warmup_noop` remains reserved for a future explicit warm-up diagnostic path, but this design does not emit it.

This inserter-intent gate is the primary fix for spurious editor-load rows: the passive editor-load path never reaches the server. The `modelRequest` marker below is only for residual real requests that reach the pattern pipeline and end before a model call.

Real inserter-intent requests may still end before the model, such as when retrieval finds no rankable candidates. Those diagnostics should remain visible, but the server must mark the outcome explicitly as a no-model result before `RecommendationAbilityExecution` persists the row. The client must identify these calls with an optional, sanitized top-level input field:

```json
{
  "requestPurpose": "inserter_ranking"
}
```

`requestPurpose` is not required for direct/external callers. The input schema remains an open object (`additionalProperties: true`) and adds optional `requestPurpose: string`. After existing input normalization, `PatternAbilities::recommend_patterns()` sanitizes the value with `sanitize_key()`; only exact `inserter_ranking` is recognized. Omitted or unknown values are silently treated as omitted, never rejected with 400, preserving the existing direct ability contract, including the graceful empty recommendation response for missing or empty `visiblePatternNames`. The client should not send a real ranking request when the current insertion point has no visible pattern scope; `missing_visible_patterns` is only a defensive no-model reason for a malformed or stale client `requestPurpose: "inserter_ranking"` request that reaches the server anyway.

A durable no-model response shape is:

```json
{
  "diagnostics": {
    "modelRequest": {
      "attempted": false,
      "reason": "no_rankable_candidates"
    }
  }
}
```

`reason` is a small allow-list for this phase: `no_rankable_candidates` and defensive `missing_visible_patterns`. `no_rankable_candidates` is emitted whenever the pipeline reaches retrieval and finds no rankable candidates, regardless of `requestPurpose`; it is additive diagnostics for a real no-model outcome. `missing_visible_patterns` is emitted only for `requestPurpose: "inserter_ranking"` requests that reach the server with a missing or empty visible pattern scope. Missing backend, unconfigured provider, and docs-grounding unavailable failures remain `WP_Error` failure diagnostics rather than soft no-model responses; they do not emit `diagnostics.modelRequest`. The Activity admin normalizer must use this marker instead of inferring from `requestToken` / `requestLogId` alone. A row with `modelRequest.attempted === false` should render copy like "No model request was attempted for this diagnostic." A row with a request token and no log ID but no no-model marker should keep the existing unavailable-log copy because it still represents a model request whose core log was unavailable or not captured.

`PatternAbilities::pattern_recommendation_response()` is shared by success, signature-only, and no-model branches, so the marker must be an explicit optional argument set only by the no-model callers. The success response and signature-only response pass no `modelRequest` marker. The no-rankable-candidates branch passes `{ attempted: false, reason: "no_rankable_candidates" }`. The defensive missing-visible-patterns branch passes `{ attempted: false, reason: "missing_visible_patterns" }` only when `requestPurpose` resolves to `inserter_ranking`; otherwise it preserves the existing empty response with no marker. The pre-runtime missing-scope branch may omit `patternRuntimeSignature` and is never cacheable.

The propagation path is part of the contract:

1. Ability response: `diagnostics.modelRequest`.
2. Store state: `patternDiagnostics.modelRequest`; `normalizePatternDiagnostics()` must preserve this allow-listed object instead of dropping unknown diagnostics keys. Preserve only `{ attempted: false, reason }` when `reason` is in the allow-list; if `reason` is missing, malformed, or not allow-listed, drop the entire `modelRequest` marker.
3. Activity persistence: `persist_request_diagnostic_activity()` copies the marker to top-level `after.modelRequest`. Do not duplicate it inside `after.requestContext`.
4. Activity normalization/admin: `normalizeActivityEntries()` exposes a normalized `modelRequest` field derived from `after.modelRequest` first, then response diagnostics if available.
5. UI: `AiRequestLogPanel` uses `entry.modelRequest.attempted === false` to render no-model copy before the token/log-id unavailable fallback.

### 2. First real ranking happens on inserter intent

The first model-backed `recommend-patterns` request should run only when the user opens or focuses the block inserter and the current insertion point has a real pattern scope:

- `isInserterOpen === true`;
- `canRecommend === true`;
- `effectivePostType` exists;
- current `visiblePatternNames.length > 0`;
- current insertion target has a stable signature built from post type, template type, root client ID, insertion index, and insertion context.

Search text inside the inserter still refines the same intent path. The request input should be built from the current insertion point at the moment of inserter use, not from an editor-load snapshot.

### 3. Cache by real insertion target

Completed rankings are cached in the Flavor Agent data store, not component-local React state. This lets the cache survive `PatternRecommender` remounts during common editor/inserter reopen flows and keeps cache invalidation next to the existing pattern recommendation state.

Completed rankings should be cached by a short-lived key that includes:

- `postType`;
- `templateType`;
- `rootClientId`;
- `insertionIndex`;
- normalized insertion context signature;
- `visiblePatternNames` signature;
- prompt/search text;
- selected block context, including `blockContext.blockName` when present;
- selected pattern runtime signature.

The runtime signature is required, not optional. Implementation path: add a single server helper for the current pattern runtime signature and use it from both `PatternAbilities::pattern_recommendation_response()` and readiness/bootstrap code. Derive it from `PatternAbilities::build_pattern_catalog_signature_context()`, which already contains the catalog-level inputs needed for the key (`patternBackend`, index fingerprint, stale reasons, Qdrant collection or Cloudflare AI Search namespace/instance/signature, and pattern fingerprint-map signature). Expose the current value to the editor before ranking through a read-only path that does not invoke `recommend-patterns`: either `flavorAgentData.capabilities.surfaces.pattern.patternRuntimeSignature` on bootstrap, `flavor-agent/check-status`, or both. That field must be sanitized as an opaque string and omitted or empty when the runtime state is not ready enough to build a signature. Also return `patternRuntimeSignature` to the client for every ranked, no-rankable, and signature-only `recommend-patterns` response that has reached pattern runtime state. The cache key uses that signature. This design does not rely on a flush-only fallback; if the current runtime signature is unavailable, cache lookup is bypassed and the result is not cached. A signature change naturally causes cache misses, and the store may flush old entries as cleanup when runtime state changes, but correctness must come from the signature key.

Every real inserter-triggered ranking request includes `blockContext.blockName` when `selectedBlockName` is present, including the first open-triggered ranking and search-triggered refinements. Put this in `buildBaseInput()` so ranking, search, retry, and the apply-time `resolveSignatureOnly` path use the same live input shape. `selectedBlockName` is therefore part of the cache key, and changes to it while the inserter is open invalidate the displayed result and may trigger a new ranking for the current inserter target. Because `blockContext` participates in the server apply signature, this is a shared ranking/apply contract change and must follow `docs/reference/cross-surface-validation-gates.md`.

If the user reopens the inserter at the same target and scope, the cached result can render immediately. If the insertion point changes, Flavor Agent should show loading and request a new ranking rather than reusing stale recommendations.

The existing apply-time resolved-signature check remains the final guard. Cache hits are a display optimization, not permission to skip freshness validation.

A cache hit must hydrate state through the same monotonic-token guard as a network response. The cache-hit path allocates a fresh `patternRequestToken`, restores the cached client request signature and insertion-target signature, dispatches a synthetic `SET_PATTERN_RECS` or equivalent cache-hydration action carrying that fresh token, then sets pattern status to `ready`. Cache hydration must neutralize any existing `_patternAbort` request, including same-key requests, because those requests were created with an older token and cannot safely refresh the newly hydrated state under the current monotonic-token guard. If implementation later wants background refresh after a cache hit, it must start a new post-hydration network request with a new token and normal request signature; that refresh is out of scope for this phase.

Cache entries must store the full state required to behave like a completed ranking response, not only the recommendation rows:

- recommendations;
- diagnostics, including the no-model/model-request marker when present;
- docs-grounding warning/status needed by the shelf;
- client request signature;
- insertion-target signature;
- server `resolvedContextSignature`;
- docs-grounding fingerprint/status when used by the shelf;
- `reviewContextSignature` only if the store starts consuming it for pattern freshness or display behavior;
- pattern runtime signature used for the cache key;
- enough recommendation-outcome identity to keep `shown`, `validation_blocked`, and insert outcomes tied to the cached recommendation set.

If a cache entry is missing the insertion-target signature, server `resolvedContextSignature`, or pattern runtime signature, treat it as a cache miss. Rendering cached recommendations without those fields would make the shelf look ready while the direct Insert path immediately fails, shows stale recommendations after backend/index drift, or records outcomes against the wrong request identity.

### 4. Show an honest loading state

Because measured linked local ranking rows ranged from 10.7s to 19.5s with a 14.9s average, the inserter UI should show a clear pending state when ranking is in progress for the current target. The badge may indicate loading only when it is anchored to the correct `+` control and the current target is rankable.

No-results copy should remain distinct:

- no-patterns insertion point: the current spot does not accept patterns;
- empty ranking: patterns were available, but no strong match was found;
- loading: real ranking is in progress for the current inserter target.

### 5. Tighten inserter badge targeting

`findInserterToggle()` should positively identify the block inserter `+` control and avoid the document-outline/list-view button. The selector strategy should:

- prefer structural Gutenberg selectors such as `button.block-editor-inserter__toggle` and inserter-specific `aria-controls`/toolbar relationships when present;
- use label matching only as a fallback, with an allow-list like `/^(toggle\s+)?(add\s+)?block\s+inserter$/i`;
- reject labels/classes matching `/list\s*view|outline|document\s*overview|hierarchy|structure/i`;
- treat localized or otherwise ambiguous labels as unknown unless structural selectors prove the button is the block inserter;
- avoid a generic `/inserter/i` fallback;
- create or reuse a dedicated badge anchor element immediately adjacent to the matched button, then portal into that anchor. Do not portal into `button.parentElement`, because that parent may be shared with sibling toolbar controls.

## Data Flow

1. Editor loads.
2. Pattern recommender initializes capability/backend/readiness state.
3. Optional warm-up runs without model ranking and without model-request activity-log linkage.
4. User opens the block inserter.
5. Client reads the live `getBlockInsertionPoint()`, derives `visiblePatternNames`, builds insertion context, and computes the insertion-target signature.
6. If scope is empty because the target accepts no patterns, render the no-patterns notice and do not request ranking.
7. If scope is non-empty, read the current pattern runtime signature from bootstrap/check-status readiness state and check the store-level target cache.
8. Cache hit: hydrate the store with a fresh request token and the cached signatures, then render recommendations for that exact target and scope.
9. Cache miss: render loading, call `recommend-patterns` with `requestPurpose: "inserter_ranking"`, persist normal diagnostics for the real inserter-intent request, and cache the result under the target key. If the pipeline ends before a model call, the diagnostic should carry an explicit no-model reason.
10. Before insertion, run the existing resolved-signature check and rollback guard.

## File-Level Change Plan

- `src/patterns/PatternRecommender.js`
  - Gate passive editor-load ranking behind inserter intent.
  - Keep warm-up logic local/read-only; it must not invoke `fetchPatternRecommendations()` or the `recommend-patterns` ability.
  - Build real ranking requests from the live inserter target and include `requestPurpose: "inserter_ranking"` only for those real inserter-triggered ranking calls.
  - Add `blockContext.blockName` to `buildBaseInput()` whenever `selectedBlockName` is available, so first-open ranking, search-triggered ranking, retry, and apply-time signature resolution stay in sync.
  - Read the current pattern runtime signature from editor bootstrap/check-status readiness state before cache lookup; never call `recommend-patterns` just to discover the cache key.
  - Use store-level target-scoped cache lookup/storage keyed by insertion target, prompt/search text, selected block context, visible pattern scope, and pattern runtime signature.
  - Bypass cache lookup/storage when the pattern runtime signature is unavailable; changes to that signature invalidate displayed cached results.
  - Keep the existing no-patterns insertion-point guard and empty-ranking copy distinct.

- `src/store/index.js` and related pattern actions
  - Add store-level pattern ranking cache metadata/state.
  - Provide a cache-hydration path that allocates a fresh `patternRequestToken` and restores recommendations, diagnostics, docs warning, request signature, insertion-target signature, resolved context signature, docs fingerprint metadata, and pattern runtime signature together.
  - Ensure cache hydration aborts or makes stale any existing `_patternAbort` request, including same-key requests, before the hydrated state is marked `ready`.
  - Preserve `diagnostics.modelRequest` in `normalizePatternDiagnostics()` as an allow-listed object with boolean `attempted` and sanitized `reason`.
  - Drop the entire `modelRequest` marker if `attempted` is not `false`, or if `reason` is absent, malformed, or outside the allow-list.
  - Preserve existing request signature and diagnostics shapes for real rankings.

- `src/patterns/inserter-dom.js`
  - Replace broad toggle discovery with positive block-inserter matching and list-view/document-outline exclusions.
  - Implement the explicit allow/deny label rules above and return null for ambiguous/localized-only matches that cannot be structurally confirmed.

- `src/patterns/InserterBadge.js`
  - Use the corrected toggle helper.
  - Create/reuse a dedicated adjacent badge anchor for the matched inserter button and portal into that anchor, not into the button's parent.
  - Continue hiding cleanly when the actual inserter button is unavailable.

- `src/admin/activity-log.js` / activity normalization
  - Apply the same distinction to real inserter-intent requests that terminate before a model call for an explicit pipeline reason.
  - Normalize the persisted marker from `after.modelRequest` into a stable entry field before rendering `AiRequestLogPanel`.
  - Keep warm-up out of Activity entirely in this phase.

- `inc/Abilities/Registration.php`, `inc/Abilities/PatternAbilities.php`, and `inc/Abilities/RecommendationAbilityExecution.php`
  - Add optional `requestPurpose` to the `recommend-patterns` input schema with `additionalProperties: true` retained. Sanitize inside `PatternAbilities::recommend_patterns()` with `sanitize_key()` against `[ 'inserter_ranking' ]`; omitted/unknown values are treated as omitted and must not produce a 400.
  - Keep the `recommend-patterns` input schema and output schema in sync: input accepts `requestPurpose`, output exposes `diagnostics.modelRequest` and `patternRuntimeSignature`, and direct/external callers passing unknown extra fields continue through the Abilities API.
  - Extend `pattern_recommendation_response()` with explicit optional data for `diagnostics.modelRequest` and `patternRuntimeSignature`; only no-model callers pass the marker, never the success or signature-only callers.
  - Add a sanitized `diagnostics.modelRequest` marker unconditionally for the no-rankable-candidates branch, and only for the missing-visible-patterns branch when `requestPurpose` is `inserter_ranking`.
  - Keep missing backend, unconfigured provider, and docs-grounding unavailable paths as `WP_Error` failures, not soft no-model responses.
  - Preserve the marker through `request_diagnostic` persistence.
  - Avoid minting a token-only AI request shape for warm-up/no-op diagnostics that never attempted a model request.
  - Add one internal helper that returns the sanitized current pattern runtime signature from `build_pattern_catalog_signature_context()`, and reuse it for both ability responses and readiness/bootstrap exposure.
  - Return `patternRuntimeSignature` from ranked, no-rankable, and signature-only pattern responses after runtime state is available.

- `flavor-agent.php`, `inc/Abilities/InfraAbilities.php`, and `inc/Abilities/SurfaceCapabilities.php`
  - Expose the current pattern runtime signature to the editor without calling `recommend-patterns`, either in `flavorAgentData.capabilities.surfaces.pattern.patternRuntimeSignature`, the `flavor-agent/check-status` surface payload, or both.
  - Omit or blank the field when the pattern runtime cannot build the signature; cache lookup/storage must fail closed in that state.

- `docs/features/pattern-recommendations.md` and related docs if behavior changes
  - Update passive-fetch language to describe warm-up versus real ranking.
  - Document that real ranking is tied to inserter intent and insertion-target scope.

## Testing

- `src/patterns/__tests__/PatternRecommender.test.js`
  - Editor load performs warm-up/readiness work but does not dispatch real ranking before inserter intent.
  - Opening the inserter with non-empty `visiblePatternNames` dispatches the first real ranking for the current target.
  - Real inserter-triggered ranking requests include `requestPurpose: "inserter_ranking"`; editor-load warm-up and direct helper calls do not.
  - The first open-triggered ranking includes `blockContext.blockName` when a selected block name is available.
  - Changing `rootClientId` or insertion index invalidates the current displayed result/cache key.
  - Changing selected block context invalidates the current cache key when `blockContext.blockName` would be sent.
  - Changing pattern runtime signature invalidates or flushes cached rankings.
  - Reopening the inserter at the same target can reuse a cached result.
  - Cache hits allocate a fresh `patternRequestToken` and restore the stored request signature, insertion-target signature, resolved context signature, diagnostics including `modelRequest`, docs warning, and pattern runtime signature before rendering the shelf.
  - Cache hydration aborts or makes stale any in-flight network response, including same-key requests created before hydration.
  - A cache hit for one target cannot be overwritten by a late in-flight network response for the same or a different target.
  - Cache entries without insertion-target signature, resolved context signature, or pattern runtime signature are treated as misses.
  - Empty current scope plus non-empty top-level scope renders the no-patterns notice and does not rank.

- `src/patterns/__tests__/inserter-dom.test.js`
  - Finds the actual block inserter `+` button.
  - Rejects list-view/document-outline/hierarchy buttons even when they sit in the same toolbar.
  - Applies the explicit allow-list and deny-list label rules.
  - Does not use localized/ambiguous labels as sufficient proof without a structural inserter selector.
  - Returns null rather than guessing when only ambiguous toolbar controls exist.

- `src/patterns/__tests__/InserterBadge.test.js`
  - Badge portals into a dedicated adjacent anchor for the actual inserter button.
  - Badge does not attach to the list-view/document-outline button.
  - Badge remains click-through and cleans up anchor classes on remount.

- Activity/admin tests
  - Pattern warm-up does not create an Activity row.
  - Real inserter-intent no-model diagnostics render "no model request was attempted" copy when `diagnostics.modelRequest.attempted === false`.
  - Real ranked requests with a linked core log still render the linked request-log panel.
  - Token-only model-backed diagnostics without a no-model marker still render the existing unavailable-log copy.
  - `normalizePatternDiagnostics()` and Activity admin normalization preserve only the allow-listed `modelRequest` shape.
  - `attempted: false` with a non-allow-listed, missing, or malformed `reason` drops the whole `modelRequest` marker.

- PHP tests
  - `RegistrationTest` asserts `requestPurpose` is present in the `recommend-patterns` input schema, the schema remains open (`additionalProperties: true`), and `diagnostics.modelRequest` plus `patternRuntimeSignature` are present in the pattern output schema.
  - Readiness/bootstrap coverage asserts the current pattern runtime signature is exposed without executing `recommend-patterns`, and omitted or blank when runtime state cannot safely produce it.
  - `PatternAbilities::recommend_patterns()` returns the existing empty recommendation response, with no no-model marker, for direct/external missing or empty visible scope when `requestPurpose` is omitted.
  - Unknown `requestPurpose` values are sanitized as omitted and do not 400.
  - `PatternAbilities::recommend_patterns()` returns a sanitized no-model marker for missing/empty visible scope only when routed through `requestPurpose: "inserter_ranking"`.
  - `PatternAbilities::recommend_patterns()` returns `diagnostics.modelRequest.attempted === false` for `no_rankable_candidates` before the model ranking call, including direct/external calls that reach retrieval with a non-empty visible scope.
  - Success and signature-only responses never include `diagnostics.modelRequest`.
  - Missing backend, unconfigured provider, and docs-grounding unavailable paths remain `WP_Error` failures for `requestPurpose: "inserter_ranking"`, not soft `modelRequest` responses.
  - `RecommendationAbilityExecution` preserves that marker in `request_diagnostic.after.modelRequest` and does not coerce it into a model-backed request-log state.
  - No-op warm-up paths persist no Activity row.

## Verification

Minimum gates:

- targeted JS suites for `PatternRecommender`, `inserter-dom`, and `InserterBadge`;
- cross-surface validation gates from `docs/reference/cross-surface-validation-gates.md` because the shared ranking/apply input contract and freshness metadata are changing;
- `node scripts/verify.js --skip-e2e`;
- `npm run check:docs` because `docs/features/pattern-recommendations.md` is contributor-facing and must be updated with this behavior change.

Browser evidence should be captured for the toolbar badge placement because this depends on live Gutenberg markup. Before finalizing fallback label rules, capture the actual failing toolbar DOM from the local full-stack WordPress runtime and write the selector tests against that markup. Use `tests/e2e/flavor-agent.smoke.spec.js`, including the existing pattern inserter smoke coverage (`pattern surface smoke uses the inserter search to fetch recommendations`) and a new badge-placement assertion for the post editor. Re-check that smoke's timing assumptions because the first ranking now fires on inserter open, not on the first search keystroke. If Site Editor loads the badge in this flow, add or extend `@wp70-site-editor` coverage under `npm run test:e2e:wp70`; if the harness is unavailable or known-red, record an explicit waiver with the target follow-up issue per the repo validation rules.

## Acceptance Criteria

- Opening the editor alone does not start a model-backed pattern ranking.
- Opening the block inserter at a rankable insertion point starts the first real ranking for that exact target.
- The UI displays an honest loading state during the first real ranking.
- Moving to a different insertion point does not show stale recommendations from the previous target.
- Returning to the same insertion point can show cached recommendations only when the insertion target, visible pattern scope, prompt/search text, selected block context, and pattern runtime signature still match.
- A cache hit uses a fresh request token and cannot be clobbered by any older in-flight request, including same-key or different-target responses.
- No local Activity row implies a missing linked core AI request log when no model request was attempted.
- A real inserter-intent request that reaches the model still produces a normal `request_diagnostic` row carrying `requestToken` and the linked `requestLogId` when core logging captures the request.
- Cache hits preserve the same request/freshness metadata as real rankings and cannot render insertable recommendations without a server `resolvedContextSignature` and matching pattern runtime signature.
- The badge displays on the `+` inserter button, not the list-view/document-outline button.
