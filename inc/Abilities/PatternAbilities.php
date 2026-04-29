<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Context\SyncedPatternRepository;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\NormalizesInput;
use FlavorAgent\Support\RankingContract;
use FlavorAgent\Support\StringArray;

final class PatternAbilities {

	use FormatsDocsGuidance;
	use NormalizesInput;

	private const DEFAULT_SEMANTIC_LIMIT                 = 8;
	private const DEFAULT_STRUCTURAL_LIMIT               = 6;
	private const FILTERED_SEMANTIC_LIMIT                = 24;
	private const FILTERED_STRUCTURAL_LIMIT              = 18;
	private const GENERAL_BLOCK_OVERRIDE_BONUS           = 0.03;
	private const CUSTOM_BLOCK_MATCHING_OVERRIDE_BONUS   = 0.04;
	private const CUSTOM_BLOCK_GENERIC_OVERRIDE_BONUS    = 0.02;
	private const SIBLING_OVERRIDE_BONUS_PER_MATCH       = 0.01;
	private const SIBLING_OVERRIDE_BONUS_CAP             = 0.02;
	private const TOTAL_OVERRIDE_BONUS_CAP               = 0.05;
	private const MAX_NEARBY_SIBLING_BLOCK_TYPES         = 4;
	private const MAX_LLM_CANDIDATES                     = 12;
	private const DEFAULT_MAX_RECOMMENDATIONS            = 8;
	private const DEFAULT_RECOMMENDATION_SCORE_THRESHOLD = 0.3;
	private const MAX_LLM_INPUT_CHARS                    = 24000;
	private const MAX_LLM_STRUCTURE_BLOCKS               = 14;
	private const MAX_LLM_STRUCTURE_LINES                = 18;
	private const MAX_LLM_CONTENT_PREVIEW_CHARS          = 240;
	private const SYNCED_PATTERN_NAME_PREFIX             = 'core/block/';

