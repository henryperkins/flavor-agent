# Flavor Agent Repository Progress Assessment

Generated: 2026-03-24

## Executive Summary

Flavor Agent is a late-prototype / early-beta plugin with real shipping code, not a scaffold. It is close to a hardened v1 for its four strongest editor surfaces: block inspector recommendations, pattern recommendations, template recommendations, and now a narrow executable template-part recommendation flow are all implemented in current code, wired into the editor, and backed by passing PHP and JS unit suites. The main remaining gaps are that `recommend-navigation` is still ability-first from the shipped plugin surface, history/auditability is still session-scoped and latest-action oriented, template-part execution is intentionally limited to deterministic start/end pattern insertion, and WordPress 7.0 support still depends on explicit compatibility adapters plus split browser verification where the default `npm run test:e2e` path remains on the WordPress `6.9.4` Playground harness while a separate Docker-backed `npm run test:e2e:wp70` command owns the active refresh/drift coverage. There is still no checked-in browser smoke for the `wp_template_part` apply path. The absence of runtime Interactivity API usage is an explicit non-goal for the current editor/admin-only plugin, not a shipping gap.

Verification for this assessment used the requested repo docs plus direct code inspection in `flavor-agent.php`, `inc/`, `src/`, and `tests/`. In the latest verification refresh, `vendor/bin/phpunit` passed (`179` tests, `910` assertions) and `npm run test:unit -- --runInBand` passed (`26` suites, `179` tests). I inspected the checked-in Playwright smoke coverage but did not re-run it in this pass.

## Overall Plugin Progress

- **[implemented] Block inspector recommendations.** The plugin injects an `editor.BlockEdit` HOC into the native inspector, collects live block context, calls the WordPress AI Client, and renders settings/style/block suggestions plus inline undo and recent activity. Evidence: `src/inspector/InspectorInjector.js:59-462`, `src/context/collector.js`, `inc/Abilities/BlockAbilities.php:15-68`, `flavor-agent.php:156-173`. Coverage: `tests/phpunit/BlockAbilitiesTest.php`, `src/context/__tests__/block-inspector.test.js`, `tests/e2e/flavor-agent.smoke.spec.js:515-651`.

- **[implemented] Pattern recommendations.** Pattern recommendations are fetched passively on editor load and actively from inserter search, filtered by `visiblePatternNames`, then patched back into the native inserter under a `Recommended` category. Evidence: `src/patterns/PatternRecommender.js:63-255`, `src/patterns/compat.js:34-253`, `inc/Abilities/PatternAbilities.php:39-292`, `flavor-agent.php:89-128`. Coverage: `tests/phpunit/PatternAbilitiesTest.php`, `tests/phpunit/AgentControllerTest.php`, `src/patterns/__tests__/compat.test.js:52-257`, `src/utils/__tests__/visible-patterns.test.js:42-186`, `tests/e2e/flavor-agent.smoke.spec.js:653-715`.

- **[implemented] Template recommendations.** The Site Editor `wp_template` panel fetches structured recommendations, shows preview-confirm-apply UI, validates executable operations, applies them deterministically, and records undoable activity. The executable scope is intentionally narrow: `assign_template_part`, `replace_template_part`, and `insert_pattern` only. Evidence: `inc/LLM/TemplatePrompt.php:12-14,19-79`, `src/templates/TemplateRecommender.js:207-649`, `src/store/index.js:699-970`, `src/utils/template-actions.js:574-989`, `inc/Abilities/TemplateAbilities.php:33-91`, `inc/REST/Agent_Controller.php:95-133,279-312`. Coverage: `tests/phpunit/AgentControllerTest.php`, `src/utils/__tests__/template-actions.test.js:348-420`, `src/templates/__tests__/template-recommender-helpers.test.js`, `src/templates/__tests__/TemplateRecommender.test.js`, `tests/e2e/flavor-agent.smoke.spec.js:717-853`.

