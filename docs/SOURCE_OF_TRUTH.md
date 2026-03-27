# Flavor Agent -- Source of Truth

> Last updated: 2026-03-26
> Version: 0.1.0
> Support floor: WordPress 7.0+, PHP 8.0+

## Documentation Backbone

Use these documents together:

1. `docs/README.md` -- entry point and doc ownership map
2. `docs/SOURCE_OF_TRUTH.md` -- canonical product definition and architecture
3. `STATUS.md` -- current verified state and known issues
4. `docs/FEATURE_SURFACE_MATRIX.md` -- feature locations, surfacing conditions, and apply/undo matrix
5. `docs/features/README.md` -- deep-dive user-flow documentation for each shipped surface
6. `docs/reference/abilities-and-routes.md` -- canonical ability and REST contract map
7. `docs/flavor-agent-readme.md` -- editor-flow and architecture companion
8. `docs/2026-03-25-roadmap-aligned-execution-plan.md` -- active forward plan

## What This Plugin Is

Flavor Agent is a WordPress plugin that adds AI-powered recommendations directly into the native Gutenberg editor. It should feel like Gutenberg and wp-admin became smarter, not like a separate AI application was bolted on. It does not insert or mutate content automatically without a bounded UI path -- it recommends, the user reviews where needed, and the user decides.

Applied AI changes are now tracked through the shared activity system and can be reversed from the UI when the live document still matches the recorded post-apply state. Activity persistence now uses server-backed storage, with editor-scoped hydration and `sessionStorage` retained only as a cache/fallback for the current editing surface.

The activity system now also has a first dedicated wp-admin audit page at `Settings > AI Activity`, built with WordPress-native `DataViews` and `DataForm` primitives rather than a plugin-only table shell.

Five first-party recommendation surfaces exist today:

1. **Block Inspector** -- Per-block setting and style suggestions injected into the native Inspector sidebar tabs.
2. **Pattern Inserter** -- Vector-similarity pattern recommendations surfaced through the native block inserter with a "Recommended" category.
3. **Template Compositor** -- Reviewable template-part and pattern composition suggestions for Site Editor templates with a narrow confirm-before-apply path.
4. **Template Part Recommender** -- Template-part-scoped block and pattern suggestions in the Site Editor.
5. **Navigation Inspector** -- Advisory navigation structure, overlay, and accessibility guidance for selected `core/navigation` blocks in the native Inspector.

A sixth surface -- **WordPress Abilities API** -- exposes the same capabilities as structured tool definitions for external AI agents on the supported WordPress 7.0+ floor.

## Repository Layout

