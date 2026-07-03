# Flavor Agent -- Source of Truth

> Last updated: 2026-06-28
> Version: 0.1.0
> Support floor: WordPress 7.0+, PHP 8.2+

## Documentation Backbone

See `docs/README.md` for the documentation backbone, reading order, ownership, and update contract.

## What This Plugin Is

Flavor Agent is a WordPress plugin that lets AI work on a live site without unchecked control. Every AI action it mediates runs through one governance layer: operations validated against bounded schemas, structural changes gated behind review, every apply the plugin owns attributed and recorded server-side, and recorded changes reversible with freshness/drift checks. For the eligible external style-apply lane, that same governance layer can produce site-key governed-change attestations that bind the approval, bounded operation set, and resulting state digest. That owned attestation boundary is intentionally narrow: `external-style-apply-v1` means WordPress approved an external Global Styles / Style Book mutation in `Settings > AI Activity`, Flavor Agent executed the bounded server-side style apply, and the resulting style subject hashed to the signed digest. The recommendation surfaces are the demonstration; the governance layer is the product (canonical contract map: `docs/reference/governance-layer.md`). It should still feel like Gutenberg and wp-admin became smarter, not like a separate AI application was bolted on. It does not insert or mutate content automatically without a bounded UI path -- it recommends, the user reviews where needed, and the user decides.

Applied AI changes are now tracked through the shared activity system and can be reversed from the UI when the live document still matches the recorded post-apply state. Activity persistence now uses server-backed storage, with editor-scoped hydration and `sessionStorage` retained only as a cache/fallback for the current editing surface.

The activity system now also has a first dedicated wp-admin approval/audit/attestation-discovery page at `Settings > AI Activity`, built with WordPress-native `DataViews` plus custom detail sections rather than a plugin-only table shell. It is the human gate for pending external Global Styles / Style Book, template, and template-part applies and the admin discovery point for public attestation artifacts after eligible applies are signed. The first selected-row action/discovery layer is now in place there too: focused-row banner, honest target/focused-view links, related-row pivots, passive evidence badges, and a first rich visual diff viewer for style-governance rows derived from stored snapshots and bounded style operations.

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

The plugin also ships one first-party admin approval/audit/attestation-discovery surface at `Settings > AI Activity`.

A parallel programmatic surface -- **WordPress Abilities API** -- exposes the shipped recommendation, helper, and diagnostic contracts as structured tool definitions for external AI agents on the supported WordPress 7.0+ floor. External agents get the same recommendation, validation, and freshness contracts as the first-party editor; they can also request review-gated style, template, template-part, and post-blocks applies, read their attribution, and undo executed style, template, template-part, and post-blocks rows through feature-gated abilities, but approval stays admin-only (the `activity/{id}/decision` route) and admin-global activity reads remain REST-only (see `docs/reference/governance-layer.md`).

## Repository Layout

Top-level orientation only — for the maintained file-by-file breakdown, read `CLAUDE.md` (architecture and key integration points), `docs/features/` (per-surface deep dives), `docs/reference/abilities-and-routes.md` (REST + Abilities contract map), and `docs/reference/shared-internals.md` (cross-cutting store and component utilities).

