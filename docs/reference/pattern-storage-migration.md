# Pattern Storage Migration

Cloudflare AI Search is the sole supported pattern store. Pattern indexing and retrieval use its managed embeddings; the saved Cloudflare account/token/model settings support setup and validation. Direct Workers AI embedding requests are validation probes.

## Existing Qdrant Installations

The former Qdrant setup is no longer a supported runtime path. Its URL, API key, collection state, and cosine threshold cannot make the managed Cloudflare index ready.

Ordinary updates preserve leftover URL/key/threshold options in the local database, but the supported settings form and runtime do not register or use them. `uninstall.php` still removes those legacy options. Removing the plugin does not delete data from a former remote Qdrant cluster.

Migration changes local compatibility state only. It does not copy vectors or pattern data, provision a remote Cloudflare index, delete a remote collection, or start inference. A former ready/indexing state does not establish that a Cloudflare index is usable. Recommendations remain blocked until the new managed path is configured and synced.

## Administrator Steps

1. Open `Settings > Flavor Agent` and configure the shared Cloudflare account ID and API token. Review the saved model used by setup and the direct Workers AI validation probe. The private pattern index uses a supported normalized AI Search model and manages catalog embeddings itself.
2. Explicitly save the Cloudflare setup. This schedules managed-index creation or adoption and validation; the dashboard shows provisioning, configuration, and recovery state. This action can contact Cloudflare and validate changed credentials.
3. Wait for the deterministic `flavor-agent-patterns-{site_hash}` index to validate its model, metadata schema, and site owner marker. Resolve the reported error before proceeding if setup is blocked.
4. Run `Sync Pattern Catalog`. This uploads the current public-safe registered and published user patterns to Cloudflare AI Search. It does not import the old remote vector database. Wait for item processing to complete and a confirmed usable index result.
5. Configure a text-generation provider in `Settings > Connectors` for recommendation reranking, enable the Flavor Agent feature in `Settings > AI`, and open the pattern inserter to request recommendations.

The separate `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search` setting remains the ranking threshold. Existing AI Search thresholds are preserved. The former cosine threshold is not copied, converted, or reused: managed hybrid-search scores have their own scale.

If monitoring reports `Status unavailable`, use `Check sync status` before starting another job. A lost response does not prove the background job stopped. Checking status can continue an already queued job.

## Verification Boundaries

Use disposable local/test fixtures for migration coverage. Assert that local compatibility migration makes no HTTP calls, queues no remote provisioning or inference, ignores legacy ready/collection metadata, preserves the AI Search threshold, and requires explicit setup plus a new sync. Test actual managed provisioning and paid services only in a separately authorized service qualification.

See [current settings behavior](../features/settings-backends-and-sync.md), [provider ownership](provider-precedence.md), and [Cloudflare pattern debugging](pattern-recommendation-debugging.md).