```
flavor-agent/
  flavor-agent.php          Bootstrap, lifecycle hooks, REST + Abilities registration, editor/admin asset enqueue
  uninstall.php             Removes plugin-owned options, sync state, grounding caches, scheduled jobs
  composer.json             PSR-4 autoload for inc/ (FlavorAgent\)
  package.json              @wordpress/scripts build, lint, tests
  webpack.config.js         Three entry points: editor (src/index.js), admin (src/admin/sync-button.js), activity-log (src/admin/activity-log.js)
  phpcs.xml.dist            WPCS config
  phpunit.xml.dist          PHPUnit config
  playwright.wp70.config.js Docker-backed WP 7.0 Playwright config for Site Editor refresh/drift coverage
  .env.example              Local WordPress/Docker defaults
  .nvmrc                    Supported Node major (20)
  .npmrc                    engine-strict pin for Node 20 / npm 10
  .mcp.json                 Playwright MCP server config
  .devcontainer/
    devcontainer.json       VS Code devcontainer for live plugin mount
  docker/
    wordpress/Dockerfile    Local WordPress dev image with Composer + WP-CLI
  docker-compose.yml        Local WordPress + MariaDB + phpMyAdmin stack
  scripts/
    local-wordpress.ps1     Start/install/reset/wp helper for local stack
    wp70-e2e.js             Bootstrap/teardown helper for the WP 7.0 browser harness
  .github/
    copilot-instructions.md Repo guidance for GitHub Copilot
  CLAUDE.md                 Claude Code project instructions
  STATUS.md                 Feature inventory and verification log

  inc/                      PHP backend (PSR-4 namespace FlavorAgent\)
    REST/
      Agent_Controller.php  REST routes under flavor-agent/v1/
    LLM/
      WordPressAIClient.php WordPress 7.0 AI client wrapper for block recommendations using `wp_ai_client_prompt()` and Connectors-backed availability checks
      Prompt.php            Block recommendation prompt assembly and response parsing
      TemplatePrompt.php    Template recommendation prompt assembly and structured operation parsing
      NavigationPrompt.php  Navigation recommendation prompt assembly and response parsing
      TemplatePartPrompt.php  Template-part recommendation prompt assembly and response parsing
    OpenAI/
      Provider.php          Provider selection (Azure OpenAI vs OpenAI Native), connector-aware credential precedence, status metadata
    AzureOpenAI/
      ConfigurationValidator.php  Deployment and backend validation helpers
      EmbeddingClient.php   Provider-selected embeddings client (Azure OpenAI or OpenAI Native)
      QdrantClient.php      Qdrant vector DB CRUD and search
      ResponsesClient.php   Provider-selected responses client (chat/ranking)
    Cloudflare/
      AISearchClient.php    Cloudflare AI Search grounding, cache, and prewarm pipeline
    Context/
      ServerCollector.php   Server-side context: blocks, tokens, patterns, templates, navigation
    Patterns/
      PatternIndex.php      Pattern embedding lifecycle: sync, diff, cron, fingerprint
    Abilities/
      Registration.php      Abilities API category + 11 ability registrations
      BlockAbilities.php    recommend-block, introspect-block
      PatternAbilities.php  recommend-patterns, list-patterns
      TemplateAbilities.php recommend-template, recommend-template-part, list-template-parts
      NavigationAbilities.php recommend-navigation
      InfraAbilities.php    get-theme-tokens, check-status, backend/connector inventory
      WordPressDocsAbilities.php  search-wordpress-docs
    Admin/
      ActivityPage.php      Settings > AI Activity screen registration and admin asset bootstrap
    Support/
      StringArray.php       Array sanitization utility
    Settings.php            Admin settings page, remote validation, sync/diagnostics panels

  src/                      JS frontend (built with @wordpress/scripts)
    index.js                Entry: registers store, session bootstrap, inspector filter, pattern/template plugins
    editor.css              Editor-side styles for all plugin surfaces
    admin/
      sync-button.js        Settings-screen pattern sync trigger
      activity-log.js       DataViews/DataForm admin audit screen for recent AI activity
      activity-log.css      Activity page styling layered on top of wp-admin and DataViews
      activity-log-utils.js Activity-entry normalization, persisted view helpers, and ordered undo-state derivation
    components/
      AIActivitySection.js  Shared recent-actions list with per-entry undo affordance
      ActivitySessionBootstrap.js  Reloads editor-scoped activity history when the edited entity changes
    store/
      index.js              @wordpress/data store (flavor-agent): state, actions, selectors, template apply, undo, activity hydration, and history persistence
      activity-history.js   Editor-scoped AI activity schema plus storage adapter and helpers
      update-helpers.js     Safe attribute merge, content-only filtering, and undo snapshot helpers
    inspector/
      InspectorInjector.js  editor.BlockEdit HOC -- injects AI panels into all blocks
      SettingsRecommendations.js  Settings tab suggestion cards
      StylesRecommendations.js   Appearance tab + style variation pills
      SuggestionChips.js    Compact chips for sub-panel injection
      suggestion-keys.js    Stable key generation for suggestion tracking
    context/
      collector.js          Assembles full block context for LLM calls
      block-inspector.js    Recursive block capability manifest builder
      theme-settings.js     Theme-token source adapter + stable-parity diagnostics
      theme-tokens.js       Design token extraction from theme.json + global styles
    patterns/
      pattern-settings.js   Stable/experimental pattern settings + selector diagnostics
      inserter-dom.js       Fail-closed inserter search/toggle DOM discovery
      compat.js             Backward-compatible barrel for pattern settings + DOM helpers
      PatternRecommender.js Headless fetcher + native inserter patching
      InserterBadge.js      Badge portal on inserter toggle (count/loading/error)
      inserter-badge-state.js  Pure badge view-model derivation
      recommendation-utils.js  Pattern metadata patching + badge reason extraction
      find-inserter-search-input.js  Re-export of inserter-dom.findInserterSearchInput for backward compat
    templates/
      TemplateRecommender.js  Site Editor review-confirm-apply panel with linked entities
      template-recommender-helpers.js  Pure helpers for template UI and executable operation view models
    template-parts/
      TemplatePartRecommender.js  Template-part-scoped AI recommendations panel
    utils/
      structural-identity.js  Block tree structural role annotation
      template-part-areas.js  Template-part area resolution
      template-types.js     Template slug normalization
      template-operation-sequence.js  Template operation validation and normalization
      template-actions.js   Editor navigation actions plus deterministic template operation preparation/execution/undo
      pattern-names.js      Extract distinct pattern names
      visible-patterns.js   Inserter-scoped visible pattern list

  tests/
    e2e/
      flavor-agent.smoke.spec.js  Playwright smoke coverage
      auth.wp70.setup.js     Playwright login setup for the Docker-backed WP 7.0 harness
      wp70.global-setup.js   Docker bootstrap for the WP 7.0 Site Editor harness
      playground-mu-plugin/
        flavor-agent-loader.php   Loads the plugin in WP Playground
      wp70-theme/            Repo-local block theme fixture for deterministic Site Editor tests
    phpunit/
      bootstrap.php         WP function/class stubs
      AgentControllerTest.php
      AISearchClientTest.php
      AzureBackendValidationTest.php
      BlockAbilitiesTest.php
      DocsGroundingEntityCacheTest.php
      DocsPrewarmTest.php
      InfraAbilitiesTest.php
      NavigationAbilitiesTest.php
      PromptGuidanceTest.php
      PromptRulesTest.php
      RegistrationTest.php
      ServerCollectorTest.php
      SettingsTest.php
      TemplatePromptTest.php
      TemplatePartPromptTest.php
      WordPressAIClientTest.php
    (JS tests live alongside source in __tests__/ dirs and *.test.js files)

  docs/
    README.md               Doc entry point and update contract
    FEATURE_SURFACE_MATRIX.md  Feature surface and gating matrix
    features/
      README.md            Entry point for per-surface deep dives
      *.md                 Block, pattern, navigation, template, activity, settings docs
    reference/
      abilities-and-routes.md  Abilities API and REST contract map
    SOURCE_OF_TRUTH.md      This document
    flavor-agent-readme.md  Architecture and editor flow reference
    local-wordpress-ide.md  Local WordPress + devcontainer workflow
    wordpress-7.0-developer-docs-index.md  Discovered WP 7.0 docs source list
    wordpress-7.0-gutenberg-22.8-reference.md  Version reference and compatibility notes
    wp7-migration-opportunities.md  Point-in-time WP 7.0 migration assessment
    superpowers/specs/
      2026-03-17-pattern-badge-status-design.md

  build/                    Webpack output (gitignored, must run npm run build)
  vendor/                   Composer autoloader (gitignored, must run composer install)
  node_modules/             JS dependencies (gitignored)
  output/                   Playwright artifacts (gitignored)
```

## External Services

