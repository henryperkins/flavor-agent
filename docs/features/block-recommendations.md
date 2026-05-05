# Block Recommendations

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view, `docs/reference/abilities-and-routes.md` for the exact route/ability contract, and `docs/reference/release-surface-scope-review.md#block-recommendations` for the release stop line.

## Exact Surface

- Primary surface: native block Inspector panel titled `AI Recommendations`
- Injection point: `editor.BlockEdit` filter registered in `src/inspector/InspectorInjector.js`
- Fallback surface: document settings panel titled `AI Recommendations` with the eyebrow `Last Selected Block` when the current selection clears but the last selected block still exists
- Secondary surfaces after a successful block request:
  - executable `SuggestionChips` lanes for `block`, `settings`, and `styles` inside the main `AI Recommendations` panel
  - `Review first` lane for validator-approved selected-block pattern operations when structural actions are enabled and the result is fresh; these can be applied only from the selected review card
  - passive mirrored `SuggestionChips` injected into delegated native sub-panels such as position, advanced, bindings, list, color, typography, dimensions, border, shadow, filter, and background so the user can see the current result beside the matching core controls without creating a second apply surface. Shadow suggestions render inside Gutenberg's native Border/Shadow group.

## Surfacing Conditions

- The selected block must exist and its editing mode must not be `disabled`
- The main panel still renders when recommendations are unavailable, but fetch is disabled when `window.flavorAgentData.canRecommendBlocks` is false
- Block structural pattern actions are behind the default-off Block Structural Actions setting in `Settings > Flavor Agent > Experimental Features`. The saved setting, the `FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS` developer constant, and the `flavor_agent_enable_block_structural_actions` filter resolve to `window.flavorAgentData.enableBlockStructuralActions`. When false, structural proposals remain advisory and the block surface does not expose a structural review/apply path for them.
- When that resolved flag is enabled, the client adds a deterministic `blockOperationContext` to the block request. It contains the selected target identity/signature plus visible, renderable patterns from Gutenberg's current allowed-pattern selector and per-target actions (`insert_before`, `insert_after`, and, where block types permit, `replace`).
- Behind the flag, the prompt may propose at most one selected-block pattern operation. PHP validates every proposal before it reaches the normalized response; invalid, stale, locked, content-only, cross-surface, unavailable-pattern, and multi-operation proposals stay in `rejectedOperations` for diagnostics.
- The structural review/apply path is keyed by selected block, request token, request signature, and suggestion. Stale results disable review entry and apply. Safe local block attribute suggestions remain the only one-click block apply path.
- PHP owns structural operation approval through `FlavorAgent\Context\BlockOperationValidator`. The browser revalidates the approved operation against the live block operation catalog and fails closed with `client_server_operation_mismatch` when its normalized operation identity disagrees with the server-approved metadata.
- Content-restricted blocks stay visible and show an informational notice; executable suggestions are limited to content-safe attributes, broader block ideas may remain advisory-only, and style projections are suppressed
- A selected `core/navigation` block adds the navigation guidance section inside the same panel

## Shared Interaction Model

