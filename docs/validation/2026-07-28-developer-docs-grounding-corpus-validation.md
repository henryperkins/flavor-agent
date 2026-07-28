# Developer Docs Grounding Corpus Validation - 2026-07-28

Run time: 2026-07-28 (UTC). Performed during an agent session against the
built-in public Cloudflare AI Search endpoint configured by
`AISearchClient::DEFAULT_PUBLIC_SEARCH_URL`, using the request shape built by
`AISearchClient::build_search_request_body()`.

Endpoint: `https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search`

Instance/default name: `wp-dev-docs`

This record is the v0.1.0 release-checklist execution of "run the corpus
validation query from the runbook and record the evidence under
`docs/validation/`".

## Result

Status: **needs attention**. The source-mix requirement still fails.

This is the same failure first recorded on
[2026-06-17](2026-06-17-developer-docs-grounding-corpus-endpoint-alignment.md);
its "required follow-up before release reliance" has not been completed. The
endpoint is reachable and well populated, but the canonical validation query
returns **only** release-cycle chunks and **zero** stable `developer-docs`
chunks.

| Runbook gate | Result |
| --- | --- |
| At least one `developer-docs` chunk | **FAIL** (0 at every result cap tested) |
| At least one `make-core` or `developer-blog` chunk | PASS (8 of 8 at the runtime cap) |
| Release-cycle chunks carry a qualifying `published_at` | Partial — 1 of 8 qualifies at the runtime cap |

