# Flavor Agent -- Source of Truth

> Last updated: 2026-04-29
> Version: 0.1.0
> Support floor: WordPress 7.0+, PHP 8.0+

## Documentation Backbone

See `docs/README.md` for the documentation backbone, reading order, ownership, and update contract.

## What This Plugin Is

Flavor Agent is a WordPress plugin that adds AI-assisted recommendations and editorial scaffolding across the native Gutenberg editor and relevant wp-admin screens. It should feel like Gutenberg and wp-admin became smarter, not like a separate AI application was bolted on. It does not insert or mutate content automatically without a bounded UI path -- it recommends, the user reviews where needed, and the user decides.

Applied AI changes are now tracked through the shared activity system and can be reversed from the UI when the live document still matches the recorded post-apply state. Activity persistence now uses server-backed storage, with editor-scoped hydration and `sessionStorage` retained only as a cache/fallback for the current editing surface.

The activity system now also has a first dedicated wp-admin audit page at `Settings > AI Activity`, built with WordPress-native `DataViews` and `DataForm` primitives rather than a plugin-only table shell.

When a recommendation surface is in scope but unavailable, the native UI now stays visible long enough to explain whether the missing dependency belongs in core `Settings > Connectors` or plugin-owned `Settings > Flavor Agent`, including the inserter-backed pattern surface.

Eight first-party recommendation surfaces exist today:

1. **Block Inspector** -- Per-block setting and style suggestions injected into the native Inspector, with the main block panel owning apply and delegated native sub-panels acting as passive mirrors; selected `core/navigation` blocks also get embedded navigation guidance.
2. **Pattern Inserter** -- Vector-similarity pattern recommendations surfaced through a Flavor Agent shelf inside the native block inserter; intentionally ranking/browse-only.
3. **Content Recommendations** -- Post/page document panel for draft, edit, and critique suggestions; editorial-only and no auto-apply path.
4. **Template Recommendations** -- Review-before-apply template-part and pattern composition suggestions for Site Editor templates.
5. **Template Part Recommender** -- Template-part-scoped block and pattern suggestions in the Site Editor.
6. **Global Styles Recommender** -- Site Editor Global Styles suggestions bounded to native theme-supported style paths and registered style variations.
7. **Style Book Recommender** -- Per-block style suggestions in the Style Book panel, bounded to block-scoped style paths and theme-backed values.
8. **Navigation Recommendations** -- Advisory navigation structure, overlay, and accessibility guidance nested inside block recommendations for selected `core/navigation` blocks.

The plugin also ships one first-party admin audit surface at `Settings > AI Activity`.

A parallel programmatic surface -- **WordPress Abilities API** -- exposes the shipped recommendation, helper, and diagnostic contracts as structured tool definitions for external AI agents on the supported WordPress 7.0+ floor.

## Repository Layout

Top-level orientation only — for the maintained file-by-file breakdown, read `CLAUDE.md` (architecture and key integration points), `docs/features/` (per-surface deep dives), `docs/reference/abilities-and-routes.md` (REST + Abilities contract map), and `docs/reference/shared-internals.md` (cross-cutting store and component utilities).

```
flavor-agent/
  flavor-agent.php          Bootstrap, lifecycle hooks, REST + Abilities registration, editor/admin asset enqueue
  uninstall.php             Cleanup for legacy/provider/vector/docs options, sync lock, and cron hooks
  inc/                      PHP backend (PSR-4 namespace FlavorAgent\)
    Abilities/              Surface, infra, and helper abilities + registration
    Activity/               Server-backed activity persistence, permissions, serialization
    Admin/                  Settings page + AI Activity admin app registration
   AzureOpenAI/            Legacy chat Responses facade for Connectors-owned text generation
   Embeddings/             Workers AI embedding client, embedding signatures, shared HTTP helpers, and Qdrant vector DB
    Cloudflare/             AI Search docs grounding, Workers AI, private pattern AI Search
    Context/                Per-surface context collectors and validators
    Guidelines/             Guidelines storage adapters (core/legacy)
    LLM/                    Prompt assembly, ResponseSchema, ChatClient, contrast validator
    MCP/                    Dedicated MCP server bootstrap
    OpenAI/                 Provider selection and connector-aware credential resolution
    Patterns/               Pattern index, retrieval backends, sync, fingerprinting, cron
    REST/                   Editor-facing REST routes (activity + sync-patterns)
    Support/                Cross-cutting helpers (signatures, normalizers, traces)
  src/                      Editor + admin JavaScript (built by @wordpress/scripts)
    admin/                  Settings page + AI Activity admin app
    components/             Shared recommendation, activity, status, and capability UI
    content/                Post/page content recommender
    context/                Editor-side block, theme-settings, theme-tokens collectors
    global-styles/          Global Styles recommender
    inspector/              Block Inspector injector + recommendation panels
    patterns/               Pattern recommender, inserter shelf, badge, compat
    review/                 Review-state adapters
    store/                  @wordpress/data store: actions, reducers, undo, abilities-client, toasts
    style-book/             Style Book recommender
    style-surfaces/         Shared Global Styles + Style Book helpers
    template-parts/         Template-part recommender
    templates/              Template recommender
    test-utils/             React 18 test harness + WP component mocks
    utils/                  Operation catalogs, validators, signatures, action helpers
  tests/
    phpunit/                PHP unit tests (one per major class)
    e2e/                    Playwright suites (Playground + WP 7.0 Site Editor)
  scripts/                  Build, verify, doc-drift, e2e bootstrap, sync-skills helpers
  docs/                     Doc backbone + features + reference + audits + validation
  CLAUDE.md                 Project instructions for Claude Code (canonical architecture map)
  AGENTS.md                 Workspace guidance for agents and contributors
  STATUS.md                 Working feature inventory and verification log
```

Generated assets (`build/`, `dist/`, `vendor/`, `node_modules/`) and per-file inventory are intentionally omitted — they drift faster than this doc and are owned by `CLAUDE.md` plus the per-feature docs.

## External Services

