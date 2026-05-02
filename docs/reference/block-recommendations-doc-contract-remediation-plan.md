# Block Recommendations Documentation Contract Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

Status: executed on 2026-05-02

Scope: documentation-only remediation for `docs/features/block-recommendations.md`, plus corrections to this plan's example shapes when review finds the plan examples no longer match runtime.
Do not change PHP, JavaScript, tests, generated assets, or release artifacts while executing this plan unless a new runtime defect is discovered and approved as a separate task.

## Goal

Bring the block recommendations feature document back in sync with the current request, response, server enforcement, and client validation contracts.

The feature-doc remediation is a single docs patch that:

- Shows the normal block recommendation request shape, including `contextSignature` and the richer `themeTokens` structure produced by the current client.
- Shows the flag-gated structural request addendum that carries `editorContext.blockOperationContext`.
- Shows the normal REST response wrapper with `payload.executionContract` and `payload.requestMeta`.
- Names the current PHP and JS enforcement owners in the flow diagram, primary functions table, and key implementation files.
- Links the feature doc to the block release-surface stop line so the docs do not imply broader mutation support than the release scope allows.

## Findings Covered

1. `docs/features/block-recommendations.md` example response omits `payload.executionContract` and `payload.requestMeta`, even though the current REST/controller tests assert both and the store consumes both.
2. The implementation inventory under-documents server enforcement ownership. It says PHP owns structural approval elsewhere, but the primary functions table does not name `Prompt::enforce_block_context_rules()`, `BlockRecommendationExecutionContract`, or `BlockOperationValidator` as the authoritative enforcement path.
3. The example request underrepresents the first-party request builder. It does not show `contextSignature`, uses stale boolean `inspectorPanels` values instead of support-path arrays, omits the richer `themeTokens` shape, and omits the flag-gated `blockOperationContext` added when structural actions are enabled.

## Current Evidence

- `tests/phpunit/AgentControllerTest.php` asserts wrapped block responses include `payload.executionContract` and `payload.requestMeta`.
- `src/store/index.js` reads `payload.executionContract`, stores `requestMeta`, and carries `blockOperationContext` into structural review/apply state.
- `src/inspector/block-recommendation-request.js` sends `contextSignature` with block recommendation requests and signature-only freshness checks.
- `src/context/collector.js` adds `blockOperationContext` only when structural actions are enabled.
- `inc/Abilities/BlockAbilities.php` builds the execution contract with `BlockRecommendationExecutionContract::from_context()` and then calls `Prompt::enforce_block_context_rules()`.
- `inc/LLM/Prompt.php` delegates structural proposal validation to `BlockOperationValidator`.
- `src/utils/block-operation-catalog.js` mirrors structural action validation on the client for actionability and client/server mismatch protection.

## Product Boundary

Keep the feature doc aligned with `docs/reference/surfaces/block-recommendations.md`:

- Safe local attribute recommendations remain the one-click block apply path.
- Structural operations remain review-first and limited to validated selected-block pattern insert/replace behind `FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS`.
- Delegated Inspector subpanel mirrors remain passive and must not become independent apply or refresh surfaces.
- The docs must not describe free-form block-tree mutation, multi-block rewrite, site-wide remediation, or apply controls inside passive mirrors as shipped behavior.

## Target File Changes

For the feature-doc remediation, update:

- `docs/features/block-recommendations.md`

This plan file may also be edited to correct inaccurate examples before the feature-doc patch is applied.

Do not overwrite:

- `docs/reference/block-recommendation-review-remediation-plan.md`

That older plan covers runtime remediation work from an earlier block recommendation review and is partially implemented or superseded.

## Implementation Checklist

- [x] Add a release-surface pointer near the opening reference sentence.
  - Update the opening paragraph so it points to `docs/reference/surfaces/block-recommendations.md` for the release stop line.
  - Keep the existing links to `docs/FEATURE_SURFACE_MATRIX.md` and `docs/reference/abilities-and-routes.md`.

- [x] Update the normal request example.
  - Add top-level `contextSignature`.
  - Keep `clientId` and `prompt`.
  - Correct `editorContext.block.inspectorPanels` so each panel maps to an array of support paths, not booleans.
  - Expand `editorContext.themeTokens` beyond simple arrays so the example reflects the current token summary model.
  - Include at least these representative keys:
    - `colors`
    - `colorPresets`
    - `spacing`
    - `spacingPresets`
    - `enabledFeatures`
    - `layout`
  - Add one short prose sentence before or after the example stating that it is the normal non-structural request shape.

- [x] Add a structural request addendum.
  - Place it directly after the normal request example.
  - State that the addendum appears only when structural actions are enabled.
  - Show the raw first-party `editorContext.blockOperationContext` fields:
    - `targetClientId`
    - `targetBlockName`
    - `targetSignature`
    - `allowedPatterns`
  - Add one sentence noting that the server normalizes the context with lock, content-only, and editing-mode defaults before validator use.
  - Include one allowed pattern with:
    - `name`
    - `title`
    - `source`
    - `categories`
    - `blockTypes`
    - `allowedActions`