- Learned-once sequence: intro -> scope/freshness -> prompt -> status -> featured recommendation -> grouped lanes -> embedded navigation when present -> undo and history
- Shared normalized states: `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, `error`
- Block recommendations normally move `idle -> loading -> advisory-ready`; safe local attribute updates can then move directly to `success` because only the selected block's local attributes are mutated
- Fresh results now surface one featured next step before the grouped `Apply now`, `Review first`, and `Manual ideas` lanes
- `Apply now` is limited to validator-computed local selected-block attribute updates tied to a known Inspector panel when panel mapping is known. Registered block style variations are the only executable block-level exception that may survive without a mapped panel when no specific panel applies.
- `Review first` is limited to exactly one server-approved selected-block structural pattern operation that the browser operation catalog can reproduce against the live block context.
- `Manual ideas` contains advisory-only structural or pattern guidance plus any suggestions blocked by panel, attribute, binding, content-only, freshness, or structural-operation validators.
- Advisory block ideas now use the shared `AIAdvisorySection` shell so the block surface matches the review-first surfaces more closely without changing its direct-apply contract. Mixed suggestions keep rejected structural proposals visible as advisory remainders when a local attribute update remains inline-safe.
- Block recommendations are the only recommendation surface that now retains stale client-side results; stale results stay visible for reference, executable chips are demoted/disabled, and `SurfaceScopeBar` exposes an explicit `Refresh` action
- Freshness now has two layers on the block surface: the client-local request signature still drives immediate stale UI, and the stored server `resolvedContextSignature` hashes the server-normalized block apply context plus the sanitized prompt. Background revalidation checks the wrapped REST signature-only response and silently demotes/disables stale results only when that check succeeds with a mismatched signature; apply-time signature revalidation is the hard gate. Background docs-cache warms alone do not invalidate apply. `applySuggestion()` only mutates attributes after both checks pass.
- The panel states that inline apply is the exception for safe local block updates. Validator-approved structural block operations enter a review-first lane; applying them goes through a separate transactional block-tree path with rollback and drift-safe undo.
- The embedded navigation section remains a subordinate exception: it keeps its own request state and `Navigation Ideas` wrapper because it is nested inside block recommendations rather than acting as a peer surface
- The main block panel is now the only executable block surface; delegated native sub-panels mirror the current result but do not own apply, refresh, or activity state
- `Recent AI Actions` and block undo use the same shared activity treatment as the template and template-part surfaces

## End-To-End Flow

1. The user selects a block and optionally enters a prompt in `BlockRecommendationsContent`
2. `collectBlockContext()` in `src/context/collector.js` builds the client-side block snapshot, including bindable attributes and native inspector panel availability
3. `fetchBlockRecommendations()` in `src/store/index.js` executes the `flavor-agent/recommend-block` ability with that context
4. `FlavorAgent\Abilities\RecommendationAbilityExecution` adapts the request to `FlavorAgent\Abilities\BlockAbilities::recommend_block()`
5. `BlockAbilities::recommend_block()` normalizes the input, gathers server context, computes `resolvedContextSignature` from the server-normalized apply context plus the sanitized prompt, returns early for signature-only and disabled-block requests, and only then resolves cache-backed WordPress docs guidance before calling `FlavorAgent\LLM\ChatClient::chat()`
6. `ChatClient::chat()` uses the configured WordPress AI Client runtime from `Settings > Connectors` and returns a `missing_text_generation_provider` error when no text-generation runtime is available
7. `FlavorAgent\LLM\Prompt` builds the prompt, parses the response, and enforces block-context guardrails, including the PHP block operation validator when structural proposals are present
8. The store saves the grouped `settings`, `styles`, and `block` suggestions and the Inspector renders inline apply, structural review/apply, and manual/advisory lanes in the main block panel plus passive mirrored chips in delegated native sub-panels
9. Opening a structural review records local UI state only. The block tree is unchanged until the user chooses `Apply reviewed structure`.
10. When the user applies an inline-safe attribute suggestion, `applySuggestion()` first compares the stored client request signature, then re-posts the same request with `resolveSignatureOnly: true` to verify the current `resolvedContextSignature`, and only then safely merges allowed attribute updates into the current block and records an activity entry
11. When the user applies a reviewed structural suggestion, `applyBlockStructuralSuggestion()` runs the same freshness checks, revalidates the live target, parses the current registered pattern, applies the insert or replacement transactionally, rolls back failed partial mutations, and records a structural activity entry with before/after signatures
12. Inline undo validates the live block state before restoring the previous attribute snapshot. Structural undo validates the live structural signature before removing inserted blocks or restoring the replaced block.

## Flow Diagram

```text
User selects block + prompt
  -> BlockRecommendationsContent
  -> collectBlockContext()
  -> fetchBlockRecommendations()
  -> flavor-agent/recommend-block ability
  -> RecommendationAbilityExecution
  -> BlockAbilities::recommend_block()
  -> ChatClient::chat()
  -> Prompt::parse_response()
  -> Prompt::enforce_block_context_rules()
  -> BlockOperationValidator validates proposed operations
  -> store saves grouped suggestions
  -> Inspector renders apply chips, review/apply cards, and advisory cards
  -> applySuggestion() / undoActivity() for inline-safe attributes
  -> applyBlockStructuralSuggestion() / undoActivity() for reviewed structural operations
