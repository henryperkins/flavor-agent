# Pattern Recommendation Debugging

Use this when pattern recommendations are failing in production and you need to separate:

- backend configuration problems
- pattern-index drift or stalled rebuilds
- Qdrant collection health issues
- weak raw retrieval
- LLM reranking problems

This is the operational companion to `docs/features/pattern-recommendations.md`.

## Fast Mental Model

The pattern pipeline has four distinct stages:

1. `PatternIndex::sync()` in `inc/Patterns/PatternIndex.php` builds and maintains the Qdrant collection
2. `PatternAbilities::recommend_patterns()` in `inc/Abilities/PatternAbilities.php` checks runtime state and backend readiness
3. `QdrantClient::search()` in `inc/AzureOpenAI/QdrantClient.php` retrieves semantic and structural candidates
4. `ResponsesClient::rank()` in `inc/AzureOpenAI/ResponsesClient.php` reranks those candidates into the final recommendation list

If you identify which stage is failing, the rest of the debugging path usually becomes obvious.

## Healthy Baseline

When the system is healthy, all of the following should be true:

- the stored pattern index state reports `status: ready`
- `last_synced_at` is populated
- `indexed_count` is non-zero and matches the collection point count closely
- `QdrantClient::validate_configuration()` returns `true`
- `QdrantClient::probe_health( 'readyz' )` returns HTTP `200`
- the collection definition reports `status: green`
- the collection definition reports `optimizer_status: ok`
- the collection vector size matches the active embedding dimension
- the collection payload schema includes keyword indexes for `blockTypes`, `templateTypes`, `categories`, and `traits`
- raw Qdrant hits for a prompt include obviously relevant patterns
- final recommendations either preserve those hits or improve their order

Small collections have one easy-to-misread detail:

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
- `embedding_dimension`
- `embedding_signature`
- `last_error`
- `last_error_code`
- `last_error_status`
- `last_error_retryable`
- `stale_reasons`

### 2. Validate the three required backends

```bash
wp eval 'var_export( \FlavorAgent\AzureOpenAI\QdrantClient::validate_configuration() );'

wp eval 'echo wp_json_encode( \FlavorAgent\AzureOpenAI\QdrantClient::probe_health( "readyz" ) );'

wp eval 'echo wp_json_encode( \FlavorAgent\AzureOpenAI\EmbeddingClient::validate_configuration() );'

wp eval 'var_export( \FlavorAgent\AzureOpenAI\ResponsesClient::validate_configuration() );'
```

Interpretation:

- if Qdrant validation fails, stop there and fix connectivity or credentials first
- if embeddings validation fails, both sync and live retrieval are blocked
- if Responses validation fails, raw retrieval can still be healthy while final recommendations fail

### 3. Inspect the live collection definition

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

### 4. Inspect optimizer activity

```bash
wp eval '
$state      = get_option( \FlavorAgent\Patterns\PatternIndex::STATE_OPTION, array() );
$collection = (string) ( $state["qdrant_collection"] ?? "" );
$result     = \FlavorAgent\AzureOpenAI\QdrantClient::get_collection_optimizations(
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

First inspect raw Qdrant hits:

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

$vector = \FlavorAgent\AzureOpenAI\EmbeddingClient::embed( $query );

if ( is_wp_error( $vector ) ) {
	echo wp_json_encode(
		array(
			"error"   => $vector->get_error_code(),
			"message" => $vector->get_error_message(),
		)
	);
	return;
}

$hits = \FlavorAgent\AzureOpenAI\QdrantClient::search( $vector, 8, array(), $collection );

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
		"postType" => "page",
		"prompt"   => "hero section with image and call to action",
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

## Symptom Runbook

### `missing_credentials`

What it means:

- pattern recommendations do not have embeddings, chat, Qdrant URL, or Qdrant key configured

Where it is enforced:

- `PatternAbilities::validate_recommendation_backends()` in `inc/Abilities/PatternAbilities.php`

What to check:

- `Provider::embedding_configured()`
- `Provider::chat_configured()`
- `flavor_agent_qdrant_url`
- `flavor_agent_qdrant_key`

Most likely fix:

- complete the missing settings in `Settings > Flavor Agent`
- if chat is connector-backed, confirm the connector path is still available

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
- `embedding_signature`
- `last_attempt_at`
- scheduled cron events for `flavor_agent_reindex_patterns`

Helpful commands:

```bash
wp cron event list --fields=hook,next_run,next_run_relative | rg flavor_agent_reindex_patterns

wp transient get flavor_agent_sync_lock
```

Interpretation:

- `embedding_signature_changed`, `collection_name_changed`, or `collection_size_mismatch` means configuration drift, not transient request failure
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

### Empty recommendations with no hard error

What it means:

- the request succeeded, but candidate selection or post-filtering produced no results

Most likely causes:

- `visiblePatternNames` was present but empty
- raw Qdrant retrieval returned no usable matches
- reranker scores fell below the score threshold

Where to look:

- `PatternAbilities::recommend_patterns()` in `inc/Abilities/PatternAbilities.php`
- `DEFAULT_RECOMMENDATION_SCORE_THRESHOLD`
- the caller-supplied `visiblePatternNames`

Important nuance:

- this is not automatically a Qdrant failure
- raw retrieval can succeed while thresholding still removes every final result

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

### Raw Qdrant hits look good but final recommendations still look wrong

What it usually means:

- the ranker prompt or the post-rank score filter is the problem

Where it happens:

- `ResponsesClient::rank()` call inside `PatternAbilities::recommend_patterns()`
- JSON parse of the ranker output
- score threshold filtering after parse

What to check:

- can `ResponsesClient::validate_configuration()` still succeed
- are final scores unexpectedly low
- do the returned reasons ignore obvious layout traits or the user instruction

Low-priority suspect:

- WordPress docs guidance from `AISearchClient`

Reason:

- in this pipeline, docs guidance is cache-only and non-blocking
- cache misses do not block retrieval or ranking

## Fast Decision Rules

- If backend validation fails, fix configuration first.
- If the runtime state is stale or erroring, fix the rebuild path before looking at ranking quality.
- If the collection is green and raw hits look good, stop blaming Qdrant.
- If raw hits are good but final picks are poor, debug `ResponsesClient` and thresholding.
- If final picks are empty without errors, inspect `visiblePatternNames` and score filtering before changing infrastructure.

## Related Docs

- `docs/features/pattern-recommendations.md`
- `docs/features/settings-backends-and-sync.md`
- `docs/reference/abilities-and-routes.md`
