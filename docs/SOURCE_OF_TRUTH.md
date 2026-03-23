# Flavor Agent -- Source of Truth

> Last updated: 2026-03-19
> Version: 0.1.0
> Support floor: WordPress 7.0+, PHP 8.0+

## What This Plugin Is

Flavor Agent is a WordPress plugin that adds AI-powered recommendations directly into the native Gutenberg editor. It does not insert or mutate content automatically -- it recommends, and the user decides.

Three recommendation surfaces exist today:

1. **Block Inspector** -- Per-block setting and style suggestions injected into the native Inspector sidebar tabs.
2. **Pattern Inserter** -- Vector-similarity pattern recommendations surfaced through the native block inserter with a "Recommended" category.
3. **Template Compositor** -- Advisory template-part and pattern composition suggestions for Site Editor templates.

A fourth surface -- **WordPress Abilities API** -- exposes the same capabilities as structured tool definitions for external AI agents (WP 6.9+).

## Repository Layout

```
flavor-agent/
  flavor-agent.php          Bootstrap, hooks, asset enqueue, lifecycle wiring
  uninstall.php             Removes plugin-owned options, sync state, scheduled jobs
  composer.json             PSR-4 autoload for inc/ (FlavorAgent\)
  package.json              @wordpress/scripts build, lint, tests
  webpack.config.js         Two entry points: editor (src/index.js), admin (src/admin/sync-button.js)
  phpcs.xml.dist            WPCS config
  phpunit.xml.dist          PHPUnit config
  CLAUDE.md                 Claude Code project instructions
  STATUS.md                 Feature inventory and verification log

  inc/                      PHP backend (PSR-4 namespace FlavorAgent\)
    REST/
      Agent_Controller.php  REST routes under flavor-agent/v1/
    LLM/
      WordPressAIClient.php WordPress 7.0 AI client wrapper for block recommendations
      Prompt.php            Block recommendation prompt assembly and response parsing
      TemplatePrompt.php    Template recommendation prompt assembly and response parsing
    AzureOpenAI/
      EmbeddingClient.php   Azure OpenAI embeddings (3072-dim vectors)
      QdrantClient.php      Qdrant vector DB CRUD and search
      ResponsesClient.php   Azure OpenAI Responses API (chat/ranking)
    Cloudflare/
      AISearchClient.php    Cloudflare AI Search for WordPress dev-doc grounding
    Context/
      ServerCollector.php   Server-side context: blocks, tokens, patterns, templates
    Patterns/
      PatternIndex.php      Pattern embedding lifecycle: sync, diff, cron, fingerprint
    Abilities/
      Registration.php      Abilities API category + 10 ability registrations
      BlockAbilities.php    recommend-block, introspect-block
      PatternAbilities.php  recommend-patterns, list-patterns
      TemplateAbilities.php recommend-template, list-template-parts
      NavigationAbilities.php  recommend-navigation
    LLM/
      NavigationPrompt.php  Navigation recommendation prompt assembly and response parsing
      InfraAbilities.php    get-theme-tokens, check-status
      WordPressDocsAbilities.php  search-wordpress-docs
    Support/
      StringArray.php       Array sanitization utility
    Settings.php            Admin settings page + pattern sync panel

  src/                      JS frontend (built with @wordpress/scripts)
    index.js                Entry: registers store, inspector filter, sidebar plugin
    editor.css              All editor-side styles (782 lines)
    store/
      index.js              @wordpress/data store (flavor-agent): state, actions, selectors
      update-helpers.js     Safe attribute merge, content-only filtering
    inspector/
      InspectorInjector.js  editor.BlockEdit HOC -- injects AI panels into all blocks
      SettingsRecommendations.js  Settings tab suggestion cards
      StylesRecommendations.js   Appearance tab + style variation pills
      SuggestionChips.js    Compact chips for sub-panel injection
      suggestion-keys.js    Stable key generation for suggestion tracking
    context/
      collector.js          Assembles full block context for LLM calls
      block-inspector.js    Recursive block capability manifest builder
      theme-tokens.js       Design token extraction from theme.json + global styles
    patterns/
      PatternRecommender.js Headless fetcher + native inserter patching
      InserterBadge.js      Badge portal on inserter toggle (count/loading/error)
      inserter-badge-state.js  Pure badge view-model derivation
      recommendation-utils.js  Pattern metadata patching + badge reason extraction
      find-inserter-search-input.js  DOM utility to locate inserter search input
    templates/
      TemplateRecommender.js  Site Editor advisory panel with linked entities
      template-recommender-helpers.js  Pure helpers for template UI
    admin/
      sync-button.js        Settings page: manual pattern index sync
    utils/
      structural-identity.js  Block tree structural role annotation
      template-part-areas.js  Template-part area resolution
      template-types.js     Template slug normalization
      template-actions.js   Editor navigation actions (select block, open inserter)
      pattern-names.js      Extract distinct pattern names
      visible-patterns.js   Inserter-scoped visible pattern list

  tests/
    phpunit/
      bootstrap.php         WP function/class stubs
      AgentControllerTest.php
      ServerCollectorTest.php
      InfraAbilitiesTest.php
      RegistrationTest.php
      DocsGroundingEntityCacheTest.php
      AISearchClientTest.php
      PromptRulesTest.php
      BlockAbilitiesTest.php
      PromptGuidanceTest.php
      NavigationAbilitiesTest.php
      SettingsTest.php
    (JS tests live alongside source in __tests__/ dirs and *.test.js files)

  docs/
    SOURCE_OF_TRUTH.md      This document
    flavor-agent-readme.md  Architecture and editor flow reference
    2026-03-18-cloudflare-ai-search-grounding-assessment.md
    superpowers/specs/
      2026-03-17-pattern-badge-status-design.md
    historical/             Superseded early designs (kept for reference only)
      LLM-WordPress-Assistant.md
      LLM-WordPress-Assistant-Notes.md
      LLM-WordPress-Phases.md

  build/                    Webpack output (gitignored, must run npm run build)
  vendor/                   Composer autoloader (gitignored, must run composer install)
  node_modules/             (gitignored)
```

