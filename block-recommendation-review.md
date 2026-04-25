# Block Recommendation Surface Review

## Findings

### P1 — Background server-side freshness revalidation never detects drift (`revalidateBlockReviewFreshness` reads the wrong response path)

- **File:** `src/store/index.js:1745-1789` (specifically the path read at line 1768)
- **Evidence:** The REST handler `Agent_Controller::handle_recommend_block` (`inc/REST/Agent_Controller.php:649-675`) **always** wraps the response in `{ payload: {...}, clientId: ... }`, even for `resolveSignatureOnly` requests. `tests/phpunit/AgentControllerTest.php:196-208` (`test_handle_recommend_block_signature_only_wraps_minimal_payload_and_skips_model_call`) explicitly asserts the shape `[ 'clientId' => ..., 'payload' => [ 'resolvedContextSignature' => ... ] ]`. The thunk however reads `response?.resolvedContextSignature` (line 1768) — the unwrapped path — which is always `undefined` for this REST endpoint. The check at line 1770-1784 (`if ( serverSig && storedResolvedSig && serverSig !== storedResolvedSig )`) is therefore unreachable, so `setBlockApplyState(..., 'idle', null, null, 'server')` is never dispatched.
- **Impact:** The proactive background revalidation invoked from `src/inspector/InspectorInjector.js:199-215` fires an extra POST to `/flavor-agent/v1/recommend-block` on every selection / context / prompt change but cannot mark the result server-stale. The Inspector keeps the result labeled "fresh" even when the server-resolved apply context has drifted — exactly the failure mode `docs/features/block-recommendations.md:29` claims is prevented ("Freshness now has two layers on the block surface … server-normalized block apply context plus the sanitized prompt"). Apply itself is still gated by the (correctly-implemented) `guardSurfaceApplyResolvedFreshness` (`src/store/index.js:474-553`), which uses `getResolvedContextSignatureFromResponse` (line 446-453) and tolerates both shapes, so no incorrect attribute write occurs — but the user only learns about server staleness when they click apply, with no preemptive warning. The design intent is broken and the bandwidth is wasted.
- **Fix direction (smallest credible):** Replace `response?.resolvedContextSignature` at `src/store/index.js:1768` with `getResolvedContextSignatureFromResponse(response)`, which already handles both wrapped and unwrapped shapes consistently with the apply-time guard. Add a unit test in `src/store/__tests__/store-actions.test.js` that mocks the wrapped `{ payload: { resolvedContextSignature } }` response and asserts the `setBlockApplyState(..., 'server')` dispatch fires when the signatures differ — none of the existing block-stale tests in that file cover this thunk's response handling (the closest, `applySuggestion blocks server-stale block results after resolveSignatureOnly drift` at line 2346, exercises only the apply-time guard).

### P2 — Block recommendation requests do not write server-side activity diagnostics for failures or empty results

- **Files:**
  - `inc/REST/Agent_Controller.php:649-675` (`handle_recommend_block` — no `persist_request_diagnostic_*` calls, no `document` request param defined in the route at lines 75-99)
  - Compare to `handle_recommend_content` (line 705-741), `handle_recommend_patterns` (line 791-818), `handle_recommend_navigation` (line 854-892), all of which call `persist_request_diagnostic_failure_activity` and `persist_request_diagnostic_activity`.
- **Evidence:** The recommend-block route declares only `editorContext`, `prompt`, `clientId`, `resolveSignatureOnly` (lines 75-99) — there is no `document` arg the way `recommend-content`/`recommend-patterns`/`recommend-navigation` declare one (lines 130, 188, 233). When the LLM call fails or returns an empty block lane, the only diagnostic surface is the **client-side** synthetic entry built in `BlockRecommendationsPanel.js:439-509` from `requestDiagnostics`. Nothing reaches `Activity\Repository`, so the admin `Settings > AI Activity` audit cannot show block recommendation failures/empties. `docs/features/activity-and-audit.md` and `docs/SOURCE_OF_TRUTH.md` describe the audit as cross-surface, and the focus area "activity/audit metadata gaps for successful applies, failed requests, provider fallback, or undo state" identifies exactly this hole.
- **Impact:** Operators monitoring AI failure rates from the admin audit screen can see content/navigation/pattern failures but are blind to block-recommendation failures or systematic empty-lane outcomes. This is a real visibility gap for the surface that has the highest call volume.
- **Fix direction:** Either (a) add `'document'` as an optional route param mirroring the other routes, then call `persist_request_diagnostic_activity`/`persist_request_diagnostic_failure_activity` from `handle_recommend_block` (extending `build_request_diagnostic_title` / `build_request_diagnostic_reference` / `get_request_result_count` for `'block'`); or (b) document the explicit exclusion in `docs/features/block-recommendations.md` and `docs/features/activity-and-audit.md` so the audit-coverage contract is honest. Option (a) closes the gap; option (b) at minimum stops doc overclaim. The fetch path (`src/store/index.js:1078-1247`) does not currently send a `document`, so option (a) also requires plumbing through `getRequestDocumentFromScope(getCurrentActivityScope(registry))` the way `fetchPatternRecommendations` (line 1518) and `fetchNavigationRecommendations` (line 1651) do.

