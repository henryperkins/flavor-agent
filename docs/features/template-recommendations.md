# Template Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: Site Editor document settings panel titled `AI Template Recommendations`
- Scope: only while editing a `wp_template` entity
- UI shape: shared setup/capability notice when unavailable, otherwise a prompt field, explanation text with linked entities, grouped `Review first` / `Manual ideas` lanes, a stale-result refresh hero when needed, a shared lower review-before-apply panel, recent activity, and inline undo

## Surfacing Conditions

- `TemplateRecommender()` must resolve a current template reference through `core/editor` or `core/edit-site`
- The shared `wp_template` entity contract from `usePostTypeEntityContract()` must resolve so the panel can align with the current Site Editor template shape while still falling back to built-in field metadata when no live view config is exposed
- Once the `wp_template` reference and entity contract resolve, the panel stays visible with a notice when `window.flavorAgentData.canRecommendTemplates` is false; the localized flag is driven by the shared surface-capability contract and flips on when a compatible text-generation provider is configured in `Settings > Connectors`
- The panel clears recommendations on hard template changes, but keeps same-template drifted results visible as stale until the user refreshes or a fresh result arrives

## Shared Interaction Model

- Learned-once sequence: scope/freshness -> prompt -> status/stale refresh -> explanation -> grouped lanes -> lower review panel where needed -> apply where allowed -> undo and history. `RecommendationHero` is reserved for stale refresh in the compact template shell.
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Template recommendations move `idle -> loading -> advisory-ready` after results arrive, then `preview-ready` only after the user explicitly opens preview on a validated suggestion
- Fresh template results render explanation text first, then executable suggestions in the `Review first` lane and non-deterministic ideas in `Manual ideas`; stale same-template results surface a refresh hero before those lanes
- Preview uses the shared `AIReviewSection` shell in a dedicated lower panel and post-apply / post-undo feedback uses the shared status notice pattern
- Executable suggestions still require validated operations, while advisory-only suggestions survive server-side parsing when their template-part or pattern summaries validate without a safe deterministic apply path
- Template freshness now uses the local request signature plus server review/apply hashes that include the docs-grounding fingerprint. PHP stores `reviewContextSignature` for background review revalidation and `resolvedContextSignature` for apply safety, so unavailable docs grounding can stale stored results while stale/degraded trusted guidance can surface as a warning.

## End-To-End Flow

1. `TemplateRecommender()` resolves the current `wp_template` reference through the shared edited-entity resolver, reads the normalized `wp_template` entity contract through `usePostTypeEntityContract()`, and derives the template type
2. The component derives editor slot state through `buildEditorTemplateSlotSnapshot()`, captures the template-global `visiblePatternNames`, and always sends an explicit live template snapshot through `buildEditorTemplateTopLevelStructureSnapshot()`, including zeroed empty-state stats, live pattern-override summaries, and viewport-visibility summaries
3. `buildTemplateFetchInput()` creates the request payload and `fetchTemplateRecommendations()` executes the `flavor-agent/recommend-template` ability
4. `FlavorAgent\Abilities\RecommendationAbilityExecution` adapts the request to `FlavorAgent\Abilities\TemplateAbilities::recommend_template()`
5. `TemplateAbilities::recommend_template()` gathers canonical template metadata through `ServerCollector::for_template()`, atomically overlays the mutable live slot and structure slices from the editor, merges effective `allowedAreas` from server-known capabilities plus unsaved live areas, scopes docs grounding with bounded `visiblePatternNames`, computes `reviewContextSignature` and `resolvedContextSignature` with the docs-grounding fingerprint, returns early for signature-only revalidation, then calls `ResponsesClient::rank()` through `FlavorAgent\LLM\TemplatePrompt`
6. The parsed response returns up to three suggestion cards, preserving validated structured operations for executable ideas and validated summaries for advisory ideas
7. The UI builds an entity map so template-part slugs, areas, and pattern names inside descriptions and explanations become clickable actions
8. Selecting an executable suggestion opens the shared lower review panel, which shows the exact validated operations that would run if the user confirms apply
9. `applyTemplateSuggestion()` in the store calls the deterministic executor, records activity, and exposes inline undo for the newest valid tail entry

## Flow Diagram

```text
User prompt in wp_template editor
  -> TemplateRecommender
  -> buildEditorTemplateSlotSnapshot() + buildEditorTemplateTopLevelStructureSnapshot() + visiblePatternNames
  -> buildTemplateFetchInput()
  -> fetchTemplateRecommendations()
  -> flavor-agent/recommend-template ability
  -> RecommendationAbilityExecution
  -> TemplateAbilities::recommend_template()
  -> ServerCollector::for_template()
  -> TemplatePrompt::parse_response()
  -> preview validated operations
  -> applyTemplateSuggestion()
  -> activity + inline undo
```