```
flavor-agent/
  flavor-agent.php          Bootstrap, lifecycle hooks, REST + Abilities registration, editor/admin asset enqueue
  uninstall.php             Cleanup for legacy/provider/vector/docs options, sync lock, and cron hooks
  inc/                      PHP backend (PSR-4 namespace FlavorAgent\)
    Abilities/              Surface, infra, helper, and external-apply abilities + registration
    Attestation/            Ring III governed-change statements, signing, key registry, verifier, and storage
    Activity/               Server-backed activity persistence, permissions, serialization
    Admin/                  Settings page + AI Activity admin app registration
    Apply/                  Governed external applies: server-side style, template, template-part, and post-blocks apply/undo executors (executor registry, shared StructuralOperationsApplier) + admin approval decision service
   AzureOpenAI/            Legacy chat Responses facade for Connectors-owned text generation
   Embeddings/             Workers AI embedding client, embedding signatures, shared HTTP helpers, and Qdrant vector DB
    Cloudflare/             AI Search docs grounding, Workers AI, private pattern AI Search
    Context/                Per-surface context collectors and validators
    Guidelines/             Guidelines storage adapters (core/legacy)
    LLM/                    Prompt assembly, ResponseSchema, ChatClient, contrast validator
    MCP/                    Dedicated MCP server bootstrap
    OpenAI/                 Provider selection and connector-aware credential resolution
    Patterns/               Pattern index, retrieval backends, sync, fingerprinting, cron
    REST/                   Activity, sync-pattern, and public attestation REST routes
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
    test-utils/             React DOM test harness + WP component mocks
    utils/                  Operation catalogs, validators, signatures, action helpers
  tests/
    phpunit/                PHP unit tests (one per major class)
    e2e/                    Playwright suites (Playground + WP 7.0 Site Editor)
  scripts/                  Build, verify, doc-drift, e2e bootstrap helpers
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
| Cloudflare Workers AI Embeddings | Pattern embedding and Cloudflare account credentials              | Pattern index + pattern recommendations when the Qdrant backend needs plugin-owned embeddings; shared account/token and effective model for the Cloudflare AI Search pattern backend | `flavor_agent_cloudflare_workers_ai_account_id`, `_api_token`, `_embedding_model`                                                |
| Qdrant                           | Vector similarity search                                          | Pattern recommendations when the Qdrant Pattern Storage backend is selected                                           | `flavor_agent_qdrant_url`, `_key`                                                                                                |
| Private Cloudflare AI Search     | Managed pattern indexing and retrieval                            | Pattern recommendations when the Cloudflare AI Search pattern backend is selected                                    | Managed `flavor-agent-patterns-{site_hash}` AI Search instance; account/token come from the Embedding Model settings             |
| Cloudflare AI Search             | WordPress dev-doc grounding                                       | Required current docs grounding for block, pattern, template, template-part, navigation, Global Styles, and Style Book recs; content recommendations are editorial-only and exempt | Managed public search endpoint plus `flavor_agent_cloudflare_ai_search_max_results`                                              |

The plugin works in degraded mode without any services configured. Each surface gracefully disables when its required backends are absent.

Runtime embeddings use Cloudflare Workers AI. Older OpenAI Native, Azure OpenAI, or connector-backed provider option values are not rendered as embedding choices, and the settings form overwrites the provider option with `cloudflare_workers_ai` on save.

When the WordPress AI plugin Connector Approval experiment is enabled, chat-backed recommendation surfaces require administrator approval for the selected connector. The first denied request is expected to create a pending approval entry in the AI plugin. Flavor Agent surfaces that denial as a request-time editor notice; administrators get a link to `Tools > Connector Approvals`, while non-admin editors are told to ask an administrator to review the pending request.

## Feature Inventory

### Implemented and Working

#### Block Inspector Recommendations

- Native block Inspector recommendations with safe local attribute apply, reviewed structural apply behind the rollout flag, delegated passive sub-panel mirrors, and editor-scoped activity/undo. Exact flow: [`features/block-recommendations.md`](features/block-recommendations.md). Exact contract: [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

#### Pattern Recommendations

- Native inserter shelf and badge for ranked, renderable, allowed patterns. Non-synced recommendations now support a bounded original/adapted compare preview with deterministic cosmetic adaptation, but direct insertion still uses core block insertion plus server signature revalidation and remains outside Flavor Agent apply/undo. Exact flow: [`features/pattern-recommendations.md`](features/pattern-recommendations.md). Exact contract: [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

#### Template Recommendations

- Site Editor template recommendations with review-before-apply, bounded template-part and pattern operations, advisory fallback, and refresh-safe activity/undo. Exact flow: [`features/template-recommendations.md`](features/template-recommendations.md). Operation vocabulary: [`reference/template-operations.md`](reference/template-operations.md).
- Governed external template apply: external agents can request a review-gated page-level template structural apply (`request-template-apply`) that queues a drift-checked pending row, is approved in `Settings > AI Activity`, executes one bounded `insert_pattern` against one `wp_template` through the server-side executor registry, and is reversible via `undo-activity`. This is the third governed external-apply lane after style and template-part; it is **not attested** (Ring III attestation stays frozen to `external-style-apply-v1`).

#### Template Part Recommendations

- Site Editor template-part recommendations with review-before-apply, focus-block links, pattern browse links, bounded operations, advisory fallback, and activity/undo. Exact flow: [`features/template-part-recommendations.md`](features/template-part-recommendations.md). Operation vocabulary: [`reference/template-operations.md`](reference/template-operations.md).
- Governed external template-part apply: external agents can request a review-gated template-part structural apply (`request-template-part-apply`) that queues a drift-checked pending row, is approved in `Settings > AI Activity`, executes ≤3 path-addressed bounded operations against one `wp_template_part` through the server-side executor registry, and is reversible via `undo-activity`. This is the second governed external-apply lane after style; it is **not attested** (Ring III attestation stays frozen to `external-style-apply-v1`).
- Governed external post-blocks apply: external agents can request a review-gated structural apply (`request-post-blocks-apply`) against one post or page's `post_content` -- extending the loop beyond theme-territory documents to arbitrary content -- that queues a drift-checked, post-scoped pending row, is approved in `Settings > AI Activity` (gated by `manage_options` plus `edit_post` on the target), executes ≤3 path-addressed, lock-aware bounded operations through the shared `StructuralOperationsGrammar`/`StructuralOperationsApplier` machinery the template-part lane uses, and is reversible via `undo-activity`. This is the fourth governed external-apply lane; it has no first-party editor UI and is **not attested** (Ring III attestation stays frozen to `external-style-apply-v1`).

#### Global Styles Recommendations

- Native Site Editor Global Styles recommendations with review-before-apply for validated `theme.json` operations and scoped undo. Exact flow: [`features/style-and-theme-intelligence.md`](features/style-and-theme-intelligence.md). Operation vocabulary: [`reference/template-operations.md`](reference/template-operations.md).

#### Style Book Recommendations

- Native Site Editor Style Book recommendations with review-before-apply for validated block-scoped `theme.json` operations and scoped undo. Exact flow: [`features/style-and-theme-intelligence.md`](features/style-and-theme-intelligence.md). Operation vocabulary: [`reference/template-operations.md`](reference/template-operations.md).

#### Content Recommendations

- Post/page document-panel drafting, editing, and critique recommendations. This surface is editorial-only, exposes no Flavor Agent apply/undo path, and trims oversized existing-draft prompt context before assembly so the content lane stays within the active prompt budget. Exact flow: [`features/content-recommendations.md`](features/content-recommendations.md). Exact contract: [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

#### Shared Inline Review Model

- The recommendation surfaces share one status vocabulary and shell order, but not one mutation contract. Surface taxonomy and intentional UI exceptions are canonical in [`reference/recommendation-ui-consistency.md`](reference/recommendation-ui-consistency.md); shared component/runtime ownership is canonical in [`reference/shared-internals.md`](reference/shared-internals.md).

#### AI Activity And Undo

- Server-backed activity supports executable apply rows, scoped request diagnostics, recommendation outcome diagnostics, inline undo, attestation discovery for eligible external style applies, selected-row target/focus/filter actions, passive discovery badges, a first rich visual diff viewer for style-governance rows, and the admin approval/audit/attestation-discovery page. Exact surfaces and audit behavior live in [`features/activity-and-audit.md`](features/activity-and-audit.md); undo states and transition rules live in [`reference/activity-state-machine.md`](reference/activity-state-machine.md).

#### Admin Activity Page

- `Settings > AI Activity` is the first approval/audit/attestation-discovery surface for recent server-backed actions, scoped diagnostics, pending external style/template/template-part applies, public verify links for attested style applies, the first linked-row/selected-row action-discovery layer, and a first rich visual diff viewer for style-governance rows. It is documented in [`features/activity-and-audit.md`](features/activity-and-audit.md).

#### Pattern Index Lifecycle

- The pattern index supports Qdrant and private Cloudflare AI Search backends, registered patterns, and public-safe published user patterns. Operational behavior and backend-specific debugging live in [`reference/pattern-recommendation-debugging.md`](reference/pattern-recommendation-debugging.md); external-service disclosure lives in [`reference/external-service-disclosure.md`](reference/external-service-disclosure.md).

#### WordPress Abilities API (available on supported WordPress 7.0+ installs)

The code defines 35 abilities with full JSON Schema input/output definitions: eight recommendation abilities, thirteen helper/read abilities, the docs-search ability, six `preview-recommend-*` signature-only siblings that wrap the executable recommendation parents for safe click-to-run testing from the Abilities Explorer and external MCP clients, and seven feature-gated external-apply abilities (`request-style-apply`, `request-template-apply`, `request-template-part-apply`, `request-post-blocks-apply`, `get-activity`, `list-activity`, `undo-activity`) that let an external agent request a review-gated style, template, template-part, or post-blocks apply, read activity, and undo executed style, template, template-part, and post-blocks rows. The exact handlers, permissions, schemas, and behavior annotations (`readonly`/`destructive`/`idempotent`/`openWorld`) live in [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

#### Developer Docs

- Trusted WordPress developer-doc grounding supports explicit `search-wordpress-docs` calls and recommendation-time grounding. Source eligibility, cache behavior, public corpus ownership, and refresh cadence are canonical in [`reference/developer-docs-public-corpus-runbook.md`](reference/developer-docs-public-corpus-runbook.md); ability fields are canonical in [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

#### REST API

Activity, sync-pattern, and public attestation routes live under `/flavor-agent/v1/`. Recommendation surfaces use WordPress Abilities API contracts instead. Permissions and handler classes are documented in [`reference/abilities-and-routes.md`](reference/abilities-and-routes.md).

- **6 activity route methods** adapt the activity repository: GET `activity` (contextual editor/theme capability; sitewide GET requires `manage_options`), POST `activity` (contextual), POST `activity/{id}/undo` (contextual), POST `activity/{id}/decision` (`manage_options` plus the row's mutation capability; approves or rejects a pending external apply), and POST/DELETE `activity/{id}/claim` (`manage_options` plus the row's mutation capability via `Activity\Permissions::can_decide_activity_request`; acquires/releases an advisory, auto-expiring review claim that never gates a decision)
- **1 admin route path**: POST `sync-patterns` (`manage_options`) queues the manual pattern reindex; GET `sync-patterns` (`manage_options`) returns current sync state for polling
- **3 public attestation route methods**: GET `attestations/keys` returns the public key registry; GET `attestations/{id}` returns the signed statement envelope; GET `attestations/{id}/subject-state` returns the current canonical subject slice for live-state verification

#### Admin Settings

Settings page at `Settings > Flavor Agent` renders six top-level accordion groups with status cards and native Help guidance:

- **AI Model** -- Text-generation readiness from `Settings > Connectors`, the current WordPress AI Client runtime path, and a link to the Connectors screen.
- **Embedding Model** -- Cloudflare Workers AI account/token/effective model for Flavor Agent semantic features. Reasoning effort remains a backwards-compatible saved option used by the Connectors-routed chat runtime, falling back to `medium` when no saved or request value is present, but it is no longer an editable Embedding Model control. Text-generation provider and model readiness belong to `Settings > Connectors`.
- **Patterns** -- Pattern Storage selector, Qdrant URL/key, managed Cloudflare AI Search pattern-index status, backend-specific ranking thresholds, max results, and the `Sync Pattern Catalog` status/metrics/manual trigger panel. Pattern Storage is infrastructure, not another AI model choice.
- **Developer Docs** -- Built-in public Cloudflare AI Search developer-doc grounding, max result count, and the minimal ok/unreachable runtime signal (last search time and result count).
- **Guidelines** -- Site/copy/image/additional guidelines, block-specific notes for content-role blocks, and JSON import/export tooling. Runtime recommendations read the core/Gutenberg Guidelines store first when the `wp_guideline` model is present, with legacy Flavor Agent options retained as migration/admin tooling and fallback storage.
- **Experimental Features** -- AI Activity Dual Logging controls. Block structural actions graduated to unconditionally-on on 2026-06-03 and are no longer listed here; the only opt-out is the `flavor_agent_enable_block_structural_actions` filter.

Exact provider ownership, credential precedence, backend validation requirements, and external-service disclosure live in [`reference/provider-precedence.md`](reference/provider-precedence.md) and [`reference/external-service-disclosure.md`](reference/external-service-disclosure.md). Settings-screen behavior lives in [`features/settings-backends-and-sync.md`](features/settings-backends-and-sync.md).

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
| Audit/revision log UI         | Phase 5        | Initial slice shipped | `Settings > AI Activity` now provides a DataViews timeline with external style-apply decisions, attestation discovery for attested applies, custom detail sections, structured state summaries, a bounded rendered `learningReport` for global admin reads, the first selected-row action/discovery layer, and a first rich visual diff layer for style-governance rows; broader observability remains open |
| Dynamic block scaffolding     | Phase 4        | Not built             | Generate `render_callback` + dynamic block configs                                                                                                                                         |
| Pattern-to-file promotion     | Phase 3        | Not built             | Export approved patterns to PHP files in `patterns/` directory                                                                                                                             |

### Known Issues and Gaps

1. **`composer lint:php`**: Green across production code, but `tests/phpunit/bootstrap.php` is intentionally excluded from WPCS due to its multi-namespace stub harness.
2. **JS toolchain support is now explicit**: `.nvmrc` defaults to Node 24 / npm 11, and the repo also supports the previously verified Node 20 / npm 10 toolchain through the `package.json` engine range under `.npmrc`'s `engine-strict` gate.
3. **Inserter DOM discovery is still markup-coupled (mitigated)**: `inserter-dom.js` centralizes container (5), search-input (4), and toolbar toggle selectors and now fails closed to `null` when the expected editor structure is absent, so caller cleanup is isolated to one module.
4. **Pattern settings compatibility is explicit and fail-closed**: `pattern-settings.js` probes future stable `blockPatterns` / `blockPatternCategories` / `getAllowedPatterns` paths when present, but current Gutenberg trunk still exposes `__experimentalAdditional*`, `__experimental*`, and `__experimentalGetAllowedPatterns` as the live baseline. The adapter returns an empty scoped result plus diagnostics instead of widening to an `all-patterns-fallback` result when contextual selectors are unavailable.
5. **Theme-token source resolution is now merged rather than over-promoted**: `theme-settings.js` isolates raw settings reads and now uses stable sources when available while filling only missing branches from `__experimentalFeatures`. Flavor Agent still targets WordPress 7.0+, so block attribute role detection reads only the stable `role` key and no longer preserves deprecated `__experimentalRole` compatibility.
6. **Browser coverage is split across two harnesses**: Playground remains the fast `6.9.4` smoke path because the current Playground 7.0 beta editor runtime breaks before plugin bootstrap, while a dedicated Docker-backed WordPress `7.0` Site Editor harness owns refresh/drift-sensitive flows. The default `npm run test:e2e` command now aggregates both harnesses and the checked-in smoke suite now covers navigation plus `wp_template_part`, but the WP 7.0 half still requires Docker on PATH. The harness pins a pre-release image via `FLAVOR_AGENT_WP70_BASE_IMAGE`; the canonical tag and override instructions live in `docs/reference/local-environment-setup.md`.
7. **Activity history is still only a first governance-console slice**: The new `Settings > AI Activity` page provides a recent DataViews timeline with external style-apply approval/rejection, request diagnostics, attestation discovery for attested style applies, structured before/after state summaries for privileged users, a linked-row banner, selected-row target/focused-view/related-row actions, passive evidence badges, and a first rich visual diff layer for style-governance rows, but broader observability workflow beyond the stored timeline remains open.
8. **Uninstall cleanup is explicit and option-focused**: `uninstall.php` clears Flavor Agent cron hooks plus the static sync/core-roadmap transient keys, drops the plugin-owned activity table, and deletes registered plugin-owned provider, embedding, Qdrant, Cloudflare AI Search, docs runtime, pattern index, activity, guideline, and experiment options. Dynamic docs grounding cache transients are not bulk-deleted by the uninstall handler.
9. **Provider-backed verification is still environment-dependent**: Live recommendation verification depends on whichever text-generation runtime is configured in `Settings > Connectors`, plus plugin-owned embedding credentials and the selected pattern storage backend, and should be rerun whenever those paths change.

### Current Open Backlog

For the consolidated work queue, source docs, gating state, and suggested next planning order, see [`reference/current-open-work.md`](reference/current-open-work.md).

- Deepen the new admin activity page beyond the shipped rich visual diff layer with broader diagnostics, tighter ability-to-audit cross-reference metadata, and cross-operator workflows.
- Continue the `improving-levers.md` roadmap from the remaining unshipped phases after Phase 3: docs fingerprint split, learning attribution, fixture harvest, bounded local ranking feedback, and editable site preference summaries. Pattern metadata/component ranking, deterministic design-quality signals, durable learning-report pattern-trait capture, and the expanded recommendation evaluation harness are represented in the current code. Shipped implementation plans are archived under `docs/superpowers/plans/archive/` and are not active backlog.
- Swap the Docker-backed WP 7.0 browser harness from the beta image to the official stable `7.0` image once it exists, and keep Docker available in environments that run that harness.
- Revisit navigation apply only if a bounded previewable/undoable executor becomes its own tracked post-v1 milestone.
- Keep Interactivity API work in the future backlog, not the current remediation backlog, until the plugin grows a front-end runtime surface.
- Keep uninstall cleanup coverage in sync with new plugin-owned runtime options, tables, transients, and scheduled hooks as they are added.

## Data Flow Diagrams

Per-surface end-to-end flows live in `docs/features/`; exact ability and REST sequence cheatsheets live in [`reference/abilities-and-routes.md#sequence-cheatsheet`](reference/abilities-and-routes.md#sequence-cheatsheet). This source-of-truth file intentionally keeps only the product inventory and pointers so detailed flow contracts do not drift across duplicate diagrams.

