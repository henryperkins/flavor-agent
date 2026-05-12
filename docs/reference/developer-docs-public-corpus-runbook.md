# Developer Docs Public Corpus Runbook

This document is the contract reference for the built-in public Developer Docs grounding corpus.

Use it when you need to answer:

- Who owns the public Cloudflare AI Search corpus used for Developer Docs grounding?
- Which source scopes and refresh cadence are required before fail-closed recommendation enforcement can ship?
- Which validation evidence must be recorded before enabling the current-release-cycle coverage gate?

Endpoint: `https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search`

Owner: Flavor Agent release maintainer for the built-in public Developer Docs grounding endpoint.

Execution stop line: do not enable fail-closed recommendation enforcement until the person or team with Cloudflare AI Search corpus access has explicitly accepted this ownership and refresh cadence in the release notes or this runbook. Runtime code may emit source-coverage diagnostics while enforcement remains disabled by default.

Required source scopes:
- `https://developer.wordpress.org/block-editor/`
- `https://developer.wordpress.org/rest-api/`
- `https://developer.wordpress.org/themes/`
- `https://developer.wordpress.org/reference/`
- `https://developer.wordpress.org/news/`
- `https://make.wordpress.org/core/7-0/`
- `https://make.wordpress.org/core/tag/dev-notes-7-0/`
- `https://make.wordpress.org/core/tag/gutenberg-new/`

Refresh cadence:
- Weekly during active WordPress major-release cycles.
- Within 48 hours of a Make/Core Field Guide, dev note batch, RC post, or Gutenberg release post.
- Monthly outside active major-release cycles.

Release gate:
- Run the validation query: `WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes`.
- Confirm at least one `developer-docs` chunk and at least one `make-core` or `developer-blog` chunk.
- Confirm release-cycle chunks from `make.wordpress.org/core` or the Developer Blog include a recent `published_at`; a recent `retrieved_at` crawl timestamp does not make an old release-cycle post current. Stable handbook/reference chunks from `developer.wordpress.org` may use `retrieved_at` for crawl freshness because those pages represent maintained reference material rather than dated release-cycle posts.
- Record the observed `retrieved_at`, `published_at`, source URLs, and result count in the release notes or verification log.
- Record the validation evidence under `docs/validation/` and make this runbook the final release decision point for enabling `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE`.
- Fail-closed recommendation enforcement must stay disabled until the public validation query returns at least one stable `developer-docs` chunk and at least one current `make-core` or `developer-blog` chunk. After corpus ownership accepts that evidence, enable enforcement through `FLAVOR_AGENT_DOCS_GROUNDING_REQUIRE_CURRENT_COVERAGE` or the `flavor_agent_docs_grounding_require_current_coverage` filter in the target release environment.