- **[implemented] Template-part recommendations.** The `wp_template_part` panel now uses the shared store, preserves the advisory block-focus / pattern-browse affordances, and adds preview-confirm-apply plus undo for one intentionally narrow operation: validated `insert_pattern` at explicit `start` or `end` placement inside the current template part. The prompt/parser remains advisory-first and drops unsupported operations back to browse-only suggestions. Evidence: `src/template-parts/TemplatePartRecommender.js`, `src/store/index.js`, `src/utils/template-actions.js`, `inc/Abilities/TemplateAbilities.php`, `inc/LLM/TemplatePartPrompt.php`. Coverage: `tests/phpunit/TemplatePartPromptTest.php`, `tests/phpunit/AgentControllerTest.php`, `src/store/__tests__/store-actions.test.js`, `src/utils/__tests__/template-actions.test.js`.

- **[partial] AI activity history and undo.** The activity system is implemented for block, template, and template-part apply flows, is scoped per edited entity in `sessionStorage`, and supports inline undo plus recent-actions UI. Only the most recent AI action is auto-undoable, there is no server-backed audit log, and default browser verification still leaves both template-part browser coverage and the WP 7.0 Site Editor refresh/drift undo cases outside `npm run test:e2e` even though the latter now exist in the separate `npm run test:e2e:wp70` harness. Evidence: `src/store/activity-history.js`, `src/components/ActivitySessionBootstrap.js`, `src/components/AIActivitySection.js`, `src/store/index.js`, `src/utils/template-actions.js`, `src/templates/TemplateRecommender.js`, `src/template-parts/TemplatePartRecommender.js`, `package.json`, `playwright.config.js`, `playwright.wp70.config.js`. Coverage: `src/store/__tests__/activity-history.test.js`, `src/store/__tests__/store-actions.test.js`, `src/utils/__tests__/template-actions.test.js`, `tests/e2e/flavor-agent.smoke.spec.js`.

- **[implemented] WordPress docs grounding.** Grounding is live, cache-backed, trusted-source filtered, and prewarmed by cron. Recommendation-time grounding is intentionally cache-only and non-blocking, while explicit docs search stays admin-only. Evidence: `inc/Abilities/WordPressDocsAbilities.php:17-42`, `inc/Cloudflare/AISearchClient.php:11-18,25-53,154-221,399-463,1011-1381`, `inc/Abilities/BlockAbilities.php:275-280`, `inc/Abilities/TemplateAbilities.php:219-360`. Coverage: `tests/phpunit/AISearchClientTest.php:179-355`, `tests/phpunit/DocsPrewarmTest.php:24-249`.

- **[implemented] Admin settings and diagnostics.** The plugin has a dedicated settings page, provider sections, validation callbacks, plugin-scoped error notices, prewarm diagnostics, and manual pattern sync controls. The page copy explicitly separates plugin-managed backends from core Connectors-managed block AI configuration. Evidence: `inc/Settings.php:47-67,69-427,429-517,1278-1406`. Coverage: `tests/phpunit/SettingsTest.php`, `tests/phpunit/InfraAbilitiesTest.php`.

- **[implemented] Pattern indexing/sync.** Pattern sync is diff-based, fingerprinted, batched, cron-driven, manually triggerable, and invalidated by provider/Qdrant/theme/plugin/settings changes. Evidence: `inc/Patterns/PatternIndex.php:14-20,47-113,179-394`, `flavor-agent.php:27-33,50-77`, `inc/REST/Agent_Controller.php:47-55,231-239`. Coverage: `tests/phpunit/PatternIndexTest.php`, `tests/phpunit/PatternAbilitiesTest.php`, and `tests/phpunit/AgentControllerTest.php` now cover fingerprinting, scheduling, sync-state transitions, retrieval/reranking, and failure handling directly.

## Abilities API Usage

- **[implemented] Category registration is real and hooked from the bootstrap.** `flavor-agent.php:47-48` wires category and ability registration into `wp_abilities_api_categories_init` and `wp_abilities_api_init`, and `inc/Abilities/Registration.php:9-16` registers the `flavor-agent` category.

