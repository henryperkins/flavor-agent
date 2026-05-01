# Block Recommendation Review Remediation Plan

Date: 2026-04-29

Status: partially implemented and partially superseded

Scope: Address the confirmed review findings in the `flavor-agent/recommend-block` runtime path. This plan intentionally avoids broader redesigns and keeps changes tied to freshness, executable attribute validation, undo contract drift, tests, and docs.

Implementation result: Solutions 1 and 2 are implemented in the current source. Solution 3 was superseded by the later undo decision captured in `docs/SOURCE_OF_TRUTH.md`: path drift is not a hard block when the same `clientId`, block name, and recorded post-apply attributes still identify the target block. Path fallback remains guarded by block name and attribute snapshots.

## Findings Covered

1. `P2`: Background server freshness never marks wrapped block signature-only responses stale.
2. `P2`: Executable block suggestions can apply uncontracted top-level attributes.
3. `P3`: The moved-block undo contract needed resolution because docs and runtime behavior diverged.

## Target Outcomes

- Block background revalidation reads the same wrapped REST response contract as apply-time freshness checks.
- Server-stale block recommendations remain visible but are demoted and disabled before the user tries to apply them.
- Executable block attribute updates are restricted to the selected block's declared content/config attributes plus explicitly supported style, style-variation, visibility, and binding updates.
- Provider output cannot apply `lock`, arbitrary `metadata`, or unregistered top-level attributes through the block recommendation path.
- Undo behavior matches the accepted current contract: path drift is diagnostic only when the target resolves by `clientId`; automatic undo remains available if the block name and recorded post-apply attributes still match.
- PHP, JS, docs, and the closest browser coverage prove the fixed contracts.

## Solution 1: Background Block Freshness

### Original Evidence Before Fix

- `Agent_Controller::handle_recommend_block()` wraps both normal and `resolveSignatureOnly` REST responses as `{ payload, clientId }`.
- `revalidateBlockReviewFreshness()` posts `resolveSignatureOnly: true` to `/flavor-agent/v1/recommend-block`.
- The thunk reads `response?.resolvedContextSignature`, while the real route returns `response.payload.resolvedContextSignature`.
- `guardSurfaceApplyResolvedFreshness()` already uses `getResolvedContextSignatureFromResponse()`, which supports both wrapped and direct response shapes.

### Implemented Behavior

1. The background freshness path reuses the existing response helper.
   - `src/store/index.js` now reads the signature through `getResolvedContextSignatureFromResponse( response )`.
   - Silent catch behavior for background failures is preserved.

2. The stale-state contract is preserved.
   - The store dispatches `setBlockApplyState( clientId, 'idle', null, null, 'server' )` when the server signature differs.
   - Apply-time freshness still blocks mutation on wrapped signature drift.
   - The REST wrapper shape remains `{ payload, clientId }`.

3. UI behavior is unchanged except for the now-reachable stale state.
   - `getBlockRecommendationFreshness()` maps stored `server` stale reasons to `server-apply`.
   - `BlockRecommendationsPanel` shows the refresh hero and disables `SuggestionChips` when `isStaleResult` is true.

### Covered Tests

`src/store/__tests__/store-actions.test.js` covers:

- `revalidateBlockReviewFreshness marks wrapped signature-only drift stale`
  - Stored resolved signature: `resolved-block-old`.
  - Mock API response: `{ payload: { resolvedContextSignature: 'resolved-block-new' }, clientId: 'block-1' }`.
  - Assert dispatch receives `setBlockApplyState( 'block-1', 'idle', null, null, 'server' )`.

- `revalidateBlockReviewFreshness leaves matching wrapped signatures fresh`
  - Stored resolved signature and wrapped response signature match.
  - Assert no stale dispatch.

- `revalidateBlockReviewFreshness ignores direct legacy signature shape when matching`
  - Optional compatibility test for `{ resolvedContextSignature: 'resolved-block-old' }`.
  - This protects any direct ability-path tests or older mocks.

When the current `test.fixme` block inspector smoke is re-enabled, `tests/e2e/flavor-agent.smoke.spec.js` should keep the route mock aligned with the real wrapper:

- In the `recommend-block` route mock, remove the top-level `resolvedContextSignature` duplicate from signature-only responses so the E2E follows the real REST wrapper.
- Add a stale-drift variant for the block panel if a reliable browser harness is available.

## Solution 2: Executable Attribute Contract

### Original Evidence Before Fix

