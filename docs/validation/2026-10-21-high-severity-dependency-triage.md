# High-severity dependency triage — October 21, 2026

## Scope and identity

This follow-up starts at `77781a9bb163d85860e89f6646d6a5af72925bae` and applies the smallest compatible dependency mitigations while retaining the existing WordPress package cohort and declared Node 20/24 support. Developer Docs grounding retains its unchanged `false` release default; no runtime configuration or remote Docs service was changed.

Validation uses the manifest and lockfile blobs committed with this record: package.json SHA-256 `ae540dc68cd458d6863343a9c1cf1d8c170b0a9cbfdca20703c320b01af5f7d5`, package-lock.json SHA-256 `55183fd5dfb303ee77455b5a4f1c4ee3aba1328f1ab41825246a4e03748a4674`. The package changes affect development tooling; plugin application source is untouched.

## Scanner accounting

The fresh baseline audit reports **57 high / 50 moderate / 0 critical**, 107 package findings. These are propagated package flags, not 57 independent vulnerabilities. All 57 high flags were traced to eight directly advisory-bearing packages and 13 high advisory records.

The candidate audit reports **53 high / 51 moderate / 0 critical**, 104 package findings. Follow-redirects, launch-editor and minimatch disappear from the scanner's package findings; markdownlint-cli changes from high to moderate. The moderate count increase is that severity transition, not a newly introduced package. Five other directly affected package families remain. The original 57-row census and each row's after-state are retained in `all-57-high-findings-disposition.json`.

**Scanner clearance does not establish complete behavioral remediation.** The Minimatch nested-extglob case remains reproducible after the update, as described below. Its advisory disappearing from the version-based audit is recorded as scanner metadata only.

## Decisions for all eight underlying packages

| Underlying package | Installed baseline | Candidate / disposition |
| --- | --- | --- |
| follow-redirects | 1.16.0 | Update to 1.16.1 within http-proxy's existing `^1.0.0` range. Loopback tests verify array-header credential stripping and literal matching of caller-declared sensitive header names. |
| launch-editor | 2.14.1 | Update to 2.14.2 within webpack-dev-server's existing `^2.14.1` range. Its raised shell-quote minimum is satisfied by the already installed 1.12.0; no fourth lock entry changes. Windows path/command branches are exercised in a VM with process launch mocked. |
| minimatch | 3.0.8 beneath markdownlint-cli | Update only this nested copy to 3.1.5 with the parent-scoped `markdownlint-cli` override `^3.1.5`. This deliberately crosses the old CLI's `~3.0.5` pin while staying in major 3 and preserving its callable/Minimatch constructor API. Retain as partial mitigation; nested extglob remains unresolved. Other Minimatch copies are unchanged. |
| braces | 3.0.3 | No patched release in the current registry/advisory. It contributes to 43 of the original high package flags, overlapping other families. Do not replace it with an invented version or remove the WordPress dependencies to change the count. |
| basic-ftp | 5.3.1 | First patched version is 6.2.1, outside get-uri's `^5.0.2` contract. The current 5.x and get-uri 6.x lines contain no compatible fix; even inspected get-uri 8.0.1 still requests basic-ftp 5.x. A major-version compatibility decision is separate work. |
| extract-zip | 2.0.1 | Both high symlink advisories have no patched release. @puppeteer/browsers 2.13.2 still depends on it. The inspected 3.2.5 replacement line requires Node >=22.12.0, unlike the supported Node 20 cohort; do not silently replace the browser/toolchain line. |
| markdown-it | 12.3.2 | markdownlint 0.25.1 pins this exact version. First patched maintained line is 14.3.2; no 12.x fix is published. A parser/parent migration requires broader compatibility qualification. |
| linkify-it | 3.0.3 | The old parser requests `^3.0.1`; the two high advisories require at least 5.0.2 together. No patched 3.x release exists. Resolve through the parser/parent migration instead of forcing an unqualified major dependency. |