| Service                          | Purpose                                                           | Required For                                                                                                         | Config Options                                                                                                                   |
| -------------------------------- | ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| WordPress AI Client + Connectors | Primary chat runtime and connector registry                       | Block/content/template/template-part/navigation/style requests and pattern reranking                                 | Core-managed in `Settings > Connectors`                                                                                          |
| Provider selection               | Hidden compatibility option                                       | Overwritten with Cloudflare Workers AI on settings save; old saved values do not select chat or embeddings          | `flavor_agent_openai_provider`                                                                                                   |
| Cloudflare Workers AI Embeddings | Pattern embedding                                                 | Pattern index + pattern recommendations when the Qdrant backend needs plugin-owned embeddings                        | `flavor_agent_cloudflare_workers_ai_account_id`, `_api_token`, `_embedding_model`                                                |
| Qdrant                           | Vector similarity search                                          | Pattern recommendations                                                                                              | `flavor_agent_qdrant_url`, `_key`                                                                                                |
| Private Cloudflare AI Search     | Managed pattern indexing and retrieval                            | Pattern recommendations when the Cloudflare AI Search pattern backend is selected                                    | `flavor_agent_cloudflare_pattern_ai_search_account_id`, `_namespace`, `_instance_id`, `_api_token`                               |
| Cloudflare AI Search             | WordPress dev-doc grounding                                       | Supplemental doc context for block, pattern, template, template-part, navigation, Global Styles, and Style Book recs | Managed public search endpoint plus `flavor_agent_cloudflare_ai_search_max_results`                                              |

The plugin works in degraded mode without any services configured. Each surface gracefully disables when its required backends are absent.

Runtime embeddings use Cloudflare Workers AI. Older OpenAI Native, Azure OpenAI, or connector-backed provider option values are not rendered as embedding choices, and the settings form overwrites the provider option with `cloudflare_workers_ai` on save.

## Feature Inventory

### Implemented and Working

#### Block Inspector Recommendations

- **Trigger:** User selects a block, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Block name, attributes, styles, supports, inspector panels, editing mode, content/config attributes, child count, structural identity (role, location, position), sibling blocks, ancestor chain, theme tokens, WordPress docs guidance (cache-only).
- **LLM:** `ChatClient::chat()`; uses the WordPress AI Client runtime configured in `Settings > Connectors`. Saved provider values from older settings screens do not pin chat.
- **Response:** Parsed into `settings`, `styles`, `block` suggestion groups. Each suggestion has label, description, panel, confidence (0-1), and `attributeUpdates`. Block-lane structural suggestions may also carry PHP-validator-approved `operations`, sanitized `proposedOperations`, and standardized `rejectedOperations` using the `BlockOperationValidator` catalog.
- **UI:** The main panel follows the shared full-surface shell: scope/freshness, prompt composer, featured recommendation, `Apply now` lane, `Manual ideas` advisory section, and recent activity. Settings and style suggestions are executable only from that main panel; delegated native Inspector sub-panels mirror the same result passively. Selected `core/navigation` blocks append a nested advisory-only navigation subsection.
- **Apply:** Safe local block updates remain one-click. Executable updates are limited to declared content/config attributes, supported style channels, supported visibility/binding metadata, and registered style variations; when a server execution contract is partial, missing local attribute-key lists are filled from the selected block context before undeclared attributes are rejected. Safe deep-merge for allowed `metadata` and `style` keys preserves unrelated existing attributes. Reviewed structural `insert_pattern` and `replace_block_with_pattern` operations run through a separate transactional apply path with rollback, before/after structural signatures, activity metadata, and drift-safe undo. Apply captures before/after snapshots, shows an inline success notice with `Undo`, and writes a structured activity record.
- **Guards:** Content-only blocks limit executable changes to content-safe attributes, may still surface broader advisory block ideas, suppress style projections, and keep `blockVisibility` (boolean and viewport-object forms) within the allowed contract. `lock`, arbitrary `metadata`, and undeclared top-level attributes are rejected. Disabled blocks receive no suggestions. Browser-side structural validation must reproduce the server-approved `catalogVersion`, type, pattern, target, position/action, signature, and expected-target identity before review/apply; disagreements fail closed with `client_server_operation_mismatch`.

#### Pattern Recommendations

- **Trigger:** Passive fetch on editor load; active fetch on inserter search input change (400ms debounce).
- **Pipeline:** Build query text -> cache-backed WordPress docs grounding via Cloudflare AI Search -> selected pattern retrieval backend -> LLM rerank via the active responses backend -> filter scores below the backend-specific ranking threshold -> return up to the saved max result count (default `8`, capped at `12`). Missing or empty `visiblePatternNames` returns an empty list before retrieval, docs, or ranker calls. The Qdrant backend uses Cloudflare Workers AI embeddings plus two-pass Qdrant search (semantic + structural). The Cloudflare AI Search backend sends query text and the `visiblePatternNames` filter to the private pattern AI Search instance and does not call `EmbeddingClient` or `QdrantClient`.
- **Synced/user patterns:** The index includes published `wp_block` patterns across `synced`, `partial`, and `unsynced` states as `core/block/{id}` candidates. Request-time ranking treats synced/user payloads from either backend as untrusted and rehydrates each candidate through current published status and `read_post` access before ranker input or response output.
- **Inserter integration:** The shelf stays local to Flavor Agent. It uses the current allowed-pattern selector to show only patterns Gutenberg already exposes for the active insertion root, then dispatches core block insertion from the shelf rather than patching the native registry.
- **Badge:** Inserter toggle badge shows recommendation count (ready), loading pulse, or error indicator. Ready count and tooltip are derived from recommendations that match the current inserter root's allowed patterns, not from a raw store badge cache. Toggle discovery centralized in `compat.findInserterToggle`.
- **Scoping:** `visiblePatternNames` derived from inserter root for context-appropriate results via `compat.getAllowedPatterns`.
- **Model:** This is intentionally a ranking/browse-only surface. Flavor Agent owns a local shelf and badge inside the native inserter, but does not add its own review, undo, or activity contract.

#### Template Recommendations

- **Trigger:** User editing a `wp_template` in Site Editor, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Template ref, type, assigned template-part slots, empty areas, available (unassigned) template parts, top-level block tree, executable top-level insertion anchors, candidate patterns (typed + generic, filtered by client-side `visiblePatternNames` when available, max 30), template-global `visiblePatternNames` when available, theme tokens, WordPress docs guidance.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Max 3 suggestions, each with validated structured operations. Supported executable operations are `assign_template_part`, `replace_template_part`, and `insert_pattern`, where template pattern insertion may now include `start`, `end`, `before_block_path`, or `after_block_path` placement metadata.
- **UI:** Entity mentions in text become clickable links: template-part slugs/areas highlight blocks in canvas; pattern names open the inserter pre-filtered. The panel uses the shared full-surface shell: scope/freshness, prompt composer, featured recommendation, `Review first` / `Manual ideas` lanes, a lower review panel, and recent activity.
- **Apply:** Deterministic client executor validates template suggestions against a working-state copy before mutating. Template-part assignment/replacement updates existing `core/template-part` blocks, and pattern insertion resolves only validated `start`, `end`, `before_block_path`, or `after_block_path` anchors before inserting parsed pattern blocks. Implicit insertions are rejected. Applied operations persist stable undo locators plus recorded post-apply snapshots for inserted subtrees.
- **Guardrails:** Free-form template tree rewrites are intentionally out of scope. If any operation fails validation, the entire apply is rejected before mutation.