| Service | Purpose | Required For | Config Options |
|---------|---------|-------------|----------------|
| WordPress AI Client + Connectors | Block recommendation LLM | Block Inspector recommendations | Core-managed in `Settings > Connectors` |
| Provider selection | Chooses between Azure OpenAI and OpenAI Native | Pattern/template/navigation recommendations | `flavor_agent_openai_provider` (`azure_openai` or `openai_native`) |
| Azure OpenAI Embeddings | Pattern embedding (3072-dim) | Pattern index + pattern recommendations (Azure provider) | `flavor_agent_azure_openai_endpoint`, `_key`, `_embedding_deployment` |
| Azure OpenAI Responses | LLM ranking / chat | Pattern ranking, template/navigation recommendations (Azure provider) | `flavor_agent_azure_openai_endpoint`, `_key`, `_chat_deployment` |
| OpenAI Native Embeddings | Pattern embedding | Pattern index + pattern recommendations (Native provider) | Optional `flavor_agent_openai_native_api_key` override, `_embedding_model`; otherwise inherits core OpenAI connector credentials |
| OpenAI Native Chat | LLM ranking / chat | Pattern ranking, template/navigation recommendations (Native provider) | Optional `flavor_agent_openai_native_api_key` override, `_chat_model`; otherwise inherits core OpenAI connector credentials |
| Qdrant | Vector similarity search | Pattern recommendations | `flavor_agent_qdrant_url`, `_key` |
| Cloudflare AI Search | WordPress dev-doc grounding | Supplemental doc context for block/template recs | `flavor_agent_cloudflare_ai_search_account_id`, `_instance_id`, `_api_token`, `_max_results` |

The plugin works in degraded mode without any services configured. Each surface gracefully disables when its required backends are absent.

When OpenAI Native is selected, credential precedence is: plugin override -> `OPENAI_API_KEY` environment variable -> `OPENAI_API_KEY` PHP constant -> core OpenAI connector database setting. The settings UI and `flavor-agent/check-status` expose the effective source and connector registration state so the ownership boundary stays visible.

## Feature Inventory

### Implemented and Working

#### Block Inspector Recommendations
- **Trigger:** User selects a block, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Block name, attributes, styles, supports, inspector panels, editing mode, content/config attributes, child count, structural identity (role, location, position), sibling blocks, ancestor chain, theme tokens, WordPress docs guidance (cache-only).
- **LLM:** WordPress AI Client via `WordPressAIClient::chat()`.
- **Response:** Parsed into `settings`, `styles`, `block` suggestion groups. Each suggestion has label, description, panel, confidence (0-1), and `attributeUpdates`.
- **Apply:** One-click per suggestion. Safe deep-merge for `metadata` and `style` keys. Apply captures before/after attribute snapshots, shows an inline success notice with `Undo`, and writes a structured activity record.
- **Guards:** Content-only blocks receive only content-attribute suggestions. Disabled blocks receive no suggestions. `blockVisibility` (boolean and viewport-object forms) respected.

#### Pattern Recommendations
- **Trigger:** Passive fetch on editor load; active fetch on inserter search input change (400ms debounce).
- **Pipeline:** Build query text -> provider-selected embedding -> two-pass Qdrant search (semantic + structural) -> dedupe -> LLM rerank via the active responses backend -> filter scores < 0.3 -> return max 8.
- **Inserter integration:** Via `compat.js`, patches block patterns (stable `blockPatterns` key preferred, then `__experimentalAdditionalBlockPatterns`, then `__experimentalBlockPatterns` fallback) to add "Recommended" category, enriched descriptions, and extracted keywords.
- **Badge:** Inserter toggle badge shows recommendation count (ready), loading pulse, or error indicator. Toggle discovery centralized in `compat.findInserterToggle`.
- **Scoping:** `visiblePatternNames` derived from inserter root for context-appropriate results via `compat.getAllowedPatterns`.

#### Template Recommendations
- **Trigger:** User editing a `wp_template` in Site Editor, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Template ref, type, assigned template-part slots, empty areas, available (unassigned) template parts, candidate patterns (typed + generic, filtered by client-side `visiblePatternNames` when available, max 30), current inserter-root `visiblePatternNames` when available, theme tokens, WordPress docs guidance.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Max 3 suggestions, each with validated structured operations. Supported executable operations are `assign_template_part`, `replace_template_part`, and `insert_pattern`.
- **UI:** Entity mentions in text become clickable links: template-part slugs/areas highlight blocks in canvas; pattern names open the inserter pre-filtered. Suggestions can also be previewed and explicitly confirmed before apply.
- **Apply:** Deterministic client executor now validates template suggestions against a working-state copy before mutating. Template-part assignment/replacement updates existing `core/template-part` blocks, pattern insertion resolves the current insertion point and validates the pattern against root-scoped allowed patterns before inserting parsed pattern blocks, and applied operations persist stable undo locators plus recorded post-apply snapshots for inserted subtrees.
- **Guardrails:** Free-form template tree rewrites are intentionally out of scope. If any operation fails validation, the entire apply is rejected before mutation.

#### Template Part Recommendations
- **Trigger:** User editing a `wp_template_part` in Site Editor, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Template-part ref, slug, inferred area, structural summaries, candidate patterns (filtered by request `visiblePatternNames` when available), theme tokens, and cache-backed WordPress docs guidance.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Max 3 suggestions, each with `label`, `description`, `blockHints`, `patternSuggestions`, optional validated `operations`, and `explanation`. Supported executable operations are `insert_pattern`, `replace_block_with_pattern`, and `remove_block`, with bounded placement at `start`, `end`, `before_block_path`, or `after_block_path` where applicable.
- **UI:** `src/template-parts/TemplatePartRecommender.js` renders a store-backed document settings panel with advisory links, preview-confirm-apply controls for validated operations, inline success/error notices, and recent activity.
- **Apply:** Deterministic client executor validates template-part operations before mutation, inserts parsed pattern blocks at the exact resolved root/path location, supports bounded targeted replacement/removal, and records stable undo metadata plus post-apply snapshots for refresh-safe undo.
- **Guardrails:** Unsupported or ambiguous suggestions stay advisory-only. There is still no free-form rewrite path; all executable operations must resolve to explicit validated placements and block-path targets before mutation.