## Example Request

```json
{
  "templateRef": "theme//single-post",
  "templateType": "single",
  "prompt": "Make the article template feel more magazine-like.",
  "editorSlots": {
    "assignedParts": [
      {
        "slug": "header",
        "area": "header"
      },
      {
        "slug": "footer",
        "area": "footer"
      }
    ],
    "emptyAreas": ["sidebar"]
  },
  "editorStructure": {
    "topLevelBlockTree": [
      {
        "path": [0],
        "name": "core/template-part",
        "label": "header template part (header)",
        "attributes": { "slug": "header", "area": "header" },
        "childCount": 0,
        "slot": { "slug": "header", "area": "header", "isEmpty": false }
      },
      {
        "path": [1],
        "name": "core/group",
        "label": "Group",
        "attributes": {},
        "childCount": 2
      }
    ],
    "structureStats": {
      "blockCount": 4,
      "maxDepth": 2,
      "topLevelBlockCount": 2,
      "hasNavigation": false,
      "hasQuery": true,
      "hasTemplateParts": true,
      "firstTopLevelBlock": "core/template-part",
      "lastTopLevelBlock": "core/group"
    },
    "currentPatternOverrides": {
      "hasOverrides": false,
      "blockCount": 0,
      "blockNames": [],
      "blocks": []
    },
    "currentViewportVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
    }
  },
  "visiblePatternNames": [
    "core/query-offset-feature",
    "core/post-meta-two-column"
  ]
}
```

`editorSlots.emptyAreas` and `editorSlots.assignedParts` are the live editor occupancy fields. The server keeps canonical capability metadata from the saved template and expands the effective `allowedAreas` set with any unsaved live areas implied by those fields. Empty templates still send `editorSlots` and `editorStructure` with empty arrays and zeroed stats.

## Example Response

```json
{
  "suggestions": [
    {
      "label": "Use the empty sidebar slot",
      "description": "Introduce supporting article context without disturbing the main reading flow.",
      "operations": [
        {
          "type": "assign_template_part",
          "slug": "single-sidebar",
          "area": "sidebar"
        },
        {
          "type": "insert_pattern",
          "patternName": "core/post-meta-two-column",
          "placement": "before_block_path",
          "targetPath": [1]
        }
      ],
      "templateParts": [
        {
          "slug": "single-sidebar",
          "area": "sidebar",
          "reason": "This keeps related metadata and supporting blocks out of the article body."
        }
      ],
      "patternSuggestions": ["core/post-meta-two-column"]
    }
  ],
  "explanation": "The template already has stable header and footer assignments, so the open sidebar area is the safest structural opportunity.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

## Request Freshness And Server Revalidation

- The panel still computes a local request signature from `templateRef + prompt + contextSignature` so stale cards can stay visible immediately when the prompt or live editor snapshot drifts.
- Normal template responses now also store `reviewContextSignature`, `resolvedContextSignature`, and `docsGroundingFingerprint`. The review hash is used by background `revalidateTemplateReviewFreshness()` checks, while the resolved hash is computed in PHP from the canonical server-normalized template apply context plus the sanitized prompt after live overlays, server-only context such as theme tokens and pattern candidates, and docs grounding have been resolved.
- `applyTemplateSuggestion()` keeps the current client-side stale guard first. If the local request signature still matches, it re-posts the same request with `resolveSignatureOnly: true` and only allows apply when the returned `resolvedContextSignature` still matches the stored result; review revalidation compares `reviewContextSignature` and can mark the stored result stale when trusted docs grounding is unavailable.
- Template docs grounding uses `visiblePatternNames` in the query text, but the family cache key stays coarse and only records bounded booleans and counters such as `hasVisiblePatternScope` and `visiblePatternCount`. Full requests use the shared cache/fallback collector, so exact, family, and entity cache hits are reused immediately; on generic or missing fallback guidance, a request may perform a foreground docs warm before queuing async warming.

## Example Preview Contract

```text
Review Before Apply
  1 operation: assign `single-sidebar` -> `sidebar`
  1 operation: insert `core/post-meta-two-column` -> `Before target block (Path 2)`