#### Template Part Recommendations

- **Trigger:** User editing a `wp_template_part` in Site Editor, types optional prompt, clicks "Get Suggestions".
- **Context sent:** Template-part ref, slug, inferred area, structural summaries, executable operation targets, executable insertion anchors, structural constraints, candidate patterns (filtered by request `visiblePatternNames` when available), theme tokens, and cache-backed WordPress docs guidance.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Max 3 suggestions, each with `label`, `description`, `blockHints`, `patternSuggestions`, optional validated `operations`, and `explanation`. Supported executable operations are `insert_pattern`, `replace_block_with_pattern`, and `remove_block`, with bounded placement at `start`, `end`, `before_block_path`, or `after_block_path` where applicable.
- **UI:** `src/template-parts/TemplatePartRecommender.js` renders a store-backed document settings panel with the same `Review first` / `Manual ideas` split, advisory links, shared lower review panel, shared status notices, and recent activity. Non-deterministic ideas stay visible through the same advisory shell instead of disappearing.
- **Apply:** Deterministic client executor validates template-part operations before mutation, inserts parsed pattern blocks at the exact resolved root/path location, supports bounded targeted replacement/removal, and records stable undo metadata plus post-apply snapshots for refresh-safe undo.
- **Guardrails:** Unsupported or ambiguous suggestions stay advisory-only. There is still no free-form rewrite path; all executable operations must resolve to explicit validated placements and block-path targets before mutation.

#### Global Styles Recommendations

