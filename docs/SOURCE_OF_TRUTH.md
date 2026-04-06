# Flavor Agent -- Source of Truth

> Last updated: 2026-04-06
> Version: 0.1.0
> Support floor: WordPress 7.0+, PHP 8.0+

## Documentation Backbone

Use these documents together:

1. `docs/README.md` -- entry point and doc ownership map
2. `docs/SOURCE_OF_TRUTH.md` -- canonical product definition and architecture
3. `STATUS.md` -- current verified state and known issues
4. `docs/FEATURE_SURFACE_MATRIX.md` -- feature locations, surfacing conditions, and apply/undo matrix
5. `docs/features/README.md` -- deep-dive user-flow documentation for each shipped surface
6. `docs/reference/` -- canonical programmatic contract docs (abilities-and-routes, shared-internals, provider-precedence, template-operations, activity-state-machine)
7. `docs/flavor-agent-readme.md` -- editor-flow and architecture companion
8. `docs/2026-03-25-roadmap-aligned-execution-plan.md` -- active forward plan

Supplemental dated planning docs such as `docs/2026-04-03-wordpress-direction-review.md` and `docs/2026-04-03-three-phase-roadmap.md` are historical context, not the active plan of record.

## What This Plugin Is

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations and editorial scaffolding across the native Gutenberg editor and relevant wp-admin screens. It should feel like Gutenberg and wp-admin became smarter, not like a separate AI application was bolted on. It does not insert or mutate content automatically without a bounded UI path -- it recommends, the user reviews where needed, and the user decides.

Applied AI changes are now tracked through the shared activity system and can be reversed from the UI when the live document still matches the recorded post-apply state. Activity persistence now uses server-backed storage, with editor-scoped hydration and `sessionStorage` retained only as a cache/fallback for the current editing surface.

The activity system now also has a first dedicated wp-admin audit page at `Settings > AI Activity`, built with WordPress-native `DataViews` and `DataForm` primitives rather than a plugin-only table shell.

When a recommendation surface is in scope but unavailable, the native UI now stays visible long enough to explain whether the missing dependency belongs in core `Settings > Connectors` or plugin-owned `Settings > Flavor Agent`, including the inserter-backed pattern surface.

Seven first-party recommendation surfaces exist today:

1. **Block Inspector** -- Per-block setting and style suggestions injected into the native Inspector sidebar tabs.
2. **Pattern Inserter** -- Vector-similarity pattern recommendations surfaced through the native block inserter with a "Recommended" category.
3. **Template Compositor** -- Reviewable template-part and pattern composition suggestions for Site Editor templates with a narrow confirm-before-apply path.
4. **Template Part Recommender** -- Template-part-scoped block and pattern suggestions in the Site Editor.
5. **Global Styles Recommender** -- Site Editor Global Styles suggestions bounded to native theme-supported style paths and registered style variations.
6. **Style Book Recommender** -- Per-block style suggestions in the Style Book panel, bounded to block-scoped style paths and theme-backed values.
7. **Navigation Inspector** -- Advisory navigation structure, overlay, and accessibility guidance for selected `core/navigation` blocks in the native Inspector.

The plugin also ships one first-party admin audit surface at `Settings > AI Activity` and one programmatic content lane exposed through REST + Abilities, but not yet mounted in a first-party Gutenberg panel.

A parallel programmatic surface -- **WordPress Abilities API** -- exposes the shipped recommendation, helper, and diagnostic contracts as structured tool definitions for external AI agents on the supported WordPress 7.0+ floor.

## Repository Layout

Representative live-tree snapshot of the current repo (selected files only; generated assets, installed dependencies, and many test helpers are omitted for brevity):