## External Services

| Service | Purpose | Required For | Config Options |
|---------|---------|-------------|----------------|
| WordPress AI Client + Connectors | Block recommendation LLM | Block Inspector recommendations | Core-managed in `Settings > Connectors` |
| Azure OpenAI Embeddings | Pattern embedding (3072-dim) | Pattern index + pattern recommendations | `flavor_agent_azure_openai_endpoint`, `_key`, `_embedding_deployment` |
| Azure OpenAI Responses | LLM ranking / chat | Pattern ranking, template recommendations | `flavor_agent_azure_openai_endpoint`, `_key`, `_chat_deployment` |
| Qdrant | Vector similarity search | Pattern recommendations | `flavor_agent_qdrant_url`, `_key` |
| Cloudflare AI Search | WordPress dev-doc grounding | Supplemental doc context for block/template recs | `flavor_agent_cloudflare_ai_search_account_id`, `_instance_id`, `_api_token`, `_max_results` |

The plugin works in degraded mode without any services configured. Each surface gracefully disables when its required backends are absent.

## Feature Inventory

### Implemented and Working

#### Block Inspector Recommendations
- **Trigger:** User selects a block, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Block name, attributes, styles, supports, inspector panels, editing mode, content/config attributes, child count, structural identity (role, location, position), sibling blocks, ancestor chain, theme tokens, WordPress docs guidance (cache-only).
- **LLM:** WordPress AI Client via `WordPressAIClient::chat()`.
- **Response:** Parsed into `settings`, `styles`, `block` suggestion groups. Each suggestion has label, description, panel, confidence (0-1), and `attributeUpdates`.
- **Apply:** One-click per suggestion. Safe deep-merge for `metadata` and `style` keys. Activity logged.
- **Guards:** Content-only blocks receive only content-attribute suggestions. Disabled blocks receive no suggestions. `blockVisibility` (boolean and viewport-object forms) respected.

