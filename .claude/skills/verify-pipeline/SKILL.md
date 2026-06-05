---
name: verify-pipeline
description: Use when working with Flavor Agent's verification runner, choosing verify modes, handling Plugin Check or E2E prerequisites, or interpreting `output/verify/summary.json`
---

# Verify Pipeline (Flavor Agent)

## Overview

`scripts/verify.js` is the canonical verification entry point. Default verify runs `build` -> `lint-js` -> `lint-plugin` -> `unit` -> `lint-php` -> `test-php` -> `e2e-playground` -> `e2e-wp70` in order, streams output, and writes a machine-readable summary at `output/verify/summary.json`.

Strict verify includes the optional `check-docs` step between `lint-php` and `test-php`.

The final stdout line is `VERIFY_RESULT={...}`: one-line JSON with `status`, `summaryPath`, and `counts` for scripted parsing.

## Quick Reference

| Goal | Command |
|------|---------|
| Full pipeline | `npm run verify` |
| Strict/docs-inclusive pipeline | `npm run verify:strict` |
| Fast loop (skip both Playwright suites) | `npm run verify -- --skip-e2e` |
| Skip Plugin Check intentionally | `npm run verify -- --skip=lint-plugin` |
| Run only a subset | `npm run verify -- --only=build,unit` |
| Run optional docs check through verifier | `npm run verify:strict -- --only=check-docs` |
| Preview plan as JSON | `npm run verify -- --dry-run` |
| Stop at first failure | `npm run verify -- --bail` |
| Suppress per-step streaming | `npm run verify -- --json` |
| Single PHP suite | `vendor/bin/phpunit --filter NameTest` |

## Choosing the mode

- **Fast loop while iterating**: `npm run verify -- --skip-e2e`. Covers build + lint-js + lint-plugin (if available) + unit + lint-php + test-php.
- **Before commit/PR on a single-surface change**: full `npm run verify`. E2E is the difference between "looks right" and "works in WP 7.0 Site Editor."
- **Multi-surface change or shared subsystem**: full verify is necessary but not sufficient. Also follow `docs/reference/cross-surface-validation-gates.md` for targeted suites, matching Playwright harnesses, docs checks, and recorded waivers.
- **Docs or contributor-facing changes**: run `npm run check:docs`, or `npm run verify:strict` when the docs check should be recorded in `output/verify/summary.json`.

## Reading `output/verify/summary.json`

```json
{
  "schemaVersion": 1,
  "status": "pass" | "fail" | "incomplete",
  "counts": { "total": N, "passed": N, "failed": N, "skipped": N },
  "environment": { ... },
  "steps": [
    {
      "name": "build",
      "command": "npm run build",
      "status": "pass" | "fail" | "skipped",
      "exitCode": 0,
      "durationMs": 1234,
      "startedAt": "...",
      "finishedAt": "...",
      "stdoutPath": "output/verify/build.stdout.log",
      "stderrPath": "output/verify/build.stderr.log"
    }
  ]
}
```

- `status: "pass"`: every included, required step passed. Explicit `--only`, `--skip`, and `--skip-e2e` exclusions do not fail the run by themselves.
- `status: "fail"`: at least one included step exited non-zero. Read that step's `stderrPath` first.
- `status: "incomplete"`: an included step could not run because a required tool or context was missing, or no step actually completed. Coverage is reduced; do not claim the change verified.
- Step-level `status: "skipped"` may be intentional (`--skip`, `--skip-e2e`, not in `--only`) or incomplete (`incomplete: true` with a missing-prerequisite reason).
- Optional steps such as `check-docs` are excluded unless `--strict` is present. To run only `check-docs` through the verifier, use `npm run verify:strict -- --only=check-docs`.

## Required-tool gotchas

- **`lint-plugin`** needs `bash` plus one Plugin Check context:
  - Host path: `wp` (WP-CLI) and a resolvable WordPress root via `WP_PLUGIN_CHECK_PATH` or the repo-relative fallback.
  - Docker path: `PLUGIN_CHECK_USE_DOCKER=1` or `true`, `docker`, and the `wordpress` compose container running. This path does not need host `wp`.
- When Plugin Check is intentionally out of scope for a local loop, pass `--skip=lint-plugin`. Treat that as an explicit coverage reduction, not a full local release signal.
- When Plugin Check is in scope but prerequisites are missing, leave `lint-plugin` included so `summary.json` records `status: "incomplete"` and the missing-prerequisite reason.
- **`e2e-wp70`** needs Docker. Run `npm run wp:start` or `npm run wp:e2e:wp70:bootstrap` first. Without Docker, use `--skip-e2e` for local iteration and record a waiver when browser proof is required.
- **`e2e-playground`** runs in a Playground browser harness. It usually works without Docker, but still requires Playwright browsers installed.
- **`lint-php` / `test-php`** need `composer install` to have run for the PSR-4 autoloader, PHPCS, and PHPUnit.
- **`check-docs`** needs `rg` (ripgrep). It is optional and only included in `--strict` / `verify:strict`; when included and `rg` is missing, the run is `incomplete`.

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | All requested steps passed |
| `1` | A step failed or an included step was implicitly skipped because a required tool/context was missing |
| `2` | Argument or verifier crash error |

## Common mistakes

- **Claiming verified when `status: "incomplete"`**: incomplete means an included step could not run, not that everything passed. Surface the missing tool and either install it or record an explicit waiver.
- **Treating `--skip=lint-plugin` as equivalent to Plugin Check passing**: explicit skip is useful for local iteration, but full local sign-off still needs Plugin Check evidence or a recorded waiver.
- **Running `--only=check-docs` without `--strict`**: optional steps are filtered unless strict mode is present, so this plans zero runnable steps and produces an incomplete run if executed.
- **Skipping E2E before commit because "it's slow"**: Playground smoke alone (`npm run test:e2e:playground`) is fast and catches regressions verify alone won't.
- **Running `vendor/bin/phpunit` and assuming that's enough**: it does not cover the JS store, abilities bridge, or executable-surface contracts. Pair with `npm run test:unit`.
- **Re-running full verify after a one-line fix in a single surface**: `--only=lint-js,unit` or the relevant subset is the right fast-feedback loop until a final pre-commit full run.