```
flavor-agent/
  flavor-agent.php          Bootstrap, lifecycle hooks, REST + Abilities registration, editor/admin asset enqueue
  readme.txt                Plugin readme and install/configuration summary
  uninstall.php             Removes plugin-owned options, sync state, grounding caches, and scheduled jobs
  composer.json             PSR-4 autoload + PHP lint/test aliases
  package.json              @wordpress/scripts build/lint/test scripts plus dist/doc checks
  jsconfig.json             JS tooling config
  webpack.config.js         Three entry points: editor, settings admin, activity log admin
  playwright.config.js      Playground Playwright harness
  playwright.wp70.config.js Docker-backed WP 7.0 Playwright harness
  phpcs.xml.dist            WPCS config
  phpunit.xml.dist          PHPUnit config
  .env.example              Local WordPress/Docker defaults
  .nvmrc                    Default Node major (24, latest LTS)
  .npmrc                    engine-strict gate for supported Node/npm majors
  .mcp.json                 Playwright MCP server config
  .devcontainer/
    devcontainer.json       VS Code devcontainer for live plugin mount
  docker/
    wordpress/Dockerfile    Local WordPress dev image with Composer + WP-CLI
  docker-compose.yml        Local WordPress + MariaDB + phpMyAdmin stack
  scripts/
    build-dist.sh           Build `dist/flavor-agent.zip`
    check-doc-freshness.sh  Guard against known stale live-doc wording
    local-wordpress.ps1     Start/install/reset/wp helper for local stack
    plugin-check.sh         Wrapper around the plugin validation flow
    prepare-release.sh      Build temp release tree with `.distignore`
    wp70-e2e.js             Bootstrap/teardown helper for the WP 7.0 browser harness
  .github/
    copilot-instructions.md Repo guidance for GitHub Copilot
  CLAUDE.md                 Claude Code project instructions
  STATUS.md                 Feature inventory and verification log

  inc/                      PHP backend (PSR-4 namespace FlavorAgent\)
    Activity/
      Permissions.php       Contextual capability checks for activity reads/writes/undo
      Repository.php        Server-backed activity persistence, query, and pruning
      Serializer.php        Activity normalization for REST/admin consumers
    Admin/
      ActivityPage.php      Settings > AI Activity screen registration and admin asset bootstrap
    Abilities/
      Registration.php      Abilities API category + 13 ability registrations
      BlockAbilities.php    recommend-block, introspect-block
      ContentAbilities.php  recommend-content
      PatternAbilities.php  recommend-patterns, list-patterns
      TemplateAbilities.php recommend-template, recommend-template-part, list-template-parts
      StyleAbilities.php    recommend-style
      NavigationAbilities.php recommend-navigation
      InfraAbilities.php    get-theme-tokens, check-status, backend inventory
      SurfaceCapabilities.php Shared localized + check-status surface readiness contract
      WordPressDocsAbilities.php search-wordpress-docs
    AzureOpenAI/
      BaseHttpClient.php    Shared HTTP client/retry plumbing
      ConfigurationValidator.php Deployment and backend validation helpers
      EmbeddingClient.php   Provider-selected embeddings client
      EmbeddingSignature.php Stable embedding fingerprint helpers
      QdrantClient.php      Qdrant vector DB CRUD and search
      ResponsesClient.php   Provider-selected responses/chat client
    Cloudflare/
      AISearchClient.php    Cloudflare AI Search grounding, cache, and prewarm pipeline
    Context/
      ServerCollector.php               Facade for per-surface context collectors
      BlockContextCollector.php         Block-level context assembly
      BlockTypeIntrospector.php         Block supports, attributes, and inspector panel introspection
      NavigationContextCollector.php    Navigation recommendation context
      NavigationParser.php              Navigation block markup parsing
      PatternCandidateSelector.php      Candidate pattern selection and filtering
      PatternCatalog.php                Pattern registry metadata extraction
      PatternOverrideAnalyzer.php       Pattern override binding detection and summary
      TemplateContextCollector.php      Template-level recommendation context
      TemplatePartContextCollector.php  Template-part-scoped recommendation context
      TemplateRepository.php            Template and template-part lookup helpers
      TemplateStructureAnalyzer.php     Template block-tree structural analysis
      TemplateTypeResolver.php          Template type normalization
      ThemeTokenCollector.php           Theme token extraction and diagnostics
      ViewportVisibilityAnalyzer.php    `blockVisibility` analysis
    LLM/
      ChatClient.php        Shared chat entry point with provider selection + fallback
      NavigationPrompt.php  Navigation recommendation prompt assembly and parsing
      Prompt.php            Block recommendation prompt assembly and parsing
      StylePrompt.php       Global Styles / Style Book prompt assembly and parsing
      TemplatePartPrompt.php Template-part recommendation prompt assembly and parsing
      TemplatePrompt.php    Template recommendation prompt assembly and parsing
      WordPressAIClient.php WordPress AI Client wrapper for connector-backed execution
      WritingPrompt.php     Programmatic content-lane prompt assembly and parsing
    OpenAI/
      Provider.php          Provider selection, connector-aware precedence, runtime metadata
    Patterns/
      PatternIndex.php      Pattern embedding lifecycle: sync, diff, cron, fingerprint
    REST/
      Agent_Controller.php  REST routes under `flavor-agent/v1/`
    Support/
      CollectsDocsGuidance.php Docs-guidance helper trait
      FormatsDocsGuidance.php Docs-guidance formatting helpers
      NormalizesInput.php   Structured input normalization helpers
      StringArray.php       Array sanitization utility
    Settings.php            Admin settings page, remote validation, sync/diagnostics panels

  src/                      JS frontend (built with @wordpress/scripts)
    index.js                Entry: registers store, session bootstrap, and editor-side plugins
    tokens.css              Shared design tokens for plugin UI
    editor.css              Editor-side styles for shipped surfaces
    admin/
      sync-button.js        Settings-screen pattern sync trigger
      activity-log.js       DataViews/DataForm admin audit screen
      activity-log.css      Activity page styling layered on top of wp-admin and DataViews
      activity-log-utils.js Activity-entry normalization and admin view helpers
      brand.css             Shared admin brand layer
      dataviews-runtime.css DataViews compatibility styling
      settings.css          Settings-screen styling
      wpds-runtime.css      WPDS runtime compatibility styling
    components/
      AIActivitySection.js  Shared recent-actions list with per-entry undo affordance
      AIAdvisorySection.js  Shared advisory suggestion list
      AIReviewSection.js    Shared review-confirm-apply framing
      AIStatusNotice.js     Shared loading/error/success status notice
      ActivitySessionBootstrap.js Entity-change-driven activity hydration
      CapabilityNotice.js   Shared unavailable/setup messaging
      InlineActionFeedback.js Inline apply/undo feedback shell
      RecommendationHero.js Shared featured recommendation presentation
      RecommendationLane.js Shared grouped recommendation lane
      SurfaceComposer.js    Shared surface layout composer
      SurfacePanelIntro.js  Shared panel intro copy block
      SurfaceScopeBar.js    Shared scope/freshness strip
    context/
      collector.js          Assembles full block context for block recommendations
      block-inspector.js    Recursive block capability manifest builder
      theme-settings.js     Theme-token source adapter + stable-parity diagnostics
      theme-tokens.js       Design token extraction from `theme.json` + Global Styles
    global-styles/
      GlobalStylesRecommender.js Site Editor Global Styles panel
    inspector/
      InspectorInjector.js  `editor.BlockEdit` HOC for AI panel injection
      BlockRecommendationsPanel.js Block-level prompt and suggestion panel
      NavigationRecommendations.js Navigation advisory panel for `core/navigation`
      SettingsRecommendations.js Settings tab suggestion cards
      StylesRecommendations.js Appearance tab + style variation pills
      SuggestionChips.js    Compact chips for sub-panel injection
      group-by-panel.js     Panel-grouping helpers
      panel-delegation.js   Inspector panel routing for block vs navigation surfaces
      suggestion-keys.js    Stable key generation for suggestion tracking
      use-suggestion-apply-feedback.js Shared apply-feedback hook
    patterns/
      pattern-settings.js   Stable/experimental pattern settings + selector diagnostics
      inserter-dom.js       Fail-closed inserter DOM discovery
      compat.js             Compatibility barrel for pattern settings + DOM helpers
      PatternRecommender.js Headless fetcher + native inserter patching
      InserterBadge.js      Badge portal on inserter toggle
      inserter-badge-state.js Pure badge view-model derivation
      recommendation-utils.js Pattern metadata patching + badge reason extraction
      find-inserter-search-input.js Backward-compatible re-export
    review/
      notes-adapter.js      Shape-only adapter for future Notes/comment projection
    store/
      index.js              `@wordpress/data` store for request state, apply/undo, and activity hydration
      activity-history.js   Editor-scoped activity schema plus storage adapter/helpers
      block-targeting.js    Live block resolution for activity entries
      update-helpers.js     Safe attribute merge, content-only filtering, undo snapshot helpers
    style-book/
      StyleBookRecommender.js Style Book per-block style AI panel
      dom.js                Style Book panel DOM discovery and portal target resolution
    template-parts/
      TemplatePartRecommender.js Template-part-scoped AI recommendations panel
    templates/
      TemplateRecommender.js Site Editor review-confirm-apply panel with linked entities
      template-recommender-helpers.js Template UI and executable-operation helpers
    utils/
      capability-flags.js   Surface capability flag derivation from localized data
      editor-context-metadata.js Shared editor metadata summaries
      editor-entity-contracts.js Shared entity/view contract normalization
      format-count.js       Shared count/string formatting helpers
      structural-identity.js Block tree structural role annotation
      style-design-semantics.js Style-oriented design summary helpers
      style-operations.js   Deterministic Global Styles / Style Book apply and undo helpers
      template-actions.js   Template and template-part preparation/execution/undo helpers
      template-operation-sequence.js Template operation validation and normalization
      template-part-areas.js Template-part area resolution
      template-types.js     Template slug normalization
      visible-patterns.js   Inserter-scoped visible pattern list

  tests/
    e2e/
      flavor-agent.smoke.spec.js  Shared Playwright smoke coverage
      auth.wp70.setup.js          Playwright login setup for the Docker-backed WP 7.0 harness
      wp70.global-setup.js        Docker bootstrap for the WP 7.0 Site Editor harness
      playground-mu-plugin/
        flavor-agent-loader.php   Loads the plugin in WP Playground
      wp70-theme/                 Repo-local block theme fixture for deterministic Site Editor tests
    phpunit/
      AgentControllerTest.php
      ActivityPermissionsTest.php
      ActivityRepositoryTest.php
      AISearchClientTest.php
      AzureBackendValidationTest.php
      BlockAbilitiesTest.php
      ChatClientTest.php
      ContentAbilitiesTest.php
      DocsGroundingEntityCacheTest.php
      DocsPrewarmTest.php
      EditorSurfaceCapabilitiesTest.php
      InfraAbilitiesTest.php
      NavigationAbilitiesTest.php
      PatternAbilitiesTest.php
      PatternCatalogTest.php
      PatternIndexTest.php
      ProviderTest.php
      RegistrationTest.php
      ServerCollectorTest.php
      SettingsTest.php
      StyleAbilitiesTest.php
      StylePromptTest.php
      TemplatePartPromptTest.php
      TemplatePromptTest.php
      WordPressAIClientTest.php
      WritingPromptTest.php
    src/**/__tests__/ and `*.test.js`
      Current JS unit coverage for store state, shared components, inspector flows, pattern compat, template/style surfaces, and utilities

  docs/
    README.md               Doc entry point and update contract
    SOURCE_OF_TRUTH.md      This document
    FEATURE_SURFACE_MATRIX.md Feature surface and gating matrix
    flavor-agent-readme.md  Architecture and editor flow reference
    local-wordpress-ide.md  Local WordPress + devcontainer workflow
    2026-03-25-roadmap-aligned-execution-plan.md Active forward plan
    2026-04-03-wordpress-direction-review.md Supplemental dated direction review
    2026-04-03-three-phase-roadmap.md Supplemental dated roadmap translation
    features/
      README.md             Entry point for per-surface deep dives
      activity-and-audit.md
      block-recommendations.md
      content-recommendations.md
      navigation-recommendations.md
      pattern-recommendations.md
      settings-backends-and-sync.md
      style-and-theme-intelligence.md
      template-part-recommendations.md
      template-recommendations.md
    reference/
      abilities-and-routes.md Contract map for Abilities API + REST
      activity-state-machine.md Undo states, transitions, ordered undo, pruning
      provider-precedence.md Provider selection and credential fallback chain
      shared-internals.md   Shared store/components/context helpers
      template-operations.md Operation vocabulary and validation rules per surface
```