- [x] Update the normal response example.
  - Keep the existing REST wrapper shape:

    ```json
    {
      "payload": {},
      "clientId": "2b1c4f3f-1234-5678-9abc-def012345678"
    }
    ```

  - Add `payload.executionContract` beside `settings`, `styles`, `block`, `explanation`, and `resolvedContextSignature`.
  - Include representative execution contract fields used by the PHP and JS sanitizers:
    - `inspectorPanels`
    - `allowedPanels`
    - `panelMappingKnown`
    - `styleSupportPaths`
    - `bindableAttributes`
    - `contentAttributeKeys`
    - `configAttributeKeys`
    - `supportsContentRole`
    - `editingMode`
    - `isInsideContentOnly`
    - `usesInnerBlocksAsContent`
    - `registeredStyles`
    - `presetSlugs`
    - `enabledFeatures`
    - `layout`
  - Add `payload.requestMeta` with a representative provider/provenance shape:
    - `selectedProvider`
    - `selectedProviderLabel`
    - `connectorId`
    - `connectorLabel`
    - `provider`
    - `providerLabel`
    - `backendLabel`
    - `model`
    - `owner`
    - `ownerLabel`
    - `pathLabel`
    - `credentialSource`
    - `credentialSourceLabel`
    - `tokenUsage`
    - `latencyMs`
    - `usedFallback`
    - `ability`
    - `route`
  - Use realistic placeholder values, not empty objects, so the example is useful for contributors and tests.

- [x] Update the flow diagram.
  - Replace the single `Prompt::parse_response()` enforcement handoff with:
    - `Prompt::parse_response()`
    - `Prompt::enforce_block_context_rules()`
    - `BlockOperationValidator validates proposed operations`
  - Keep the existing store, Inspector, inline apply, structural apply, and undo sequence.

- [x] Update the primary functions table.
  - Add a row for `src/inspector/block-recommendation-request.js` as the request builder that carries `contextSignature`.
  - Add a row for `BlockRecommendationExecutionContract::from_context()` in `inc/Context/BlockRecommendationExecutionContract.php`.
  - Add a row for `Prompt::enforce_block_context_rules()` in `inc/LLM/Prompt.php`.
  - Add a row for `BlockOperationValidator::validate_sequence()` in `inc/Context/BlockOperationValidator.php`.
  - Add a row for `validateBlockOperationSequence()` or the catalog helpers in `src/utils/block-operation-catalog.js` as the client-side structural mirror.
  - Keep `BlockAbilities::recommend_block()` as the orchestration point rather than describing it as the only validator.

- [x] Update the key implementation files list.
  - Add `src/inspector/block-recommendation-request.js`.
  - Add `src/utils/block-operation-catalog.js`.
  - Add `src/utils/block-execution-contract.js`.
  - Add `inc/Context/BlockRecommendationExecutionContract.php`.
  - Add `inc/Context/BlockOperationValidator.php`.

- [x] Cross-check related docs without broad rewrites.
  - Confirm `docs/reference/surfaces/block-recommendations.md` still matches the feature doc stop line.
  - Confirm `docs/reference/abilities-and-routes.md` still documents the wrapped block response shape.
  - Do not edit those files unless the feature-doc patch creates a direct contradiction.

## Suggested Response Example Details

Use the existing block suggestion examples, but add response metadata in this shape:

```json
{
  "payload": {
    "settings": [],
    "styles": [],
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
      "styleSupportPaths": ["color.background", "color.text", "spacing.padding"],
      "bindableAttributes": [],
      "contentAttributeKeys": [],
      "configAttributeKeys": ["layout"],
      "supportsContentRole": true,
      "editingMode": "default",
      "isInsideContentOnly": false,
      "usesInnerBlocksAsContent": true,
      "registeredStyles": ["default", "section"],
      "presetSlugs": {
        "color": ["contrast", "base"],
        "spacing": ["40", "50", "60"]
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
      "route": "/flavor-agent/v1/recommend-block"
    }
  },
  "clientId": "2b1c4f3f-1234-5678-9abc-def012345678"
}
```

Adjust array values to match nearby examples, but keep the keys listed above.

## Verification

Run these commands after editing `docs/features/block-recommendations.md`:

```bash
npm run check:docs
git diff --check -- docs/features/block-recommendations.md docs/reference/block-recommendations-doc-contract-remediation-plan.md
rg -n "executionContract|requestMeta|blockOperationContext|contextSignature|enforce_block_context_rules|BlockOperationValidator|BlockRecommendationExecutionContract|block-operation-catalog" docs/features/block-recommendations.md
```

Expected result:

- `npm run check:docs` exits 0.
- `git diff --check` prints no whitespace errors.
- The `rg` command returns hits for every contract term listed in the pattern.

No PHP, JS, build, or browser verification is required for this docs-only patch. If implementation expands beyond docs, stop and define a separate runtime remediation plan before changing code.

## Acceptance Criteria

- [x] `docs/features/block-recommendations.md` normal request example includes `contextSignature` and a richer `themeTokens` shape.
- [x] `docs/features/block-recommendations.md` explains that `blockOperationContext` is flag-gated and shows its selected-block pattern-operation shape.
- [x] `docs/features/block-recommendations.md` normal response example includes `payload.executionContract`.
- [x] `docs/features/block-recommendations.md` normal response example includes `payload.requestMeta`.
- [x] The flow diagram and primary functions table name the PHP enforcement path and the client structural mirror.
- [x] The key implementation files list names the current request builder, execution contract, structural validator, client execution contract, and block operation catalog files.
- [x] The feature doc still respects the release stop line from `docs/reference/surfaces/block-recommendations.md`.
- [x] Verification commands pass or any blocker is recorded with the exact command and failure.
