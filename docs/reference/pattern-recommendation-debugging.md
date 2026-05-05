# Pattern Recommendation Debugging

Use this when pattern recommendations are failing in production and you need to separate:

- backend configuration problems
- pattern-index drift or stalled rebuilds
- Qdrant collection health issues
- Cloudflare AI Search item sync or schema issues
- weak raw retrieval
- LLM reranking problems

This is the operational companion to `docs/features/pattern-recommendations.md`.

## Fast Mental Model

Check the selected pattern backend first:

| Backend | First debugging path |
| --- | --- |
| `qdrant` | Inspect embeddings, Qdrant health, collection compatibility, and raw Qdrant hits. |
| `cloudflare_ai_search` | Inspect private AI Search credentials, filterable metadata schema, item sync state, search chunks, filters, and synced-pattern rehydration. |

The current pattern pipeline has four distinct stages:

1. `PatternIndex::sync()` in `inc/Patterns/PatternIndex.php` builds and maintains the selected pattern backend corpus
2. `PatternAbilities::recommend_patterns()` in `inc/Abilities/PatternAbilities.php` checks visible-pattern scope, runtime state, backend readiness, and synced-pattern access
3. `QdrantClient::search()` or `PatternSearchClient::search_patterns()` retrieves candidates
4. `ResponsesClient::rank()` in `inc/AzureOpenAI/ResponsesClient.php` reranks those candidates through the WordPress AI Client / Connectors runtime into the final recommendation list

The embedding provider behind Qdrant is Cloudflare Workers AI. The Cloudflare AI Search backend uses Cloudflare-managed embeddings/indexing and does not call `EmbeddingClient` or `QdrantClient`.

If you identify which stage is failing, the rest of the debugging path usually becomes obvious.

## Healthy Baseline

When the system is healthy, all of the following should be true:

- the stored pattern index state reports `status: ready`
- `last_synced_at` is populated
- `indexed_count` is non-zero and matches the collection point count closely for registered patterns plus public-safe published synced/user `wp_block` patterns
- `pattern_backend` matches the selected backend in `Settings > Flavor Agent`

For Qdrant, the following should be true:

- `QdrantClient::validate_configuration()` returns `true`
- `QdrantClient::probe_health( 'readyz' )` returns HTTP `200`
- the collection definition reports `status: green`
- the collection definition reports `optimizer_status: ok`
- the collection vector size matches the active embedding dimension
- the collection payload schema includes keyword indexes for `blockTypes`, `templateTypes`, `categories`, and `traits`
- raw Qdrant hits for a prompt include obviously relevant patterns
- synced/user pattern hits use payload names like `core/block/{id}` with `source: synced`
- final recommendations either preserve those hits or improve their order after request-time synced-pattern rehydration

For Cloudflare AI Search, the following should be true:

- `PatternSearchClient::validate_configuration()` returns `true`
- the private AI Search instance has `pattern_name`, `candidate_type`, `source`, `synced_id`, and `public_safe` declared as filterable metadata
- `PatternSearchClient::list_pattern_item_ids()` returns stable item IDs for the current corpus after sync
- raw AI Search chunks for a prompt include relevant patterns and metadata with names in the current `visiblePatternNames` scope
- synced/user pattern hits use payload names like `core/block/{id}` with `source: synced`
- final recommendations either preserve those hits or improve their order after request-time synced-pattern rehydration

Small Qdrant collections have one easy-to-misread detail:

- `indexed_vectors_count` can stay `0` while the collection is still healthy
- this repo creates small pattern catalogs, and the observed optimizer config uses `indexing_threshold: 10000`
- for catalogs far below that threshold, Qdrant can stay on exact scans instead of building an HNSW index

Treat that as expected unless latency is actually bad.

## First Five Checks

### 1. Read the stored runtime state

```bash
wp option get flavor_agent_pattern_index_state --format=json
```

Focus on:

- `status`
- `last_synced_at`
- `indexed_count`
- `qdrant_collection`
- `pattern_backend`
- `cloudflare_ai_search_namespace`
- `cloudflare_ai_search_instance`
- `cloudflare_ai_search_signature`
- `embedding_dimension`
- `embedding_signature`
- `last_error`
- `last_error_code`
- `last_error_status`
- `last_error_retryable`
- `stale_reasons`