## External Services

| Service                          | Purpose                                                   | Required For                                                                                                    | Config Options                                                                                                                   |
| -------------------------------- | --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| WordPress AI Client + Connectors | Generic text-generation fallback and connector registry   | Block/content requests when no direct Flavor Agent chat backend is available, plus connector-backed chat paths | Core-managed in `Settings > Connectors`                                                                                          |
| Provider selection               | Chooses Azure OpenAI, OpenAI Native, or a connector path | All chat-backed recommendation lanes plus pattern ranking                                                       | `flavor_agent_openai_provider` (`azure_openai`, `openai_native`, or a configured connector-backed provider)                     |
| Azure OpenAI Embeddings          | Pattern embedding (3072-dim)                              | Pattern index + pattern recommendations when Azure is the active embedding backend                              | `flavor_agent_azure_openai_endpoint`, `_key`, `_embedding_deployment`                                                            |
| Azure OpenAI Responses           | Chat + ranking                                            | Direct-provider block/content/template/template-part/navigation/style requests and pattern ranking              | `flavor_agent_azure_openai_endpoint`, `_key`, `_chat_deployment`                                                                 |
| OpenAI Native Embeddings         | Pattern embedding                                         | Pattern index + pattern recommendations when OpenAI Native is the active embedding backend                      | Optional `flavor_agent_openai_native_api_key` override, `_embedding_model`; otherwise inherits core OpenAI connector credentials |
| OpenAI Native Chat               | Chat + ranking                                            | Direct-provider block/content/template/template-part/navigation/style requests and pattern ranking              | Optional `flavor_agent_openai_native_api_key` override, `_chat_model`; otherwise inherits core OpenAI connector credentials      |
| Qdrant                           | Vector similarity search                                  | Pattern recommendations                                                                                         | `flavor_agent_qdrant_url`, `_key`                                                                                                |
| Cloudflare AI Search             | WordPress dev-doc grounding                               | Supplemental doc context for block, pattern, template, template-part, navigation, Global Styles, and Style Book recs | `flavor_agent_cloudflare_ai_search_account_id`, `_instance_id`, `_api_token`, `_max_results`                                     |

The plugin works in degraded mode without any services configured. Each surface gracefully disables when its required backends are absent.

When OpenAI Native is selected, credential precedence is: plugin override -> `OPENAI_API_KEY` environment variable -> `OPENAI_API_KEY` PHP constant -> core OpenAI connector database setting. The settings UI and `flavor-agent/check-status` expose the effective source and connector registration state so the ownership boundary stays visible.

## Feature Inventory

### Implemented and Working

#### Block Inspector Recommendations

- **Trigger:** User selects a block, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Block name, attributes, styles, supports, inspector panels, editing mode, content/config attributes, child count, structural identity (role, location, position), sibling blocks, ancestor chain, theme tokens, WordPress docs guidance (cache-only).
- **LLM:** `ChatClient::chat()`; prefers the direct plugin-managed provider when configured and otherwise falls back to the WordPress AI Client / Connectors path.
- **Response:** Parsed into `settings`, `styles`, `block` suggestion groups. Each suggestion has label, description, panel, confidence (0-1), and `attributeUpdates`.
- **Apply:** One-click per suggestion. Safe deep-merge for `metadata` and `style` keys. Apply captures before/after attribute snapshots, shows an inline success notice with `Undo`, and writes a structured activity record.
- **Guards:** Content-only blocks receive only content-attribute suggestions. Disabled blocks receive no suggestions. `blockVisibility` (boolean and viewport-object forms) respected.

#### Pattern Recommendations

- **Trigger:** Passive fetch on editor load; active fetch on inserter search input change (400ms debounce).
- **Pipeline:** Build query text -> cache-backed WordPress docs grounding via Cloudflare AI Search -> provider-selected embedding -> two-pass Qdrant search (semantic + structural) -> dedupe -> LLM rerank via the active responses backend -> filter scores < 0.3 -> return max 8.
- **Inserter integration:** Via `compat.js`, probes future stable pattern settings first for forward compatibility, but current Gutenberg trunk / WordPress 7.0 still resolves through `__experimentalAdditionalBlockPatterns` and `__experimentalBlockPatterns`. The adapter patches whichever path the editor actually exposes to add the "Recommended" category, enriched descriptions, and extracted keywords.
- **Badge:** Inserter toggle badge shows recommendation count (ready), loading pulse, or error indicator. Toggle discovery centralized in `compat.findInserterToggle`.
- **Scoping:** `visiblePatternNames` derived from inserter root for context-appropriate results via `compat.getAllowedPatterns`.

#### Template Recommendations

