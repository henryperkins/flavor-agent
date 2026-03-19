# Cloudflare AI Search Grounding Assessment

The earlier summary was directionally right for `recommend-block` and `recommend-template`, but it overstated how generic entity cache works and omitted that `recommend-patterns` does not use Cloudflare WordPress-doc grounding at all.

- `flavor-agent/search-wordpress-docs` remains the only direct Cloudflare fetch path, and it stays admin-only via `manage_options` in `inc/Abilities/WordPressDocsAbilities.php`.
- `recommend-block` and `recommend-template` stay cache-only on the recommendation path. They now call `AISearchClient::maybe_search_with_entity_fallback()` so the exact-query cache is checked first and warmed entity cache is only a fallback on query-cache miss.
- The exact-query cache remains the most specific contract because the block/template query builders encode prompt and editor context such as inspector panels, structural identity, allowed areas, empty areas, and the current prompt text.
- Explicit `search-wordpress-docs` requests always seed the exact-query cache through `AISearchClient::search()`. They only seed entity cache when a valid entity key resolves, preferably from the explicit `entityKey` input and secondarily from legacy query inference.
- `recommend-patterns` still uses the Azure embeddings + Qdrant + Responses pipeline in `inc/Abilities/PatternAbilities.php`; it does not call the Cloudflare docs grounding path.
- Cold starts are still expected when neither the exact-query cache nor the matching warmed entity cache has been populated.

Current behavior by path:

- Blocks: grounded by live block context plus cached WordPress developer-doc snippets when the exact query or warmed entity cache is available.
- Templates: grounded by live template context plus cached WordPress developer-doc snippets under the same exact-query-first contract.
- Patterns: grounded by the pattern index and editor context, not by Cloudflare WordPress docs.
- Cloudflare docs: supplemental and opportunistic, not comprehensive or always-on.

Residual limitation:

- Newer or niche block attributes/support flags are only reflected when they already exist in model training or in the cached WordPress-doc snippets that match the recommendation context.