#### AI Activity And Undo
- **Schema:** Block, template, and template-part apply actions share one activity shape: surface, target identifiers, suggestion label, before/after state, prompt/reference metadata, timestamp, and undo status.
- **Persistence:** Activity records are persisted through the server-backed activity repository when available and are hydrated back into the editor-side storage adapter keyed by the current post, template, or template-part reference. The same repository now also supports recent unscoped/admin reads for privileged users. Template and template-part activities use schema-versioned persisted metadata; legacy clientId-only entries load as undo unavailable with a clear reason.
- **UI:** The Block Inspector, Template Compositor, and Template Part panel show inline `Undo` on the latest applied action plus a `Recent AI Actions` section with the last few records. A first dedicated admin page at `Settings > AI Activity` exposes the recent server-backed timeline across supported surfaces through a `DataViews` activity feed and a read-only `DataForm` details panel.
- **Undo rules:** Only the most recent AI action is auto-undoable. Block undo remains path-plus-attribute based. Template assignment/replacement undo resolves the current block from a stable area/slug locator, and template/template-part inserted-pattern undo only stays available while the recorded inserted subtree still exactly matches the persisted post-apply snapshot. The admin audit view applies the same ordered-tail rule when it marks older entries as blocked by newer still-applied actions.

#### Admin Activity Page
- **Location:** `Settings > AI Activity`
- **Permission:** `manage_options`
- **Bundle:** `inc/Admin/ActivityPage.php` + `src/admin/activity-log.js`
- **Behavior:** Uses `@wordpress/dataviews/wp` with the `activity` layout as the default feed, persisted/resettable view preferences, grouped summary cards, and a read-only `DataForm` details sidebar for diagnostics and links back to affected entities, plugin settings, and core Connectors.
- **Scope:** Covers recent server-backed block, template, and template-part actions; it is the first audit surface, not the final observability product.

#### Pattern Index Lifecycle
- **Sync:** Diffs current registered patterns against Qdrant index using per-pattern fingerprints. Embeds only changed patterns in batches of 100. Detects config changes for full reindex.
- **Triggers:** Plugin activation, theme switch, plugin activate/deactivate, upgrades, settings changes.
- **Scheduling:** WP cron with 300s cooldown and transient lock.
- **Admin UI:** Manual sync button on settings page with status display.

#### WordPress Abilities API (available on supported WordPress 7.0+ installs)
All abilities registered with full JSON Schema input/output definitions:

| Ability | Handler | Permission | Status |
|---------|---------|-----------|--------|
| `flavor-agent/recommend-block` | `BlockAbilities` | `edit_posts` | Working |
| `flavor-agent/introspect-block` | `BlockAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | `edit_posts` | Working |
| `flavor-agent/list-patterns` | `PatternAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/recommend-template` | `TemplateAbilities` | `edit_theme_options` | Working |
| `flavor-agent/recommend-template-part` | `TemplateAbilities` | `edit_theme_options` | Working |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | `edit_theme_options` | Working (readonly) |
| `flavor-agent/search-wordpress-docs` | `WordPressDocsAbilities` | `manage_options` | Working (readonly) |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/check-status` | `InfraAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/recommend-navigation` | `NavigationAbilities` | `edit_theme_options` | Working |

#### WordPress Docs Grounding (Cloudflare AI Search)
- Explicit search via `search-wordpress-docs` ability (`manage_options` only).
- Recommendation-time grounding is cache-only and non-blocking. Exact-query cache (6h TTL) is authoritative; warmed entity cache (12h TTL) is fallback.
- Strict source filtering: only `developer.wordpress.org` chunks accepted. URL trust validation (HTTPS, no credentials, sourceKey/URL identity checks). Source keys with an `ai-search/<instanceId>/` prefix are now recognized alongside the plain `developer.wordpress.org/` prefix.
- Docs grounding prewarm: on plugin activation and successful Cloudflare credential changes, an async WP-Cron job seeds the entity cache for 16 high-frequency entities (8 core blocks, 7 template types, core/navigation). Exact entity misses now also fall back to prewarmed generic editor/template/template-part guidance families before returning empty. Throttled by credential fingerprint and 1-hour cooldown. Admin diagnostics panel shows last prewarm status, timestamp, and warmed/failed counts.

#### REST API
| Route | Method | Permission | Handler |
|-------|--------|-----------|---------|
| `/flavor-agent/v1/recommend-block` | POST | `edit_posts` | `BlockAbilities::recommend_block` |
| `/flavor-agent/v1/recommend-patterns` | POST | `edit_posts` | `PatternAbilities::recommend_patterns` |
| `/flavor-agent/v1/recommend-navigation` | POST | `edit_theme_options` | `NavigationAbilities::recommend_navigation` |
| `/flavor-agent/v1/recommend-template` | POST | `edit_theme_options` | `TemplateAbilities::recommend_template` |
| `/flavor-agent/v1/recommend-template-part` | POST | `edit_theme_options` | `TemplateAbilities::recommend_template_part` |
| `/flavor-agent/v1/activity` | GET/POST | editor or theme capability by context; unscoped GET requires `manage_options` | `ActivityRepository` adapters via `Agent_Controller` |
| `/flavor-agent/v1/activity/{id}/undo` | POST | editor or theme capability by context | `ActivityRepository::update_undo_status` via `Agent_Controller` |
| `/flavor-agent/v1/sync-patterns` | POST | `manage_options` | `PatternIndex::sync` |

#### Admin Settings
Settings page at Settings > Flavor Agent with five sections:
- OpenAI Provider (select Azure OpenAI or OpenAI Native for pattern/template/navigation recommendations)
- Azure OpenAI (endpoint, key, embedding deployment, chat deployment)
- OpenAI Native (optional API key override, chat model, embedding model, effective key source, connector status)
- Qdrant (URL, key)
- Cloudflare AI Search (account ID, instance ID, API token, max results)