- **Trigger:** User editing a `wp_template` in Site Editor, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Template ref, type, assigned template-part slots, empty areas, available (unassigned) template parts, top-level block tree, executable top-level insertion anchors, candidate patterns (typed + generic, filtered by client-side `visiblePatternNames` when available, max 30), template-global `visiblePatternNames` when available, theme tokens, WordPress docs guidance.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Max 3 suggestions, each with validated structured operations. Supported executable operations are `assign_template_part`, `replace_template_part`, and `insert_pattern`, where template pattern insertion may now include `start`, `end`, `before_block_path`, or `after_block_path` placement metadata.
- **UI:** Entity mentions in text become clickable links: template-part slugs/areas highlight blocks in canvas; pattern names open the inserter pre-filtered. The panel now uses the shared Epic 2 review model: prompt -> suggestions -> explanation -> review -> apply -> undo/history, with shared advisory/status/review components.
- **Apply:** Deterministic client executor now validates template suggestions against a working-state copy before mutating. Template-part assignment/replacement updates existing `core/template-part` blocks, anchored pattern insertion resolves validated top-level template paths before inserting parsed pattern blocks, legacy insertion still resolves the current insertion point, and applied operations persist stable undo locators plus recorded post-apply snapshots for inserted subtrees.
- **Guardrails:** Free-form template tree rewrites are intentionally out of scope. If any operation fails validation, the entire apply is rejected before mutation.

#### Template Part Recommendations

- **Trigger:** User editing a `wp_template_part` in Site Editor, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Template-part ref, slug, inferred area, structural summaries, executable operation targets, executable insertion anchors, structural constraints, candidate patterns (filtered by request `visiblePatternNames` when available), theme tokens, and cache-backed WordPress docs guidance.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Max 3 suggestions, each with `label`, `description`, `blockHints`, `patternSuggestions`, optional validated `operations`, and `explanation`. Supported executable operations are `insert_pattern`, `replace_block_with_pattern`, and `remove_block`, with bounded placement at `start`, `end`, `before_block_path`, or `after_block_path` where applicable.
- **UI:** `src/template-parts/TemplatePartRecommender.js` renders a store-backed document settings panel with advisory links, shared preview-confirm-apply framing for validated operations, shared status notices, and recent activity. Non-deterministic ideas stay visible through the same advisory shell instead of disappearing.
- **Apply:** Deterministic client executor validates template-part operations before mutation, inserts parsed pattern blocks at the exact resolved root/path location, supports bounded targeted replacement/removal, and records stable undo metadata plus post-apply snapshots for refresh-safe undo.
- **Guardrails:** Unsupported or ambiguous suggestions stay advisory-only. There is still no free-form rewrite path; all executable operations must resolve to explicit validated placements and block-path targets before mutation.

#### Global Styles Recommendations

- **Trigger:** User opens the Site Editor Styles sidebar, types an optional prompt, and clicks "Get Style Suggestions".
- **Context sent:** Resolved Global Styles scope descriptor, current user config, current merged config, available theme style variations, theme-token source diagnostics, theme tokens, and supported site-level style paths.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Up to 4 suggestions, each with `label`, `description`, `category`, `tone`, optional validated `operations`, and `explanation`. Supported executable operations are `set_styles` (global-styles surface only) and `set_theme_variation`.
- **UI:** `src/global-styles/GlobalStylesRecommender.js` portals into the native Global Styles sidebar when available and falls back to a document settings panel when the sidebar slot is missing. It uses the shared prompt -> suggestions -> explanation -> review -> apply -> undo/history model.
- **Apply:** Deterministic client helpers validate supported paths, preset requirements, and still-available theme variations before updating the active `root/globalStyles` entity through `editEntityRecord()`. Applied changes persist before/after user config plus operation metadata for scoped undo.
- **Guardrails:** Raw CSS, `customCSS`, unsupported style paths, arbitrary preset-less values where a preset family is required, width/height transforms, and pseudo-element-only operations remain out of scope for the first Epic 3 slice.

#### Style Book Recommendations

- **Trigger:** User opens the Style Book panel for a block type, types an optional prompt, and clicks "Get Style Suggestions".
- **Context sent:** Resolved scope descriptor with `surface: "style-book"`, target block name and title, current block-scoped styles, merged config, theme-token source diagnostics, and theme tokens.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`, using the same `StylePrompt` and `StyleAbilities::recommend_style()` as Global Styles with surface-aware operation rules.
- **Response:** Up to 4 suggestions with validated `operations`. Supported executable operations are `set_block_styles` (style-book surface only).
- **UI:** `src/style-book/StyleBookRecommender.js` portals into the Style Book panel using `src/style-book/dom.js` for target resolution. Uses the same shared prompt -> suggestions -> explanation -> review -> apply -> undo/history model.
- **Apply:** Deterministic client helpers validate block-scoped style paths and preset requirements before updating the active `root/globalStyles` entity. Applied changes persist before/after config plus operation metadata for scoped undo.
- **Guardrails:** `set_styles` is rejected on the style-book surface. `set_theme_variation` is also rejected on the style-book surface. `set_block_styles.blockName` must exactly match the target block in scope. Same raw CSS, `customCSS`, and unsupported-path guardrails as Global Styles.

#### Content Recommendations (Programmatic Scaffold)

- **Surface:** No first-party Gutenberg panel yet. The contract exists as a stable REST + Abilities endpoint so a future post-editor UI, external agent, or admin tool can attach without inventing one later.
- **Trigger:** A caller posts `mode` (`draft`, `edit`, or `critique`), optional `prompt`, optional `voiceProfile`, and optional `postContext` to the endpoint.
- **LLM:** `ChatClient::chat()` using `WritingPrompt` for Henry-voice system prompt assembly and response parsing.
- **Response:** `mode`, `title`, `summary`, `content`, `notes[]`, and `issues[]`. Editorial-only — no auto-apply path.
- **Guards:** `edit` and `critique` modes require `postContext.content`. `draft` mode accepts a prompt, title, or other working context.

#### Shared Inline Review Model

- **Sequence:** Block, navigation, template, template-part, Global Styles, and Style Book surfaces now all follow one learned-once order: prompt -> suggestions -> explanation -> review where needed -> apply where allowed -> undo/history.
- **Normalized states:** The shared editor-side vocabulary is `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, and `error`.
- **Surface mapping:** Block can move directly from `advisory-ready` to `success` for safe local attribute updates; navigation stops at `advisory-ready`; template, template-part, Global Styles, and Style Book must explicitly enter `preview-ready` before any mutation occurs.
- **Shared UI:** `AIStatusNotice`, `AIAdvisorySection`, and `AIReviewSection` now own the common review/status framing, while `AIActivitySection` remains the shared undo/history block.
- **Notes future-proofing:** `src/review/notes-adapter.js` is a shape-only adapter for future Notes/comment projection. It normalizes shared review evidence but is not wired into runtime UI and does not depend on unstable editor APIs.

#### AI Activity And Undo

