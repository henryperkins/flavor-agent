# Flavor Agent v0.1.0 Release-Hardening Program — Design

Date: 2026-08-02
Status: Approved direction; written-spec review findings incorporated

## Goal

Make the v0.1.0 release decision depend on repeatable evidence from the exact candidate revision. The program closes the confirmed external-apply authorization defect, makes corpus validation enforce its documented contract, adds a reproducible live Anthropic check, and records final release evidence without adding adaptive ranking or Agents API scope.

This design authorizes no implementation or external-state change by itself. In particular, it does not authorize a commit, push, branch publication, pull-request creation or merge, production deployment, live endpoint query, provider call, remote configuration change, Cloudflare corpus write, Git tag, GitHub release, or WordPress.org submission. Local implementation, any network call (including a read-only validation request), and every local or remote write require the separate authorization appropriate to that action. The commands below define reproducible operator gates; documenting a gate is not permission to execute it.

## Selected Approach

Use four serial, focused slices:

1. Target-bound authorization security fix.
2. Two-probe Developer Docs corpus validator.
3. Opt-in live Anthropic recommendation verifier.
4. Exact-revision release evidence and packaging gates.

The security fix is reviewed first and remains isolated from the other slices. Each later slice is based on the reviewed predecessor and receives its own source-grounded design or plan before code changes. This serial dependency is a sequencing rule, not authorization to commit, push, open a pull request, or merge. A failure in a later operational gate must not delay separately authorized integration of the security correction.

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
- Target-authorization failures reached by the decision service persist an honest failed row; outer permission denial leaves the row pending, and denied undo leaves live state and the row unchanged.
- Targeted PHPUnit, the shared verification pipeline, and matching approval/undo browser coverage pass on the final security SHA.

Implementation record (2026-08-02): Slice 1 is implemented in commit `c2b781ff26151a3edc2dab65a142f36a04333445`, published on branch `release-hardening-v0-1-0`. At that revision, all seven primary PHPUnit suites passed locally: 242 tests / 1,256 assertions. This record intentionally makes no claim about transient pull-request state and does not satisfy PR review, merge, deployment, Plugin Check, browser, or final-SHA verifier gates; those require separate authorization and evidence, including post-merge verification on the actual merge SHA. Slices 2–4 remain outside this implementation commit and retain their own design, implementation, integration, and release gates.

### Slice 2: Corpus contract alignment

Add a dedicated `npm run docs:ai-search:validate` entrypoint. It is not a mode of `scripts/update-docs-ai-search.js`, does not import the updater's command path or Cloudflare management client, and has no configure, ingest, upload, poll, or delete capability. Its configuration parser accepts only the public search origin, a versioned release profile, an output directory, and bounded transport settings. It never reads, validates, serializes, or forwards `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_AI_SEARCH_API_TOKEN`, or another write credential. Its only permitted network operation is an HTTPS `POST` to the normalized public `*.search.ai.cloudflare.com/search` endpoint.

Validation contract version `developer-docs-corpus-v1` defines two independent WordPress 7.0 probes. These strings are fixtures and must not be assembled from free-form operator input:

| Probe ID | Exact query | Required same-result evidence |
| --- | --- | --- |
| `stable-reference-v1` | `WordPress developer documentation block.json metadata reference for WordPress 7.0` | At least one `developer-docs` result on an allowed `developer.wordpress.org` handbook/reference URL with a valid `retrieved_at` no more than 90 days before the recorded validation time. |
| `current-cycle-v1` | `WordPress 7.0 Field Guide developer notes AI Client Abilities API` | At least one `make-core` or `developer-blog` result on its allowed source URL with a valid `published_at`: within that source's runbook window, or on/after the WordPress 7.0 release date `2026-05-20`. |

Each request uses the same message and `ai_search_options.retrieval.max_num_results` shape as `AISearchClient::build_search_request_body()`, with `max_num_results` fixed at `8`, redirects rejected, a 30-second deadline, and a 1 MiB response limit for contract version `developer-docs-corpus-v1`. The active-release profile owns the release slug, label, public release date, exact queries, source windows, transport bounds, and contract version; changing release cycles requires a reviewed fixture and runbook update rather than string substitution at runtime.

A URL, source label, and qualifying timestamp count only when they resolve from the same returned chunk/item. The validator must not combine a URL from one result with a timestamp from another, use `retrieved_at` to freshen a dated release-cycle post, or infer missing metadata from the query. It records `make-ai` and `wordpress-news` results as non-qualifying context. Aggregate success requires both probes; one cannot compensate for the other.