### P3 — Abilities-API schema for `flavor-agent/recommend-block` does not describe the REST request shape

- **File:** `inc/Abilities/Registration.php:36-63` (`register_block_abilities`)
- **Evidence:** The ability `input_schema` only declares `selectedBlock`, `prompt`, `resolveSignatureOnly` and lists `selectedBlock` as required. The REST route (`Agent_Controller::register_routes()` line 67-99) instead requires `editorContext` and `clientId`. `BlockAbilities::recommend_block` accepts both shapes via `prepare_recommend_block_input` (`inc/Abilities/BlockAbilities.php:160-177`), but the schema-declared MCP/abilities contract doesn't mention `editorContext`, and the output shape declared in `suggestion_output_schema` (Registration line 1648-1668) describes the inner payload — REST consumers receive `{ payload, clientId }` instead, never the bare inner object.
- **Impact:** Third-party MCP clients discovering `flavor-agent/recommend-block` and the editor REST consumer see two different effective contracts. Since `meta.mcp.public => true` (Registration line 1253-1257), this is exposed to MCP. Confusing rather than dangerous — both shapes work at runtime — but it does lock callers into the abilities path if they want a stable contract. The `docs/reference/abilities-and-routes.md` map should also be checked for consistency (cross-reference for fix).
- **Fix direction:** Either describe both shapes in the `input_schema` (with `oneOf: [{ required: ['selectedBlock'] }, { required: ['editorContext'] }]` and an `editorContext` property derived from the route definition), or document that abilities callers must use `selectedBlock` while REST callers must use `editorContext`. Keep the schema and documentation in sync; if you change one, run `npm run check:docs`.

### P3 — Inspector subpanel mirror logic relies on test-fragile selector behavior

