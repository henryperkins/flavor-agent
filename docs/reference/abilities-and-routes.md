# Abilities And Routes Reference

This document is the contract reference for Flavor Agent's programmatic surfaces.

Use it when you need to answer:

- which ability or route owns a feature
- what capability and backend gates apply
- whether a first-party UI uses REST, abilities, or both

## How First-Party UI And Abilities Relate

- The shipped Gutenberg editor UI uses the plugin REST routes plus the `flavor-agent` data store, while the wp-admin audit page is a separate `apiFetch` + `DataViews` app and does not use that store
- The Abilities API exposes closely related contracts for external AI agents on supported WordPress 7.0+ installs
- Activity persistence and manual pattern sync are REST-only today; they do not have matching registered abilities
- `POST /flavor-agent/v1/recommend-block` is the main response-shape exception: the REST route wraps the ability payload in `{ payload, clientId }`
- Pattern, template, and template-part first-party surfaces also read the shared post-type entity contract from `src/utils/editor-entity-contracts.js`, which normalizes built-in field metadata and safe fallbacks when no live WordPress view config is exposed, so panel visibility, title-field expectations, template-part area labels, and the patched pattern category stay aligned with the current entity contract

## Registered Abilities

| Ability | Permission | Extra gate | What it returns or does | First-party surface |
|---|---|---|---|---|
| `flavor-agent/recommend-block` | `edit_posts` | Meaningful output requires `ChatClient::is_supported()` | Block recommendation payload with `settings`, `styles`, `block`, and `explanation` | Block Inspector recommendations |
| `flavor-agent/recommend-content` | `edit_posts`; positive `postContext.postId` also requires `edit_post` for that post | Connectors text-generation provider configured | Draft, edit, or critique payload for blog posts, essays, and site copy in Henry Perkins's voice, with notes and line-level rewrites. Positive `postId` requests render current-post blocks server-side before prompting; absent or `0` uses the text fallback path. | Post/page Content Recommendations panel plus external-agent contract |
| `flavor-agent/introspect-block` | `edit_posts` | None beyond capability | Block registry manifest: supports, Inspector panels, attributes, styles, and variations | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/list-allowed-blocks` | `edit_posts` | None beyond capability | Site-wide registered block manifests plus `total`, with optional search, category, pagination, and variation controls; not filtered by current inserter context | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/recommend-patterns` | `edit_posts` | Plugin-owned embeddings, Connectors text generation, Qdrant configured, usable pattern index | Ranked registered and synced/user patterns that are in the supplied visible scope and currently readable | Pattern inserter recommendations |
| `flavor-agent/list-patterns` | `edit_posts` | None beyond capability | Registered block patterns with optional category, block-type, template-type, search, pagination, and `includeContent` controls, plus `total` | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/get-pattern` | `edit_posts` | None beyond capability | One registered block pattern by name; `patternId` is an alias for the returned string `id` | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/list-synced-patterns` | `edit_posts` | Per-post read access with published browse fallback | Caller-readable or published `wp_block` pattern entities filtered by `syncStatus` (`synced`, `partial`, `unsynced`, or `all`), with optional search, pagination, `includeContent`, and `total` | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/get-synced-pattern` | `edit_posts` | Per-post read access with published browse fallback | One caller-readable or published `wp_block` pattern entity by numeric post ID | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/recommend-template` | `edit_theme_options` | Connectors text-generation provider configured | Template suggestions plus validated template-part operations and bounded pattern insertions with explicit placement and optional anchor metadata | Site Editor template panel |
| `flavor-agent/recommend-template-part` | `edit_theme_options` | Connectors text-generation provider configured | Template-part suggestions, focus blocks, patterns, and validated bounded operations constrained by executable paths and anchors | Site Editor template-part panel |
| `flavor-agent/recommend-style` | `edit_theme_options` | Connectors text-generation provider configured | Shared style suggestions for Global Styles and Style Book, constrained to validated `theme.json` paths, theme-backed values, and Global Styles-only theme variations | Site Editor Global Styles and Style Book panels |
| `flavor-agent/list-template-parts` | `edit_posts` or `edit_theme_options` | None beyond capability | Registered template parts, optionally filtered by area, with content returned only to theme-capable callers | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | Connectors text-generation provider configured for useful output | Advisory navigation suggestion groups plus explanation | Navigation guidance inside the block panel |
| `flavor-agent/search-wordpress-docs` | `manage_options` | Managed public docs backend available (legacy Cloudflare credentials optional) | Trusted WordPress developer-doc guidance, optionally warming entity cache | No direct first-party editor UI; admin and external-agent surface |
| `flavor-agent/get-active-theme` | `edit_posts` | None beyond capability | Active theme name, stylesheet, template, and version | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/get-theme-presets` | `edit_posts` | None beyond capability | Theme preset families from global settings: color, gradient, typography, spacing, shadow, and duotone | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/get-theme-styles` | `edit_posts` | None beyond capability | Applied global theme styles plus extracted element and pseudo-state summaries | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/get-theme-tokens` | `edit_posts` | None beyond capability | Theme token snapshot: colors, typography, spacing, layout, and related feature flags | No direct first-party UI; helper and external-agent surface |
| `flavor-agent/check-status` | `edit_posts` | None beyond capability | Backend inventory, active model hint, currently available ability list, and per-surface readiness map | Settings diagnostics and external-agent surface |

## Ability Notes

- All twenty abilities are registered in `inc/Abilities/Registration.php`
- On supported WordPress 7.0+ admin screens, core hydrates these server-registered abilities into the client-side abilities store
- The seven AI recommendation abilities (`recommend-block`, `recommend-content`, `recommend-patterns`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, and `recommend-style`) also opt into the Abilities API default MCP server via `meta.mcp.public = true`
- All twenty abilities declare behavior annotations. The seven AI recommendation abilities keep WP-format `meta.annotations.readonly` unset so core and `@wordpress/core-abilities` run calls stay POST for large prompt/editor payloads, while exposing direct MCP `readOnlyHint:true`, `destructive:false`, and `idempotent:false`; the 13 data-read abilities declare WP-format `readonly:true`, `destructive:false`, and `idempotent:true`.
- `flavor-agent/recommend-block` accepts different input shapes depending on the caller: the REST route passes `editorContext` (with nested `block`, `siblingsBefore`, `siblingsAfter`, `themeTokens`), while the Abilities API registers `selectedBlock` (with `structuralIdentity`, `structuralAncestors`, `structuralBranch`, `childCount`, and `blockVisibility`). `BlockAbilities::recommend_block()` normalizes both paths into a single prompt context
- When `window.flavorAgentData.enableBlockStructuralActions` is true, the first-party REST `editorContext` also includes a client-computed `blockOperationContext` with selected-block target identity, target signature, lock/content-only state, and allowed pattern metadata from Gutenberg's allowed-pattern selector. The flag is resolved from the default-off admin setting, the developer constant, and the final override filter; executable structural block operations stay empty when the flag, pattern context, target, lock, or catalog validation fails.
- Normalized block suggestions may include `operations`, `proposedOperations`, and `rejectedOperations`. `operations` contains only `FlavorAgent\Context\BlockOperationValidator`-approved block structural operations from the v1 catalog (`insert_pattern` and `replace_block_with_pattern`); `proposedOperations` preserves sanitized model proposals for diagnostics; `rejectedOperations` records standardized validator rejection codes and sanitized proposal payloads. In the editor, the JS catalog revalidates the PHP-approved operation and fails closed with `client_server_operation_mismatch` if the browser validation identity disagrees before review/apply.
- `flavor-agent/check-status` now reports the runtime-gated `availableAbilities` list plus a `surfaces` map that explains per-surface ready / unavailable state for block, pattern, template, template-part, navigation, Global Styles, and Style Book UIs
- The `surfaces` map uses the keys `block`, `pattern`, `content`, `template`, `templatePart`, `navigation`, `globalStyles`, and `styleBook`. Each entry returns `available`, `reason`, `owner`, `actions`, `configurationLabel`, `configurationUrl`, `message`, and `advisoryOnly`.
- `flavor-agent/get-pattern` resolves only by registered pattern name. The returned `id` is the same string as `name`, and `patternId` is a convenience alias for that same value.
- `flavor-agent/list-patterns` supports `search`, `limit`, `offset`, and `includeContent`, returns `total`, and omits `content` by default.
- `flavor-agent/list-synced-patterns` accepts `synced`, `partial`, `unsynced`, or `all`. It queries `wp_block` posts with `post_status = any`, keeps the helper browse fallback that allows published posts when `read_post` is denied, supports `search`, `limit`, `offset`, and `includeContent`, returns `total`, and omits `content` by default.
- `flavor-agent/get-synced-pattern` uses the same helper browse fallback for published `wp_block` patterns. That fallback is not reused by recommendation ranking.
- `flavor-agent/recommend-patterns` requires `visiblePatternNames` from the current inserter root; missing or empty scope returns an empty recommendation list. It indexes registered patterns plus public-safe published synced/user `wp_block` patterns, then rehydrates synced candidates through current `read_post` access before ranking or response output. Synced/user candidates keep Gutenberg's `core/block/{id}` names and carry `type: user`, `source: synced`, `syncedPatternId`, and `syncStatus` metadata in recommendation payloads. The response may include `diagnostics.filteredCandidates.unreadableSyncedPatterns`, a de-duplicated aggregate count of visible-scope synced/user candidates skipped because the current request could not read the source `wp_block`. This diagnostic is intentionally non-identifying and is safe for the inserter UI to display.
- `flavor-agent/list-allowed-blocks` returns the whole registered block registry rather than context-aware inserter results. It now also supports `search`, `category`, `limit`, `offset`, `includeVariations`, and `maxVariations`, returns `total`, and omits `variations` by default. `introspect-block` still returns up to 10 variations; `list-allowed-blocks` truncates only when `includeVariations` is enabled.
- `flavor-agent/get-theme-styles` returns both raw `styles` and extracted summaries. `elementStyles.base`, `hover`, and `focus` are color-only objects, while `focusVisible` preserves the full `:focus-visible` object.
- Helper permissions are intentionally asymmetric: `get-active-theme`, `get-theme-presets`, `get-theme-styles`, and `get-theme-tokens` require `edit_posts`; `list-template-parts` allows either editor or theme capability at the boundary but silently coerces `includeContent: true` to metadata-only unless the caller has `edit_theme_options`; the theme-oriented recommendation surfaces remain `edit_theme_options` only.
- The executable first-party editor surfaces (`block`, `template`, `template-part`, `global-styles`, and `style-book`) still compute a local request signature from the live context signature plus the composer prompt and scoped entity ref. That signature remains client-local and is not POSTed back to PHP.
- The same five executable surfaces now also store a server `resolvedContextSignature` on normal responses. PHP computes that hash from the server-normalized apply context plus the sanitized prompt, so it still captures server-only context such as theme tokens, pattern candidates, and Style Book block-manifest details without making docs-cache churn part of freshness.
- Template, template-part, Global Styles, Style Book, and advisory navigation responses also store a server `reviewContextSignature`. These review hashes cover docs-free server-owned context so background review freshness tracks real server drift without treating docs guidance churn as stale state.
- `flavor-agent/recommend-block`, `flavor-agent/recommend-template`, `flavor-agent/recommend-template-part`, `flavor-agent/recommend-navigation`, and `flavor-agent/recommend-style` accept an optional boolean `resolveSignatureOnly`. When true, they resolve only the current server freshness signature(s) and return them (`resolvedContextSignature` for block, `reviewContextSignature` plus `resolvedContextSignature` for template/template-part/style, and `reviewContextSignature` for navigation) without doing docs lookup or model calls. REST callers of `POST /flavor-agent/v1/recommend-block` must read the signature from `payload.resolvedContextSignature` because the route wraps signature-only block responses as `{ payload, clientId }`.
- `flavor-agent/recommend-patterns` remains a request-time ranking surface. It does not accept `resolveSignatureOnly` and does not participate in review/apply freshness revalidation.

## REST Routes

| Route | Permission | First-party caller | Backend owner | Notes |
|---|---|---|---|---|
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | `fetchBlockRecommendations()` | `BlockAbilities::recommend_block()` | Wraps both normal and signature-only responses as `{ payload, clientId }` |
| `POST /flavor-agent/v1/recommend-content` | `edit_posts`; positive `postContext.postId` also requires `edit_post` for that post | `fetchContentRecommendations()` | `ContentAbilities::recommend_content()` | Thin REST adapter over the content recommendation ability; the first-party client sends `postId` so the server can render current-post blocks before prompt assembly |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | `fetchPatternRecommendations()` | `PatternAbilities::recommend_patterns()` | Thin REST adapter over the ability; requires scoped `visiblePatternNames`; no `resolveSignatureOnly` contract |
| `POST /flavor-agent/v1/recommend-navigation` | `edit_theme_options` | `fetchNavigationRecommendations()` | `NavigationAbilities::recommend_navigation()` | Accepts `resolveSignatureOnly`; normal responses include a docs-free `reviewContextSignature` |
| `POST /flavor-agent/v1/recommend-template` | `edit_theme_options` | `fetchTemplateRecommendations()` | `TemplateAbilities::recommend_template()` | Accepts `resolveSignatureOnly`; normal responses include docs-free `reviewContextSignature` and `resolvedContextSignature` |
| `POST /flavor-agent/v1/recommend-template-part` | `edit_theme_options` | `fetchTemplatePartRecommendations()` | `TemplateAbilities::recommend_template_part()` | Accepts `resolveSignatureOnly`; normal responses include docs-free `reviewContextSignature` and `resolvedContextSignature` |
| `POST /flavor-agent/v1/recommend-style` | `edit_theme_options` | `fetchGlobalStylesRecommendations()` and `fetchStyleBookRecommendations()` | `StyleAbilities::recommend_style()` | Accepts `resolveSignatureOnly`; normal responses include docs-free `reviewContextSignature` and `resolvedContextSignature` |
| `GET /flavor-agent/v1/activity` | Contextual editor/theme capability; `manage_options` for global reads | `loadActivitySession()` and admin activity log | `ActivityRepository::query()` for scoped reads; `ActivityRepository::query_admin()` for global admin reads | Scoped queries power editor/theme history; global admin reads return pagination, summary, and filter-option metadata for the audit page |
| `POST /flavor-agent/v1/activity` | Contextual editor/theme capability | Store-side activity persistence | `ActivityRepository::create()` | Persists server-backed activity entries, including executable apply rows and scoped read-only `request_diagnostic` audit rows |
| `POST /flavor-agent/v1/activity/{id}/undo` | Contextual editor/theme capability | `undoActivity()` | `ActivityRepository::update_undo_status()` | Persists undo-status transitions |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | `src/admin/settings-page-controller.js` | `PatternIndex::sync()` | Manual admin-only pattern catalog rebuild with live settings-panel state refresh |

## Activity Route Notes

- Activity persistence is REST-only today. There is no matching registered ability for create/read/undo.
- `POST /flavor-agent/v1/activity` persists the request provenance that the UI shows later: backend/provider label, model, provider path, configuration owner, credential source, selected provider, fallback usage, route, ability, prompt, reference, token usage, and latency when the originating client includes them.
- The repository projects the admin-audit fields it needs for filtering into schema-versioned table columns (`admin_post_type`, `admin_operation_type`, `admin_provider`, `admin_provider_path`, `admin_configuration_owner`, `admin_credential_source`, `admin_selected_provider`, `admin_request_ability`, `admin_request_route`, `admin_request_reference`, `admin_request_prompt`, and related identifiers) so `Settings > AI Activity` does not need to decode every historical `request_json` payload to filter by provenance.
- `GET /flavor-agent/v1/activity?global=1` is the only route that exposes the wp-admin audit feed. It rejects malformed active admin date filters with `400` instead of broadening the query; the wp-admin UI also blocks incomplete or inverted persisted date filters until the filter is completed or reset. Its `filterOptions.operationType` values are grouped by effective action type such as `insert` and `replace`. It is still intentionally a first audit slice rather than a full observability console: the response includes timeline entries, summary counts, pagination, and filter options, but not diff-oriented inspection or broader operator workflows.

## Example Contracts

### Check-Status Response Shape

```json
{
  "configured": true,
  "model": "gpt-5.4-mini",
  "availableAbilities": [
    "flavor-agent/introspect-block",
    "flavor-agent/get-theme-styles",
    "flavor-agent/check-status"
  ],
  "surfaces": {
    "block": {
      "available": true,
      "reason": "ready",
      "owner": "connectors",
      "actions": [],
      "configurationLabel": "",
      "configurationUrl": "",
      "message": "Block recommendations are configured.",
      "advisoryOnly": false
    },
    "navigation": {
      "available": false,
      "reason": "missing_theme_capability",
      "owner": "connectors",
      "actions": [],
      "configurationLabel": "",
      "configurationUrl": "",
      "message": "Navigation recommendations require the edit_theme_options capability.",
      "advisoryOnly": true
    }
  },
  "backends": {
    "wordpress_ai_client": {
      "configured": true
    },
    "azure_openai": {
      "configured": true,
      "embeddingDeployment": "text-embedding-3-small"
    },
    "openai_native": {
      "configured": true,
      "embeddingModel": "text-embedding-3-small",
      "credentialSource": "connector_database",
      "connectorRegistered": true,
      "connectorConfigured": true,
      "connectorKeySource": "database"
    }
  }
}
```

`configured` means the active chat runtime is available, or the pattern pipeline has both a configured embedding provider and Qdrant. It is not a standalone docs-search readiness flag. WordPress docs search availability is reflected in `availableAbilities` and `backends.cloudflare_ai_search`.

### List-Allowed-Blocks Response Shape

```json
{
  "total": 237,
  "blocks": [
    {
      "name": "core/group",
      "title": "Group",
      "category": "design",
      "description": "Gather blocks in a container.",
      "supports": {
        "spacing": {
          "padding": true
        }
      },
      "inspectorPanels": {
        "dimensions": ["minHeight"],
        "general": ["tagName"]
      },
      "bindableAttributes": [],
      "contentAttributes": {},
      "configAttributes": {
        "tagName": {
          "type": "string",
          "default": "div",
          "role": "config"
        }
      },
      "styles": [
        {
          "name": "default",
          "label": "Default",
          "isDefault": true
        }
      ],
      "variations": [],
      "supportsContentRole": false,
      "parent": null,
      "allowedBlocks": ["core/heading", "core/paragraph"],
      "apiVersion": 3
    }
  ]
}
```

This response is the full registered block registry sorted by title and then name. It is not filtered by the current inserter context. `total` reports the unpaginated match count. `variations` are omitted by default and truncate to the first `maxVariations` entries only when `includeVariations: true`.

### List-Synced-Patterns Response Shape

```json
{
  "total": 2,
  "patterns": [
    {
      "id": 42,
      "title": "Announcement Bar",
      "slug": "announcement-bar",
      "status": "draft",
      "authorId": 1,
      "dateGmt": "2026-04-23 13:40:18",
      "modifiedGmt": "2026-04-23 13:52:09",
      "syncStatus": "partial",
      "wpPatternSyncStatus": "partial"
    }
  ]
}
```

The request-side `syncStatus` filter accepts `synced`, `partial`, `unsynced`, or `all`. The list response omits `content` by default, returns `total`, and the underlying query uses `post_status = any`.

### Theme Styles Response Shape

```json
{
  "styles": {
    "elements": {
      "link": {
        "color": {
          "text": "var(--wp--preset--color--contrast)"
        },
        ":hover": {
          "color": {
            "text": "var(--wp--preset--color--accent)"
          }
        },
        ":focus-visible": {
          "color": {
            "text": "var(--wp--preset--color--accent)"
          },
          "outline": {
            "color": "var(--wp--preset--color--accent)",
            "style": "solid",
            "width": "2px"
          }
        }
      }
    }
  },
  "elementStyles": {
    "link": {
      "base": {
        "text": "var(--wp--preset--color--contrast)"
      },
      "hover": {
        "text": "var(--wp--preset--color--accent)"
      },
      "focus": {},
      "focusVisible": {
        "color": {
          "text": "var(--wp--preset--color--accent)"
        },
        "outline": {
          "color": "var(--wp--preset--color--accent)",
          "style": "solid",
          "width": "2px"
        }
      }
    }
  },
  "blockPseudoStyles": {
    "core/button": {
      ":hover": {
        "color": {
          "background": "var(--wp--preset--color--accent)"
        }
      }
    }
  },
  "diagnostics": {
    "source": "server",
    "settingsKey": "wp_get_global_settings",
    "reason": "server-global-settings"
  }
}
```

`elementStyles.base`, `hover`, and `focus` expose only color maps. `focusVisible` preserves the full `:focus-visible` pseudo-state object.

### Block Recommendation REST Request

```json
{
  "editorContext": {
    "block": {
      "name": "core/group",
      "currentAttributes": {
        "layout": {
          "type": "constrained"
        }
      },
      "editingMode": "default",
      "isInsideContentOnly": false
    },
    "siblingsBefore": ["core/heading"],
    "siblingsAfter": ["core/paragraph"],
    "themeTokens": {
      "colors": ["contrast"],
      "spacing": ["40", "50", "60"]
    }
  },
  "prompt": "Give this section more breathing room.",
  "clientId": "block-client-id"
}
```

### Block Recommendation Structural Operation Shape

```json
{
  "block": [
    {
      "label": "Add a hero after this block",
      "description": "Use the registered hero pattern directly after the selected section.",
      "type": "pattern_replacement",
      "attributeUpdates": {},
      "operations": [
        {
          "catalogVersion": 1,
          "type": "insert_pattern",
          "patternName": "theme/hero",
          "targetClientId": "block-client-id",
          "targetType": "block",
          "targetSignature": "sha256-of-selected-target",
          "position": "insert_after",
          "expectedTarget": {
            "clientId": "block-client-id",
            "name": "core/group"
          }
        }
      ],
      "proposedOperations": [
        {
          "type": "insert_pattern",
          "patternName": "theme/hero",
          "targetClientId": "block-client-id",
          "position": "insert_after"
        }
      ],
      "rejectedOperations": []
    }
  ],
  "explanation": "The selected section can safely be followed by the allowed hero pattern.",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

The normalized block operation metadata is `catalogVersion`, `type`, `patternName`, `targetClientId`, `targetType`, `targetSignature`, `position` for `insert_pattern`, `action: "replace"` for `replace_block_with_pattern`, and `expectedTarget` when the server can record the selected target fingerprint. Rejection entries use the same operation shape under `operation` plus one of the catalog rejection codes: `block_structural_actions_disabled`, `multi_operation_unsupported`, `invalid_operation_payload`, `unknown_operation_type`, `missing_pattern_name`, `pattern_not_available`, `missing_target_client_id`, `stale_target`, `cross_surface_target`, `invalid_target_type`, `locked_target`, `content_only_target`, `invalid_insertion_position`, `action_not_allowed`, or the client-side fail-closed code `client_server_operation_mismatch`.

### Template Ability Request

```json
{
  "templateRef": "theme//single-post",
  "templateType": "single",
  "prompt": "Introduce a supporting sidebar.",
  "visiblePatternNames": ["core/post-meta-two-column"],
  "editorSlots": {
    "assignedParts": [
      {
        "slug": "header",
        "area": "header"
      }
    ],
    "emptyAreas": ["sidebar"]
  },
  "editorStructure": {
    "topLevelBlockTree": [
      {
        "path": [0],
        "name": "core/template-part",
        "label": "Header",
        "attributes": { "slug": "header", "area": "header" },
        "childCount": 3,
        "slot": { "slug": "header", "area": "header", "isEmpty": false }
      },
      {
        "path": [1],
        "name": "core/group",
        "label": "Content",
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
  }
}
```

The client only sends live slot occupancy (`assignedParts`, `emptyAreas`). The server keeps canonical saved capability metadata and computes the effective `allowedAreas` set by merging those live areas with the saved template contract. Empty templates still send `editorSlots` and `editorStructure` with empty arrays and zeroed stats.

### Template-Part Ability Request

```json
{
  "templatePartRef": "theme//header",
  "prompt": "Create a stronger utility row above the main navigation.",
  "visiblePatternNames": ["core/header-with-utility-row"],
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
      "core/navigation": 1
    },
    "structureStats": {
      "blockCount": 2,
      "maxDepth": 2,
      "hasNavigation": true,
      "containsLogo": false,
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

`editorStructure.blockTree` is the prompt-facing summary. `editorStructure.allBlockPaths` is the full live path index the server uses to validate deep executable paths and stale signatures. Empty template parts still send the same keys with empty trees, zeroed stats, no operation targets, and start/end anchors.

### Template Ability Response Shape

```json
{
  "suggestions": [
    {
      "label": "Lead with the hero before the header",
      "description": "Use the validated top-level anchor so the intro lands ahead of the current header slot.",
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "core/post-meta-two-column",
          "placement": "before_block_path",
          "targetPath": [1],
          "expectedTarget": {
            "name": "core/group",
            "label": "Content",
            "attributes": {},
            "childCount": 2
          }
        }
      ],
      "templateParts": [],
      "patternSuggestions": ["core/post-meta-two-column"]
    }
  ],
  "explanation": "The top-level template structure exposes a safe anchor before the existing header block.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