Status is deterministic:

- `pass`: both probes return bounded 2xx JSON and contain their required same-result evidence.
- `fail`: a fixed request is rejected with a non-retryable 4xx response, or a bounded valid 2xx response definitively lacks required coverage, URL integrity, or freshness.
- `incomplete`: DNS, TLS, timeout, redirect rejection, 429, 5xx, truncated/oversized response, or non-JSON response prevents the contract from being evaluated.
- Aggregate precedence is `fail`, then `incomplete`, then `pass`.

Every invocation atomically writes a structured artifact before exit, including local configuration errors, transport failures, parse failures, and unsuccessful probe results. The artifact contains contract/release version, validation timestamp, normalized endpoint origin, per-probe exact query, HTTP status, byte/result bounds, result count, source mix, URL-and-timestamp evidence tuples, failure reasons, and aggregate status; it contains no snippets or raw chunks. If the artifact itself cannot be written, the command exits nonzero and its stderr summary is explicitly not release evidence.

The scheduled workflow cadence and stale-deletion policy remain unchanged. The implementation does not dispatch the validator, query the endpoint, or write the live corpus. A separately authorized public-endpoint validation is a read-only external call; a manual ingestion workflow requires separate Cloudflare write authorization.

Exit criteria:

- Unit tests cover passing mixed evidence, missing stable docs, missing current-cycle sources, stale metadata, malformed URLs, and non-JSON/HTTP failures.
- Tests prove the validation process cannot reach updater/configuration/upload/delete code and never accesses write-credential variables.
- The runbook and validator state the same source and freshness contract.
- A separately authorized read-only run produces an always-written artifact, and a reviewed copy or summary is committed under `docs/validation/` before the final release candidate is selected. That slice-level record is not the final exact-revision evidence bundle described in Slice 4.

### Slice 3: Live Anthropic verifier

Add an opt-in repository command that sends the smallest valid synthetic `core/group` recommendation request through the real WordPress Abilities endpoint on an operator-supplied site. The committed fixture is limited to `selectedBlock.blockName: core/group`, empty attributes/inner blocks, and only other fields the Ability contract strictly requires. Context size is not a grammar-complexity input; the purpose of the fixture is to select the block response schema, not to recreate the incident's large editor context. The command exercises the normal `ChatClient` -> WordPress AI Client -> connector path and never calls Anthropic through a direct SDK.

The target must be a disposable/synthetic WordPress site containing no private content, or the operator must explicitly authorize the site-generated guidelines, theme tokens, block metadata, request diagnostics, and best-effort docs-grounding context that the server may add to the outbound provider request. A synthetic client fixture alone does not prove that the server-added prompt is content-free.

