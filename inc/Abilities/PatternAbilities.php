<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\FormatsDocsGuidance;
use FlavorAgent\Support\NormalizesInput;
use FlavorAgent\Support\StringArray;

final class PatternAbilities {

	use FormatsDocsGuidance;
	use NormalizesInput;

	private const DEFAULT_SEMANTIC_LIMIT    = 8;
	private const DEFAULT_STRUCTURAL_LIMIT  = 6;
	private const FILTERED_SEMANTIC_LIMIT   = 24;
	private const FILTERED_STRUCTURAL_LIMIT = 18;
	private const MAX_LLM_CANDIDATES        = 12;
	private const MAX_RECOMMENDATIONS       = 8;

	public static function list_patterns( mixed $input ): array {
		$input          = self::normalize_input( $input );
		$categories     = $input['categories'] ?? null;
		$block_types    = $input['blockTypes'] ?? null;
		$template_types = $input['templateTypes'] ?? null;

		return [
			'patterns' => ServerCollector::for_patterns( $categories, $block_types, $template_types ),
		];
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

		if ( is_array( $visible_pattern_names ) && empty( $visible_pattern_names ) ) {
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
				if ( ! $has_usable_index ) {
					if ( current_user_can( 'manage_options' ) ) {
						PatternIndex::schedule_sync();
					}
					return new \WP_Error(
						'index_warming',
						'Pattern catalog is building. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
						[ 'status' => 503 ]
					);
				}
				if ( current_user_can( 'manage_options' ) ) {
					PatternIndex::schedule_sync();
				}
				break;

			case 'uninitialized':
				if ( current_user_can( 'manage_options' ) ) {
					PatternIndex::schedule_sync();
				}
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
					return new \WP_Error(
						'index_unavailable',
						'Pattern catalog sync failed. Review the last sync error in Settings > Flavor Agent.',
						[ 'status' => 503 ]
					);
				}
				if ( current_user_can( 'manage_options' ) ) {
					PatternIndex::schedule_sync();
				}
				break;
		}

		// Step 2: Build query string.
		$post_type        = isset( $input['postType'] ) && is_string( $input['postType'] ) && sanitize_key( $input['postType'] ) !== ''
			? sanitize_key( $input['postType'] )
			: 'post';
		$block_context    = self::normalize_input( $input['blockContext'] ?? [] );
		$block_name       = isset( $block_context['blockName'] ) && is_string( $block_context['blockName'] )
			? sanitize_text_field( $block_context['blockName'] )
			: '';
		$template_type    = isset( $input['templateType'] ) && is_string( $input['templateType'] )
			? sanitize_key( $input['templateType'] )
			: '';
		$prompt           = isset( $input['prompt'] ) && is_string( $input['prompt'] )
			? sanitize_textarea_field( $input['prompt'] )
			: '';
		$visible_lookup   = is_array( $visible_pattern_names )
			? array_fill_keys( $visible_pattern_names, true )
			: null;
		$semantic_limit   = $visible_lookup ? self::FILTERED_SEMANTIC_LIMIT : self::DEFAULT_SEMANTIC_LIMIT;
		$structural_limit = $visible_lookup ? self::FILTERED_STRUCTURAL_LIMIT : self::DEFAULT_STRUCTURAL_LIMIT;
		$docs_guidance    = self::collect_wordpress_docs_guidance(
			[
				'postType'     => $post_type,
				'templateType' => $template_type,
				'blockContext' => $block_context,
			],
			$prompt
		);

		$query = "Recommend patterns for a {$post_type} post";
		if ( $block_name !== '' ) {
			$query .= " near a {$block_name} block";
		}
		if ( $template_type ) {
			$query .= " in a {$template_type} template";
		}
		if ( $prompt ) {
			$query .= ". {$prompt}";
		}

		// Step 3: Embed query.
		$query_vector = EmbeddingClient::embed( $query );
		if ( is_wp_error( $query_vector ) ) {
			return $query_vector;
		}

		// Step 4: Two-pass Qdrant retrieval.
		$pass_a = QdrantClient::search( $query_vector, $semantic_limit );
		if ( is_wp_error( $pass_a ) ) {
			return $pass_a;
		}

		$pass_b         = [];
		$should_clauses = [];

		if ( $template_type ) {
			$should_clauses[] = [
				'key'   => 'templateTypes',
				'match' => [ 'value' => $template_type ],
			];
		}
		if ( $block_name !== '' ) {
			$should_clauses[] = [
				'key'   => 'blockTypes',
				'match' => [ 'value' => $block_name ],
			];
		}

		if ( ! empty( $should_clauses ) ) {
			$pass_b = QdrantClient::search(
				$query_vector,
				$structural_limit,
				[ 'should' => $should_clauses ]
			);
			if ( is_wp_error( $pass_b ) ) {
				return $pass_b;
			}
		}

