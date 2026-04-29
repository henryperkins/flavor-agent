# Block Recommendation Review Remediation Plan

Date: 2026-04-29

Status: proposed implementation plan

Scope: Address the confirmed review findings in the `flavor-agent/recommend-block` runtime path. This plan intentionally avoids broader redesigns and keeps changes tied to freshness, executable attribute validation, undo contract drift, tests, and docs.

## Findings Covered

1. `P2`: Background server freshness never marks wrapped block signature-only responses stale.
2. `P2`: Executable block suggestions can apply uncontracted top-level attributes.
3. `P3`: Docs say moved blocks cannot be undone, but runtime undo follows the original `clientId` after movement.

## Target Outcomes

- Block background revalidation reads the same wrapped REST response contract as apply-time freshness checks.
- Server-stale block recommendations remain visible but are demoted and disabled before the user tries to apply them.
- Executable block attribute updates are restricted to the selected block's declared content/config attributes plus explicitly supported style, style-variation, visibility, and binding updates.
- Provider output cannot apply `lock`, arbitrary `metadata`, or unregistered top-level attributes through the block recommendation path.
- Undo behavior matches the documented contract: if the target block moved from the recorded `blockPath`, automatic undo is blocked.
- PHP, JS, docs, and the closest browser coverage prove the fixed contracts.

## Solution 1: Background Block Freshness

### Current Evidence

- `Agent_Controller::handle_recommend_block()` wraps both normal and `resolveSignatureOnly` REST responses as `{ payload, clientId }`.
- `revalidateBlockReviewFreshness()` posts `resolveSignatureOnly: true` to `/flavor-agent/v1/recommend-block`.
- The thunk reads `response?.resolvedContextSignature`, while the real route returns `response.payload.resolvedContextSignature`.
- `guardSurfaceApplyResolvedFreshness()` already uses `getResolvedContextSignatureFromResponse()`, which supports both wrapped and direct response shapes.

### Implementation Plan

1. Reuse the existing response helper.
   - Modify `src/store/index.js`.
   - In `revalidateBlockReviewFreshness()`, replace the direct `response?.resolvedContextSignature` read with `getResolvedContextSignatureFromResponse( response )`.
   - Keep silent catch behavior for background failures.

2. Preserve the stale-state contract.
   - Keep dispatching `setBlockApplyState( clientId, 'idle', null, null, 'server' )` when the server signature differs.
   - Do not change apply-time freshness; it already blocks mutation on wrapped signature drift.
   - Do not change the REST wrapper shape because docs and normal fetch handling already depend on `{ payload, clientId }`.

3. Keep UI behavior unchanged except for the newly reachable stale state.
   - `getBlockRecommendationFreshness()` already maps stored `server` stale reasons to `server-apply`.
   - `BlockRecommendationsPanel` already shows the refresh hero and disables `SuggestionChips` when `isStaleResult` is true.

### Tests

Add or update `src/store/__tests__/store-actions.test.js`:

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

Update `tests/e2e/flavor-agent.smoke.spec.js` when the current `test.fixme` block inspector smoke is re-enabled:

- In the `recommend-block` route mock, remove the top-level `resolvedContextSignature` duplicate from signature-only responses so the E2E follows the real REST wrapper.
- Add a stale-drift variant for the block panel if a reliable browser harness is available.

## Solution 2: Executable Attribute Contract

### Current Evidence

- Server-side `filter_suggestion_for_execution_contract()` allows `block`-lane executable suggestions with omitted `panel` even when panel mapping is known.
- Server-side `filter_attribute_updates_for_execution_contract()` validates known style/support keys, but the default branch returns unknown top-level values unchanged.
- Client-side `sanitizeSuggestionForExecutionContract()` and `filterAttributeUpdatesForExecutionContract()` mirror the same behavior.
- `BlockTypeIntrospector::GENERAL_PANEL_EXCLUDED_ATTRIBUTES` excludes `lock`, `metadata`, `style`, and `className` from the general panel, but unknown executable attributes can still pass through the block lane.
- `buildSafeAttributeUpdates()` then merges `metadata` and `style` and `applySuggestion()` calls `updateBlockAttributes()` with the surviving patch.

