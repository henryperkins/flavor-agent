<?php
/**
 * Shared structural-operation grammar for path-addressed block-tree applies.
 */

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\Support\ValidationReason;

/**
 * Single owner of the structural-operation grammar shared by the
 * template-part and post-blocks surfaces: the ≤3-op cap, overlap rejection,
 * placement vocabulary, expectedTarget fingerprint rules (name + childCount,
 * NOT attributes), and the context-lookup builders the validator consumes.
 *
 * Extracted from TemplatePartPrompt (behavior-preserving); TemplatePartPrompt
 * delegates here so the two surfaces cannot drift. The only post-blocks-only
 * behavior is the `target_locked` rejection, which fires exclusively when a
 * target entry carries `locked` metadata — template-part operation targets
 * never do, so template-part validation is byte-identical.
 *
 * @internal
 */
final class StructuralOperationsGrammar {

	/**
	 * Validate structural operations against the four context lookups,
	 * returning surviving executable operations alongside the specific
	 * rejection reasons that were accumulated. Each deterministic rejection
	 * branch emits a precise ValidationReason vocabulary code.
	 *
	 * @param array<int, mixed>                   $operations
	 * @param array<string, array<string, mixed>> $block_lookup
	 * @param array<string, true>                 $pattern_lookup
	 * @param array<string, array<string, mixed>> $operation_target_lookup
	 * @param array<string, array<string, mixed>> $insertion_anchor_lookup
	 * @return array{operations: array<int, array<string, mixed>>, reasons: array<int, array{code: string, severity: string, message?: string}>}
	 */
	public static function validate_operations(
		array $operations,
		array $block_lookup,
		array $pattern_lookup,
		array $operation_target_lookup,
		array $insertion_anchor_lookup
	): array {
		if ( count( $operations ) > 3 ) {
			return [
				'operations' => [],
				'reasons'    => ValidationReason::normalize( [ [ 'code' => 'too_many_operations' ] ] ),
			];
		}

		$valid              = [];
		$reasons            = [];
		$targeted_paths     = [];
		$allowed_placements = [
			'start'             => true,
			'end'               => true,
			'before_block_path' => true,
			'after_block_path'  => true,
		];

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				$reasons[] = [ 'code' => 'malformed_operation' ];
				continue;
			}