### 2. Validate the selected backend and chat

For Qdrant:

```bash
wp eval 'var_export( \FlavorAgent\Embeddings\QdrantClient::validate_configuration() );'

wp eval 'echo wp_json_encode( \FlavorAgent\Embeddings\QdrantClient::probe_health( "readyz" ) );'

wp eval 'echo wp_json_encode( \FlavorAgent\Embeddings\EmbeddingClient::validate_configuration() );'

wp eval 'var_export( \FlavorAgent\AzureOpenAI\ResponsesClient::validate_configuration() );'
```

Interpretation:

- if Qdrant validation fails, stop there and fix connectivity or credentials first
- if embeddings validation fails, both sync and live retrieval are blocked
- if Connectors/text-generation validation fails, raw retrieval can still be healthy while final recommendations fail

For Cloudflare AI Search:

```bash
wp eval 'var_export( \FlavorAgent\Cloudflare\PatternSearchClient::validate_configuration() );'

wp eval 'var_export( \FlavorAgent\AzureOpenAI\ResponsesClient::validate_configuration() );'
```

Interpretation:

- if validation reports missing credentials, fix the private pattern AI Search settings first
- if validation reports metadata/filter schema errors, add the five filterable metadata fields in the Cloudflare dashboard before the first sync
- if Connectors/text-generation validation fails, raw retrieval can still be healthy while final recommendations fail

### 3. Inspect the live Qdrant collection definition

Use the collection name stored in the runtime state and fetch the collection definition directly:

```bash
wp eval '
$state      = get_option( \FlavorAgent\Patterns\PatternIndex::STATE_OPTION, array() );
$collection = (string) ( $state["qdrant_collection"] ?? "" );
$base       = (string) get_option( "flavor_agent_qdrant_url", "" );
$key        = (string) get_option( "flavor_agent_qdrant_key", "" );

$response = wp_remote_get(
	untrailingslashit( $base ) . "/collections/" . $collection,
	array(
		"timeout" => 10,
		"headers" => array( "api-key" => $key ),
	)
);

if ( is_wp_error( $response ) ) {
	echo wp_json_encode(
		array(
			"error"   => $response->get_error_code(),
			"message" => $response->get_error_message(),
		)
	);
	return;
}

$data   = json_decode( wp_remote_retrieve_body( $response ), true );
$result = is_array( $data ) ? ( $data["result"] ?? array() ) : array();

echo wp_json_encode(
	array(
		"collection"           => $collection,
		"qdrant_status"        => $result["status"] ?? null,
		"optimizer_status"     => $result["optimizer_status"] ?? null,
		"points_count"         => $result["points_count"] ?? null,
		"segments_count"       => $result["segments_count"] ?? null,
		"indexed_vectors_count"=> $result["indexed_vectors_count"] ?? null,
		"vector_config"        => $result["config"]["params"]["vectors"] ?? null,
		"payload_schema"       => $result["payload_schema"] ?? null,
	)
);
'
```

Healthy output should show:

- `qdrant_status: green`
- `optimizer_status: ok`
- `points_count` close to `indexed_count`
- `vector_config.size` equal to the active embedding dimension
- payload indexes for `blockTypes`, `templateTypes`, `categories`, and `traits`

Use `GET /collections/{name}`, not `GET /collections/{name}/index`, for this summary.

### 4. Inspect Qdrant optimizer activity

```bash
wp eval '
$state      = get_option( \FlavorAgent\Patterns\PatternIndex::STATE_OPTION, array() );
$collection = (string) ( $state["qdrant_collection"] ?? "" );
$result     = \FlavorAgent\Embeddings\QdrantClient::get_collection_optimizations(
	$collection,
	array( "queued", "completed", "idle_segments" )
);

if ( is_wp_error( $result ) ) {
	echo wp_json_encode(
		array(
			"error"   => $result->get_error_code(),
			"message" => $result->get_error_message(),
			"data"    => $result->get_error_data(),
		)
	);
	return;
}

echo wp_json_encode( $result );
'
```