#### Pattern Recommendations
- **Trigger:** Passive fetch on editor load; active fetch on inserter search input change (400ms debounce).
- **Pipeline:** Build query text -> Azure OpenAI embed -> two-pass Qdrant search (semantic + structural) -> dedupe -> LLM rerank via Azure Responses API -> filter scores < 0.3 -> return max 8.
- **Inserter integration:** Patches `__experimentalBlockPatterns` to add "Recommended" category, enriched descriptions, and extracted keywords.
- **Badge:** Inserter toggle badge shows recommendation count (ready), loading pulse, or error indicator.
- **Scoping:** `visiblePatternNames` derived from inserter root for context-appropriate results.

#### Template Recommendations
- **Trigger:** User editing a `wp_template` in Site Editor, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Template ref, type, assigned template-part slots, empty areas, available (unassigned) template parts, candidate patterns (typed + generic, max 30), theme tokens, WordPress docs guidance.
- **LLM:** Azure OpenAI Responses API via `ResponsesClient::rank()`.
- **Response:** Max 3 suggestions, each with template-part assignments and pattern suggestions. Validated against collected context.
- **UI:** Advisory-only. Entity mentions in text become clickable links: template-part slugs/areas highlight blocks in canvas; pattern names open the inserter pre-filtered.

#### Pattern Index Lifecycle
- **Sync:** Diffs current registered patterns against Qdrant index using per-pattern fingerprints. Embeds only changed patterns in batches of 100. Detects config changes for full reindex.
- **Triggers:** Plugin activation, theme switch, plugin activate/deactivate, upgrades, settings changes.
- **Scheduling:** WP cron with 300s cooldown and transient lock.
- **Admin UI:** Manual sync button on settings page with status display.

#### WordPress Abilities API (WP 6.9+)
All abilities registered with full JSON Schema input/output definitions:

| Ability | Handler | Permission | Status |
|---------|---------|-----------|--------|
| `flavor-agent/recommend-block` | `BlockAbilities` | `edit_posts` | Working |
| `flavor-agent/introspect-block` | `BlockAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | `edit_posts` | Working |
| `flavor-agent/list-patterns` | `PatternAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/recommend-template` | `TemplateAbilities` | `edit_theme_options` | Working |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | `edit_theme_options` | Working (readonly) |
| `flavor-agent/search-wordpress-docs` | `WordPressDocsAbilities` | `manage_options` | Working (readonly) |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/check-status` | `InfraAbilities` | `edit_posts` | Working (readonly) |
| `flavor-agent/recommend-navigation` | `NavigationAbilities` | `edit_theme_options` | Working |

#### WordPress Docs Grounding (Cloudflare AI Search)
- Explicit search via `search-wordpress-docs` ability (`manage_options` only).
- Recommendation-time grounding is cache-only and non-blocking. Exact-query cache (6h TTL) is authoritative; warmed entity cache (12h TTL) is fallback.
- Strict source filtering: only `developer.wordpress.org` chunks accepted. URL trust validation (HTTPS, no credentials, sourceKey/URL identity checks).
- Cold starts expected on first request for a given block/template context.

#### REST API
| Route | Method | Permission | Handler |
|-------|--------|-----------|---------|
| `/flavor-agent/v1/recommend-block` | POST | `edit_posts` | `BlockAbilities::recommend_block` |
| `/flavor-agent/v1/recommend-patterns` | POST | `edit_posts` | `PatternAbilities::recommend_patterns` |
| `/flavor-agent/v1/recommend-template` | POST | `edit_theme_options` | `TemplateAbilities::recommend_template` |
| `/flavor-agent/v1/sync-patterns` | POST | `manage_options` | `PatternIndex::sync` |

#### Admin Settings
Settings page at Settings > Flavor Agent with three sections:
- Azure OpenAI (endpoint, key, embedding deployment, chat deployment)
- Qdrant (URL, key)
- Cloudflare AI Search (account ID, instance ID, API token, max results)

Block recommendation providers are configured separately in core under `Settings > Connectors`.

Plus pattern sync status panel with manual trigger.

