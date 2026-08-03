# Flavor Agent v0.1.0 Release-Hardening Program — Design

Date: 2026-08-02
Status: Approved direction; awaiting written-spec review

## Goal

Make the v0.1.0 release decision depend on repeatable evidence from the exact candidate revision. The program closes the confirmed external-apply authorization defect, makes corpus validation enforce its documented contract, adds a reproducible live Anthropic check, and records final release evidence without adding adaptive ranking or Agents API scope.

The program does not itself authorize a production deployment, Cloudflare corpus write, Git tag, GitHub release, or WordPress.org submission. Those remain explicit operator actions after their prerequisites pass.

## Selected Approach

Use four serial, focused slices:

1. Target-bound authorization security fix.
2. Two-probe Developer Docs corpus validator.
3. Opt-in live Anthropic recommendation verifier.
4. Exact-revision release evidence and packaging gates.

The security fix ships first and in its own pull request. Each later slice starts from the merged predecessor and receives its own source-grounded design or plan before code changes. A failure in a later operational gate must not delay landing the security correction.

## Alternatives Considered

### One bundled release PR

This minimizes branch coordination, but mixes a security boundary change, corpus policy, external-provider tooling, and release documentation. Review and rollback would be harder, and the isolated authorization fix requested for PR #72 would be lost in a large diff.

### Security-only release with manual corpus/provider checks

This is the smallest code change. It leaves the corpus contract inconsistent with automation and makes future Anthropic validation depend on hand-built requests, so the same release uncertainty returns on the next provider or schema change.

### Release hardening plus adaptive ranking

This would address the observational learning loop at the same time, but there is no production outcome dataset or fixture-harvest layer supporting safe weight changes. It expands risk precisely when the release candidate should be narrowing.

## Program Sequence

### Slice 1: Target-bound authorization

Add an executor-owned target authorization contract and invoke it immediately before approval-time target resolution and undo-time mutation. The check binds the activity document identity to the executor target and authorizes the current user against the target actually read or written. The detailed design is `docs/superpowers/specs/2026-08-02-external-apply-target-authorization-design.md`.

Exit criteria:

- Divergent document/target rows cannot write either target.
- Post-scoped writes require `edit_post` for the target post ID.
- Theme-territory writes reassert `edit_theme_options` at dispatch.
- Apply failures persist an honest failed row; denied undo leaves live state and the row unchanged.
- Targeted PHPUnit, the shared verification pipeline, and matching approval/undo browser coverage pass on the final security SHA.

### Slice 2: Corpus contract alignment

Replace the single overloaded validation query with two independent probes:

- A stable-reference probe using the merged block-metadata query and requiring a structurally valid `developer-docs` result whose crawl timestamp satisfies the runbook window.
- A current-cycle probe using a release-specific Field Guide/dev-note query and requiring a `make-core` or `developer-blog` result whose publication timestamp satisfies the runbook window or the WordPress 7.0 release-date rule.

The validator will classify `make-ai` and `wordpress-news` results for evidence without making them mandatory. Its structured output will contain per-probe query, HTTP status, result count, source mix, relevant timestamps, qualifying URLs, failure reasons, and aggregate status. Aggregate success requires both probes; one cannot compensate for the other.

The scheduled workflow cadence and stale-deletion policy remain unchanged. Implementation does not dispatch or write the live corpus. A live public-endpoint query is read-only; any manual ingestion workflow needs separate authorization.

Exit criteria:

- Unit tests cover passing mixed evidence, missing stable docs, missing current-cycle sources, stale metadata, malformed URLs, and non-JSON/HTTP failures.
- The runbook and validator state the same source and freshness contract.
- A read-only live validation record is written under `docs/validation/`.

### Slice 3: Live Anthropic verifier

Add an opt-in repository command that sends a synthetic, representative `core/group` recommendation request through the real WordPress Abilities endpoint on an operator-supplied site. It exercises the WordPress AI Client and configured connector; it does not bypass them with a direct provider SDK call.

Inputs come from environment variables or non-committed local configuration. Secrets never appear in command arguments, logs, artifacts, or committed fixtures. The committed request fixture contains no site content and is large enough to exercise the block response schema that triggered the grammar-limit incident.

The verifier emits a sanitized structured artifact with:

- status: `pass`, `fail`, or `incomplete`;
- asserted source revision and target URL origin;
- WordPress, Flavor Agent, AI plugin, connector, and model identifiers when observable;
- HTTP/result shape, suggestion counts, and schema-fallback diagnostics;
- explicit detection of the compiled-grammar failure family;
- redaction metadata proving credentials and raw private content were not retained.

Missing credentials, an unconfigured connector, or an unverifiable provider identity produces `incomplete`, never `pass`. Unit tests mock HTTP and cover redaction, ordinary structured success, successful schema-free fallback, grammar-limit recurrence, authentication failure, and malformed responses.

Executing the command against a live provider is an external call and remains operator-triggered. The implementation may add the harness and tests without invoking provider credentials.

### Slice 4: Exact-revision release evidence

On one immutable candidate SHA:

1. Run the targeted security, corpus, and provider-harness unit suites.
2. Run `npm run verify:strict` with Plugin Check available.
3. Run the Playground and WordPress 7.0 browser suites if they were not included in the strict aggregate.
4. Run the read-only live corpus validator.
5. Run the opt-in Anthropic verifier against a deployment built from the candidate SHA.
6. Run `npm run dist`, inspect the package, and record its digest.
7. Run `git diff --check` and prove the evidence revision equals the intended tag target.
8. Record command results, artifact paths, environment versions, candidate SHA, and any explicit waiver in a dated `docs/validation/` record.

Any code or production-PHP change invalidates the evidence and requires the affected gates to rerun. Prior CI, another branch, a release version string, or a green check conclusion without the underlying job result is not exact-revision proof.

Tagging, publishing a GitHub release, deploying to a site, changing remote connector settings, and submitting to WordPress.org are outside this implementation authorization.

## Error And Evidence Policy

- `pass` means every required included check completed successfully.
- `fail` means a check ran and disproved the contract.
- `incomplete` means required tools, credentials, environment, or provenance were unavailable.
- A waiver must name the omitted gate, reason, owner, and release impact. Silence is not a waiver.
- Operational artifacts must distinguish source correctness, merged state, deployed state, and live-provider proof.
- Secrets, raw prompts, full block trees, application passwords, connector tokens, and private response content are never committed as evidence.

## Verification Baseline

The isolated `release-hardening-v0-1-0` worktree starts at `origin/master` commit `6e5fcd1dfdb77f77848be16f941c8b7530f97973`.

Baseline `npm run verify -- --skip-e2e` results on 2026-08-02:

- build: pass;
- JS lint: pass;
- JS unit: 109 suites / 1,695 tests passed;
- PHP lint: pass;
- PHPUnit: 2,008 tests / 9,085 assertions passed;
- Plugin Check: incomplete because no WordPress root was resolvable in the isolated checkout;
- docs check: excluded because the run was not strict;
- both browser suites: intentionally excluded.

This is a green included-test baseline, not a full release pass.

## Program Acceptance

The program is complete when all four slices are merged, the final evidence record names one immutable candidate SHA, every mandatory gate is `pass` or carries an explicitly approved waiver, and no evidence overstates deployment or live-provider state. Adaptive ranking, explicit dismissal signals, cohort-correct learning reports, fixture harvest, editable preferences, and PR #73 remain post-v0.1.0 work.