Interpretation:

- queued or running optimizations can explain temporary latency or inconsistent performance
- no queued work and no running work is the ideal steady state

### 5. Compare raw retrieval to final output

For Qdrant, first inspect raw Qdrant hits:

```bash
wp eval '
$prompt     = "hero section with image and call to action";
$state      = \FlavorAgent\Patterns\PatternIndex::get_runtime_state();
$collection = (string) ( $state["qdrant_collection"] ?? "" );
$class      = new ReflectionClass( "FlavorAgent\\Abilities\\PatternAbilities" );
$method     = $class->getMethod( "build_embedding_query" );
$method->setAccessible( true );

$query = $method->invoke(
	null,
	"page",
	"",
	"",
	$prompt,
	"",
	array(),
	array(),
	"",
	"",
	"",
	false
);

$vector = \FlavorAgent\Embeddings\EmbeddingClient::embed( $query );

if ( is_wp_error( $vector ) ) {
	echo wp_json_encode(
		array(
			"error"   => $vector->get_error_code(),
			"message" => $vector->get_error_message(),
		)
	);
	return;
}

$hits = \FlavorAgent\Embeddings\QdrantClient::search( $vector, 8, array(), $collection );

if ( is_wp_error( $hits ) ) {
	echo wp_json_encode(
		array(
			"error"   => $hits->get_error_code(),
			"message" => $hits->get_error_message(),
		)
	);
	return;
}

$summary = array(
	"query" => $query,
	"hits"  => array(),
);

foreach ( array_slice( $hits, 0, 8 ) as $hit ) {
	$summary["hits"][] = array(
		"name"          => $hit["payload"]["name"] ?? null,
		"title"         => $hit["payload"]["title"] ?? null,
		"score"         => $hit["score"] ?? null,
		"blockTypes"    => $hit["payload"]["blockTypes"] ?? array(),
		"templateTypes" => $hit["payload"]["templateTypes"] ?? array(),
		"traits"        => $hit["payload"]["traits"] ?? array(),
	);
}

echo wp_json_encode( $summary );
'
```

Then inspect the final recommendation output:

```bash
wp eval '
$result = \FlavorAgent\Abilities\PatternAbilities::recommend_patterns(
	array(
		"postType"            => "page",
		"prompt"              => "hero section with image and call to action",
		"visiblePatternNames" => array( "theme/hero", "theme/call-to-action", "core/block/123" ),
	)
);

if ( is_wp_error( $result ) ) {
	echo wp_json_encode(
		array(
			"error"   => $result->get_error_code(),
			"message" => $result->get_error_message(),
			"data"    => $result->get_error_data(),
		)
	);
	return;
}

echo wp_json_encode( $result["recommendations"] ?? array() );
'
```

Interpretation:

- if raw Qdrant hits are bad, the problem is retrieval, indexing, or the collection contents
- if raw Qdrant hits are good but final recommendations are bad, the problem is reranking or post-rerank filtering

For Cloudflare AI Search, inspect raw chunks first:

```bash
wp eval '
$result = \FlavorAgent\Cloudflare\PatternSearchClient::search_patterns(
	"hero section with image and call to action",
	array( "theme/hero", "theme/call-to-action", "core/block/123" ),
	8
);

if ( is_wp_error( $result ) ) {
	echo wp_json_encode(
		array(
			"error"   => $result->get_error_code(),
			"message" => $result->get_error_message(),
			"data"    => $result->get_error_data(),
		)
	);
	return;
}

echo wp_json_encode( $result );
'
```

Interpretation:

- if raw chunks are empty, confirm the `visiblePatternNames` filter, filterable metadata schema, and item sync state
- if raw chunks are good but final recommendations are empty, inspect synced-pattern access and the active backend threshold

## Symptom Runbook

### `missing_credentials`

What it means:

- pattern recommendations do not have embeddings, chat, Qdrant URL, or Qdrant key configured
- or the selected Cloudflare AI Search pattern backend does not have private account, namespace, instance, or token configured

Where it is enforced:

