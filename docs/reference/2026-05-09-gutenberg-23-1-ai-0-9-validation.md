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
| Content guideline query | temporary `wp_guideline` with `wp_guideline_type=content` | Flavor Agent query returns exactly the content guideline row | temporary `content` row was returned and then deleted |
| AI developer option | `wp option get wpai_feature_flavor-agent_field_developer --format=json` | unset or sanitized provider/model object | option unset in the baseline container |

## Upstream Follow-Up

AI plugin `0.9.0` includes an internal Guidelines service that queries the latest `wp_guideline` without filtering `wp_guideline_type=content`. File the upstream issue described in Task 6 and add the final GitHub issue URL to this section before the implementation branch is complete.

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
