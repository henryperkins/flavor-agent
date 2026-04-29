# Recommendation Actionability Implementation Plan

Date: 2026-04-29

Status: canonical implementation scope for recommendation actionability and narrow block structural actions.

This plan supersedes the broader implementation sequencing in `docs/recommendation-improvement-report-and-checklist.md` when there is a scope conflict. The broader checklist remains the backlog and checklist source. `recommendation-actionability-review.md` remains the rationale and architecture review.

## Source Inputs

- `docs/recommendation-improvement-report-and-checklist.md`
- `recommendation-actionability-review.md`
- `docs/reference/template-operations.md`
- `docs/features/block-recommendations.md`
- `docs/reference/block-recommendation-review-remediation-plan.md`
- `docs/reference/cross-surface-validation-gates.md`
- `docs/reference/wordpress-ai-roadmap-tracking.md`
- `docs/reference/gutenberg-feature-tracking.md`
- ChatGPT transcript "Turning Advisory Patterns Insertable", pasted into the planning thread on 2026-04-29. Use it only where it aligns with repository docs and runtime constraints.

## Thesis

Keep Flavor Agent as an editor-bound recommendation layer. Let the LLM propose richer recommendations, but promote only the subset that can be reduced to deterministic, allow-listed, validator-computed, live-state-checked operations.

Eligibility is never model-authored authorization. The model can propose operations and advisory text; local parser and validator code computes the final `inline-safe`, `review-safe`, or `advisory` state.

## Current Implementation State

Implemented in the current working branch:

- `src/utils/recommendation-actionability.js` defines validator-sourced tiers, blockers, executable operation metadata, and advisory rejection metadata.
- `src/store/update-helpers.js` attaches computed eligibility to block suggestions while preserving current safety behavior.
- `src/inspector/BlockRecommendationsPanel.js` surfaces eligibility labels, blocker reasons, block-keyed structural review state, and a non-mutating `Review first` lane for validator-approved operations.
- `src/inspector/block-review-state.js` keys active block structural review state by `clientId`, request token, request signature, and suggestion key.
- `src/utils/block-operation-catalog.js` defines a versioned block operation catalog for `insert_pattern` and `replace_block_with_pattern`.
- `flavor-agent.php` localizes the default-off block structural actions rollout flag for JS, with strict boolean parsing for constants and filters.
- `src/utils/block-allowed-pattern-context.js` builds deterministic allowed-pattern context for selected-block pattern actions when the rollout flag is enabled.
- `src/context/collector.js`, `inc/Abilities/BlockAbilities.php`, and `inc/LLM/Prompt.php` carry sanitized `blockOperationContext` into the block prompt.
- `inc/Context/BlockOperationValidator.php` is the authoritative PHP validator for the block operation catalog.
- Block-lane responses now normalize structural metadata into `operations`, `proposedOperations`, and `rejectedOperations`; multiple proposed structural operations are rejected in M2.
- Focused JS tests cover actionability, block suggestion execution metadata, inspector surfacing, and block operation catalog validation.

Still intentionally not implemented:

- Block structural apply/undo.
- Any ordinary native pattern inserter activity ownership.

## MVP Scope

The MVP is narrow:

- Keep one-click block apply limited to safe local block attribute updates.
- Add validator-computed eligibility/actionability metadata to normalized client payloads.
- Add a review-safe block structural path only for selected-block pattern operations:
  - `insert_pattern` before the selected block.
  - `insert_pattern` after the selected block.
  - `replace_block_with_pattern` for the selected block.
- Gate structural actions behind a default-off rollout flag until the full review/apply/undo path and negative tests are complete.
- Keep pattern recommendations in the standalone pattern shelf ranking/browse-only.
- Keep template, template-part, Global Styles, and Style Book review-first behavior intact.

## Explicit Non-Goals