### Style Ability Request

```json
{
  "scope": {
    "surface": "global-styles",
    "scopeKey": "global_styles:17",
    "globalStylesId": "17",
    "postType": "global_styles",
    "entityId": "17",
    "entityKind": "root",
    "entityName": "globalStyles"
  },
  "styleContext": {
    "currentConfig": {
      "settings": {},
      "styles": {}
    },
    "mergedConfig": {
      "settings": {},
      "styles": {}
    },
    "availableVariations": [
      {
        "title": "Midnight"
      }
    ],
    "themeTokenDiagnostics": {
      "source": "server",
      "settingsKey": "wp_get_global_settings",
      "reason": "server-global-settings"
    },
    "templateStructure": [
      {
        "name": "core/template-part",
        "innerBlocks": [{ "name": "core/site-title" }]
      },
      {
        "name": "core/group",
        "innerBlocks": [{ "name": "core/query-title" }]
      }
    ],
    "templateVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
    }
  },
  "prompt": "Make the site feel more editorial."
}
```

### Style Book Ability Request

```json
{
  "scope": {
    "surface": "style-book",
    "scopeKey": "style_book:17:core/group",
    "globalStylesId": "17",
    "entityKind": "block",
    "entityName": "styleBook",
    "entityId": "core/group",
    "blockName": "core/group",
    "blockTitle": "Group"
  },
  "styleContext": {
    "currentConfig": {
      "settings": {},
      "styles": {}
    },
    "mergedConfig": {
      "settings": {},
      "styles": {}
    },
    "styleBookTarget": {
      "blockName": "core/group",
      "blockTitle": "Group",
      "currentStyles": {},
      "mergedStyles": {}
    },
    "templateStructure": [
      {
        "name": "core/template-part",
        "innerBlocks": [{ "name": "core/heading" }]
      },
      {
        "name": "core/group",
        "innerBlocks": [{ "name": "core/paragraph" }]
      }
    ],
    "templateVisibility": {
      "hasVisibilityRules": false,
      "blockCount": 0,
      "blocks": []
    }
  },
  "prompt": "Make this block feel more editorial."
}
```