			$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );

			switch ( $type ) {
				case 'insert_pattern':
					$pattern_name = sanitize_text_field(
						(string) ( $operation['patternName'] ?? $operation['name'] ?? '' )
					);
					$placement    = sanitize_key( (string) ( $operation['placement'] ?? '' ) );
					$target_path  = self::sanitize_block_path( $operation['targetPath'] ?? null );

					if ( '' === $pattern_name || ! isset( $pattern_lookup[ $pattern_name ] ) ) {
						$reasons[] = [ 'code' => 'unknown_pattern' ];
						continue 2;
					}

					if ( ! isset( $allowed_placements[ $placement ] ) ) {
						$reasons[] = [ 'code' => 'invalid_placement' ];
						continue 2;
					}

					if (
						in_array( $placement, [ 'before_block_path', 'after_block_path' ], true )
						&& (
							null === $target_path ||
							! isset(
								$insertion_anchor_lookup[ $placement . '|' . self::block_path_key( $target_path ) ]
							)
						)
					) {
						$reasons[] = [ 'code' => 'invalid_anchor' ];
						continue 2;
					}

					if (
						in_array( $placement, [ 'start', 'end' ], true )
						&& ! isset( $insertion_anchor_lookup[ $placement ] )
					) {
						$reasons[] = [ 'code' => 'invalid_anchor' ];
						continue 2;
					}

					$normalized = [
						'type'        => 'insert_pattern',
						'patternName' => $pattern_name,
						'placement'   => $placement,
					];

					if ( null !== $target_path ) {
						if ( self::has_overlapping_operation_path( $targeted_paths, $target_path ) ) {
							$reasons[] = [ 'code' => 'overlapping_block_paths' ];
							return [
								'operations' => [],
								'reasons'    => ValidationReason::normalize( $reasons ),
							];
						}

						$targeted_paths[]         = $target_path;
						$normalized['targetPath'] = $target_path;

						$target_node = $block_lookup[ self::block_path_key( $target_path ) ] ?? null;
						if ( is_array( $target_node ) ) {
							$normalized['expectedTarget'] = self::build_expected_target( $target_node );
						}
					}

					$valid[] = $normalized;
					break;

				case 'replace_block_with_pattern':
					$pattern_name        = sanitize_text_field(
						(string) ( $operation['patternName'] ?? $operation['name'] ?? '' )
					);
					$expected_block_name = sanitize_text_field( (string) ( $operation['expectedBlockName'] ?? '' ) );
					$target_path         = self::sanitize_block_path( $operation['targetPath'] ?? null );

					if ( '' === $pattern_name || ! isset( $pattern_lookup[ $pattern_name ] ) ) {
						$reasons[] = [ 'code' => 'unknown_pattern' ];
						continue 2;
					}

					if ( '' === $expected_block_name || null === $target_path ) {
						$reasons[] = [ 'code' => 'invalid_anchor' ];
						continue 2;
					}

					$path_key    = self::block_path_key( $target_path );
					$target_node = $block_lookup[ $path_key ] ?? null;
					$target_meta = $operation_target_lookup[ $path_key ] ?? null;

					if ( self::is_locked_for_operation( $target_meta, 'replace_block_with_pattern' ) ) {
						$reasons[] = [ 'code' => 'target_locked' ];
						continue 2;
					}

					if (
						! is_array( $target_node ) ||
						! is_array( $target_meta ) ||
						! in_array( 'replace_block_with_pattern', $target_meta['allowedOperations'] ?? [], true ) ||
						sanitize_text_field( (string) ( $target_node['name'] ?? '' ) ) !== $expected_block_name
					) {
						$reasons[] = [ 'code' => 'invalid_anchor' ];
						continue 2;
					}

					if ( self::has_overlapping_operation_path( $targeted_paths, $target_path ) ) {
						$reasons[] = [ 'code' => 'overlapping_block_paths' ];
						return [
							'operations' => [],
							'reasons'    => ValidationReason::normalize( $reasons ),
						];
					}

					$targeted_paths[] = $target_path;

					$valid[] = [
						'type'              => 'replace_block_with_pattern',
						'patternName'       => $pattern_name,
						'expectedBlockName' => $expected_block_name,
						'expectedTarget'    => self::build_expected_target( $target_node ),
						'targetPath'        => $target_path,
					];
					break;

				case 'remove_block':
					$expected_block_name = sanitize_text_field( (string) ( $operation['expectedBlockName'] ?? '' ) );
					$target_path         = self::sanitize_block_path( $operation['targetPath'] ?? null );

					if ( '' === $expected_block_name || null === $target_path ) {
						$reasons[] = [ 'code' => 'invalid_anchor' ];
						continue 2;
					}

					$path_key    = self::block_path_key( $target_path );
					$target_node = $block_lookup[ $path_key ] ?? null;
					$target_meta = $operation_target_lookup[ $path_key ] ?? null;

					if ( self::is_locked_for_operation( $target_meta, 'remove_block' ) ) {
						$reasons[] = [ 'code' => 'target_locked' ];
						continue 2;
					}

					if (
						! is_array( $target_node ) ||
						! is_array( $target_meta ) ||
						! in_array( 'remove_block', $target_meta['allowedOperations'] ?? [], true ) ||
						sanitize_text_field( (string) ( $target_node['name'] ?? '' ) ) !== $expected_block_name
					) {
						$reasons[] = [ 'code' => 'invalid_anchor' ];
						continue 2;
					}

					if ( self::has_overlapping_operation_path( $targeted_paths, $target_path ) ) {
						$reasons[] = [ 'code' => 'overlapping_block_paths' ];
						return [
							'operations' => [],
							'reasons'    => ValidationReason::normalize( $reasons ),
						];
					}

					$targeted_paths[] = $target_path;

					$valid[] = [
						'type'              => 'remove_block',
						'expectedBlockName' => $expected_block_name,
						'expectedTarget'    => self::build_expected_target( $target_node ),
						'targetPath'        => $target_path,
					];
					break;

				default:
					$reasons[] = [ 'code' => 'unknown_operation_type' ];
					continue 2;
			}
		}

		return [
			'operations' => $valid,
			'reasons'    => ValidationReason::normalize( $reasons ),
		];
	}

	/**
	 * Post-blocks-only lock rejection: fires when a target entry exists in the
	 * lookup, carries `locked` metadata (attrs.lock / templateLock recorded at
	 * collection time), and does not allow the requested operation. Template-part
	 * operation targets never carry `locked`, so this branch is unreachable for
	 * that surface.
	 *
	 * @param array<string, mixed>|null $target_meta
	 */
	private static function is_locked_for_operation( ?array $target_meta, string $operation_type ): bool {
		return is_array( $target_meta )
			&& ! empty( $target_meta['locked'] )
			&& ! in_array( $operation_type, $target_meta['allowedOperations'] ?? [], true );
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, array<string, mixed>>
	 */
	public static function build_block_lookup( array $context ): array {
		if ( array_key_exists( 'allBlockPaths', $context ) && is_array( $context['allBlockPaths'] ) ) {
			return self::build_flat_block_lookup( $context['allBlockPaths'] );
		}

		$tree = is_array( $context['blockTree'] ?? null ) ? $context['blockTree'] : [];
		return self::build_tree_block_lookup( $tree );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<string, array<string, mixed>>
	 */
	public static function build_tree_block_lookup( array $tree ): array {
		$lookup = [];

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );

			if ( $path !== null ) {
				$lookup[ self::block_path_key( $path ) ] = [
					'name'       => (string) ( $node['name'] ?? '' ),
					'path'       => $path,
					'label'      => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
					'attributes' => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
					'childCount' => isset( $node['childCount'] ) ? (int) $node['childCount'] : 0,
					'slot'       => is_array( $node['slot'] ?? null ) ? $node['slot'] : [],
				];
			}

			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			$lookup   = array_merge( $lookup, self::build_tree_block_lookup( $children ) );
		}

		return $lookup;
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<string, array<string, mixed>>
	 */
	public static function build_flat_block_lookup( array $nodes ): array {
		$lookup = [];

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $node['path'] ?? null );

			if ( null === $path ) {
				continue;
			}

			$lookup[ self::block_path_key( $path ) ] = [
				'name'       => (string) ( $node['name'] ?? '' ),
				'path'       => $path,
				'label'      => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
				'attributes' => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
				'childCount' => isset( $node['childCount'] ) ? (int) $node['childCount'] : 0,
				'slot'       => is_array( $node['slot'] ?? null ) ? $node['slot'] : [],
			];
		}

		return $lookup;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, true>
	 */
	public static function build_pattern_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [] as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $pattern['name'] ?? '' ) );

			if ( $name !== '' ) {
				$lookup[ $name ] = true;
			}
		}

		return $lookup;
	}

	/**
	 * @param array<string, mixed> $context Surface context used to build the prompt.
	 * @return array<string, array<string, mixed>>
	 */
	public static function build_operation_target_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [] as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path = self::sanitize_block_path( $target['path'] ?? null );

			if ( $path === null ) {
				continue;
			}

			$entry = [
				'name'              => sanitize_text_field( (string) ( $target['name'] ?? '' ) ),
				'allowedOperations' => is_array( $target['allowedOperations'] ?? null ) ? array_map( 'sanitize_key', $target['allowedOperations'] ) : [],
				'allowedInsertions' => is_array( $target['allowedInsertions'] ?? null ) ? array_map( 'sanitize_key', $target['allowedInsertions'] ) : [],
			];

			if ( is_array( $target['locked'] ?? null ) && [] !== $target['locked'] ) {
				$entry['locked'] = $target['locked'];
			}

			$lookup[ self::block_path_key( $path ) ] = $entry;
		}

		return $lookup;
	}

	/**
	 * @param array<string, mixed> $context Surface context used to build the prompt.
	 * @return array<string, array<string, mixed>>
	 */
	public static function build_insertion_anchor_lookup( array $context ): array {
		$lookup = [];

		foreach ( is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [] as $anchor ) {
			if ( ! is_array( $anchor ) ) {
				continue;
			}

			$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
			$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );

			if ( $placement === '' ) {
				continue;
			}

			if ( $path === null ) {
				$lookup[ $placement ] = [
					'placement' => $placement,
				];
				continue;
			}

			$lookup[ $placement . '|' . self::block_path_key( $path ) ] = [
				'placement'  => $placement,
				'targetPath' => $path,
			];
		}

		return $lookup;
	}

	/**
	 * Build the expectedTarget payload recorded on a stored operation: name,
	 * label, attributes, childCount, and slot (when present).
	 *
	 * The apply-time drift comparison (StructuralOperationsApplier::assert_expected_target)
	 * enforces only name + childCount — the stable structural fingerprint.
	 * label, attributes, and slot ride along as request-time provenance but are
	 * intentionally NOT part of the comparison: attribute-level fingerprints
	 * churn on unrelated edits, so comparing them would reject an apply after
	 * any incidental content change to the target.
	 *
	 * @param array<string, mixed> $target_node
	 * @return array<string, mixed>
	 */
	public static function build_expected_target( array $target_node ): array {
		$expected = [
			'name'       => sanitize_text_field( (string) ( $target_node['name'] ?? '' ) ),
			'label'      => sanitize_text_field( (string) ( $target_node['label'] ?? '' ) ),
			'attributes' => is_array( $target_node['attributes'] ?? null ) ? $target_node['attributes'] : [],
			'childCount' => isset( $target_node['childCount'] ) ? (int) $target_node['childCount'] : 0,
		];

		$slot = is_array( $target_node['slot'] ?? null ) ? $target_node['slot'] : [];
		if ( count( $slot ) > 0 ) {
			$expected['slot'] = [
				'slug'    => sanitize_key( (string) ( $slot['slug'] ?? '' ) ),
				'area'    => sanitize_key( (string) ( $slot['area'] ?? '' ) ),
				'isEmpty' => ! empty( $slot['isEmpty'] ),
			];
		}

		return $expected;
	}

	/**
	 * @param mixed $path
	 * @return int[]|null
	 */
	public static function sanitize_block_path( mixed $path ): ?array {
		if ( ! is_array( $path ) || count( $path ) === 0 ) {
			return null;
		}

		$normalized = [];

		foreach ( $path as $segment ) {
			if ( ! is_int( $segment ) && ! is_numeric( $segment ) ) {
				return null;
			}

			$segment = (int) $segment;

			if ( $segment < 0 ) {
				return null;
			}

			$normalized[] = $segment;
		}

		return $normalized;
	}

	/**
	 * @param array<int, int[]> $targeted_paths
	 * @param int[]            $target_path
	 */
	public static function has_overlapping_operation_path( array $targeted_paths, array $target_path ): bool {
		foreach ( $targeted_paths as $candidate ) {
			if ( self::block_paths_overlap( $candidate, $target_path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int[] $left
	 * @param int[] $right
	 */
	public static function block_paths_overlap( array $left, array $right ): bool {
		return self::block_path_key( $left ) === self::block_path_key( $right )
			|| self::is_block_path_ancestor( $left, $right )
			|| self::is_block_path_ancestor( $right, $left );
	}

	/**
	 * @param int[] $ancestor
	 * @param int[] $path
	 */
	public static function is_block_path_ancestor( array $ancestor, array $path ): bool {
		if ( count( $ancestor ) >= count( $path ) ) {
			return false;
		}

		foreach ( $ancestor as $index => $segment ) {
			if ( ! array_key_exists( $index, $path ) || $path[ $index ] !== $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param int[] $path
	 */
	public static function block_path_key( array $path ): string {
		return implode( '.', $path );
	}

	// ---------------------------------------------------------------------
	// Prompt presentation of the executable contract (shared verbatim so the
	// model-facing grammar description cannot drift between surfaces).
	// ---------------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $tree
	 */
	public static function format_block_tree( array $tree, int $depth = 0 ): string {
		$lines  = [];
		$indent = str_repeat( '  ', $depth );

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$path        = self::sanitize_block_path( $node['path'] ?? null ) ?? [];
			$path_string = '[' . implode( ', ', $path ) . ']';
			$name        = (string) ( $node['name'] ?? 'unknown' );
			$attributes  = is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [];
			$attr_suffix = '';

			if ( count( $attributes ) > 0 ) {
				$pairs = [];

				foreach ( $attributes as $key => $value ) {
					$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
					$pairs[] = "{$key}={$display}";
				}

				$attr_suffix = ' {' . implode( ', ', $pairs ) . '}';
			}

			$lines[] = "{$indent}- {$path_string} {$name}{$attr_suffix}";

			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			if ( count( $children ) > 0 ) {
				$lines[] = self::format_block_tree( $children, $depth + 1 );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $targets
	 */
	public static function format_operation_targets( array $targets ): string {
		$lines = [];

		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path               = self::sanitize_block_path( $target['path'] ?? null ) ?? [];
			$path_string        = '[' . implode( ', ', $path ) . ']';
			$name               = sanitize_text_field( (string) ( $target['name'] ?? 'unknown' ) );
			$label              = sanitize_text_field( (string) ( $target['label'] ?? self::humanize_block_name( $name ) ) );
			$allowed_operations = is_array( $target['allowedOperations'] ?? null ) ? array_filter( array_map( 'sanitize_key', $target['allowedOperations'] ) ) : [];
			$allowed_insertions = is_array( $target['allowedInsertions'] ?? null ) ? array_filter( array_map( 'sanitize_key', $target['allowedInsertions'] ) ) : [];

			$capabilities = array_merge( $allowed_operations, $allowed_insertions );
			$lines[]      = sprintf(
				'- %s %s (%s)',
				$path_string,
				$label !== '' ? $label : $name,
				implode( ', ', $capabilities )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $anchors
	 */
	public static function format_insertion_anchors( array $anchors ): string {
		$lines = [];

		foreach ( $anchors as $anchor ) {
			if ( ! is_array( $anchor ) ) {
				continue;
			}

			$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
			$label     = sanitize_text_field( (string) ( $anchor['label'] ?? '' ) );
			$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );
			$line      = '- ' . ( $label !== '' ? $label : $placement );

			if ( $placement !== '' ) {
				$line .= " (`{$placement}`)";
			}

			if ( $path !== null ) {
				$line .= ' -> [' . implode( ', ', $path ) . ']';
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $patterns
	 * @param array<int, array<string, mixed>> $targets
	 * @param array<int, array<string, mixed>> $anchors
	 */
	public static function format_operation_examples( array $patterns, array $targets, array $anchors ): string {
		$first_pattern = '';

		foreach ( $patterns as $pattern ) {
			if ( is_array( $pattern ) && ! empty( $pattern['name'] ) ) {
				$first_pattern = sanitize_text_field( (string) $pattern['name'] );
				break;
			}
		}

		$examples = [];

		// insert_pattern and replace_block_with_pattern both require a concrete
		// pattern name, so they are only synthesized when a candidate pattern is
		// available.
		if ( '' !== $first_pattern ) {
			foreach ( $anchors as $anchor ) {
				if ( ! is_array( $anchor ) ) {
					continue;
				}

				$placement = sanitize_key( (string) ( $anchor['placement'] ?? '' ) );
				$path      = self::sanitize_block_path( $anchor['targetPath'] ?? null );

				if ( in_array( $placement, [ 'before_block_path', 'after_block_path' ], true ) && null !== $path ) {
					$examples[] = wp_json_encode(
						[
							'type'        => 'insert_pattern',
							'patternName' => $first_pattern,
							'placement'   => $placement,
							'targetPath'  => $path,
						],
						JSON_UNESCAPED_SLASHES
					);
					break;
				}
			}

			foreach ( $targets as $target ) {
				if ( ! is_array( $target ) ) {
					continue;
				}

				$path    = self::sanitize_block_path( $target['path'] ?? null );
				$name    = sanitize_text_field( (string) ( $target['name'] ?? '' ) );
				$allowed = is_array( $target['allowedOperations'] ?? null ) ? $target['allowedOperations'] : [];

				if ( null !== $path && '' !== $name && in_array( 'replace_block_with_pattern', $allowed, true ) ) {
					$examples[] = wp_json_encode(
						[
							'type'              => 'replace_block_with_pattern',
							'patternName'       => $first_pattern,
							'targetPath'        => $path,
							'expectedBlockName' => $name,
						],
						JSON_UNESCAPED_SLASHES
					);
					break;
				}
			}
		}

		// remove_block carries no pattern, so it is surfaced whenever a live target
		// permits removal — independent of pattern availability. Without this, the
		// most destructive operation would have no worked example in the prompt.
		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$path    = self::sanitize_block_path( $target['path'] ?? null );
			$name    = sanitize_text_field( (string) ( $target['name'] ?? '' ) );
			$allowed = is_array( $target['allowedOperations'] ?? null ) ? $target['allowedOperations'] : [];

			if ( null !== $path && '' !== $name && in_array( 'remove_block', $allowed, true ) ) {
				$examples[] = wp_json_encode(
					[
						'type'              => 'remove_block',
						'targetPath'        => $path,
						'expectedBlockName' => $name,
					],
					JSON_UNESCAPED_SLASHES
				);
				break;
			}
		}

		return implode( "\n", array_filter( $examples ) );
	}

	/**
	 * @param array<string, mixed> $constraints
	 */
	public static function format_structural_constraints( array $constraints ): string {
		$lines = [];

		$content_only_paths = is_array( $constraints['contentOnlyPaths'] ?? null ) ? $constraints['contentOnlyPaths'] : [];
		if ( count( $content_only_paths ) > 0 ) {
			$lines[] = 'contentOnly paths: ' . implode(
				', ',
				array_map(
					static fn( array $path ): string => '[' . implode( ', ', $path ) . ']',
					array_filter( $content_only_paths, 'is_array' )
				)
			);
		}

		$locked_paths = is_array( $constraints['lockedPaths'] ?? null ) ? $constraints['lockedPaths'] : [];
		if ( count( $locked_paths ) > 0 ) {
			$lines[] = 'Locked paths: ' . implode(
				', ',
				array_map(
					static fn( array $path ): string => '[' . implode( ', ', $path ) . ']',
					array_filter( $locked_paths, 'is_array' )
				)
			);
		}

		return implode( "\n", $lines );
	}

	public static function humanize_block_name( string $block_name ): string {
		if ( str_starts_with( $block_name, 'core/' ) ) {
			$block_name = substr( $block_name, 5 );
		}

		return ucwords( str_replace( '-', ' ', $block_name ) );
	}
}