- **Trigger:** User opens the Site Editor Styles sidebar, types an optional prompt, and clicks "Get Style Suggestions".
- **Context sent:** Resolved Global Styles scope descriptor, current user config, current merged config, available theme style variations, theme-token source diagnostics, theme tokens, and supported site-level style paths.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`.
- **Response:** Up to 4 suggestions, each with `label`, `description`, `category`, `tone`, optional validated `operations`, and `explanation`. Supported executable operations are `set_styles` (global-styles surface only) and `set_theme_variation`.
- **UI:** `src/global-styles/GlobalStylesRecommender.js` is portal-first in the native Global Styles sidebar and falls back to a document settings panel when the sidebar slot is missing. It uses the shared full-surface shell with `Review first` / `Manual ideas`, a lower review panel, and scoped recent activity.
- **Apply:** Deterministic client helpers validate supported paths, preset requirements, and still-available theme variations before updating the active `root/globalStyles` entity through `editEntityRecord()`. Applied changes persist before/after user config plus operation metadata for scoped undo.
- **Guardrails:** Raw CSS, `customCSS`, unsupported style paths, arbitrary preset-less values where a preset family is required, width/height transforms, and pseudo-element-only operations remain out of scope for the first Epic 3 slice.

#### Style Book Recommendations

- **Trigger:** User opens the Style Book panel for a block type, types an optional prompt, and clicks "Get Style Suggestions".
- **Context sent:** Resolved scope descriptor with `surface: "style-book"`, target block name and title, current block-scoped styles, merged config, theme-token source diagnostics, and theme tokens.
- **LLM:** Provider-selected responses backend via `ResponsesClient::rank()`, using the same `StylePrompt` and `StyleAbilities::recommend_style()` as Global Styles with surface-aware operation rules.
- **Response:** Up to 4 suggestions with validated `operations`. Supported executable operations are `set_block_styles` (style-book surface only).
- **UI:** `src/style-book/StyleBookRecommender.js` is portal-first in the Style Book panel using `src/style-book/dom.js` for target resolution. It uses the same shared full-surface shell with `Review first` / `Manual ideas`, a lower review panel, and scoped recent activity.
- **Apply:** Deterministic client helpers validate block-scoped style paths and preset requirements before updating the active `root/globalStyles` entity. Applied changes persist before/after config plus operation metadata for scoped undo.
- **Guardrails:** `set_styles` is rejected on the style-book surface. `set_theme_variation` is also rejected on the style-book surface. `set_block_styles.blockName` must exactly match the target block in scope. Same raw CSS, `customCSS`, and unsupported-path guardrails as Global Styles.

#### Content Recommendations

- **Surface:** `src/content/ContentRecommender.js` mounts a post/page `PluginDocumentSettingPanel` titled `Content Recommendations`. The same contract remains available through REST and Abilities for external callers.
- **Trigger:** The user chooses `Draft`, `Edit`, or `Critique`, enters an optional prompt, and requests a recommendation. External callers can post the same `mode`, optional `prompt`, optional `voiceProfile`, and optional `postContext` to the endpoint; positive `postContext.postId` enables server-rendered current-post context after a per-post edit check.
- **LLM:** `ChatClient::chat()` using `WritingPrompt` for Henry-voice system prompt assembly and response parsing. When an authorized positive `postId` is present, `PostContentRenderer` renders current-post blocks server-side and `WritingPrompt` receives that rendered text under `Existing draft`; absent or `0` keeps the text fallback path.
- **Response:** `mode`, `title`, `summary`, `content`, `notes[]`, and `issues[]`. Editorial-only — no auto-apply path.
- **Guards:** `edit` and `critique` modes require `postContext.content`. `draft` mode accepts a prompt, title, or other working context. Positive `postContext.postId` requires `current_user_can( 'edit_post', $post_id )`; no block render callbacks run without a positive post ID.

#### Shared Inline Review Model

- **Sequence:** The recommendation surfaces now share one vocabulary and shell order, but not one mutation contract.
- **Normalized states:** The shared editor-side vocabulary is `idle`, `loading`, `advisory-ready`, `preview-ready`, `applying`, `success`, `undoing`, and `error`.
- **Surface mapping:** The main block panel is direct-apply for safe local block updates. Template, template-part, Global Styles, and Style Book are review-before-apply surfaces. Navigation is an advisory-only nested subsection. Delegated block Inspector sub-panels are passive mirrors of the current block result. Pattern recommendations remain ranking/browse-only in the native inserter.
- **Shared UI:** `SurfaceScopeBar`, `RecommendationHero`, `AIStatusNotice`, `AIAdvisorySection`, and `AIReviewSection` now own the common scope/status/review framing, while `AIActivitySection` remains the shared undo/history block on executable surfaces.
- **Notes future-proofing:** `src/review/notes-adapter.js` is a shape-only adapter for future Notes/comment projection. It normalizes shared review evidence but is not wired into runtime UI and does not depend on unstable editor APIs.

#### AI Activity And Undo

- **Schema:** Block, template, template-part, Global Styles, and Style Book apply actions share one activity shape: surface, target identifiers, suggestion label, before/after state, prompt/reference metadata, timestamp, and undo status. The admin audit lane also stores scoped read-only `request_diagnostic` rows for successful and failed recommendation requests across content, pattern, navigation, block, template, template-part, Global Styles, and Style Book when a document scope exists; signature-only freshness probes do not create rows.
- **Persistence:** Activity records are persisted through the server-backed activity repository when available and are hydrated back into the editor-side storage adapter keyed by the current post, template, template-part, or Global Styles scope reference. The same repository now also supports recent unscoped/admin reads for privileged users and projects filterable admin columns for provider/model/path/ability metadata so global audit queries do not need to decode every historical `request_json` payload to filter by provenance. Template, template-part, and Global Styles activities use schema-versioned persisted metadata; legacy clientId-only entries load as undo unavailable with a clear reason.
- **UI:** The Block Inspector, Template Recommendations panel, Template Part panel, Global Styles panel, and Style Book panel share the same status vocabulary, recent-activity shell, and inline undo treatment. Navigation still shares only the advisory/status framing and remains non-executable, while pattern recommendations remain browse-only. A first dedicated admin page at `Settings > AI Activity` exposes the recent server-backed timeline across supported executable surfaces plus scoped review-only diagnostics through a `DataViews` activity feed and a read-only `DataForm` details panel.
- **Undo rules:** Only the most recent AI action is auto-undoable. Block undo resolves by `clientId` first and allows a moved block when the block name and recorded post-apply attributes still match; path fallback remains guarded by block name and attribute snapshots. Template assignment/replacement undo resolves the current block from a stable area/slug locator, template/template-part inserted-pattern undo only stays available while the recorded inserted subtree still exactly matches the persisted post-apply snapshot, Global Styles and Style Book undo only stays available while the active `root/globalStyles` entity still matches the recorded post-apply user config. The admin audit view applies the same ordered-tail rule when it marks older entries as blocked by newer still-applied actions.

#### Admin Activity Page

- **Location:** `Settings > AI Activity`
- **Permission:** `manage_options`
- **Bundle:** `inc/Admin/ActivityPage.php` + `src/admin/activity-log.js`
- **Behavior:** Uses `@wordpress/dataviews/wp` with the `activity` layout as the default feed, persisted/resettable view preferences, grouped summary cards, a read-only `DataForm` details sidebar for diagnostics, request metadata, undo state, and before/after state summaries, links back to affected entities, plugin settings, and core Connectors, and separate summary buckets for review-only rows, blocked undo, and failed/unavailable entries.
- **Scope:** Covers recent server-backed block, template, template-part, Global Styles, and Style Book actions plus scoped read-only `request_diagnostic` rows from recommendation requests across content, pattern, navigation, block, template, template-part, Global Styles, and Style Book; it is the first audit surface, not the final observability product.

#### Pattern Index Lifecycle

- **Sync:** Diffs current registered patterns plus published synced/user `wp_block` patterns normalized as `core/block/{id}` against the selected backend using per-pattern fingerprints. Qdrant sync embeds changed patterns through `EmbeddingClient`, which chunks Workers AI requests at 32 inputs, then upserts/deletes Qdrant points. Cloudflare AI Search sync uploads changed public-safe pattern markdown items with stable IDs and `wait_for_completion=true`, then deletes stale remote item IDs. Both paths detect config changes for full reindex.
- **Triggers:** Plugin activation, theme switch, plugin activate/deactivate, upgrades, settings changes, and synced-pattern save/delete/trash/untrash events.
- **Scheduling:** WP cron with 300s cooldown and transient lock.
- **Admin UI:** Manual sync button on settings page with status display.

#### WordPress Abilities API (available on supported WordPress 7.0+ installs)

20 abilities are registered with full JSON Schema input/output definitions, grouped by category. The full contract — handlers, permissions, schemas, and behavior annotations (`readonly`/`destructive`/`idempotent`) — lives in [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

- **Block** (`BlockAbilities`): `recommend-block`, `introspect-block`, `list-allowed-blocks`
- **Content** (`ContentAbilities`): `recommend-content`
- **Pattern** (`PatternAbilities`): `recommend-patterns`, `list-patterns`, `get-pattern`, `list-synced-patterns`, `get-synced-pattern`
- **Template** (`TemplateAbilities`): `recommend-template`, `recommend-template-part`, `list-template-parts`
- **Navigation** (`NavigationAbilities`): `recommend-navigation`
- **Style** (`StyleAbilities`): `recommend-style`
- **Docs** (`WordPressDocsAbilities`): `search-wordpress-docs` (`manage_options`)
- **Infra** (`InfraAbilities`): `get-active-theme`, `get-theme-presets`, `get-theme-styles`, `get-theme-tokens`, `check-status`

#### Developer Docs

- Explicit search via `search-wordpress-docs` ability (`manage_options` only).
- Recommendation-time grounding for block, pattern, template, template-part, navigation, Global Styles, and Style Book suggestions is cache-only and non-blocking. Exact-query cache (6h TTL) is authoritative; warmed entity cache (12h TTL) is fallback.
- Recommendation-time grounding can also merge a compact WordPress AI/Core roadmap signal from the GitHub project board into the same guidance stream when the opt-in `flavor_agent_enable_core_roadmap_guidance` filter is enabled and a cached roadmap warm is available. Roadmap chunks are cached separately, tagged as `core-roadmap`, capped so developer-doc chunks remain in the first prompt window, and can be skipped per request with `skipCoreRoadmapGuidance`.
- Strict source filtering: only `developer.wordpress.org` chunks accepted. URL trust validation (HTTPS, no credentials, sourceKey/URL identity checks). Source keys with an `ai-search/<instanceId>/` prefix are now recognized alongside the plain `developer.wordpress.org/` prefix.
- Developer Docs prewarm: scheduling fires on plugin activation and normal bootstrap, but the WP-Cron job only seeds the entity cache when the `flavor_agent_cloudflare_ai_search_allow_public_prewarm` opt-in filter returns true; otherwise the built-in public endpoint stays available for user-triggered docs grounding without background prewarm. When prewarm runs, exact entity misses still fall back to prewarmed generic editor/template/template-part guidance families before returning empty. Throttled by source fingerprint and 1-hour cooldown. Admin diagnostics panel shows last prewarm status, timestamp, and warmed/failed counts.

#### REST API

Three REST routes live under `/flavor-agent/v1/`. Recommendation surfaces use WordPress Abilities API contracts instead. Permissions and handler classes are documented in [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

- **2 activity routes** adapt the activity repository: GET/POST `activity` (contextual editor/theme capability; sitewide GET requires `manage_options`) and POST `activity/{id}/undo` (contextual)
- **1 admin route**: POST `sync-patterns` (`manage_options`) — manual pattern reindex

#### Admin Settings

Settings page at `Settings > Flavor Agent` renders six top-level accordion groups with status cards and native Help guidance:

- **AI Model** -- Text-generation readiness from `Settings > Connectors`, the current WordPress AI Client runtime path, and a link to the Connectors screen.
- **Embedding Model** -- Cloudflare Workers AI account/token/model for Flavor Agent semantic features. Reasoning effort remains a backwards-compatible saved option used by the Connectors-routed chat runtime when present, but it is no longer an editable Embedding Model control. Text-generation provider and model readiness belong to `Settings > Connectors`.
- **Patterns** -- Pattern Storage selector, Qdrant URL/key, private Cloudflare AI Search pattern credentials, backend-specific ranking thresholds, max results, and the `Sync Pattern Catalog` status/metrics/manual trigger panel. Pattern Storage is infrastructure, not another AI model choice.
- **Developer Docs** -- Built-in public Cloudflare AI Search developer-doc grounding, source status, max result count, runtime grounding diagnostics, and docs prewarm diagnostics.
- **Guidelines** -- Site/copy/image/additional guidelines, block-specific notes for content-role blocks, and JSON import/export tooling. Runtime recommendations read the core/Gutenberg Guidelines store first when the `wp_guideline` model is present, with legacy Flavor Agent options retained as migration/admin tooling and fallback storage.
- **Experimental Features** -- Block structural action opt-in controls.

Block, content, template, template-part, navigation, Global Styles, Style Book, and pattern reranking requests use the WordPress AI Client and `Settings > Connectors` for chat. Qdrant pattern storage uses the Cloudflare Workers AI Embedding Model. Saved provider values from older settings screens do not pin chat or select embeddings. The Cloudflare AI Search pattern backend uses Cloudflare-managed embeddings/indexing inside a private AI Search instance instead.

The legacy-named reasoning effort setting is applied to Connectors-routed chat only through known provider model custom options: Codex `reasoningEffort` and OpenAI `reasoning.effort`. Anthropic remains unmapped until its provider plugin publishes the accepted reasoning/thinking payload contract.

When the Cloudflare Workers AI account ID, API token, or embedding model changes and all three fields are present, the settings save flow validates the Workers AI embedding model and preserves the previous values if validation fails.
When the Qdrant URL or key changes and both fields are present, the settings save flow validates the `/collections` endpoint and preserves the previous values if validation fails.
When private Cloudflare AI Search pattern account ID, namespace, instance ID, or API token changes and all four fields are present, the settings save flow validates a filtered search probe and preserves the previous values if validation fails.
Flavor Agent uses its built-in public Cloudflare AI Search `/search` endpoint for docs grounding, so site owners do not configure Cloudflare credentials for Developer Docs.
Unchanged or partial private backend credential submissions skip remote validation.
Successful saves still use the standard Settings API notice flow, and failed Cloudflare Workers AI, Qdrant, or Cloudflare AI Search validation surfaces a plugin-scoped error notice on the same screen.

### Not Yet Built (From Original Vision)

Earlier planning iterations described a broader 5-phase roadmap. Since then, the current codebase has shipped the later safety and hardening work that now exists in-tree: template review-confirm-apply, AI activity + undo, the pattern compatibility adapter, docs prewarm, and deeper Playwright smoke coverage. The larger generative/editor-transform items below still remain out of scope. Some of them, especially Interactivity scaffolding, are future-facing ideas rather than current shipping gaps because the plugin still operates entirely in editor/admin surfaces.

| Feature                       | Original Phase | Current Status        | Notes                                                                                                                                                                                      |
| ----------------------------- | -------------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Block subtree transforms      | Phase 2        | Not built             | Propose replacement block trees for selected blocks                                                                                                                                        |
| Pattern generation            | Phase 3        | Not built             | LLM generates new pattern markup from context                                                                                                                                              |
| Pattern promotion             | Phase 3        | Not built             | Save approved AI output as plugin-managed registered patterns                                                                                                                              |
| Interactivity API scaffolding | Phase 4        | Not built             | Future-facing only; the current plugin has no front-end runtime surface that requires `viewScriptModule` or Interactivity API code                                                         |
| Navigation overlay generation | Phase 4        | Not built             | Create mobile nav overlays as template parts                                                                                                                                               |
| Approval pipeline UI          | Phase 1-3      | Not built             | Visual approve/reject flow with diff preview before insertion                                                                                                                              |
| Audit/revision log UI         | Phase 5        | Initial slice shipped | `Settings > AI Activity` now provides a DataViews/DataForm timeline with structured state summaries for recent actions; richer row actions/discovery and broader observability remain open |
| Dynamic block scaffolding     | Phase 4        | Not built             | Generate `render_callback` + dynamic block configs                                                                                                                                         |
| Pattern-to-file promotion     | Phase 3        | Not built             | Export approved patterns to PHP files in `patterns/` directory                                                                                                                             |

### Known Issues and Gaps

1. **`composer lint:php`**: Green across production code, but `tests/phpunit/bootstrap.php` is intentionally excluded from WPCS due to its multi-namespace stub harness.
2. **JS toolchain support is now explicit**: `.nvmrc` defaults to Node 24 / npm 11, and the repo also supports the previously verified Node 20 / npm 10 toolchain through the `package.json` engine range under `.npmrc`'s `engine-strict` gate.
3. **Inserter DOM discovery is still markup-coupled (mitigated)**: `inserter-dom.js` centralizes container (5), search-input (4), and toolbar toggle selectors and now fails closed to `null` when the expected editor structure is absent, so caller cleanup is isolated to one module.
4. **Pattern settings compatibility is explicit and fail-closed**: `pattern-settings.js` probes future stable `blockPatterns` / `blockPatternCategories` / `getAllowedPatterns` paths when present, but current Gutenberg trunk still exposes `__experimentalAdditional*`, `__experimental*`, and `__experimentalGetAllowedPatterns` as the live baseline. The adapter returns an empty scoped result plus diagnostics instead of widening to an `all-patterns-fallback` result when contextual selectors are unavailable.
5. **Theme-token source resolution is now merged rather than over-promoted**: `theme-settings.js` isolates raw settings reads and now uses stable sources when available while filling only missing branches from `__experimentalFeatures`. Flavor Agent still targets WordPress 7.0+, so block attribute role detection reads only the stable `role` key and no longer preserves deprecated `__experimentalRole` compatibility.
6. **Browser coverage is split across two harnesses**: Playground remains the fast `6.9.4` smoke path because the current Playground 7.0 beta editor runtime breaks before plugin bootstrap, while a dedicated Docker-backed WordPress `7.0` Site Editor harness owns refresh/drift-sensitive flows. The default `npm run test:e2e` command now aggregates both harnesses and the checked-in smoke suite now covers navigation plus `wp_template_part`, but the WP 7.0 half still requires Docker on PATH. The harness pins a pre-release image via `FLAVOR_AGENT_WP70_BASE_IMAGE`; the canonical tag and override instructions live in `docs/reference/local-environment-setup.md`.
7. **Activity history is still only a first audit slice**: The new `Settings > AI Activity` page provides a recent DataViews/DataForm timeline with request diagnostics and structured before/after state summaries for privileged users, but there are still no abilities-backed row actions/discovery layer and no broader observability workflow beyond the stored timeline.
8. **Uninstall cleanup is narrower than the full runtime footprint**: `uninstall.php` currently removes selected legacy/provider/vector/docs options, clears the pattern/docs warm hooks, and deletes the sync lock transient. It does not drop the server-backed activity table or clear newer activity schema/backfill, guidelines, OpenAI Native, pattern tuning, docs runtime, or core-roadmap cache/lock options, so the old "clean uninstall" claim is not accurate for the live tree.
9. **Provider-backed verification is still environment-dependent**: A fresh Azure-backed recommendation pass was recorded on 2026-04-04 and captured in `STATUS.md`, but future live verification still depends on the configured Connectors chat runtime plus plugin-owned embedding credentials and should be rerun whenever those paths change.

### Current Open Backlog

- Deepen the new admin activity page into a richer audit/observability surface with broader diagnostics and a cleaner action/discovery layer.
- Swap the Docker-backed WP 7.0 browser harness from the beta image to the official stable `7.0` image once it exists, and keep Docker available in environments that run that harness.
- Revisit navigation apply only if a bounded previewable/undoable executor becomes its own tracked post-v1 milestone.
- Keep Interactivity API work in the future backlog, not the current remediation backlog, until the plugin grows a front-end runtime surface.
- Decide whether uninstall should drop the activity table and newer plugin-owned runtime/options, then either implement that cleanup or keep the narrower-retention behavior documented intentionally.

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
     -> flavor-agent/recommend-block ability
        -> BlockAbilities::recommend_block()
           -> ServerCollector::introspect_block_type() (server enrichment)
           -> AISearchClient::maybe_search_with_cache_fallbacks() (docs grounding)
           -> Prompt::build_system() + Prompt::build_user()
           -> ChatClient::chat() (WordPress AI Client / Settings > Connectors)
           -> Prompt::parse_response()
           -> Prompt::enforce_block_context_rules()
        <- JSON response: { settings, styles, block, explanation }
  -> store: SET_BLOCK_RECOMMENDATIONS
  -> UI: main block panel lanes + passive delegated SuggestionChips mirrors
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
     -> flavor-agent/recommend-patterns ability
        -> PatternAbilities::recommend_patterns()
            -> PatternIndex: check state (ready/stale/error)
            -> selected backend corpus: registered patterns + published synced/user wp_block patterns as core/block/{id}
            -> Qdrant backend: EmbeddingClient::embed(query) + QdrantClient::search() x2 (semantic + structural)
            -> Cloudflare AI Search backend: PatternSearchClient::search_patterns(query, visiblePatternNames)
           -> Dedupe/normalize, take top candidates
            -> ResponsesClient::rank(instructions, candidates)
            -> Parse ranking, apply backend-specific threshold, rehydrate synced/user payloads through published status/read_post access
        <- JSON response: [{ name, score, reason, ... }]
  -> store: SET_PATTERN_RECS + setPatternStatus('ready')
  -> PatternRecommender.js matches allowed patterns for the current inserter root
  -> UI: local shelf + inserter badge
  -> InserterBadge renders count/loading/error via portal
```