When the Cloudflare AI Search account ID, instance ID, or token changes and all three fields are present, the settings save flow validates the configured account, instance, and token against the instance endpoint, rejects disabled or paused instances, runs a lightweight probe search, and preserves the previous values if validation fails.
Successful saves still use the standard Settings API notice flow, and failed Cloudflare validation surfaces a plugin-scoped error notice on the same screen.

### Not Yet Built (From Original Vision)

The early design documents (`docs/historical/`) described a broader 5-phase roadmap. Only Phase 1 (recommend existing patterns) and parts of Phases 2-3 were implemented, though in a significantly different architecture than originally envisioned.

| Feature | Original Phase | Current Status | Notes |
|---------|---------------|----------------|-------|
| Block subtree transforms | Phase 2 | Not built | Propose replacement block trees for selected blocks |
| Pattern generation | Phase 3 | Not built | LLM generates new pattern markup from context |
| Pattern promotion | Phase 3 | Not built | Save approved AI output as plugin-managed registered patterns |
| Interactivity API scaffolding | Phase 4 | Not built | Generate `viewScriptModule` + Interactivity API code for interactive blocks |
| Navigation overlay generation | Phase 4 | Not built | Create mobile nav overlays as template parts |
| Approval pipeline UI | Phase 1-3 | Not built | Visual approve/reject flow with diff preview before insertion |
| Audit/revision log UI | Phase 5 | Not built | DataViews-based history of AI actions |
| Dynamic block scaffolding | Phase 4 | Not built | Generate `render_callback` + dynamic block configs |
| Pattern-to-file promotion | Phase 3 | Not built | Export approved patterns to PHP files in `patterns/` directory |

### Known Issues and Gaps

1. **Cold-start docs grounding**: First request for a block/template context returns without WordPress developer-doc guidance until cache is warmed.
2. **No live Azure/Qdrant credential verification**: Cloudflare AI Search credentials are now validated on credential changes against the configured instance state plus trusted WordPress-doc compatibility, but Azure OpenAI and Qdrant settings are still accepted without remote verification.
3. **`composer lint:php`**: Green across production code, but `tests/phpunit/bootstrap.php` is intentionally excluded from WPCS due to its multi-namespace stub harness.
4. **Inserter search detection is DOM-coupled**: `find-inserter-search-input.js` uses 5 container selectors x 4 input selectors. Fragile across Gutenberg versions.
5. **Pattern inserter patching uses legacy APIs**: `__experimentalBlockPatterns` and `__experimentalBlockPatternCategories` are experimental. Could break if Gutenberg stabilizes these.
6. **No integration/E2E tests**: Only unit tests exist (Jest for JS, PHPUnit for PHP with stub bootstrap). No Playwright or browser-level tests.
7. **Template recommender is advisory-only**: No apply/execute path. Users must manually act on suggestions.

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
           -> AISearchClient::maybe_search_with_entity_fallback() (docs grounding)
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
     -> Patches __experimentalBlockPatterns in block editor settings
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
           -> ServerCollector::for_template(ref, type)
              -> Walks parsed blocks for template-part slots
              -> Collects available parts, empty areas, candidate patterns
           -> AISearchClient::maybe_search_with_entity_fallback()
           -> TemplatePrompt::build_system() + build_user()
           -> ResponsesClient::rank(instructions, input)
           -> TemplatePrompt::parse_response() (validates against context)
        <- JSON response: { suggestions, explanation }
  -> store: SET_TEMPLATE_RECS
  -> UI: TemplateSuggestionCards with LinkedText entity links
     -> Template-part links -> selectBlockBySlugOrArea()
     -> Pattern links -> openInserterForPattern()
```

### Navigation Recommendation Flow
```
External AI agent calls flavor-agent/recommend-navigation ability
  -> NavigationAbilities::recommend_navigation(input)
     -> ServerCollector::for_navigation(menuId, markup)
        -> get_post(menuId) for wp_navigation content
        -> parse_blocks() to extract menu item tree
        -> Extract navigation block attributes
        -> for_template_parts('navigation-overlay') for WP 7.0 overlay parts
        -> infer_navigation_location() from template part refs
        -> for_tokens() for theme design tokens
     -> AISearchClient::maybe_search_with_entity_fallback()
     -> NavigationPrompt::build_system() + build_user()
     -> ResponsesClient::rank(instructions, input)
     -> NavigationPrompt::parse_response() (validates categories, change types)
  <- JSON response: { suggestions, explanation }