### Template-Part Ability Response Shape

```json
{
  "suggestions": [
    {
      "label": "Add a utility row before navigation",
      "description": "Strengthen secondary navigation without crowding the main row.",
      "blockHints": [
        {
          "path": [0, 1],
          "label": "Navigation block",
          "blockName": "core/navigation",
          "reason": "This is the existing structural anchor."
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
  "explanation": "The current header supports a bounded insertion before the existing navigation block.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt",
  "resolvedContextSignature": "sha256-of-surface-apply-context-and-prompt"
}
```

### Navigation Response Shape

```json
{
  "suggestions": [
    {
      "label": "Flatten low-value nesting",
      "description": "Reducing one submenu level makes key destinations easier to scan.",
      "category": "structure",
      "changes": [
        {
          "type": "flatten",
          "targetPath": [2],
          "target": "Resources submenu",
          "detail": "Promote the two most-used links to the top level."
        }
      ]
    }
  ],
  "explanation": "The current menu depth is heavier than the observed information hierarchy needs.",
  "reviewContextSignature": "sha256-of-surface-review-context-and-prompt"
}
```

### Pattern Sync Response Shape

```json
{
  "indexed": 6,
  "removed": 2,
  "fingerprint": "1b52d1f7c8a7e3f1",
  "status": "ready"
}
```

