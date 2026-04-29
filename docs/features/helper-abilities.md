# Helper Abilities And Diagnostics

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- No dedicated Gutenberg panel or wp-admin screen
- These abilities are consumed by external AI agents, plugin diagnostics, and admin tooling
- The server-registered helper abilities are:
  - `flavor-agent/introspect-block`
  - `flavor-agent/list-allowed-blocks`
  - `flavor-agent/list-patterns`
  - `flavor-agent/get-pattern`
  - `flavor-agent/list-synced-patterns`
  - `flavor-agent/get-synced-pattern`
  - `flavor-agent/list-template-parts`
  - `flavor-agent/get-active-theme`
  - `flavor-agent/get-theme-presets`
  - `flavor-agent/get-theme-styles`
  - `flavor-agent/get-theme-tokens`
  - `flavor-agent/check-status`
  - `flavor-agent/search-wordpress-docs`
- The settings screen and external diagnostics use `flavor-agent/check-status` to report backend inventory and per-surface readiness

## Surfacing Conditions

- `flavor-agent/introspect-block`, `flavor-agent/list-allowed-blocks`, `flavor-agent/list-patterns`, `flavor-agent/get-pattern`, `flavor-agent/list-synced-patterns`, `flavor-agent/get-synced-pattern`, `flavor-agent/get-active-theme`, `flavor-agent/get-theme-presets`, `flavor-agent/get-theme-styles`, `flavor-agent/get-theme-tokens`, and `flavor-agent/check-status` require `edit_posts`
- `flavor-agent/list-template-parts` allows callers with either `edit_posts` or `edit_theme_options`; `includeContent: true` is silently coerced to metadata-only when the caller lacks `edit_theme_options`
- `flavor-agent/search-wordpress-docs` requires `manage_options` and a valid docs backend; by default that is the managed public Cloudflare AI Search endpoint, with legacy Cloudflare credentials still supported for backwards compatibility
- `flavor-agent/check-status` only reports what is currently available; it does not change configuration or retry backends
- `flavor-agent/search-wordpress-docs` accepts a query plus optional `maxResults` and `entityKey` fields

## Shared Interaction Model

- These helpers are read-only from the editor's point of view
- They do not create editor activity entries and do not participate in inline undo
- They are intended as building blocks for external agents and admin-facing diagnostics rather than direct end-user editor flows

## End-To-End Flow

1. An external caller invokes a helper ability through the WordPress Abilities API
2. `inc/Abilities/Registration.php` registers the ability under the `flavor-agent` category
3. The ability delegates to a server collector, registry helper, or diagnostics helper
4. `flavor-agent/check-status` calls `FlavorAgent\Abilities\SurfaceCapabilities::build()` and returns backend inventory plus readiness data
5. `flavor-agent/get-active-theme`, `flavor-agent/get-theme-presets`, `flavor-agent/get-theme-styles`, and `flavor-agent/get-theme-tokens` read the active theme plus `wp_get_global_settings()` / `wp_get_global_styles()` through `FlavorAgent\Context\ServerCollector`
6. `flavor-agent/list-patterns`, `flavor-agent/get-pattern`, `flavor-agent/list-synced-patterns`, `flavor-agent/get-synced-pattern`, and `flavor-agent/list-template-parts` expose registry or `wp_block` entity data through `FlavorAgent\Context\ServerCollector`
7. `flavor-agent/introspect-block` and `flavor-agent/list-allowed-blocks` return block supports, inspector panels, attributes, styles, variations, and allowed inner blocks through the block introspector
8. `flavor-agent/search-wordpress-docs` sanitizes the query, resolves an optional entity key, and then searches or warms trusted WordPress docs guidance through Cloudflare AI Search

## What This Surface Can Do

- Return a block manifest for agentic inspection and capability discovery, or list the full registered block registry with each block type's allowed inner blocks
- List registered patterns with optional search, pagination, and `includeContent` control, fetch one by name via the `patternId` or `name` alias, and inspect caller-readable synced `wp_block` patterns separately from registry-backed block patterns
- Return the active theme plus current theme presets, applied theme styles, and the broader theme token snapshot for style and layout reasoning
- List template parts with either editor or theme capability, while returning markup only to theme-capable callers
- Return backend and surface-readiness diagnostics for editor and admin tooling
- Search trusted WordPress developer docs for grounded guidance, with cache warming for repeated queries

## Guardrails And Failure Modes

- Docs search fails closed only if no valid docs backend resolves, for example when the managed public endpoint is disabled or invalid and no working legacy Cloudflare credentials are available
- Only `developer.wordpress.org` guidance is accepted by the docs-search pipeline
- Empty docs queries return a `missing_query` error
- `search-wordpress-docs` may warm exact-query or entity cache entries, but that background behavior never blocks the caller
- There is no direct apply or undo path for any helper ability

## Current Contract Notes

