# Admin Settings Page Review Prompt

Date: 2026-04-28

Purpose: Evidence-first review of the settings-page surface for security, validation, state, provider, and doc-grounding behavior.

```text
You are reviewing the Flavor Agent admin settings page implementation. Treat this as a review-only pass: do not modify files, do not rewrite components, and do not propose broad redesigns unless you can tie them to a concrete bug, security issue, privacy leak, validation issue, runtime-contract mismatch, accessibility/responsive issue, missing test, or documented contract drift.

Scope
- Primary surface: the WordPress admin settings page at `Settings > Flavor Agent` (`options-general.php?page=flavor-agent`, page slug `flavor-agent`).
- Include `Settings > Connectors`, AI Activity, editor recommendation surfaces, provider/runtime code, pattern sync, and docs grounding only where they define, consume, validate, explain, or depend on settings-page state.
- Trace the complete path before making findings: admin menu registration, page-load hooks, contextual Help, asset enqueue/localized boot data, Settings API registration, field rendering, sanitization/validation, feedback redirect state, provider/runtime selection, pattern sync REST path, settings-page JS behavior, CSS states, tests, and docs.
- Keep findings evidence-first. Grep hits are leads, not findings; open the file and confirm the actual runtime path before flagging.
- Keep the review anchored to the current checkout. Do not rely on stale plans, old docs, or assumptions when the live code says otherwise.

Inspect at minimum
- `flavor-agent.php`
- `inc/Settings.php`
- `inc/Admin/Settings/Config.php`
- `inc/Admin/Settings/Assets.php`
- `inc/Admin/Settings/Help.php`
- `inc/Admin/Settings/Page.php`
- `inc/Admin/Settings/Registrar.php`
- `inc/Admin/Settings/Fields.php`
- `inc/Admin/Settings/Validation.php`
- `inc/Admin/Settings/Feedback.php`
- `inc/Admin/Settings/State.php`
- `inc/Admin/Settings/Utils.php`
- `inc/OpenAI/Provider.php`
- `inc/LLM/ChatClient.php`
- `inc/LLM/WordPressAIClient.php`
- `inc/AzureOpenAI/ResponsesClient.php`
- `inc/AzureOpenAI/EmbeddingClient.php`
- `inc/AzureOpenAI/QdrantClient.php`
- `inc/Cloudflare/AISearchClient.php`
- `inc/Patterns/PatternIndex.php`
- `inc/REST/Agent_Controller.php`
- `inc/Abilities/InfraAbilities.php`
- `inc/Abilities/SurfaceCapabilities.php`
- `src/admin/settings-page.js`
- `src/admin/settings-page-controller.js`
- `src/admin/settings.css`
- `src/admin/brand.css`
- `src/admin/wpds-runtime.css`
- `src/admin/dataviews-runtime.css`
- `src/utils/capability-flags.js`
- `tests/phpunit/SettingsTest.php`
- `tests/phpunit/ProviderTest.php`
- `tests/phpunit/AzureBackendValidationTest.php`
- `tests/phpunit/AgentControllerTest.php`
- `tests/phpunit/InfraAbilitiesTest.php`
- `tests/phpunit/bootstrap.php`
- `src/admin/__tests__/settings-page-controller.test.js`
- `src/utils/__tests__/capability-flags.test.js`
- `tests/e2e/flavor-agent.settings.spec.js`
- `tests/e2e/flavor-agent.smoke.spec.js`
- `docs/features/settings-backends-and-sync.md`
- `docs/reference/provider-precedence.md`
- `docs/reference/abilities-and-routes.md`
- `docs/reference/shared-internals.md`
- `docs/reference/cross-surface-validation-gates.md`
- `docs/FEATURE_SURFACE_MATRIX.md`
- `docs/SOURCE_OF_TRUTH.md`
- `readme.txt`
- `STATUS.md`

Review focus areas

1. Admin access, menu registration, assets, and Help
- Confirm the page is registered only for the intended capability (`manage_options`) and that assets enqueue only on the settings page.
- Verify page-load hooks, contextual Help tabs/sidebar, quick links, and admin URLs are registered on the correct screen and escaped correctly.
- Check localized boot data, nonces, REST base URLs, and screen-detection fallbacks for robustness without leaking data to other admin pages.
- Confirm generated assets are consumed correctly, but do not hand-edit `build/` or release artifacts during the review.

2. Settings API registration and field rendering
- Trace every option from `Registrar::register_settings()` to its field renderer and sanitizer.
- Verify option names, defaults, `type`, `sanitize_callback`, section grouping, field IDs, descriptions, autocomplete values, and rendered form controls match the current UI contract.
- Confirm text, textarea, select, hidden feedback fields, and dynamic guideline block controls escape output correctly and preserve accessibility relationships such as `label_for` and `aria-describedby`.
- Check that page copy clearly separates required chat setup from optional pattern, docs-grounding, and guideline settings.

3. Sanitization, validation, and save feedback
- Review `Validation` end to end for Azure, OpenAI Native, Qdrant, Cloudflare override, pattern thresholds/counts, reasoning effort, and guidelines.
- Confirm validation runs only when the relevant submitted values changed and enough data is present, while unchanged credentials skip unnecessary remote validation.
- Verify invalid remote credentials preserve previous saved values, report Settings API errors once per save, and focus/open the correct section after redirect.
- Check nonce, referer, request-fingerprint, transient feedback, and `settings-updated` flows for cross-user leakage, stale feedback, duplicate notices, and lost errors.
- Confirm sensitive values are sanitized without being logged, echoed, stored in transient feedback, or surfaced in notices.

4. Provider, credential, and runtime precedence
- Compare settings UI and docs against `Provider::normalize_provider()`, `Provider::choices()`, `Provider::chat_configuration()`, `Provider::embedding_configuration()`, `Provider::active_chat_request_meta()`, and OpenAI Native credential-source helpers.
- Verify the Connectors-first chat contract: connector-backed providers pin chat explicitly, OpenAI Native may map only to the OpenAI connector, and generic WordPress AI Client provider fallback stays disabled so unselected providers are not used implicitly.
- Confirm pattern embeddings remain plugin-managed and do not accidentally use connector-only chat credentials.
- Check OpenAI Native key-source precedence, source labels, connector registration/configuration status, env/constant/database precedence, and owner/path labels.
- Verify dropdown choices, saved provider normalization, selected-provider diagnostics, and runtime messages cannot overclaim that the selected direct provider is active when a matching connector is actually serving chat.

5. Page state, status cards, and section behavior
- Trace `State::get_page_state()`, default-open-section logic, group card metadata, section badges, setup glance cards, and section status blocks.
- Confirm status labels/tone values are consistent between PHP-rendered state, JS-updated state, CSS classes, tests, docs, and `STATUS.md`.
- Verify forced feedback sections override local persisted state only when intended, and normal accordion persistence does not hide urgent validation errors.
- Check empty, partial, configured, stale, failed, retrying, warming, and syncing states for chat, patterns, docs grounding, and guidelines.

6. Pattern sync and REST behavior
- Trace the `Sync Pattern Catalog` panel from `src/admin/settings-page-controller.js` to `POST /flavor-agent/v1/sync-patterns` and `PatternIndex::sync()`.
- Verify nonce handling, permissions, disabled/prerequisite states, duplicate-click behavior, loading spinner, live region text, notice rendering, and runtime-state updates.
- Confirm sync response metadata, indexed counts, last sync time, stale reasons, last errors, collection name, and embedding dimension are rendered honestly and do not require full page reloads.
- Check that pattern sync cannot bypass readiness checks, locking rules, or admin-only access.

7. Guidelines import/export and block guidance
- Review site/copy/image/additional guidelines plus block-specific guideline storage.
- Confirm guideline text is sanitized and escaped consistently and that JSON import/export does not allow malformed state, hidden field corruption, script injection, or unsaved changes being mistaken for persisted settings.
- Verify block guideline select/list/edit/cancel/save behavior handles duplicate blocks, empty blocks, long text, deleted options, and translated labels.

8. Docs grounding and Cloudflare override
- Confirm built-in docs grounding, optional Cloudflare override fields, diagnostics panels, prewarm status, and runtime grounding state all match docs.
- Verify Cloudflare validation only accepts trusted `developer.wordpress.org` results and preserves existing values on failed override validation.
- Check prewarm status, retry/degraded/error messaging, warmed/failed counts, timestamps, and cache-first/non-blocking claims against the live implementation.

9. UI, CSS, WordPress Design System, and accessibility
- Review `src/admin/settings.css`, `src/admin/brand.css`, and runtime CSS shims for fragile assumptions about generated WordPress admin markup, overflow, responsive layout, status badges, accordions, subpanels, notices, forms, and sync metrics.
- Confirm long labels, translated strings, URLs, secret-like placeholders, validation messages, and guideline text do not overlap or break the layout across common wp-admin widths.
- Check that success/warning/error/accent/neutral states are distinguishable without relying on color alone.
- Avoid subjective taste findings; flag measurable accessibility, overflow, contrast, responsive, or contract drift only.

10. Tests and documentation
- Verify the closest PHP, JS unit, and Playwright tests cover any behavior being relied on by the settings page.
- If you find missing coverage, name the exact test file and scenario it should cover.
- Confirm docs in `docs/features/settings-backends-and-sync.md`, `docs/reference/provider-precedence.md`, route/reference docs, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/SOURCE_OF_TRUTH.md`, `readme.txt`, and `STATUS.md` match current behavior.
- Treat changes touching provider routing, settings validation, pattern sync, docs grounding, or surface readiness as cross-surface contract changes that require the additive gates in `docs/reference/cross-surface-validation-gates.md`.