- **[implemented] The plugin currently registers 11 abilities, all with real handlers and schemas.** I did not find any registered ability that is scaffold-only. The weakest ones are still real server behaviors, but some are only partial as product surfaces.

| Ability | Permission model | `show_in_rest` | `readonly` | Handler | Status |
| --- | --- | --- | --- | --- | --- |
| `flavor-agent/recommend-block` | `edit_posts` | yes | no | `BlockAbilities::recommend_block` | implemented |
| `flavor-agent/introspect-block` | `edit_posts` | yes | yes | `BlockAbilities::introspect_block` | implemented |
| `flavor-agent/recommend-patterns` | `edit_posts` | yes | no | `PatternAbilities::recommend_patterns` | implemented |
| `flavor-agent/list-patterns` | `edit_posts` | yes | yes | `PatternAbilities::list_patterns` | implemented |
| `flavor-agent/recommend-template` | `edit_theme_options` | yes | no | `TemplateAbilities::recommend_template` | implemented |
| `flavor-agent/recommend-template-part` | `edit_theme_options` | yes | no | `TemplateAbilities::recommend_template_part` | implemented |
| `flavor-agent/list-template-parts` | `edit_theme_options` | yes | yes | `TemplateAbilities::list_template_parts` | implemented |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | yes | no | `NavigationAbilities::recommend_navigation` | partial |
| `flavor-agent/search-wordpress-docs` | `WordPressDocsAbilities::can_search_wordpress_docs()`; effectively `manage_options` | yes | yes | `WordPressDocsAbilities::search_wordpress_docs` | implemented |
| `flavor-agent/get-theme-tokens` | `edit_posts` | yes | yes | `InfraAbilities::get_theme_tokens` | implemented |
| `flavor-agent/check-status` | `edit_posts` | yes | yes | `InfraAbilities::check_status` | implemented |

Evidence for the registrations above: `inc/Abilities/Registration.php:28-599`. Coverage: `tests/phpunit/RegistrationTest.php`.

- **[implemented] Production-ready abilities.** `recommend-block`, `introspect-block`, `recommend-patterns`, `list-patterns`, `recommend-template`, `recommend-template-part`, `list-template-parts`, `search-wordpress-docs`, `get-theme-tokens`, and `check-status` all have concrete handler logic, validation, and matching test coverage. Evidence: `inc/Abilities/BlockAbilities.php`, `inc/Abilities/PatternAbilities.php`, `inc/Abilities/TemplateAbilities.php`, `inc/Abilities/WordPressDocsAbilities.php`, `inc/Abilities/InfraAbilities.php`.

- **[implemented] `recommend-template-part` now exposes a narrow executable contract.** The handler builds real template-part context, filters candidate patterns by request visibility when provided, parses optional executable operations, and the first-party UI can preview/apply/undo validated start/end pattern insertion without broadening into free-form tree mutation. Evidence: `inc/Abilities/TemplateAbilities.php`, `inc/LLM/TemplatePartPrompt.php`, `src/template-parts/TemplatePartRecommender.js`, `src/utils/template-actions.js`.

- **[partial] `recommend-navigation` is implemented server-side but currently ability-first.** The handler and prompt pipeline are real, and server context includes WP 7.0 navigation-overlay data, but I found no first-party editor panel or REST controller adapter for it in `src/index.js` or `inc/REST/Agent_Controller.php`. Evidence: `inc/Abilities/NavigationAbilities.php:23-62`, `inc/Context/ServerCollector.php:1168-1249`, `src/index.js:21-38`, `inc/REST/Agent_Controller.php:17-157`.

- **[implemented] Navigation and docs search are Abilities-only, not plugin REST/UI surfaces.** The plugin REST controller exposes block, pattern, template, template-part, and sync routes only. Evidence: `inc/REST/Agent_Controller.php:17-157`.

