# Pattern Recommendation Debugging

Cloudflare AI Search is the sole supported pattern store. Use this runbook to separate local setup, managed-index provisioning, item processing, scoped retrieval, and Connectors reranking failures. Existing legacy storage requires [explicit Cloudflare setup and a new sync](pattern-storage-migration.md); old vector collections and cosine thresholds are not part of this operational path.

## Pipeline And Healthy Baseline

1. `PatternSearchInstanceManager` provisions or adopts the managed `flavor-agent-patterns-{site_hash}` instance after an explicit settings save and validates its supported model, metadata schema, and site owner marker.
2. `PatternIndex::sync()` uploads current public-safe registered patterns and published user `wp_block` patterns as managed markdown items.
3. `PatternAbilities::recommend_patterns()` validates capability, visible-pattern scope, current catalog state, and synced-pattern access.
4. `PatternSearchClient::search_patterns()` retrieves chunks scoped by `visiblePatternNames`; candidates are rehydrated against current WordPress content and permissions.
5. `ResponsesClient::rank()` reranks those candidates through the WordPress AI Client / Connectors chat runtime.

Pattern indexing and retrieval use Cloudflare-managed embeddings. They do not call the plugin-owned `EmbeddingClient`; the direct client is used only by settings validation. Pattern setup shares the Cloudflare account/token and validates a normalized supported AI Search model.

A healthy baseline has a validated managed instance/signature, a confirmed usable index, completed current pattern items, a populated `last_synced_at`, and a configured Connectors text-generation provider. `Configured` only describes saved setup, while `Ready` describes the observed index result. A usable empty catalog can legitimately have zero indexed patterns; investigate the eligible WordPress pattern registry and public-safety scope before assuming service failure.

## 1. Inspect Local State First

In `Settings > Flavor Agent`, inspect managed Pattern Storage, the Sync summary, last confirmed time/count, stale reasons, and last error. Confirm that the Flavor Agent feature is enabled in `Settings > AI`. Connectors chat is required for final recommendations; it is not required to diagnose local index state.

The following command reads selected local state and does not expose saved credentials:

```bash
wp eval '
$index = \FlavorAgent\Patterns\PatternIndex::get_state();
$setup = \FlavorAgent\Cloudflare\PatternSearchInstanceManager::get_provisioning_state();
echo wp_json_encode( [
    "index" => array_intersect_key( $index, array_flip( [
        "status", "pattern_backend", "indexed_count", "last_synced_at",
        "last_attempt_at", "stale_reason", "stale_reasons", "last_error",
        "cloudflare_ai_search_namespace", "cloudflare_ai_search_instance"
    ] ) ),
    "setup" => array_intersect_key( $setup, array_flip( [
        "status", "requested_at", "completed_at", "managed_status",
        "last_error_code", "last_error", "owner_marker_repair", "marker_attempts"
    ] ) )
], JSON_PRETTY_PRINT );'
```

Keep local/runtime evidence separate from service qualification. A saved signature or an old successful sync does not prove the service is reachable now. Do not print the Cloudflare token or dump all plugin options into diagnostic reports.

## 2. Resolve Managed Setup Failures

- Missing account/token: configure the shared Cloudflare fields and explicitly save.
- Provisioning: inspect its reported status and allow the scheduled callback to run. Repeatedly saving or creating arbitrary new indexes is not a recovery strategy.
- Changed credential/model signature: save the corrected setup to validate the managed instance against current settings before syncing.
- Schema error: the managed index must support the filterable pattern metadata expected by `PatternSearchClient`; inspect the specific validation error.
- Owner-marker error: the instance must belong to this site. Do not adopt an unrelated instance or delete its items to make validation pass.
- Unsupported model: use the normalized supported AI Search model reported by setup. The saved model is used by the direct settings-validation probe and normalized for managed-index setup; AI Search generates catalog embeddings itself.
- Authorization or quota error: fix the token's access or account/service constraint, then deliberately retry setup.

Settings save validation and managed provisioning make external requests and may create/adopt storage. Exercise them only on the intended authorized environment. Local migration from the former backend makes no remote setup calls; it leaves recommendations blocked until explicit setup and a new sync.

## 3. Distinguish Queued, Processing, And Unknown Sync State