Corpus health itself is **not** the problem: targeted queries return stable
Developer Docs cleanly (see [Targeted probes](#targeted-probes)). This is a
retrieval-ranking failure on the broad validation query, not missing coverage.

## Validation query

Matches the `VALIDATION_QUERY` constant in `scripts/update-docs-ai-search.js`
and the runbook:

```
WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes
```

Observed response at `ai_search_options.retrieval.max_num_results = 8`
(`AISearchClient::MAX_MAX_RESULTS`, the highest value the plugin will ever send):

- HTTP status: `200`
- `result.query_kind`: present
- `result.search_query`: `WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes`
- `result.chunks`: `8`
- `result.hybrid_meta.search_methods`: `["vector","keyword"]`
- `result.hybrid_meta.vector_result_count`: `50`
- `result.hybrid_meta.keyword_result_count`: `50`

Returned sources, in rank order. Source labels follow
`inc/Support/DocsGroundingSourcePolicy.php`; currency follows the runbook rule
(a `make-core` chunk counts as current when `published_at` is within 21 days of
validation, or on/after the 2026-05-20 WordPress 7.0 release date):

| # | Label | `published_at` | `retrieved_at` | Currency | Source |
| --- | --- | --- | --- | --- | --- |
| 1 | make-core | 2026-03-18 | 2026-06-17 | stale (131d) | `https://make.wordpress.org/core/2026/03/18/dev-chat-agenda-march-18-2026/` |
| 2 | make-core | 2026-04-01 | 2026-06-17 | stale (117d) | `https://make.wordpress.org/core/2026/04/01/dev-chat-agenda-april-1-2026/` |
| 3 | make-core | 2026-04-21 | 2026-06-17 | stale (97d) | `https://make.wordpress.org/core/2026/04/21/dev-chat-agenda-april-22-2026/` |
| 4 | make-core | 2026-05-27 | 2026-06-17 | **current** | `https://make.wordpress.org/core/2026/05/27/dev-chat-agenda-may-27-2026/` |
| 5 | make-core | 2026-03-11 | 2026-06-17 | stale (138d) | `https://make.wordpress.org/core/2026/03/11/dev-chat-agenda-march-11-2026/` |
| 6 | make-core | 2026-03-04 | 2026-06-17 | stale (145d) | `https://make.wordpress.org/core/2026/03/04/dev-chat-agenda-march-04-2026/` |
| 7 | make-core | 2026-05-13 | 2026-06-17 | stale (75d) | `https://make.wordpress.org/core/2026/05/13/summary-dev-chat-may-6-2026/` |
| 8 | make-core | 2026-04-07 | 2026-06-17 | stale (111d) | `https://make.wordpress.org/core/2026/04/07/dev-chat-agenda-april-8-2026/` |

Seven of eight are pre-7.0-release Dev Chat agendas that no longer pass the
runbook's currency rule. Only rank 4 qualifies.

### The failure is not a result-cap artifact

The same query was re-run at four caps. `developer-docs` never appears, even at
`20` — two and a half times the maximum the plugin can request:

| `max_num_results` | Returned | Source mix | Gate A |
| --- | --- | --- | --- |
| 4 (`DEFAULT_MAX_RESULTS`) | 4 | make-core 4 | FAIL |
| 8 (`MAX_MAX_RESULTS`) | 8 | make-core 8 | FAIL |
| 16 | 16 | make-core 14, wordpress-news 1, developer-blog 1 | FAIL |
| 20 | 20 | make-core 18, wordpress-news 1, developer-blog 1 | FAIL |

Raising the cap only adds more Make/Core dev-chat chunks. No value reachable
from plugin configuration makes this query satisfy the gate.

## Targeted probes

Run to separate "corpus is missing stable docs" from "stable docs are
outranked". Stable Developer Docs are present, healthy, and retrievable:

| Query | Source mix | Representative sources |
| --- | --- | --- |
| `wp_register_ability` | developer-docs 8 | `/reference/functions/wp_register_ability/`, `/reference/classes/wp_ability/`, `/reference/hooks/wp_register_ability_category_args/` |
| `theme.json settings typography` | developer-docs 6, developer-blog 2 | `/themes/global-settings-and-styles/settings/typography/`, `/block-editor/how-to-guides/curating-the-editor-experience/theme-json/` |
| `block.json supports attributes` | developer-docs 8 | `/block-editor/getting-started/fundamentals/block-json/`, core-block reference pages |
| `WordPress 7.0 Field Guide` | make-core 2, developer-blog 2, make-ai 2, wordpress-news 2 | `/news/2026/06/whats-new-for-developers-june-2026/`, `wordpress.org/news/2026/05/wordpress-7-0-release-candidate-4/` |

Ingestion is also still running. Observed `retrieved_at` values across probes
span 2026-06-17, 2026-06-29, 2026-07-13, and 2026-07-18, which is the expected
signature of the incremental updater: unchanged pages keep their original crawl
timestamp and only changed pages are re-fetched. A uniform 2026-06-17
`retrieved_at` across the validation query's result set reflects that the
dev-chat-agenda cluster has not changed since then — not a stalled workflow.

## Diagnosis

The `make.wordpress.org/core/` dev-chat family is a large set of near-identical
documents that all open with the same boilerplate ("The next WordPress
Developers Chat will take place…", "### WordPress 7.0 Updates"). Against a broad,
release-flavored query containing "WordPress", "developer", "7.0", and "dev
notes", that cluster wins both the vector and keyword arms of the hybrid search
and saturates the fused top-N. Scores are tightly bunched (0.9953–0.9989),
which is consistent with a near-duplicate cluster rather than genuine relevance
separation. Results are not de-duplicated by source, so the cluster is never
thinned.

Because grounding is best-effort and never blocks a recommendation, this does
not break the runtime path. It does mean the built-in grounding corpus, queried
the way the runbook validates it, currently surfaces stale meeting agendas
instead of stable handbook and reference material.

## Impact on the v0.1.0 release gate

The release checklist item "run the corpus validation query … and record the
evidence" is executed by this record, and it **does not pass**. Treat the docs
corpus gate as open. Either satisfy it or consciously waive it and record the
decision — do not mark the checklist item done on the strength of a run that
failed one of its two required conditions.

## Required follow-up before release reliance

Unchanged from the 2026-06-17 record, now with a narrower diagnosis. Both paths
need Cloudflare AI Search corpus access, which this session does not have:

1. **Corpus hygiene (preferred).** Run a healthy full `delete_stale` pass. The
   returned chunks all carry 2026-06-17 crawl timestamps, so superseded
   generations of the dev-chat cluster may still be resident and inflating its
   weight. Re-validate afterwards.
2. **Ingestion scope.** Consider whether routine `dev-chat-agenda-*` and
   `summary-dev-chat-*` posts belong in a grounding corpus at all. They are
   meeting logistics, not developer guidance, and they are the entire failing
   result set. Narrowing the `make.wordpress.org/core/` discovery filter in
   `scripts/update-docs-ai-search.js` would remove the dominating cluster
   without touching the stable scopes.
3. **Validation query.** The runbook permits tuning the query itself. Do this
   only as a last resort and record the reasoning: a query edited until it
   passes proves less than the original one did, and the original is what
   detected this.

Replace this needs-attention record with a passing validation record only after
the top result set includes both stable Developer Docs chunks and current
release-cycle chunks.