## Interactivity API Usage (Explicitly De-scoped)

- **[absent] No runtime Interactivity API usage is present.** I found no runtime use of `@wordpress/interactivity`, `data-wp-*` directives, `viewScriptModule`, `wp_interactivity_*()`, or equivalent patterns in `src/`, `inc/`, `build/`, `tests/`, or `flavor-agent.php`.

- **[docs-only] Interactivity references are documentation/reference material, not implementation.** The remaining matches are in docs such as `docs/wordpress-7.0-gutenberg-22.8-reference.md`, plus backlog notes that explicitly mark Interactivity scaffolding as future work. Evidence: `docs/SOURCE_OF_TRUTH.md:293,510`, `docs/NEXT_STEPS_PLAN.md:480`, `docs/wordpress-7.0-gutenberg-22.8-reference.md:581-705`. The runtime plugin does not currently depend on the Interactivity API, and that is acceptable for the current editor/admin-only scope.

## Settings API Usage

- **[implemented] The plugin uses a conventional Settings API admin page.** The settings page is added under `manage_options`, registers one option group/page slug, and renders sections for provider selection, Azure OpenAI, OpenAI Native, Qdrant, and Cloudflare AI Search. Evidence: `inc/Settings.php:16-17,47-67,69-427`.

- **[implemented] Registered settings are explicit and grouped by backend.**
  - Provider: `flavor_agent_openai_provider` via `Provider::OPTION_NAME`. Evidence: `inc/Settings.php:70-78`.
  - Azure OpenAI: endpoint, key, embedding deployment, chat deployment. Evidence: `inc/Settings.php:81-116`.
  - OpenAI Native: API key, embedding model, chat model. Evidence: `inc/Settings.php:117-143`.
  - Qdrant: URL and key. Evidence: `inc/Settings.php:145-163`.
  - Cloudflare AI Search: account ID, instance ID, API token, max results. Evidence: `inc/Settings.php:166-201`.

- **[implemented] Notice behavior is standard Settings API plus plugin-scoped validation errors.** The page prints `settings_errors()` and reports backend-specific validation failures without replacing the core success notice behavior. Evidence: `inc/Settings.php:429-465,1214-1259`. Coverage: `tests/phpunit/SettingsTest.php`.

- **[implemented] Azure OpenAI validation is changed-only and validates both deployments.** Validation only runs on actual settings saves, only when Azure is the submitted provider, only when all four Azure fields are present, and only when values changed; both embeddings and responses endpoints must validate before new values are accepted. Evidence: `inc/Settings.php:577-603,753-835,1147-1157`. Coverage: `tests/phpunit/SettingsTest.php`.

- **[implemented] OpenAI Native validation honors connector-backed fallback credentials.** Validation only runs when the native provider is selected and the effective key/models are available; the effective key can come from the plugin setting, env var, constant, or the core connector option. Evidence: `inc/Settings.php:715-730,841-924`, `inc/OpenAI/Provider.php:65-89`. Coverage: `tests/phpunit/SettingsTest.php:347-404`, `tests/phpunit/InfraAbilitiesTest.php:120-146`.

- **[implemented] Qdrant validation probes the expected API shape and only runs when credentials change.** Evidence: `inc/Settings.php:626-658,931-988`. Coverage: `tests/phpunit/SettingsTest.php`.

- **[implemented] Cloudflare AI Search validation is probe-search based, trusted-source aware, and schedules prewarm on success.** It validates only when account/instance/token change, uses a lightweight probe search rather than metadata fetch, and schedules docs prewarm after successful validation. Evidence: `inc/Settings.php:660-679,994-1063`, `inc/Cloudflare/AISearchClient.php:75-98`. Coverage: `tests/phpunit/SettingsTest.php`, `tests/phpunit/AISearchClientTest.php:179-279`.