Inputs come from environment variables or non-committed local configuration. The site URL must normalize to an HTTPS origin with no credentials, query, fragment, encoded path delimiters, or non-default port. The command constructs only the fixed Abilities route on that origin, rejects every redirect, sends an application password or equivalent bearer secret only in the authorization header, applies a 210-second hard deadline (covering the server's bounded first attempt and retry), and rejects response bodies over 1 MiB before JSON parsing. Credentials never appear in command arguments or committed fixtures, and request/response headers and raw bodies are never written to the artifact.

This gate must reproduce the original unpinned `Settings > Connectors` route: the Ability input, command, and server request pass no provider or model override, and no verification-only hook may select Anthropic. The command reads the returned `requestMeta.requestLogId`, fetches that same request from the authenticated core AI Request Log, and treats the log's actual provider and model as authoritative server-observed evidence. The log ID and Flavor Agent request token/correlation ID must bind the Ability response, Flavor Agent diagnostic, and core log; if a fallback produces more than one provider attempt, the diagnostics must correlate every attempt needed to establish the outcome. The generic Anthropic profile accepts any nonempty model identifier paired with authoritative provider slug `anthropic`; the exact incident-replay profile additionally requires `claude-opus-5` (or another explicitly versioned expected incident model). Expected values are post-response assertions only and must never influence provider selection. A CLI expectation, preflight settings value, Flavor Agent's reconstructed `resolvedProvider`, uncorrelated row, client echo, `wordpress_ai_client` sentinel, or inferred connector label is not proof. A missing `requestLogId`, disabled/unavailable core logging, broken correlation, unobservable provider/model, observed non-Anthropic provider, or wrong model for the exact replay profile means the requested profile was not exercised and produces `incomplete`, not a product failure.

The verifier emits a sanitized structured artifact with:

- status: `pass`, `fail`, or `incomplete`;
- source revision plus server-observed deployment provenance and target URL origin;
- WordPress, Flavor Agent, AI plugin, connector, provider, and model identifiers, with the authoritative source and correlation ID for each;
- HTTP/result shape, suggestion counts, and schema-fallback diagnostics;
- explicit detection of the compiled-grammar failure family;
- artifact-scoped redaction checks: omitted sensitive field names, secret-value match count, and raw-body retention flag. This proves only what the local artifact retained; it makes no claim about server logs or provider retention.

The artifact separates transport status from two release claims:

- `structured_first_attempt`: the first request succeeds with the output schema and the response validates. This may support both "the live recommendation succeeds" and "Anthropic accepted the compact schema."
- `grammar_limit_fallback_succeeded`: the first attempt receives the recognized compiled-grammar rejection, a newly built schema-free retry succeeds, and the final response validates. The aggregate verifier may be `pass` for the v0.1.0 user-visible mitigation gate, but must set `degraded: true`; it supports only "the user-visible request recovered." It must not be cited as proof that Anthropic accepted the compact schema or that the grammar-limit rejection was eliminated.
- A grammar-limit rejection whose fallback fails, an invalid final recommendation, or a definitive non-retryable provider error is `fail`.
- Missing credentials, an unconfigured connector, unverifiable provider/model or deployment provenance, outer HTTP timeout/authentication uncertainty, and an unbounded/malformed response are `incomplete`, never `pass`.

Unit tests mock HTTP and cover secret/header/body redaction, redirect refusal, URL/timeout/response bounds, authoritative versus asserted provider evidence, ordinary structured success, successful schema-free fallback with degraded claim limits, grammar-limit recurrence, authentication failure, and malformed responses.

Executing the command against a site and live provider is an external call and remains separately authorized and operator-triggered. Implementing the harness and local tests does not authorize reading provider credentials or invoking either endpoint.

### Slice 4: Exact-revision release evidence

Final evidence uses an immutable external-artifact model so recording results cannot move the revision being tested. Before selecting the candidate, commit any human-readable checklist/template and slice-level validation records that belong in `docs/validation/`. The final run then generates `release-evidence-<full-sha>` as a CI/workflow artifact tied to the exact candidate commit; it does not modify or commit a result file into that candidate. A later documentation commit may link to the artifact as historical evidence, but it is not the tag target and must not claim otherwise.

On one immutable candidate SHA, a separately authorized release-evidence workflow must:

1. Check out the candidate at detached HEAD and refuse to start unless `git status --porcelain=v1 --untracked-files=all` is empty; record the reviewed base SHA, full candidate/start SHA, tree ID, lockfile digests, workflow definition SHA, tool versions, and immutable commit/digest pins for every invoked action, reusable workflow, container, and downloaded installer.
2. Run the targeted security, corpus, and provider-harness unit suites.
3. Run `npm run verify:strict` with Plugin Check available. Run the Playground and WordPress 7.0 browser suites separately only if the strict aggregate did not execute them.
4. Run the separately authorized read-only corpus validator and ingest its always-written artifact.
5. Build the release ZIP twice from independent clean checkouts of the candidate using the locked dependency graph. Require byte-identical ZIP SHA-256 values, deterministic file lists/modes/timestamps, and an identical installed-tree manifest digest; inspect the exact resulting archive rather than a source-tree surrogate.
6. Install that exact archive in the representative WordPress 7.0 harness and run Plugin Check against the installed archive. Record exact WordPress core build/version, PHP version, Plugin Check version, companion-plugin versions, archive SHA-256, installed-tree manifest digest, command, warning/error counts, and raw report digest.
7. Deploy that exact archive only under separate deployment authorization. Before extraction, the deployment plane must hash the uploaded archive and issue a receipt containing its server-observed ZIP SHA-256. After extraction, the authenticated target independently enumerates the installed plugin tree and returns its recomputed digest, the embedded source SHA, and the observed SHA-256 of the embedded provenance/manifest file. The canonical installed-tree manifest includes every regular file except that embedded manifest, normalizes relative paths to forward-slash form, rejects absolute/traversal paths, symlinks, and case-fold or normalization collisions, sorts paths bytewise, and hashes path plus file bytes. Missing or extra paths fail comparison. Portable installed-tree identity intentionally excludes filesystem modes; deterministic archive modes and timestamps remain a separate ZIP-build claim from step 5. The verifier compares the deployment receipt, manifest-file hash, recomputed tree, source SHA, and local archive evidence. It also records target/environment identity, deployment generation, and observation timestamp. An operator-entered version string, request parameter, echoed embedded digest, or deployment assertion is not proof.
8. Run the separately authorized unpinned Anthropic verifier against that proven deployment and ingest its artifact.
9. Run `git diff --check <reviewed-base-sha>..<candidate-sha>` plus a worktree whitespace check; require the working tree to remain clean and detached at the candidate; record the full end SHA/tree ID and fail if either differs from the start.
10. Hash every raw log, structured summary (including `output/verify/summary.json`), corpus/provider artifact, archive, Plugin Check report, deployment receipt, and environment manifest into a SHA-256 evidence manifest. Upload the bundle as an immutable workflow artifact associated with the candidate SHA and record its workflow run/job identifiers, retention policy, and platform-reported artifact digest or externally anchored signed workflow attestation. A digest stored only inside the replaceable bundle is not sufficient authentication.

The package provenance design must avoid self-reference. The archive embeds the source SHA and the canonical path/byte manifest described above; that manifest excludes its own embedded file but covers every other shipped regular file. The evidence bundle hashes the embedded manifest file, the target reports the hash it actually observed, and the deployment receipt binds the uploaded ZIP SHA-256 before extraction. Together those values map the independently recomputed installed tree to the byte-for-byte reproducible ZIP. A matching plugin version alone is never sufficient.

Any source, lockfile, build configuration, production-PHP, generated release asset, or evidence-workflow change invalidates the candidate and requires a new full run. A failed gate cannot be repaired by editing the external evidence bundle. Prior CI, another branch, a release version string, a client-asserted SHA, or a green check conclusion without the underlying job and artifact digests is not exact-revision proof.

The workflow definition does not authorize its own external effects. Uploading evidence, querying the corpus, calling a site/provider, deploying an archive, committing, pushing, creating or merging a pull request, tagging, publishing a GitHub release, changing remote connector settings, and submitting to WordPress.org each require separate operator authorization.

## Error And Evidence Policy

- `pass` means every required included check completed successfully.
- `fail` means a check ran and disproved the contract.
- `incomplete` means required tools, credentials, environment, or provenance were unavailable.
- Where multiple required checks contribute to one aggregate, deterministic precedence is `fail`, then `incomplete`, then `pass`.
- A waiver must name the omitted gate, reason, owner, and release impact. It records accepted risk but never changes `incomplete` to `pass` and never supplies missing proof. Silence is not a waiver.
- Target-authorization regression coverage, both corpus probes, authoritative provider/model correlation plus a successful live Anthropic result, clean candidate/archive/installed-tree provenance, and Plugin Check in the recorded WordPress 7.0 environment are non-waivable program-acceptance gates. Other explicitly waivable operational evidence remains `incomplete` even when the release owner accepts its risk.
- Operational artifacts must distinguish source correctness, merged state, deployed state, and live-provider proof.
- Secrets, raw prompts, full block trees, application passwords, connector tokens, and private response content are never committed as evidence.
- An artifact can report its own field omission and secret-value scan; it cannot prove that a remote server or provider retained nothing.

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

A separate `npm run check:docs` invocation passed. The aggregate baseline nevertheless remained `incomplete`: every check that actually executed passed, but Plugin Check, strict docs integration, and both browser suites were not proven by that aggregate. It is a partial local comparison point, not a green verification result or release pass.

## Program Acceptance

The program is complete only after separately authorized integration of all four slices, a platform-digested or externally attested immutable evidence bundle names and cryptographically binds one candidate SHA/tree, release archive, observed installed deployment, and all gate artifacts, every non-waivable gate is `pass`, any permitted waiver is explicit while its omitted evidence remains `incomplete`, and no evidence overstates deployment, structured-schema acceptance, or live-provider state. Deployment evidence proves only the named environment and generation observed at the recorded time; it does not claim the site remained unchanged afterward. A fallback-success artifact may satisfy the v0.1.0 user-visible Anthropic mitigation gate only with its degraded claim limits intact; it cannot close the stronger compact-schema-accepted claim. Adaptive ranking, explicit dismissal signals, cohort-correct learning reports, fixture harvest, editable preferences, and PR #73 remain post-v0.1.0 work.