`POST /flavor-agent/v1/sync-patterns` queues a catalog sync. While `indexing`, the settings controller polls GET. GET can run a due queued sync before returning state, so a manual status check can continue already accepted work.

If monitoring loses its response, every status surface reports `Status unavailable` and qualifies the last confirmed values. The server may still be working. Use `Check sync status` to establish current state before requesting another job. A definite enqueue rejection reports `Sync not started` and preserves the server's recovery message; it does not establish that a previously completed catalog became unusable.

Sync uses saved configuration. Save or restore unsaved relevant Cloudflare settings before starting a job. Status checking remains available for an uncertain existing job.

For stalled managed indexing, inspect the item-processing summary and reported error. Uploads use `wait_for_completion=false`; successful upload acceptance is not completed indexing. The runtime waits for listed current items to report `completed`. Pending/missing items and retryable processing failures schedule follow-up syncs. Workers AI capacity/timeouts can appear as managed AI Search item errors even though the plugin-owned embedding client did not run.

Distinguish remote item `key` (Flavor Agent's stable pattern identity) from Cloudflare's generated item `id` used for deletion. Sync preserves unknown remote items and the owner marker. Do not clear an entire instance as a generic repair.

## 4. Verify The Eligible Pattern Scope

Before probing search, establish the current `visiblePatternNames` for the inserter root and the eligible registered/published user patterns:

- Missing or empty visible scope returns no recommendations by design.
- Nested insertion points may expose fewer patterns than the full site registry.
- Synced/user candidates use `core/block/{id}` identities and are rehydrated from current readable published WordPress content.
- Private, draft, trashed, or explicitly non-public-safe user patterns are not uploaded and cannot be returned.
- Theme/plugin changes or changed public pattern content require a new managed sync.
- A missing source, outdated fingerprint, or inaccessible user pattern is a catalog/scope issue even when Cloudflare returned a chunk.

Inspect `SyncedPatternRepository`, `PatternIndex`, and candidate-filter diagnostics before treating an empty result as a search-service error.

## 5. Separate Raw Retrieval From Reranking

A controlled raw search can use `PatternSearchClient::search_patterns( $query, $visible_pattern_names, $max_results )`. It makes an external Cloudflare request. Use a disposable fixture, public-safe query, and explicit visible scope when service probing is authorized; it is not part of source-only or offline verification.

Inspect returned candidate names, scores, metadata, and filter behavior. The request nests pattern names under `ai_search_options.retrieval.filters.pattern_name`. The client bounds/batches visible-scope filters to the service's supported limits and filters results before reranking. Do not remove the visible-scope filter to hide an empty-result defect.

- No raw candidates: investigate completed items, current eligible scope, query text, managed metadata schema, and the search service response.
- Raw names outside the visible scope: inspect request filter serialization and post-retrieval filtering.
- Raw candidates are relevant but final rankings are weak: inspect Connectors availability, reranking prompt/context, response normalization, and thresholds.
- Raw synced candidates disappear before reranking: inspect publication/read permissions and current WordPress rehydration.
- Final candidates cannot render: verify Gutenberg's current allowed-pattern data and insertion point; the client does not patch core registry metadata.

## 6. Tune The Current Score Scale

Use `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search` and `flavor_agent_pattern_max_recommendations`. Managed hybrid-search scores are not portable cosine similarity numbers. The old threshold is neither copied nor reused during migration.

Collect fixed public-safe query/scope fixtures, raw candidate counts/scores, final rankings, filtering reasons, and insertion outcomes. Change one threshold at a time and compare the same fixtures. A lower threshold cannot fix missing setup, unfinished item processing, invalid visible scope, or failed Connectors chat.

## Record Evidence And Recovery

Record source SHA/worktree changes, WordPress/plugin/runtime versions, fixture or service environment, current setup/index state, last confirmed time, sanitized error, visible scope, and whether requests were mocked. Separate successful HTTP acceptance, completed item processing, usable scoped retrieval, and model-backed ranking. Do not call a mocked or local-state-only pass a live service qualification.

Nearest checks include `PatternIndexTest`, `PatternAbilitiesTest`, managed-instance/client tests, settings migration/readiness tests, shared capability/store tests, and the matching pattern-inserter/admin Playwright flows. Follow the additive [cross-surface gates](cross-surface-validation-gates.md).