### Template Recommendation Flow

```
User editing wp_template in Site Editor
  -> TemplateRecommender.js renders PluginDocumentSettingPanel
  -> User clicks "Get Suggestions"
   -> store thunk: fetchTemplateRecommendations(input)
      -> flavor-agent/recommend-template ability
         -> TemplateAbilities::recommend_template()
            -> ServerCollector::for_template(ref, type, visiblePatternNames)
               -> Walks parsed blocks for template-part slots
               -> Collects available parts, empty areas, candidate patterns (filtered by visiblePatternNames)
           -> AISearchClient::maybe_search_with_cache_fallbacks()
           -> TemplatePrompt::build_system() + build_user()
           -> ResponsesClient::rank(instructions, input)
           -> TemplatePrompt::parse_response() (validates against context, normalizes executable operations)
        <- JSON response: { suggestions, explanation }
  -> store: SET_TEMPLATE_RECS
  -> UI: compact scope/composer shell + linked explanation + `Review first` / `Manual ideas` lanes + review state; stale results use a refresh `RecommendationHero`
     -> Template-part links -> selectBlockBySlugOrArea()
     -> Pattern links -> openInserterForPattern()
  -> User opens review on an executable suggestion and clicks "Confirm Apply"
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
     -> flavor-agent/recommend-navigation ability
        -> NavigationAbilities::recommend_navigation(input)
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
     -> flavor-agent/recommend-template-part ability
        -> TemplateAbilities::recommend_template_part()
           -> ServerCollector::for_template_part(ref, visiblePatternNames)
           -> AISearchClient::maybe_search_with_cache_fallbacks()
           -> TemplatePartPrompt::build_system() + build_user()
           -> ResponsesClient::rank(instructions, input) via WordPress AI Client / Connectors
           -> TemplatePartPrompt::parse_response() (validates block hints, pattern names, placements)
        <- JSON response: { suggestions, explanation }
  -> store saves request/result state for current templatePartRef
  -> UI renders advisory links plus preview-confirm-apply for validated bounded operations
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
     -> flavor-agent/recommend-style ability
        -> StyleAbilities::recommend_style()
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
     -> flavor-agent/recommend-style ability (scope.surface = "style-book")
        -> StyleAbilities::recommend_style()
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

| Suite / area                              | Representative files                                                                                                             | What's Covered                                                                                                                       |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Contract and registration                 | `AgentControllerTest`, `RegistrationTest`, `EditorSurfaceCapabilitiesTest`                                                       | REST wrappers, ability schemas, localized capability payloads, activity endpoints, and route/shape validation                        |
| Block and content abilities               | `BlockAbilitiesTest`, `ContentAbilitiesTest`, `PromptGuidanceTest`, `PromptRulesTest`, `WordPressAIClientTest`, `ChatClientTest` | Block input normalization, editorial content scaffold behavior, prompt guardrails, and Connectors-first chat runtime behavior        |
| Provider and backend configuration        | `ProviderTest`, `SettingsTest`, `EmbeddingBackendValidationTest`, `InfraAbilitiesTest`                                           | Provider compatibility canonicalization, Workers AI embedding validation, Qdrant diagnostics, validation rollback, and effective runtime metadata |
| Pattern pipeline                          | `PatternAbilitiesTest`, `PatternCatalogTest`, `PatternIndexTest`                                                                 | Registry filtering, recommendation pipeline behavior, sync/fingerprint lifecycle, and Qdrant-backed indexing rules                   |
| Template, style, and navigation prompting | `TemplatePromptTest`, `TemplatePartPromptTest`, `StyleAbilitiesTest`, `StylePromptTest`, `NavigationAbilitiesTest`               | Structured operations, bounded placements, style scope rules, variation parsing, and navigation advice parsing                       |
| Activity and persistence                  | `ActivityRepositoryTest`, `ActivityPermissionsTest`                                                                              | Activity create/query/prune, ordered undo eligibility, undo state transitions, and contextual permissions                            |
| Docs grounding                            | `AISearchClientTest`, `DocsGroundingEntityCacheTest`, `DocsPrewarmTest`                                                          | Trusted-source filtering, cache layers, prewarm scheduling, throttling, and diagnostics                                              |
| Shared context collectors                 | `ServerCollectorTest`                                                                                                            | Template/template-part metadata, visible-pattern filtering, and token diagnostics                                                    |
| Guidelines storage and formatting         | `GuidelinesTest`, `CoreRoadmapGuidanceTest`                                                                                      | Editorial guidelines retrieval, migration status, prompt context formatting, and core roadmap signal caching                         |
| Support utilities                         | `MetricsNormalizerTest`, `RankingContractTest`, `ThemeTokenFormatterTest`, `PromptBudgetTest`, `PromptFormattingTest`            | Metrics normalization, ranking contracts, theme token formatting, prompt budget enforcement, and prompt formatting helpers           |
| Admin pages                               | `ActivityPageTest`, `SupportToPanelSyncTest`                                                                                     | Settings page panel sync, activity admin page rendering                                                                              |

### JS (Jest)

Current high-signal coverage map:

| Area                                   | Representative files                                                                                                                                                                                                                                                                                                                                       | What's Covered                                                                                                                             |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Store state and activity orchestration | `src/store/__tests__/activity-history.test.js`, `activity-history-state.test.js`, `store-actions.test.js`, `template-apply-state.test.js`, `pattern-status.test.js`, `navigation-request-state.test.js`                                                                                                                                                    | Request lifecycle, stale-result rejection, activity hydration, undo coordination, and shared request/apply reducer state                   |
| Inspector flows                        | `src/inspector/__tests__/BlockRecommendationsPanel.test.js`, `NavigationRecommendations.test.js`, `InspectorInjector.test.js`, `panel-delegation.test.js`, `SuggestionChips.test.js`, `src/inspector/suggestion-keys.test.js`                                                                                                                              | Panel rendering, capability gating, delegated passive sub-panel behavior, navigation framing, and key generation                           |
| Pattern inserter integration           | `src/patterns/__tests__/PatternRecommender.test.js`, `InserterBadge.test.js`, `compat.test.js`, `recommendation-utils.test.js`, `find-inserter-search-input.test.js`, `inserter-badge-state.test.js`                                                                                                                                                       | Inserter DOM discovery, compat negotiation, local shelf rendering, badge state, and fetcher lifecycle                                      |
| Template and style surfaces            | `src/templates/__tests__/TemplateRecommender.test.js`, `template-recommender-helpers.test.js`, `src/template-parts/__tests__/TemplatePartRecommender.test.js`, `src/global-styles/__tests__/GlobalStylesRecommender.test.js`, `src/style-book/__tests__/StyleBookRecommender.test.js`                                                                      | Site Editor rendering, suggestion lifecycle, operation helpers, scope resolution, and panel portal behavior                                |
| Shared UI composition                  | `src/components/__tests__/AIStatusNotice.test.js`, `AIAdvisorySection.test.js`, `AIReviewSection.test.js`, `AIActivitySection.test.js`, `ActivitySessionBootstrap.test.js`, `RecommendationHero.test.js`, `RecommendationLane.test.js`, `SurfaceComposer.test.js`, `SurfacePanelIntro.test.js`, `SurfaceScopeBar.test.js`                                  | Shared review model, status framing, featured/grouped presentation, scope UI, and activity bootstrap behavior                              |
| Utility modules                        | `src/utils/__tests__/editor-entity-contracts.test.js`, `editor-context-metadata.test.js`, `format-count.test.js`, `style-design-semantics.test.js`, `style-operations.test.js`, `template-actions.test.js`, `template-part-areas.test.js`, `template-types.test.js`, `visible-patterns.test.js`, `capability-flags.test.js`, `structural-identity.test.js` | Entity contracts, editor metadata summaries, formatting helpers, design semantics, deterministic apply/undo rules, and structural analysis |
| Admin audit page                       | `src/admin/__tests__/activity-log.test.js`, `activity-log-utils.test.js`                                                                                                                                                                                                                                                                                   | DataViews rendering, persisted admin views, summary cards, filters, and entity/settings link generation                                    |

## Definition of "Complete" (v1.0)

Based on the original vision and current trajectory, Flavor Agent v1.0 should satisfy:

### Must Have (v1.0)

- [x] Block Inspector recommendations with per-block loading/error state
- [x] Content-only and disabled block guards
- [x] Pattern recommendations via vector search + LLM ranking
- [x] Native inserter integration (local shelf, badge)
- [x] Template composition panel with review-confirm-apply for validated operations
- [x] Template-part recommendations panel
- [x] Global Styles recommendations panel with review-confirm-apply for bounded site-level style changes
- [x] Style Book recommendations panel with review-confirm-apply for per-block style changes
- [x] Undoable block/template/template-part/Global Styles/Style Book AI actions with server-backed activity persistence plus editor-scoped hydration/cache fallback
- [x] Pattern index lifecycle (auto-sync, background cron, diff-based updates for registered and published synced/user patterns)
- [x] WordPress Abilities API integration (all working abilities)
- [x] WordPress docs grounding (cache-based)
- [x] Admin settings page with backend configuration
- [x] Legacy Cloudflare AI Search credential validation on changed settings saves
- [x] Settings page success/error feedback for credential validation
- [ ] Full uninstall cleanup for newer activity, guidelines, tuning, docs runtime, and roadmap state
- [x] Live credential validation on Cloudflare Workers AI, Qdrant, and Cloudflare AI Search settings save
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
- [ ] Deeper audit and observability UI: richer row actions/discovery and broader operator workflows
- [ ] Navigation overlay generation
- [ ] Multi-turn conversation (context carryover across recommendation rounds)
- [ ] Batch recommendations (multiple blocks at once)

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
node scripts/verify.js --skip-e2e    # Baseline non-browser release gate for cross-surface changes
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

## Cross-Surface Validation

Multi-surface validation is an explicit release contract, not an implicit reviewer judgment call. Use `docs/reference/cross-surface-validation-gates.md` whenever a change touches more than one recommendation surface or any shared subsystem such as REST or ability contracts, provider routing, freshness signatures, activity and undo, shared UI taxonomy, or operator and admin paths.

Minimum sign-off evidence:

- nearest targeted PHPUnit and JS suites for the touched subsystem
- `node scripts/verify.js --skip-e2e` plus `output/verify/summary.json`
- `npm run check:docs` when contracts, surfacing rules, operator paths, or contributor docs changed
- targeted Playwright coverage in the matching harnesses: `playground` for post-editor, block, pattern, and navigation flows; `wp70` for Site Editor template, template-part, Global Styles, and Style Book flows
- a recorded blocker or explicit waiver for any known-red or unavailable browser harness, with `STATUS.md` as the source of truth for current harness health

## Key Technical Decisions

1. **Connectors-owned chat, plugin-owned embeddings**: Block and content requests use `ChatClient`, while template, template-part, navigation, Global Styles, Style Book, and pattern ranking use `ResponsesClient`. Both resolve chat through the WordPress AI Client runtime configured in `Settings > Connectors`. Cloudflare Workers AI configures the one Flavor Agent Embedding Model until core exposes an embeddings provider path. Saved provider values from older settings screens are ignored.
2. **Narrow approval model**: Block suggestions still apply inline on click, template/template-part/Global Styles/Style Book suggestions require review first, navigation stays advisory-only, and pattern recommendations stay ranking/browse-only. There is no separate multi-stage approval workspace or diff-review pipeline.
3. **Inspector injection over sidebar**: Recommendations appear in the native Inspector tabs (Settings, Styles, sub-panels) rather than a separate sidebar. This feels native, not bolted-on.
4. **Indexed retrieval for patterns**: Patterns are retrieved through the selected backend rather than passed to the LLM as a raw full catalog. The default Qdrant backend embeds patterns into vectors and searches Qdrant. The Cloudflare AI Search backend stores public-safe pattern markdown in a private AI Search instance and retrieves filtered chunks. Both paths keep final ranking bounded before the LLM sees candidates.
5. **Cache-only docs grounding**: WordPress docs are not fetched on every recommendation request. Cache is warmed via explicit `search-wordpress-docs` calls, async prewarm jobs, prior queries, or first-request misses that queue follow-up warming. This avoids latency on the critical path.
6. **Abilities API is additive**: The REST API remains the primary runtime path. Abilities API registration is a parallel exposure for external agents. Neither depends on the other.
7. **Store is the contract boundary for first-party recommendation surfaces**: Block, pattern, navigation, template, template-part, Global Styles, and Style Book UI read through `@wordpress/data` selectors and store thunks handle REST calls, ranking/request state, stale-request rejection, and activity/undo coordination where those contracts exist.
8. **Navigation is advisory-only through v1.0**: The inspector surface is intentionally guidance-only until a bounded previewable/undoable executor earns its own milestone.
9. **Client-side `@wordpress/core-abilities` usage stays deferred for v1**: First-party JS continues to use feature-specific stores and REST endpoints; client-side abilities remain an external-agent/admin integration surface rather than the editor runtime baseline.
10. **Pattern Overrides, expanded `contentOnly`, and first-style extras stay bounded**: Pattern Overrides-aware ranking, broader `contentOnly` structural semantics, width/height preset transforms, and pseudo-element-aware token extraction are all deferred until later bounded milestones rather than being treated as ambient WP 7.0 work.
11. **`customCSS` recommendation generation is out of scope for v1**: The product remains grounded in native Gutenberg structures and theme tokens, not raw CSS authoring.
12. **Surface readiness uses one shared contract**: `flavorAgentData.capabilities.surfaces` and `flavor-agent/check-status` now read from the same `SurfaceCapabilities` shape so first-party UI and external diagnostics expose the same surface keys, reason codes, actions, and messages.
13. **Cross-surface release evidence is explicit**: When a change crosses surfaces or shared subsystems, validation gates are additive and sign-off requires targeted tests, the non-browser aggregate verifier, docs freshness when applicable, and the matching browser harnesses or an explicit recorded blocker or waiver.