		// Union, dedupe by payload.name, keep best score.
		$candidates = [];
		foreach ( array_merge( $pass_a, $pass_b ) as $point ) {
			$name  = $point['payload']['name'] ?? '';
			$score = $point['score'] ?? 0.0;
			if ( $name === '' ) {
				continue;
			}
			if ( $visible_lookup && ! isset( $visible_lookup[ $name ] ) ) {
				continue;
			}
			if ( ! isset( $candidates[ $name ] ) || $candidates[ $name ]['score'] < $score ) {
				$candidates[ $name ] = [
					'score'   => $score,
					'payload' => $point['payload'],
				];
			}
		}

		// Sort by score desc, take top 12.
		uasort( $candidates, fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$candidates = array_slice( $candidates, 0, self::MAX_LLM_CANDIDATES, true );

		if ( empty( $candidates ) ) {
			return [ 'recommendations' => [] ];
		}

		// Step 5: Rank via Responses API.
		$candidates_for_llm = [];
		foreach ( $candidates as $name => $c ) {
			$candidates_for_llm[] = [
				'name'          => $name,
				'title'         => $c['payload']['title'] ?? '',
				'description'   => $c['payload']['description'] ?? '',
				'categories'    => $c['payload']['categories'] ?? [],
				'blockTypes'    => $c['payload']['blockTypes'] ?? [],
				'templateTypes' => $c['payload']['templateTypes'] ?? [],
				'content'       => substr( $c['payload']['content'] ?? '', 0, 500 ),
			];
		}

		$llm_input = "## Editing Context\n"
			. "Post type: {$post_type}\n"
			. ( $block_name !== '' ? "Block context: {$block_name}\n" : '' )
			. ( $template_type ? "Template type: {$template_type}\n" : '' )
			. ( $prompt ? "User instruction: {$prompt}\n" : '' )
			. self::build_wordpress_docs_guidance_section( $docs_guidance )
			. "\n## Candidate Patterns\n"
			. wp_json_encode( $candidates_for_llm, JSON_PRETTY_PRINT );

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

		$recommendations = [];
		foreach ( array_slice( (array) $data['recommendations'], 0, self::MAX_RECOMMENDATIONS ) as $rec ) {
			$name = $rec['name'] ?? '';
			if ( ! isset( $candidates[ $name ] ) ) {
				continue;
			}

			$payload = $candidates[ $name ]['payload'];
			$score   = isset( $rec['score'] ) ? max( 0.0, min( 1.0, (float) $rec['score'] ) ) : 0.0;
			if ( $score < 0.3 ) {
				continue;
			}
			$recommendations[] = [
				'name'       => $name,
				'title'      => $payload['title'] ?? '',
				'score'      => $score,
				'reason'     => sanitize_text_field( $rec['reason'] ?? '' ),
				'categories' => $payload['categories'] ?? [],
				'content'    => $payload['content'] ?? '',
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
				'Pattern recommendations need a compatible embedding backend and Qdrant in Settings > Flavor Agent, plus a usable chat provider from Settings > Flavor Agent or Settings > Connectors.',
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	private static function ranking_system_prompt(): string {
		return <<<'SYSTEM'
You are a WordPress pattern recommendation engine.

You receive a list of candidate block patterns and an editing context
(post type, nearby block, template type, user instruction, optional WordPress Developer Guidance).

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
- Omit patterns scoring below 0.3.
- Order by score descending.
- Consider: post type conventions, block proximity, template structure,
  category relevance, and the user's stated intent.
- When WordPress Developer Guidance is provided, prefer recommendations that
  align with documented pattern, block, template, and theme.json guidance.
- Return only name, score, and reason per pattern. Title, categories,
  and content are attached from the source data.
- Return at most 8 recommendations.
SYSTEM;
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
			$prompt
		);
	}

	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$post_type     = isset( $context['postType'] ) && is_string( $context['postType'] )
			? sanitize_key( $context['postType'] )
			: 'post';
		$template_type = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';
		$block_context = self::normalize_input( $context['blockContext'] ?? [] );
		$block_name    = isset( $block_context['blockName'] ) && is_string( $block_context['blockName'] )
			? sanitize_text_field( $block_context['blockName'] )
			: '';
		$parts         = [ 'WordPress block patterns, inserter, and editor composition best practices' ];

		if ( $post_type !== '' ) {
			$parts[] = "post type {$post_type}";
		}

		if ( $template_type !== '' ) {
			$parts[] = "template type {$template_type}";
		}

		if ( $block_name !== '' ) {
			$parts[] = "near block type {$block_name}";
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
		$post_type     = isset( $context['postType'] ) && is_string( $context['postType'] )
			? sanitize_key( $context['postType'] )
			: 'post';
		$template_type = isset( $context['templateType'] ) && is_string( $context['templateType'] )
			? sanitize_key( $context['templateType'] )
			: '';
		$block_context = self::normalize_input( $context['blockContext'] ?? [] );
		$block_name    = isset( $block_context['blockName'] ) && is_string( $block_context['blockName'] )
			? sanitize_text_field( $block_context['blockName'] )
			: '';
		$surface       = 'block';

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