- **File:** `src/inspector/InspectorInjector.js:276-309` and `src/inspector/SuggestionChips.js:117-143`
- **Evidence:** The subpanel mirror passes `interactive={ false }`, which is the right design (the focus area "delegated native sub-panels accidentally creating second apply/refresh/activity paths" is correctly avoided — the chips are passive and don't dispatch `applySuggestion`). However, when a chip is rendered with `interactive={ false }` and `isStale={ true }` simultaneously, the rendering at SuggestionChips line 117-128 only shows the non-stale "passive" notice; the stale notice at line 130-142 is not shown for non-interactive chips because the conditions at lines 117 and 130 are mutually exclusive (the `! interactive && ! isStale` branch wins). Users looking at native sub-panels see passive chips with no indication that the underlying result is stale.
- **Impact:** Minor UX consistency issue. Stale-result UX is correct in the main panel (which gates apply); subpanel mirrors silently show stale chips as just "passive". Low severity because no apply happens through these chips.
- **Fix direction:** In `SuggestionChips.js`, render the stale notice when either `isStale` OR (`isStale && ! interactive`) — combine the conditions so stale-state messaging is shown regardless of interactive mode. Or add a `flavor-agent-chip--stale` class to the passive chip rendering so the visual styling reflects state.

---

## Open Questions / Assumptions

- **Assumption on activity-log scope:** I assumed `Settings > AI Activity` is meant to be the canonical cross-surface audit surface. If block requests are intentionally excluded for volume/cost reasons, P2 collapses to a docs-only fix.
- **Background revalidation cadence:** P1's user-visible severity depends on how often the Inspector triggers `revalidateBlockReviewFreshness`. From `InspectorInjector.js:199-215` the effect fires on every change of `[clientId, currentRequestInput, hasStoredResult, status]`, which is frequent. A real fix should also confirm the test at `tests/phpunit/AgentControllerTest.php:175-211` (signature-only response shape) hasn't been recently changed in a way that invalidates my reading — line 200-204 is the source of truth I matched against.

---

## Verification Reviewed

**Files inspected (runtime path traced):**
- `inc/REST/Agent_Controller.php` (route registration, `handle_recommend_block`, `persist_request_diagnostic_*` family, signature-only branch)
- `inc/Abilities/Registration.php` (block ability schema, output schema)
- `inc/Abilities/BlockAbilities.php` (`recommend_block`, `prepare_recommend_block_input`, normalization)
- `inc/Context/BlockRecommendationExecutionContract.php` (server execution contract)
- `inc/Context/BlockTypeIntrospector.php`, `inc/Context/BlockContextCollector.php` (server context)
- `inc/LLM/Prompt.php` (system/user prompt, `parse_response`, `enforce_block_context_rules`, full attribute/style validation chain)
- `src/inspector/BlockRecommendationsPanel.js` (`BlockRecommendationsContent`, `BlockRecommendationsDocumentPanel`, diagnostic activity entry assembly)
- `src/inspector/InspectorInjector.js` (HOC + sub-panel delegation, background revalidation effect)
- `src/inspector/SuggestionChips.js` (chip apply path, passive vs interactive)
- `src/inspector/NavigationRecommendations.js` (embedded mode)
- `src/inspector/block-recommendation-request.js` (request signature, freshness derivation)
- `src/store/index.js` (`fetchBlockRecommendations`, `applySuggestion`, `revalidateBlockReviewFreshness`, reducer for block state, freshness guards)
- `src/store/update-helpers.js` (full client-side sanitization chain, `getBlockSuggestionExecutionInfo`, `sanitizeRecommendationsForContext`)
- `src/store/activity-history.js` (`getBlockActivityUndoState`, ordered-undo, scope resolution)
- `src/store/block-targeting.js`
- `src/utils/recommendation-request-signature.js`, `src/utils/block-recommendation-context.js`, `src/utils/block-execution-contract.js`

**Tests inspected:**
- `tests/phpunit/AgentControllerTest.php` (`test_handle_recommend_block_*` — explicit confirmation of the wrapped signature-only response shape that the JS thunk mishandles)
- `tests/phpunit/BlockAbilitiesTest.php` (`test_prepare_recommend_block_input_*`)
- `src/store/__tests__/store-actions.test.js` (`applySuggestion blocks server-stale block results after resolveSignatureOnly drift` confirms apply-time guard works; no test exists for `revalidateBlockReviewFreshness`)
- `src/inspector/__tests__/InspectorInjector.test.js` (mocks `revalidateBlockReviewFreshness` but does not verify its result handling — gap that allowed P1 to slip through)
- `src/inspector/__tests__/BlockRecommendationsPanel.test.js`, `src/inspector/__tests__/SuggestionChips.test.js`

**Docs cross-checked:**
- `docs/features/block-recommendations.md` (overclaim on "two layers" of freshness — partially false given P1)
- `docs/SOURCE_OF_TRUTH.md`, `STATUS.md`, `CLAUDE.md`

**Commands run:**
- `grep`-based searches for `revalidateBlockReviewFreshness`, `resolveSignatureOnly`, `persist_request_diagnostic_*`, `payload.resolvedContextSignature`, `additionalProperties`, `'document'`, `blockClientId` to confirm call sites and contract drift
- File line counts via `wc -l`

**No commands changed any files.** No fix was applied — this is review-only as instructed.

---

## Residual risk / Test gaps

- No JS unit test verifies that `revalidateBlockReviewFreshness` correctly parses the wrapped REST response. Adding a parallel test to the existing apply-time `resolveSignatureOnly drift` tests (one per surface, lines 2346-3626 of `store-actions.test.js`) would prevent regression after P1 is fixed and would mirror the contract guarantees the PHP test at `AgentControllerTest.php:175-211` already enforces.
- No PHPUnit test asserts the absence (or presence) of `request_diagnostic` activity entries from the block route. If the team accepts P2's option (a), add a positive test; if option (b), nothing to test but the docs check should call this out.
- No cross-language parity test guards `Prompt::validate_style_leaf_value` (PHP) against `validateStyleLeafValue` (JS, `update-helpers.js:697-845`). The two reimplement the same rule table; if one drifts, suggestions could pass server validation but fail client validation (or vice versa). This is not in scope for P0/P1 today, but worth flagging as residual risk because the existing `SupportToPanelSyncTest.php` only covers the supports→panel mapping, not the executable rule table.
