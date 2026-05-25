---
name: verify-pipeline
description: Use when about to run Flavor Agent's verification pipeline (`npm run verify`), choosing between full / fast / strict / skip-e2e / skip-plugin-check modes, or interpreting `output/verify/summary.json` (pass / fail / incomplete) after a run
---

# Verify Pipeline (Flavor Agent)

## Overview

`scripts/verify.js` is the canonical verification entry point. It runs `build` → `lint-js` → `lint-plugin` → `unit` → `lint-php` → `test-php` → `e2e-playground` → `e2e-wp70` in order, streams output, and writes a machine-readable summary at `output/verify/summary.json`.

The final stdout line is `VERIFY_RESULT={...}` — one-line JSON with `status`, `summaryPath`, `counts` for scripted parsing.

## Quick Reference

| Goal | Command |
|------|---------|
| Full pipeline | `npm run verify` |
| Strict (warnings = failure) | `npm run verify:strict` |
| Fast loop (skip both Playwright suites) | `npm run verify -- --skip-e2e` |
| Skip Plugin Check when WP-CLI unavailable | `npm run verify -- --skip=lint-plugin` |
| Run only a subset | `npm run verify -- --only=build,unit` |
| Preview plan as JSON | `npm run verify -- --dry-run` |
| Stop at first failure | `npm run verify -- --bail` |
| Suppress per-step streaming | `npm run verify -- --json` |
| Single PHP suite | `vendor/bin/phpunit --filter NameTest` |

## Choosing the mode

- **Fast loop while iterating** → `npm run verify -- --skip-e2e`. Covers build + lint-js + lint-plugin (if available) + unit + lint-php + test-php. Typically under a minute.
- **Before commit/PR on a single-surface change** → full `npm run verify`. E2E is the difference between "looks right" and "works in WP 7.0 Site Editor."
- **Multi-surface change or shared subsystem** → full verify is necessary but not sufficient. Also follow `docs/reference/cross-surface-validation-gates.md` (matching Playwright harness per surface, `check:docs` if contracts moved, recorded waivers if a harness is unavailable).
- **CI-style strict run** → `npm run verify:strict` (warnings flip to failures).

## Reading `output/verify/summary.json`

```json
{
  "schemaVersion": "...",
  "status": "pass" | "fail" | "incomplete",
  "counts": { "passed": N, "failed": N, "skipped": N },
  "environment": { ... },
  "steps": [
    {
      "name": "build",
      "status": "pass" | "fail" | "skip",
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

- `status: "pass"` → every required step passed.
- `status: "fail"` → at least one step exited non-zero. Read that step's `stderrPath` first.
- `status: "incomplete"` → a required tool was missing (typical: no WP-CLI, no Docker, no PHP). Coverage is reduced — **do not claim the change verified**.
- `--only` / `--skip` / `--skip-e2e` skips do NOT fail the run by themselves.

## Required-tool gotchas

- **`lint-plugin`** needs `bash`, `wp` (WP-CLI), and `WP_PLUGIN_CHECK_PATH` resolvable. When WP-CLI or WordPress root is unavailable, pass `--skip=lint-plugin` so the missing tool flips the run to `incomplete` rather than failing it.
- **`e2e-wp70`** needs Docker. Run `npm run wp:start` (or `npm run wp:e2e:wp70:bootstrap`) first. Without Docker, use `--skip-e2e`.
- **`e2e-playground`** runs in a Playground browser harness — usually works without Docker, but still requires Playwright browsers installed.
- **`lint-php` / `test-php`** need `composer install` to have run (PSR-4 autoloader + PHPUnit).

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | All required steps passed |
| `1` | Any failure, or a required tool was missing (status flips to `incomplete`) |
| `2` | Argument error |

## Common mistakes

- **Claiming verified when `status: "incomplete"`** — incomplete means a step was skipped due to missing tooling, not that everything passed. Surface the missing tool and either install it or record an explicit waiver.
- **Skipping E2E before commit because "it's slow"** — Playground smoke alone (`npm run test:e2e:playground`) is fast and catches regressions verify alone won't.
- **Running `vendor/bin/phpunit` and assuming that's enough** — it doesn't cover the JS store, abilities-bridge, or executable-surface contracts. Pair with `npm run test:unit`.
- **Re-running full verify after a one-line fix in a single surface** — `--only=lint-js,unit` (or the relevant subset) is the right fast-feedback loop until a final pre-commit full run.