- `PatternAbilities::validate_recommendation_backends()` in `inc/Abilities/PatternAbilities.php`

What to check:

- `Provider::embedding_configured()`
- `Provider::chat_configured()`
- `flavor_agent_qdrant_url`
- `flavor_agent_qdrant_key`
- `flavor_agent_pattern_retrieval_backend`
- `flavor_agent_cloudflare_pattern_ai_search_account_id`
- `flavor_agent_cloudflare_pattern_ai_search_namespace`
- `flavor_agent_cloudflare_pattern_ai_search_instance_id`
- `flavor_agent_cloudflare_pattern_ai_search_api_token`

Most likely fix:

- configure text generation in `Settings > Connectors`
- configure the selected pattern backend in `Settings > Flavor Agent`: plugin-owned embeddings and Qdrant for Qdrant, or private Cloudflare AI Search for AI Search
- if a connector-backed provider is pinned, confirm that connector path is still available

### `index_warming`

What it means:

- the collection is rebuilding
- or the runtime state drifted away from the active embedding or Qdrant configuration

Where it comes from:

- `PatternAbilities::recommend_patterns()` in `inc/Abilities/PatternAbilities.php`

What to check:

- `status`
- `stale_reasons`
- `qdrant_collection`
- `pattern_backend`
- `cloudflare_ai_search_namespace`
- `cloudflare_ai_search_instance`
- `cloudflare_ai_search_signature`
- `embedding_signature`
- `last_attempt_at`
- scheduled cron events for `flavor_agent_reindex_patterns`

Helpful commands:

```bash
wp cron event list --fields=hook,next_run,next_run_relative | rg flavor_agent_reindex_patterns

wp transient get flavor_agent_sync_lock
```

Interpretation:

- `embedding_signature_changed`, `collection_name_changed`, or `collection_size_mismatch` means Qdrant configuration drift, not transient request failure
- `pattern_backend_changed`, `cloudflare_ai_search_instance_changed`, or `cloudflare_ai_search_signature_changed` means selected backend or private AI Search configuration drift
- no scheduled cron event while the state stays stale suggests background rebuilds are not being queued or executed
- a long-lived sync lock suggests the rebuild path did not clean up correctly

### `index_unavailable`

What it means:

- sync failed and there is no usable previous snapshot to fall back to

Where it comes from:

- `PatternAbilities::recommend_patterns()` after `PatternIndex::has_usable_index()` fails

What to check:

- `last_error`
- `last_error_code`
- `last_error_status`
- `last_error_retryable`
- `last_error_retry_after`

Most likely root causes:

- embedding request failure
- Qdrant collection creation or write failure
- Qdrant scroll, upsert, or delete failure during sync
- Cloudflare AI Search validation, item list, upload, or delete failure during sync

### Empty recommendations with no hard error

What it means:

- the request succeeded, but candidate selection or post-filtering produced no results

Most likely causes:

- `visiblePatternNames` was missing or empty
- raw Qdrant retrieval returned no usable matches
- raw Cloudflare AI Search retrieval returned no usable chunks
- synced/user candidates were dropped during request-time `read_post` rehydration
- reranker scores fell below the score threshold

Where to look:

- `PatternAbilities::recommend_patterns()` in `inc/Abilities/PatternAbilities.php`
- `DEFAULT_RECOMMENDATION_SCORE_THRESHOLD`
- the caller-supplied `visiblePatternNames`
- current `wp_block` post status and `current_user_can( 'read_post', $id )` for synced/user candidates

Important nuance:

- this is not automatically a Qdrant failure
- raw retrieval can succeed while thresholding still removes every final result
- raw synced hits can also be correct while current post access removes them before ranking
- Cloudflare AI Search scores use their own scale. The AI Search backend reads `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search`, while Qdrant reads `flavor_agent_pattern_recommendation_threshold`.

### Calibrate backend thresholds

Workers AI embeddings and Cloudflare AI Search scores are not equivalent to OpenAI `text-embedding-3-*` embeddings plus Qdrant cosine scores. Treat thresholds as backend-specific operational settings, not portable quality numbers.