## Test Coverage

### PHP (PHPUnit)

This section is intentionally representative rather than a line-by-line manifest. The live suite currently includes the files under `tests/phpunit/`, with these areas carrying direct coverage:

| Suite / area                              | Representative files                                                                                                             | What's Covered                                                                                                                       |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Contract and registration                 | `AgentControllerTest`, `RegistrationTest`, `EditorSurfaceCapabilitiesTest`                                                       | Ability registrations and schemas, MCP metadata, localized capability payloads, activity/sync REST endpoints, and route/shape validation |
| Block and content abilities               | `BlockAbilitiesTest`, `ContentAbilitiesTest`, `PromptGuidanceTest`, `PromptRulesTest`, `WordPressAIClientTest`, `ChatClientTest` | Block input normalization, editorial content scaffold behavior, prompt guardrails, and Connectors-first chat runtime behavior        |
| Provider and backend configuration        | `ProviderTest`, `SettingsTest`, `EmbeddingBackendValidationTest`, `InfraAbilitiesTest`                                           | Provider compatibility canonicalization, Workers AI embedding validation, Qdrant diagnostics, validation rollback, and effective runtime metadata |
| Pattern pipeline                          | `PatternAbilitiesTest`, `PatternCatalogTest`, `PatternIndexTest`                                                                 | Registry filtering, recommendation pipeline behavior, sync/fingerprint lifecycle, and Qdrant-backed indexing rules                   |
| Template, style, and navigation prompting | `TemplatePromptTest`, `TemplatePartPromptTest`, `StyleAbilitiesTest`, `StylePromptTest`, `NavigationAbilitiesTest`               | Structured operations, bounded placements, style scope rules, variation parsing, and navigation advice parsing                       |
| Activity and persistence                  | `ActivityRepositoryTest`, `ActivityPermissionsTest`                                                                              | Activity create/query/prune, ordered undo eligibility, undo state transitions, and contextual permissions                            |
| Docs grounding                            | `AISearchClientTest`, `CollectsDocsGuidanceTest`, `DocsGuidanceResultTest`                                                       | Best-effort search, query cache, URL hygiene, source labels, and the ok/unreachable runtime signal                                   |
| Shared context collectors                 | `ServerCollectorTest`                                                                                                            | Template/template-part metadata, visible-pattern filtering, and token diagnostics                                                    |
| Guidelines storage and formatting         | `GuidelinesTest`, `CoreRoadmapGuidanceTest`                                                                                      | Editorial guidelines retrieval, migration status, prompt context formatting, and core roadmap signal caching                         |
| Support utilities                         | `MetricsNormalizerTest`, `RankingContractTest`, `ThemeTokenFormatterTest`, `PromptBudgetTest`, `PromptFormattingTest`            | Metrics normalization, ranking contracts, theme token formatting, prompt budget enforcement including content draft caps, and prompt formatting helpers |
| Admin pages                               | `ActivityPageTest`, `SupportToPanelSyncTest`                                                                                     | Settings page panel sync, activity admin page rendering                                                                              |

