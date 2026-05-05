# Canonical AI Integration Remediation Validation - 2026-05-05

| Gate | Result | Notes |
|---|---|---|
| `vendor/bin/phpunit tests/phpunit/MCPServerBootstrapTest.php` | PASS | 4 tests, 16 assertions. Covers dedicated MCP bootstrap and transport capability policy. |
| `vendor/bin/phpunit tests/phpunit/RegistrationTest.php` | PASS | 26 tests, 457 assertions. Covers ability registration, MCP exposure metadata, and annotation policy. |
| `vendor/bin/phpunit tests/phpunit/FeatureBootstrapTest.php` | PASS | 7 tests, 18 assertions. Covers AI feature bootstrap and feature-toggle registration. |
| `vendor/bin/phpunit tests/phpunit/AgentRoutesTest.php` | PASS | 4 tests, 28 assertions. Covers active REST route contract and removed recommendation routes. |
| `npm run check:docs` | PASS | Docs freshness gate passed. |
| `npm run verify -- --skip-e2e` | PASS | `VERIFY_RESULT={"status":"pass","summaryPath":"output/verify/summary.json","counts":{"total":9,"passed":6,"failed":0,"skipped":3}}`; build, JS lint, Plugin Check, JS unit, PHP lint, and PHP unit passed. E2E suites skipped by request flag. |
| Live MCP `tools/list` on server `flavor-agent` | PASS | Ran through `wp mcp-adapter serve --user=admin --server=flavor-agent`; response listed seven direct recommendation tools, including `flavor-agent-recommend-block`, `flavor-agent-recommend-content`, `flavor-agent-recommend-patterns`, `flavor-agent-recommend-navigation`, `flavor-agent-recommend-style`, `flavor-agent-recommend-template`, and `flavor-agent-recommend-template-part`. |
| Live MCP `tools/call` on `flavor-agent-recommend-block` | PASS | Returned structured recommendation content with `isError:false` and request meta showing `ability:"flavor-agent/recommend-block"` and `executionTransport:"wp-abilities"`. The local runtime's AI feature options were temporarily enabled for this smoke and then deleted to restore the previous option state. |

Additional notes:

- Plugin Check passed with one warning for the intentional `error_log()` call in `inc/MCP/ServerBootstrap.php`.
- `git diff --check` was not used as a repository-wide completion gate because the working tree already contains unrelated dirty docs/IDE files with whitespace changes outside this remediation.
