# Context Retrieval And Prompt Grounding Remediation Plan

> Scope: fix the verified context-grounding defects from the 2026-04-08 review of block, pattern, template, template-part, and style recommendation surfaces, and add regression protection where the current flows are already correct.

## Goal

Make every recommendation prompt reflect the live editor state that the user is looking at, without weakening the current server-side validation, deterministic apply paths, or docs grounding behavior.

The end state should be:

1. Template and template-part requests always carry an explicit live document snapshot, including explicit empty state.
2. Server prompt context never mixes saved structural data with partial live overlays for the same mutable document slice.
3. Context signatures cover every prompt-shaping field that can make a result stale.
4. Block, pattern, and style surfaces keep their current behavior and gain regression coverage so this contract expansion does not break them.
5. Live capability-affecting template areas and deep template-part paths remain executable even when they only exist in unsaved editor state.

## Findings In Scope

1. Template-part recommendations are still grounded on saved template-part content for most structural context.
   The client only sends `currentPatternOverrides` and `visiblePatternNames`, while the server prompt still uses saved `blockTree`, `topLevelBlocks`, `blockCounts`, `structureStats`, `operationTargets`, `insertionAnchors`, and `structuralConstraints`.

2. Template recommendations only partially overlay live editor state.
   When the current template becomes empty, the client omits both `editorSlots` and `editorStructure`, so the server prompt can retain saved `topLevelBlockTree`, `topLevelInsertionAnchors`, and `structureStats`.

3. Block, pattern, and style flows do not have verified correctness defects, but they are part of the same recommendation stack and need explicit regression protection while the shared context contract changes.

## Design Decisions

### 1. Separate mutable live document context from canonical metadata

The prompt context should be treated as two categories:

- Canonical metadata that should still come from the server:
  `templateRef`, `templateType`, `templatePartRef`, `slug`, `title`, `area`, `availableParts`, `patterns`, `themeTokens`, docs guidance, and any capability-driven surface metadata that the editor cannot derive reliably.
- Mutable live document context that should come from the editor when available:
  template slot occupancy, live-detected template areas, block trees, block counts, structure summaries, override summaries, viewport visibility, operation targets, insertion anchors, structural constraints, and any path index needed to validate unsaved executable targets.

This keeps the server authoritative for stable metadata while making prompt grounding accurate for unsaved edits.

### 2. Empty is authoritative, not missing

If the live template or template part is empty, the client must send an explicit empty snapshot.

The server must interpret:

- `topLevelBlockTree: []`
- `blockTree: []`
- empty `assignedParts`
- empty `operationTargets`
- empty `insertionAnchors`
- zeroed `structureStats`

as the current truth, not as absence of data.

### 3. Overlay mutable slices atomically

Do not keep mixing saved and live values from the same document slice.

When a live template structure snapshot is present, replace the full template structure slice in prompt context:

- `topLevelBlockTree`
- `topLevelInsertionAnchors`
- `structureStats`
- `currentPatternOverrides`
- `currentViewportVisibility`

When a live template-part structure snapshot is present, replace the full template-part structure slice in prompt context:

- `blockTree`
- `topLevelBlocks`
- `blockCounts`
- `structureStats`
- `operationTargets`
- `insertionAnchors`
- `structuralConstraints`
- `currentPatternOverrides`

Keep server-collected values only when the request does not include the live snapshot at all.

### 4. Merge live template areas into the effective capability contract

For template recommendations, `allowedAreas` should not stay purely saved-state metadata once the editor exposes unsaved slot occupancy.

The effective allowed-area set should be:

- server-known `allowedAreas`
- plus any areas implied by live `assignedParts`
- plus any areas implied by live `emptyAreas`

This keeps stable capability metadata from the server while still letting newly inserted unsaved areas remain prompt-visible and executable.

### 5. Validate deep template-part paths against full live coverage

The prompt-facing `blockTree` may stay summarized or depth-limited for readability, but path validation cannot depend on that truncated tree.