### JS (Jest)

Current high-signal coverage map:

| Area                                   | Representative files                                                                                                                                                                                                                                                                                                                                       | What's Covered                                                                                                                             |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Store state and activity orchestration | `src/store/__tests__/activity-history.test.js`, `activity-history-state.test.js`, `store-actions.test.js`, `template-apply-state.test.js`, `pattern-status.test.js`, `navigation-request-state.test.js`                                                                                                                                                    | Request lifecycle, stale-result rejection, activity hydration, undo coordination, and shared request/apply reducer state                   |
| Inspector flows                        | `src/inspector/__tests__/BlockRecommendationsPanel.test.js`, `NavigationRecommendations.test.js`, `InspectorInjector.test.js`, `panel-delegation.test.js`, `SuggestionChips.test.js`, `src/inspector/suggestion-keys.test.js`                                                                                                                              | Panel rendering, capability gating, delegated passive sub-panel behavior, navigation framing, and key generation                           |
| Pattern inserter integration           | `src/patterns/__tests__/PatternRecommender.test.js`, `InserterBadge.test.js`, `compat.test.js`, `recommendation-utils.test.js`, `inserter-dom.test.js`, `inserter-badge-state.test.js`                                                                                                                                                                      | Inserter DOM discovery, compat negotiation, local shelf rendering, badge state, and fetcher lifecycle                                      |
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
- [x] Pattern index lifecycle (auto-sync, background cron, diff-based updates for registered patterns and published user patterns across sync states)
- [x] WordPress Abilities API integration (all working abilities)
- [x] WordPress docs grounding (cache-based)
- [x] Admin settings page with backend configuration
- [x] Built-in public Cloudflare AI Search developer-doc grounding with max-result diagnostics and no site-owner credential fields
- [x] Settings page success/error feedback for credential validation
- [x] Uninstall cleanup for activity storage, guidelines, tuning, docs runtime, and roadmap state
- [x] Live credential validation on Cloudflare Workers AI, Qdrant, and Cloudflare AI Search settings save
- [x] Navigation recommendations (replace 501 stub)
- [x] Integration tests for block, pattern, template, and WP 7.0 refresh/drift coverage
- [x] Playwright smoke for navigation and `wp_template_part`, plus a default `npm run test:e2e` path that covers both harnesses

