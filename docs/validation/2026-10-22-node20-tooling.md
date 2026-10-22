# Node 20 installation and Jest ESM validation — October 22, 2026 UTC

This follow-up starts at `1c5c8bf92312522160f698cb10116778ae825a49` and resolves the installation and Jest limitations recorded in the [Minimatch validation](2026-10-22-minimatch-nested-extglob.md). Normal repository installation, build, lint, and unit tests work on both qualified Node runtimes. The auxiliary Repomix CLI runs on Node 24; it is deliberately omitted from Node 20 installations.

Plugin application source, declared Node/npm ranges, and the Developer Docs grounding default (`false`) remain unchanged. No runtime configuration or remote service was changed.

## Compatible installation and module boundaries

Repomix moves from `devDependencies` to `optionalDependencies`, with its minimum raised from `^1.13.1` to `^1.14.1`. The locked version remains **1.14.1**. Under the existing `engine-strict=true` policy, npm skips this incompatible optional package on Node 20 and installs it on Node 24. Repository build, lint, and test scripts do not require Repomix. The [setup guide](../reference/local-environment-setup.md) explains switching to Node 24 and reinstalling before using that CLI.

Repomix 1.14.0 is the last release with Node 20 support, but [1.14.1's release notes](https://github.com/yamadashy/repomix/releases/tag/v1.14.1) identify security fixes for Git remote-branch argument injection and an MCP secret-scan bypass, alongside the Node 20 support removal. A downgrade would lose those fixes. This change retains the fixed package and its engine declaration; it does not force that package onto an unsupported runtime or weaken engine checks.

The lockfile adds or removes no package records and changes no package version, URL, integrity, engine, or dependency edge. Its 144 changed records comprise the root dependency classification and 143 packages' `dev`, `optional`, and `devOptional` metadata. Existing Handlebars and Minimatch fixes retain their installed versions.

Jest now transforms Marked and `@wordpress/theme` through the existing WordPress Babel transformer beside the existing UUID exception. A narrow `.mjs` rule handles Theme's published entry. The package test command and application modules remain unchanged. The interoperability regression loads the real Marked, ThemeProvider, UUID, and WordPress blocks implementations; it checks Markdown rendering, ThemeProvider loading, UUID output, and nested block cloning. No dependency is mocked at that boundary.

## Reproduced failures and final qualification

The baseline strict Node 20 installation fails with `EBADENGINE` on Repomix 1.14.1. The baseline interoperability test fails while loading Marked's ESM entry. Allowing Marked alone restores most suites but exposes three UI suites importing Theme's `.mjs` entry. Both regression failures were reproduced before the matching configuration changes.

Final checks use actual clean installations in the Git worktree, with each native runtime first in `PATH`:

| Check | Node 20.20.2 / npm 10.8.2 | Node 24.21.0 / npm 11.19.0 |
| --- | --- | --- |
| `npm ci --no-audit --no-fund --engine-strict=true` | Pass; Repomix package and CLI omitted | Pass; Repomix 1.14.1 installed |
| `node scripts/verify.js --only=build,lint-js,unit` | Three selected gates pass | Three selected gates pass |
| Full Jest suite | 127 suites / 2,433 tests pass | 127 suites / 2,433 tests pass |
| Generated build artifacts | All 12 match the baseline | All 12 match baseline and Node 20 |

The four focused interoperability/UI suites pass **118 tests on each runtime**. Independent review repeats those suites on Node 20 with `--no-cache` and finds no actionable issue in the final manifest, lock, or Jest changes. The changed Jest configuration and regression file also pass ESLint.

Repomix's real CLI produces XML, Markdown, and plain outputs on Node 24 with security checking enabled. Each output includes both fixture files and their expected content. All **313 installed Repomix files** match their pre-change SHA-256 values.

`AISearchClientTest` passes **34 tests / 160 assertions**, including the four default-disabled/no-HTTP release checks. `npm run check:docs` and `git diff --check` pass. The fresh full-lock audit is unchanged at **53 high / 51 moderate / 0 critical**, with identical flagged package names; those findings remain open.

An exploratory copy outside Git passed installation, build, and lint, but its unit run had one failure because the commit-provenance test requires a real Git `HEAD`. That failure is retained in the evidence and is not counted as a passing run. The final matrix above uses the actual Git worktree and passes that test on both runtimes.

## Scope and evidence

Both verifier reports explicitly exclude six other gates. Full PHP validation, Plugin Check, browser harnesses, WordPress deployment, and installed production-byte qualification are outside this tooling-only change. Developer Docs grounding remains disabled.

Qualified source SHA-256 values:

- `package.json`: `3be5bdc056aa61bd5b921e74eaf9cb11785d113f8eb9bf789aa4032fc98b328c`
- `package-lock.json`: `aef462b69b42555cde58d4a381129b829138c59a3b2528953c1306541ab87625`
- `jest.config.js`: `9584e4edf66e59d029477721634b2f306883bae4b9fd87e45ed9cf44b373e130`
- `scripts/__tests__/jest-module-interop.test.js`: `1f2c30585fd032a9a3989fff61570d7b1520eecc684082dc5ed3818a25816cad`

Protected execution evidence: `/home/ubuntu/.local/state/flavor-agent/node20/20261022T014201Z`. It retains failing baseline and exploratory runs, fresh installation and verifier logs, CLI output controls, source/build hashes, dependency and audit comparisons, independent review, and Git recovery/sync receipts.