	public static function list_patterns( mixed $input ): array {
		$input           = self::normalize_input( $input );
		$categories      = $input['categories'] ?? null;
		$block_types     = $input['blockTypes'] ?? null;
		$template_types  = $input['templateTypes'] ?? null;
		$include_content = filter_var(
			$input['includeContent'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);
		$limit           = self::normalize_non_negative_int( $input['limit'] ?? null );
		$offset          = self::normalize_non_negative_int( $input['offset'] ?? null ) ?? 0;
		$search          = isset( $input['search'] ) && is_string( $input['search'] )
			? sanitize_text_field( $input['search'] )
			: null;

		return [
			'patterns' => ServerCollector::for_patterns(
				$categories,
				$block_types,
				$template_types,
				$include_content,
				$limit,
				$offset,
				$search
			),
			'total'    => ServerCollector::count_patterns(
				$categories,
				$block_types,
				$template_types,
				$search
			),
		];
	}

	public static function get_pattern( mixed $input ): array|\WP_Error {
		$input      = self::normalize_input( $input );
		$pattern_id = isset( $input['patternId'] )
			? sanitize_text_field( (string) $input['patternId'] )
			: sanitize_text_field( (string) ( $input['name'] ?? '' ) );

		if ( '' === $pattern_id ) {
			return new \WP_Error( 'missing_pattern_id', 'patternId is required.', [ 'status' => 400 ] );
		}

		$pattern = ServerCollector::for_pattern( $pattern_id );

		if ( ! is_array( $pattern ) ) {
			return new \WP_Error( 'pattern_not_found', "Pattern '{$pattern_id}' is not registered.", [ 'status' => 404 ] );
		}

		return $pattern;
	}

	public static function list_synced_patterns( mixed $input ): array {
		$input           = self::normalize_input( $input );
		$sync_status     = isset( $input['syncStatus'] ) && is_string( $input['syncStatus'] )
			? sanitize_key( $input['syncStatus'] )
			: 'synced';
		$include_content = filter_var(
			$input['includeContent'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);
		$limit           = self::normalize_non_negative_int( $input['limit'] ?? null );
		$offset          = self::normalize_non_negative_int( $input['offset'] ?? null ) ?? 0;
		$search          = isset( $input['search'] ) && is_string( $input['search'] )
			? sanitize_text_field( $input['search'] )
			: null;

		return [
			'patterns' => ServerCollector::for_synced_patterns(
				$sync_status,
				$include_content,
				$limit,
				$offset,
				$search
			),
			'total'    => ServerCollector::count_synced_patterns( $sync_status, $search ),
		];
	}

	public static function get_synced_pattern( mixed $input ): array|\WP_Error {
		$input      = self::normalize_input( $input );
		$pattern_id = absint( $input['patternId'] ?? ( $input['id'] ?? 0 ) );

		if ( $pattern_id <= 0 ) {
			return new \WP_Error( 'missing_pattern_id', 'patternId is required.', [ 'status' => 400 ] );
		}

		$pattern = ServerCollector::for_synced_pattern( $pattern_id );

		if ( ! is_array( $pattern ) ) {
			return new \WP_Error( 'pattern_not_found', "Synced pattern '{$pattern_id}' was not found.", [ 'status' => 404 ] );
		}

		return $pattern;
	}

	private static function normalize_non_negative_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( is_numeric( $value ) ) {
			$normalized = (int) $value;
			return $normalized >= 0 ? $normalized : null;
		}

		return null;
	}

	/**
	 * Vector-powered pattern recommendation: embed query, two-pass Qdrant
	 * search, LLM ranking, rehydrate from Qdrant payloads.
	 */
	public static function recommend_patterns( mixed $input ): array|\WP_Error {
		$input = self::normalize_input( $input );

		$visible_pattern_names = array_key_exists( 'visiblePatternNames', $input )
			? StringArray::sanitize( $input['visiblePatternNames'] )
			: null;

		if ( null === $visible_pattern_names || [] === $visible_pattern_names ) {
			return [ 'recommendations' => [] ];
		}

		$configuration_error = self::validate_recommendation_backends();
		if ( is_wp_error( $configuration_error ) ) {
			return $configuration_error;
		}

		// Step 1: Staleness check.
		$state            = PatternIndex::get_runtime_state();
		$has_usable_index = PatternIndex::has_usable_index( $state );

		switch ( $state['status'] ) {
			case 'ready':
				break;

			case 'stale':
				if ( PatternIndex::has_compatibility_drift( $state ) ) {
					PatternIndex::schedule_sync();
					return new \WP_Error(
						'index_warming',
						'Pattern catalog is rebuilding because the embedding or vector-store configuration changed. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
						[ 'status' => 503 ]
					);
				}

				if ( ! $has_usable_index ) {
					PatternIndex::schedule_sync();
					return new \WP_Error(
						'index_warming',
						'Pattern catalog is building. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
						[ 'status' => 503 ]
					);
				}

				PatternIndex::schedule_sync();
				break;

			case 'uninitialized':
				PatternIndex::schedule_sync();
				return new \WP_Error(
					'index_warming',
					'Pattern catalog is building. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
					[ 'status' => 503 ]
				);

			case 'indexing':
				if ( ! $has_usable_index ) {
					return new \WP_Error(
						'index_warming',
						'Pattern catalog is building. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
						[ 'status' => 503 ]
					);
				}

				break;

			case 'error':
			default:
				if ( ! $has_usable_index ) {
					if ( PatternIndex::has_retryable_error( $state ) ) {
						PatternIndex::schedule_sync();
						return new \WP_Error(
							'index_warming',
							'Pattern catalog is building. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
							[ 'status' => 503 ]
						);
					}

					return new \WP_Error(
						'index_unavailable',
						'Pattern catalog sync failed. Review the last sync error in Settings > Flavor Agent.',
						[ 'status' => 503 ]
					);
				}

				PatternIndex::schedule_sync();
				break;
		}

		// Step 2: Build query string.
		$post_type               = isset( $input['postType'] ) && is_string( $input['postType'] ) && sanitize_key( $input['postType'] ) !== ''
			? sanitize_key( $input['postType'] )
			: 'post';
		$block_context           = self::normalize_input( $input['blockContext'] ?? [] );
		$block_name              = isset( $block_context['blockName'] ) && is_string( $block_context['blockName'] )
			? sanitize_text_field( $block_context['blockName'] )
			: '';
		$template_type           = isset( $input['templateType'] ) && is_string( $input['templateType'] )
			? sanitize_text_field( $input['templateType'] )
			: '';
		$prompt                  = isset( $input['prompt'] ) && is_string( $input['prompt'] )
			? sanitize_textarea_field( $input['prompt'] )
			: '';
		$insertion_context       = self::normalize_input( $input['insertionContext'] ?? [] );
		$root_block              = isset( $insertion_context['rootBlock'] ) && is_string( $insertion_context['rootBlock'] )
			? sanitize_text_field( $insertion_context['rootBlock'] )
			: '';
		$ancestors               = StringArray::sanitize( $insertion_context['ancestors'] ?? [] );
		$nearby_siblings         = StringArray::sanitize( $insertion_context['nearbySiblings'] ?? [] );
		$template_part_area      = isset( $insertion_context['templatePartArea'] ) && is_string( $insertion_context['templatePartArea'] )
			? sanitize_key( $insertion_context['templatePartArea'] )
			: '';
		$template_part_slug      = isset( $insertion_context['templatePartSlug'] ) && is_string( $insertion_context['templatePartSlug'] )
			? sanitize_text_field( $insertion_context['templatePartSlug'] )
			: '';
		$container_layout        = isset( $insertion_context['containerLayout'] ) && is_string( $insertion_context['containerLayout'] )
			? sanitize_key( $insertion_context['containerLayout'] )
			: '';
		$has_insertion_context   = array_key_exists( 'insertionContext', $input )
			|| $root_block !== ''
			|| ! empty( $ancestors )
			|| ! empty( $nearby_siblings )
			|| $template_part_area !== ''
			|| $template_part_slug !== ''
			|| $container_layout !== '';
		$is_custom_block_context = self::is_custom_block_name( $block_name );
		$visible_lookup          = array_fill_keys( $visible_pattern_names, true );
		$semantic_limit          = $visible_lookup ? self::FILTERED_SEMANTIC_LIMIT : self::DEFAULT_SEMANTIC_LIMIT;
		$structural_limit        = $visible_lookup ? self::FILTERED_STRUCTURAL_LIMIT : self::DEFAULT_STRUCTURAL_LIMIT;
		$docs_guidance           = self::collect_wordpress_docs_guidance(
			[
				'postType'            => $post_type,
				'templateType'        => $template_type,
				'blockContext'        => $block_context,
				'insertionContext'    => $insertion_context,
				'visiblePatternNames' => $visible_pattern_names,
			],
			$prompt
		);

		$query = self::build_embedding_query(
			$post_type,
			$block_name,
			$template_type,
			$prompt,
			$root_block,
			$ancestors,
			$nearby_siblings,
			$template_part_area,
			$template_part_slug,
			$container_layout,
			$has_insertion_context
		);

		// Step 3: Embed query.
		$query_vector = EmbeddingClient::embed( $query );
		if ( is_wp_error( $query_vector ) ) {
			return $query_vector;
		}

		$active_signature    = EmbeddingClient::build_signature_for_dimension( count( $query_vector ) );
		$expected_collection = QdrantClient::get_collection_name( $active_signature );

		if (
			(string) ( $state['embedding_signature'] ?? '' ) !== (string) $active_signature['signature_hash']
			|| (string) ( $state['qdrant_collection'] ?? '' ) !== $expected_collection
		) {
			PatternIndex::mark_stale(
				[
					'embedding_signature_changed',
					'collection_name_changed',
				]
			);

			PatternIndex::schedule_sync();

			return new \WP_Error(
				'index_warming',
				'Pattern catalog is rebuilding because the active embedding signature changed. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
				[ 'status' => 503 ]
			);
		}

		$collection_validation = QdrantClient::validate_collection_compatibility(
			(string) $state['qdrant_collection'],
			count( $query_vector )
		);

		if ( is_wp_error( $collection_validation ) ) {
			if ( 'qdrant_collection_missing' === $collection_validation->get_error_code() ) {
				PatternIndex::mark_stale( [ 'collection_missing' ] );
				PatternIndex::schedule_sync();

				return new \WP_Error(
					'index_warming',
					'Pattern catalog is rebuilding because the active Qdrant collection is missing. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
					[ 'status' => 503 ]
				);
			}

			if ( 'qdrant_collection_size_mismatch' === $collection_validation->get_error_code() ) {
				PatternIndex::mark_stale( [ 'collection_size_mismatch' ] );
				PatternIndex::schedule_sync();

				return new \WP_Error(
					'index_warming',
					'Pattern catalog is rebuilding because the active Qdrant collection is incompatible with the current embedding size. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
					[ 'status' => 503 ]
				);
			}

			return $collection_validation;
		}

		// Step 4: Two-pass Qdrant retrieval.
		$pass_a = QdrantClient::search(
			$query_vector,
			$semantic_limit,
			[],
			(string) $state['qdrant_collection']
		);
		if ( is_wp_error( $pass_a ) ) {
			return $pass_a;
		}

		$pass_b              = [];
		$should_clauses      = self::build_structural_should_clauses(
			$post_type,
			$block_name,
			$template_type,
			$root_block,
			$ancestors,
			$nearby_siblings,
			$template_part_area,
			$has_insertion_context
		);
		$ran_structural_pass = ! empty( $should_clauses );

		if ( $ran_structural_pass ) {
			$pass_b = QdrantClient::search(
				$query_vector,
				$structural_limit,
				[ 'should' => $should_clauses ],
				(string) $state['qdrant_collection']
			);
			if ( is_wp_error( $pass_b ) ) {
				return $pass_b;
			}
		}

		// Union, dedupe by payload.name, keep best score.
		$candidates                = [];
		$retrieved_candidate_names = [];
		foreach ( array_merge( $pass_a, $pass_b ) as $point ) {
			$payload = is_array( $point['payload'] ?? null ) ? $point['payload'] : [];
			$payload = self::resolve_recommendation_candidate_payload( $payload );

			if ( [] === $payload ) {
				continue;
			}

			$name  = $payload['name'] ?? '';
			$score = $point['score'] ?? 0.0;
			if ( $name === '' ) {
				continue;
			}
			$retrieved_candidate_names[ $name ] = true;
			if ( $visible_lookup && ! isset( $visible_lookup[ $name ] ) ) {
				continue;
			}
			if ( ! isset( $candidates[ $name ] ) || $candidates[ $name ]['score'] < $score ) {
				$candidates[ $name ] = [
					'score'   => $score,
					'payload' => $payload,
				];
			}
		}

		foreach ( $candidates as &$candidate ) {
			$ranking_hint = self::build_candidate_ranking_hint(
				is_array( $candidate['payload'] ?? null ) ? $candidate['payload'] : [],
				$block_name,
				$nearby_siblings,
				$is_custom_block_context
			);

			$candidate['rankingHint']  = $ranking_hint;
			$candidate['rankingScore'] = (float) $candidate['score']
				+ (float) ( $ranking_hint['bonus'] ?? 0.0 );
		}
		unset( $candidate );

		// Sort by score desc, take top 12.
		uasort(
			$candidates,
			static function ( array $left, array $right ): int {
				$ranking_compare = ( $right['rankingScore'] ?? $right['score'] ) <=> ( $left['rankingScore'] ?? $left['score'] );

				if ( 0 !== $ranking_compare ) {
					return $ranking_compare;
				}

				return $right['score'] <=> $left['score'];
			}
		);
		$candidates = self::diversify_candidates(
			array_slice( $candidates, 0, self::MAX_LLM_CANDIDATES * 2, true ),
			self::MAX_LLM_CANDIDATES
		);

		if ( empty( $candidates ) ) {
			return [ 'recommendations' => [] ];
		}

		// Step 5: Rank via Responses API.
		$llm_input = self::build_ranking_input(
			$post_type,
			$block_name,
			$template_type,
			$prompt,
			$root_block,
			$ancestors,
			$nearby_siblings,
			$template_part_area,
			$template_part_slug,
			$container_layout,
			$block_name,
			$has_insertion_context,
			$is_custom_block_context,
			$docs_guidance,
			$visible_pattern_names,
			count( $retrieved_candidate_names ),
			$candidates
		);

		$ranked = ResponsesClient::rank( self::ranking_system_prompt(), $llm_input );
		if ( is_wp_error( $ranked ) ) {
			return $ranked;
		}

		// Step 6: Parse and rehydrate.
		$cleaned = preg_replace( '/^```(?:json)?\s*\n?|\n?```\s*$/m', '', trim( $ranked ) );
		$data    = json_decode( $cleaned, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['recommendations'] ) ) {
			return new \WP_Error(
				'parse_error',
				'Failed to parse ranking response.',
				[ 'status' => 502 ]
			);
		}

		$recommendations     = [];
		$max_recommendations = self::max_recommendations();
		$score_threshold     = self::recommendation_score_threshold();
		foreach ( (array) $data['recommendations'] as $rec ) {
			if ( count( $recommendations ) >= $max_recommendations ) {
				break;
			}
			$name = $rec['name'] ?? '';
			if ( ! isset( $candidates[ $name ] ) ) {
				continue;
			}

			$payload      = $candidates[ $name ]['payload'];
			$overrides    = self::normalize_pattern_overrides_metadata( $payload['patternOverrides'] ?? [] );
			$ranking_hint = is_array( $candidates[ $name ]['rankingHint'] ?? null )
				? $candidates[ $name ]['rankingHint']
				: [];
			$score        = isset( $rec['score'] ) ? max( 0.0, min( 1.0, (float) $rec['score'] ) ) : 0.0;
			if ( $score < $score_threshold ) {
				continue;
			}
			$reason         = self::append_pattern_override_reason_hint(
				sanitize_text_field( $rec['reason'] ?? '' ),
				$ranking_hint
			);
			$source_signals = [ 'qdrant_semantic', 'llm_ranker' ];
			if ( $ran_structural_pass ) {
				$source_signals[] = 'qdrant_structural';
			}
			$recommendations[] = [
				'name'                 => $name,
				'title'                => $payload['title'] ?? '',
				'type'                 => $payload['type'] ?? 'registered',
				'source'               => $payload['source'] ?? 'registered',
				'syncedPatternId'      => $payload['syncedPatternId'] ?? 0,
				'syncStatus'           => $payload['syncStatus'] ?? '',
				'wpPatternSyncStatus'  => $payload['wpPatternSyncStatus'] ?? '',
				'score'                => $score,
				'reason'               => $reason,
				'categories'           => $payload['categories'] ?? [],
				'patternOverrides'     => $overrides,
				'overrideCapabilities' => self::build_override_capabilities(
					$overrides,
					$ranking_hint
				),
				'ranking'              => RankingContract::normalize(
					is_array( $rec['ranking'] ?? null ) ? $rec['ranking'] : [],
					[
						'score'         => $score,
						'reason'        => $reason,
						'sourceSignals' => $source_signals,
						'safetyMode'    => 'validated',
						'freshnessMeta' => [
							'indexStatus'        => (string) ( $state['status'] ?? '' ),
							'embeddingSignature' => (string) ( $state['embedding_signature'] ?? '' ),
							'qdrantCollection'   => (string) ( $state['qdrant_collection'] ?? '' ),
						],
						'rankingHint'   => $ranking_hint,
					]
				),
				'content'              => $payload['content'] ?? '',
			];
		}

		usort(
			$recommendations,
			static fn( array $left, array $right ): int => $right['score'] <=> $left['score']
		);

		return [ 'recommendations' => $recommendations ];
	}

