# Helper Abilities And Diagnostics

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- No dedicated Gutenberg panel or wp-admin screen
- These abilities are consumed by external AI agents, plugin diagnostics, and admin tooling
- The exact registered ability list, permissions, schemas, and annotations are canonical in `docs/reference/abilities-and-routes.md#registered-abilities`
- The settings screen and external diagnostics use `flavor-agent/check-status` to report backend inventory and per-surface readiness

## Surfacing Conditions

- Helper abilities are exposed through the WordPress Abilities API when their capability checks pass. Exact permissions, request fields, response shapes, and read-only annotations are canonical in `docs/reference/abilities-and-routes.md#registered-abilities`.
- `flavor-agent/check-status` only reports what is currently available; it does not change configuration or retry backends.

## Shared Interaction Model

- Helper/read abilities other than `flavor-agent/search-wordpress-docs` are read-only from the editor's point of view
- They do not create editor activity entries and do not participate in inline undo; recommendation abilities are separate and may persist request-diagnostic activity
- They are intended as building blocks for external agents and admin-facing diagnostics rather than direct end-user editor flows

## End-To-End Flow

1. An external caller invokes a helper ability through the WordPress Abilities API
2. `inc/Abilities/Registration.php` registers the ability under the `flavor-agent` category
3. The ability delegates to a server collector, registry helper, or diagnostics helper
4. `flavor-agent/check-status` calls `FlavorAgent\Abilities\SurfaceCapabilities::build()` and returns backend inventory plus readiness data
5. Theme, pattern, template-part, and block-introspection helpers read current WordPress registry/entity state through the server collectors
6. `flavor-agent/search-wordpress-docs` sanitizes the query, resolves an optional entity key, and then searches or warms trusted WordPress docs guidance through Cloudflare AI Search

## What This Surface Can Do

- Return a block manifest for agentic inspection and capability discovery, or list the full registered block registry with each block type's allowed inner blocks
- List registered patterns with optional search, pagination, and `includeContent` control, fetch one by name via the `patternId` or `name` alias, and inspect caller-readable synced `wp_block` patterns separately from registry-backed block patterns
- Return the active theme plus current theme presets, applied theme styles, and the broader theme token snapshot for style and layout reasoning
- List template parts with either editor or theme capability, while returning markup only to theme-capable callers
- Return backend and surface-readiness diagnostics for editor and admin tooling
- Search trusted WordPress developer docs for grounded guidance, with cache warming for repeated queries

## Guardrails And Failure Modes

- Docs search fails closed for empty queries, unavailable public endpoint config, HTTP/search/parse errors, or untrusted source filtering; source policy and cache behavior are canonical in `docs/reference/developer-docs-public-corpus-runbook.md`.
- There is no direct apply or undo path for any helper ability

## Contract Pointers

- Exact permissions, request fields, response shapes, and helper contract notes: `docs/reference/abilities-and-routes.md`
- Provider and backend readiness semantics: `docs/reference/provider-precedence.md`
- Docs-grounding source policy and corpus operations: `docs/reference/developer-docs-public-corpus-runbook.md`

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

The helper ability inventory is canonical in `docs/reference/abilities-and-routes.md#registered-abilities`. There is no plugin-owned `/flavor-agent/v1` route for these helpers; they are exposed through the WordPress Abilities API REST surface because the ability registrations set `meta.show_in_rest` to `true`.

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