Block recommendation providers are configured separately in core under `Settings > Connectors`.
When OpenAI Native is selected, Flavor Agent still owns the chat and embedding model IDs for pattern/template/navigation work, but the API key can be inherited from the core OpenAI connector unless a plugin-specific override is saved.

Plus pattern sync status panel with manual trigger.

When the Azure OpenAI endpoint, key, embedding deployment, or chat deployment changes and all four fields are present, the settings save flow validates both the embeddings and responses deployments and preserves the previous values if validation fails.
When the Qdrant URL or key changes and both fields are present, the settings save flow validates the `/collections` endpoint and preserves the previous values if validation fails.
When the Cloudflare AI Search account ID, instance ID, or token changes and all three fields are present, the settings save flow validates the configured account, instance, and token with a lightweight probe search and preserves the previous values if validation fails. This keeps the settings flow compatible with documented AI Search Run tokens.
Unchanged or partial credential submissions skip remote validation.
Successful saves still use the standard Settings API notice flow, and failed Azure OpenAI, Qdrant, or Cloudflare validation surfaces a plugin-scoped error notice on the same screen.

### Not Yet Built (From Original Vision)

Earlier planning iterations described a broader 5-phase roadmap. Since then, the current codebase has shipped the later safety and hardening work that now exists in-tree: template review-confirm-apply, AI activity + undo, the pattern compatibility adapter, docs prewarm, and deeper Playwright smoke coverage. The larger generative/editor-transform items below still remain out of scope. Some of them, especially Interactivity scaffolding, are future-facing ideas rather than current shipping gaps because the plugin still operates entirely in editor/admin surfaces.

| Feature | Original Phase | Current Status | Notes |
|---------|---------------|----------------|-------|
| Block subtree transforms | Phase 2 | Not built | Propose replacement block trees for selected blocks |
| Pattern generation | Phase 3 | Not built | LLM generates new pattern markup from context |
| Pattern promotion | Phase 3 | Not built | Save approved AI output as plugin-managed registered patterns |
| Interactivity API scaffolding | Phase 4 | Not built | Future-facing only; the current plugin has no front-end runtime surface that requires `viewScriptModule` or Interactivity API code |
| Navigation overlay generation | Phase 4 | Not built | Create mobile nav overlays as template parts |
| Approval pipeline UI | Phase 1-3 | Not built | Visual approve/reject flow with diff preview before insertion |
| Audit/revision log UI | Phase 5 | Initial slice shipped | `Settings > AI Activity` now provides a DataViews/DataForm timeline for recent actions; diff-oriented inspection and broader observability remain open |
| Dynamic block scaffolding | Phase 4 | Not built | Generate `render_callback` + dynamic block configs |
| Pattern-to-file promotion | Phase 3 | Not built | Export approved patterns to PHP files in `patterns/` directory |

### Known Issues and Gaps

1. **`composer lint:php`**: Green across production code, but `tests/phpunit/bootstrap.php` is intentionally excluded from WPCS due to its multi-namespace stub harness.
2. **JS toolchain must stay on Node 20 / npm 10**: This repo now pins that combo because Node 24 / npm 11 on this host fails `npm ci` immediately via `engine-strict` (`EBADENGINE`).
3. **Inserter DOM discovery is still markup-coupled (mitigated)**: `inserter-dom.js` centralizes container (5), search-input (4), and toolbar toggle selectors and now fails closed to `null` when the expected editor structure is absent, so caller cleanup is isolated to one module.
4. **Pattern settings compatibility is explicit and fail-closed**: `pattern-settings.js` prefers stable `blockPatterns`/`blockPatternCategories`/`getAllowedPatterns` paths when present, then `__experimentalAdditional*` and `__experimental*` variants, and now returns an empty scoped result plus diagnostics instead of widening to an `all-patterns-fallback` result when contextual selectors are unavailable.
5. **Theme-token source resolution is now merged rather than over-promoted**: `theme-settings.js` isolates raw settings reads and now uses stable sources when available while filling only missing branches from `__experimentalFeatures`. Flavor Agent still targets WordPress 7.0+, so block attribute role detection reads only the stable `role` key and no longer preserves deprecated `__experimentalRole` compatibility.
6. **Browser coverage is split across two harnesses**: Playground remains the fast `6.9.4` smoke path, while a dedicated Docker-backed WordPress `7.0` Site Editor harness owns refresh/drift-sensitive flows. The default `npm run test:e2e` command now aggregates both harnesses and the checked-in smoke suite now covers navigation plus `wp_template_part`, but the WP 7.0 half still requires Docker on PATH. Because WordPress `7.0` is still beta as of 2026-03-26, that harness currently pins `wordpress:beta-7.0-beta4-php8.2-apache` instead of a final stable `7.0` image tag.
7. **Activity history is still only a first audit slice**: The new `Settings > AI Activity` page provides a recent DataViews/DataForm timeline for privileged users, but there is still no diff-oriented inspection UI, no abilities-backed row actions/discovery layer, and no broader observability workflow beyond the stored timeline.
8. **Live provider-backed execution still needs a fresh credentialed pass**: Checked-in unit and smoke coverage is strong, but this 2026-03-26 pass did not rerun recommendation execution against valid provider credentials.

### Current Open Backlog

- Decide whether navigation should remain advisory-only or grow a bounded apply contract, while keeping the UX native to the Inspector and Site Editor.
- Deepen the new admin activity page into a richer audit/observability surface with before/after inspection, better diagnostics, and a cleaner action/discovery layer.
- Rerun live provider-backed recommendation execution with valid credentials to refresh end-to-end verification on the active provider path.
- Swap the Docker-backed WP 7.0 browser harness from the beta image to the official stable `7.0` image once it exists, and keep Docker available in environments that run that harness.
- Keep Interactivity API work in the future backlog, not the current remediation backlog, until the plugin grows a front-end runtime surface.

## Data Flow Diagrams