- **Schema:** Block, template, template-part, Global Styles, and Style Book apply actions share one activity shape: surface, target identifiers, suggestion label, before/after state, prompt/reference metadata, timestamp, and undo status.
- **Persistence:** Activity records are persisted through the server-backed activity repository when available and are hydrated back into the editor-side storage adapter keyed by the current post, template, template-part, or Global Styles scope reference. The same repository now also supports recent unscoped/admin reads for privileged users. Template, template-part, and Global Styles activities use schema-versioned persisted metadata; legacy clientId-only entries load as undo unavailable with a clear reason.
- **UI:** The Block Inspector, Navigation section, Template Compositor, Template Part panel, Global Styles panel, and Style Book panel now share the same status vocabulary and review framing. Executable surfaces show inline `Undo` on the latest applied action plus a `Recent AI Actions` section with the last few records. A first dedicated admin page at `Settings > AI Activity` exposes the recent server-backed timeline across supported surfaces through a `DataViews` activity feed and a read-only `DataForm` details panel.
- **Undo rules:** Only the most recent AI action is auto-undoable. Block undo remains path-plus-attribute based. Template assignment/replacement undo resolves the current block from a stable area/slug locator, template/template-part inserted-pattern undo only stays available while the recorded inserted subtree still exactly matches the persisted post-apply snapshot, Global Styles and Style Book undo only stays available while the active `root/globalStyles` entity still matches the recorded post-apply user config. The admin audit view applies the same ordered-tail rule when it marks older entries as blocked by newer still-applied actions.

#### Admin Activity Page

- **Location:** `Settings > AI Activity`
- **Permission:** `manage_options`
- **Bundle:** `inc/Admin/ActivityPage.php` + `src/admin/activity-log.js`
- **Behavior:** Uses `@wordpress/dataviews/wp` with the `activity` layout as the default feed, persisted/resettable view preferences, grouped summary cards, and a read-only `DataForm` details sidebar for diagnostics and links back to affected entities, plugin settings, and core Connectors.
- **Scope:** Covers recent server-backed block, template, template-part, Global Styles, and Style Book actions; it is the first audit surface, not the final observability product.

#### Pattern Index Lifecycle

- **Sync:** Diffs current registered patterns against Qdrant index using per-pattern fingerprints. Embeds only changed patterns in batches of 100. Detects config changes for full reindex.
- **Triggers:** Plugin activation, theme switch, plugin activate/deactivate, upgrades, settings changes.
- **Scheduling:** WP cron with 300s cooldown and transient lock.
- **Admin UI:** Manual sync button on settings page with status display.

#### WordPress Abilities API (available on supported WordPress 7.0+ installs)

All abilities registered with full JSON Schema input/output definitions:

| Ability                                | Handler                  | Permission           | Status             |
| -------------------------------------- | ------------------------ | -------------------- | ------------------ |
| `flavor-agent/recommend-block`         | `BlockAbilities`         | `edit_posts`         | Working            |
| `flavor-agent/recommend-content`       | `ContentAbilities`       | `edit_posts`         | Working            |
| `flavor-agent/introspect-block`        | `BlockAbilities`         | `edit_posts`         | Working (readonly) |
| `flavor-agent/recommend-patterns`      | `PatternAbilities`       | `edit_posts`         | Working            |
| `flavor-agent/list-patterns`           | `PatternAbilities`       | `edit_posts`         | Working (readonly) |
| `flavor-agent/recommend-template`      | `TemplateAbilities`      | `edit_theme_options` | Working            |
| `flavor-agent/recommend-template-part` | `TemplateAbilities`      | `edit_theme_options` | Working            |
| `flavor-agent/recommend-style`         | `StyleAbilities`         | `edit_theme_options` | Working            |
| `flavor-agent/list-template-parts`     | `TemplateAbilities`      | `edit_theme_options` | Working (readonly) |
| `flavor-agent/search-wordpress-docs`   | `WordPressDocsAbilities` | `manage_options`     | Working (readonly) |
| `flavor-agent/get-theme-tokens`        | `InfraAbilities`         | `edit_posts`         | Working (readonly) |
| `flavor-agent/check-status`            | `InfraAbilities`         | `edit_posts`         | Working (readonly) |
| `flavor-agent/recommend-navigation`    | `NavigationAbilities`    | `edit_theme_options` | Working            |

#### WordPress Docs Grounding (Cloudflare AI Search)

- Explicit search via `search-wordpress-docs` ability (`manage_options` only).
- Recommendation-time grounding for block, pattern, template, template-part, navigation, Global Styles, and Style Book suggestions is cache-only and non-blocking. Exact-query cache (6h TTL) is authoritative; warmed entity cache (12h TTL) is fallback.
- Strict source filtering: only `developer.wordpress.org` chunks accepted. URL trust validation (HTTPS, no credentials, sourceKey/URL identity checks). Source keys with an `ai-search/<instanceId>/` prefix are now recognized alongside the plain `developer.wordpress.org/` prefix.
- Docs grounding prewarm: on plugin activation and successful Cloudflare credential changes, an async WP-Cron job seeds the entity cache for 16 high-frequency entities (8 core blocks, 7 template types, core/navigation). Exact entity misses now also fall back to prewarmed generic editor/template/template-part guidance families before returning empty. Throttled by credential fingerprint and 1-hour cooldown. Admin diagnostics panel shows last prewarm status, timestamp, and warmed/failed counts.

#### REST API

| Route                                      | Method   | Permission                                                                    | Handler                                                         |
| ------------------------------------------ | -------- | ----------------------------------------------------------------------------- | --------------------------------------------------------------- |
| `/flavor-agent/v1/recommend-block`         | POST     | `edit_posts`                                                                  | `BlockAbilities::recommend_block`                               |
| `/flavor-agent/v1/recommend-content`       | POST     | `edit_posts`                                                                  | `ContentAbilities::recommend_content`                           |
| `/flavor-agent/v1/recommend-patterns`      | POST     | `edit_posts`                                                                  | `PatternAbilities::recommend_patterns`                          |
| `/flavor-agent/v1/recommend-navigation`    | POST     | `edit_theme_options`                                                          | `NavigationAbilities::recommend_navigation`                     |
| `/flavor-agent/v1/recommend-template`      | POST     | `edit_theme_options`                                                          | `TemplateAbilities::recommend_template`                         |
| `/flavor-agent/v1/recommend-template-part` | POST     | `edit_theme_options`                                                          | `TemplateAbilities::recommend_template_part`                    |
| `/flavor-agent/v1/recommend-style`         | POST     | `edit_theme_options`                                                          | `StyleAbilities::recommend_style`                               |
| `/flavor-agent/v1/activity`                | GET/POST | editor or theme capability by context; unscoped GET requires `manage_options` | `ActivityRepository` adapters via `Agent_Controller`            |
| `/flavor-agent/v1/activity/{id}/undo`      | POST     | editor or theme capability by context                                         | `ActivityRepository::update_undo_status` via `Agent_Controller` |
| `/flavor-agent/v1/sync-patterns`           | POST     | `manage_options`                                                              | `PatternIndex::sync`                                            |

#### Admin Settings

Settings page at Settings > Flavor Agent with five sections:

- OpenAI Provider (select Azure OpenAI or OpenAI Native for pattern/template/navigation recommendations)
- Azure OpenAI (endpoint, key, embedding deployment, chat deployment)
- OpenAI Native (optional API key override, chat model, embedding model, effective key source, connector status)
- Qdrant (URL, key)
- Cloudflare AI Search (account ID, instance ID, API token, max results)