- No site agent, admin-wide agent, frontend chat agent, or general site mutation surface.
- No provider router, model selector, credential flow, or plugin-owned chat provider expansion.
- No new observability product or admin audit expansion beyond current activity entries.
- No ordinary native pattern inserter activity/undo ownership.
- No arbitrary structural rewrites, remove-block operation, move-block operation, container start/end insertion, navigation mutation, or cross-surface target mutation in the first block structural release.
- No direct reuse of template-part apply helpers without extracting shared structural primitives.
- No prompt/schema contract change unless parser, REST/Abilities, JS normalizer, UI, docs, and tests are updated together.

## Workstream A: Foundation And Contracts

Goal: make actionability explicit without changing structural apply behavior.

Required work:

- Finalize the shared `RecommendationEligibility` shape in code and docs.
- Keep `source: 'validator'` for final eligibility.
- Preserve proposed operations, executable operations, rejected operations, and advisory remainder separately.
- Add activity diagnostic summaries where useful, without implying new execution capability.
- Update `docs/reference/recommendation-ui-consistency.md` only after UI taxonomy is stable across surfaces.

Acceptance criteria:

- Existing block structural and pattern suggestions remain advisory-only.
- A model-supplied eligibility field cannot make a suggestion executable.
- Tests prove inline-safe eligibility only appears when local validators return executable updates.

## Workstream B: Rollout Flag

Goal: make structural actions impossible to accidentally expose before the complete safety pipeline exists.

Recommended MVP flag source:

- PHP constant `FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS`, default `false`.
- Filter `flavor_agent_enable_block_structural_actions` for local/test overrides.
- Localized JS value under `window.flavorAgentData`.
- No settings UI in MVP; a plugin setting can be added later if beta rollout needs site-admin control.

Disabled behavior:

- Block prompts do not request structural operations.
- If structural proposals arrive anyway, normalizers keep them advisory.
- REST/Abilities responses may include advisory diagnostics but must not include executable structural operations.
- Existing inline-safe attribute updates remain unaffected.

Acceptance criteria:

- Unit tests cover disabled flag normalization.
- UI cannot show a structural review/apply path when disabled.
- Ability/REST behavior is documented in `docs/reference/abilities-and-routes.md` when the contract changes.

## Workstream C: Deterministic Allowed Pattern Context

Goal: constrain pattern choices before the model responds.

Context must be built from editor/runtime state, not model output.

Allowed pattern context shape:

```ts
type AllowedPatternForRecommendation = {
  name: string;
  title: string;
  source: 'core' | 'theme' | 'plugin' | 'user';
  categories?: string[];
  blockTypes?: string[];
  allowedActions: Array<'insert_before' | 'insert_after' | 'replace'>;
};
```

Required work:

- Gather only visible, readable, renderable patterns.
- Exclude patterns not insertable in the current editor context.
- Compute `allowedActions` per selected target.
- Include target identity and recommendation-time target signature.
- Keep `visiblePatternNames` as the hard insertion scope for the standalone pattern shelf.
- Do not attach Flavor Agent activity to ordinary inserter use.

Acceptance criteria:

- The model can choose only from the allowed list.
- Unknown pattern names are rejected locally and stay advisory.
- Pattern disappearance or action invalidation between recommendation and apply demotes/blocks execution.

Implementation note: as of 2026-04-29, the rollout-gated request/prompt context exists and is sanitized server-side. Workstream D now parses model-supplied structural proposals, but only validator-approved operations enter `operations[]`.

## Workstream D: Block Operation Proposal Contract

Goal: add proposals without making proposals authoritative.

Operation catalog v1:

| Operation | Required fields | Allowed actions | Target |
| --- | --- | --- | --- |
| `insert_pattern` | `patternName`, `targetClientId`, `position` | `insert_before`, `insert_after` | selected block only |
| `replace_block_with_pattern` | `patternName`, `targetClientId` | `replace` | selected block only |

Prompt-facing proposal examples:

```json
{
  "type": "insert_pattern",
  "patternName": "flavor-agent/hero-with-cta",
  "position": "insert_after",
  "targetClientId": "selected-block-client-id"
}
```

```json
{
  "type": "replace_block_with_pattern",
  "patternName": "flavor-agent/feature-grid",
  "targetClientId": "selected-block-client-id"
}
```