### Should Have (v1.x)

- [ ] Block subtree transform: propose replacement trees for selected block groups
- [x] Inserter search input detection resilience (abstract away DOM selectors)
- [x] Pattern API migration plan (move off `__experimentalBlockPatterns` when stable API lands)
- [x] Docs grounding relaxed to best-effort: one cached corpus search per recommendation, no warm/prewarm crons, grounding never blocks a surface (trust and currency owned by `scripts/update-docs-ai-search.js`)
- [x] Suggestion undo (restore previous attribute values)
- [ ] Rate limiting / request throttling for LLM calls

### Could Have (v2.0+)

- [ ] Pattern generation: LLM creates new pattern markup from context
- [ ] Pattern promotion: save approved AI output as registered patterns
- [ ] Interactivity API scaffolding: generate viewScriptModule code
- [ ] Dynamic block scaffolding: generate render_callback configurations
- [ ] Deeper audit and observability UI: broader operator workflows and tighter cross-reference metadata beyond the shipped rich visual diff layer
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
5. **Docs grounding is cache-first, not unconditional remote search**: WordPress docs are not fetched on every recommendation request. Exact, family, and entity caches are checked first. Pattern recommendations disable foreground warming entirely; other recommendation surfaces may use a bounded foreground warm only when generic or missing fallback guidance would otherwise be used, then queue async warming for future requests.
6. **Abilities API is the recommendation runtime path**: The remaining REST API covers activity and pattern sync. First-party recommendation execution and external-agent recommendation calls use the WordPress Abilities API.
7. **Store is the contract boundary for first-party recommendation surfaces**: Block, pattern, navigation, template, template-part, Global Styles, and Style Book UI read through `@wordpress/data` selectors and store thunks handle ability calls, ranking/request state, stale-request rejection, and activity/undo coordination where those contracts exist.
8. **Navigation is advisory-only through v1.0**: The inspector surface is intentionally guidance-only until a bounded previewable/undoable executor earns its own milestone.
9. **Client-side abilities stay behind a bridge**: First-party JS continues to use feature-specific stores, and those store thunks execute recommendation requests through the WordPress Abilities API bridge instead of exposing raw prompt access from UI components.
10. **Pattern Overrides, expanded `contentOnly`, and first-style extras stay bounded**: Pattern Overrides-aware ranking, broader `contentOnly` structural semantics, width/height preset transforms, and pseudo-element-aware token extraction are all deferred until later bounded milestones rather than being treated as ambient WP 7.0 work.
11. **`customCSS` recommendation generation is out of scope for v1**: The product remains grounded in native Gutenberg structures and theme tokens, not raw CSS authoring.
12. **Surface readiness uses one shared contract**: `flavorAgentData.capabilities.surfaces` and `flavor-agent/check-status` now read from the same `SurfaceCapabilities` shape so first-party UI and external diagnostics expose the same surface keys, reason codes, actions, and messages.
13. **Cross-surface release evidence is explicit**: When a change crosses surfaces or shared subsystems, validation gates are additive and sign-off requires targeted tests, the non-browser aggregate verifier, docs freshness when applicable, and the matching browser harnesses or an explicit recorded blocker or waiver.