```

## Example Request

This is the normal non-structural request shape sent by the first-party block Inspector panel.

```json
{
  "clientId": "2b1c4f3f-1234-5678-9abc-def012345678",
  "contextSignature": "sha256-of-client-block-context",
  "editorContext": {
    "block": {
      "name": "core/group",
      "title": "Group",
      "currentAttributes": {
        "layout": {
          "type": "constrained"
        },
        "style": {
          "spacing": {
            "padding": {
              "top": "var:preset|spacing|40",
              "bottom": "var:preset|spacing|40"
            }
          }
        }
      },
      "inspectorPanels": {
        "color": ["color.background", "color.text"],
        "dimensions": ["spacing.padding"],
        "layout": ["layout.type"]
      },
      "editingMode": "default",
      "isInsideContentOnly": false,
      "childCount": 2,
      "structuralIdentity": {
        "role": "section",
        "location": "content"
      }
    },
    "siblingsBefore": ["core/heading"],
    "siblingsAfter": ["core/paragraph"],
    "themeTokens": {
      "colors": ["contrast: #111111", "base: #ffffff"],
      "colorPresets": [
        {
          "name": "Contrast",
          "slug": "contrast",
          "color": "#111111",
          "cssVar": "var(--wp--preset--color--contrast)"
        }
      ],
      "spacing": ["40: 1rem", "50: 1.5rem", "60: 2rem"],
      "spacingPresets": [
        {
          "name": "60",
          "slug": "60",
          "size": "2rem",
          "cssVar": "var(--wp--preset--spacing--60)"
        }
      ],
      "layout": {
        "content": "42rem",
        "wide": "72rem",
        "allowEditing": true,
        "allowCustomContentAndWideSize": true
      },
      "enabledFeatures": {
        "backgroundColor": true,
        "textColor": true,
        "padding": true,
        "blockGap": true
      }
    }
  },
  "prompt": "Make this section feel more spacious and editorial."
}
```

When `window.flavorAgentData.enableBlockStructuralActions` is true, the request also carries this selected-block pattern-operation context under `editorContext.blockOperationContext`:

```json
{
  "editorContext": {
    "blockOperationContext": {
      "targetClientId": "2b1c4f3f-1234-5678-9abc-def012345678",
      "targetBlockName": "core/group",
      "targetSignature": "sha256-of-selected-block-target",
      "allowedPatterns": [
        {
          "name": "theme/editorial-section",
          "title": "Editorial section",
          "source": "theme",
          "categories": ["featured"],
          "blockTypes": ["core/group"],
          "allowedActions": ["insert_before", "insert_after", "replace"]
        }
      ]
    }
  }
}
```

The server normalizes that context with lock, content-only, and editing-mode defaults before `BlockOperationValidator` evaluates any proposed operation.

## Example Response

```json
{
  "payload": {
    "settings": [
      {
        "label": "Use a wider layout",
        "description": "Give the section more room so the inner content feels less cramped.",
        "panel": "layout",
        "attributeUpdates": {
          "layout": {
            "type": "constrained",
            "wideSize": "72rem"
          }
        },
        "confidence": 0.84
      }
    ],
    "styles": [
      {
        "label": "Increase vertical padding",
        "description": "More top and bottom spacing helps separate this section from nearby content.",
        "panel": "dimensions",
        "attributeUpdates": {
          "style": {
            "spacing": {
              "padding": {
                "top": "var:preset|spacing|60",
                "bottom": "var:preset|spacing|60"
              }
            }
          }
        },
        "confidence": 0.9
      }
    ],
    "block": [],
    "explanation": "The block already works as a section wrapper, so spacing and layout changes are the lowest-risk improvements.",
    "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt",
    "executionContract": {
      "inspectorPanels": {
        "color": ["color.background", "color.text"],
        "dimensions": ["spacing.padding"],
        "layout": ["layout.type"]
      },
      "allowedPanels": ["color", "dimensions", "layout"],
      "panelMappingKnown": true,
      "styleSupportPaths": [
        "color.background",
        "color.text",
        "spacing.padding"
      ],
      "bindableAttributes": [],
      "contentAttributeKeys": [],
      "configAttributeKeys": ["layout"],
      "supportsContentRole": true,
      "editingMode": "default",
      "isInsideContentOnly": false,
      "usesInnerBlocksAsContent": true,
      "registeredStyles": ["default", "section"],
      "presetSlugs": {
        "color": ["base", "contrast"],
        "gradient": [],
        "duotone": [],
        "fontsize": [],
        "fontfamily": [],
        "spacing": ["40", "50", "60"],
        "shadow": []
      },
      "enabledFeatures": {
        "backgroundColor": true,
        "textColor": true,
        "padding": true,
        "blockGap": true
      },
      "layout": {
        "content": "42rem",
        "wide": "72rem",
        "allowEditing": true,
        "allowCustomContentAndWideSize": true
      }
    },
    "requestMeta": {
      "selectedProvider": "openai",
      "selectedProviderLabel": "OpenAI",
      "connectorId": "openai",
      "connectorLabel": "OpenAI",
      "provider": "openai",
      "providerLabel": "OpenAI",
      "backendLabel": "WordPress AI Client",
      "model": "gpt-4.1-mini",
      "owner": "site",
      "ownerLabel": "Site",
      "pathLabel": "AI Client",
      "credentialSource": "connector",
      "credentialSourceLabel": "Connector",
      "tokenUsage": {
        "input": 1180,
        "output": 430,
        "total": 1610
      },
      "latencyMs": 940,
      "usedFallback": false,
      "ability": "flavor-agent/recommend-block",
      "route": "wp-abilities:flavor-agent/recommend-block"
    }
  },
  "clientId": "2b1c4f3f-1234-5678-9abc-def012345678"
}
```

## Example Activity Entry Shape

```json
{
  "surface": "block",
  "suggestion": "Increase vertical padding",
  "target": {
    "clientId": "2b1c4f3f-1234-5678-9abc-def012345678",
    "blockName": "core/group",
    "blockPath": [3]
  },
  "request": {
    "prompt": "Make this section feel more spacious and editorial.",
    "reference": "block:2b1c4f3f-1234-5678-9abc-def012345678:4"
  },
  "document": {
    "scopeKey": "post:128",
    "postType": "post",
    "entityId": "128"
  },
  "undo": {
    "status": "available"
  }
}
```

## What This Surface Can Do

- Suggest block settings changes, style changes, and broader block-level adjustments
- Keep block, settings, and style apply actions in one place while still mirroring the result into the native Inspector location where the user would normally inspect that change
- Apply bounded attribute updates limited to declared content/config attributes, supported style channels, supported visibility/binding metadata, and registered style variations
- Apply validator-approved selected-block pattern insertions and replacements from `Review first` when structural actions are enabled and the result is fresh
- Record the apply action in the shared AI activity system and surface inline undo for the newest valid tail entry

## Guardrails And Failure Modes

- Disabled blocks do not render the surface at all
- Content-only editing mode limits executable suggestions to content-safe attributes, though broader manual guidance can still remain visible
- Visibility state in `attributes.metadata.blockVisibility` is respected during prompt building and post-parse enforcement
- Executable block attribute suggestions require a non-empty `panel` that appears in the block execution contract whenever panel mapping is known. Empty or unknown panels are filtered out before they can become `Apply now` suggestions.
- Registered style variation suggestions must reference a registered style class; they may use an empty panel when no mapped panel applies, but a non-empty panel must still be valid.
- Executable updates cannot set `lock`, arbitrary `metadata`, or undeclared top-level attributes; `metadata` is limited to supported `blockVisibility` and allowed `bindings`. Partial execution contracts inherit missing local attribute-key lists from the block context before this undeclared-attribute filter runs.
- Apply is also blocked when the live server-resolved apply context drifts, even if the local block snapshot still hashes to the same client request signature
- If no allowed attribute updates remain after validation, the suggestion is not applied
- Structural apply is blocked when the reviewed target disappeared, changed block type or target signature, became locked or content-only, lost the referenced registered pattern, or no longer permits the requested insert/replace action
- Structural apply is also blocked before review/apply when the browser-side operation catalog cannot reproduce the server-approved `catalogVersion`, type, pattern, target, position/action, signature, and expected-target identity
- Structural insert/replace uses rollback if the editor dispatch does not produce the expected parsed pattern blocks
- Undo is blocked if the block disappeared, changed type, or changed attributes after the AI apply; a moved block remains undoable when the same `clientId`, block name, and applied attribute snapshot still match
- Structural undo is blocked if the live root structure no longer matches the recorded post-apply structural signature

## Primary Functions And Handlers

| Layer                    | Function / class                                                                                                 | Role                                                                                                                                                             |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| UI shell                 | `withAIRecommendations()` in `src/inspector/InspectorInjector.js`                                                | Injects the panel into the native Inspector                                                                                                                      |
| UI state                 | `BlockRecommendationsContent()` in `src/inspector/BlockRecommendationsPanel.js`                                  | Renders intro, scope/freshness, prompt, status, featured recommendation, grouped lanes, embedded navigation, activity, and undo                                  |
| Context collection       | `collectBlockContext()` in `src/context/collector.js`                                                            | Builds the client snapshot sent to the backend                                                                                                                   |
| Request builder          | `buildBlockRecommendationRequestData()` in `src/inspector/block-recommendation-request.js`                       | Carries `clientId`, prompt, live context, and top-level `contextSignature`, and computes the client request signature used for stale-result checks               |
| Store request            | `fetchBlockRecommendations()` in `src/store/index.js`                                                            | Sends the recommendation request and stores the result                                                                                                           |
| Store apply              | `applySuggestion()` in `src/store/index.js`                                                                      | Applies bounded attribute updates and records activity                                                                                                           |
| Store structural apply   | `applyBlockStructuralSuggestion()` in `src/store/index.js`                                                       | Applies reviewed structural operations after freshness and live-target checks                                                                                    |
| Client structural mirror | `validateBlockOperationSequence()` and catalog helpers in `src/utils/block-operation-catalog.js`                 | Mirrors server-approved operation validation before review/apply and fails closed on client/server mismatch                                                      |
| Structural operations    | `applyBlockStructuralSuggestionOperations()` in `src/utils/block-structural-actions.js`                          | Parses patterns, applies insert/replace operations transactionally, and prepares structural signatures for activity/undo                                         |
| Activity undo            | `undoActivity()` in `src/store/activity-undo.js`                                                                 | Routes inline block undo and structural block undo through their drift validators                                                                                |
| REST handler             | `Agent_Controller::handle_recommend_block()`                                                                     | Adapts the REST request to the backend ability                                                                                                                   |
| Backend ability          | `BlockAbilities::recommend_block()`                                                                              | Normalizes input, gathers context, and runs the prompt pipeline                                                                                                  |
| Execution contract       | `BlockRecommendationExecutionContract::from_context()` in `inc/Context/BlockRecommendationExecutionContract.php` | Derives the server-side apply contract from normalized block context and theme tokens                                                                            |
| LLM wrapper              | `ChatClient::chat()`                                                                                             | Uses the configured WordPress AI Client / Connectors text-generation runtime without reading legacy Flavor Agent provider selections |
| Prompt contract          | `Prompt::build_user()` / `Prompt::parse_response()`                                                              | Builds and parses the structured block-suggestion payload                                                                                                        |
| Server enforcement       | `Prompt::enforce_block_context_rules()` in `inc/LLM/Prompt.php`                                                  | Filters parsed suggestions against panel, attribute, style, visibility, binding, and structural-operation rules                                                  |
| Structural validator     | `BlockOperationValidator::validate_sequence()` in `inc/Context/BlockOperationValidator.php`                      | Validates proposed selected-block pattern operations against the normalized operation context                                                                    |

## Related Abilities

- Ability: `flavor-agent/recommend-block`
- Helper ability: `flavor-agent/introspect-block`

## Key Implementation Files

- `src/inspector/InspectorInjector.js`
- `src/inspector/BlockRecommendationsPanel.js`
- `src/inspector/block-recommendation-request.js`
- `src/inspector/SuggestionChips.js`
- `src/inspector/suggestion-keys.js`
- `src/context/collector.js`
- `src/context/block-inspector.js` — client-side block introspection (supports, attributes, styles); see `docs/reference/shared-internals.md`
- `src/context/theme-tokens.js` — design token extraction for LLM context; see `docs/reference/shared-internals.md`
- `src/utils/structural-identity.js` — block structural role inference for `structuralIdentity` context; see `docs/reference/shared-internals.md`
- `src/utils/block-operation-catalog.js` — client structural-operation catalog and validation mirror
- `src/utils/block-execution-contract.js` — client-side execution-contract normalizer for stored recommendation state
- `src/utils/block-structural-actions.js` — transactional selected-block structural apply and drift-safe undo helpers
- `src/store/index.js`
- `src/store/activity-history.js`
- `src/store/activity-undo.js`
- `src/store/update-helpers.js` — safe attribute merging, undo patch construction, suggestion sanitization; see `docs/reference/shared-internals.md`
- `src/store/block-targeting.js` — resolves activity targets by clientId or blockPath for undo; see `docs/reference/shared-internals.md`
- `src/components/CapabilityNotice.js` — shared backend-unavailable notice; see `docs/reference/shared-internals.md`
- `src/components/AIStatusNotice.js` — shared contextual status feedback; see `docs/reference/shared-internals.md`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Context/BlockRecommendationExecutionContract.php`
- `inc/Context/BlockOperationValidator.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/Prompt.php`