- **[implemented] Plugin-managed versus core-managed settings are cleanly separated.** Flavor Agent owns provider selection for pattern/template/navigation backends plus Azure/OpenAI Native/Qdrant/Cloudflare configuration. Block recommendation provider selection is intentionally delegated to WordPress core via `Settings > Connectors`. Evidence: `inc/Settings.php:240-251,308-319,429-455`, `flavor-agent.php:162-170`.

## Gutenberg And Editor Integration

- **[implemented] Editor bootstrapping is centralized in `src/index.js`.** The plugin registers a store-backed root that loads session activity, pattern recommender UI, inserter badge, template panel, and template-part panel. Evidence: `src/index.js:9-38`.

- **[implemented] Block inspector integration uses stable Gutenberg extension points.** The plugin relies on `editor.BlockEdit`, `InspectorControls`, `@wordpress/data`, and block-editor selectors/dispatchers rather than a custom sidebar shell. Evidence: `src/inspector/InspectorInjector.js:7-29,59-462`.

- **[implemented] Template and template-part surfaces use `PluginDocumentSettingPanel`.** `TemplateRecommender` activates only for `wp_template`, while `TemplatePartRecommender` activates only for `wp_template_part`. Evidence: `src/templates/TemplateRecommender.js:213-229,480-649`, `src/template-parts/TemplatePartRecommender.js:67-83,218-327`.

- **[implemented] The REST controller is a thin adapter over abilities, not a separate business-logic layer.** It sanitizes request data and forwards into ability handlers for block, pattern, template, template-part, and sync flows. Evidence: `inc/REST/Agent_Controller.php:17-157,209-334`. Coverage: `tests/phpunit/AgentControllerTest.php`.

- **[partial] Pattern integration is harder to regress because settings compatibility and DOM fallbacks are now isolated.** `src/patterns/pattern-settings.js` owns stable-vs-experimental settings/selector negotiation plus diagnostics for contextual vs `all-patterns-fallback` behavior, while `src/patterns/inserter-dom.js` owns fail-closed search/toggle discovery. Experimental fallbacks still exist, but the DOM-coupling risk is narrower and the fallback mode is explicit. Evidence: `src/patterns/pattern-settings.js`, `src/patterns/inserter-dom.js`, `src/patterns/PatternRecommender.js`, `src/patterns/InserterBadge.js`. Coverage: `src/patterns/__tests__/compat.test.js`, `src/patterns/__tests__/PatternRecommender.test.js`, `src/patterns/__tests__/InserterBadge.test.js`, `src/utils/__tests__/visible-patterns.test.js`.

- **[partial] Theme token collection still resolves to an experimental Gutenberg source on current WordPress 7.0, but the dependency is now isolated behind a source adapter.** `src/context/theme-settings.js` only promotes a stable `features` source when parity with `__experimentalFeatures` is proven; otherwise it keeps the experimental source active while preserving the existing manifest contract in `src/context/theme-tokens.js`. Evidence: `src/context/theme-settings.js`, `src/context/theme-tokens.js`. Coverage: `src/context/__tests__/theme-tokens.test.js`.

- **[implemented] Client/server support-map parity for WP 7.0 additions is now present.** Both client and server collectors map `customCSS -> advanced` and `listView -> settings`, and both use the stable `role` attribute key only. Evidence: `src/context/block-inspector.js:19-25,47-50,123-125`, `inc/Context/ServerCollector.php:11-43,1085-1089`. Coverage: `tests/phpunit/ServerCollectorTest.php`, `src/context/__tests__/block-inspector.test.js`.

- **[absent] I found no use of private Gutenberg lock/unlock or `__unstable*` APIs in runtime code.** The main compatibility risk is not private APIs; it is experimental fallbacks and DOM assumptions.

## Connectors Integration

- **[implemented] Block recommendations depend on the WordPress AI Client plus `Settings > Connectors`.** `flavor-agent.php` localizes `canRecommendBlocks` from `WordPressAIClient::is_supported()`, and the wrapper returns a setup message that explicitly points users to `Settings > Connectors`. Evidence: `flavor-agent.php:156-173`, `inc/LLM/WordPressAIClient.php:11-67`.

