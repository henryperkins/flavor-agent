# Developer Docs Public Corpus Validation - 2026-05-11

Validation query: `WordPress current block editor developer guidance, WordPress 7.0 dev notes, Gutenberg release notes`

Observed result: HTTP 200 from the public Cloudflare AI Search endpoint, with four top chunks from `developer.wordpress.org` and no `make-core` or `developer-blog` chunks.

Top sources observed on refresh:

- `https://developer.wordpress.org/block-editor/reference-guides`
- `https://developer.wordpress.org/rest-api/reference/block-types`
- `https://developer.wordpress.org/block-editor/reference-guides/packages/packages-boot`
- `https://developer.wordpress.org/block-editor/explanations`

Runtime behavior: recommendations include the missing-current-release-cycle coverage warning while fail-closed enforcement remains disabled by default.
Release gate: fail-closed enforcement must not be enabled until a refreshed validation query returns current release-cycle coverage and corpus ownership accepts that evidence.
