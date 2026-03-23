# Flavor Agent Copilot Instructions

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations to Gutenberg. The plugin requires WordPress `7.0+` and PHP `8.0+`.

## Build, lint, and test commands

Install dependencies first:

```bash
npm install
composer install
```

Editor/admin build:

```bash
npm start
npm run build
```

Lint:

```bash
npm run lint:js
composer lint:php
# or:
vendor/bin/phpcs --standard=phpcs.xml.dist
```

Run all unit tests:

```bash
npm run test:unit -- --runInBand
vendor/bin/phpunit
```

Run a single JS test file:

```bash
npm run test:unit -- src/store/update-helpers.test.js --runInBand
```

Run a single PHP test file or filter:

```bash
vendor/bin/phpunit tests/phpunit/AgentControllerTest.php
vendor/bin/phpunit --filter test_method_name tests/phpunit/AgentControllerTest.php
```

`build/` and `vendor/` are gitignored. Run `npm run build` before testing changes in WordPress.

## High-level architecture

`flavor-agent.php` is the runtime bootstrap. It wires editor asset enqueueing, REST routes, the settings page, Abilities API registration, and the pattern-index lifecycle hooks. It also localizes the editor script with the `flavorAgentData` global, including the REST base URL, nonce, capability flags, and template-part area lookup.

`webpack.config.js` has two entry points: the editor bundle at `src/index.js` and the admin settings bundle at `src/admin/sync-button.js`. `src/index.js` is the client composition root: it self-registers the `flavor-agent` `@wordpress/data` store, installs the `editor.BlockEdit` filter from `src/inspector/InspectorInjector.js`, and registers the pattern recommender, inserter badge, and template recommender plugins.

Block recommendations are a cross-layer flow. `src/context/collector.js` combines block introspection, theme token summaries, sibling context, and structural context. The store thunk in `src/store/index.js` posts that snapshot to `/flavor-agent/v1/recommend-block`. `inc/REST/Agent_Controller.php` is intentionally thin and delegates to ability handlers, so the real backend behavior lives in `inc/Abilities/*`, `inc/Context/ServerCollector.php`, and the prompt/client classes under `inc/LLM/`.

Pattern recommendations are a separate pipeline. `src/patterns/PatternRecommender.js` does an initial fetch plus debounced refreshes from the inserter search box, always passing `visiblePatternNames` for the current inserter root. Server-side, pattern recommendation logic depends on `inc/Patterns/PatternIndex.php` and the Azure/Qdrant clients: patterns are embedded, searched in Qdrant, reranked with Azure Responses, then returned to the client. The client patches `__experimentalBlockPatterns` so recommended items appear in the native inserter under the `recommended` category instead of a custom UI.

Template recommendations are Site Editor-only. `src/templates/TemplateRecommender.js` activates only for `wp_template` entities and is advisory rather than auto-applying changes. Suggested template parts and patterns are converted into editor actions so the user can inspect a template part in canvas or open the inserter filtered to a pattern.

Pattern indexing is its own subsystem. `inc/Patterns/PatternIndex.php` keeps runtime state in the `flavor_agent_pattern_index_state` option, computes both overall and per-pattern fingerprints, and uses a lock plus cooldown around sync work. Syncs can be triggered by activation, theme/plugin changes, backend-setting changes, cron, or the admin sync button, so changes in indexing behavior usually have implications across bootstrap hooks, settings sanitization, and the admin UI.

## Key repository conventions

The `@wordpress/data` store name is `flavor-agent`. Block recommendation state is keyed by block `clientId`, not globally, and block fetches use monotonically increasing request tokens so stale responses do not overwrite newer ones.

Keep `inc/REST/Agent_Controller.php` thin. New recommendation or diagnostics behavior should usually be implemented in an ability class plus shared helpers, then exposed through REST as a thin adapter so REST and Abilities API behavior stay aligned.

Respect editing restrictions from `src/store/update-helpers.js`. `contentOnly` and `disabled` are enforced in the client logic, content-restricted blocks only allow content-attribute updates, and some content-only blocks that expose content only through inner blocks should receive no direct attribute updates at all.

When suggestion application changes block attributes, preserve the nested-merge behavior in `buildSafeAttributeUpdates()`. `metadata` and `style` updates are merged, not replaced wholesale.

`ServerCollector` and the client collector have different jobs. `inc/Context/ServerCollector.php` maps block supports to Inspector panels and gathers server-visible data, while `src/context/collector.js` adds editor-only structural and theme context. Changes to the recommendation payload often need coordinated edits in both layers.

Pattern recommendations patch editor settings non-destructively. `src/patterns/recommendation-utils.js` preserves original pattern `description`, `keywords`, and `categories` in a module-level map so the inserter overlay can be reverted cleanly. Do not mutate the registry payload in place without preserving rollback behavior.

`visiblePatternNames` should come from the current inserter root. That scoping is important for pattern recommendations, while the template recommender intentionally stays advisory and broader in scope.

The settings page only manages Azure OpenAI, Qdrant, Cloudflare AI Search, and pattern sync. Block text-generation providers are configured separately in core under `Settings > Connectors`.

Settings sanitization has an established pattern: Azure, Qdrant, and Cloudflare validation only runs when submitted values actually change, validation results are deduplicated within a single save request via fingerprinted static state, and failed validation keeps the previously saved value while surfacing a Settings API error.

PHP unit tests do not boot a real WordPress environment. `tests/phpunit/bootstrap.php` provides a stub harness for core functions and classes. JS unit tests live next to source as `*.test.js` files or in `__tests__/` directories.

Inspector sub-panel suggestion chips rely on the existing ToolsPanel grid layout. The CSS span behavior for those chips is intentional and easy to break if the layout is refactored casually.