### Block Recommendation Flow
```
User selects block -> InspectorInjector renders AI panel
  -> User clicks "Get Suggestions"
  -> collector.js: collectBlockContext(clientId)
     -> block-inspector.js: introspectBlockInstance + introspectBlockType
     -> theme-tokens.js: collectThemeTokens + summarizeTokens
     -> structural-identity.js: buildStructuralContext
  -> store thunk: fetchBlockRecommendations(clientId, context, prompt)
     -> POST /flavor-agent/v1/recommend-block
        -> Agent_Controller -> BlockAbilities::recommend_block()
           -> ServerCollector::introspect_block_type() (server enrichment)
           -> AISearchClient::maybe_search_with_cache_fallbacks() (docs grounding)
           -> Prompt::build_system() + Prompt::build_user()
           -> WordPressAIClient::chat() (core AI client)
           -> Prompt::parse_response()
           -> Prompt::enforce_block_context_rules()
        <- JSON response: { settings, styles, block, explanation }
  -> store: SET_BLOCK_RECOMMENDATIONS
  -> UI: SettingsRecommendations, StylesRecommendations, SuggestionChips
  -> User clicks "Apply" on a suggestion
     -> store thunk: applySuggestion(clientId, suggestion)
        -> update-helpers.js: buildSafeAttributeUpdates()
        -> core/block-editor.updateBlockAttributes()
```

### Pattern Recommendation Flow
```
Editor loads (or inserter search changes)
  -> PatternRecommender.js detects trigger
  -> store thunk: fetchPatternRecommendations(input)
     -> POST /flavor-agent/v1/recommend-patterns
        -> Agent_Controller -> PatternAbilities::recommend_patterns()
           -> PatternIndex: check state (ready/stale/error)
           -> EmbeddingClient::embed(query)
           -> QdrantClient::search() x2 (semantic + structural)
           -> Dedupe, take top 12 candidates
           -> ResponsesClient::rank(instructions, candidates)
           -> Parse ranking, filter < 0.3, rehydrate from payloads
        <- JSON response: [{ name, score, reason, ... }]
  -> store: SET_PATTERN_RECS + setPatternStatus('ready')
  -> recommendation-utils.js: patchPatternMetadata()
     -> compat.js: setBlockPatterns() (stable key preferred, experimental fallback)
     -> Adds "Recommended" category, enriched descriptions/keywords
  -> InserterBadge renders count/loading/error via portal
```

### Template Recommendation Flow
```
User editing wp_template in Site Editor
  -> TemplateRecommender.js renders PluginDocumentSettingPanel
  -> User clicks "Get Suggestions"
   -> store thunk: fetchTemplateRecommendations(input)
      -> POST /flavor-agent/v1/recommend-template
         -> Agent_Controller -> TemplateAbilities::recommend_template()
            -> ServerCollector::for_template(ref, type, visiblePatternNames)
               -> Walks parsed blocks for template-part slots
               -> Collects available parts, empty areas, candidate patterns (filtered by visiblePatternNames)
           -> AISearchClient::maybe_search_with_cache_fallbacks()
           -> TemplatePrompt::build_system() + build_user()
           -> ResponsesClient::rank(instructions, input)
           -> TemplatePrompt::parse_response() (validates against context, normalizes executable operations)
        <- JSON response: { suggestions, explanation }
  -> store: SET_TEMPLATE_RECS
  -> UI: TemplateSuggestionCards with LinkedText entity links + preview state
     -> Template-part links -> selectBlockBySlugOrArea()
     -> Pattern links -> openInserterForPattern()
  -> User clicks "Preview Apply" and then "Confirm Apply"
     -> store thunk: applyTemplateSuggestion(suggestion)
        -> template-actions.js: prepareTemplateSuggestionOperations()
           -> validate template-part slugs, areas, current assignments, pattern names, insertion point
        -> template-actions.js: applyTemplateSuggestionOperations()
           -> core/block-editor.updateBlockAttributes() for template-part changes
           -> core/block-editor.insertBlocks() for pattern insertion
        -> store: LOG_ACTIVITY + SET_TEMPLATE_APPLY_STATE('success')
```

### Navigation Recommendation Flow
```
User selects core/navigation block
  -> NavigationRecommendations.js renders inline Inspector UI
  -> User clicks "Get Navigation Suggestions"
  -> store thunk: fetchNavigationRecommendations(input)
     -> POST /flavor-agent/v1/recommend-navigation
        -> Agent_Controller -> NavigationAbilities::recommend_navigation(input)
     -> ServerCollector::for_navigation(menuId, markup)
        -> get_post(menuId) for wp_navigation content
        -> parse_blocks() to extract menu item tree
        -> Extract navigation block attributes
        -> for_template_parts('navigation-overlay') for WP 7.0 overlay parts
        -> infer_navigation_location() from template part refs
        -> for_tokens() for theme design tokens
     -> AISearchClient::maybe_search_with_cache_fallbacks()
     -> NavigationPrompt::build_system() + build_user()
     -> ResponsesClient::rank(instructions, input)
     -> NavigationPrompt::parse_response() (validates categories, change types)
     <- JSON response: { suggestions, explanation }
  -> UI renders advisory-only structure, overlay, and accessibility guidance
```

### Template Part Recommendation Flow
```
User editing wp_template_part in Site Editor
  -> TemplatePartRecommender.js renders PluginDocumentSettingPanel
  -> User clicks "Get Suggestions"
  -> store thunk fetchTemplatePartRecommendations(input)
     -> POST /flavor-agent/v1/recommend-template-part
        -> Agent_Controller -> TemplateAbilities::recommend_template_part()
           -> ServerCollector::for_template_part(ref, visiblePatternNames)
           -> AISearchClient::maybe_search_with_cache_fallbacks()
           -> TemplatePartPrompt::build_system() + build_user()
           -> ResponsesClient::rank(instructions, input) via active provider
           -> TemplatePartPrompt::parse_response() (validates block hints, pattern names, placements)
        <- JSON response: { suggestions, explanation }
  -> store saves request/result state for current templatePartRef
  -> UI renders advisory links plus preview-confirm-apply for validated start/end insert_pattern operations
  -> applyTemplatePartSuggestion()
     -> validateTemplatePartOperationSequence()
     -> applyTemplatePartSuggestionOperations()
     -> write an editor-scoped activity entry, persist it through the activity repository, and keep refresh-safe undo metadata
```