Use a representative visible scope and prompt, then compare raw retrieval against final renderable recommendations:

```bash
wp eval 'echo wp_json_encode( \FlavorAgent\Patterns\PatternIndex::sync() );'
wp eval 'echo wp_json_encode( \FlavorAgent\Abilities\PatternAbilities::recommend_patterns( array( "postType" => "post", "visiblePatternNames" => array( "theme/hero", "theme/footer-callout" ), "prompt" => "hero for a product launch" ) ) );'
```

Record this evidence for each backend you tune:

- backend (`qdrant` or `cloudflare_ai_search`)
- embedding model for Qdrant or AI Search namespace and instance
- active threshold option and value
- number of candidates before rerank
- number of renderable final recommendations
- visible scope size

For Qdrant, tune `flavor_agent_pattern_recommendation_threshold`. For Cloudflare AI Search, tune `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search`; its RRF-fused hybrid scores are independent from Qdrant similarity scores.

### Raw Qdrant hits look broad or irrelevant

What it usually means:

- the collection is healthy, but retrieval precision is weak for the specific context

Most common reasons in this repo:

- the request has little structural context, so the semantic pass dominates
- the prompt is broad and the collection contains many visually similar landing-page patterns
- the catalog is small enough that exact scan retrieval is acceptable but still semantically broad

What to compare:

- does the raw hit list contain at least a few clearly relevant patterns
- does the request include `blockContext`, `templateType`, `insertionContext`, or `visiblePatternNames`
- does the structural `should` pass in `build_structural_should_clauses()` have any useful signals to work with

If raw hits are wrong:

- inspect the stored pattern payloads
- inspect the embedding query text
- confirm the collection belongs to the active embedding signature

### Raw Cloudflare AI Search chunks look broad or irrelevant

What it usually means:

- the private AI Search instance is reachable, but retrieval precision or filtering is weak for the current context

What to compare:

- does the request include the expected `visiblePatternNames`
- did the uploaded pattern markdown include title, description, categories, block types, template types, traits, and sanitized content
- are the five metadata fields filterable in the Cloudflare dashboard, especially `pattern_name`
- does the active threshold come from `flavor_agent_pattern_recommendation_threshold_cloudflare_ai_search`

If raw chunks are wrong:

- inspect the uploaded item metadata and markdown body
- confirm `PatternIndex::sync()` uploaded changed items with stable item IDs and `wait_for_completion=true`
- confirm stale remote item IDs were deleted after sync
- confirm the AI Search instance/namespace in runtime state matches the selected settings

### Raw Qdrant hits look good but final recommendations still look wrong

What it usually means:

- the ranker prompt or the post-rank score filter is the problem

Where it happens:

- `ResponsesClient::rank()` call inside `PatternAbilities::recommend_patterns()`
- JSON parse of the ranker output
- score threshold filtering after parse

What to check:

- can `ResponsesClient::validate_configuration()` still succeed against the WordPress AI Client / Connectors runtime
- are final scores unexpectedly low
- do the returned reasons ignore obvious layout traits or the user instruction

Low-priority suspect:

- WordPress docs guidance from `AISearchClient`

Reason:

- in this pipeline, docs guidance is cache-only and non-blocking
- cache misses schedule async warming and do not perform foreground AI Search
- cache misses do not block retrieval or ranking
- live Cloudflare AI Search requests should not appear in foreground `/recommend-patterns` request traces

## Fast Decision Rules

- If backend validation fails, fix configuration first.
- If the runtime state is stale or erroring, fix the selected backend rebuild path before looking at ranking quality.
- If the Qdrant collection is green and raw hits look good, stop blaming Qdrant.
- If Cloudflare AI Search raw chunks are relevant and in visible scope, stop blaming AI Search.
- If raw hits are good but final picks are poor, debug `ResponsesClient` and thresholding.
- If final picks are empty without errors, inspect `visiblePatternNames`, synced-pattern access, and score filtering before changing infrastructure.

## Related Docs

- `docs/features/pattern-recommendations.md`
- `docs/features/settings-backends-and-sync.md`
- `docs/reference/abilities-and-routes.md`
