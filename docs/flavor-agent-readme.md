# Flavor Agent

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations directly to the block editor.

It currently has two primary editor experiences:

- Block recommendations in the native Inspector, powered by Anthropic.
- Pattern recommendations in the native inserter, powered by Azure OpenAI embeddings + responses and Qdrant.

There is no separate approval sidebar in the current codebase. Pattern recommendations are surfaced inside the inserter's Patterns tab through a `Recommended` category and a small toolbar badge for high-confidence matches.

## Current Architecture

```text
flavor-agent/
├── flavor-agent.php              # Bootstrap, hooks, editor asset enqueue, lifecycle wiring
├── uninstall.php                 # Removes plugin-owned options, sync state, and scheduled jobs
├── composer.json                 # PSR-4 autoload for inc/
├── package.json                  # @wordpress/scripts build, lint, unit tests
├── webpack.config.js             # Multi-entry build for editor + admin sync button
│
├── inc/
│   ├── Abilities/                # WordPress Abilities API registrations and callbacks
│   ├── AzureOpenAI/              # Embeddings, Responses API, and Qdrant REST clients
│   ├── Context/                  # Server-side block/theme/pattern collectors
│   ├── LLM/                      # Anthropic client + prompt/response handling
│   ├── Patterns/                 # Pattern index state, sync, fingerprinting, scheduling
│   ├── REST/                     # Editor-facing REST routes
│   └── Settings.php              # Settings API page + pattern sync panel
│
├── src/
│   ├── admin/                    # Settings-screen sync button script
│   ├── context/                  # Editor-side block and theme collectors
│   ├── inspector/                # InspectorControls injection and recommendation UI
│   ├── patterns/                 # Inserter recommendation patching + toolbar badge
│   └── store/                    # @wordpress/data store and safe update helpers
│
└── docs/                         # Specs, plans, and this repo guide
```

## Editor Flows

### Block Recommendations

When a block is selected, the plugin injects an `AI Recommendations` panel into the native Inspector. Clicking `Get Suggestions` sends a block context snapshot to `POST /flavor-agent/v1/recommend-block`.

The request includes:

- Block name, current attributes, styles, and supported Inspector panels.
- Theme token summaries from `theme.json` and global settings.
- Nearby sibling blocks.
- Content-only lock state and current `metadata.blockVisibility`, when present.

The response is parsed into `settings`, `styles`, and `block` suggestion groups. Applying a suggestion now uses a safe nested merge path so partial `style` and `metadata` updates do not wipe unrelated state. Loading and error state are tracked per selected block, and the backend now mirrors the same `disabled` / `contentOnly` restriction matrix that the editor enforces client-side.

### Pattern Recommendations

Pattern recommendations are exposed through `POST /flavor-agent/v1/recommend-patterns` and the `flavor-agent/recommend-patterns` ability.

The client behavior is:

- Passive fetch on editor load when Azure/Qdrant configuration is present.
- Search-triggered refresh when the inserter search box changes.
- Native pattern patching through `__experimentalBlockPatterns` so matched patterns appear in a `Recommended` category with updated descriptions and enriched keywords.
- A toolbar `!` badge when any recommendation score is `>= 0.9`.

The server behavior is:

- Check persisted pattern index runtime state.
- Embed the query with Azure OpenAI.
- Retrieve candidates from Qdrant using semantic and optional structural search passes.
- Rank candidates with the Azure OpenAI Responses API.
- Rehydrate registry-owned fields (`title`, `categories`, `content`) from stored payloads.

`visiblePatternNames` is now derived from the active inserter root, so nested insertion contexts only receive patterns WordPress already allows in that specific surface.

### Template Recommendations

Template recommendations are exposed through `POST /flavor-agent/v1/recommend-template` and the `flavor-agent/recommend-template` ability.

The client behavior is:

- Available only while editing a `wp_template` entity in the Site Editor.
- Advisory-only: template part rows link back to the relevant block or area, and pattern rows open the inserter filtered to that pattern.
- Template-global by design: the shipped panel does not send inserter-root `visiblePatternNames`, so template candidate patterns are not silently narrowed by the current insertion surface.

The server behavior is:

- Resolve the active template from the Site Editor reference.
- Collect assigned template-part slots, available template parts, and typed plus generic pattern candidates.
- Rank template composition suggestions with the Azure OpenAI Responses API.
- Validate returned template parts and pattern names against the collected context before rendering them in the panel.

## Settings

The plugin exposes a Settings API screen at `Settings > Flavor Agent`.

Configured options:

- `flavor_agent_api_key`
- `flavor_agent_model`
- `flavor_agent_azure_openai_endpoint`
- `flavor_agent_azure_openai_key`
- `flavor_agent_azure_embedding_deployment`
- `flavor_agent_azure_chat_deployment`
- `flavor_agent_qdrant_url`
- `flavor_agent_qdrant_key`

The same screen also includes a `Sync Pattern Catalog` action that calls `POST /flavor-agent/v1/sync-patterns` and reports the current pattern index status.

## Abilities Status

Implemented abilities:

- `flavor-agent/recommend-block`
- `flavor-agent/introspect-block`
- `flavor-agent/recommend-patterns`
- `flavor-agent/recommend-template`
- `flavor-agent/list-patterns`
- `flavor-agent/list-template-parts`
- `flavor-agent/get-theme-tokens`
- `flavor-agent/check-status`

Stubbed abilities returning `501`:

- `flavor-agent/recommend-navigation`

The Abilities API path is additive for WordPress versions that expose those hooks. The editor-side REST and injected UI path remains the primary runtime path.

## Pattern Index Lifecycle

Pattern indexing is managed by `FlavorAgent\Patterns\PatternIndex`.

Current lifecycle behavior:

- Activation marks the catalog dirty and schedules a sync when the vector backends are configured.
- Theme switches, plugin activation/deactivation, upgrades, and relevant settings changes mark the index dirty and schedule a background refresh.
- Deactivation clears the scheduled reindex hook and any active sync lock.
- Uninstall removes plugin-owned options, pattern index state, and the scheduled reindex hook.

## Development

Install dependencies:

```bash
composer install
npm install
```

Build and verify:

```bash
npm run build
npm run lint:js
npm run test:unit -- --runInBand
```

## Compatibility Notes

- Plugin header currently targets WordPress 6.5+ and PHP 8.0+.
- The editor-side inserter enhancement uses DOM access for the search input observer and the toolbar badge anchor. It is editor-specific code, not a DOM-free abstraction.
- The pattern UI depends on legacy `__experimentalBlockPatterns` and `__experimentalBlockPatternCategories` editor settings, isolated behind the pattern recommendation module so the integration can be updated in one place if Gutenberg changes the API surface.
