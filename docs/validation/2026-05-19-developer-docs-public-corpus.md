# Developer Docs Public Corpus Validation - 2026-05-19

Validation query: `WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes`

Endpoint: `https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search`

## Cloudflare State

- Instance: namespace `default`, instance `green-sun`.
- Source: R2 bucket `web-fetch-data`.
- State after repair: `paused: false`, `enable: true`, `type: r2`, `source: web-fetch-data`.
- Service token reference restored; token identifiers are intentionally omitted from this validation artifact.
- Web crawler storage config restored to `source_params.web_crawler.store_options.storage_id = web-fetch-data`.
- Successful resync job: `8ad41b81-2189-400c-96e0-8f06aae03c27`.
- Post-refresh stats: `completed: 8049`, `queued: 0`, `running: 0`, `error: 0`, `file_embed_errors: {}`.

## Sources Refreshed

Make/Core current feed and release-cycle sources:

- `https://make.wordpress.org/core/2026/05/18/xpost-leadership-transition-for-the-core-ai-team/`, published `2026-05-18T18:48:35Z`, retrieved `2026-05-19T07:15:52Z`.
- `https://make.wordpress.org/core/2026/05/14/removing-title-attributes-in-author-link-functions/`, published `2026-05-14T15:00:00Z`, retrieved `2026-05-19T07:06:23Z`.
- `https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/`, published `2026-05-14T03:44:11Z`, retrieved `2026-05-19T07:06:12Z`.
- `https://make.wordpress.org/core/2026/05/13/dev-chat-agenda-may-13-2026/`, published `2026-05-13T03:55:38Z`, retrieved `2026-05-19T07:16:02Z`.
- `https://make.wordpress.org/core/2026/05/13/summary-dev-chat-may-6-2026/`, published `2026-05-13T03:46:19Z`, retrieved `2026-05-19T07:16:11Z`.
- `https://make.wordpress.org/core/2026/05/08/rtc-removed-from-7-0/`, published `2026-05-08T00:50:01Z`, retrieved `2026-05-19T07:06:45Z`.
- `https://make.wordpress.org/core/2026/05/07/whats-new-in-gutenberg-23-1-07-may/`, published `2026-05-07T17:34:37Z`, retrieved `2026-05-19T07:06:33Z`.
- `https://make.wordpress.org/core/2026/05/05/proposal-auto-generate-block-editor-handbook-docs-from-block-json/`, published `2026-05-05T14:56:33Z`, retrieved `2026-05-19T07:06:52Z`.
- `https://make.wordpress.org/core/7-0/`, published `2025-12-02T21:01:07Z`, retrieved `2026-05-19T07:05:52Z`.
- `https://make.wordpress.org/core/tag/dev-notes-7-0/`, latest source published `2026-05-14T15:00:00Z`, retrieved `2026-05-19T07:06:03Z`.

Developer Blog current feed sources:

- `https://developer.wordpress.org/news/2026/05/how-to-build-an-image-generation-plugin-with-the-wordpress-ai-client/`, published `2026-05-14T14:54:00Z`, retrieved `2026-05-19T07:07:05Z`.
- `https://developer.wordpress.org/news/2026/05/whats-new-for-developers-may-2026/`, published `2026-05-12T17:44:24Z`, retrieved `2026-05-19T07:07:19Z`.
- `https://developer.wordpress.org/news/2026/05/getting-started-writing-wordpress-e2e-tests-with-playwright/`, published `2026-05-04T07:57:36Z`, retrieved `2026-05-19T07:07:34Z`.
- `https://developer.wordpress.org/news/2026/04/whats-new-for-developers-april-2026/`, published `2026-04-10T16:39:02Z`, retrieved `2026-05-19T07:16:20Z`.
- `https://developer.wordpress.org/news/2026/04/wordpress-build-the-next-generation-of-wordpress-plugin-build-tooling/`, published `2026-04-02T17:26:38Z`, retrieved `2026-05-19T07:16:27Z`. This source is indexed for latest-feed context but is outside the 45-day Developer Blog currentness window on this validation date.

## Public Endpoint Evidence

Public endpoint request shape matched `AISearchClient::build_search_request_body()` with hybrid retrieval, `max_num_results: 8`, `match_threshold: 0.2`, `context_expansion: 1`, `fusion_method: rrf`, and `return_on_failure: true`.

Observed result for the validation query: HTTP 200, `success: true`, `chunk_count: 8`.

Qualifying stable Developer Docs chunks observed:

- `https://developer.wordpress.org/block-editor/reference-guides`, retrieved `2026-03-16T22:16:19.987Z`.
- `https://developer.wordpress.org/rest-api/reference/block-types`, retrieved `2026-03-16T22:47:00.897Z`.

Qualifying current Make/Core chunks observed:

- `https://make.wordpress.org/core/2026/05/05/proposal-auto-generate-block-editor-handbook-docs-from-block-json/`, published `2026-05-05T14:56:33Z`, retrieved `2026-05-19T07:06:52Z`.
- `https://make.wordpress.org/core/2026/05/07/whats-new-in-gutenberg-23-1-07-may/`, published `2026-05-07T17:34:37Z`, retrieved `2026-05-19T07:06:33Z`.

Targeted latest-source checks:

- Query `X-post Leadership transition for the Core AI team Make Core` returned `https://make.wordpress.org/core/2026/05/18/xpost-leadership-transition-for-the-core-ai-team/` as the top chunk with `published_at: 2026-05-18T18:48:35Z`.
- Query `How to build an image generation plugin with the WordPress AI Client Developer Blog May 2026` returned `https://developer.wordpress.org/news/2026/05/how-to-build-an-image-generation-plugin-with-the-wordpress-ai-client/` as the top chunk with `published_at: 2026-05-14T14:54:00Z`.
- Query `WordPress 7.0 Field Guide removing title attributes Gutenberg 23.1 WordPress AI Client What's new for developers May 2026` returned `https://make.wordpress.org/core/tag/dev-notes-7-0/` and `https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/` in the top results.

The `wordpress-docs-ai-search` MCP smoke check for the validation query also returned stable Developer Docs plus current Make/Core chunks, including `https://make.wordpress.org/core/2026/05/05/proposal-auto-generate-block-editor-handbook-docs-from-block-json/` and `https://make.wordpress.org/core/2026/05/07/whats-new-in-gutenberg-23-1-07-may/`.

## Operational Notes

During manual refresh, five sources were first written with malformed keys that omitted the slash after the hostname. The same sources were re-indexed under canonical keys, and the malformed-key objects were overwritten with an invalid-source tombstone. The AI Search delete endpoint rejected item deletion for this R2-backed instance with `this_operation_requires_a_managed_instance`, so `DocsGroundingSourcePolicy` must continue to discard any chunk whose source key and canonical URL disagree.

Release gate evidence: the public validation query now returns at least one stable `developer-docs` chunk and at least one current `make-core` chunk. The release-owner decision for `v0.1.0` is to enable `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` in the target release environment while leaving Cloudflare AI Search reranking disabled until a dedicated reranking evaluation fixture exists and duplicate/provenance-poor chunks are cleaned up or proven harmless under `DocsGroundingSourcePolicy`.

Runtime enablement check: the Docker-backed release-validation WordPress runtime has `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` defined as `true`. After clearing the stale coverage transient, `FlavorAgent\Cloudflare\AISearchClient::get_current_source_coverage( true )` returned `status: current`, `hasDeveloperDocs: true`, `hasCurrentReleaseCycle: true`, and `sourceTypes: [make-core, developer-docs]` at `2026-05-19 07:33:35`.