### Implementation Plan

1. Define a shared executable top-level allowlist model in PHP and JS.
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

2. Reject unsupported executable top-level attributes on the server.
   - Modify `inc/LLM/Prompt.php`.
   - In `filter_attribute_updates_for_execution_contract()`, replace the default pass-through with an allowlist check against contract content/config keys.
   - Add focused handling for `metadata`:
     - Keep only `blockVisibility` after its existing structural sanitization.
     - Keep only `bindings` after bindable filtering runs.
     - Drop all other metadata keys from executable suggestions.
   - Reject `lock` explicitly.
   - Reject arbitrary unknown keys by default.

3. Mirror the same contract on the client.
   - Modify `src/store/update-helpers.js`.
   - Keep the JS allowlist equivalent to PHP so client sanitization cannot resurrect server-filtered unsafe updates from stale/local/mock data.
   - Add `lock` to the banned executable attribute set.
   - Restrict `metadata` to `blockVisibility` and `bindings`, then let existing bindable filtering remove unsupported binding targets.

4. Tighten block-lane panel behavior without breaking legitimate block suggestions.
   - For non-advisory, non-style-variation block suggestions with executable `attributeUpdates`, require the updates to pass the attribute allowlist.
   - Do not require `panel` for permitted local block attributes, because block-lane visibility suggestions intentionally omit a native panel in some responses.
   - Keep `structural_recommendation` and `pattern_replacement` advisory-only and strip their attribute updates as today.

5. Preserve content-only and disabled behavior.
   - Leave `disabled` restrictions as a hard stop.
   - Keep content-only filtering after execution-contract filtering.
   - For content-only blocks, executable updates must still be in `contentAttributeKeys`; `metadata.blockVisibility`, `lock`, and style updates remain blocked.

### Tests

Add or update PHP coverage, preferably in `tests/phpunit/BlockAbilitiesTest.php` or a focused prompt parser test:

- Provider response with a block-lane executable suggestion containing `attributeUpdates.lock` is filtered out.
- Provider response with an unknown top-level key, such as `attributeUpdates.unregisteredThing`, is filtered out.
- Provider response with `metadata.customName` or arbitrary metadata is filtered out.
- Provider response with `metadata.blockVisibility.viewport.mobile = false` still survives for a normal editable block.
- Provider response with `metadata.bindings.content` survives only when `content` is in `bindableAttributes`.
- Content-only block response with `metadata.blockVisibility` or style updates is filtered out.
- Declared `configAttributeKeys` and `contentAttributeKeys` still survive.
- Registered style variation `className` still survives only when the style name is registered.

Add or update JS coverage in `src/store/__tests__/store-actions.test.js` and, if useful, a new focused update-helper test:

- `applySuggestion` does not call `updateBlockAttributes()` for `lock`.
- `applySuggestion` does not call `updateBlockAttributes()` for an unknown top-level attribute.
- `applySuggestion` still applies declared content/config attributes.
- `applySuggestion` still applies supported `metadata.blockVisibility`.
- `applySuggestion` still rejects advisory block suggestions even if they include otherwise valid local updates.
- `sanitizeRecommendationsForContext()` strips unsupported metadata while preserving supported visibility and bindings.

## Solution 3: Moved-Block Undo Contract

### Current Evidence

- `docs/features/block-recommendations.md` says undo is blocked when the block moved.
- Runtime target resolution in `src/store/block-targeting.js` resolves by `clientId` first and falls back to `blockPath` only if the direct block lookup fails.
- `getBlockActivityUndoState()` and `undoBlockActivity()` check block name and attribute snapshots, but they do not verify that the current path still matches `target.blockPath`.
- A moved block with the same `clientId` and unchanged post-apply attributes remains undoable.