Suggested verification commands
- `composer run test:php -- --filter '(SettingsTest|ProviderTest|AzureBackendValidationTest|AgentControllerTest|InfraAbilitiesTest)'`
- `npm run test:unit -- --runInBand src/admin/__tests__/settings-page-controller.test.js src/utils/__tests__/capability-flags.test.js`
- `npm run build`
- `npm run lint:js -- --quiet`
- `vendor/bin/phpcs --standard=phpcs.xml.dist inc/Settings.php inc/Admin/Settings/Config.php inc/Admin/Settings/Assets.php inc/Admin/Settings/Help.php inc/Admin/Settings/Page.php inc/Admin/Settings/Registrar.php inc/Admin/Settings/Fields.php inc/Admin/Settings/Validation.php inc/Admin/Settings/Feedback.php inc/Admin/Settings/State.php inc/Admin/Settings/Utils.php inc/OpenAI/Provider.php inc/REST/Agent_Controller.php tests/phpunit/SettingsTest.php tests/phpunit/ProviderTest.php tests/phpunit/AzureBackendValidationTest.php tests/phpunit/AgentControllerTest.php tests/phpunit/InfraAbilitiesTest.php`
- `npx playwright test tests/e2e/flavor-agent.settings.spec.js`
- `npm run check:docs`
- `git diff --check`

Output format
1. Start with findings first, ordered by severity (`P0`, `P1`, `P2`, `P3`).
2. For each finding include: title, exact file/line references, observed behavior, impact, and the smallest practical fix.
3. Keep confirmed findings and open questions separate. Add an "Open Questions / Assumptions" section only if you verified a gap.
4. Include a short "Verification Reviewed" section naming commands you ran and commands you did not run.
- If there are no findings, say so plainly and list any remaining test or environment gaps.
6. Keep conclusions checkout-specific and evidence-backed. Confirm behavior from live code paths and do not treat stale docs or plans as higher priority than code.
```
