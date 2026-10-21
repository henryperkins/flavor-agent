# October 21 follow-up validation and production provenance

## Scope and source identity

The approved follow-up covers merged provider/Activity validation, recoverable local worktree cleanup, dependency-alert triage, production provenance and a bounded descriptive cohort, retained Developer Docs suspension, and a concrete package/publication decision.

Baseline master was 24034d6d4c3531ef024fbac2c65de9493ed99d02, containing #90 (1b32210) and the diagnostics/outcome-attribution change (5eae3aa). The only implementation change introduced by this follow-up is a standalone test-bootstrap UTC initialization at 1d87df9512c83dba687bc70fdcef4dabd58d4183. Native WordPress sets UTC in wp-settings.php; production serialization/ranking/provider code and existing assertions were left intact.

## Preserved initial failures

The initial strict non-browser aggregate on 24034d6 completed five steps successfully and failed Plugin Check and PHPUnit; both browser steps were explicitly excluded. Plugin Check lacked the plugin-check WP-CLI command in the fresh test runtime. PHP ran 2,664 tests and encountered three ActivitySerializer timestamp failures under the host Europe/Berlin timezone. The 20-test serializer suite reproduced all three failures in isolation and passed unchanged when PHP was initialized to UTC.

Plugin Check 2.1.0 was installed only in the dedicated local runtime. The test bootstrap was aligned with WordPress UTC initialization; all 20 serializer tests then passed under both Europe/Berlin and Pacific/Honolulu host settings. Initial logs and failing aggregate summary remain preserved.

## Current validation

A clean strict run at candidate `1d87df9512c83dba687bc70fdcef4dabd58d4183` passed **9/9 gates, zero skipped**: build, JS lint, staged Plugin Check, 126 Jest suites / 2,420 tests, PHP lint, documentation checks, 2,664 PHPUnit tests / 13,256 assertions, Playground 50/50, and Gutenberg 23.9.0 Site Editor 78/78. The command was `node scripts/verify.js --strict --output=<evidence>/strict`, with the dedicated runtime environment retained in `harness-env.json`. Existing webpack asset/entrypoint-size recommendations remain non-failing build warnings.

Separate editor evidence passed bundled 77/77 and Gutenberg 24.2.0 targeted 4/4 (authentication, real Activity repository display, native Codex-option matching, and AI 1.4.0 abilities). The bundled command excludes only the Gutenberg-dependent shared-Guidelines fixture by its exact title; that fixture passed in the complete pinned leg. All executed browser cases used one worker and zero retries, with zero flaky or skipped cases. The 24.2.0 run is targeted compatibility evidence, not a full 78-case matrix on that editor.

Both Docker runtimes used a cached WordPress 7.1 / PHP 8.2.33 image, MariaDB 11.4 and AI 1.4.0. Separate Compose projects, ports, databases, authentication-state files and output paths isolate the concurrent editor legs. Their tracked source commit and all 12 build-file hashes match; actual runtime/plugin inventories and the cached image ID are retained. This is local automated-browser evidence and makes no physical-device or live-provider claim.

## Recoverable worktree cleanup

All 12 modified/untracked diagnostics paths exactly matched qualified ancestor a622fe0, which is already an ancestor of master. Six redundant worktrees and three local branches were retired after preserving and verifying their complete file inventories, dirty/staged patches, indexes, source refs, built assets, packages, and test evidence. Root node_modules was the only omitted reproducible cache; vendor and all other ignored files were retained. The private recovery directory contains six verified checkout archives and a Git bundle. Remote branches/tags were retained.

## Dependency triage

GitHub returned 19 open advisory alerts: 2 critical, 5 high, 11 medium, and 1 low, all labeled development scope. The two critical advisories affect handlebars 4.7.9 through repomix; the parent range ^4.7.9 accepts patched 4.7.10, whose registry version/integrity was independently checked. Markdown/linkification and selector-parser fixes require checking their parent tool versions; extract-zip and sprintf-js have no patched version in the corresponding GitHub advisory response. A vulnerable minimatch 3.0.8 remains in the markdownlint-cli branch; newer installed minimatch copies do not repair that path.

The separate npm registry audit returned 108 package findings. Its production-filtered audit returned 29 high package findings propagated from the braces advisory through WordPress/theme/stylelint-related dependencies. These package counts are not the same population as the 19 GitHub advisory alerts. Production/development package flags are not proof of bundled runtime inclusion.

The byte-identical production build's webpack trace contains **1,031 compiled modules**. None of the 18 directly advisory-bearing package names in the npm audit snapshot appears in that graph. WordPress-provided external scripts and the rest of the site's plugins were not audited by this trace. Development/build-tool risk remains; this is not a claim of a vulnerability-free environment.

A generated, unapplied lockfile proposal changes only the handlebars package entry from 4.7.9 to 4.7.10 (including its integrity and declared minimist range). It is retained as `handlebars-4.7.10-proposal.patch`; the repository lockfile and qualified candidate dependencies remain unchanged. The proposal is a follow-up remediation candidate, not a tested/applied fix or a closed GitHub alert.