## Test Coverage

### PHP (PHPUnit)
| Test File | Tests | What's Covered |
|-----------|-------|---------------|
| `AgentControllerTest` | 6 | REST recommend-block/template wrapping, template-part visiblePatternNames forwarding, and string array validation |
| `ServerCollectorTest` | 6 | Template parts metadata, area lookup, content-role introspection, template candidate ordering, visible-pattern filtering, duotone token summaries |
| `InfraAbilitiesTest` | 6 | check-status: Cloudflare backend, admin filtering, model fallback, provider inventory |
| `RegistrationTest` | 4 | Ability schema structure, entityKey schema, visiblePatternNames schema, recommend-template-part schema |
| `DocsGroundingEntityCacheTest` | 7 | Two-tier cache: query vs entity, seeding, inference, instance-prefixed source keys |
| `AISearchClientTest` | 24 | Search flow, config, cache, source filtering, URL trust, entity keys, instance-prefixed source keys, probe-only validation, trusted-docs compatibility |
| `PromptRulesTest` | 3 | Content-only rules, disabled blocks, container behavior |
| `BlockAbilitiesTest` | 3 | Input normalization, XSS sanitization, disabled block short-circuit |
| `PromptGuidanceTest` | 8 | Guidance sections, structural identity, content-only restrictions, filter panel coverage, duotone summaries, aspect-ratio rules |
| `SettingsTest` | 19 | Changed-vs-unchanged Azure/Qdrant/Cloudflare save validation, rollback, partial credentials, probe-only Cloudflare validation, and settings notice rendering |
| `AzureBackendValidationTest` | 16 | Azure embeddings/responses validation, remote error propagation, payload-shape checks, Qdrant response-shape checks, OpenAI Native validation |
| `NavigationAbilitiesTest` | 13 | Input validation, prompt assembly, docs guidance, response parsing limits, and system prompt rules |
| `TemplatePromptTest` | 7 | Structured template operation parsing, conflicting multi-step validation, visible-pattern context, and legacy fallback normalization |
| `TemplatePartPromptTest` | 3 | Template-part prompt assembly, executable-operation parsing, and advisory fallback validation |
| `DocsPrewarmTest` | 19 | Warm set definition, cache seeding, state recording, throttling (same creds, changed creds, expired window), partial/total failure, schedule/should prewarm, diagnostics, resilience |
| `WordPressAIClientTest` | 3 | Function-based prompt creation, system instruction application, missing-provider error |
| **Total** | **149** | |

### JS (Jest)
| Test File | What's Covered |
|-----------|---------------|
| `store/__tests__/activity-history.test.js` | Activity scope resolution, session storage persistence, legacy template-entry downgrade, latest-applied/undoable stack rules |
| `store/__tests__/activity-history-state.test.js` | Reducer hydration of persisted activity state, legacy non-undoable template entries, and undo side effects |
| `store/__tests__/block-request-state.test.js` | Per-block request state, stale token rejection |
| `store/__tests__/pattern-status.test.js` | Pattern status/error transitions, badge recalculation |
| `store/__tests__/store-actions.test.js` | Block/template/template-part apply logging, client-side validation failures, session persistence, and undo thunk behavior |
| `store/__tests__/template-apply-state.test.js` | Template preview/apply reducer state transitions |
| `store/update-helpers.test.js` | Safe merge, content-only filtering, editing restrictions, and undo snapshot comparison |
| `patterns/__tests__/inserter-badge-state.test.js` | Badge view-model derivation (all 4 states) |
| `patterns/__tests__/recommendation-utils.test.js` | Metadata patching, badge reason extraction |
| `patterns/__tests__/find-inserter-search-input.test.js` | DOM search strategy (delegates to compat) |
| `patterns/__tests__/compat.test.js` | Stable/experimental/additional API negotiation, DOM selector strategies, fallback behavior |
| `context/__tests__/theme-tokens.test.js` | Theme token extraction and summarization |
| `templates/__tests__/template-recommender-helpers.test.js` | Entity map, suggestion view models, context signature, format helpers |
| `inspector/suggestion-keys.test.js` | Key generation |
| `utils/__tests__/structural-identity.test.js` | Role annotation, location resolution, position tracking |
| `utils/__tests__/template-actions.test.js` | Template and template-part operation preparation, placement validation, refresh-safe undo resolution, inserted-pattern drift detection, and client-side conflict validation |
| `utils/__tests__/template-part-areas.test.js` | Area resolution priority chain |
| `utils/__tests__/template-types.test.js` | Slug normalization |
| `utils/__tests__/visible-patterns.test.js` | Inserter-scoped pattern list, injected block-editor selector, null-root document scope |

## Definition of "Complete" (v1.0)

Based on the original vision and current trajectory, Flavor Agent v1.0 should satisfy:

### Must Have (v1.0)

- [x] Block Inspector recommendations with per-block loading/error state
- [x] Content-only and disabled block guards
- [x] Pattern recommendations via vector search + LLM ranking
- [x] Native inserter integration (Recommended category, badge)
- [x] Template composition panel with review-confirm-apply for validated operations
- [x] Template-part recommendations panel
- [x] Undoable block/template/template-part AI actions with server-backed activity persistence plus editor-scoped hydration/cache fallback
- [x] Pattern index lifecycle (auto-sync, background cron, diff-based updates)
- [x] WordPress Abilities API integration (all working abilities)
- [x] WordPress docs grounding (cache-based)
- [x] Admin settings page with backend configuration
- [x] Cloudflare AI Search credential validation on changed settings saves
- [x] Settings page success/error feedback for credential validation
- [x] Clean uninstall
- [x] Live credential validation on Azure OpenAI/Qdrant settings save
- [x] Navigation recommendations (replace 501 stub)
- [x] Integration tests for block, pattern, template, and WP 7.0 refresh/drift coverage
- [x] Playwright smoke for navigation and `wp_template_part`, plus a default `npm run test:e2e` path that covers both harnesses

