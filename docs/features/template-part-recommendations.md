# Template-Part Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- Surface location: Site Editor document settings panel titled `AI Template Part Recommendations`
- Scope: only while editing a `wp_template_part` entity
- UI shape: shared setup/capability notice when unavailable, otherwise a prompt field, slug and area badges, explanation text, a featured recommendation, grouped `Review first` / `Manual ideas` lanes, focus-block links, suggested-pattern links, a shared lower review-before-apply panel, recent activity, and inline undo

## Surfacing Conditions

- `TemplatePartRecommender()` must resolve a current template-part reference through the shared edited-entity resolver, preferring `core/editor` and falling back to `core/edit-site`
- The shared `wp_template_part` entity contract from `usePostTypeEntityContract()` must resolve so the panel can align its title and area labels with the current WordPress template-part contract while still falling back to built-in field and area metadata when no live view config is exposed
- The panel stays visible with a notice when `window.flavorAgentData.canRecommendTemplateParts` is false; the localized flag is driven by the shared surface-capability contract and flips on when a compatible text-generation provider is configured in `Settings > Connectors`
- The panel clears recommendations on hard template-part changes, but keeps same-template-part drifted results visible as stale until the user refreshes or a fresh result arrives

## Shared Interaction Model

- Learned-once sequence: prompt -> featured recommendation -> grouped lanes -> review where needed -> apply where allowed -> undo and history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Template-part recommendations move `idle -> loading -> advisory-ready` after results arrive, then `preview-ready` only when the user explicitly opens preview on a validated suggestion
- The strongest validated suggestion now appears first in a shared recommendation hero; executable suggestions stay in the `Review first` lane and non-deterministic ideas move to `Manual ideas`
- Preview uses the shared `AIReviewSection` shell in a dedicated lower panel and post-apply / post-undo feedback uses the same shared status notice pattern as block and template
- Suggestions that fail deterministic validation stay visible in the shared advisory shell so the user can still review focus blocks and pattern ideas without getting an apply affordance
- Template-part freshness uses the local request signature plus docs-free server review/apply hashes. PHP stores `reviewContextSignature` for background review revalidation and `resolvedContextSignature` for apply safety, so docs warming alone does not invalidate either server freshness check.

## End-To-End Flow

1. `TemplatePartRecommender()` resolves the current template-part reference through the shared edited-entity resolver, then derives slug and area using both the template-part area lookup and the normalized `wp_template_part` entity contract returned by `usePostTypeEntityContract()`
2. The component builds the request through `buildTemplatePartFetchInput()`, including `visiblePatternNames` plus a full live template-part structure snapshot from `buildEditorTemplatePartStructureSnapshot()`
3. `fetchTemplatePartRecommendations()` in the store posts the request to `POST /flavor-agent/v1/recommend-template-part`
4. `FlavorAgent\REST\Agent_Controller::handle_recommend_template_part()` adapts the request to `FlavorAgent\Abilities\TemplateAbilities::recommend_template_part()`
5. `TemplateAbilities::recommend_template_part()` gathers canonical template-part metadata through `ServerCollector::for_template_part()`, atomically overlays the live unsaved structural slice from the editor, validates path-based targets and anchors against the full live path index, computes a docs-free `reviewContextSignature` from the server review context and `resolvedContextSignature` from the apply context plus the sanitized prompt, returns early for signature-only revalidation, and only then scopes docs grounding with `currentPatternOverrides` before calling `ResponsesClient::rank()` through `FlavorAgent\LLM\TemplatePartPrompt`
6. The parsed response returns explanation text, advisory `blockHints`, advisory `patternSuggestions`, and optional structured `operations`
7. `buildTemplatePartSuggestionViewModel()` validates the operation sequence before the UI offers preview or apply controls
8. The user can jump to focus blocks through path-based selection links, browse suggested patterns in the inserter, open the shared lower review panel for validated operations, and confirm apply
9. `applyTemplatePartSuggestion()` runs the deterministic executor, records activity, and exposes inline undo for the newest valid tail entry

## Flow Diagram

```text
User prompt in wp_template_part editor
  -> TemplatePartRecommender
  -> buildTemplatePartFetchInput()
  -> fetchTemplatePartRecommendations()
  -> POST /flavor-agent/v1/recommend-template-part
  -> Agent_Controller::handle_recommend_template_part()
  -> TemplateAbilities::recommend_template_part()
  -> ServerCollector::for_template_part()
  -> TemplatePartPrompt::parse_response()
  -> validateTemplatePartOperationSequence()
  -> preview exact operations
  -> applyTemplatePartSuggestion()
  -> activity + inline undo
```

## Example Request