The lockfile was retained. Broad npm audit force updates would alter the pinned WordPress/tool cohort; triage does not claim those alerts fixed.

## Current production evidence and cohort gate

WordPress.com inventory for hperkins.blog (site 253647414) reports Flavor Agent 0.1.3, AI 1.4.0, Gutenberg 24.2.0, and Scriptorium Codex 2.1.4 active. The site has 56 installed plugins, 37 active and 19 inactive. These are inventory observations, not byte verification.

All nine public JS/CSS assets returned HTTP 200. Eight match the October 11 qualified artifact and the current candidate build. build/activity-log.js is 2,077,041 bytes with SHA-256 542c62d0203487697bf5c030055ebc764337670e520eb94beabb13cf2d484733; it matches neither comparison. A cache-busted no-cache request returned the same bytes; its Last-Modified header is October 21, 2026 06:20:08 GMT. The public homepage returned HTTP 200 without the critical-error message. None of these reads establishes installed PHP or non-secret runtime configuration.

The user requested browser discovery of SSH/SFTP details. The workspace browser opened WordPress.com hosting configuration, redirected to the sign-in URL, and received Checking your browser / HTTP 403. No authenticated browser session, SSH configuration, or WordPress.com key/credential-file path was available in the workspace. No credentials were created/reset, no SSH setting changed, no provenance declaration stamped, and no cohort/export started.

The existing October 12 block-only collection plan remains: at most 500 rows, newest-first, fixed whole-second UTC cutoffs beginning strictly after independent installed-package/configuration verification; stop after five independently observed fresh generations with shown sets or 24 hours. Require known eligible population, no truncation/unexplained exclusions, complete required identities/joins, schema validity and privacy/intended-use review. Insufficient coverage remains unqualified. Do not manufacture events or applies to fill categories.

Static ranking remains 30% model / 45% deterministic / 25% context and does not learn from dismissal/outcome history. Missing dismissal denominators remain unavailable; dismissal metrics remain outside export v1. The October 7 five-shown-set/two-linked-apply cohort remains historical, lacks installed-code/configuration provenance and establishes no causal or adaptive-ranking readiness. No sharing or adaptive-ranking use is authorized by this follow-up.

## Developer Docs disposition

At 2026-10-21T17:59:39Z the managed wp-dev-docs service reported 26,596 completed items and zero queued, running, errored, outdated or skipped items; separate running/error lists were empty. These totals do not compare the frozen 13,480 desired keys or establish their source/hash/crawl provenance. That prior manifest/census is not in the current Linux checkout or preserved local worktree artifacts. Complete desired-key settlement and unchanged public/managed/MCP freshness qualification remain unverified. Grounding remains disabled. Only GET observations were made: no indexing job, upload, cleanup, search query or instance configuration change.

## Package/publication decision

Selected candidate for the proposed publication is the newly qualified current-source package, retaining the older artifact separately. The new 0.1.3 ZIP is **1,314,096 bytes / 221 files**, SHA-256 `2d2cb7ce1208d3a85e1a769701d4f4f6a4fff2bd154b87e40f65b503e76eb861`. All packaged non-vendor source/build bytes match the candidate, all **202 packaged PHP files** pass native PHP 8.2 lint, and unfiltered extracted-package Plugin Check passes without errors. The extracted native Codex-budget probe loads `WordPressAIClient.php` from the extracted ZIP, accepts a synthetic model lacking the optional budget, preserves an explicit caller limit as required, and restores its temporary local model-selection option. It makes no generation HTTP request.

The local probe wrapper initially over-escaped a PHP namespace; its syntax error was preserved, the wrapper was corrected and syntax checked, and the same extracted package then passed. No package/source bytes changed for that repair. The compiler trace traversal was likewise corrected to include chunk/concatenated modules before the security statement above; the empty initial traversal is not qualification evidence.

Package rebuilds retained byte-identical verified build assets. A later documentation-only record can use the byte-identical-artifact gate; it must not claim those later commits were the source SHA of the strict run. No GitHub tag/release, WordPress.com replacement or WordPress.org publication was performed by this qualification.

The qualified October 11 221-file 0.1.3 ZIP remains preserved separately with SHA-256 c116d6eae1dad804a2b019aba4483bf2a8739de30542e02d9e222519950dc917. Candidate/package validation, GitHub publication, WordPress.com replacement and WordPress.org distribution remain distinct evidence lanes. A version string, Git merge, or public asset response cannot substitute for installed-package verification.

## Evidence

Protected evidence: /home/ubuntu/.local/state/flavor-agent/followups/20261021T175612Z. The execution ledger is progress.md; failed initial aggregate is aggregate/summary.json; the complete strict report, supplemental editor matrix, package audits, dependency records, production inventory/public/browser receipts, cohort plan/status and recovery manifests are kept under this directory with exact source and artifact identities. Historical October 7/8/11 evidence was preserved separately.