	private static function validate_recommendation_backends(): true|\WP_Error {
		$qdrant_url = get_option( 'flavor_agent_qdrant_url', '' );
		$qdrant_key = get_option( 'flavor_agent_qdrant_key', '' );

		if (
			! Provider::embedding_configured()
			|| ! Provider::chat_configured()
			|| $qdrant_url === ''
			|| $qdrant_key === ''
		) {
			return new \WP_Error(
				'missing_credentials',
				'Pattern recommendations need a compatible embedding backend and Qdrant in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.',
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function resolve_recommendation_candidate_payload( array $payload ): array {
		if ( ! self::is_synced_candidate_payload( $payload ) ) {
			return $payload;
		}

		$synced_pattern_id = self::resolve_synced_pattern_id_from_payload( $payload );

		if ( $synced_pattern_id <= 0 ) {
			return [];
		}

		$current_pattern = ServerCollector::for_readable_synced_pattern_recommendation( $synced_pattern_id );

		if ( ! is_array( $current_pattern ) ) {
			return [];
		}

		$rehydrated = SyncedPatternRepository::normalize_synced_pattern_payload( $current_pattern );

		if ( [] === $rehydrated ) {
			return [];
		}

		$rehydrated['traits'] = PatternIndex::infer_layout_traits( $rehydrated );

		return $rehydrated;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function is_synced_candidate_payload( array $payload ): bool {
		return 'user' === sanitize_key( (string) ( $payload['type'] ?? '' ) )
			|| 'synced' === sanitize_key( (string) ( $payload['source'] ?? '' ) )
			|| absint( $payload['syncedPatternId'] ?? 0 ) > 0
			|| self::resolve_core_block_pattern_id( $payload['name'] ?? '' ) > 0
			|| self::resolve_core_block_pattern_id( $payload['id'] ?? '' ) > 0;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function resolve_synced_pattern_id_from_payload( array $payload ): int {
		$synced_pattern_id = absint( $payload['syncedPatternId'] ?? 0 );

		if ( $synced_pattern_id > 0 ) {
			return $synced_pattern_id;
		}

		foreach ( [ 'name', 'id' ] as $key ) {
			$synced_pattern_id = self::resolve_core_block_pattern_id( $payload[ $key ] ?? '' );

			if ( $synced_pattern_id > 0 ) {
				return $synced_pattern_id;
			}
		}

		if ( is_numeric( $payload['id'] ?? null ) ) {
			return absint( $payload['id'] );
		}

		return 0;
	}

	private static function resolve_core_block_pattern_id( mixed $value ): int {
		if ( ! is_string( $value ) ) {
			return 0;
		}

		if ( ! preg_match( '#^' . preg_quote( self::SYNCED_PATTERN_NAME_PREFIX, '#' ) . '(\d+)$#', $value, $matches ) ) {
			return 0;
		}

		return absint( $matches[1] ?? 0 );
	}

	private static function ranking_system_prompt(): string {
		return <<<'SYSTEM'
You are a WordPress pattern recommendation engine.

You receive a list of candidate block patterns and an editing context
(post type, nearby block, template type, user instruction, insertion context,
design summary, optional WordPress Developer Guidance).

Your job: score each pattern for relevance to the context and explain why.

Respond with a JSON object (no markdown fences, no text outside the JSON):

{
  "recommendations": [
    {
      "name": "pattern-slug",
      "score": 0.85,
      "reason": "One sentence explaining why this pattern fits"
    }
  ]
}

Rules:
- Score 0.0 to 1.0 where 1.0 = perfect fit for the context.
- Order by score descending.
- Consider: post type conventions, block proximity, template structure,
  category relevance, and the user's stated intent.
- When insertion context is provided, consider the container block and
  ancestor chain. Prefer patterns that fit the container's constraints
  (e.g., simpler patterns for columns, full-width patterns for root areas).
  Nearby sibling block types indicate what content already exists — avoid
  recommending patterns that duplicate adjacent content.
- When a Design Summary is provided, prefer patterns whose visual character
  (density, media usage, layout structure) matches the site's design
  vocabulary. Pattern traits, structureSummary, and contentBlockCount are
  the primary layout signals.
- When a Visible Pattern Scope section is provided, the candidate list is
  already filtered to the patterns WordPress allows at that insertion
  point. Rank only within that allowed subset.
- Prefer patterns when their Pattern Overrides metadata overlaps the nearby
  block's bindable fields or nearby sibling block types. This applies to
  both core and custom blocks.
- When an override overlap is relevant, mention the specific overlapping
  attribute names in the reason text.
- In custom block contexts, also prefer patterns whose Pattern Overrides
  metadata shows relevant support for the nearby custom block or other
  custom blocks when that flexibility materially improves fit.
- When WordPress Developer Guidance is provided, prefer recommendations that
  align with documented pattern, block, template, and theme.json guidance.
- When Pattern Overrides metadata materially strengthens the recommendation,
  mention that briefly in the reason.
- Return only name, score, and reason per pattern. Title, categories,
  traits, structureSummary, contentPreview, and Pattern Overrides metadata
  are attached from the source data.
- Return only the strongest matches and keep the list concise.
SYSTEM;
	}

	private static function build_custom_block_context_section( string $block_name, bool $is_custom_block_context ): string {
		if ( ! $is_custom_block_context ) {
			return '';
		}

		return "Custom block context: {$block_name}\n"
			. "Prefer patterns with relevant Pattern Overrides support when it materially improves flexibility around this custom block.\n";
	}

	/**
	 * Build an insertion context section for the LLM prompt.
	 *
	 * @param string   $root_block         Block name at the insertion root.
	 * @param string[] $ancestors          Ancestor block name chain (outermost first).
	 * @param string[] $nearby_siblings    Block names near the insertion point.
	 * @param string   $template_part_area Template-part area when known.
	 * @param string   $template_part_slug Template-part slug when known.
	 * @param string   $container_layout   Root container layout type when known.
	 */
	private static function build_insertion_context_section(
		string $root_block,
		array $ancestors,
		array $nearby_siblings,
		string $template_part_area = '',
		string $template_part_slug = '',
		string $container_layout = '',
		bool $has_insertion_context = false
	): string {
		if (
			! $has_insertion_context
			&&
			$root_block === ''
			&& empty( $ancestors )
			&& empty( $nearby_siblings )
			&& $template_part_area === ''
			&& $template_part_slug === ''
			&& $container_layout === ''
		) {
			return '';
		}

		$lines = [];

		if ( $root_block !== '' ) {
			$lines[] = "Container: {$root_block}";
		}

		if ( ! empty( $ancestors ) ) {
			$lines[] = 'Ancestor chain: ' . implode( ' > ', array_slice( $ancestors, 0, 6 ) );
		}

		if ( ! empty( $nearby_siblings ) ) {
			$lines[] = 'Nearby blocks: ' . implode( ', ', array_slice( $nearby_siblings, 0, 6 ) );
		}

		if ( $template_part_area !== '' ) {
			$lines[] = "Template-part area: {$template_part_area}";
		}

		if ( $template_part_slug !== '' ) {
			$lines[] = "Template-part slug: {$template_part_slug}";
		}

		if ( $container_layout !== '' ) {
			$lines[] = "Container layout: {$container_layout}";
		}

		$is_constrained = self::is_constrained_insertion_area( $root_block, $ancestors, $template_part_area );
		$is_root_level  = self::is_root_insertion_area( $root_block, $ancestors, $template_part_area );

		if ( $is_constrained ) {
			$lines[] = 'Area type: constrained (prefer compact patterns)';
		} elseif ( $is_root_level ) {
			$lines[] = 'Area type: root-level (broader section patterns can fit)';
		}

		return "\n## Insertion Context\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Build a compact design vocabulary summary from the active theme tokens.
	 */
	private static function build_compact_design_summary(): string {
		$tokens = ServerCollector::for_tokens();
		$parts  = [];

		$color_count    = count( $tokens['colorPresets'] ?? [] );
		$gradient_count = count( $tokens['gradientPresets'] ?? [] );

		if ( $color_count > 0 || $gradient_count > 0 ) {
			$parts[] = sprintf( 'Palette: %d colors, %d gradients', $color_count, $gradient_count );
		}

		$font_count = count( $tokens['fontFamilyPresets'] ?? [] );
		$size_count = count( $tokens['fontSizePresets'] ?? [] );
		$fluid      = ! empty( $tokens['enabledFeatures']['fluid'] ) ? ', fluid' : '';

		if ( $font_count > 0 || $size_count > 0 ) {
			$parts[] = sprintf( 'Typography: %d families, %d sizes%s', $font_count, $size_count, $fluid );
		}

		$spacing_count = count( $tokens['spacingPresets'] ?? [] );

		if ( $spacing_count > 0 ) {
			$parts[] = sprintf( 'Spacing: %d scale steps', $spacing_count );
		}

		$content_size = $tokens['layout']['content'] ?? '';
		$wide_size    = $tokens['layout']['wide'] ?? '';

		if ( $content_size !== '' || $wide_size !== '' ) {
			$layout_parts = [];

			if ( $content_size !== '' ) {
				$layout_parts[] = "content {$content_size}";
			}

			if ( $wide_size !== '' ) {
				$layout_parts[] = "wide {$wide_size}";
			}

			$parts[] = 'Layout: ' . implode( ', ', $layout_parts );
		}

		$style_features = [];
		$enabled        = $tokens['enabledFeatures'] ?? [];

		if ( ! empty( $enabled['borderRadius'] ) ) {
			$style_features[] = 'border-radius';
		}

		if ( count( $tokens['shadowPresets'] ?? [] ) > 0 ) {
			$style_features[] = 'shadows';
		}

		if ( ! empty( $enabled['backgroundImage'] ) ) {
			$style_features[] = 'background-image';
		}

		if ( ! empty( $enabled['margin'] ) || ! empty( $enabled['padding'] ) ) {
			$style_features[] = 'custom-spacing';
		}

		if ( ! empty( $style_features ) ) {
			$parts[] = 'Available: ' . implode( ', ', $style_features );
		}

		return implode( "\n", array_map( static fn( string $part ): string => "- {$part}", $parts ) );
	}

	private static function build_design_summary_section(): string {
		$summary = self::build_compact_design_summary();

		if ( $summary === '' ) {
			return '';
		}

		return "\n## Design Summary\n" . $summary . "\n";
	}

	/**
	 * Enforce category diversity in the candidate pool before LLM ranking.
	 *
	 * Caps per-category representation so the result set is not dominated
	 * by structurally similar patterns from the same category cluster.
	 *
	 * @param array<string, array> $candidates Score-sorted candidates.
	 * @param int                  $max        Maximum candidates to return.
	 * @return array<string, array> Diversified candidates.
	 */
	private static function diversify_candidates( array $candidates, int $max ): array {
		if ( count( $candidates ) <= $max ) {
			return $candidates;
		}

		$category_counts = [];
		$trait_counts    = [];

		foreach ( $candidates as $candidate ) {
			$categories    = $candidate['payload']['categories'] ?? [];
			$primary       = $categories[0] ?? '_uncategorized';
			$traits        = is_array( $candidate['payload']['traits'] ?? null ) ? $candidate['payload']['traits'] : [];
			$primary_trait = $traits[0] ?? '_untyped';

			$category_counts[ $primary ]    = ( $category_counts[ $primary ] ?? 0 ) + 1;
			$trait_counts[ $primary_trait ] = ( $trait_counts[ $primary_trait ] ?? 0 ) + 1;
		}

		$threshold       = max( 3, (int) ceil( $max * 0.4 ) );
		$needs_diversity = false;

		foreach ( array_merge( array_values( $category_counts ), array_values( $trait_counts ) ) as $count ) {
			if ( $count > $threshold ) {
				$needs_diversity = true;
				break;
			}
		}

		if ( ! $needs_diversity ) {
			return array_slice( $candidates, 0, $max, true );
		}

		$picked          = [];
		$category_picked = [];
		$trait_picked    = [];
		$overflow        = [];

		foreach ( $candidates as $name => $candidate ) {
			if ( count( $picked ) >= $max ) {
				break;
			}

			$categories          = $candidate['payload']['categories'] ?? [];
			$primary             = $categories[0] ?? '_uncategorized';
			$traits              = is_array( $candidate['payload']['traits'] ?? null ) ? $candidate['payload']['traits'] : [];
			$primary_trait       = $traits[0] ?? '_untyped';
			$current_count       = $category_picked[ $primary ] ?? 0;
			$current_trait_count = $trait_picked[ $primary_trait ] ?? 0;

			if ( $current_count < $threshold && $current_trait_count < $threshold ) {
				$picked[ $name ]                = $candidate;
				$category_picked[ $primary ]    = $current_count + 1;
				$trait_picked[ $primary_trait ] = $current_trait_count + 1;
			} else {
				$overflow[ $name ] = $candidate;
			}
		}

		foreach ( $overflow as $name => $candidate ) {
			if ( count( $picked ) >= $max ) {
				break;
			}

			$picked[ $name ] = $candidate;
		}

		return $picked;
	}

	private static function is_custom_block_name( string $block_name ): bool {
		return $block_name !== '' && ! str_starts_with( $block_name, 'core/' );
	}

	private static function is_root_insertion_area( string $root_block, array $ancestors, string $template_part_area ): bool {
		return $root_block === '' && empty( $ancestors ) && $template_part_area === '';
	}

	private static function is_constrained_insertion_area( string $root_block, array $ancestors, string $template_part_area ): bool {
		$constrained_containers = [ 'core/column', 'core/group', 'core/query', 'core/media-text' ];

		if ( in_array( $template_part_area, [ 'header', 'footer', 'sidebar' ], true ) ) {
			return true;
		}

		if ( in_array( $root_block, $constrained_containers, true ) ) {
			return true;
		}

		return [] !== array_intersect( $ancestors, $constrained_containers );
	}

	/**
	 * @param mixed $override_attributes
	 * @return array<string, string[]>
	 */
	private static function sanitize_override_attribute_map( mixed $override_attributes ): array {
		if ( ! is_array( $override_attributes ) ) {
			return [];
		}

		$sanitized = [];

		foreach ( $override_attributes as $candidate_block_name => $attributes ) {
			if ( ! is_string( $candidate_block_name ) || $candidate_block_name === '' ) {
				continue;
			}

			$attribute_list = StringArray::sanitize( $attributes );

			if ( [] === $attribute_list ) {
				continue;
			}

			$sanitized[ $candidate_block_name ] = $attribute_list;
		}

		return $sanitized;
	}

	/**
	 * @param array<string, string[]> $override_attributes_map
	 * @return string[]
	 */
	private static function resolve_override_overlap_attrs( string $block_name, array $override_attributes_map ): array {
		if ( $block_name === '' ) {
			return [];
		}

		$override_attributes = StringArray::sanitize( $override_attributes_map[ $block_name ] ?? [] );

		if ( [] === $override_attributes ) {
			return [];
		}

		$manifest            = ServerCollector::introspect_block_type( $block_name );
		$bindable_attributes = is_array( $manifest['bindableAttributes'] ?? null )
			? StringArray::sanitize( $manifest['bindableAttributes'] )
			: [];

		if ( [] === $bindable_attributes ) {
			return [];
		}

		return array_values( array_intersect( $bindable_attributes, $override_attributes ) );
	}

	/**
	 * @param string[] $nearby_siblings
	 * @return string[]
	 */
	private static function dedupe_nearby_sibling_block_names( array $nearby_siblings ): array {
		return array_slice(
			array_values( array_unique( StringArray::sanitize( $nearby_siblings ) ) ),
			0,
			self::MAX_NEARBY_SIBLING_BLOCK_TYPES
		);
	}

	/**
	 * @param string[] $nearby_block_overlap_attrs
	 */
	private static function build_override_summary(
		string $block_name,
		bool $matches_nearby_block,
		array $nearby_block_overlap_attrs,
		bool $matches_nearby_custom_block,
		bool $supports_custom_blocks,
		int $sibling_override_count,
		bool $uses_default_binding
	): string {
		$parts = [];

		if ( $matches_nearby_block && $block_name !== '' ) {
			$parts[] = sprintf(
				'Supports Pattern Overrides for %s (%s).',
				$block_name,
				implode( ', ', $nearby_block_overlap_attrs )
			);
		} elseif ( $matches_nearby_custom_block && $block_name !== '' ) {
			$parts[] = sprintf( 'Supports Pattern Overrides for %s.', $block_name );
		} elseif ( $supports_custom_blocks ) {
			$parts[] = 'Supports Pattern Overrides for custom block fields.';
		}

		if ( $sibling_override_count > 0 ) {
			$parts[] = sprintf(
				'Also supports %d nearby sibling block type%s.',
				$sibling_override_count,
				1 === $sibling_override_count ? '' : 's'
			);
		}

		if ( $uses_default_binding ) {
			$parts[] = 'Uses default binding expansion.';
		}

		return implode( ' ', $parts );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param string[]             $nearby_siblings
	 * @return array<string, mixed>
	 */
	private static function build_candidate_ranking_hint( array $payload, string $block_name, array $nearby_siblings, bool $is_custom_block_context ): array {
		$pattern_overrides           = self::normalize_pattern_overrides_metadata( $payload['patternOverrides'] ?? [] );
		$override_attributes_map     = self::sanitize_override_attribute_map( $pattern_overrides['overrideAttributes'] ?? [] );
		$override_block_names        = array_values(
			array_unique(
				array_merge(
					StringArray::sanitize( $pattern_overrides['blockNames'] ?? [] ),
					array_keys( $override_attributes_map )
				)
			)
		);
		$custom_override_block_names = array_values(
			array_filter(
				$override_block_names,
				static fn( string $candidate_block_name ): bool => self::is_custom_block_name( $candidate_block_name )
			)
		);
		$matches_nearby_custom_block = $is_custom_block_context
			&& in_array( $block_name, $custom_override_block_names, true );
		$supports_custom_blocks      = $is_custom_block_context
			&& [] !== $custom_override_block_names;
		$nearby_block_overlap_attrs  = self::resolve_override_overlap_attrs(
			$block_name,
			$override_attributes_map
		);
		$matches_nearby_block        = [] !== $nearby_block_overlap_attrs;
		$uses_default_binding        = ! empty( $pattern_overrides['usesDefaultBinding'] );
		$sibling_override_count      = 0;

		foreach ( self::dedupe_nearby_sibling_block_names( $nearby_siblings ) as $sibling_block_name ) {
			if ( [] !== self::resolve_override_overlap_attrs( $sibling_block_name, $override_attributes_map ) ) {
				++$sibling_override_count;
			}
		}

		$general_bonus = $matches_nearby_block ? self::GENERAL_BLOCK_OVERRIDE_BONUS : 0.0;
		$custom_bonus  = 0.0;

		if ( $matches_nearby_custom_block ) {
			$custom_bonus = self::CUSTOM_BLOCK_MATCHING_OVERRIDE_BONUS;
		} elseif ( $supports_custom_blocks ) {
			$custom_bonus = self::CUSTOM_BLOCK_GENERIC_OVERRIDE_BONUS;
		}

		$sibling_bonus = min(
			self::SIBLING_OVERRIDE_BONUS_CAP,
			$sibling_override_count * self::SIBLING_OVERRIDE_BONUS_PER_MATCH
		);
		$summary       = self::build_override_summary(
			$block_name,
			$matches_nearby_block,
			$nearby_block_overlap_attrs,
			$matches_nearby_custom_block,
			$supports_custom_blocks,
			$sibling_override_count,
			$uses_default_binding
		);

		return [
			'customBlockContext'       => $is_custom_block_context,
			'matchesNearbyBlock'       => $matches_nearby_block,
			'nearbyBlockOverlapAttrs'  => $nearby_block_overlap_attrs,
			'siblingOverrideCount'     => $sibling_override_count,
			'matchesNearbyCustomBlock' => $matches_nearby_custom_block,
			'supportsCustomBlocks'     => $supports_custom_blocks,
			'usesDefaultBinding'       => $uses_default_binding,
			'customOverrideBlockNames' => $custom_override_block_names,
			'summary'                  => $summary,
			'bonus'                    => min(
				self::TOTAL_OVERRIDE_BONUS_CAP,
				$general_bonus + $custom_bonus + $sibling_bonus
			),
		];
	}

	/**
	 * @param array<string, mixed> $ranking_hint
	 * @return array<string, mixed>
	 */
	private static function prepare_candidate_ranking_hint_for_llm( array $ranking_hint ): array {
		$summary                    = isset( $ranking_hint['summary'] ) && is_string( $ranking_hint['summary'] )
			? sanitize_text_field( $ranking_hint['summary'] )
			: '';
		$nearby_block_overlap_attrs = StringArray::sanitize( $ranking_hint['nearbyBlockOverlapAttrs'] ?? [] );
		$hint                       = [
			'matchesNearbyBlock'       => ! empty( $ranking_hint['matchesNearbyBlock'] ),
			'nearbyBlockOverlapAttrs'  => $nearby_block_overlap_attrs,
			'siblingOverrideCount'     => max( 0, (int) ( $ranking_hint['siblingOverrideCount'] ?? 0 ) ),
			'matchesNearbyCustomBlock' => ! empty( $ranking_hint['matchesNearbyCustomBlock'] ),
			'supportsCustomBlocks'     => ! empty( $ranking_hint['supportsCustomBlocks'] ),
			'usesDefaultBinding'       => ! empty( $ranking_hint['usesDefaultBinding'] ),
			'summary'                  => $summary,
		];

		if (
			$summary === ''
			&& ! $hint['matchesNearbyBlock']
			&& [] === $nearby_block_overlap_attrs
			&& 0 === $hint['siblingOverrideCount']
			&& ! $hint['matchesNearbyCustomBlock']
			&& ! $hint['supportsCustomBlocks']
			&& ! $hint['usesDefaultBinding']
		) {
			return [];
		}

		return $hint;
	}

	/**
	 * @param array<string, mixed> $ranking_hint
	 */
	private static function append_pattern_override_reason_hint( string $reason, array $ranking_hint ): string {
		$hint = isset( $ranking_hint['summary'] ) && is_string( $ranking_hint['summary'] )
			? sanitize_text_field( $ranking_hint['summary'] )
			: '';

		if ( $hint === '' ) {
			return $reason;
		}

		$normalized_reason = strtolower( $reason );
		if (
			str_contains( $normalized_reason, 'pattern override' )
			|| str_contains( $normalized_reason, 'custom block' )
			|| str_contains( $normalized_reason, 'binding' )
		) {
			return $reason;
		}

		if ( $reason === '' ) {
			return $hint;
		}

		return rtrim( $reason, ". \t\n\r\0\x0B" ) . '. ' . $hint;
	}

	/**
	 * @param array<string, mixed> $pattern_overrides
	 * @param array<string, mixed> $ranking_hint
	 * @return array<string, mixed>
	 */
	private static function build_override_capabilities( array $pattern_overrides, array $ranking_hint ): array {
		$pattern_overrides      = self::normalize_pattern_overrides_metadata( $pattern_overrides );
		$override_attributes    = is_array( $pattern_overrides['overrideAttributes'] ?? null )
			? $pattern_overrides['overrideAttributes']
			: [];
		$unsupported_attributes = is_array( $pattern_overrides['unsupportedAttributes'] ?? null )
			? $pattern_overrides['unsupportedAttributes']
			: [];

		return [
			'hasPatternOverrides'      => ! empty( $pattern_overrides['hasOverrides'] ),
			'overrideBlockCount'       => max( 0, (int) ( $pattern_overrides['blockCount'] ?? 0 ) ),
			'usesDefaultBinding'       => ! empty( $pattern_overrides['usesDefaultBinding'] ),
			'hasBindableOverrides'     => [] !== $override_attributes,
			'hasUnsupportedOverrides'  => [] !== $unsupported_attributes,
			'matchesNearbyBlock'       => ! empty( $ranking_hint['matchesNearbyBlock'] ),
			'nearbyBlockOverlapAttrs'  => StringArray::sanitize( $ranking_hint['nearbyBlockOverlapAttrs'] ?? [] ),
			'siblingOverrideCount'     => max( 0, (int) ( $ranking_hint['siblingOverrideCount'] ?? 0 ) ),
			'matchesNearbyCustomBlock' => ! empty( $ranking_hint['matchesNearbyCustomBlock'] ),
			'supportsCustomBlocks'     => ! empty( $ranking_hint['supportsCustomBlocks'] ),
		];
	}

	private static function build_embedding_query(
		string $post_type,
		string $block_name,
		string $template_type,
		string $prompt,
		string $root_block,
		array $ancestors,
		array $nearby_siblings,
		string $template_part_area,
		string $template_part_slug,
		string $container_layout,
		bool $has_insertion_context
	): string {
		$sections = [
			"[TASK]\nRecommend WordPress block patterns for the current editing context.",
			"[POST TYPE]\n{$post_type}",
		];

		if ( $block_name !== '' ) {
			$sections[] = "[BLOCK CONTEXT]\n{$block_name}";
		}

		if ( $template_type !== '' ) {
			$sections[] = "[TEMPLATE TYPE]\n{$template_type}";
		}

		if ( $has_insertion_context ) {
			$context_lines = [];

			if ( $root_block !== '' ) {
				$context_lines[] = "Container: {$root_block}";
			}

			if ( ! empty( $ancestors ) ) {
				$context_lines[] = 'Ancestors: ' . implode( ' > ', array_slice( $ancestors, 0, 6 ) );
			}

			if ( ! empty( $nearby_siblings ) ) {
				$context_lines[] = 'Nearby blocks: ' . implode( ', ', array_slice( $nearby_siblings, 0, 6 ) );
			}

			if ( $template_part_area !== '' ) {
				$context_lines[] = "Template-part area: {$template_part_area}";
			}

			if ( $template_part_slug !== '' ) {
				$context_lines[] = "Template-part slug: {$template_part_slug}";
			}

			if ( $container_layout !== '' ) {
				$context_lines[] = "Container layout: {$container_layout}";
			}

			if ( self::is_constrained_insertion_area( $root_block, $ancestors, $template_part_area ) ) {
				$context_lines[] = 'Area type: constrained';
			} elseif ( self::is_root_insertion_area( $root_block, $ancestors, $template_part_area ) ) {
				$context_lines[] = 'Area type: root-level';
			}

			if ( ! empty( $context_lines ) ) {
				$sections[] = "[INSERTION CONTEXT]\n" . implode( "\n", $context_lines );
			}
		}

		if ( $prompt !== '' ) {
			$sections[] = "[USER INSTRUCTION]\n{$prompt}";
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_structural_should_clauses(
		string $post_type,
		string $block_name,
		string $template_type,
		string $root_block,
		array $ancestors,
		array $nearby_siblings,
		string $template_part_area,
		bool $has_insertion_context
	): array {
		$clauses = [];

		if ( $template_type !== '' ) {
			$clauses[] = [
				'key'   => 'templateTypes',
				'match' => [ 'value' => $template_type ],
			];
		}

		if ( $block_name !== '' ) {
			$clauses[] = [
				'key'   => 'blockTypes',
				'match' => [ 'value' => $block_name ],
			];
		}

		if ( $root_block !== '' ) {
			$clauses[] = [
				'key'   => 'blockTypes',
				'match' => [ 'value' => $root_block ],
			];
		}

		if ( self::is_constrained_insertion_area( $root_block, $ancestors, $template_part_area ) ) {
			$clauses[] = [
				'key'   => 'traits',
				'match' => [ 'value' => 'simple' ],
			];
		}

		if ( in_array( $template_part_area, [ 'header', 'footer' ], true ) ) {
			$clauses[] = [
				'key'   => 'traits',
				'match' => [ 'value' => 'site-chrome' ],
			];
		}

		if (
			$has_insertion_context
			&& $template_type === ''
			&& self::is_root_insertion_area( $root_block, $ancestors, $template_part_area )
		) {
			foreach ( self::derive_template_type_hints_from_post_type( $post_type ) as $template_hint ) {
				$clauses[] = [
					'key'   => 'templateTypes',
					'match' => [ 'value' => $template_hint ],
				];
			}

			if ( empty( $nearby_siblings ) ) {
				$clauses[] = [
					'key'   => 'traits',
					'match' => [ 'value' => 'moderate-complexity' ],
				];
			}
		}

		$deduped = [];
		$seen    = [];

		foreach ( $clauses as $clause ) {
			$key   = is_string( $clause['key'] ?? null ) ? $clause['key'] : '';
			$value = is_string( $clause['match']['value'] ?? null ) ? $clause['match']['value'] : '';

			if ( $key === '' || $value === '' ) {
				continue;
			}

			$signature = $key . ':' . $value;

			if ( isset( $seen[ $signature ] ) ) {
				continue;
			}

			$seen[ $signature ] = true;
			$deduped[]          = $clause;
		}

		return $deduped;
	}

	/**
	 * @return string[]
	 */
	private static function derive_template_type_hints_from_post_type( string $post_type ): array {
		$post_type = sanitize_key( $post_type );

		if ( $post_type === 'page' ) {
			return [ 'page' ];
		}

		if ( $post_type === '' || self::is_internal_root_pattern_context_post_type( $post_type ) ) {
			return [];
		}

		return [ 'single', 'singular' ];
	}

	private static function is_internal_root_pattern_context_post_type( string $post_type ): bool {
		return str_starts_with( $post_type, 'wp_' )
			|| in_array( $post_type, [ 'global_styles' ], true );
	}

	/**
	 * @param array<string, array{payload: array<string, mixed>, rankingHint?: array<string, mixed>}> $candidates
	 */
	private static function build_ranking_input(
		string $post_type,
		string $block_name,
		string $template_type,
		string $prompt,
		string $root_block,
		array $ancestors,
		array $nearby_siblings,
		string $template_part_area,
		string $template_part_slug,
		string $container_layout,
		string $custom_block_name,
		bool $has_insertion_context,
		bool $is_custom_block_context,
		array $docs_guidance,
		array $visible_pattern_names,
		int $retrieved_candidate_count,
		array $candidates
	): string {
		$base_input = "## Editing Context\n"
			. "Post type: {$post_type}\n"
			. ( $block_name !== '' ? "Block context: {$block_name}\n" : '' )
			. ( $template_type !== '' ? "Template type: {$template_type}\n" : '' )
			. ( $prompt !== '' ? "User instruction: {$prompt}\n" : '' )
			. self::build_insertion_context_section(
				$root_block,
				$ancestors,
				$nearby_siblings,
				$template_part_area,
				$template_part_slug,
				$container_layout,
				$has_insertion_context
			)
			. self::build_custom_block_context_section( $custom_block_name, $is_custom_block_context )
			. self::build_design_summary_section()
			. self::build_wordpress_docs_guidance_section( $docs_guidance );

		$candidate_entries = self::build_candidates_for_llm( $candidates );
		$total_candidates  = count( $candidate_entries );
		$shown_candidates  = $total_candidates;

		for ( $i = 0; $i < 2; $i++ ) {
			$meta             = self::build_candidate_budget_section( $shown_candidates, $total_candidates )
				. self::build_visible_pattern_scope_section(
					$visible_pattern_names,
					$shown_candidates,
					$total_candidates,
					$retrieved_candidate_count
				);
			$available        = self::MAX_LLM_INPUT_CHARS - strlen( $base_input . $meta . "\n## Candidate Patterns\n" );
			$selected         = self::select_candidates_for_budget( $candidate_entries, $available );
			$shown_candidates = count( $selected );
		}

		$meta            = self::build_candidate_budget_section( $shown_candidates, $total_candidates )
			. self::build_visible_pattern_scope_section(
				$visible_pattern_names,
				$shown_candidates,
				$total_candidates,
				$retrieved_candidate_count
			);
		$selected        = self::select_candidates_for_budget(
			$candidate_entries,
			self::MAX_LLM_INPUT_CHARS - strlen( $base_input . $meta . "\n## Candidate Patterns\n" )
		);
		$candidates_json = wp_json_encode( $selected );

		return $base_input
			. $meta
			. "\n## Candidate Patterns\n"
			. ( false !== $candidates_json ? $candidates_json : '[]' );
	}

	/**
	 * @param array<string, array{payload: array<string, mixed>, rankingHint?: array<string, mixed>}> $candidates
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_candidates_for_llm( array $candidates ): array {
		$entries = [];

		foreach ( $candidates as $name => $candidate ) {
			$payload           = is_array( $candidate['payload'] ?? null ) ? $candidate['payload'] : [];
			$ranking_hint      = is_array( $candidate['rankingHint'] ?? null ) ? $candidate['rankingHint'] : [];
			$pattern_overrides = self::normalize_pattern_overrides_metadata( $payload['patternOverrides'] ?? [] );
			$content           = is_string( $payload['content'] ?? null ) ? $payload['content'] : '';
			$traits            = is_array( $payload['traits'] ?? null ) ? StringArray::sanitize( $payload['traits'] ) : [];
			$structure         = self::build_pattern_structure_summary( $content );
			$content_preview   = self::build_pattern_content_preview( $content );

			$entry = [
				'name'                 => $name,
				'title'                => $payload['title'] ?? '',
				'description'          => $payload['description'] ?? '',
				'categories'           => $payload['categories'] ?? [],
				'blockTypes'           => $payload['blockTypes'] ?? [],
				'templateTypes'        => $payload['templateTypes'] ?? [],
				'traits'               => $traits,
				'patternOverrides'     => $pattern_overrides,
				'overrideCapabilities' => self::build_override_capabilities(
					$pattern_overrides,
					$ranking_hint
				),
				'rankingHints'         => self::prepare_candidate_ranking_hint_for_llm( $ranking_hint ),
				'contentBlockCount'    => self::count_pattern_blocks( $content ),
			];

			if ( $structure !== '' ) {
				$entry['structureSummary'] = $structure;
			}

			if ( $content_preview !== '' ) {
				$entry['contentPreview'] = $content_preview;
			}

			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * @param array<int, array<string, mixed>> $candidate_entries
	 * @return array<int, array<string, mixed>>
	 */
	private static function select_candidates_for_budget( array $candidate_entries, int $available_chars ): array {
		if ( $available_chars <= 0 ) {
			return [];
		}

		$selected = [];

		foreach ( $candidate_entries as $entry ) {
			$trial = array_merge( $selected, [ $entry ] );
			$json  = wp_json_encode( $trial );

			if ( false === $json ) {
				continue;
			}

			if ( strlen( $json ) <= $available_chars ) {
				$selected[] = $entry;
				continue;
			}

			if ( [] === $selected ) {
				$selected[] = self::shrink_candidate_entry_for_budget( $entry );
			}

			break;
		}

		return $selected;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function shrink_candidate_entry_for_budget( array $entry ): array {
		if ( isset( $entry['structureSummary'] ) && is_string( $entry['structureSummary'] ) ) {
			$entry['structureSummary'] = self::truncate_text( $entry['structureSummary'], 320 );
		}

		if ( isset( $entry['contentPreview'] ) && is_string( $entry['contentPreview'] ) ) {
			$entry['contentPreview'] = self::truncate_text( $entry['contentPreview'], 120 );
		}

		return $entry;
	}

	private static function build_candidate_budget_section( int $shown_candidates, int $total_candidates ): string {
		if ( $shown_candidates >= $total_candidates ) {
			return '';
		}

		return "\n## Candidate Budget\n"
			. "Candidates shown to rank: {$shown_candidates} of {$total_candidates}\n";
	}

	private static function build_visible_pattern_scope_section(
		array $visible_pattern_names,
		int $shown_candidates,
		int $total_candidates,
		int $retrieved_candidate_count
	): string {
		return "\n## Visible Pattern Scope\n"
			. 'Allowed patterns at this insertion point: ' . count( array_unique( StringArray::sanitize( $visible_pattern_names ) ) ) . "\n"
			. "Unique retrieved candidates before visibility filtering: {$retrieved_candidate_count}\n"
			. "Candidates shown to rank: {$shown_candidates} of {$total_candidates}\n"
			. "Only allowed patterns appear in the candidate list below.\n";
	}

	private static function build_pattern_structure_summary( string $content ): string {
		if ( $content === '' ) {
			return '';
		}

		$blocks = parse_blocks( $content );

		if ( [] === $blocks ) {
			return '';
		}

		$lines       = [];
		$seen_blocks = 0;
		self::collect_pattern_structure_lines( $blocks, 0, $lines, $seen_blocks );

		if ( [] === $lines ) {
			return '';
		}

		$total_blocks = self::count_pattern_blocks( $content );

		if ( $total_blocks > $seen_blocks ) {
			$lines[] = sprintf(
				'... %d more block%s',
				$total_blocks - $seen_blocks,
				1 === ( $total_blocks - $seen_blocks ) ? '' : 's'
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param string[]                         $lines
	 */
	private static function collect_pattern_structure_lines( array $blocks, int $depth, array &$lines, int &$seen_blocks ): void {
		foreach ( $blocks as $block ) {
			if (
				$seen_blocks >= self::MAX_LLM_STRUCTURE_BLOCKS
				|| count( $lines ) >= self::MAX_LLM_STRUCTURE_LINES
			) {
				return;
			}

			$block_name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : 'unknown';
			$lines[]    = str_repeat( '  ', min( 4, $depth ) ) . '- ' . $block_name;
			++$seen_blocks;

			if ( is_array( $block['innerBlocks'] ?? null ) && ! empty( $block['innerBlocks'] ) ) {
				self::collect_pattern_structure_lines( $block['innerBlocks'], $depth + 1, $lines, $seen_blocks );
			}
		}
	}

	private static function build_pattern_content_preview( string $content ): string {
		$preview = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );

		if ( $preview === '' ) {
			return '';
		}

		return self::truncate_text( $preview, self::MAX_LLM_CONTENT_PREVIEW_CHARS );
	}

	private static function count_pattern_blocks( string $content ): int {
		return max( 0, substr_count( $content, '<!-- wp:' ) );
	}

	private static function truncate_text( string $text, int $limit ): string {
		if ( $limit <= 0 || strlen( $text ) <= $limit ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $limit - 3 ) ) . '...';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_pattern_overrides_metadata( mixed $pattern_overrides ): array {
		if ( ! is_array( $pattern_overrides ) ) {
			$pattern_overrides = [];
		}

		return [
			'hasOverrides'          => ! empty( $pattern_overrides['hasOverrides'] ),
			'blockCount'            => max( 0, (int) ( $pattern_overrides['blockCount'] ?? 0 ) ),
			'blockNames'            => StringArray::sanitize( $pattern_overrides['blockNames'] ?? [] ),
			'bindableAttributes'    => self::sanitize_override_attribute_map( $pattern_overrides['bindableAttributes'] ?? [] ),
			'overrideAttributes'    => self::sanitize_override_attribute_map( $pattern_overrides['overrideAttributes'] ?? [] ),
			'usesDefaultBinding'    => ! empty( $pattern_overrides['usesDefaultBinding'] ),
			'unsupportedAttributes' => self::sanitize_override_attribute_map( $pattern_overrides['unsupportedAttributes'] ?? [] ),
		];
	}

	private static function recommendation_score_threshold(): float {
		$value = (float) get_option(
			'flavor_agent_pattern_recommendation_threshold',
			self::DEFAULT_RECOMMENDATION_SCORE_THRESHOLD
		);

		return max( 0.0, min( 1.0, $value ) );
	}

	private static function max_recommendations(): int {
		$value = (int) get_option(
			'flavor_agent_pattern_max_recommendations',
			self::DEFAULT_MAX_RECOMMENDATIONS
		);

		return max( 1, min( self::MAX_LLM_CANDIDATES, $value ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
		return CollectsDocsGuidance::collect(
			static fn( array $request_context, string $request_prompt ): string => self::build_wordpress_docs_query( $request_context, $request_prompt ),
			static fn( array $request_context ): string => self::build_wordpress_docs_entity_key( $request_context ),
			static fn( array $request_context, string $request_prompt, string $entity_key ): array => self::build_wordpress_docs_family_context( $request_context, $entity_key ),
			$context,
			$prompt,
			[
				'allowForegroundWarm' => false,
			]
		);
	}

	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$post_type             = isset( $context['postType'] ) && is_string( $context['postType'] )
			? sanitize_key( $context['postType'] )
			: 'post';
		$template_type         = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';
		$block_context         = self::normalize_input( $context['blockContext'] ?? [] );
		$insertion_context     = self::normalize_input( $context['insertionContext'] ?? [] );
		$block_name            = isset( $block_context['blockName'] ) && is_string( $block_context['blockName'] )
			? sanitize_text_field( $block_context['blockName'] )
			: '';
		$root_block            = isset( $insertion_context['rootBlock'] ) && is_string( $insertion_context['rootBlock'] )
			? sanitize_text_field( $insertion_context['rootBlock'] )
			: '';
		$ancestors             = array_slice( StringArray::sanitize( $insertion_context['ancestors'] ?? [] ), 0, 4 );
		$nearby_siblings       = array_slice( StringArray::sanitize( $insertion_context['nearbySiblings'] ?? [] ), 0, 4 );
		$template_part_area    = isset( $insertion_context['templatePartArea'] ) && is_string( $insertion_context['templatePartArea'] )
			? sanitize_key( $insertion_context['templatePartArea'] )
			: '';
		$template_part_slug    = isset( $insertion_context['templatePartSlug'] ) && is_string( $insertion_context['templatePartSlug'] )
			? sanitize_text_field( $insertion_context['templatePartSlug'] )
			: '';
		$container_layout      = isset( $insertion_context['containerLayout'] ) && is_string( $insertion_context['containerLayout'] )
			? sanitize_key( $insertion_context['containerLayout'] )
			: '';
		$visible_pattern_names = array_slice( StringArray::sanitize( $context['visiblePatternNames'] ?? [] ), 0, 6 );
		$parts                 = [ 'WordPress block patterns, inserter, and editor composition best practices' ];

		if ( $post_type !== '' ) {
			$parts[] = "post type {$post_type}";
		}

		if ( $template_type !== '' ) {
			$parts[] = "template type {$template_type}";
		}

		if ( $block_name !== '' ) {
			$parts[] = "near block type {$block_name}";
		}

		if ( $root_block !== '' ) {
			$parts[] = "insertion root {$root_block}";
		}

		if ( ! empty( $ancestors ) ) {
			$parts[] = 'ancestor chain ' . implode( ' > ', $ancestors );
		}

		if ( ! empty( $nearby_siblings ) ) {
			$parts[] = 'nearby sibling blocks ' . implode( ', ', $nearby_siblings );
		}

		if ( $template_part_area !== '' ) {
			$parts[] = "template part area {$template_part_area}";
		}

		if ( $template_part_slug !== '' ) {
			$parts[] = "template part slug {$template_part_slug}";
		}

		if ( $container_layout !== '' ) {
			$parts[] = "container layout {$container_layout}";
		}

		if ( ! empty( $visible_pattern_names ) ) {
			$parts[] = 'visible pattern scope ' . implode( ', ', $visible_pattern_names );
		}

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'block patterns, inserter context, theme.json, and site editor guidance';

		return implode(
			'. ',
			array_values(
				array_filter(
					$parts,
					static fn( string $part ): bool => $part !== ''
				)
			)
		);
	}

	private static function build_wordpress_docs_entity_key( array $context ): string {
		$template_type = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';
		$block_context = self::normalize_input( $context['blockContext'] ?? [] );
		$block_name    = isset( $block_context['blockName'] ) && is_string( $block_context['blockName'] )
			? sanitize_text_field( $block_context['blockName'] )
			: '';

		if ( $block_name !== '' ) {
			$entity_key = AISearchClient::resolve_entity_key( $block_name );

			if ( $entity_key !== '' ) {
				return $entity_key;
			}

			if ( str_starts_with( $block_name, 'core/template-part/' ) ) {
				return 'core/template-part';
			}
		}

		if ( $template_type !== '' ) {
			return AISearchClient::resolve_entity_key( 'template:' . $template_type );
		}

		return AISearchClient::resolve_entity_key( 'guidance:block-editor' );
	}

	/**
	 * @param string $entity_key Normalized AI Search entity key.
	 * @return array<string, mixed>
	 */
	private static function build_wordpress_docs_family_context( array $context, string $entity_key ): array {
		$post_type             = isset( $context['postType'] ) && is_string( $context['postType'] )
			? sanitize_key( $context['postType'] )
			: 'post';
		$template_type         = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';
		$block_context         = self::normalize_input( $context['blockContext'] ?? [] );
		$insertion_context     = self::normalize_input( $context['insertionContext'] ?? [] );
		$block_name            = isset( $block_context['blockName'] ) && is_string( $block_context['blockName'] )
			? sanitize_text_field( $block_context['blockName'] )
			: '';
		$root_block            = isset( $insertion_context['rootBlock'] ) && is_string( $insertion_context['rootBlock'] )
			? sanitize_text_field( $insertion_context['rootBlock'] )
			: '';
		$template_part_area    = isset( $insertion_context['templatePartArea'] ) && is_string( $insertion_context['templatePartArea'] )
			? sanitize_key( $insertion_context['templatePartArea'] )
			: '';
		$container_layout      = isset( $insertion_context['containerLayout'] ) && is_string( $insertion_context['containerLayout'] )
			? sanitize_key( $insertion_context['containerLayout'] )
			: '';
		$ancestors             = array_slice( StringArray::sanitize( $insertion_context['ancestors'] ?? [] ), 0, 4 );
		$nearby_siblings       = array_slice( StringArray::sanitize( $insertion_context['nearbySiblings'] ?? [] ), 0, 4 );
		$visible_pattern_names = array_slice( StringArray::sanitize( $context['visiblePatternNames'] ?? [] ), 0, 6 );
		$surface               = 'block';

		if ( $entity_key === 'core/template-part' || str_starts_with( $block_name, 'core/template-part/' ) ) {
			$surface = 'template-part';
		} elseif ( str_starts_with( $entity_key, 'template:' ) || ( $template_type !== '' && $block_name === '' ) ) {
			$surface = 'template';
		}

		$family_context = [
			'surface'   => $surface,
			'entityKey' => $entity_key,
			'postType'  => $post_type,
		];

		if ( $template_type !== '' ) {
			$family_context['templateType'] = $template_type;
		}

		if ( $block_name !== '' ) {
			$family_context['nearBlock'] = $block_name;
		}

		if ( $root_block !== '' ) {
			$family_context['insertionRoot'] = $root_block;
		}

		if ( $template_part_area !== '' ) {
			$family_context['templatePartArea'] = $template_part_area;
		}

		if ( $container_layout !== '' ) {
			$family_context['containerLayout'] = $container_layout;
		}

		if ( ! empty( $ancestors ) ) {
			$family_context['ancestorBlocks'] = $ancestors;
		}

		if ( ! empty( $nearby_siblings ) ) {
			$family_context['nearbyBlocks'] = $nearby_siblings;
		}

		if ( ! empty( $visible_pattern_names ) ) {
			$family_context['visiblePatterns'] = $visible_pattern_names;
		}

		return $family_context;
	}

	/**
	 * @param array<int, array<string, mixed>> $docs_guidance
	 */
	private static function build_wordpress_docs_guidance_section( array $docs_guidance ): string {
		if ( empty( $docs_guidance ) ) {
			return '';
		}

		$lines = [];

		foreach ( array_slice( $docs_guidance, 0, 3 ) as $guidance ) {
			if ( ! is_array( $guidance ) ) {
				continue;
			}

			$summary = self::format_guidance_line( $guidance );

			if ( $summary !== '' ) {
				$lines[] = '- ' . $summary;
			}
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "\n## WordPress Developer Guidance\n" . implode( "\n", $lines ) . "\n";
	}
}
