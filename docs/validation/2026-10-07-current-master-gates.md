# Current master validation - 2026-10-07

Repository identity: `1cae8e14d6a90bc9f6ff81a7afccb8e7d7689270`
(`master`, matching `origin/master` at the start of validation).

Evidence snapshot: `2026-10-07T09:11:46Z`. Both corrected Docker Site Editor
legs have completed.

## Result and limits

The current-master PHP, JavaScript, build, documentation, Plugin Check,
Playground and both corrected Docker Site Editor checks completed successfully.
These are separately scoped runs; no single all-steps strict verification pass
is claimed.

The browser recommendation fixtures exercise editor behavior. They do not prove
a live Anthropic request, production deployment, or real-site recommendation
quality. No product source or outcome/ranking contracts were changed by these
checks.

Anthropic connector `1.0.5` was installed only after this baseline validation.
The subsequent live-provider probe is separate evidence and is not included in
these browser results.

## Completed checks

| Check | Command / included steps | Result | Local evidence |
| --- | --- | --- | --- |
| PHP and docs | Verifier with `--strict --only=lint-php,test-php,check-docs` | Three included steps passed; six other steps intentionally excluded. PHPUnit: 2,467 tests, 11,656 assertions. | `output/verify-oct07-master-php-docs/summary.json` and sibling logs |
| JavaScript and build | Verifier with `--only=build,lint-js,unit` | Three included steps passed; six other steps intentionally excluded. Jest: 119 suites, 2,092 tests. | `output/verify-oct07-master-js/summary.json` and sibling logs |
| Plugin Check | `npm run lint:plugin` through the Docker-backed checker | Completed: `Success: Checks complete. No errors found.` | `output/verify-oct07-master-gutenberg/lint-plugin.stdout.log` and `.stderr.log` |
| Playground | `node scripts/verify.js --only=e2e-playground --json --output=output/verify-oct07-master-playground` | One included step passed; eight other steps intentionally excluded; `options.strict: false`. Playwright: 28 passed, 8.7 minutes. | `output/verify-oct07-master-playground/summary.json` and sibling logs |

The PHP/docs run started at `2026-10-07T08:29:07.258Z` and finished at
`2026-10-07T08:30:06.052Z`. The JavaScript/build run started at
`2026-10-07T08:29:33.993Z` and finished at `2026-10-07T08:33:23.446Z`.
Playground ran from `2026-10-07T08:36:25.080Z` to
`2026-10-07T08:45:09.199Z`.

The recorded host environment was Windows x64, Node `24.16.0`, npm `11.13.0`,
Composer `2.10.1`, PHP CLI `8.3.33`, and Docker CLI `29.8.2`. The completed
summary files contain the individual step commands, exit codes and durations.

## Docker Site Editor evidence

The original attempt failed and was aborted after a collision was established:
two Docker daemons exposed different harnesses on host port `9404`. Preserve that
failure; it is not a passing Site Editor result and is not used to claim a
product defect.

Original browser artifacts are retained under
`output/validation/2026-10-07/master-1cae8e1/docker-port-collision/`.
This directory includes authentication state and traces and remains local; its
contents must not be committed or shared wholesale.

The corrected harness uses host ports `19404` and `19405`. Its baseline runtime
was WordPress `7.1`, AI plugin `1.4.0`, MCP Adapter `0.7.0` and Plugin Check
`2.1.0`.

| Editor leg | Status | Evidence location |
| --- | --- | --- |
| Pinned Gutenberg `23.9.0`, full suite | Passed: 40 tests, 9.5 minutes | `output/verify-oct07-master-gutenberg-rerun/summary.json` and sibling logs |
| Bundled editor, full suite | Passed: 40 tests, 8.6 minutes; Gutenberg inactive before and after this leg | `output/verify-oct07-master-bundled/summary.json` and sibling logs |

Both runs included only `e2e-wp70` through the verifier, invoked
`npm run test:e2e:wp70`, returned exit code `0`, and recorded
`options.strict: false`. Each summary has one passed step and eight intentionally
excluded steps. The retained `wp70` harness name covers this WordPress 7.1
runtime.

The Gutenberg leg ran from `2026-10-07T08:50:49.320Z` to
`2026-10-07T09:00:20.846Z`; the bundled leg ran from
`2026-10-07T09:01:08.016Z` to `2026-10-07T09:09:44.958Z`. These successful
corrected runs supplement rather than erase the original collision evidence.

## Validated source and build identity

A direct comparison found the primary checkout and corrected Docker plugin
copy identical for the following relevant source and build files. SHA-256
values were also calculated directly from the primary files for this record.
This is a four-file identity check, not a whole-tree or deployment comparison.