### Should Have (v1.x)

- [ ] Block subtree transform: propose replacement trees for selected block groups
- [x] Inserter search input detection resilience (abstract away DOM selectors)
- [x] Pattern API migration plan (move off `__experimentalBlockPatterns` when stable API lands)
- [x] Warm docs cache on plugin activation for common block types
- [x] Suggestion undo (restore previous attribute values)
- [ ] Rate limiting / request throttling for LLM calls

### Could Have (v2.0+)

- [ ] Pattern generation: LLM creates new pattern markup from context
- [ ] Pattern promotion: save approved AI output as registered patterns
- [ ] Interactivity API scaffolding: generate viewScriptModule code
- [ ] Dynamic block scaffolding: generate render_callback configurations
- [ ] Deeper audit and observability UI: diff-oriented inspection, richer row actions, and broader operator workflows
- [ ] Navigation overlay generation
- [ ] Multi-turn conversation (context carryover across recommendation rounds)
- [ ] Batch recommendations (multiple blocks at once)

## Doc Index

| Document | Purpose | Status |
|----------|---------|--------|
| `docs/README.md` | Documentation backbone, reading order, and update contract | **Current** |
| `docs/SOURCE_OF_TRUTH.md` | Definitive project reference: scope, architecture, inventory, roadmap | **Current** |
| `docs/FEATURE_SURFACE_MATRIX.md` | Fast map of every shipped surface, gating condition, and apply/undo path | **Current** |
| `docs/features/README.md` | Entry point for detailed feature docs covering end-to-end flows | **Current** |
| `docs/reference/abilities-and-routes.md` | Canonical ability and REST contract reference | **Current** |
| `docs/flavor-agent-readme.md` | Architecture details: editor flows, settings, pattern lifecycle | **Current** |
| `docs/local-wordpress-ide.md` | Local Docker/devcontainer workflow and host setup | **Current** |
| `docs/2026-03-25-roadmap-aligned-execution-plan.md` | Active forward plan aligned to the current WordPress 7.0 and Gutenberg roadmap context | **Current** |
| `docs/wordpress-7.0-gutenberg-22.8-reference.md` | WP 7.0 and Gutenberg 22.8 API changes, new features, deprecations, and plugin impact | **Reference snapshot** |
| `docs/wordpress-7.0-developer-docs-index.md` | Discovery snapshot of official WordPress 7.0 developer documentation sources | **Reference snapshot** |
| `docs/wp7-migration-opportunities.md` | Point-in-time WordPress 7.0 migration assessment and follow-up opportunities | **Reference snapshot** |
| `docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md` | Implemented design spec for pattern badge status surface | **Implemented reference spec** |
| `CLAUDE.md` | Claude Code project instructions: commands, architecture, gotchas | **Current** |
| `STATUS.md` | Working feature inventory and verification log | **Current** |

## Build and Dev Commands

```bash
# JS
source ~/.nvm/nvm.sh && nvm use 20   # Supported JS toolchain
npm ci                               # Install JS deps reproducibly
npm run build                        # Production build -> build/index.js, build/admin.js
npm start                            # Dev build with watch
npm run lint:js                      # ESLint on src/
npm run test:unit -- --runInBand     # Jest unit tests
npm run test:e2e                     # Default Playwright smoke coverage (currently Playground-only)
npm run test:e2e:playground          # Fast 6.9.4 Playground smoke harness
npm run test:e2e:wp70                # Docker-backed WP 7.0 Site Editor harness
npm run wp:start                     # Local Docker stack up
npm run wp:stop                      # Local Docker stack down
npm run wp:reset                     # Local Docker stack reset
npm run wp:e2e:wp70:bootstrap        # Provision the WP 7.0 browser harness without running tests
npm run wp:e2e:wp70:teardown         # Stop and remove the WP 7.0 browser harness

# PHP
composer install                     # PSR-4 autoloader
composer lint:php                    # WPCS lint alias
composer test:php                    # PHPUnit alias
vendor/bin/phpunit                   # PHPUnit tests (direct)
vendor/bin/phpcs --standard=phpcs.xml.dist inc/ flavor-agent.php  # WPCS lint (direct)
```

## Key Technical Decisions

1. **Split recommendation backends**: WordPress AI Client for block recommendations (provider-agnostic, connector-managed), plus provider-selected embeddings/responses via `Provider` for pattern/template/navigation ranking. Not a redundancy -- different strengths for different tasks.
2. **Narrow approval model**: Block suggestions still apply inline on click, but template suggestions use an explicit preview-confirm-apply step. There is no separate multi-stage approval workspace or diff-review pipeline.
3. **Inspector injection over sidebar**: Recommendations appear in the native Inspector tabs (Settings, Styles, sub-panels) rather than a separate sidebar. This feels native, not bolted-on.
4. **Vector search for patterns**: Patterns are embedded and stored in Qdrant rather than passed to the LLM as raw text. This scales to hundreds of patterns without hitting token limits.
5. **Cache-only docs grounding**: WordPress docs are not fetched on every recommendation request. Cache is warmed via explicit `search-wordpress-docs` calls, async prewarm jobs, prior queries, or first-request misses that queue follow-up warming. This avoids latency on the critical path.
6. **Abilities API is additive**: The REST API remains the primary runtime path. Abilities API registration is a parallel exposure for external agents. Neither depends on the other.
7. **Store is the contract boundary for executable surfaces**: Block, pattern, template, and template-part UI read through `@wordpress/data` selectors and store thunks handle REST calls, error state, stale-request rejection, and activity/undo coordination.