```

## Test Coverage

### PHP (PHPUnit)
| Test File | Tests | What's Covered |
|-----------|-------|---------------|
| `AgentControllerTest` | 1 | REST recommend-block wraps clientId, correct API call |
| `ServerCollectorTest` | 3 | Template parts metadata, area lookup, context normalization |
| `InfraAbilitiesTest` | 4 | check-status: Cloudflare backend, admin filtering, model fallback |
| `RegistrationTest` | 2 | Ability schema structure, entityKey schema |
| `DocsGroundingEntityCacheTest` | 6 | Two-tier cache: query vs entity, seeding, inference |
| `AISearchClientTest` | 18 | Search flow, config, cache, source filtering, URL trust, entity keys, instance validation states, trusted-docs compatibility probe |
| `PromptRulesTest` | 3 | Content-only rules, disabled blocks, container behavior |
| `BlockAbilitiesTest` | 3 | Input normalization, XSS sanitization, disabled block short-circuit |
| `PromptGuidanceTest` | 5 | Guidance sections in prompts, structural identity, content-only |
| `SettingsTest` | 6 | Changed-vs-unchanged Cloudflare save validation, rollback, partial credentials, and settings notice rendering |
| `NavigationAbilitiesTest` | 12 | Input validation, prompt assembly, response parsing, system prompt content |
| **Total** | **63** | |

### JS (Jest)
| Test File | What's Covered |
|-----------|---------------|
| `store/__tests__/block-request-state.test.js` | Per-block request state, stale token rejection |
| `store/__tests__/pattern-status.test.js` | Pattern status/error transitions, badge recalculation |
| `store/update-helpers.test.js` | Safe merge, content-only filtering, editing restrictions |
| `patterns/__tests__/inserter-badge-state.test.js` | Badge view-model derivation (all 4 states) |
| `patterns/__tests__/recommendation-utils.test.js` | Metadata patching, badge reason extraction |
| `patterns/__tests__/find-inserter-search-input.test.js` | DOM search strategy |
| `templates/__tests__/template-recommender-helpers.test.js` | Entity map, suggestion view models, format helpers |
| `inspector/suggestion-keys.test.js` | Key generation |
| `utils/__tests__/structural-identity.test.js` | Role annotation, location resolution, position tracking |
| `utils/__tests__/template-part-areas.test.js` | Area resolution priority chain |
| `utils/__tests__/template-types.test.js` | Slug normalization |
| `utils/__tests__/visible-patterns.test.js` | Inserter-scoped pattern list |

## Definition of "Complete" (v1.0)

Based on the original vision and current trajectory, Flavor Agent v1.0 should satisfy:

### Must Have (v1.0)

- [x] Block Inspector recommendations with per-block loading/error state
- [x] Content-only and disabled block guards
- [x] Pattern recommendations via vector search + LLM ranking
- [x] Native inserter integration (Recommended category, badge)
- [x] Template composition advisory panel
- [x] Pattern index lifecycle (auto-sync, background cron, diff-based updates)
- [x] WordPress Abilities API integration (all working abilities)
- [x] WordPress docs grounding (cache-based)
- [x] Admin settings page with backend configuration
- [x] Cloudflare AI Search credential validation on changed settings saves
- [x] Settings page success/error feedback for credential validation
- [x] Clean uninstall
- [ ] Fix: live credential validation on Azure OpenAI/Qdrant settings save
- [x] Navigation recommendations (replace 501 stub)
- [ ] Integration tests (at minimum: Playwright smoke for each editor surface)

### Should Have (v1.x)

- [ ] Block subtree transform: propose replacement trees for selected block groups
- [ ] Apply path for template recommendations (not just advisory)
- [ ] Inserter search input detection resilience (abstract away DOM selectors)
- [ ] Pattern API migration plan (move off `__experimentalBlockPatterns` when stable API lands)
- [ ] Warm docs cache on plugin activation for common block types
- [ ] Suggestion undo (restore previous attribute values)
- [ ] Rate limiting / request throttling for LLM calls

### Could Have (v2.0+)

- [ ] Pattern generation: LLM creates new pattern markup from context
- [ ] Pattern promotion: save approved AI output as registered patterns
- [ ] Interactivity API scaffolding: generate viewScriptModule code
- [ ] Dynamic block scaffolding: generate render_callback configurations
- [ ] Audit log UI: DataViews-based history of AI actions
- [ ] Navigation overlay generation
- [ ] Multi-turn conversation (context carryover across recommendation rounds)
- [ ] Batch recommendations (multiple blocks at once)

## Doc Index

| Document | Purpose | Status |
|----------|---------|--------|
| `docs/SOURCE_OF_TRUTH.md` | Definitive project reference: scope, architecture, inventory, roadmap | **Current** |
| `docs/flavor-agent-readme.md` | Architecture details: editor flows, settings, pattern lifecycle | **Current** |
| `docs/wordpress-7.0-gutenberg-22.8-reference.md` | WP 7.0 and Gutenberg 22.8 API changes, new features, deprecations, and plugin impact | **Current** |
| `docs/2026-03-18-cloudflare-ai-search-grounding-assessment.md` | Cloudflare AI Search integration assessment and cache behavior | **Current** |
| `docs/superpowers/specs/2026-03-17-pattern-badge-status-design.md` | Implemented design spec for pattern badge status surface | **Current** (implemented) |
| `CLAUDE.md` | Claude Code project instructions: commands, architecture, gotchas | **Current** |
| `STATUS.md` | Working/stubbed feature inventory and verification log | **Current** |
| `docs/historical/LLM-WordPress-Assistant.md` | Early design: Dispatcher/Generator/Transformer/Executor | **Superseded** |
| `docs/historical/LLM-WordPress-Assistant-Notes.md` | Early design: product shape, milestones, WP7 guardrails | **Superseded** |
| `docs/historical/LLM-WordPress-Phases.md` | Early design: 5-phase roadmap, context schema, approval pipeline | **Superseded** |

## Build and Dev Commands

```bash
# JS
npm install                          # Install JS deps
npm run build                        # Production build -> build/index.js, build/admin.js
npm start                            # Dev build with watch
npm run lint:js                      # ESLint on src/
npm run test:unit -- --runInBand     # Jest unit tests