Block recommendations can use the direct plugin-managed chat backend configured here or the core `Settings > Connectors` path when the direct backend is not configured.
When OpenAI Native is selected, Flavor Agent still owns the chat and embedding model IDs for pattern/template/navigation work, but the API key can be inherited from the core OpenAI connector unless a plugin-specific override is saved.

Plus pattern sync status panel with manual trigger.

When the Azure OpenAI endpoint, key, embedding deployment, or chat deployment changes and all four fields are present, the settings save flow validates both the embeddings and responses deployments and preserves the previous values if validation fails.
When the Qdrant URL or key changes and both fields are present, the settings save flow validates the `/collections` endpoint and preserves the previous values if validation fails.
When the Cloudflare AI Search account ID, instance ID, or token changes and all three fields are present, the settings save flow validates the configured account, instance, and token with a lightweight probe search and preserves the previous values if validation fails. This keeps the settings flow compatible with documented AI Search Run tokens.
Unchanged or partial credential submissions skip remote validation.
Successful saves still use the standard Settings API notice flow, and failed Azure OpenAI, Qdrant, or Cloudflare validation surfaces a plugin-scoped error notice on the same screen.

### Not Yet Built (From Original Vision)

Earlier planning iterations described a broader 5-phase roadmap. Since then, the current codebase has shipped the later safety and hardening work that now exists in-tree: template review-confirm-apply, AI activity + undo, the pattern compatibility adapter, docs prewarm, and deeper Playwright smoke coverage. The larger generative/editor-transform items below still remain out of scope. Some of them, especially Interactivity scaffolding, are future-facing ideas rather than current shipping gaps because the plugin still operates entirely in editor/admin surfaces.

| Feature                       | Original Phase | Current Status        | Notes                                                                                                                                                  |
| ----------------------------- | -------------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Block subtree transforms      | Phase 2        | Not built             | Propose replacement block trees for selected blocks                                                                                                    |
| Pattern generation            | Phase 3        | Not built             | LLM generates new pattern markup from context                                                                                                          |
| Pattern promotion             | Phase 3        | Not built             | Save approved AI output as plugin-managed registered patterns                                                                                          |
| Interactivity API scaffolding | Phase 4        | Not built             | Future-facing only; the current plugin has no front-end runtime surface that requires `viewScriptModule` or Interactivity API code                     |
| Navigation overlay generation | Phase 4        | Not built             | Create mobile nav overlays as template parts                                                                                                           |
| Approval pipeline UI          | Phase 1-3      | Not built             | Visual approve/reject flow with diff preview before insertion                                                                                          |
| Audit/revision log UI         | Phase 5        | Initial slice shipped | `Settings > AI Activity` now provides a DataViews/DataForm timeline for recent actions; diff-oriented inspection and broader observability remain open |
| Dynamic block scaffolding     | Phase 4        | Not built             | Generate `render_callback` + dynamic block configs                                                                                                     |
| Pattern-to-file promotion     | Phase 3        | Not built             | Export approved patterns to PHP files in `patterns/` directory                                                                                         |

### Known Issues and Gaps

1. **`composer lint:php`**: Green across production code, but `tests/phpunit/bootstrap.php` is intentionally excluded from WPCS due to its multi-namespace stub harness.
2. **JS toolchain support is now explicit**: `.nvmrc` defaults to Node 24 / npm 11, and the repo also supports the previously verified Node 20 / npm 10 toolchain through the `package.json` engine range under `.npmrc`'s `engine-strict` gate.
3. **Inserter DOM discovery is still markup-coupled (mitigated)**: `inserter-dom.js` centralizes container (5), search-input (4), and toolbar toggle selectors and now fails closed to `null` when the expected editor structure is absent, so caller cleanup is isolated to one module.
4. **Pattern settings compatibility is explicit and fail-closed**: `pattern-settings.js` probes future stable `blockPatterns` / `blockPatternCategories` / `getAllowedPatterns` paths when present, but current Gutenberg trunk still exposes `__experimentalAdditional*`, `__experimental*`, and `__experimentalGetAllowedPatterns` as the live baseline. The adapter returns an empty scoped result plus diagnostics instead of widening to an `all-patterns-fallback` result when contextual selectors are unavailable.
5. **Theme-token source resolution is now merged rather than over-promoted**: `theme-settings.js` isolates raw settings reads and now uses stable sources when available while filling only missing branches from `__experimentalFeatures`. Flavor Agent still targets WordPress 7.0+, so block attribute role detection reads only the stable `role` key and no longer preserves deprecated `__experimentalRole` compatibility.
6. **Browser coverage is split across two harnesses**: Playground remains the fast `6.9.4` smoke path, while a dedicated Docker-backed WordPress `7.0` Site Editor harness owns refresh/drift-sensitive flows. The default `npm run test:e2e` command now aggregates both harnesses and the checked-in smoke suite now covers navigation plus `wp_template_part`, but the WP 7.0 half still requires Docker on PATH. Because WordPress `7.0` is still pre-release and the cycle was extended on 2026-03-31, that harness currently pins `wordpress:beta-7.0-RC2-php8.2-apache`, which matched the latest checked Docker Hub pre-release tag on 2026-04-04, until the official stable `7.0` image exists and Core publishes the final release timeline.
7. **Activity history is still only a first audit slice**: The new `Settings > AI Activity` page provides a recent DataViews/DataForm timeline for privileged users, but there is still no diff-oriented inspection UI, no abilities-backed row actions/discovery layer, and no broader observability workflow beyond the stored timeline.
8. **Provider-backed verification is still environment-dependent**: A fresh Azure-backed recommendation pass was recorded on 2026-04-04 and captured in `STATUS.md`, but future live verification still depends on active provider credentials and should be rerun whenever the configured provider path changes.

### Current Open Backlog

- Deepen the new admin activity page into a richer audit/observability surface with before/after inspection, better diagnostics, and a cleaner action/discovery layer.
- Swap the Docker-backed WP 7.0 browser harness from the beta image to the official stable `7.0` image once it exists, and keep Docker available in environments that run that harness.
- Revisit navigation apply only if a bounded previewable/undoable executor becomes its own tracked post-v1 milestone.
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
           -> ChatClient::chat() (provider-first, WordPress AI Client fallback)
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
    -> compat.js: setBlockPatterns() (future stable key if present, otherwise current experimental path)
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

### Global Styles Recommendation Flow

```
User opens the Site Editor Styles sidebar
  -> GlobalStylesRecommender.js renders inside the native sidebar slot or document settings fallback
  -> User clicks "Get Style Suggestions"
  -> store thunk: fetchGlobalStylesRecommendations(input)
     -> POST /flavor-agent/v1/recommend-style
        -> Agent_Controller -> StyleAbilities::recommend_style()
           -> getGlobalStylesUserConfig() contributes the current entity id, user config, and available variations
           -> ServerCollector::for_tokens() contributes theme tokens plus token-source diagnostics
           -> StylePrompt::build_system() + build_user()
           -> ResponsesClient::rank(instructions, input)
           -> StylePrompt::parse_response() (validates supported paths, preset usage, and variation references)
        <- JSON response: { suggestions, explanation }
  -> store saves request/result state for the current global_styles scope
  -> UI renders advisory or executable cards plus preview-confirm-apply for validated style operations
  -> applyGlobalStylesSuggestion()
     -> applyGlobalStyleSuggestionOperations()
     -> write an editor-scoped activity entry, persist it through the activity repository, and keep refresh-safe undo metadata
```

