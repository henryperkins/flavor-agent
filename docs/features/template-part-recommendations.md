# Template-Part Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: Site Editor document settings panel titled `AI Template Part Recommendations`
- Scope: only while editing a `wp_template_part` entity
- UI shape: shared setup/capability notice when unavailable, otherwise a compact scope bar and prompt field, collapsed result rationale, one `Recommendations` list with compact cards, focus-block and suggested-pattern details behind per-card disclosures, a shared lower review-before-apply panel, collapsed recent activity, and inline undo after the activity section is opened

## Surfacing Conditions

- `TemplatePartRecommender()` must resolve a current template-part reference through the shared edited-entity resolver, preferring `core/editor` and falling back to `core/edit-site`
- The shared `wp_template_part` entity contract from `usePostTypeEntityContract()` must resolve so the panel can align its title and area labels with the current WordPress template-part contract while still falling back to built-in field and area metadata when no live view config is exposed
- Once the `wp_template_part` reference and entity contract resolve, the panel stays visible with a notice when `window.flavorAgentData.canRecommendTemplateParts` is false; the localized flag is driven by the shared surface-capability contract and flips on when a compatible text-generation provider is configured in `Settings > Connectors`
- The panel clears recommendations on hard template-part changes, but keeps same-template-part drifted results visible as stale until the user refreshes or a fresh result arrives

## Shared Interaction Model

- Learned-once sequence: scope/freshness -> prompt -> status/stale refresh -> collapsed rationale -> recommendations -> lower review panel where needed -> apply where allowed -> collapsed undo history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Template-part recommendations move `idle -> loading -> advisory-ready` after results arrive, then `preview-ready` only when the user explicitly opens preview on a validated suggestion
- Executable and advisory suggestions now share one compact `Recommendations` list. Executable cards keep the `Review` button; advisory cards are labeled `Manual`. Focus-block and pattern support material stays available behind each card's `Details` disclosure.
- Preview uses the shared `AIReviewSection` shell in a dedicated lower panel and post-apply / post-undo feedback uses the same shared status notice pattern as block and template
- Suggestions that fail deterministic validation stay visible in the shared advisory shell so the user can still review focus blocks and pattern ideas without getting an apply affordance
- Template-part freshness uses the local request signature plus server review/apply hashes that include the docs-grounding fingerprint. PHP stores `reviewContextSignature` for background review revalidation and `resolvedContextSignature` for apply safety, so unavailable docs grounding can stale stored results while stale/degraded trusted guidance can surface as a warning.

## End-To-End Flow

1. `TemplatePartRecommender()` resolves the current template-part reference through the shared edited-entity resolver, then derives slug and area using both the template-part area lookup and the normalized `wp_template_part` entity contract returned by `usePostTypeEntityContract()`
2. The component builds the request through `buildTemplatePartFetchInput()`, including `visiblePatternNames` plus a full live template-part structure snapshot from `buildEditorTemplatePartStructureSnapshot()`
3. `fetchTemplatePartRecommendations()` in the store executes the `flavor-agent/recommend-template-part` ability
4. `FlavorAgent\Abilities\RecommendationAbilityExecution` adapts the request to `FlavorAgent\Abilities\TemplateAbilities::recommend_template_part()`
5. `TemplateAbilities::recommend_template_part()` gathers canonical template-part metadata through `ServerCollector::for_template_part()`, atomically overlays the live unsaved structural slice from the editor, validates path-based targets and anchors against the full live path index, scopes docs grounding with `currentPatternOverrides`, computes `reviewContextSignature` and `resolvedContextSignature` with the docs-grounding fingerprint, returns early for signature-only revalidation, then calls `ResponsesClient::rank()` through `FlavorAgent\LLM\TemplatePartPrompt`
6. The parsed response returns explanation text, advisory `blockHints`, advisory `patternSuggestions`, and optional structured `operations`
7. `buildTemplatePartSuggestionViewModel()` validates the operation sequence before the UI offers preview or apply controls
8. The user can expand a card for focus-block links and suggested patterns, browse suggested patterns in the inserter, open the shared lower review panel for validated operations, and confirm apply
9. `applyTemplatePartSuggestion()` runs the deterministic executor, records activity, and exposes inline undo for the newest valid tail entry

## Flow Diagram

```text
User prompt in wp_template_part editor
  -> TemplatePartRecommender
  -> buildTemplatePartFetchInput()
  -> fetchTemplatePartRecommendations()
  -> flavor-agent/recommend-template-part ability
  -> RecommendationAbilityExecution
  -> TemplateAbilities::recommend_template_part()
  -> ServerCollector::for_template_part()
  -> TemplatePartPrompt::parse_response()
  -> validateTemplatePartOperationSequence()
  -> preview exact operations
  -> applyTemplatePartSuggestion()
  -> activity + inline undo
```

## Contract Pointers

- Ability request and response shape: `docs/reference/abilities-and-routes.md#template-part-ability-request`
- Operation vocabulary, placement rules, and deep-path validation: `docs/reference/template-operations.md#template-part-operations`
- Activity entry shape and undo lifecycle: `docs/reference/abilities-and-routes.md#activity-entry-shape` and `docs/reference/activity-state-machine.md`