# PHP
composer install                     # PSR-4 autoloader
vendor/bin/phpunit                   # PHPUnit tests
vendor/bin/phpcs --standard=phpcs.xml.dist inc/ flavor-agent.php  # WPCS lint
```

## Key Technical Decisions

1. **Split recommendation backends**: WordPress AI Client for block recommendations (provider-agnostic, connector-managed), Azure OpenAI for pattern/template ranking (structured scoring). Not a redundancy -- different strengths for different tasks.
2. **No approval pipeline**: Unlike the original vision, recommendations apply immediately on user click. The "approve" step is the click itself. This is simpler and matches how native Inspector controls work.
3. **Inspector injection over sidebar**: Recommendations appear in the native Inspector tabs (Settings, Styles, sub-panels) rather than a separate sidebar. This feels native, not bolted-on.
4. **Vector search for patterns**: Patterns are embedded and stored in Qdrant rather than passed to the LLM as raw text. This scales to hundreds of patterns without hitting token limits.
5. **Cache-only docs grounding**: WordPress docs are not fetched on every recommendation request. Cache is warmed via explicit `search-wordpress-docs` calls or prior queries. This avoids latency on the critical path.
6. **Abilities API is additive**: The REST API remains the primary runtime path. Abilities API registration is a parallel exposure for external agents. Neither depends on the other.
7. **Store is the contract boundary**: All UI components read from `@wordpress/data` selectors. The store thunks handle REST calls, error state, and stale-request rejection. Components never call REST directly.