The registry snapshots establish that @wordpress/scripts 34.2.0 is the latest 34.x release. The inspected 37.0.0 toolchain requires Node `^22.22.2 || ^24.15.0 || >=26.0.0`; it is not a compatible automatic update for the repository's Node 20/24 declaration. Audit-suggested WordPress downgrades and blanket force updates were not applied.

## Minimatch partial mitigation and retained failure

An independent read-only review confirms that 3.1.5 fixes the tested repeated-wildcard and non-adjacent globstar cases: the old copy hits a two-second child-process limit, while the updated copy returns false in under one millisecond in those bounded probes. Five actual legacy CLI scenarios (directory ignores, brace input globs, dot-file filtering, JSON/YAML configuration and failing-file controls) produce matching before/after results across ten executions. A preload trace confirms the CLI constructs its matchers from the selected 3.1.5 copy.

**GHSA-23c5-xmqv-rm74 remains behaviorally reproducible in 3.1.5.** The published nested-extglob form compiles to the same nested regex in 3.0.8 and 3.1.5. The original candidate probe hits its 1.5-second cap; isolated follow-up hits five seconds; independent bounded controls reproduce the slow/hanging behavior on both versions while short controls complete normally. This is an existing upstream residual, not a newly introduced API regression. Keep the update for its verified improvements, but do not claim all three Minimatch advisory behaviors fixed or the risk eliminated. The failed original probe is retained, not reclassified as passing.

The derived audit record distinguishes six scanner-removed high advisory records from five behaviorally verified advisory mitigations, and separately records this residual. A full remedy needs a separate upstream/parser strategy; no custom fork or change to the lint CLI's glob semantics was introduced.

## Validation and limits

- Clean `npm ci --no-audit --no-fund` succeeds; registry tarball integrity and declared dependency metadata match the selected packages.
- The scoped verifier passes build, JS lint and 126 Jest suites / 2,420 tests. Six other verifier gates are explicitly excluded; this is not a new full WordPress/browser release qualification.
- AISearchClient PHPUnit passes 34 tests / 160 assertions, including all four default-disabled/no-HTTP release checks.
- The two follow-redirects security cases use real ephemeral loopback HTTP origins and synthetic headers. Ordinary headers remain intact.
- Launch-editor normal file/line handling and the relevant Windows guards pass in a VM with Windows path semantics and filesystem/process stubs. This is not native Windows device evidence; no editor or arbitrary command is launched.
- Legacy CLI compatibility passes five cases on each version. Only numeric process IDs in otherwise identical Node deprecation warnings are normalized; original diagnostics remain retained.
- All 12 generated plugin build files are byte-identical to the qualified baseline artifact. Source/ranking/Docs configuration and all other locked package versions remain unchanged. This does not audit WordPress external scripts, other site plugins or deployment.
- Documentation freshness checks and independent review cover this record before integration. The known Minimatch performance failure remains a reported limitation, not a passing check.

## Sources and evidence

The [follow-redirects header advisory](https://github.com/advisories/GHSA-q3jh-wrc6-jgq8), [launch-editor advisory](https://github.com/advisories/GHSA-587h-7wmj-h5pm), [Minimatch nested-extglob advisory](https://github.com/advisories/GHSA-23c5-xmqv-rm74), [unpatched braces advisory](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm), [unpatched extract-zip advisory](https://github.com/advisories/GHSA-jmr9-qjv8-65gv), [basic-ftp advisory](https://github.com/advisories/GHSA-c475-qrg2-pj4r), [markdown-it advisory](https://github.com/advisories/GHSA-r7fv-28h4-cvq7) and [linkify-it advisory](https://github.com/advisories/GHSA-v245-v573-v5vm) establish the published version ranges. The Minimatch behavioral evidence above limits what the published patched range establishes.

Protected execution evidence: `/home/ubuntu/.local/state/flavor-agent/high-audit/20261021T213319Z`. It contains the complete original 57-row census and disposition, raw before/after npm audits, all 13 high advisory records, registry snapshots, selected tarballs/integrity, unchanged-package and build-byte comparisons, verifier/PHP logs, before/after consumer and security probes, retained failures/residual, review and recovery records. No advisory was manually dismissed and no production replacement or service configuration write is part of this change.