### Style Book Recommendation Flow

```
User opens the Style Book panel for a block type
  -> StyleBookRecommender.js portals into the Style Book panel via dom.js
  -> User clicks "Get Style Suggestions"
  -> store thunk: fetchStyleBookRecommendations(input)
     -> POST /flavor-agent/v1/recommend-style (scope.surface = "style-book")
        -> Agent_Controller -> StyleAbilities::recommend_style()
           -> Resolves style-book surface, target block name and styles
           -> ServerCollector::for_tokens() contributes theme tokens plus diagnostics
           -> StylePrompt::build_system() + build_user() (surface-aware rules)
           -> ResponsesClient::rank(instructions, input)
           -> StylePrompt::parse_response() (validates block-scoped paths, preset usage, and variation references)
        <- JSON response: { suggestions, explanation }
  -> store saves request/result state for the current style_book scope
  -> UI renders advisory or executable cards plus preview-confirm-apply for validated style operations
  -> applyStyleBookSuggestion()
     -> applyGlobalStyleSuggestionOperations() (shared with Global Styles)
     -> write an editor-scoped activity entry, persist it through the activity repository, and keep refresh-safe undo metadata
```

## Test Coverage

### PHP (PHPUnit)

This section is intentionally representative rather than a line-by-line manifest. The live suite currently includes the files under `tests/phpunit/`, with these areas carrying direct coverage:

| Suite / area | Representative files | What's Covered |
| ------------ | -------------------- | -------------- |
| Contract and registration | `AgentControllerTest`, `RegistrationTest`, `EditorSurfaceCapabilitiesTest` | REST wrappers, ability schemas, localized capability payloads, activity endpoints, and route/shape validation |
| Block and content abilities | `BlockAbilitiesTest`, `ContentAbilitiesTest`, `PromptGuidanceTest`, `PromptRulesTest`, `WordPressAIClientTest`, `ChatClientTest` | Block input normalization, editorial content scaffold behavior, prompt guardrails, and WordPress AI Client fallback behavior |
| Provider and backend configuration | `ProviderTest`, `SettingsTest`, `AzureBackendValidationTest`, `InfraAbilitiesTest` | Provider selection, connector/native credential precedence, backend diagnostics, validation rollback, and effective runtime metadata |
| Pattern pipeline | `PatternAbilitiesTest`, `PatternCatalogTest`, `PatternIndexTest` | Registry filtering, recommendation pipeline behavior, sync/fingerprint lifecycle, and Qdrant-backed indexing rules |
| Template, style, and navigation prompting | `TemplatePromptTest`, `TemplatePartPromptTest`, `StyleAbilitiesTest`, `StylePromptTest`, `NavigationAbilitiesTest` | Structured operations, bounded placements, style scope rules, variation parsing, and navigation advice parsing |
| Activity and persistence | `ActivityRepositoryTest`, `ActivityPermissionsTest` | Activity create/query/prune, ordered undo eligibility, undo state transitions, and contextual permissions |
| Docs grounding | `AISearchClientTest`, `DocsGroundingEntityCacheTest`, `DocsPrewarmTest` | Trusted-source filtering, cache layers, prewarm scheduling, throttling, and diagnostics |
| Shared context collectors | `ServerCollectorTest` | Template/template-part metadata, visible-pattern filtering, and token diagnostics |

### JS (Jest)

Current high-signal coverage map:

| Area | Representative files | What's Covered |
| ---- | -------------------- | -------------- |
| Store state and activity orchestration | `src/store/__tests__/activity-history.test.js`, `activity-history-state.test.js`, `store-actions.test.js`, `template-apply-state.test.js`, `pattern-status.test.js`, `navigation-request-state.test.js` | Request lifecycle, stale-result rejection, activity hydration, undo coordination, and executable-surface reducer state |
| Inspector flows | `src/inspector/__tests__/BlockRecommendationsPanel.test.js`, `SettingsRecommendations.test.js`, `StylesRecommendations.test.js`, `NavigationRecommendations.test.js`, `InspectorInjector.test.js`, `panel-delegation.test.js`, `SuggestionChips.test.js`, `src/inspector/suggestion-keys.test.js` | Panel rendering, capability gating, delegated sub-panel behavior, navigation framing, and key generation |
| Pattern inserter integration | `src/patterns/__tests__/PatternRecommender.test.js`, `InserterBadge.test.js`, `compat.test.js`, `recommendation-utils.test.js`, `find-inserter-search-input.test.js`, `inserter-badge-state.test.js` | Inserter DOM discovery, compat negotiation, metadata patching, badge state, and fetcher lifecycle |
| Template and style surfaces | `src/templates/__tests__/TemplateRecommender.test.js`, `template-recommender-helpers.test.js`, `src/template-parts/__tests__/TemplatePartRecommender.test.js`, `src/global-styles/__tests__/GlobalStylesRecommender.test.js`, `src/style-book/__tests__/StyleBookRecommender.test.js` | Site Editor rendering, suggestion lifecycle, operation helpers, scope resolution, and panel portal behavior |
| Shared UI composition | `src/components/__tests__/AIStatusNotice.test.js`, `AIAdvisorySection.test.js`, `AIReviewSection.test.js`, `AIActivitySection.test.js`, `ActivitySessionBootstrap.test.js`, `RecommendationHero.test.js`, `RecommendationLane.test.js`, `SurfaceComposer.test.js`, `SurfacePanelIntro.test.js`, `SurfaceScopeBar.test.js` | Shared review model, status framing, featured/grouped presentation, scope UI, and activity bootstrap behavior |
| Utility modules | `src/utils/__tests__/editor-entity-contracts.test.js`, `editor-context-metadata.test.js`, `format-count.test.js`, `style-design-semantics.test.js`, `style-operations.test.js`, `template-actions.test.js`, `template-part-areas.test.js`, `template-types.test.js`, `visible-patterns.test.js`, `capability-flags.test.js`, `structural-identity.test.js` | Entity contracts, editor metadata summaries, formatting helpers, design semantics, deterministic apply/undo rules, and structural analysis |
| Admin audit page | `src/admin/__tests__/activity-log.test.js`, `activity-log-utils.test.js` | DataViews rendering, persisted admin views, summary cards, filters, and entity/settings link generation |

## Definition of "Complete" (v1.0)

Based on the original vision and current trajectory, Flavor Agent v1.0 should satisfy:

### Must Have (v1.0)