```

## What This Surface Can Do

- Recommend template-part assignment and replacement
- Recommend bounded pattern insertion into the current template at validated top-level anchors
- Turn mentioned template parts, areas, and patterns into clickable editor actions
- Preview the exact operation list before any mutation happens
- Record template apply actions in the shared activity system and expose inline undo

## Supported Executable Operations

- `assign_template_part`
- `replace_template_part`
- `insert_pattern`

`insert_pattern` now supports these bounded placement modes:

- `start`
- `end`
- `before_block_path`
- `after_block_path`

Anchored template insertion is limited to validated top-level anchors gathered by `ServerCollector::for_template()` and the editor structure payload. Every template `insert_pattern` operation must include an explicit placement. `start` and `end` require matching live insertion anchors, while `before_block_path` and `after_block_path` also require a validated `targetPath`. Legacy implicit insertions are rejected by `TemplatePrompt::parse_response()` and `validateTemplateOperationSequence()`.

Each suggestion may include at most one `insert_pattern` operation. Executable suggestions derive `patternSuggestions` from validated insert operations, while advisory-only suggestions may preserve validated pattern names when no safe deterministic insertion anchor is available.

## Guardrails And Failure Modes

- The surface is hidden outside `wp_template` editing
- Any invalid operation causes the entire apply to fail before mutation; there is no partial apply path
- Apply is also blocked when the stored `resolvedContextSignature` no longer matches the current server-resolved apply context
- Suggestions with neither validated operations nor validated advisory summaries are dropped before they reach the UI
- Free-form template rewrites are intentionally out of scope
- Only the newest valid tail entry is undoable; older entries are blocked until newer still-applied actions are undone

## Primary Functions And Handlers

| Layer                  | Function / class                                                               | Role                                                              |
| ---------------------- | ------------------------------------------------------------------------------ | ----------------------------------------------------------------- |
| UI shell               | `TemplateRecommender()` in `src/templates/TemplateRecommender.js`              | Renders the panel, entity links, preview flow, activity, and undo |
| Input builder          | `buildTemplateFetchInput()` in `src/templates/template-recommender-helpers.js` | Normalizes the request payload                                    |
| Context helpers        | `buildEditorTemplateSlotSnapshot()` and `getVisiblePatternNames()`             | Capture slot state and invalidation boundaries                    |
| Store request          | `fetchTemplateRecommendations()` in `src/store/index.js`                       | Sends the recommendation request                                  |
| Store apply            | `applyTemplateSuggestion()` in `src/store/index.js`                            | Runs deterministic apply and records activity                     |
| Deterministic executor | `applyTemplateSuggestionOperations()` in `src/utils/template-actions.js`       | Validates and executes the structured operation set               |
| Ability wrapper        | `RecommendationAbilityExecution::execute()`                                    | Adapts the ability request to the backend handler                 |
| Backend ability        | `TemplateAbilities::recommend_template()`                                      | Builds context and returns validated template suggestions         |
| Prompt contract        | `TemplatePrompt::build_user()` / `TemplatePrompt::parse_response()`            | Defines and validates the structured template suggestion format   |

## Related Abilities

- Ability: `flavor-agent/recommend-template`
- Helper ability: `flavor-agent/list-template-parts`

## Key Implementation Files

- `src/templates/TemplateRecommender.js`
- `src/templates/template-recommender-helpers.js`
- `src/utils/template-actions.js`
- `src/utils/template-operation-sequence.js`
- `src/utils/visible-patterns.js`
- `src/utils/editor-entity-contracts.js` — dual-store entity resolution and `usePostTypeEntityContract` hook; see `docs/reference/shared-internals.md`
- `src/utils/editor-context-metadata.js` — pattern override and viewport visibility summaries; see `docs/reference/shared-internals.md`
- `src/utils/template-part-areas.js` — template-part area resolution for slot state; see `docs/reference/shared-internals.md`
- `src/utils/template-types.js` — template slug normalization; see `docs/reference/shared-internals.md`
- `src/components/CapabilityNotice.js` — shared backend-unavailable notice; see `docs/reference/shared-internals.md`
- `src/components/AIStatusNotice.js` — shared contextual status feedback; see `docs/reference/shared-internals.md`
- `src/components/AIReviewSection.js` — shared review-before-apply panel; see `docs/reference/shared-internals.md`
- `src/components/AIAdvisorySection.js` — shared advisory-only section; see `docs/reference/shared-internals.md`
- `src/store/index.js`
- `src/store/abilities-client.js`
- `inc/Abilities/RecommendationAbilityExecution.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/LLM/TemplatePrompt.php`
- `inc/Context/ServerCollector.php`