- **[implemented] OpenAI Native can fall back to connector-backed credentials.** The native provider resolves credentials in this order: plugin option, `OPENAI_API_KEY` env var, `OPENAI_API_KEY` constant, then the core connector option `connectors_ai_openai_api_key`. Evidence: `inc/OpenAI/Provider.php:13-15,56-89`.

- **[implemented] Connector-backed credentials also affect non-block surfaces indirectly.** The pattern/template/navigation provider layer uses `Provider::chat_configuration()` and `Provider::embedding_configuration()`, so OpenAI Native mode can operate without a plugin-saved key if the connector key is present. Evidence: `inc/OpenAI/Provider.php:95-167`, `inc/Abilities/InfraAbilities.php:18-121`.

- **[implemented] The plugin consumes core connector infrastructure; it does not register custom connectors.** I found no connector-registration code in `flavor-agent.php`, `inc/`, or `src/`. The only connector-specific touchpoints are reading the core option and reacting to its updates for reindexing. Evidence: `inc/OpenAI/Provider.php:13-89`, `flavor-agent.php:57-77`.

- **[docs-only] Connector expansion for Azure/Qdrant/Cloudflare remains a migration idea, not current implementation.** Current runtime code keeps those backends in plugin-owned settings rather than the Connectors UI.

## WordPress 7.0 Support

- **[implemented] The declared support floor is real in code and docs.** The plugin header declares `Requires at least: 7.0` and `Requires PHP: 8.0`, and the top-level docs repeat that floor. Evidence: `flavor-agent.php:3-10`, `docs/SOURCE_OF_TRUTH.md:3-6`.

- **[implemented] Some WP 7.0-specific assumptions are real runtime behavior.**
  - Block recommendations require the built-in AI client entry point `wp_ai_client_prompt()`. Evidence: `inc/LLM/WordPressAIClient.php:76-99`.
  - Block attribute role handling uses the stable `role` key only. Evidence: `src/context/block-inspector.js:123-125`, `inc/Context/ServerCollector.php:1085-1089`.
  - Navigation context explicitly includes the WP 7.0 `navigation-overlay` template-part area. Evidence: `inc/Context/ServerCollector.php:1168-1249`.

- **[partial] Current compatibility still relies on some pre-stable Gutenberg behavior, but the remaining risk is now narrower and explicit.** Pattern/category APIs still carry experimental fallbacks and may intentionally report `all-patterns-fallback` when no contextual selector exists, and theme-token source resolution still lands on `__experimentalFeatures` because no stable-parity source is verified yet. Evidence: `src/patterns/pattern-settings.js`, `src/patterns/inserter-dom.js`, `flavor-agent.php:102-128`, `src/context/theme-settings.js`, `src/context/theme-tokens.js`.

- **[partial] The local browser-verification story is still split rather than unified.** The repo’s checked-in smoke file contains active post-editor coverage plus active `@wp70-site-editor` refresh/drift cases, but `package.json` still points `npm run test:e2e` at the Playground-only harness, `playwright.config.js` explicitly excludes the tagged WP 7.0 cases, and there is still no `wp_template_part` browser smoke. That means WP 7.0 support is credible in code shape and in the separate Docker-backed harness, but not yet covered by the default e2e entry point. Evidence: `package.json`, `playwright.config.js`, `playwright.wp70.config.js`, `tests/e2e/flavor-agent.smoke.spec.js:855-977`.

## Test Coverage And Gaps

- **[implemented] Pattern recommendation backends now have direct PHPUnit isolation.** `PatternAbilitiesTest` covers backend gating, runtime-state handling, query construction, Qdrant retrieval/reranking, parse failure handling, and capped score shaping. `PatternIndexTest` covers fingerprinting, scheduling, full and incremental sync, removed-pattern deletion, lock contention, and remote failure persistence. Evidence: `tests/phpunit/PatternAbilitiesTest.php`, `tests/phpunit/PatternIndexTest.php`, `inc/Abilities/PatternAbilities.php:15-334`, `inc/Patterns/PatternIndex.php:12-458`.

