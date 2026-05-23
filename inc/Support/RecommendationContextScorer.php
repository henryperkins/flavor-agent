<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RecommendationContextScorer {

	public const VERSION = 'contextual-ranking-v1';

	public const EVIDENCE_KEYS = [
		'prompt_match',
		'operation_fit',
		'supports_fit',
		'section_role_match',
		'docs_freshness',
		'pattern_readiness',
		'visible_scope_match',
		'native_preset_fit',
		'accessibility_fit',
		'design_semantics_fit',
	];

	public const PENALTY_KEYS = [
		'possible_no_op',
		'weak_prompt_match',
		'unsupported_control',
		'stale_docs',
		'validation_risk',
	];

	private const EVIDENCE_WEIGHTS = [
		'prompt_match'         => 0.18,
		'operation_fit'        => 0.18,
		'supports_fit'         => 0.16,
		'section_role_match'   => 0.12,
		'docs_freshness'       => 0.12,
		'pattern_readiness'    => 0.08,
		'visible_scope_match'  => 0.06,
		'native_preset_fit'    => 0.06,
		'accessibility_fit'    => 0.02,
		'design_semantics_fit' => 0.02,
	];

	private const PENALTY_VALUES = [
		'weak_prompt_match'   => 0.12,
		'possible_no_op'      => 0.25,
		'unsupported_control' => 0.20,
		'stale_docs'          => 0.15,
		'validation_risk'     => 0.15,
	];

	/**
	 * @param array<string, mixed> $input
	 * @return array{score: float, evidence: array<string, float>, penalties: array<string, float>}
	 */
	public static function score( array $input ): array {
		$suggestion         = self::map( $input['suggestion'] ?? [] );
		$context            = self::map( $input['context'] ?? [] );
		$execution_contract = self::map( $input['executionContract'] ?? [] );
		$docs_grounding     = self::map( $input['docsGrounding'] ?? [] );
		$prompt             = self::text( $input['prompt'] ?? '' );

		$evidence  = array_fill_keys( self::EVIDENCE_KEYS, 0.55 );
		$penalties = [];

		$evidence['prompt_match'] = self::score_prompt_match( $prompt, $suggestion );
		if ( '' !== $prompt && $evidence['prompt_match'] < 0.35 ) {
			$penalties['weak_prompt_match'] = self::PENALTY_VALUES['weak_prompt_match'];
		}

		$evidence['operation_fit'] = self::score_operation_fit( $suggestion );

		$support                  = self::score_support_fit( $suggestion, $execution_contract, $context );
		$evidence['supports_fit'] = $support['score'];
		if ( $support['unsupported'] ) {
			$penalties['unsupported_control'] = self::PENALTY_VALUES['unsupported_control'];
		}

		$evidence['section_role_match'] = self::score_section_role_match( $suggestion, $context );

		$docs                       = self::score_docs_freshness( $docs_grounding );
		$evidence['docs_freshness'] = $docs['score'];
		if ( $docs['stale'] ) {
			$penalties['stale_docs'] = self::PENALTY_VALUES['stale_docs'];
		}

		$pattern                         = self::score_pattern_readiness( $suggestion, $context );
		$evidence['pattern_readiness']   = $pattern['score'];
		$evidence['visible_scope_match'] = $pattern['visibleScore'];
		if ( $pattern['unsupported'] ) {
			$penalties['unsupported_control'] = self::PENALTY_VALUES['unsupported_control'];
		}

		$evidence['native_preset_fit']    = self::score_native_preset_fit( $suggestion );
		$evidence['accessibility_fit']    = self::score_accessibility_fit( $suggestion, $context );
		$evidence['design_semantics_fit'] = self::score_design_semantics_fit( $suggestion, $context );

		if ( self::is_possible_no_op( $suggestion, $context ) ) {
			$penalties['possible_no_op'] = self::PENALTY_VALUES['possible_no_op'];
		}

		if ( self::has_validation_risk( $suggestion ) ) {
			$penalties['validation_risk'] = self::PENALTY_VALUES['validation_risk'];
		}

		$weighted = 0.0;
		foreach ( self::EVIDENCE_WEIGHTS as $key => $weight ) {
			$weighted += ( $evidence[ $key ] ?? 0.55 ) * $weight;
		}

		$score = max( 0.0, min( 1.0, round( $weighted - min( 0.35, array_sum( $penalties ) ), 4 ) ) );

		return [
			'score'     => $score,
			'evidence'  => $evidence,
			'penalties' => $penalties,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function map( mixed $value ): array {
		return is_array( $value ) ? $value : [];
	}

	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	private static function score_prompt_match( string $prompt, array $suggestion ): float {
		if ( '' === $prompt ) {
			return 0.55;
		}

		$prompt_tokens     = self::tokens( $prompt );
		$suggestion_tokens = self::tokens( implode( ' ', self::text_values( $suggestion, 40 ) ) );

		if ( [] === $prompt_tokens || [] === $suggestion_tokens ) {
			return 0.20;
		}

		$overlap = array_intersect_key( $prompt_tokens, $suggestion_tokens );
		if ( [] === $overlap ) {
			return 0.20;
		}

		$ratio = count( $overlap ) / max( 1, count( $prompt_tokens ) );
		return max( 0.35, min( 0.95, round( 0.45 + $ratio, 4 ) ) );
	}

	private static function score_operation_fit( array $suggestion ): float {
		$operations = self::list_value( $suggestion['operations'] ?? [] );
		$proposed   = self::list_value( $suggestion['proposedOperations'] ?? [] );
		$updates    = self::map( $suggestion['attributeUpdates'] ?? [] );

		if ( self::has_validation_risk( $suggestion ) ) {
			return 0.35;
		}

		if ( [] !== $operations || [] !== $proposed || [] !== $updates ) {
			return 0.75;
		}

		return 0.55;
	}

	/**
	 * @return array{score: float, unsupported: bool}
	 */
	private static function score_support_fit( array $suggestion, array $execution_contract, array $context ): array {
		$paths = self::normalize_path_inventory( $execution_contract['styleSupportPaths'] ?? [] );
		if ( [] === $paths ) {
			$paths = self::normalize_style_context_path_inventory( $context['styleContext']['supportedStylePaths'] ?? [] );
		}
		$concrete_paths = self::extract_concrete_style_paths( $suggestion );

		if ( [] !== $paths && [] !== $concrete_paths ) {
			foreach ( $concrete_paths as $path ) {
				if ( ! isset( $paths[ $path ] ) ) {
					return [
						'score'       => 0.45,
						'unsupported' => true,
					];
				}
			}

			return [
				'score'       => 0.85,
				'unsupported' => false,
			];
		}

		$panel          = sanitize_key( (string) ( $suggestion['panel'] ?? '' ) );
		$allowed_panels = [];
		foreach ( self::list_value( $execution_contract['allowedPanels'] ?? [] ) as $allowed_panel ) {
			$allowed = sanitize_key( (string) $allowed_panel );
			if ( '' !== $allowed ) {
				$allowed_panels[ $allowed ] = true;
			}
		}

		if ( '' !== $panel && [] !== $allowed_panels ) {
			return [
				'score'       => isset( $allowed_panels[ $panel ] ) ? 0.75 : 0.45,
				'unsupported' => ! isset( $allowed_panels[ $panel ] ),
			];
		}

		return [
			'score'       => 0.55,
			'unsupported' => false,
		];
	}

	private static function score_section_role_match( array $suggestion, array $context ): float {
		$role_tokens = [];
		foreach ( [
			$context['designSemantics']['sectionRole'] ?? null,
			$context['designSemantics']['mainDesignIssue'] ?? null,
			$context['block']['structuralIdentity']['role'] ?? null,
			$context['block']['structuralIdentity']['location'] ?? null,
			$context['area'] ?? null,
			$context['templateType'] ?? null,
		] as $value ) {
			foreach ( self::tokens( self::text( $value ) ) as $token => $_ ) {
				$role_tokens[ $token ] = true;
			}
		}

		if ( [] === $role_tokens ) {
			return 0.55;
		}

		$suggestion_tokens = self::tokens( implode( ' ', self::text_values( $suggestion, 30 ) ) );
		return [] !== array_intersect_key( $role_tokens, $suggestion_tokens ) ? 0.8 : 0.55;
	}

	/**
	 * @return array{score: float, stale: bool}
	 */
	private static function score_docs_freshness( array $docs_grounding ): array {
		$status     = sanitize_key( (string) ( $docs_grounding['status'] ?? '' ) );
		$encoded    = wp_json_encode( $docs_grounding );
		$serialized = is_string( $encoded ) ? strtolower( $encoded ) : '';
		$stale      = 'stale' === $status || str_contains( $serialized, 'stale' );

		if ( $stale ) {
			return [
				'score' => 0.35,
				'stale' => true,
			];
		}

		if ( in_array( $status, [ 'grounded', 'current', 'ready' ], true ) || str_contains( $serialized, 'current' ) ) {
			return [
				'score' => 0.8,
				'stale' => false,
			];
		}

		return [
			'score' => 0.55,
			'stale' => false,
		];
	}

	/**
	 * @return array{score: float, visibleScore: float, unsupported: bool}
	 */
	private static function score_pattern_readiness( array $suggestion, array $context ): array {
		$patterns = self::extract_pattern_names( $suggestion );
		if ( [] === $patterns ) {
			return [
				'score'        => 0.55,
				'visibleScore' => 0.55,
				'unsupported'  => false,
			];
		}

		$visible = [];
		foreach ( self::list_value( $context['visiblePatternNames'] ?? [] ) as $name ) {
			$normalized = self::text( $name );
			if ( '' !== $normalized ) {
				$visible[ $normalized ] = true;
			}
		}

		foreach ( self::list_value( $context['patterns'] ?? [] ) as $pattern ) {
			if ( is_array( $pattern ) ) {
				$name = self::text( $pattern['name'] ?? '' );
				if ( '' !== $name ) {
					$visible[ $name ] = true;
				}
			}
		}

		if ( [] === $visible ) {
			return [
				'score'        => 0.65,
				'visibleScore' => 0.55,
				'unsupported'  => false,
			];
		}

		foreach ( $patterns as $pattern ) {
			if ( ! isset( $visible[ $pattern ] ) ) {
				return [
					'score'        => 0.45,
					'visibleScore' => 0.45,
					'unsupported'  => true,
				];
			}
		}

		return [
			'score'        => 0.85,
			'visibleScore' => 0.85,
			'unsupported'  => false,
		];
	}

	private static function score_native_preset_fit( array $suggestion ): float {
		$encoded    = wp_json_encode( $suggestion );
		$serialized = is_string( $encoded ) ? strtolower( $encoded ) : '';
		if ( str_contains( $serialized, 'var:preset|' ) || str_contains( $serialized, 'var(--wp--preset--' ) ) {
			return 0.85;
		}

		if ( preg_match( '/#[0-9a-f]{3,8}\b/', $serialized ) ) {
			return 0.45;
		}

		return 0.55;
	}

	private static function score_accessibility_fit( array $suggestion, array $context ): float {
		$suggestion_tokens       = self::tokens( implode( ' ', self::text_values( $suggestion, 40 ) ) );
		$accessibility_tokens    = array_fill_keys( [ 'accessibility', 'accessible', 'contrast', 'readability', 'readable', 'focus', 'keyboard' ], true );
		$addresses_accessibility = [] !== array_intersect_key( $suggestion_tokens, $accessibility_tokens );

		if ( ! $addresses_accessibility ) {
			return 0.55;
		}

		$negative = array_fill_keys( [ 'low', 'dim', 'faint', 'hide', 'hidden' ], true );
		if ( [] !== array_intersect_key( $suggestion_tokens, $negative ) ) {
			return 0.35;
		}

		return 0.85;
	}

	private static function score_design_semantics_fit( array $suggestion, array $context ): float {
		$issue = self::text( $context['designSemantics']['mainDesignIssue'] ?? '' );
		if ( '' === $issue ) {
			return 0.55;
		}

		$suggestion_tokens = self::tokens( implode( ' ', self::text_values( $suggestion, 40 ) ) );
		$issue_tokens      = self::tokens( $issue );

		return [] !== array_intersect_key( $issue_tokens, $suggestion_tokens ) ? 0.8 : 0.55;
	}

	private static function has_validation_risk( array $suggestion ): bool {
		if ( [] !== self::list_value( $suggestion['rejectedOperations'] ?? [] ) ) {
			return true;
		}

		$text = strtolower( implode( ' ', self::text_values( $suggestion, 30 ) ) );
		return str_contains( $text, 'rejected' )
			|| str_contains( $text, 'invalid' )
			|| str_contains( $text, 'validation' )
			|| str_contains( $text, 'contrast failed' );
	}

	private static function is_possible_no_op( array $suggestion, array $context ): bool {
		$updates = self::map( $suggestion['attributeUpdates'] ?? [] );
		if ( [] !== $updates ) {
			$current = self::map( $context['block']['currentAttributes'] ?? $context['currentState']['attributes'] ?? [] );
			if ( [] === $current ) {
				return false;
			}

			$flat_updates = self::flatten_scalar_map( $updates );
			if ( [] === $flat_updates ) {
				return false;
			}

			foreach ( $flat_updates as $path => $value ) {
				$current_value = self::value_at_path( $current, explode( '.', $path ) );
				if ( $current_value !== $value ) {
					return false;
				}
			}

			return true;
		}

		return self::style_operations_are_possible_no_op( $suggestion, $context );
	}

	/**
	 * @return array<string, true>
	 */
	private static function normalize_path_inventory( mixed $paths ): array {
		$normalized = [];
		foreach ( self::list_value( $paths ) as $path ) {
			$key = self::normalize_path( $path );
			if ( '' !== $key ) {
				$normalized[ $key ] = true;
			}
		}

		return $normalized;
	}

	/**
	 * @return array<string, true>
	 */
	private static function normalize_style_context_path_inventory( mixed $paths ): array {
		$normalized = [];
		foreach ( self::normalize_style_context_path_entries( $paths ) as $path ) {
			$key = self::normalize_path( $path );
			if ( '' !== $key ) {
				$normalized[ $key ] = true;
			}
		}

		return $normalized;
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function normalize_style_context_path_entries( mixed $paths ): array {
		if ( is_string( $paths ) ) {
			return [ $paths ];
		}

		if ( ! is_array( $paths ) ) {
			return [];
		}

		if ( array_key_exists( 'path', $paths ) ) {
			return [ $paths['path'] ];
		}

		if ( self::is_path_segment_list( $paths ) ) {
			return [ $paths ];
		}

		$entries = [];
		foreach ( self::list_value( $paths ) as $entry ) {
			if ( is_array( $entry ) && array_key_exists( 'path', $entry ) ) {
				$entries[] = $entry['path'];
				continue;
			}

			if ( is_string( $entry ) || ( is_array( $entry ) && self::is_path_segment_list( $entry ) ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	private static function is_path_segment_list( array $path ): bool {
		if ( [] === $path || array_keys( $path ) !== range( 0, count( $path ) - 1 ) ) {
			return false;
		}

		foreach ( $path as $segment ) {
			if ( ! is_scalar( $segment ) || is_bool( $segment ) ) {
				return false;
			}
			if ( str_contains( (string) $segment, '.' ) ) {
				return false;
			}
		}

		return true;
	}

	private static function style_operations_are_possible_no_op( array $suggestion, array $context ): bool {
		$current_styles = self::map( $context['styleContext']['currentConfig']['styles'] ?? [] );
		if ( [] === $current_styles ) {
			return false;
		}

		$inspected = 0;
		foreach ( [ 'operations', 'proposedOperations' ] as $key ) {
			foreach ( self::list_value( $suggestion[ $key ] ?? [] ) as $operation ) {
				if ( ! is_array( $operation ) ) {
					continue;
				}

				$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );
				if ( ! in_array( $type, [ 'set_styles', 'set_block_styles' ], true ) ) {
					continue;
				}

				$segments = self::normalize_style_lookup_path( $operation['path'] ?? '' );
				if ( [] === $segments || ! array_key_exists( 'value', $operation ) ) {
					return false;
				}

				$value = $operation['value'];
				if ( ! is_scalar( $value ) && null !== $value ) {
					return false;
				}

				$current_branch = $current_styles;
				if ( 'set_block_styles' === $type ) {
					$block_name = self::text( $operation['blockName'] ?? '' );
					if ( '' === $block_name ) {
						return false;
					}

					$current_branch = self::map( $current_styles['blocks'][ $block_name ] ?? [] );
					if ( [] === $current_branch ) {
						return false;
					}
				}

				if ( ! self::path_exists( $current_branch, $segments ) ) {
					return false;
				}

				if ( self::value_at_path( $current_branch, $segments ) !== $value ) {
					return false;
				}

				++$inspected;
			}
		}

		return $inspected > 0;
	}

	/**
	 * @return array<int, string>
	 */
	private static function normalize_style_lookup_path( mixed $path ): array {
		$parts = [];
		if ( is_string( $path ) ) {
			$parts = explode( '.', $path );
		} elseif ( is_array( $path ) ) {
			$parts = $path;
		}

		$segments = [];
		foreach ( $parts as $part ) {
			if ( ! is_scalar( $part ) || is_bool( $part ) ) {
				continue;
			}

			$segment = trim( sanitize_text_field( (string) $part ) );
			if ( '' === $segment || 'style' === $segment ) {
				continue;
			}

			$segments[] = $segment;
		}

		if ( [] !== $segments && 'styles' === $segments[0] ) {
			array_shift( $segments );
		}

		return array_values( $segments );
	}

	/**
	 * @return array<int, string>
	 */
	private static function extract_concrete_style_paths( array $suggestion ): array {
		$paths   = [];
		$updates = self::map( $suggestion['attributeUpdates'] ?? [] );
		if ( isset( $updates['style'] ) && is_array( $updates['style'] ) ) {
			foreach ( array_keys( self::flatten_scalar_map( $updates['style'] ) ) as $path ) {
				$paths[ $path ] = $path;
			}
		}

		foreach ( [ 'operations', 'proposedOperations' ] as $key ) {
			foreach ( self::list_value( $suggestion[ $key ] ?? [] ) as $operation ) {
				if ( ! is_array( $operation ) ) {
					continue;
				}
				$path = self::normalize_path( $operation['path'] ?? '' );
				if ( '' !== $path ) {
					$paths[ $path ] = $path;
				}
			}
		}

		return array_values( $paths );
	}

	/**
	 * @return array<int, string>
	 */
	private static function extract_pattern_names( array $suggestion ): array {
		$patterns = [];
		foreach ( [ 'operations', 'proposedOperations' ] as $key ) {
			foreach ( self::list_value( $suggestion[ $key ] ?? [] ) as $operation ) {
				if ( is_array( $operation ) ) {
					$name = self::text( $operation['patternName'] ?? '' );
					if ( '' !== $name ) {
						$patterns[ $name ] = $name;
					}
				}
			}
		}

		foreach ( self::list_value( $suggestion['patternSuggestions'] ?? [] ) as $pattern ) {
			$name = is_array( $pattern ) ? self::text( $pattern['name'] ?? '' ) : self::text( $pattern );
			if ( '' !== $name ) {
				$patterns[ $name ] = $name;
			}
		}

		return array_values( $patterns );
	}

	private static function normalize_path( mixed $path ): string {
		$parts = [];
		if ( is_string( $path ) ) {
			$parts = explode( '.', $path );
		} elseif ( is_array( $path ) ) {
			$parts = $path;
		}

		$segments = [];
		foreach ( $parts as $part ) {
			$segment = sanitize_key( (string) $part );
			if ( '' === $segment || 'style' === $segment ) {
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '.', $segments );
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function list_value( mixed $value ): array {
		return is_array( $value ) ? array_values( $value ) : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function flatten_scalar_map( array $value, string $prefix = '' ): array {
		$result = [];
		foreach ( $value as $key => $entry ) {
			$path = '' === $prefix ? sanitize_key( (string) $key ) : $prefix . '.' . sanitize_key( (string) $key );
			if ( '' === $path ) {
				continue;
			}
			if ( is_array( $entry ) ) {
				$result += self::flatten_scalar_map( $entry, $path );
				continue;
			}
			if ( is_scalar( $entry ) || null === $entry ) {
				$result[ $path ] = $entry;
			}
		}

		return $result;
	}

	private static function value_at_path( array $source, array $path ): mixed {
		$current = $source;
		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	private static function path_exists( array $source, array $path ): bool {
		$current = $source;
		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return false;
			}
			$current = $current[ $segment ];
		}

		return true;
	}

	/**
	 * @return array<string, true>
	 */
	private static function tokens( string $text ): array {
		$tokens = [];
		$parts  = preg_split( '/[^a-z0-9]+/i', strtolower( $text ) );
		foreach ( is_array( $parts ) ? $parts : [] as $part ) {
			if ( strlen( $part ) < 3 ) {
				continue;
			}
			$tokens[ $part ] = true;
		}

		return $tokens;
	}

	/**
	 * @return array<int, string>
	 */
	private static function text_values( mixed $value, int $limit ): array {
		if ( $limit <= 0 ) {
			return [];
		}

		if ( is_scalar( $value ) ) {
			$text = self::text( $value );
			return '' !== $text ? [ $text ] : [];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$values = [];
		foreach ( $value as $entry ) {
			foreach ( self::text_values( $entry, $limit - count( $values ) ) as $text ) {
				$values[] = $text;
				if ( count( $values ) >= $limit ) {
					break 2;
				}
			}
		}

		return $values;
	}
}
