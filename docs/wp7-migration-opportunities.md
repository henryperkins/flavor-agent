# WP 7.0 Migration Opportunities

Generated: 2026-03-25
Verified: 2026-04-20

> Status: point-in-time migration assessment, not the live backlog. This doc has two parts: [Applied Changes](#applied-changes) records WP 7.0 migration work already shipped, and [Remaining Opportunities](#remaining-opportunities) lists still-open watch items and future-facing evaluations. Neither section is re-verified on every release — treat both as frozen snapshots and confirm against the current source tree before acting.
> Verification basis: current source tree, targeted doc and source review, and official WordPress 7.0 release-cycle docs/dev notes re-checked on 2026-04-20.
> Use `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/features/`, and `STATUS.md` for current priorities and shipped behavior.
> Use `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md` for the active remediation plan where Flavor Agent should stop duplicating Gutenberg or hand ownership back to core.

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

Aligned current Gutenberg support mappings in `SUPPORT_TO_PANEL` (`src/context/block-inspector.js` and `inc/Context/ServerCollector.php`):

- `customCSS` -> `advanced` (per-block custom CSS, stored at `style.css`)
- `listView` -> `list` (dedicated List View inspector tab)
- `typography.fitText` -> `typography`
- `typography.textIndent` -> `typography`
- bindable attributes now surface as the dedicated `bindings` inspector panel when the block editor exposes them

`dimensions.width` and `dimensions.height` were already mapped.

### 5. Block Visibility Viewport Form

WP 7.0 extends `blockVisibility` from `false` (boolean) to optionally `{ viewport: { mobile, tablet, desktop } }`. The plugin's handling already passes through the raw value without type assumptions, so both forms work correctly. No code changes needed. The LLM prompt already documents the viewport form.

## Remaining Opportunities

### Experimental Settings Adapters (keep adapter-backed)

The WP 7.0 dev notes did **not** confirm promotion of these pattern settings keys, and Gutenberg trunk still exposes the experimental forms as the live baseline:
- `__experimentalBlockPatterns` / `__experimentalAdditionalBlockPatterns`
- `__experimentalBlockPatternCategories` / `__experimentalAdditionalBlockPatternCategories`
- `__experimentalGetAllowedPatterns`

Flavor Agent should retain the pattern settings compatibility layer in `src/patterns/pattern-settings.js` (re-exported via `src/patterns/compat.js`). These keys may still be promoted in a future release.

Separately, theme-token extraction should keep its `__experimentalFeatures` merge path in `src/context/theme-settings.js`. That key is still part of the repo's adapter-backed theme-token source resolution and is not something this plugin should treat as fully settled.

### Connectors API Integration (strategic)

The Connectors API (`wp_connectors_init` action, `WP_Connector_Registry` class) provides:
- `wp_is_connector_registered()`, `wp_get_connector()`, `wp_get_connectors()`
- Auto-discovery from WP AI Client registry
- Built-in admin screen at Settings > Connectors
- API key priority: env var -> PHP constant -> database (`connectors_ai_{$id}_api_key`)
- Built-in connectors: Anthropic, Google, OpenAI

The plugin already has partial Connectors integration: `inc/OpenAI/Provider.php` falls back to `connectors_ai_openai_api_key`, and block recommendations already use the core WordPress AI Client plus `Settings > Connectors` flow. A deeper integration could:
- Lean harder on connector metadata for OpenAI Native availability and credential-source UX
- Potentially move additional AI-provider-style credentials into Connectors where the API is a natural fit
- Use `wp_get_connector()` / `wp_is_connector_registered()` to check provider availability instead of custom option checks in more places

Qdrant and Cloudflare AI Search should remain plugin-owned for now. The current Connectors screen is AI-provider-centric, so forcing non-provider infrastructure into it would add abstraction drift rather than reduce it.

### Connector Ecosystem Growth (watch)

The April 2026 roundup and the March 25, 2026 Make/AI community testing call show the Connectors ecosystem moving beyond the three initial official providers. OpenRouter, Ollama, and Mistral are now concrete examples of third-party providers registering with the WordPress AI Client and surfacing through the same connector model.

For Flavor Agent, this is a documentation and compatibility watch item more than a code-migration requirement:

- keep connector-backed chat support framed as a real first-class runtime path
- avoid docs or UX copy that implies only OpenAI, Anthropic, and Google matter
- keep embeddings, Qdrant, and Cloudflare AI Search explicitly plugin-owned

### Client-Side Abilities API (no immediate code change)

WordPress 7.0 now ships `@wordpress/abilities` plus `@wordpress/core-abilities` for client-side ability registration, querying, and execution. Because Flavor Agent already registers its abilities in PHP with `meta.show_in_rest`, those abilities are now automatically hydrated into the admin-side `core/abilities` store when core loads `@wordpress/core-abilities`.

No code change is required for the current first-party UI. The plugin can keep using feature-specific REST endpoints and its own `@wordpress/data` store for scoped permission checks, preview/apply flow, and undo. The new client-side store is mainly an additional integration surface for future admin-only tooling or browser-agent workflows.

### Playground MCP Workflows (optional)

WordPress Playground now has an official MCP server via the `@wp-playground/mcp` package. This creates a new upstream-supported path for agent-assisted local WordPress workflows:

- file reads and writes inside a Playground site
- direct PHP execution
- browser navigation and request-driven checks

For this repo, that is an optional contributor-workflow opportunity, not a runtime migration target. It may eventually belong in local-dev or agent-runbook docs, but it should not displace the current Docker and Playwright workflows unless the team chooses to standardize on it.

### `@wordpress/build` Evaluation (tooling watch)

The April 2, 2026 `@wordpress/build` article describes the new build tool as the longer-term engine under `@wordpress/scripts`, not as an immediate replacement requirement for every plugin repo.

For Flavor Agent:

- stay on `@wordpress/scripts` for now
- watch future upstream releases for changes that alter generated asset registration or build conventions
- only plan an explicit migration if the repo's current `wp-scripts` workflow stops matching upstream expectations or if a switch would clearly simplify this codebase

### Pattern Overrides for Custom Blocks (feature)

WP 7.0 extends Pattern Overrides to any block via the `block_bindings_supported_attributes` filter. The pattern recommendation engine could leverage this to recommend patterns with override-aware metadata.

### New Block Supports for Recommendation Expansion (feature)

| Support | Details | Opportunity |
|---------|---------|-------------|
| `dimensions.width` / `dimensions.height` | New first-class block supports with `dimensionSizes` presets | Style recommendations can suggest width/height values using theme presets |
| `customCSS` | Per-block custom CSS stored at `style.css`, gated by `edit_css` capability | Style recommendations could suggest custom CSS declarations |
| `listView` | Inspector List View tab for container blocks | Now modeled as its own `list` panel in block context and prompt routing |
| `textIndent` | New typography support | Now mapped into typography recommendations |
| Pseudo-elements in theme.json | `::before`/`::after` styles in theme.json | Theme token extraction could include pseudo-element styles |

### contentOnly Editing Expansion

WP 7.0 defaults unsynced patterns and template parts to `contentOnly` mode. The plugin already respects `contentOnly` editing mode. New features to consider:
- `disableContentOnlyForUnsyncedPatterns` editor setting
- `"contentOnly": true` block support (alias for `contentRole`)
- Parent/child `contentOnly` blocks allowing child insertion

## Not Applicable

- **Interactivity API** - plugin uses React, not directives
- **DataViews/DataForm** - no migration opportunity for the Settings page itself; the separate `Settings > AI Activity` admin surface already uses DataViews/DataForm
- **PHP-only block registration** - plugin doesn't register blocks
- **Real-time collaboration** - not relevant to AI recommendations
- **Most April 2026 roundup UI bullets** - items like waveform visuals, hidden form inputs, or command-palette grouping do not currently change Flavor Agent contracts or compatibility posture