| File | Bytes | Matching SHA-256 |
| --- | ---: | --- |
| `inc/LLM/WordPressAIClient.php` | 66,124 | `671e0646b43721ca33c8051264cf34390dd015c7ab7b34216aa744f589214201` |
| `flavor-agent.php` | 14,622 | `03e8b97c02b27b9f59b88d6637f4fc6998c20a620949eb305a89b752d5dce92e` |
| `build/index.js` | 566,815 | `afb57faac4ca6a49eb8378579043760be7eb8a0b640784ddbfed036da22c2e1e` |
| `build/activity-log.js` | 2,057,223 | `a6589345cd33d21c4264609acda3d7a47653d8a4efc6c591d3d2cbce4fb677b3` |

## Candidate archive

Local artifact: `output/validation/2026-10-07/master-1cae8e1/flavor-agent.zip`.

- Size: `1,291,882` bytes.
- Archive inventory: `216` entries.
- SHA-256: `42dc3f7a1ec94dd309654ca92c1b5f4fbbcb538f2bd466f893b4246f029ae1e2`.
- Inventory file: `output/validation/2026-10-07/master-1cae8e1/archive-inventory.txt`.

The inventory includes the plugin bootstrap, built assets, application PHP,
shared contract data and Composer runtime files. It excludes `.github/`,
`.claude/`, `.codex/`, `tests/`, `scripts/`, `docs/`, `node_modules/`, `output/`,
`dist/`, `AGENTS.md`, `CLAUDE.md`, `STATUS.md`, `composer.lock`, `package.json`
and `package-lock.json`. `composer.json` is included.

This is a local candidate artifact identity, not a release, deployment or
exact-tag reverification.

## Completed artifact identities

Paths below refer to retained local outputs; raw artifacts are not committed.

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `output/verify-oct07-master-php-docs/summary.json` | 3,188 | `6014ddc5b77cfb2218dfa66610095b640a84fb9c12f8fc43794e376b5378d887` |
| `output/verify-oct07-master-js/summary.json` | 3,133 | `999751f36768ac3e82ab49f4eec1ad26b32b2b7a1d0f5cd450fd3f7975c14506` |
| `output/verify-oct07-master-playground/summary.json` | 2,672 | `f4032eb4b9ed78d902271a0b2534af3372f2b15dfd1d4345853622e505655cff` |
| `output/verify-oct07-master-gutenberg/lint-plugin.stdout.log` | 129 | `93e30a7c46473360646b53ffe8184362825544d870b98f6e3e88169505d4bea3` |
| `output/verify-oct07-master-gutenberg/lint-plugin.stderr.log` | 8,501 | `2da9bdd94d37169bfe3b687959bccfd327bd3fa469be47976be8ca073558b97a` |
| `output/verify-oct07-master-gutenberg-rerun/summary.json` | 2,642 | `a61b5eee49b3daeef37cd85bca258b6e9e91e11430d3b31bcc7d37cee83015ef` |
| `output/verify-oct07-master-gutenberg-rerun/e2e-wp70.stdout.log` | 7,513 | `4e9dbf41be89c3613f82ffe1845b58f936c303337d3cf3f402e8ed5601d0f98f` |
| `output/verify-oct07-master-gutenberg-rerun/e2e-wp70.stderr.log` | 656 | `56a89196d4b8372a74a65f995d25d27feb56589c2482fac777e6ddcc382bfb91` |
| `output/verify-oct07-master-bundled/summary.json` | 2,642 | `12a0f7d9705e346bcebc0b817346cef5b58a6031931d567d8b2bb3ade7ab7cb0` |
| `output/verify-oct07-master-bundled/e2e-wp70.stdout.log` | 7,510 | `606d937d562a415aa5af24c1f6c5669573d02f7c36679fca0264b4967ac5792f` |
| `output/verify-oct07-master-bundled/e2e-wp70.stderr.log` | 656 | `c74d07a67012b434f3e1bfa821b56d06273954868646e1ab4f976bd358073eab` |
| `output/validation/2026-10-07/master-1cae8e1/archive-inventory.txt` | 10,774 | `4e53b19dafc903f4cddd5a9f24b29794317363892f3d1a6f0b0b75ccbfdd3db8` |

## Remaining evidence

- Retain the completed logs, summaries and recorded artifact identities.
- Evaluate the complete evidence against the existing
  [cross-surface validation gates](../reference/cross-surface-validation-gates.md).
- Keep the [corpus scheduler record](2026-10-07-public-corpus-scheduler.md) and any
  later live-provider proof separate from these deterministic local checks.

The standing local release procedure remains the one documented in the existing
gate reference. This record changes no runner policy and does not claim
independent-runner evidence.
