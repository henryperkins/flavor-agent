# Minimatch nested-extglob validation — October 22, 2026 UTC

This follow-up starts at `a26a0c4f42a0540c30515a54c2376ff06a855c29` and resolves the Markdown CLI residual recorded in the [October 21 dependency triage](2026-10-21-high-severity-dependency-triage.md). It qualifies the CLI and its Glob consumer. Other tooling's Minimatch 3 copies and the remaining dependency findings retain their separate triage status.

The committed inputs are package.json SHA-256 `8dc9f7e4a24dbb7c7d0de11cd972b69d9c70ff53e51dbbd40a608f4a3b954646`, package-lock.json SHA-256 `114f4daf28d64167aeced2f839b222302128a92ea619cd3ccda685671810a851`, and regression-test SHA-256 `d3aa9a8442261d754ff98d56854d362d1181a4b2c9b142df640892d0d105d12c`. Plugin application source and the Developer Docs grounding release default (`false`) are unchanged. No runtime configuration or remote service was changed.

## Compatible dependency boundary

The scoped override selects `markdownlint-cli` **0.32.2** and `minimatch` **^8.0.7** beneath it. The installed CLI and its Glob 8 consumer both resolve the callable CommonJS 8.0.7 implementation. The repository's declared Node `^20.0.0 || ^24.0.0` and npm ranges remain unchanged; the newly selected packages' engine requirements accommodate both Node lines.

Minimatch 3.1.5 still times out on the nested-extglob reproducer under both native Linux Node 20.20.2 and 24.21.0. Integrity-verified parser-isolation probes also time out on the latest maintained 4.x–7.x releases. The 8.x line is the earliest tested working parser and retains both the callable API and `Minimatch` constructor required by the legacy consumers. The 9.x CommonJS export is an object.

A bare parser override on CLI 0.31.1 changes Windows backslash-pattern matching. The nearest CLI minor line, 0.32, provides upstream path normalization; 0.32.2 selects its latest patch. A bounded test executes the installed CLI's actual filtering helper with Windows path semantics: the bare override fails, and the selected CLI passes directory and glob filtering. This is VM/path simulation, not native Windows evidence.

The lockfile changes 12 package entries, including one Commander relocation, within the CLI cohort. Its matching parser cohort advances Markdownlint to 0.26.2, Markdown-it to 13.0.1 and Linkify-it to 4.0.1. Upstream 0.32 also adds Markdownlint rules MD051–MD053 and configuration-file support; this record does not assert that every lint rule produces identical results. Root Glob 7, unrelated Minimatch copies, the WordPress package cohort, Handlebars 4.7.10, Follow-redirects 1.16.1 and Launch-editor 2.14.2 retain their existing versions.

## Behavioral validation

- The corrected baseline regression run has five passing ordinary CLI controls and five bounded timeouts: nested `*()`, `+()` and mixed matching, plus actual CLI ignore and discovery paths. Original failures remain retained.
- The final regression suite passes **11/11 on Node 20.20.2 and Node 24.21.0**. It resolves Minimatch from both actual consumers and checks callable, constructor, `makeRe`, defaults, filter and list-match APIs; matching and nonmatching inputs; Glob discovery; Windows filtering; and real CLI directory, brace, dot-file, JSON/YAML and invalid-file controls. Hostile probes run in child processes with five-second termination limits.
- Twenty actual CLI executions compare five ordinary cases before/after on both Node versions. Selected files, exit status and diagnostic details agree. Only numeric Node warning process IDs and Markdownlint 0.25.1/0.26.2 rule-documentation URL versions are normalized; raw diagnostics remain saved.
- Clean `npm ci --no-audit --no-fund` succeeds under Node 24.21.0/npm 11.19.0. The new regression file passes the repository ESLint configuration, including its assertion-helper declaration.
- The Node 24 scoped verifier passes build, JS lint and **127 Jest suites / 2,431 tests**. A final full Jest rerun confirms the committed regression source hash was unchanged during the run. Six other verifier gates are explicitly excluded.
- Node 20 build and JS lint pass. Its default full Jest run has **104 passing suites / 1,698 passing tests and 23 suites blocked by the existing Marked ESM/Jest interoperability limitation**. The unchanged baseline interoperability test reproduces that error independently. The focused 11-test dependency suite passes on this runtime.
- `AISearchClientTest` passes **34 tests / 160 assertions**, including the four default-disabled/no-HTTP release checks.
- All **12 fresh build artifacts are byte-identical** between a clean baseline installation/build and the candidate, and between Node 20 and Node 24. The main checkout's previously stored activity-log bundle and asset-version receipt differ from that fresh baseline; their original bytes are preserved in a verified recovery archive. Generated files are excluded from the commit.

## Node 20 installation limit and remaining risk

With the existing `engine-strict=true` configuration, Node 20/npm 10 installation encounters unchanged Repomix 1.14.1's `node >=22` requirement. A baseline dry-run and candidate checks independently report the same `EBADENGINE` constraint. Node 20 runtime checks above use dependencies clean-installed under Node 24. A separate npm 10 lock-validation dry-run passes with a per-command `--engine-strict=false`; it is neither a successful strict installation nor a persistent configuration change. Full Node 20 toolchain qualification remains limited by these existing installer and Jest constraints.

The fresh audit remains **53 high / 51 moderate / 0 critical**, with no newly flagged package or severity transition. The scanner had already cleared Minimatch 3.1.5 by version metadata; this fix closes the behaviorally reproduced CLI residual without changing that count. The remaining findings are still open.

Plugin Check, the remaining PHP checks and browser harnesses are outside this tooling-only validation. This run establishes no new WordPress deployment or live-site qualification. Developer Docs grounding remains disabled.

## Sources and evidence

Primary sources: [nested-extglob advisory](https://github.com/isaacs/minimatch/security/advisories/GHSA-23c5-xmqv-rm74), [Minimatch 8.0.7 manifest](https://github.com/isaacs/minimatch/blob/v8.0.7/package.json), [8.0.7 parser](https://github.com/isaacs/minimatch/blob/v8.0.7/src/ast.ts), [CLI 0.32.2 source](https://github.com/igorshubovych/markdownlint-cli/blob/v0.32.2/markdownlint.js), [0.32.0 release changes](https://github.com/igorshubovych/markdownlint-cli/releases/tag/v0.32.0), and [0.32.2 patch release](https://github.com/igorshubovych/markdownlint-cli/releases/tag/v0.32.2).

Protected execution evidence: `/home/ubuntu/.local/state/flavor-agent/minimatch/20261022T000844Z`. It retains registry/tarball integrity, corrected failing baseline probes, rejected bare-override evidence, raw CLI comparisons and constructor traces, both final verifier reports, baseline installer/Jest limitations, final passing test/source identities, dependency/audit comparisons, fresh-build hashes, independent review and Git recovery/sync receipts. The historical failures are preserved with their scope; they are not reclassified as passing.