Path-based template-part validation must use a full live path index that covers every real block path represented by:

- `operationTargets`
- `insertionAnchors`
- `structuralConstraints`
- any future path-based apply metadata

### 6. Stale detection must hash the same fields that shape the prompt

The context signature used by template and template-part surfaces must include every live field that materially changes the prompt.

If the prompt would change, the signature must change.

### 7. Do not expand scope for block, pattern, or styles

This plan does not redesign the healthy surfaces.

It only adds regression coverage to ensure:

- block recommendations still use live block context plus theme tokens
- pattern recommendations still use `visiblePatternNames` and inserter context
- style recommendations still use live style scope and canonical server-side style semantics

## Files In Scope

Primary implementation files:

- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/template-parts/TemplatePartRecommender.js`
- `inc/Abilities/TemplateAbilities.php`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/Registration.php`
- `docs/features/template-recommendations.md`
- `docs/features/template-part-recommendations.md`
- `docs/reference/abilities-and-routes.md`

Likely new helper file:

- `src/template-parts/template-part-recommender-helpers.js`

Primary test files:

- `src/templates/__tests__/TemplateRecommender.test.js`
- `src/templates/__tests__/template-recommender-helpers.test.js`
- `src/template-parts/__tests__/TemplatePartRecommender.test.js`
- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/RegistrationTest.php`
- `tests/phpunit/TemplatePromptTest.php`
- `tests/phpunit/TemplatePartPromptTest.php`
- `tests/phpunit/ServerCollectorTest.php`
- `src/context/__tests__/collector.test.js`
- `src/inspector/__tests__/BlockRecommendationsPanel.test.js`
- `src/patterns/__tests__/PatternRecommender.test.js`
- `src/global-styles/__tests__/GlobalStylesRecommender.test.js`
- `src/style-book/__tests__/StyleBookRecommender.test.js`
- `tests/phpunit/BlockAbilitiesTest.php`
- `tests/phpunit/PatternAbilitiesTest.php`
- `tests/phpunit/StylePromptTest.php`

Related plan that should stay aligned but does not need to be rewritten here:

- `docs/superpowers/plans/2026-04-06-recommendation-stale-state-alignment-plan.md`

## Implementation Plan

### Workstream 1: Harden The Request Contract

#### Objective

Define a complete live-context contract for template and template-part requests, then make the REST and ability schemas describe that contract precisely.

#### Steps

1. Expand the template `editorStructure` snapshot in `src/templates/template-recommender-helpers.js`.
   The client-side snapshot should include:
   - `topLevelBlockTree`
   - `structureStats`
   - `currentPatternOverrides`
   - `currentViewportVisibility`

2. Keep template insertion anchors server-derived from the live top-level tree.
   For templates, the server can still derive `topLevelInsertionAnchors` from the live `topLevelBlockTree`, so the request does not need a second anchor field unless implementation friction makes the duplication cheaper than recomputation.

3. Always build template snapshots even when the live template is empty.
   Remove the `templateBlocks.length > 0` guard in `TemplateRecommender.js` for:
   - `editorStructure`
   - the live slot occupancy snapshot

4. Refine the template slot contract.
   Treat template slot fields as two different concerns:
   - live occupancy from the editor: `assignedParts`, `emptyAreas`
   - capability metadata merged from both layers: server-known areas plus any live areas implied by unsaved `assignedParts` or `emptyAreas`

   Do not let an editor-derived empty array erase useful server capability data.
   Do let unsaved live areas extend the effective `allowedAreas` set so new slots remain prompt-visible and executable before save.

5. Extract template-part request builders from `TemplatePartRecommender.js` into a helper module.
   Create `src/template-parts/template-part-recommender-helpers.js` so the following can be unit-tested outside the React component:
   - `buildEditorTemplatePartStructureSnapshot()`
   - `buildTemplatePartRecommendationContextSignature()`
   - `buildTemplatePartFetchInput()`

6. Expand the template-part `editorStructure` snapshot to include the entire live structural slice:
   - `blockTree`
   - `allBlockPaths` or an equivalent full live path index that is not depth-trimmed for prompt display
   - `topLevelBlocks`
   - `blockCounts`
   - `structureStats`
   - `currentPatternOverrides`
   - `operationTargets`
   - `insertionAnchors`
   - `structuralConstraints`

7. Update REST route args in `inc/REST/Agent_Controller.php` and ability input schemas in `inc/Abilities/Registration.php`.
   The schema should allow the expanded object shape for template and template-part requests.

8. Keep the expanded fields optional at the transport level for backward compatibility.
   The frontend and backend ship together, but direct ability calls, old cached admin assets, or tests may still hit the old sparse shape during rollout.

#### Exit Criteria

1. Template and template-part requests can represent both non-empty and empty live documents explicitly.
2. The REST and ability schemas describe the full live context that the client now sends.
3. The template contract preserves unsaved live template-part areas, and the template-part contract preserves full live path coverage for validation.

### Workstream 2: Make Server Overlay Live-First And Atomic

#### Objective

Ensure prompt builders see a coherent live snapshot whenever the editor provides one.

#### Steps

1. Split the current generic normalization path in `inc/Abilities/TemplateAbilities.php` into surface-specific helpers.
   Introduce dedicated helpers for:
   - template editor structure
   - template-part editor structure
   - template live slot occupancy

   This avoids overloading one sparse normalizer for two very different shapes.

2. Add explicit overlay helpers in `TemplateAbilities`.
   The goal is to replace mutable slices in one place before prompt assembly, for example:
   - `apply_template_live_structure_context()`
   - `apply_template_live_slot_context()`
   - `apply_template_part_live_structure_context()`

3. For templates, replace the full live structure slice when `editorStructure` is present.
   Server behavior should become:
   - use server-collected context as a baseline
   - if live `topLevelBlockTree` is present, replace `topLevelBlockTree`
   - derive `topLevelInsertionAnchors` from the live tree
   - if live `structureStats` is present, replace `structureStats`
   - if live override and viewport summaries are present, replace those summaries

4. For templates, replace live slot occupancy without throwing away server capability metadata.
   Server behavior should become:
   - if `assignedParts` is present, replace `assignedParts`
   - if `emptyAreas` is present, replace `emptyAreas`
   - build effective `allowedAreas` as the union of server `allowedAreas` plus any live areas implied by `assignedParts` and `emptyAreas`

5. For template parts, replace the full structural slice when `editorStructure` is present.
   Server behavior should become:
   - replace `blockTree`
   - replace `topLevelBlocks`
   - replace `blockCounts`
   - replace `structureStats`
   - replace `operationTargets`
   - replace `insertionAnchors`
   - replace `structuralConstraints`
   - replace `currentPatternOverrides`

6. Validate internal consistency of the live template-part snapshot before prompt assembly.
   Since the server cannot reconstruct unsaved editor blocks from the database, it should:
   - sanitize every path and scalar value
   - verify that `operationTargets`, `insertionAnchors`, and any path-based constraints refer to paths that exist in a full live path index, not only in the prompt-facing `blockTree`
   - drop inconsistent entries instead of falling back to stale saved ones

7. Recompute or cross-check derived summaries whenever the request provides live structure.
   `topLevelBlocks`, `blockCounts`, `structureStats`, `operationTargets`, `insertionAnchors`, and `structuralConstraints` should not be accepted as unrelated free-form summaries.
   They must either be server-derived from the live structural payload or validated against the same live path index before prompt assembly.

8. Keep saved-content fallback only for truly missing live context.
   If the request omits `editorStructure`, keep the existing server collector behavior.
   If the request includes an explicit empty live snapshot, treat that empty snapshot as authoritative.

#### Exit Criteria

1. Prompt builders never see a mixed saved/live structural slice for template or template-part recommendations.
2. Empty live documents no longer produce saved-content structure summaries in prompts.
3. Effective template areas and template-part executable paths stay aligned with the unsaved editor state.

### Workstream 3: Implement Template-Part Live Context Capture

#### Objective

Make template-part recommendation prompts and stale signatures track the same unsaved editor structure that the user is editing.

#### Steps

1. Build a live template-part structure snapshot from `editorBlocks`.
   The snapshot builder should gather:
   - the recursive block tree used by `TemplatePartPrompt`
   - a full live path index for every real block path, even if the prompt-facing tree stays depth-limited
   - top-level block names
   - block counts
   - structure stats
   - operation targets
   - insertion anchors
   - structural constraints
   - current pattern override summary

2. Reuse existing client-side metadata collectors where possible.
   Prefer existing summary helpers for override metadata instead of inventing duplicate formatters.
   Add narrow new helpers only for the missing template-part structural summaries.

3. Update `handleFetch()` in `TemplatePartRecommender.js` to send the full structure snapshot, not just `currentPatternOverrides`.

4. Expand the template-part context signature so every prompt-shaping structural change invalidates the current result.
   The signature should include:
   - `blockTree`
   - the full live path index or equivalent full-path fingerprint
   - `topLevelBlocks`
   - `blockCounts`
   - `structureStats`
   - `operationTargets`
   - `insertionAnchors`
   - `structuralConstraints`
   - `currentPatternOverrides`
   - sorted `visiblePatternNames`

5. Keep template-part stale presentation on the existing plan path.
   This workstream should only change the signature and fetch payload.
   The stale badge, disabled execution, and refresh UX should continue to follow the existing stale-state alignment plan.

6. Update template-part feature docs and request examples.
   The docs should stop implying that only `visiblePatternNames` and override summaries shape template-part prompts.

#### Tests

1. JS: request payload contains the full live template-part snapshot.
2. JS: changing insertion anchors or structural constraints changes the context signature.
3. JS: an empty template part still sends an explicit empty structure snapshot.
4. PHP: `TemplateAbilities::recommend_template_part()` overlays live `blockTree`, targets, anchors, constraints, and stats instead of keeping saved values.
5. PHP: template-part path validation accepts valid deep live targets that are absent from the depth-limited prompt tree but present in the full live path index.
6. PHP: `TemplatePartPrompt::build_user()` reflects live unsaved structure after overlay.

#### Exit Criteria

1. Unsaved template-part edits change the recommendation prompt immediately.
2. Template-part results become stale whenever the live structure that shaped the prompt changes.

### Workstream 4: Implement Template Live Context Capture

#### Objective

Make template prompts use a complete live template snapshot and stop retaining saved structural summaries when the editor document becomes empty or materially changes.

#### Steps

1. Always build and send the live template structure snapshot in `TemplateRecommender.js`.
   Remove the current `templateBlocks.length > 0` guard so the empty template path produces an explicit snapshot instead of omitting one.

2. Extend `buildEditorTemplateTopLevelStructureSnapshot()` to include `structureStats`.
   The stats should match the fields `TemplatePrompt::build_user()` already renders:
   - `blockCount`
   - `maxDepth`
   - `topLevelBlockCount`
   - `hasNavigation`
   - `hasQuery`
   - `hasTemplateParts`
   - `firstTopLevelBlock`
   - `lastTopLevelBlock`

3. Continue deriving template insertion anchors from the live top-level tree on the server.
   This keeps one canonical anchor format and avoids duplicating anchor computation in both layers unless tests show a mismatch.

4. Always build and send live slot occupancy.
   `buildEditorTemplateSlotSnapshot()` should be called for empty templates too so `assignedParts` and `emptyAreas` can explicitly become empty.

5. Merge live template areas into the effective allowed-area contract.
   Keep server-derived capability data as the baseline, but union it with any live areas implied by unsaved `assignedParts` or `emptyAreas`.
   This prevents a fully empty template from losing useful area availability metadata while still allowing newly inserted unsaved areas to remain prompt-visible and executable.

6. Expand the template context signature so it matches the live prompt inputs.
   The signature should include:
   - slot occupancy fields that can change with editor edits
   - `topLevelBlockTree`
   - `structureStats`
   - `currentPatternOverrides`
   - `currentViewportVisibility`
   - sorted `visiblePatternNames`

7. Update template docs and examples to show the richer request contract and the live/server overlay boundary clearly.

#### Tests

1. JS: empty templates still serialize `editorStructure` and slot occupancy into the fetch payload.
2. JS: the context signature changes when the live template becomes empty, when top-level structure changes, and when visibility or override summaries change.
3. PHP: `TemplateAbilities::recommend_template()` replaces saved `structureStats` and `topLevelBlockTree` with live values when present.
4. PHP: effective `allowedAreas` includes unsaved live areas derived from editor slot occupancy instead of staying stuck on saved capability metadata only.
5. PHP: the prompt uses live zeroed structure stats for empty templates instead of saved stats.
6. PHP: server-derived insertion anchors are rebuilt from the live `topLevelBlockTree`.

#### Exit Criteria

1. Empty or unsaved template edits no longer leak saved structural summaries into template prompts.
2. Template stale detection now follows the same fields that the prompt actually uses.

### Workstream 5: Align Docs And Surface Contracts

#### Objective

Make the written contracts match the real transport and prompt behavior after the implementation lands.

#### Steps

1. Update `docs/features/template-recommendations.md`.
   Clarify:
   - which fields come from live editor state
   - which fields stay server-canonical
   - that empty live templates still send explicit structure snapshots

2. Update `docs/features/template-part-recommendations.md`.
   Clarify:
   - that template-part prompts now use live unsaved structure, not only saved entity content plus override summaries
   - that request payloads include executable targets and anchors

3. Update `docs/reference/abilities-and-routes.md`.
   Refresh the request schemas and examples for:
   - `POST /flavor-agent/v1/recommend-template`
   - `POST /flavor-agent/v1/recommend-template-part`
   Document that template `allowedAreas` is an effective merged set, and that template-part requests carry full live path coverage for validation even if the prompt-facing tree is summarized.

4. Keep block, pattern, and style docs unchanged unless test-backed regressions force a wording tweak.

5. Cross-link this plan with the stale-state alignment plan.
   The docs should note that richer context signatures feed stale detection, while UI stale presentation still follows the separate stale-state plan.

#### Exit Criteria

1. Feature docs, route docs, and runtime behavior describe the same contract.
2. There is no remaining doc claim that suggests template-part prompts are grounded only on saved content.

### Workstream 6: Add Regression Protection For Block, Pattern, And Styles

#### Objective

Lock in the healthy surfaces while the shared recommendation plumbing changes.

#### Steps

1. Add or tighten block-surface tests.
   Confirm that:
   - `src/context/collector.js` still collects live selected-block context
   - `buildBlockRecommendationContextSignature()` only changes when relevant block context changes
   - `BlockRecommendationsPanel` still uses the same signature-driven stale logic

2. Add or tighten pattern-surface tests.
   Confirm that:
   - `PatternRecommender` still sends `visiblePatternNames`
   - empty visible pattern lists still short-circuit correctly
   - server-side pattern ability behavior is unchanged

3. Add or tighten style-surface tests.
   Confirm that:
   - `GlobalStylesRecommender` and `StyleBookRecommender` still send live style context
   - `StylePrompt` still renders supported path and semantic context correctly

4. Avoid changing block, pattern, or style production logic unless a regression test exposes a real issue.

#### Exit Criteria

1. The fix set does not alter the healthy recommendation surfaces.
2. The repo has explicit tests that will fail if a future refactor regresses those surfaces.

## Delivery Order

Ship the work in this order:

1. Expand the client request builders and signatures.
2. Expand REST and ability schemas.
3. Add server-side overlay helpers.
4. Land template-part behavior and tests.
5. Land template behavior and tests.
6. Update docs.
7. Run the healthy-surface regression suites.

This order keeps the transport contract and server merge logic aligned before prompt assertions are updated.

## Verification

### Targeted PHP

```bash
vendor/bin/phpunit \
  tests/phpunit/TemplatePromptTest.php \
  tests/phpunit/TemplatePartPromptTest.php \
  tests/phpunit/ServerCollectorTest.php \
  tests/phpunit/AgentControllerTest.php \
  tests/phpunit/RegistrationTest.php \
  tests/phpunit/DocsGroundingEntityCacheTest.php \
  tests/phpunit/BlockAbilitiesTest.php \
  tests/phpunit/PatternAbilitiesTest.php \
  tests/phpunit/StylePromptTest.php