Response normalization uses the existing block suggestion item shape and supports attribute-only suggestions with empty structural operations:

```json
{
  "label": "Make the CTA clearer",
  "description": "The button copy can be more direct.",
  "attributeUpdates": {
    "text": "Get started"
  },
  "operations": [],
  "proposedOperations": [],
  "rejectedOperations": []
}
```

Required work:

- Extend `inc/LLM/ResponseSchema.php` only after prompt, parser, REST, and JS handling are ready together.
- Update `inc/LLM/Prompt.php` to describe the operation catalog and allowed pattern list.
- Add server-side parser validation for unknown operation types, unknown pattern names, stale targets, invalid insertion positions, locked/content-only targets, and cross-surface targets.
- Mirror validation in JS via the block operation catalog.
- Normalize mixed recommendations into executable operations plus advisory remainder.

MVP constraint:

- The validator may parse multiple proposals, but the first apply-capable release should apply at most one structural operation per recommendation unless the transaction engine and negative tests cover multi-operation partial failure.
- M2 rejects multiple proposed structural operations in one block suggestion. More complex recommendations should be decomposed into atomic operations only when the operation engine supports every step, including target resolution for newly inserted child blocks and all-or-nothing rollback. Until then, emit a single structural operation plus advisory remainder.

Acceptance criteria:

- Parser fixtures cover valid, invalid, and mixed operation payloads.
- Structural recommendations normalize to advisory when flag, schema, pattern context, target, lock, or validation fails.
- Attribute-only suggestions do not need structural operations to remain executable as inline-safe updates.

## Workstream E: Block-Keyed Review State

Goal: add review-first UX without force-fitting block structural actions into unkeyed executable-surface state.

Required work:

- Add block-keyed review state by `clientId`, request token, and request signature.
- Show a review lane distinct from `Apply now` local-attribute chips.
- Disable review apply for stale local signatures or stale server-resolved signatures.
- Keep advisory cards visible when only part of a recommendation is executable.
- Preserve current delegated native sub-panel behavior as passive mirrors only.

Acceptance criteria:

- `Apply now` still means inline-safe local attributes.
- `Review first` means deterministic structural operation selected for non-mutating review; confirmation and apply remain M4 scope.
- Stale structural recommendations remain visible but cannot enter review or apply.

## Workstream F: Transactional Apply, Activity, And Undo

Goal: make structural mutation safe under editor drift, locks, and collaborative changes.

Required apply checks:

- Recommendation-time target signature matches the live selected target.
- Live selected block still exists and matches expected identity.
- Target is not locked or content-only.
- Pattern still exists and still permits the requested action.
- Feature flag remains enabled.
- Operation validator still returns an executable operation.

Required apply behavior:

- Capture pre-apply structural signature.
- Apply transactionally.
- Capture post-apply structural signature.
- If any operation fails, leave the editor unchanged.
- Record activity only after successful apply.
- Include rollback payloads in activity metadata.

Required undo behavior:

- Undo is available only when current live editor state matches the recorded post-apply signature.
- If live state diverged, disable automatic undo and show manual recovery guidance.
- Do not undo over user edits or collaborative changes.
- For current inline block attribute undo, preserve the accepted contract from `docs/reference/block-recommendation-review-remediation-plan.md`: path drift is diagnostic-only when the same `clientId`, block name, and post-apply attributes still match.

Acceptance criteria:

- Negative tests cover deleted selected block, moved target into locked parent, target lock transition, content-only transition, pattern disappearance, pattern action invalidation, user edits before undo, and partial failure.
- Activity rows distinguish inline-safe attribute applies from review-safe structural applies.

## Workstream G: Template-Part Promotion

Goal: harvest a lower-risk win from an already review-safe surface.

This is the first product-facing promotion target after foundation because template-part recommendations already have the closest matching review/apply/undo lifecycle. It is separate from block structural actions and should not delay Workstreams B-D foundation work.

Allowed work:

- Improve template-part prompt/context behavior so more valid `operations[]` are produced.
- Keep invalid or ambiguous `patternSuggestions` advisory.
- Reuse the existing template-part review/apply/undo flow.

