# Corpus recovery investigation — 2026-10-08

This record separates the current Developer Docs corpus observation from the
historical failed updater and the v0.1.3 source/browser/package evidence.

## Current desired-set census

The October 8 updater's `needs-attention` result and 6,508 pending count remain
historical. A new read-only census used its frozen 13,480-entry manifest,
SHA-256 `3c4540abda69134ca524986bef2cc390e3e44824976c1b9f4ecf8f43d2cda8b3`,
against `wp-dev-docs`. It ran from 19:32:17 to 19:34:09 UTC and made 592 GETs
with four workers, zero retries, a 30-second per-request bound, and an
eight-minute total bound. All 592 pages completed, listing 29,589 unique
built-in items with stable declared totals, no duplicate IDs/keys, and no
exact-key gap repairs.

| Desired status | Count |
| --- | ---: |
| Completed | 13,458 |
| Outdated | 11 |
| Running | 5 |
| Error | 6 |
| Missing / unknown status / provenance mismatch | 0 |

All six errors are `workers_ai_timeout_error`. Each matches its current
manifest key, source URL, content hash, and genuine crawl timestamp. The
manifest, updater source, checkout HEAD `0913173`, and original updater
summary stayed unchanged. This is a complete census spanning its recorded
interval, rather than an atomic service snapshot or a passing settlement.

## Bounded recovery attempt and delta observation

Before the census, one manual `INDEX` request was sent for each of the six
fresh errored item IDs. A live GET first verified exact source/key/hash/crawl
identity and the timeout-error state. All six requests returned HTTP 200 and
`success: true`, with `result: null`; no completion state was returned. No
automatic write retry, upload, deletion, instance configuration, scheduler
change, or retry-policy change occurred.

The full census still found those six in error. A single subsequent scoped
observation from 19:36:11 to 19:36:16 UTC made 29 GETs: the 22 unfinished
items, their six error logs, and instance stats. One running item had completed;
11 remained outdated, four running, and six errored. Each error still had
`next_action: null`, and its latest log preceded the manual INDEX request.
The scope is now 21 observed unfinished desired items; this delta does not
rewrite the complete census or claim a second full sweep.

Global counters in that delta were zero queued, four running, 29,558 completed,
six errors, and 21 outdated. They include predecessor generations and are not
the desired-key counts. No post-settlement freshness query was run because
settlement has not passed.

## Isolated indexing pilot

A private upload-only instance, `wp-docs-pilot-oct08`, received nine documents
once: the six timeout errors, one outdated item, one running item, and a
known-completed positive control. Source downloads were verified against
stable live identity and frontmatter before upload. Exact source bytes and
source/hash/crawl metadata were preserved; the private instance's indexing
and retrieval configuration matched the current instance. The existing public
endpoint and scheduler were unchanged.

The pilot ran from 19:43:15 to 19:45:10 UTC, 114.865 seconds, and stopped at
its five-round observation limit. All nine items remained `running`, with
one to 12 chunks each and no reported error or identity mismatch. Even the
positive control had not completed. This establishes chunk-generation
progress, but does not qualify any completed replacement or distinguish an
instance-specific fault from service-wide processing delay. Final destination
byte comparison was not reached because no item qualified as completed.

There were 84 requests, one instance creation, nine uploads, zero retries,
and no resync, configuration update, deletion, or endpoint cutover. The
instance remains private with its nine items for review; no recurring monitor
or further polling was started. A five-minute observation-time bound and
ten-minute outer bound were ceilings, not elapsed observation time.

## Supplementary retrieval diagnosis

One unchanged query was submitted once to each of the public search endpoint,
managed namespace search API, and public MCP search at 19:46:02 UTC. All three
returned HTTP 200. The public and managed APIs returned eight chunks each;
MCP returned ten. Under the unchanged updater qualification policy, each had
current release-cycle evidence but no qualifying Developer Docs evidence.
Their current sources were Developer Blog and Make/Core.

The default eight-result responses included three crawl generations of the
same stale May 5 Make/Core proposal. This demonstrates duplicate-generation
crowding, but does not prove why Developer Docs were excluded. Preserved
October 7 controlled probes returned identical results with and without the
publication-date boost, so that configuration is not an established cause.
No retrieval configuration or predecessor-cleanup change was made.

These are supplementary diagnostics against an unsettled corpus. They do
not satisfy the required post-settlement order, substitute a filtered query,
or clear any release gate. The host's managed WordPress Docs MCP connector
was unavailable; the MCP observation used the public endpoint's documented
JSON-RPC search tool directly and does not establish a host-connector pass.

## Recovery direction and release boundary

The product's existing best-effort grounding path permits recommendations on
transport failure. A successful search can still supply older chunks; runtime
normalization applies URL hygiene and labels, while ingestion owns freshness.
There is no existing alternate Developer Docs endpoint setting or grounding
disable switch. A release mode that deliberately omits external docs
grounding could remove this service dependency while preserving the failure
record, but requires an explicit scope decision and implementation with
regression coverage. It was proposed at the end of this investigation.
The maintainer subsequently approved "approve and implement v0.1.3 without
external docs grounding"; the [separate implementation record](2026-10-08-v0.1.3-disabled-docs-grounding.md)
owns its new candidate evidence. The frozen investigation retains the earlier
unapproved proposal and all nonpassing service observations.

The proposed implementation must stop both live and cached docs reads at the
shared client, including direct docs abilities and signature-only collection;
report an intentional disabled state in runtime diagnostics, settings, and
editor notices; retain Guidelines, roadmap guidance, and separate pattern
retrieval; and refresh reviews that carry previous docs content. Meaningful
tests must exercise a primed docs cache with zero docs HTTP calls, retained
recommendation/apply behavior, and truthful diagnostics. Other source,
browser, package, immutable-candidate, and production gates remain additive.

Before restoring external grounding, full desired-set settlement and unchanged
public/managed/MCP freshness remain required. The approved suspended release
scope instead requires verified disabled behavior and the normal remaining
release matrix. Clean immutable candidate/tag/package proof, publication, production
replacement/live verification, and the qualified outcome cohort remain
separate pending gates. No publication, deployment, support-packet
transmission, commit, or push is part of this investigation.

## Evidence

New local evidence lives in `output/corpus-escape-oct08/`. The original
`output/docs-ai-search/summary.json`, manifest, historical release evidence,
and candidate ZIP are preserved. `observation.json` owns the initial live
snapshot; `reindex-six/` owns request receipts; the timestamped `census-*/`
directory owns the complete page archive, input manifest, summary, unresolved
records and checksums; the adjacent scripts own the local selftests.
`after-census/` owns the one scoped delta and processing logs. The timestamped
`pilot-*/` directory owns source-byte checks, creation/upload receipts,
configuration comparisons, all five observations, and the nonpassing pilot
summary. `retrieval-diagnostic/` owns the three unchanged request receipts,
responses, and policy-qualified diagnostic results. The unadopted
`release-exception-proposal.md` is a decision aid, not a waiver or release
approval.

Documentation freshness and `git diff --check` passed after recording these
outcomes. No product source or release artifact changed in this investigation.