- [x] Block Inspector recommendations with per-block loading/error state
- [x] Content-only and disabled block guards
- [x] Pattern recommendations via vector search + LLM ranking
- [x] Native inserter integration (Recommended category, badge)
- [x] Template composition panel with review-confirm-apply for validated operations
- [x] Template-part recommendations panel
- [x] Global Styles recommendations panel with review-confirm-apply for bounded site-level style changes
- [x] Style Book recommendations panel with review-confirm-apply for per-block style changes
- [x] Undoable block/template/template-part/Global Styles/Style Book AI actions with server-backed activity persistence plus editor-scoped hydration/cache fallback
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

| Document                                                           | Purpose                                                                                                    | Status                         |
| ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- | ------------------------------ |
| `docs/README.md`                                                   | Documentation backbone, reading order, and update contract                                                 | **Current**                    |
| `docs/SOURCE_OF_TRUTH.md`                                          | Definitive project reference: scope, architecture, inventory, roadmap                                      | **Current**                    |
| `docs/FEATURE_SURFACE_MATRIX.md`                                   | Fast map of every shipped surface, gating condition, and apply/undo path                                   | **Current**                    |
| `docs/features/README.md`                                          | Entry point for detailed feature docs covering end-to-end flows                                            | **Current**                    |
| `docs/features/content-recommendations.md`                         | Programmatic content lane contract and current no-first-party-UI status                                     | **Current**                    |
| `docs/features/style-and-theme-intelligence.md`                    | Detailed Global Styles and Style Book surface doc: scope contract, prompt/apply flow, guardrails, and undo | **Current**                    |
| `docs/reference/abilities-and-routes.md`                           | Canonical ability and REST contract reference                                                              | **Current**                    |
| `docs/reference/shared-internals.md`                               | Shared store utilities, UI components, and context helpers                                                  | **Current**                    |
| `docs/reference/provider-precedence.md`                            | AI backend selection and credential fallback chain                                                         | **Current**                    |
| `docs/reference/template-operations.md`                            | Operation type vocabulary and validation rules per surface                                                 | **Current**                    |
| `docs/reference/activity-state-machine.md`                         | Undo states, transitions, ordered undo, and pruning                                                        | **Current**                    |
| `docs/flavor-agent-readme.md`                                      | Architecture details: editor flows, settings, style surfaces, pattern lifecycle, and admin audit          | **Current**                    |
| `docs/local-wordpress-ide.md`                                      | Local Docker/devcontainer workflow and host setup                                                          | **Current**                    |
| `docs/2026-03-25-roadmap-aligned-execution-plan.md`                | Active forward plan aligned to the current WordPress 7.0 and Gutenberg roadmap context                     | **Current**                    |
| `docs/2026-04-03-wordpress-direction-review.md`                    | Supplemental dated direction review after the WordPress 7.0 schedule extension                              | **Supplemental context**       |
| `docs/2026-04-03-three-phase-roadmap.md`                           | Supplemental dated three-phase execution framing derived from the direction review                           | **Supplemental context**       |
| `docs/wordpress-7.0-gutenberg-22.8-reference.md`                   | WP 7.0 plus Gutenberg 22.8 stable / 22.9 RC API changes, new features, deprecations, and plugin impact     | **Reference snapshot**         |
| `docs/wordpress-7.0-developer-docs-index.md`                       | Discovery snapshot of official WordPress 7.0 developer documentation sources                               | **Reference snapshot**         |
| `docs/wp7-migration-opportunities.md`                              | Point-in-time WordPress 7.0 migration assessment and follow-up opportunities                               | **Reference snapshot**         |
| `docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md` | Implemented design spec for pattern badge status surface                                                   | **Implemented reference spec** |
| `CLAUDE.md`                                                        | Claude Code project instructions: commands, architecture, gotchas                                          | **Current**                    |
| `STATUS.md`                                                        | Working feature inventory and verification log                                                             | **Current**                    |

## Build and Dev Commands

```bash
# JS
source ~/.nvm/nvm.sh && nvm use      # Default JS toolchain from .nvmrc (Node 24 / npm 11)
npm ci                               # Install JS deps reproducibly
npm run build                        # Production build -> build/index.js, build/admin.js, build/activity-log.js
npm run dist                         # Prepare release temp tree and zip -> dist/flavor-agent.zip
npm start                            # Dev build with watch
npm run lint:js                      # ESLint on src/
npm run lint:plugin                  # Plugin validation wrapper
npm run check:docs                   # Live-doc freshness checks
npm run test:unit -- --runInBand     # Jest unit tests
npm run test:e2e                     # Default Playwright smoke coverage (Playground + WP 7.0 harness)
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

1. **Shared provider selection, split execution paths**: Block and content requests use `ChatClient`, while template, template-part, navigation, Global Styles, Style Book, and pattern ranking use `ResponsesClient`. Both ride the same `Provider` selection layer, which can use Azure OpenAI, OpenAI Native, a configured connector-backed provider, or the generic WordPress AI Client fallback when no direct chat backend is configured. Pattern embeddings remain plugin-managed even when chat is connector-backed.
2. **Narrow approval model**: Block suggestions still apply inline on click, but template suggestions use an explicit preview-confirm-apply step. There is no separate multi-stage approval workspace or diff-review pipeline.
3. **Inspector injection over sidebar**: Recommendations appear in the native Inspector tabs (Settings, Styles, sub-panels) rather than a separate sidebar. This feels native, not bolted-on.
4. **Vector search for patterns**: Patterns are embedded and stored in Qdrant rather than passed to the LLM as raw text. This scales to hundreds of patterns without hitting token limits.
5. **Cache-only docs grounding**: WordPress docs are not fetched on every recommendation request. Cache is warmed via explicit `search-wordpress-docs` calls, async prewarm jobs, prior queries, or first-request misses that queue follow-up warming. This avoids latency on the critical path.
6. **Abilities API is additive**: The REST API remains the primary runtime path. Abilities API registration is a parallel exposure for external agents. Neither depends on the other.
7. **Store is the contract boundary for executable surfaces**: Block, pattern, template, template-part, Global Styles, and Style Book UI read through `@wordpress/data` selectors and store thunks handle REST calls, error state, stale-request rejection, and activity/undo coordination.
8. **Navigation is advisory-only through v1.0**: The inspector surface is intentionally guidance-only until a bounded previewable/undoable executor earns its own milestone.
9. **Client-side `@wordpress/core-abilities` usage stays deferred for v1**: First-party JS continues to use feature-specific stores and REST endpoints; client-side abilities remain an external-agent/admin integration surface rather than the editor runtime baseline.
10. **Pattern Overrides, expanded `contentOnly`, and first-style extras stay bounded**: Pattern Overrides-aware ranking, broader `contentOnly` structural semantics, width/height preset transforms, and pseudo-element-aware token extraction are all deferred until later bounded milestones rather than being treated as ambient WP 7.0 work.
11. **`customCSS` recommendation generation is out of scope for v1**: The product remains grounded in native Gutenberg structures and theme tokens, not raw CSS authoring.
12. **Surface readiness uses one shared contract**: `flavorAgentData.capabilities.surfaces` and `flavor-agent/check-status` now read from the same `SurfaceCapabilities` shape so first-party UI and external diagnostics expose the same surface keys, reason codes, actions, and messages.