### Product Decision

Keep the documented safer contract: automatic undo should be blocked when the block moved from the recorded path. This avoids applying a historical attribute restore after the block has been reorganized into a different document context.

### Implementation Plan

1. Add path-aware target resolution.
   - Modify `src/store/block-targeting.js`.
   - Add a helper that finds the current block path for a `clientId` by walking `getBlocks()`.
   - Return both the resolved block and its current path, or expose a `hasBlockMoved()` helper that compares the current path to `target.blockPath`.

2. Enforce movement checks in undo state.
   - Modify `src/store/activity-history.js`.
   - In `getBlockActivityUndoState()`, after resolving the target block and before attribute snapshot checks, compare current path to recorded `target.blockPath` when both are available.
   - If the paths differ, return `canUndo: false`, `status: 'failed'`, and the existing movement/type error text.

3. Enforce movement checks in the undo action.
   - Modify `src/store/activity-undo.js`.
   - In `undoBlockActivity()`, apply the same movement guard before calling `updateBlockAttributes()`.
   - Keep existing behavior for missing blocks, type changes, post-apply attribute drift, and native undo already reverting the action.

4. Keep path fallback behavior for missing direct client IDs.
   - If `clientId` cannot be resolved but the original `blockPath` still resolves to a block of the expected type and with matching attributes, keep the current fallback behavior unless tests show this creates ambiguity.
   - If fallback-by-path is considered too permissive during implementation, document and test the stricter behavior before changing it.

### Tests

Add or update `src/store/__tests__/store-actions.test.js`:

- `undoActivity blocks a moved block target before restoring attributes`
  - Activity target: `clientId: 'block-1'`, `blockPath: [0]`.
  - Current block tree places `block-1` at `[1]`.
  - Current attributes match `after.attributes`.
  - Assert `updateBlockAttributes` is not called and undo fails with the movement/type error.

- `undoActivity still treats native undo as already undone when path is unchanged`
  - Preserve existing native undo behavior.

Add or update `src/store/__tests__/activity-history-state.test.js`:

- `getBlockActivityUndoState marks moved block activity failed`
  - Same moved-path setup.
  - Assert `canUndo: false`, `status: 'failed'`.

Update E2E coverage only if the block inspector smoke is re-enabled:

- Add a moved-block undo scenario after the stable apply/undo smoke path exists.

## Documentation Updates

Update docs only after implementation:

- `docs/features/block-recommendations.md`
  - Keep the moved-block undo statement if Solution 3 is implemented as above.
  - Add a short note that server-side freshness drift disables apply before mutation once background revalidation completes.
  - Clarify that executable block updates are limited to declared local attributes plus explicit visibility, binding, style, and registered style-variation channels.

- `docs/reference/abilities-and-routes.md`
  - Keep the existing `{ payload, clientId }` REST wrapper statement.
  - Clarify that block signature-only REST callers must read `payload.resolvedContextSignature`.

- `docs/reference/shared-internals.md`
  - If it documents block activity targeting or update sanitization, align it with the moved-block guard and stricter attribute allowlist.

- `docs/SOURCE_OF_TRUTH.md`
  - Update only if it currently overclaims broader executable block mutation behavior.

Run `npm run check:docs` after any docs edits.

## Implementation Sequence

1. Fix background freshness helper usage and add store tests.
2. Add PHP executable attribute allowlist and parser tests.
3. Add JS executable attribute allowlist and apply/sanitization tests.
4. Add path-aware undo target guard and undo-state tests.
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
- Moved block activity entries cannot be automatically undone.
- Native undo, missing block, changed block, disappeared block, and newer-action ordering behavior remain covered.
- Docs describe the actual REST wrapper, attribute mutation limits, stale handling, and moved-block undo behavior.
- Targeted PHP, JS, and docs checks pass.