- **[implemented] Template invalidation drift is covered at both helper and panel levels.** Runtime code clears recommendations when the normalized recommendation-context signature changes, including `visiblePatternNames`, helper tests cover normalization and signature stability, and the panel integration test proves same-template insertion-root drift clears stale cards and apply state without wiping the local prompt. Evidence: `src/templates/TemplateRecommender.js:351-399`, `src/templates/__tests__/template-recommender-helpers.test.js:72-109`, `src/templates/__tests__/TemplateRecommender.test.js`.

- **[partial] Browser coverage still stops short of the declared support contract.** The checked-in smoke suite verifies block, pattern, and template flows in the default Playground path, and the refresh/drift Site Editor undo cases are now active in the separate WP 7.0 harness. The remaining gaps are that `npm run test:e2e` still does not aggregate the WP 7.0 run and there is still no checked-in browser smoke for the template-part apply/undo flow. Evidence: `package.json`, `playwright.config.js`, `playwright.wp70.config.js`, `tests/e2e/flavor-agent.smoke.spec.js`.

## Documentation Status

- **[implemented] `docs/2026-03-24-review-follow-up-plan.md` now reads as closed execution history for the reviewed issues.** It no longer presents the three reviewed issues as current-open work, and its acceptance checklist now marks the template-context-drift coverage gap as complete alongside the runtime and doc fixes. Evidence: `docs/2026-03-24-review-follow-up-plan.md:5-13,25-30,391-399`.

- **[implemented] `docs/SOURCE_OF_TRUTH.md` now accurately describes `WordPressAIClient.php`.** The repo layout section reflects the actual `wp_ai_client_prompt()` and Connectors-backed availability behavior rather than a nonexistent `AI_Client` fallback. Evidence: `docs/SOURCE_OF_TRUTH.md:52-53`, `inc/LLM/WordPressAIClient.php:76-99`.

- **[docs-only] `docs/NEXT_STEPS_PLAN.md` is not a list of still-missing work through Phase 5.** It explicitly says phases 0-5 are execution history / validation context and that the open backlog starts later. Treating those phases as current gaps would overstate the mismatch between docs and code. Evidence: `docs/NEXT_STEPS_PLAN.md:3-7`.

- **[implemented] Top-level docs are now broadly aligned with the current codebase.** `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, and `docs/2026-03-24-review-follow-up-plan.md` now match the implemented block, pattern, template, settings, docs-grounding, and WP 7.0 compatibility posture. The major remaining backlog is narrower: a first-party navigation surface, durable activity history, expanded template-part execution, WP 7.0 adapter hardening, and unified browser verification with template-part smoke coverage.

## Top 5 Recommended Next Actions

Status note as of 2026-03-24 in the current source tree: the earlier pattern-backend and template-drift recommendations are now implemented. The real follow-up backlog is the smaller set below.

1. **Expose `recommend-navigation` through a first-party plugin surface.** The ability and prompt pipeline are real, but the shipped plugin still lacks a REST adapter and inspector experience for selected `core/navigation` blocks.
2. **Replace session-only activity history with a durable audit trail.** The current undo and recent-actions model works for same-session editor flows, but cross-session review and ordered undo eligibility are still absent.
3. **Expand the template-part executor beyond one start/end insertion.** The current contract is intentionally safe, but broader bounded placements and replacement/remove operations remain unshipped.
4. **Harden the remaining WP 7.0 compatibility adapters.** Pattern scoping still carries explicit fallbacks, and theme-token source selection still lands on `__experimentalFeatures` on the current runtime.
5. **Make default browser verification match the support claim.** The repo still needs an aggregate `npm run test:e2e` path that includes the active WP 7.0 harness plus a checked-in `wp_template_part` smoke flow.