```

### Targeted JS

```bash
npm run test:unit -- \
  src/templates/__tests__/template-recommender-helpers.test.js \
  src/templates/__tests__/TemplateRecommender.test.js \
  src/template-parts/__tests__/TemplatePartRecommender.test.js \
  src/context/__tests__/collector.test.js \
  src/inspector/__tests__/BlockRecommendationsPanel.test.js \
  src/patterns/__tests__/PatternRecommender.test.js \
  src/global-styles/__tests__/GlobalStylesRecommender.test.js \
  src/style-book/__tests__/StyleBookRecommender.test.js
```

### Manual Editor Checks

1. Template:
   create a recommendation, make an unsaved structural change, verify the result becomes stale, refresh, and confirm the prompt-derived suggestion changes.
2. Template:
   clear the template to empty, request recommendations, and verify the prompt no longer describes saved structure.
3. Template:
   add an unsaved template-part slot in a new area, request recommendations, and verify the prompt and executable validation treat that area as allowed.
4. Template part:
   add or remove a block without saving, request recommendations, and verify the prompt uses the new live targets and anchors.
5. Template part:
   clear the template part to empty, request recommendations, and verify no saved `blockTree` or saved structural constraints leak into the prompt.
6. Template part:
   create a deep nested block path beyond the prompt-tree depth cap, request recommendations, and verify path-based targets remain executable because validation uses the full live path index.
7. Pattern:
   verify results still respect current `visiblePatternNames`.
8. Styles:
   verify style recommendations still track the live style book and global styles scope.

## Risks And Mitigations

1. Risk: the editor and server snapshot shapes drift again.
   Mitigation: keep the schema, feature docs, and unit tests updated together, centralize snapshot normalization in helper functions instead of duplicating inline object assembly, and validate client-provided derived summaries against the same live structural source.

2. Risk: template `allowedAreas` becomes less useful if the client blindly overrides it.
   Mitigation: do not blindly replace server capability data; compute effective `allowedAreas` as a union of server-known areas plus unsaved live areas implied by editor slot occupancy.

3. Risk: template-part client helpers duplicate too much server analyzer logic.
   Mitigation: keep the client snapshot minimal but complete for prompt relevance, prefer server recomputation for derived summaries where practical, and validate deep paths against a full live path index rather than against the depth-limited prompt tree.

4. Risk: stale-state behavior becomes harder to reason about with richer signatures.
   Mitigation: keep stale presentation work in the existing stale-state plan and only widen the signature here.

5. Risk: docs grounding and family-cache behavior drifts from the new live/server overlay contract.
   Mitigation: update template and template-part docs-guidance tests alongside the request contract, and verify the docs-query/family-context builders still reflect the intended merged live context.

## Done When

This plan is complete when:

1. Template and template-part prompts are grounded on live unsaved structure whenever the editor can provide it.
2. Empty live documents are represented explicitly and never backfill saved structure summaries.
3. REST docs, ability schemas, and feature docs describe the same request contract.
4. Unsaved template areas and deep template-part paths stay valid in prompt and apply validation.
5. Block, pattern, and style regression suites remain green without production behavior changes.