- Server-side `filter_suggestion_for_execution_contract()` allows `block`-lane executable suggestions with omitted `panel` even when panel mapping is known.
- Server-side `filter_attribute_updates_for_execution_contract()` validates known style/support keys, but the default branch returns unknown top-level values unchanged.
- Client-side `sanitizeSuggestionForExecutionContract()` and `filterAttributeUpdatesForExecutionContract()` mirror the same behavior.
- `BlockTypeIntrospector::GENERAL_PANEL_EXCLUDED_ATTRIBUTES` excludes `lock`, `metadata`, `style`, and `className` from the general panel, but unknown executable attributes can still pass through the block lane.
- `buildSafeAttributeUpdates()` then merges `metadata` and `style` and `applySuggestion()` calls `updateBlockAttributes()` with the surviving patch.

### Implemented Behavior

1. A shared executable top-level allowlist model exists in PHP and JS.
   - Allow block attributes listed in the execution contract:
     - `contentAttributeKeys`
     - `configAttributeKeys`
   - Allow supported top-level block support attributes already handled by validators:
     - `backgroundColor`
     - `textColor`
     - `gradient`
     - `fontSize`
     - `textAlign`
     - `minHeight`
     - `minHeightUnit`
     - `height`
     - `width`
     - `aspectRatio`
     - `style`
   - Allow `className` only for validated `type: "style_variation"` suggestions and only through the registered style variation validator.
   - Allow `metadata.blockVisibility` because the prompt and docs intentionally support viewport visibility.
   - Allow `metadata.bindings` only after the existing bindable-attribute filter removes unregistered binding targets.

2. The server rejects unsupported executable top-level attributes.
   - `inc/LLM/Prompt.php` allowlists contract content/config keys in `filter_attribute_updates_for_execution_contract()`.
   - `metadata` keeps only supported `blockVisibility` and allowed `bindings`.
   - `lock` and arbitrary unknown keys are rejected.

3. The client mirrors the same contract.
   - `src/store/update-helpers.js` keeps the JS allowlist equivalent to PHP so client sanitization cannot resurrect server-filtered unsafe updates from stale/local/mock data.
   - `lock` is banned.
   - `metadata` is restricted to `blockVisibility` and `bindings`, then existing bindable filtering removes unsupported binding targets.

4. Block-lane panel behavior is tightened without breaking legitimate block suggestions.
   - For non-advisory, non-style-variation block suggestions with executable `attributeUpdates`, require the updates to pass the attribute allowlist.
   - Do not require `panel` for permitted local block attributes, because block-lane visibility suggestions intentionally omit a native panel in some responses.
   - Keep `structural_recommendation` and `pattern_replacement` advisory-only and strip their attribute updates as today.

5. Content-only and disabled behavior is preserved.
   - Leave `disabled` restrictions as a hard stop.
   - Keep content-only filtering after execution-contract filtering.
   - For content-only blocks, executable updates must still be in `contentAttributeKeys`; `metadata.blockVisibility`, `lock`, and style updates remain blocked.

### Covered Tests

PHP coverage includes:

- Provider response with a block-lane executable suggestion containing `attributeUpdates.lock` is filtered out.
- Provider response with an unknown top-level key, such as `attributeUpdates.unregisteredThing`, is filtered out.
- Provider response with `metadata.customName` or arbitrary metadata is filtered out.
- Provider response with `metadata.blockVisibility.viewport.mobile = false` still survives for a normal editable block.
- Provider response with `metadata.bindings.content` survives only when `content` is in `bindableAttributes`.
- Content-only block response with `metadata.blockVisibility` or style updates is filtered out.
- Declared `configAttributeKeys` and `contentAttributeKeys` still survive.
- Registered style variation `className` still survives only when the style name is registered.

JS coverage in `src/store/__tests__/store-actions.test.js` and focused update-helper tests includes:

- `applySuggestion` does not call `updateBlockAttributes()` for `lock`.
- `applySuggestion` does not call `updateBlockAttributes()` for an unknown top-level attribute.
- `applySuggestion` still applies declared content/config attributes.
- `applySuggestion` still applies supported `metadata.blockVisibility`.
- `applySuggestion` still rejects advisory block suggestions even if they include otherwise valid local updates.
- `sanitizeRecommendationsForContext()` strips unsupported metadata while preserving supported visibility and bindings.

## Solution 3: Superseded Moved-Block Undo Contract

### Original Evidence

- `docs/features/block-recommendations.md` previously said undo was blocked when the block moved.
- Runtime target resolution in `src/store/block-targeting.js` resolves by `clientId` first and falls back to `blockPath` only if the direct block lookup fails.
- `getBlockActivityUndoState()` and `undoBlockActivity()` check block name and attribute snapshots, but they do not verify that the current path still matches `target.blockPath`.
- A moved block with the same `clientId` and unchanged post-apply attributes remains undoable.

### Final Product Decision

Do not block automatic undo solely because a `clientId`-resolved block moved from the recorded path. The accepted safety contract is the stricter identity and state check already used by the runtime: the block must still exist, the block name must match, and current attributes must match the recorded post-apply snapshot. Path drift can remain diagnostic metadata, but it is not by itself an undo failure.