### Activity Entry Shape

```json
{
  "surface": "template-part",
  "suggestion": "Add a utility row before navigation",
  "request": {
    "prompt": "Create a stronger utility row above the main navigation.",
    "reference": "template-part:theme//header:3"
  },
  "document": {
    "scopeKey": "wp_template_part:theme//header",
    "postType": "wp_template_part",
    "entityId": "theme//header"
  },
  "undo": {
    "status": "available"
  }
}
```

## Sequence Cheatsheet

```text
Block UI -> store -> /recommend-block -> BlockAbilities -> ChatClient -> Prompt -> UI
Pattern UI -> store -> /recommend-patterns -> PatternAbilities -> Qdrant + Responses -> inserter patch
Navigation UI -> store -> /recommend-navigation -> NavigationAbilities -> NavigationPrompt -> advisory UI
Template UI -> store -> /recommend-template -> TemplateAbilities -> TemplatePrompt -> preview/apply/undo
Template-part UI -> store -> /recommend-template-part -> TemplateAbilities -> TemplatePartPrompt -> preview/apply/undo
Global Styles / Style Book UI -> store -> /recommend-style -> StyleAbilities -> StylePrompt -> preview/apply/undo
Apply flow -> activity create -> inline activity UI -> undo -> activity/{id}/undo
```

