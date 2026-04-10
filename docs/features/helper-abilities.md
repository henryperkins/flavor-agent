# Helper Abilities And Diagnostics

Use this with `docs/FEATURE_SURFACE_MATRIX.md` for the quick view and `docs/reference/abilities-and-routes.md` for the exact contract.

## Exact Surface

- No dedicated Gutenberg panel or wp-admin screen
- These abilities are consumed by external AI agents, plugin diagnostics, and admin tooling
- The server-registered helper abilities are:
  - `flavor-agent/introspect-block`
  - `flavor-agent/list-patterns`
  - `flavor-agent/list-template-parts`
  - `flavor-agent/get-theme-tokens`
  - `flavor-agent/check-status`
  - `flavor-agent/search-wordpress-docs`
- The settings screen and external diagnostics use `flavor-agent/check-status` to report backend inventory and per-surface readiness

## Surfacing Conditions

- `flavor-agent/introspect-block`, `flavor-agent/list-patterns`, `flavor-agent/get-theme-tokens`, and `flavor-agent/check-status` require `edit_posts`
- `flavor-agent/list-template-parts` requires `edit_theme_options`
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
5. `flavor-agent/get-theme-tokens` calls `FlavorAgent\Context\ServerCollector::for_tokens()` and returns the current theme token snapshot
6. `flavor-agent/list-patterns` and `flavor-agent/list-template-parts` expose registry data through `FlavorAgent\Context\ServerCollector`
7. `flavor-agent/introspect-block` returns block supports, inspector panels, attributes, styles, and variations through the block introspector
8. `flavor-agent/search-wordpress-docs` sanitizes the query, resolves an optional entity key, and then searches or warms trusted WordPress docs guidance through Cloudflare AI Search

## What This Surface Can Do

- Return a block manifest for agentic inspection and capability discovery
- List registered patterns and template parts for content planning or tooling
- Return the current theme token snapshot for style and layout reasoning
- Return backend and surface-readiness diagnostics for editor and admin tooling
- Search trusted WordPress developer docs for grounded guidance, with cache warming for repeated queries

## Guardrails And Failure Modes

- Docs search fails closed only if no valid docs backend resolves, for example when the managed public endpoint is disabled or invalid and no working legacy Cloudflare credentials are available
- Only `developer.wordpress.org` guidance is accepted by the docs-search pipeline
- Empty docs queries return a `missing_query` error
- `search-wordpress-docs` may warm exact-query or entity cache entries, but that background behavior never blocks the caller
- There is no direct apply or undo path for any helper ability

## Primary Functions And Handlers

| Layer | Function / class | Role |
|---|---|---|
| Ability registry | `inc/Abilities/Registration.php` | Registers the helper abilities in the `flavor-agent` category |
| Block introspection | `BlockAbilities::introspect_block()` | Returns block capabilities and inspector metadata |
| Pattern listing | `PatternAbilities::list_patterns()` | Returns registered patterns with optional filters |
| Template-part listing | `TemplateAbilities::list_template_parts()` | Returns registered template parts with optional area filtering |
| Theme tokens | `InfraAbilities::get_theme_tokens()` | Returns the current theme token snapshot |
| Diagnostics | `InfraAbilities::check_status()` | Returns backend inventory, active model hints, and surface readiness |
| Docs search | `WordPressDocsAbilities::search_wordpress_docs()` | Searches or warms trusted WordPress docs guidance |
| Readiness contract | `SurfaceCapabilities::build()` | Builds the per-surface availability and action-link payload |
| Docs search backend | `AISearchClient::search()` / `warm_entity()` / `maybe_search_with_cache_fallbacks()` | Handles trusted docs search, cache fallback, and async warm behavior |

## Related Routes And Abilities

- Ability: `flavor-agent/introspect-block`
- Ability: `flavor-agent/list-patterns`
- Ability: `flavor-agent/list-template-parts`
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
- `inc/Context/ServerCollector.php`
- `inc/Cloudflare/AISearchClient.php`