### Final Contract

1. Resolve block activity targets by `clientId` first.
   - `src/store/block-targeting.js` returns the resolved block and its current path.
   - `hasResolvedActivityBlockMoved()` can report path drift for diagnostics.

2. Keep path drift non-blocking for `clientId` matches.
   - `getBlockActivityUndoState()` does not fail merely because the live path differs from `target.blockPath`.
   - `undoBlockActivity()` does not fail merely because the live path differs from `target.blockPath`.

3. Keep the hard safety checks.
   - Undo still fails when the block is missing, the block name changed, current attributes are unavailable, or current attributes no longer match the recorded post-apply snapshot.
   - Native undo that already reverted the action is still treated separately.

4. Keep path fallback guarded.
   - If `clientId` cannot be resolved but `blockPath` resolves a block, the fallback target must still pass the block-name and attribute-snapshot checks.
   - Do not undo solely because a block exists at the old path.

### Covered Tests

`src/store/__tests__/store-actions.test.js` and activity-history tests cover:

- moved block targets remain undoable when `clientId`, block name, and post-apply attributes still match
- replaced or mutated block targets still fail safely
- path fallback does not undo an unrelated block solely because it occupies the old path

Update E2E coverage only if the block inspector smoke is re-enabled:

- Add a moved-block undo scenario after the stable apply/undo smoke path exists.

## Documentation Updates

Update docs only after implementation:

- `docs/features/block-recommendations.md`
  - State the accepted current contract: a moved block remains undoable when the same `clientId`, block name, and applied attribute snapshot still match.
  - Add a short note that server-side freshness drift disables apply before mutation once background revalidation completes.
  - Clarify that executable block updates are limited to declared local attributes plus explicit visibility, binding, style, and registered style-variation channels.

- `docs/reference/abilities-and-routes.md`
  - Keep the existing `{ payload, clientId }` REST wrapper statement.
  - Clarify that block signature-only REST callers must read `payload.resolvedContextSignature`.

- `docs/reference/shared-internals.md`
  - If it documents block activity targeting or update sanitization, align it with the diagnostic-only path drift contract and stricter attribute allowlist.

- `docs/SOURCE_OF_TRUTH.md`
  - Update only if it currently overclaims broader executable block mutation behavior.

Run `npm run check:docs` after any docs edits.

## Implementation Sequence

1. Fix background freshness helper usage and add store tests.
2. Add PHP executable attribute allowlist and parser tests.
3. Add JS executable attribute allowlist and apply/sanitization tests.
4. Keep path drift diagnostic-only and cover moved-block undo safety with tests.
5. Update docs to match the final runtime contracts.
6. Run targeted verification.
7. Run broader verification only after targeted checks are green.

## Verification Plan

Targeted checks:

```bash
composer run test:php -- --filter 'BlockAbilitiesTest|AgentControllerTest'
npm run test:unit -- --runTestsByPath src/store/__tests__/store-actions.test.js src/store/__tests__/activity-history-state.test.js src/inspector/__tests__/InspectorInjector.test.js src/inspector/__tests__/BlockRecommendationsPanel.test.js src/inspector/__tests__/SuggestionChips.test.js src/inspector/__tests__/NavigationRecommendations.test.js
npm run check:docs
```

If implementation touches lint-sensitive PHP or JS:

```bash
composer run lint:php
npm run lint:js
```

If bundled JS behavior or editor integration changes:

```bash
npm run build
```

Browser verification:

```bash
npm run test:e2e:playground -- --grep "block inspector"
```

If the block inspector smoke remains `test.fixme`, record that as a known browser coverage blocker and rely on the targeted PHP/JS suites plus a manual note that the route mock must follow the real wrapped signature-only response before the test can prove the freshness fix.

Final aggregate check when environment prerequisites are available:

```bash
npm run verify -- --skip-e2e
```

Run full E2E only when the WordPress/Playground harness is available and known-green:

```bash
npm run test:e2e
```

## Acceptance Criteria

- Background block revalidation marks wrapped signature-only drift stale.
- Stale block recommendations remain visible but executable chips are disabled before apply.
- Apply-time server freshness still blocks drifted results.
- Server parsing removes `lock`, arbitrary metadata, and unknown top-level executable attributes.
- Client sanitization and apply helpers enforce the same attribute contract.
- Declared content/config attributes, supported style updates, registered style variations, supported visibility updates, and allowed bindings still work.
- Content-only and disabled editing restrictions remain hard apply gates.
- Moved block activity entries remain undoable when `clientId`, block name, and post-apply attributes still match.
- Native undo, missing block, changed block, disappeared block, and newer-action ordering behavior remain covered.
- Docs describe the actual REST wrapper, attribute mutation limits, stale handling, and moved-block undo behavior.
- Targeted PHP, JS, and docs checks pass.
