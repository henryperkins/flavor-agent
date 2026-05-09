# Gutenberg 23.1 And AI 0.9 Validation

Snapshot date: 2026-05-09.

## Runtime Versions

| Component | Expected value | Observed value |
| --- | --- | --- |
| WordPress core | `7.1-alpha-62341` or newer trunk nightly | `7.1-alpha-62341` |
| Gutenberg plugin | `23.1.1` | `23.1.1` |
| AI plugin | `0.9.0` | `0.9.0` |

## WP-CLI Smoke Checks

| Check | Command | Expected outcome | Observed outcome |
| --- | --- | --- | --- |
| Release plugins active | `wp plugin list --name=gutenberg,ai --fields=name,status,version,update --format=json` | Gutenberg and AI active with no pending update | Gutenberg `23.1.1` active with `update:none`; AI `0.9.0` active with `update:none` |
| Guidelines post type | `wp post-type list --format=json` | includes `wp_guideline`; does not require `wp_content_guideline` | `wp_guideline` exists; `wp_content_guideline` is not required |
| Guidelines taxonomy | `wp taxonomy list --format=json` | includes `wp_guideline_type` | `wp_guideline_type` exists |
| Content guideline query | temporary `wp_guideline` with `wp_guideline_type=content` | Flavor Agent query returns exactly the content guideline row | `wp_guideline` exists, `wp_guideline_type` exists, and a temporary `wp_guideline_type=content` row was returned by the expected tax query before cleanup. |
| AI developer option | `wp option get wpai_feature_flavor-agent_field_developer --format=json` | unset or sanitized provider/model object | option unset in the baseline container |

## Upstream Follow-Up

AI plugin `0.9.0` includes an internal Guidelines service that queries the latest `wp_guideline` without filtering `wp_guideline_type=content`. Upstream issue filed: https://github.com/WordPress/ai/issues/529

## Provider And Model Selection

Flavor Agent honors the AI plugin Developer Tools per-feature option `wpai_feature_flavor-agent_field_developer` when no explicit provider argument is supplied. If the option contains both provider and model, `WordPressAIClient::chat()` resolves the model through the WordPress AI Client registry and records the selected provider/model in Activity Log request diagnostics. If model resolution fails, the request falls back to provider-managed model selection and records `model_resolution_failed_provider_fallback` plus the resolution error message.

Explicit per-call provider arguments retain highest precedence over the AI plugin per-feature option.

## Multisite Connector State

`vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat` passed. Flavor Agent settings state treats the WordPress AI Client runtime as ready when the AI Client reports text-generation support. This is downstream readiness evidence only; network-active plugin behavior remains unclaimed unless the disposable multisite smoke check is run.

## Verification Commands

```bash
npx wp-scripts lint-js assets/abilities-bridge.js src/store/abilities-client.js src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js
npm run test:unit -- src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js --runInBand
vendor/bin/phpunit --filter GuidelinesTest
vendor/bin/phpunit --filter WordPressAIClientTest
vendor/bin/phpunit --filter RecommendationAbilityExecutionTest::test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity
vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat
npm run test:e2e:playground
npm run check:docs
node scripts/verify.js --skip-e2e
```

## Final Verification

| Command | Result |
| --- | --- |
| `npx wp-scripts lint-js assets/abilities-bridge.js src/store/abilities-client.js src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js` | Pass |
| `npm run test:unit -- src/store/__tests__/abilities-client.test.js src/store/__tests__/abilities-bridge.test.js --runInBand` | Pass |
| `vendor/bin/phpunit --filter GuidelinesTest` | Pass |
| `vendor/bin/phpunit --filter WordPressAIClientTest` | Pass |
| `vendor/bin/phpunit --filter RecommendationAbilityExecutionTest::test_execute_persists_resolved_provider_fields_in_request_diagnostic_activity` | Pass |
| `vendor/bin/phpunit --filter SettingsTest::test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat` | Pass |
| `npm run test:e2e:playground` | Pass |
| `npm run check:docs` | Pass |
| `node scripts/verify.js --skip-e2e` | Pass |

`npm run test:e2e:wp70` was not run because the implemented provider/model routing is covered at the shared PHP client and Activity Log metadata layer, and this branch did not change a Site Editor-specific UI flow beyond the Playground-covered surfaces.
