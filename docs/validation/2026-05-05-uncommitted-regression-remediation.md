# Uncommitted Regression Remediation Validation

Date: 2026-05-05

| Command | Result | Notes |
| --- | --- | --- |
| `composer run test:php -- --filter 'PluginLifecycleTest\|FeatureBootstrapTest\|ProviderTest\|ResponsesClientTest\|SettingsTest\|EmbeddingBackendValidationTest\|PatternAbilitiesTest\|InfraAbilitiesTest'` | PASS | Focused coverage for lifecycle feature-toggle writes, provider precedence, settings focus, and adjacent pattern/chat contracts. |
| `composer run test:php` | PASS | Full PHP suite: 1082 tests, 4680 assertions. |
| `npm run test:unit -- --runInBand` | PASS | Full JS unit suite: 84 suites, 1036 tests. |
| `npm run check:docs` | PASS | Docs freshness gate. |
| `npm run verify -- --skip-e2e` | PASS | Fast aggregate verifier: `VERIFY_RESULT` status `pass`, 6 passed, 0 failed, 3 skipped. |
| `git diff --check` | PASS | Final whitespace gate after validation doc creation. |