- `flavor-agent/get-pattern` resolves by registered pattern name only. The returned `id` is the same string as `name`, and the request-side `patternId` field is an alias for that same string value rather than a separate numeric identifier.
- `flavor-agent/list-patterns` supports `search`, `limit`, `offset`, and `includeContent`, returns a `total` count, and omits `content` by default for lighter payloads.
- `flavor-agent/list-synced-patterns` queries `wp_block` posts with `post_status = any`, returns caller-readable results while preserving the helper browse fallback for published patterns, accepts `synced`, `partial`, `unsynced`, or `all` for the `syncStatus` filter, supports `search`, `limit`, `offset`, and `includeContent`, returns a `total` count, and omits `content` by default.
- `flavor-agent/list-allowed-blocks` returns the full registered block registry, sorted by title and then name. It is not filtered by the current inserter root, post type, or other editor context, but it now supports `search`, `category`, `limit`, `offset`, `includeVariations`, and `maxVariations`, plus a `total` count.
- `flavor-agent/introspect-block` still returns up to 10 variations. `flavor-agent/list-allowed-blocks` now omits `variations` by default and truncates them only when `includeVariations: true`, using `maxVariations` with a default cap of 10.
- `flavor-agent/get-theme-styles` returns both raw `styles` and extracted summaries. `elementStyles.base`, `hover`, and `focus` contain only color maps, while `focusVisible` preserves the full `:focus-visible` object.
- `flavor-agent/check-status` returns backend inventory, `availableAbilities`, and a `surfaces` map keyed by `block`, `pattern`, `content`, `template`, `templatePart`, `navigation`, `globalStyles`, and `styleBook`.
- Helper permissions are intentionally asymmetric: `get-active-theme`, `get-theme-presets`, `get-theme-styles`, and `get-theme-tokens` require `edit_posts`; `list-template-parts` allows either editor or theme capability at the outer boundary but only returns markup to theme-capable callers; the theme-oriented recommendation surfaces remain `edit_theme_options` only.

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| Ability registry | `inc/Abilities/Registration.php` | Registers the helper abilities in the `flavor-agent` category |
| Block introspection | `BlockAbilities::introspect_block()` | Returns block capabilities and inspector metadata |
| Block listing | `BlockAbilities::list_allowed_blocks()` | Returns the full registered block registry plus per-block `allowedBlocks` contracts |
| Pattern listing | `PatternAbilities::list_patterns()` | Returns registered patterns with optional filters |
| Pattern lookup | `PatternAbilities::get_pattern()` | Returns one registered pattern by name; `patternId` is an alias for the same string ID |
| Synced pattern listing | `PatternAbilities::list_synced_patterns()` | Returns caller-readable `wp_block` patterns filtered by sync status |
| Synced pattern lookup | `PatternAbilities::get_synced_pattern()` | Returns one caller-readable `wp_block` pattern by numeric post ID |
| Template-part listing | `TemplateAbilities::list_template_parts()` | Returns registered template parts with optional area filtering and optional content for theme-capable callers |
| Active theme | `InfraAbilities::get_active_theme()` | Returns the active theme name, stylesheet, template, and version |
| Theme presets | `InfraAbilities::get_theme_presets()` | Returns palette, typography, spacing, shadow, gradient, and duotone presets |
| Theme styles | `InfraAbilities::get_theme_styles()` | Returns applied global styles plus extracted element and pseudo-state summaries |
| Theme tokens | `InfraAbilities::get_theme_tokens()` | Returns the current theme token snapshot |
| Diagnostics | `InfraAbilities::check_status()` | Returns backend inventory, available ability IDs, and per-surface readiness |
| Docs search | `WordPressDocsAbilities::search_wordpress_docs()` | Searches or warms trusted WordPress docs guidance |
| Readiness contract | `SurfaceCapabilities::build()` | Builds the per-surface availability and action-link payload |
| Docs search backend | `AISearchClient::search()` / `warm_entity()` / `maybe_search_with_cache_fallbacks()` | Handles trusted docs search, cache fallback, and async warm behavior |

## Related Routes And Abilities

- Ability: `flavor-agent/introspect-block`
- Ability: `flavor-agent/list-allowed-blocks`
- Ability: `flavor-agent/list-patterns`
- Ability: `flavor-agent/get-pattern`
- Ability: `flavor-agent/list-synced-patterns`
- Ability: `flavor-agent/get-synced-pattern`
- Ability: `flavor-agent/list-template-parts`
- Ability: `flavor-agent/get-active-theme`
- Ability: `flavor-agent/get-theme-presets`
- Ability: `flavor-agent/get-theme-styles`
- Ability: `flavor-agent/get-theme-tokens`
- Ability: `flavor-agent/check-status`
- Ability: `flavor-agent/search-wordpress-docs`
- There is no dedicated REST route for these helper abilities

## Key Implementation Files

- `inc/Abilities/Registration.php`
- `inc/Abilities/BlockAbilities.php`
- `inc/Abilities/PatternAbilities.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/WordPressDocsAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `inc/Context/BlockTypeIntrospector.php`
- `inc/Context/PatternCatalog.php`
- `inc/Context/ServerCollector.php`
- `inc/Context/SyncedPatternRepository.php`
- `inc/Context/ThemeTokenCollector.php`
- `inc/Cloudflare/AISearchClient.php`
