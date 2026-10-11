# UI Theme Style Review Remediation

This record covers local remediation of the five confirmed review findings against the working tree based on `2ee79cd`. It is implementation and regression evidence, not an immutable release or production deployment qualification.

## Changes

1. PHP recommendation validation and the editor executor fail closed when an explicit element or block complement cannot be resolved by the existing hex/preset color resolver. Only a missing scoped declaration may inherit the root color.
2. Recommendation contrast checks overlay all validated style writes before resolving inherited colors, matching the final configuration used by apply executors. Block preset overrides and inherited global preset provenance remain separate. Theme variations mixed with readable color overrides still require separate review.
3. Mounted block apply owners supply a live request getter. Single, batch, and structural applies reject changed/cleared prompts, changed requests, canceled owners, and superseded server baselines after asynchronous preflight. Stale suggestions and the current draft remain visible.
4. Toast removal restores the original connected control, including same-origin nested iframe controls, while preserving focus that has moved elsewhere. A removed origin falls back to a surviving toast or the named region.
5. Activity Release invalidates pending claims immediately. Late own-claim/feed responses cannot restore released presence; abandoned self-claims receive best-effort cleanup without clearing a newer review. Foreign claims and terminal decision evidence remain visible, and claims never gate decisions.

## Regression Evidence

The nearest PHPUnit and Jest regressions failed on the original behavior before implementation. Focused suites cover both color sides, element/block scoping, final root-operation ordering, palette provenance, all three block apply paths, controlled/uncontrolled drafts, owner cancellation, toast focus ownership and fallback, claim release/selection/unmount, newer review ownership, and terminal evidence.

Playwright regressions run in both Playground and the Docker harness for prompt edits during held preflight, actual editor iframe shortcut/dismissal focus, explicit Release during a held focus claim, and unresolved scoped RGB text/background complements. Existing affected apply/activity/undo and stale-result workflows also run in the Docker harness.

## Environment

- Node `24.21.0`; host PHP `8.4.26`; Composer `2.10.3`.
- Chromium via Playwright `1.60.0`.
- Playground CLI `3.1.51`, WordPress `7.1`, PHP `8.3`, Gutenberg `23.9.0`.
- Docker: isolated project `flavor-agent-ui-remediation`, WordPress `7.1`, PHP `8.2.33`, Gutenberg `23.9.0`, AI `1.4.0`, Plugin Check `2.1.0`.
- Cached Docker base override `flavor-agent-wp70-wordpress:latest`, MariaDB `11.4`, ports `9414`/`9415`, and `FLAVOR_AGENT_WP70_RESET=0`. This avoids resetting any other local project.

## Results

| Gate | Final evidence |
| --- | --- |
| Strict non-browser aggregate | `pass`, 7 included gates passed, 0 failures; both aggregate browser steps intentionally excluded in favor of the targeted runs below |
| Jest | 125 suites, 2,373 tests passed |
| PHPUnit | 2,606 tests, 12,254 assertions passed under task-local UTC |
| Plugin Check | Completed against the staged built plugin with no errors |
| Build, JS/PHP lint, docs | Passed; modified E2E files also pass explicit JS lint |
| Playground | Seven application checks qualified: six initial passes plus one corrected claim-fixture pass, zero skips |
| Docker with Gutenberg | 27/27 passed: 26 application checks plus authentication, zero skips or retries |

The first aggregate passed build, JS lint, all 125 Jest suites / 2,373 tests, PHP lint, and docs checking. Plugin Check was interrupted (exit 143) when browser bootstrap recreated the Docker test container. The PHP suite reached 2,606 tests with three unchanged serializer fixture failures caused by the host default timezone, Europe/Berlin. The same serializer suite passes under UTC (20 tests / 64 assertions); its source, tests, and bootstrap are byte-identical to the baseline. The final aggregate passed with a task-local UTC ini override and a stable container. Its result is `output/verify/summary.json`; the first failed aggregate remains at `output/review/ui-theme-style-remediation/aggregate-first/`.

The initial Playground run used a claim fixture that mistook WordPress apiFetch's HTTP POST plus `X-HTTP-Method-Override: DELETE` for a claim POST and held the explicit Release behind the refresh gate. The fixture now normalizes the effective HTTP method. Playground's six unaffected cases passed, and the corrected claim case passed separately. The Docker run passed 27/27 checks (26 application scenarios plus authentication), including the corrected claim fixture. Across both harnesses, 33 application checks passed with zero skips; only Playground's claim scenario required a fixture-corrected rerun. These are qualified results across named runs; the failed reports are retained rather than relabeled as passing.

The non-browser gate includes Plugin Check and the docs check:

```sh
COMPOSE_PROJECT_NAME=flavor-agent-ui-remediation PLUGIN_CHECK_USE_DOCKER=1 \
  PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d:/home/ubuntu/flavor-agent/output/review/ui-theme-style-remediation/php-ini \
  node scripts/verify.js --strict --skip-e2e
```

The task-local `php-ini/99-wordpress-utc.ini` contains `date.timezone = UTC`, matching WordPress bootstrap without changing host configuration. Docker browser runs use the isolated project overrides above. The matching browser scope includes the new review regressions, admin decision permission checks, block normal/structural apply and stale preflight, template/template-part apply, and Global Styles/Style Book apply/undo and stale/contrast guards. Browser fixtures mock recommendation and delayed claim responses; these checks do not establish live provider behavior.

SHA-256 identities in `source-build-sha256.json` confirm that the product source, built JavaScript, and asset manifests remained unchanged across the qualified browser runs and final aggregate. Browser reports are `playground-first.json`, `playground-claim-fixed.json`, and `wp70-gutenberg.json`.

Local logs and machine-readable evidence are retained under `output/verify/` and `output/review/ui-theme-style-remediation/`. This record does not establish production deployment qualification.
