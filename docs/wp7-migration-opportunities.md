# WP 7.0 Migration Opportunities

Generated: 2026-03-25

> Status: point-in-time migration assessment, not the live backlog.
> Use `docs/2026-03-25-roadmap-aligned-execution-plan.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/features/`, and `STATUS.md` for current priorities and shipped behavior.

## Applied Changes

### 1. Removed Dead Compatibility Guards

Removed `function_exists()` checks for functions available since WP 2.1-5.9:

| File | Guard | Status |
|------|-------|--------|
| `inc/Settings.php` | `function_exists('wp_unslash')` x3 | Removed |
| `inc/Settings.php` | `function_exists('sanitize_url')` fallback | Removed (now calls `sanitize_url()` directly) |
| `inc/Cloudflare/AISearchClient.php` | `function_exists('wp_next_scheduled')` x2 | Removed |
| `inc/Cloudflare/AISearchClient.php` | `function_exists('wp_schedule_single_event')` x2 | Removed |
| `flavor-agent.php` | `function_exists('register_block_pattern_category')` | Removed |

### 2. Simplified AI Client Wrapper

`inc/LLM/WordPressAIClient.php` — collapsed three-tier fallback (`wp_ai_client_prompt()` -> `AI_Client::prompt_with_wp_error()` -> error) to a direct `wp_ai_client_prompt()` call with early error return. The `class_exists(AI_Client::class)` middle tier is no longer needed since WP 7.0 guarantees the function.

### 3. Removed `__experimentalRole` Fallback

`role` has been stable since WP 6.7 (confirmed by Pattern Editing dev note). Removed fallback to `__experimentalRole` in:

- `src/context/block-inspector.js` — `getAttributeRole()` now returns `definition?.role`
- `inc/Context/ServerCollector.php` — `resolve_attribute_role()` now reads only `role`
- `tests/phpunit/ServerCollectorTest.php` — updated test to use stable `role` key

### 4. Added New Block Support Mappings

Added WP 7.0 block supports to `SUPPORT_TO_PANEL` in `src/context/block-inspector.js`:

- `customCSS` -> `advanced` (per-block custom CSS, stored at `style.css`)
- `listView` -> `settings` (List View tab for container blocks)

`dimensions.width` and `dimensions.height` were already mapped.

### 5. Block Visibility Viewport Form

WP 7.0 extends `blockVisibility` from `false` (boolean) to optionally `{ viewport: { mobile, tablet, desktop } }`. The plugin's handling already passes through the raw value without type assumptions, so both forms work correctly. No code changes needed. The LLM prompt already documents the viewport form.

## Remaining Opportunities

### Experimental Pattern Settings Keys (keep adapter)

The WP 7.0 dev notes did **not** confirm promotion of these pattern settings keys:
- `__experimentalBlockPatterns` / `__experimentalAdditionalBlockPatterns`
- `__experimentalBlockPatternCategories` / `__experimentalAdditionalBlockPatternCategories`
- `__experimentalGetAllowedPatterns`
- `__experimentalFeatures`

The three-tier compat adapter in `src/patterns/compat.js` should be retained. These keys may be promoted in a future release.

### Connectors API Integration (strategic)

The Connectors API (`wp_connectors_init` action, `WP_Connector_Registry` class) provides:
- `wp_is_connector_registered()`, `wp_get_connector()`, `wp_get_connectors()`
- Auto-discovery from WP AI Client registry
- Built-in admin screen at Settings > Connectors
- API key priority: env var -> PHP constant -> database (`connectors_ai_{$id}_api_key`)
- Built-in connectors: Anthropic, Google, OpenAI

The plugin already has partial Connectors integration: `inc/OpenAI/Provider.php` falls back to `connectors_ai_openai_api_key`, and block recommendations already use the core WordPress AI Client plus `Settings > Connectors` flow. A deeper integration could:
- Register the plugin's Azure OpenAI, Qdrant, and Cloudflare services as connectors via `wp_connectors_init`
- Migrate credential management from the custom Settings page to the Connectors screen
- Use `wp_get_connector()` to check provider availability instead of custom option checks

### Client-Side Abilities API (no immediate code change)

WordPress 7.0 now ships `@wordpress/abilities` plus `@wordpress/core-abilities` for client-side ability registration, querying, and execution. Because Flavor Agent already registers its abilities in PHP with `meta.show_in_rest`, those abilities are now automatically hydrated into the admin-side `core/abilities` store when core loads `@wordpress/core-abilities`.

No code change is required for the current first-party UI. The plugin can keep using feature-specific REST endpoints and its own `@wordpress/data` store for scoped permission checks, preview/apply flow, and undo. The new client-side store is mainly an additional integration surface for future admin-only tooling or browser-agent workflows.

### Pattern Overrides for Custom Blocks (feature)

WP 7.0 extends Pattern Overrides to any block via the `block_bindings_supported_attributes` filter. The pattern recommendation engine could leverage this to recommend patterns with override-aware metadata.

### New Block Supports for Recommendation Expansion (feature)

| Support | Details | Opportunity |
|---------|---------|-------------|
| `dimensions.width` / `dimensions.height` | New first-class block supports with `dimensionSizes` presets | Style recommendations can suggest width/height values using theme presets |
| `customCSS` | Per-block custom CSS stored at `style.css`, gated by `edit_css` capability | Style recommendations could suggest custom CSS declarations |
| `listView` | Inspector List View tab for container blocks | Context could indicate container block capabilities |
| `textIndent` | New typography support | Add to support mappings when text indent recommendations are needed |
| Pseudo-elements in theme.json | `::before`/`::after` styles in theme.json | Theme token extraction could include pseudo-element styles |

### contentOnly Editing Expansion

WP 7.0 defaults unsynced patterns and template parts to `contentOnly` mode. The plugin already respects `contentOnly` editing mode. New features to consider:
- `disableContentOnlyForUnsyncedPatterns` editor setting
- `"contentOnly": true` block support (alias for `contentRole`)
- Parent/child `contentOnly` blocks allowing child insertion

## Not Applicable

- **Interactivity API** - plugin uses React, not directives
- **DataViews/DataForm** - settings page is a simple options form
- **PHP-only block registration** - plugin doesn't register blocks
- **Real-time collaboration** - not relevant to AI recommendations
- **Navigation overlays** - cosmetic, doesn't affect nav recommendation logic