Constraints:

- Do not deepen assumptions around standalone Pattern Overrides; Gutenberg is moving toward Block Fields and Block Bindings.
- Do not extract shared structural helpers from template-part code unless block apply work needs them immediately.

## Workstream H: Later Backlog

Keep these outside the first block structural release unless explicitly pulled forward:

- Pattern ranking explanations and empty-result diagnostics.
- Style contrast/readability validation and paired color transaction semantics.
- Native variation preview improvements.
- Ability annotation cleanup across all abilities.
- Observability Logger bridge if upstream lands first.
- Core revisions handoff for template/template-part/pattern undo.

## Milestones

### M0: Foundation Complete

Exit criteria:

- Validator-computed eligibility metadata is present in JS for block recommendations.
- Block operation catalog exists with parser fixtures.
- Structural/pattern block suggestions remain advisory.

Current branch status: substantially complete.

### M1: Feature Flag And Pattern Context

Exit criteria:

- Default-off feature flag is wired through PHP and JS.
- Allowed pattern context is deterministic and tested.
- Disabled flag and invalid pattern context normalize structural proposals to advisory.

### M1A: Template-Part Yield Improvement

Exit criteria:

- Template-part prompts/context produce more valid `operations[]` without broadening the operation vocabulary.
- Invalid or ambiguous `patternSuggestions` stay advisory.
- Existing template-part review/apply/undo behavior remains unchanged.

Detailed implementation plan: `docs/reference/recommendation-actionability-m4-m1a-plan.md`.

### M2: Prompt, Schema, And Server Parser

Exit criteria:

- Block prompt can request only catalog operations from the allowed pattern list.
- Response schema supports proposed operations on block-lane items.
- Server parser computes final operation metadata and separates executable operations from advisory remainder.
- No structural review UI or structural mutation is introduced by M2.
- REST/Abilities contract docs are updated.

M2 status: closed. The `No structural review UI` criterion applies only to that historical milestone; the non-mutating review lane below is M3 scope.

### M3: Review UI Without Apply

Exit criteria:

- Block-keyed review state exists.
- Review lane renders validated operations.
- Stale and disabled states block review apply.
- No structural mutation occurs yet.

Current branch status: complete. The block panel separates inline-safe `Apply now` suggestions, review-safe structural suggestions, and advisory/manual remainders. The review lane opens scoped non-mutating details only; structural apply remains unavailable until M4.

### M4: Transactional Apply And Undo Behind Flag

Exit criteria:

- Apply path revalidates live target, locks, pattern availability, and operation validity immediately before mutation.
- Apply is transactional.
- Activity and undo metadata are recorded only after successful apply.
- Undo is disabled on post-apply live-state divergence.
- Race and negative tests are green.

Detailed implementation plan: `docs/reference/recommendation-actionability-m4-m1a-plan.md`.

## Validation Gates

Use `docs/reference/cross-surface-validation-gates.md` as the release gate reference.

Minimum evidence before any structural apply release:

- Targeted JS tests for catalog, normalizers, store/review state, UI, apply, activity, and undo.
- Targeted PHPUnit for prompt/schema/parser/REST/Abilities changes.
- `npm run check:docs` when contracts or contributor-facing docs change.
- `node scripts/verify.js --skip-e2e` before sign-off.
- `npm run test:e2e:playground` for block/pattern post-editor behavior.
- `npm run test:e2e:wp70` only when Site Editor/template/style surfaces are touched.
- Recorded blocker or waiver for any unavailable browser harness.

## Decision Log

- Final eligibility is validator-computed, not LLM-declared.
- Block structural actions are review-safe, not inline-safe.
- One-click block apply remains limited to safe local attributes.
- Structural operations are editor-bound selected-block operations only in v1.
- Feature flag defaults off until transactional apply, activity, undo, and negative tests are complete.
- Ordinary native pattern inserter use remains owned by Gutenberg, not Flavor Agent.
- Upstream Site Agent, Observability Logger, provider routing, ability exposure policy, and core revisions are watched but not reimplemented here.