## Route Notes

- The recommendation routes sanitize and normalize structured inputs before handing them to the ability layer
- Normal `recommend-block` responses include `resolvedContextSignature`. Template, template-part, and style responses include docs-free `reviewContextSignature` and `resolvedContextSignature`, and navigation includes docs-free `reviewContextSignature` as its only server freshness field.
- Signature-only requests return only the current freshness field(s) after normalizing the current server context and prompt; they skip docs grounding and model calls. The block REST route keeps its `{ payload, clientId }` wrapper even in signature-only mode.
- `POST /flavor-agent/v1/recommend-patterns` remains outside that freshness contract and does not accept `resolveSignatureOnly`.
- `POST /flavor-agent/v1/recommend-patterns` can return synced/user pattern recommendations by their `core/block/{id}` names when those names are present in the current `visiblePatternNames` set.
- `POST /flavor-agent/v1/recommend-patterns` does not accept `editorStructure`; the current pattern route contract ignores it
- Template recommendation requests carry an editor-collected `editorStructure` with the live top-level block tree, zeroed empty-state stats when needed, current pattern-override summaries, and current viewport-visibility summaries; the server replaces that mutable slice atomically and derives insertion anchors from the live tree
- Template recommendation requests also carry live `editorSlots.assignedParts` and `editorSlots.emptyAreas`; the server keeps canonical saved capability metadata and computes effective `allowedAreas` by merging those live areas with the saved template contract
- Template-part requests accept a full live `editorStructure` slice: `blockTree`, `allBlockPaths`, `topLevelBlocks`, `blockCounts`, `structureStats`, `currentPatternOverrides`, `operationTargets`, `insertionAnchors`, and `structuralConstraints`
- Template-part executable paths are validated against `editorStructure.allBlockPaths`, so deep unsaved paths remain valid even when the prompt-facing `blockTree` is depth-limited
- Global Styles and Style Book requests carry live `styleContext.templateStructure` and `styleContext.templateVisibility` snapshots from the current editor canvas so style docs grounding and prompt shaping stay aligned with the template the user is actually looking at
- Docs grounding stays surface-specific: template requests scope guidance with live slot occupancy, top-level structure, visible patterns, pattern overrides, and viewport visibility; template-part requests scope guidance with operation targets, insertion anchors, structural constraints, and live block paths; style requests scope guidance with supported style paths, available variations or the active Style Book target, design semantics, and the live template structure/visibility snapshot
- Navigation response groups now validate structural `changes[].targetPath` values against the current menu target inventory instead of trusting free-form target text alone
- Activity permissions are contextual: post-like scopes use `edit_posts` or `edit_post`, while template and template-part scopes use `edit_theme_options`
- Manual sync is intentionally admin-only because it mutates shared vector-index state

## Primary Source Files

- `inc/Abilities/Registration.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/StyleAbilities.php`
- `inc/Abilities/NavigationAbilities.php`
- `inc/Abilities/WordPressDocsAbilities.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/REST/Agent_Controller.php`
- `src/store/index.js`

## Contract Verification Checklist

When updating any ability or REST contract, keep these sources aligned:

1. Ability registrations and schemas in `inc/Abilities/Registration.php`
2. REST route registrations, permission callbacks, and sanitization in `inc/REST/Agent_Controller.php`
3. First-party callers and payload shaping in `src/store/index.js` and the relevant surface module