```json
{
  "templatePartRef": "theme//header",
  "prompt": "Create a stronger utility row above the main navigation.",
  "visiblePatternNames": [
    "core/header-with-utility-row",
    "core/site-branding-minimal"
  ],
  "editorStructure": {
    "blockTree": [
      {
        "path": [0],
        "name": "core/group",
        "label": "Group",
        "attributes": { "tagName": "header" },
        "childCount": 2,
        "children": [
          {
            "path": [0, 0],
            "name": "core/site-logo",
            "label": "Site Logo",
            "attributes": {},
            "childCount": 0,
            "children": []
          },
          {
            "path": [0, 1],
            "name": "core/navigation",
            "label": "Navigation",
            "attributes": { "overlayMenu": "mobile" },
            "childCount": 0,
            "children": []
          }
        ]
      }
    ],
    "allBlockPaths": [
      {
        "path": [0],
        "name": "core/group",
        "label": "Group",
        "attributes": { "tagName": "header" },
        "childCount": 2
      },
      {
        "path": [0, 0],
        "name": "core/site-logo",
        "label": "Site Logo",
        "attributes": {},
        "childCount": 0
      },
      {
        "path": [0, 1],
        "name": "core/navigation",
        "label": "Navigation",
        "attributes": { "overlayMenu": "mobile" },
        "childCount": 0
      }
    ],
    "topLevelBlocks": ["core/group"],
    "blockCounts": {
      "core/group": 1,
      "core/site-logo": 1,
      "core/navigation": 1
    },
    "structureStats": {
      "blockCount": 3,
      "maxDepth": 2,
      "hasNavigation": true,
      "containsLogo": true,
      "containsSiteTitle": false,
      "containsSearch": false,
      "containsSocialLinks": false,
      "containsQuery": false,
      "containsColumns": false,
      "containsButtons": false,
      "containsSpacer": false,
      "containsSeparator": false,
      "firstTopLevelBlock": "core/group",
      "lastTopLevelBlock": "core/group",
      "hasSingleWrapperGroup": true,
      "isNearlyEmpty": false
    },
    "currentPatternOverrides": {
      "hasOverrides": false,
      "blockCount": 0,
      "blockNames": [],
      "blocks": []
    },
    "operationTargets": [
      {
        "path": [0],
        "name": "core/group",
        "label": "Group",
        "allowedOperations": ["replace_block_with_pattern", "remove_block"],
        "allowedInsertions": ["before_block_path", "after_block_path"]
      },
      {
        "path": [0, 1],
        "name": "core/navigation",
        "label": "Navigation",
        "allowedOperations": ["replace_block_with_pattern", "remove_block"],
        "allowedInsertions": ["before_block_path", "after_block_path"]
      }
    ],
    "insertionAnchors": [
      { "placement": "start", "label": "Start of template part" },
      { "placement": "end", "label": "End of template part" },
      {
        "placement": "before_block_path",
        "targetPath": [0, 1],
        "blockName": "core/navigation",
        "label": "Before Navigation"
      }
    ],
    "structuralConstraints": {
      "contentOnlyPaths": [],
      "lockedPaths": [],
      "hasContentOnly": false,
      "hasLockedBlocks": false
    }
  }
}
```

The prompt-facing `blockTree` may stay summarized, but `editorStructure.allBlockPaths` carries the full live path coverage the server uses to validate deep executable targets. Empty template parts still send an explicit structure snapshot with empty trees, zeroed stats, no targets, and start/end anchors.

## Example Response

```json
{
  "suggestions": [
    {
      "label": "Add a utility row before navigation",
      "description": "A compact utility row can hold secondary links without crowding the main menu.",
      "blockHints": [
        {
          "path": [0, 1],
          "label": "Navigation block",
          "blockName": "core/navigation",
          "reason": "This is the main anchor for the header's current structure."
        }
      ],
      "patternSuggestions": ["core/header-with-utility-row"],
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "core/header-with-utility-row",
          "placement": "before_block_path",
          "targetPath": [0, 1]
        }
      ]
    }
  ],
  "explanation": "The current header already has a clear navigation anchor, so inserting a utility pattern before that block is a deterministic way to expand the layout.",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

## Request Freshness And Server Revalidation

- The panel still computes a local request signature from `templatePartRef + prompt + contextSignature` so stale review cards are detected immediately when the prompt or live structure drifts.
- Normal template-part responses now also store docs-free `reviewContextSignature` and a PHP-computed `resolvedContextSignature`. The review hash is used by background `revalidateTemplatePartReviewFreshness()` checks, while the resolved hash is based on the server-normalized structural apply context plus the sanitized prompt after live overlays and server-only context have been resolved.
- `applyTemplatePartSuggestion()` keeps the local stale guard first, then re-posts the same request with `resolveSignatureOnly: true` and only allows apply when the current `resolvedContextSignature` still matches the stored result; review revalidation compares `reviewContextSignature` without treating docs grounding churn as stale state.
- Template-part docs grounding now uses `currentPatternOverrides` in the query text, while the family cache key stays coarse and records only bounded override booleans and counters.

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

## Supported Executable Operations

- `insert_pattern`
- `replace_block_with_pattern`
- `remove_block`

Supported placement modes today:

- `start`
- `end`
- `before_block_path`
- `after_block_path`

Replace and remove operations only stay executable when their `targetPath` is listed in the prompt's executable operation targets. Insert operations only stay executable when their placement and `targetPath` match the prompt's executable insertion anchors. Deep paths are validated against the full live path index, not only the depth-limited prompt tree.

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
| REST handler | `Agent_Controller::handle_recommend_template_part()` | Adapts the REST request to the backend ability |
| Backend ability | `TemplateAbilities::recommend_template_part()` | Builds context and returns template-part suggestions |
| Prompt contract | `TemplatePartPrompt::build_user()` / `TemplatePartPrompt::parse_response()` | Defines and validates the structured output |

## Related Routes And Abilities

- REST: `POST /flavor-agent/v1/recommend-template-part`
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
- `src/components/AIAdvisorySection.js` — shared advisory-only section; see `docs/reference/shared-internals.md`
- `src/store/index.js`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/Context/ServerCollector.php`