The prompt-facing `blockTree` may stay summarized, but `editorStructure.allBlockPaths` carries the full live path coverage the server uses to validate deep executable targets. Empty template parts still send an explicit structure snapshot with empty trees, zeroed stats, no targets, and start/end anchors.

## Request Freshness And Server Revalidation

- The panel still computes a local request signature from `templatePartRef + prompt + contextSignature` so stale review cards are detected immediately when the prompt or live structure drifts.
- Normal template-part responses now also store `reviewContextSignature`, `resolvedContextSignature`, and `docsGroundingFingerprint`. The review hash is used by background `revalidateTemplatePartReviewFreshness()` checks, while the resolved hash is based on the server-normalized structural apply context plus the sanitized prompt after live overlays, server-only context, and docs grounding have been resolved.
- `applyTemplatePartSuggestion()` keeps the local stale guard first, then re-posts the same request with `resolveSignatureOnly: true` and only allows apply when the current `resolvedContextSignature` still matches the stored result; review revalidation compares `reviewContextSignature` and can mark the stored result stale when trusted docs grounding is unavailable.
- Template-part docs grounding now uses `currentPatternOverrides` in the query text, while the family cache key stays coarse and records only bounded override booleans and counters. Full requests use the shared cache/fallback collector, so exact, family, and entity cache hits are reused immediately; on generic or missing fallback guidance, a request may perform a foreground docs warm before queuing async warming.

## Example Preview Contract

```text
Review Before Apply
  Insert `core/header-with-utility-row`
  -> Before target block (Path 0 > 1)
```

## What This Surface Can Do

- Suggest focus blocks inside the current template part
- Suggest relevant patterns to browse in the inserter
- Offer preview-confirm-apply only for operation sets that survive deterministic validation against executable paths and anchors
- Record template-part apply actions in the shared activity system and expose inline undo

## Operation Contract

Template-part operation types, placement rules, and executable target validation are canonical in `docs/reference/template-operations.md#template-part-operations`. Deep paths are validated against the full live path index, not only the depth-limited prompt tree.

## Guardrails And Failure Modes

- Unsupported or ambiguous suggestions stay advisory-only and never receive apply controls
- Only the exact validated operations shown in preview can be applied
- Apply is also blocked when the stored `resolvedContextSignature` no longer matches the current server-resolved apply context
- There is no free-form template-part rewrite path
- Undo is blocked if the live document no longer matches the persisted post-apply snapshot or if newer still-applied AI actions remain in the tail

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| UI shell | `TemplatePartRecommender()` in `src/template-parts/TemplatePartRecommender.js` | Renders the panel, advisory links, preview flow, activity, and undo |
| Input builder | `buildTemplatePartFetchInput()` | Normalizes the recommendation request |
| Validation | `validateTemplatePartOperationSequence()` in `src/utils/template-operation-sequence.js` | Rejects unsupported or ambiguous operation sets before apply UI appears |
| Store request | `fetchTemplatePartRecommendations()` in `src/store/index.js` | Sends the recommendation request |
| Store apply | `applyTemplatePartSuggestion()` in `src/store/index.js` | Runs deterministic apply and records activity |
| Deterministic executor | `applyTemplatePartSuggestionOperations()` in `src/utils/template-actions.js` | Executes validated operations against the live editor state |
| Focus helpers | `selectBlockByPath()` and `openInserterForPattern()` | Drive the advisory links inside suggestion cards |
| Ability wrapper | `RecommendationAbilityExecution::execute()` | Adapts the ability request to the backend handler |
| Backend ability | `TemplateAbilities::recommend_template_part()` | Builds context and returns template-part suggestions |
| Prompt contract | `TemplatePartPrompt::build_user()` / `TemplatePartPrompt::parse_response()` | Defines and validates the structured output |

## Related Abilities

- Ability: `flavor-agent/recommend-template-part`
- Helper ability: `flavor-agent/list-template-parts`

## Key Implementation Files

- `src/template-parts/TemplatePartRecommender.js`
- `src/template-parts/template-part-recommender-helpers.js`
- `src/utils/template-actions.js`
- `src/utils/template-operation-sequence.js`
- `src/utils/template-part-areas.js` — four-tier template-part area resolution; see `docs/reference/shared-internals.md`
- `src/utils/editor-entity-contracts.js` — dual-store entity resolution and `usePostTypeEntityContract` hook; see `docs/reference/shared-internals.md`
- `src/utils/editor-context-metadata.js` — pattern override summaries; see `docs/reference/shared-internals.md`
- `src/utils/visible-patterns.js`
- `src/components/CapabilityNotice.js` — shared backend-unavailable notice; see `docs/reference/shared-internals.md`
- `src/components/AIStatusNotice.js` — shared contextual status feedback; see `docs/reference/shared-internals.md`
- `src/components/AIReviewSection.js` — shared review-before-apply panel; see `docs/reference/shared-internals.md`
- `src/store/index.js`
- `src/store/abilities-client.js`
- `inc/Abilities/RecommendationAbilityExecution.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/Context/ServerCollector.php`
