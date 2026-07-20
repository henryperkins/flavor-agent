<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Shared atomic apply pass for path-addressed structural operations over a
 * parsed block tree. Extracted verbatim from TemplatePartApplyExecutor /
 * TemplateApplyExecutor (behavior-preserving) so the template, template-part,
 * and post-blocks executors share one implementation of the load-bearing
 * ordering property instead of re-deriving it.
 *
 * Three phases, all fail closed before any mutation:
 *   1. Verify each path-addressed op's expectedTarget against the ORIGINAL
 *      parsed tree.
 *   2. Pre-resolve every pattern.
 *   3. Apply ALL ops in ONE lexicographic-DESCENDING pass over their
 *      effective target path.
 *
 * Single descending pass is correct because validate_operations already
 * rejects equal and ancestor/descendant path pairs (block_paths_overlap),
 * leaving only sibling relationships between distinct ops. Editing the higher
 * path first therefore never shifts a still-frozen lower path, so removes,
 * replaces and inserts (including mixed and multi-insert plans) compose
 * correctly without per-op path re-derivation.
 *
 * @internal
 */
final class StructuralOperationsApplier {

	/**
	 * Apply every validated operation to the parsed block tree atomically.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function apply_operations( array $blocks, array $operations ): array|\WP_Error {
		// Phase 1 — verify every path-addressed op's expectedTarget against the
		// ORIGINAL tree (fail closed, no mutation).
		foreach ( $operations as $operation ) {
			$path = self::op_path( $operation );

			if ( null !== $path ) {
				$err = self::assert_expected_target( BlockTreeMutator::resolve( $blocks, $path ), $operation );

				if ( is_wp_error( $err ) ) {
					return $err;
				}
			} elseif ( in_array( $operation['type'] ?? '', [ 'remove_block', 'replace_block_with_pattern' ], true ) ) {
				return new \WP_Error(
					'flavor_agent_apply_target_changed',
					'A targeted operation is missing its path.',
					[ 'status' => 409 ]
				);
			}
		}

		// Phase 2 — pre-resolve every pattern (fail closed, no mutation).
		$resolved_patterns = [];

		foreach ( $operations as $i => $operation ) {
			if ( in_array( $operation['type'] ?? '', [ 'insert_pattern', 'replace_block_with_pattern' ], true ) ) {
				$pattern_blocks = self::resolve_pattern_blocks( (string) ( $operation['patternName'] ?? '' ) );

				if ( is_wp_error( $pattern_blocks ) ) {
					return $pattern_blocks;
				}

				$resolved_patterns[ $i ] = $pattern_blocks;
			}
		}

		// Phase 3 — apply ALL ops in one lexicographic-DESCENDING pass over
		// effective paths. Overlap rejection guarantees no two ops are
		// equal/ancestor/descendant, so higher-first never shifts a later op's
		// frozen path; this composes removes, replaces and inserts.
		$indexed = [];

		foreach ( $operations as $i => $operation ) {
			$indexed[] = [
				'index'     => $i,
				'operation' => $operation,
			];
		}

		usort(
			$indexed,
			static fn ( array $a, array $b ): int => self::compare_paths(
				self::effective_order_path( $b['operation'] ),
				self::effective_order_path( $a['operation'] )
			)
		);

		foreach ( $indexed as $item ) {
			$blocks = self::apply_single_operation(
				$blocks,
				$item['operation'],
				$resolved_patterns[ $item['index'] ] ?? []
			);

			if ( is_wp_error( $blocks ) ) {
				return $blocks;
			}
		}

		return $blocks;
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return int[]|null
	 */
	public static function op_path( array $operation ): ?array {
		if ( ! is_array( $operation['targetPath'] ?? null ) || [] === $operation['targetPath'] ) {
			return null;
		}

		return array_map( 'intval', $operation['targetPath'] );
	}

	/**
	 * Re-attach request-time expectedTarget fingerprints after live contract
	 * validation so the apply pass compares against the approved request rather
	 * than a fingerprint rebuilt from the current tree.
	 *
	 * @param array<int, array<string, mixed>> $validated_operations
	 * @param array<int, mixed>                $requested_operations
	 * @return array<int, array<string, mixed>>
	 */
	public static function restore_requested_expected_targets( array $validated_operations, array $requested_operations ): array {
		foreach ( $validated_operations as $index => $operation ) {
			$requested = is_array( $requested_operations[ $index ] ?? null )
				? $requested_operations[ $index ]
				: [];

			if (
				null !== self::op_path( $operation )
				&& is_array( $requested['expectedTarget'] ?? null )
			) {
				$validated_operations[ $index ]['expectedTarget'] = $requested['expectedTarget'];
			}
		}

		return $validated_operations;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $pattern_blocks
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function apply_single_operation( array $blocks, array $operation, array $pattern_blocks ): array|\WP_Error {
		switch ( (string) ( $operation['type'] ?? '' ) ) {
			case 'remove_block':
				return BlockTreeMutator::remove( $blocks, self::op_path( $operation ) ?? [] );
			case 'replace_block_with_pattern':
				return BlockTreeMutator::replace( $blocks, self::op_path( $operation ) ?? [], $pattern_blocks );
			case 'insert_pattern':
				return self::apply_insert( $blocks, $operation, $pattern_blocks );
		}

		return new \WP_Error(
			'flavor_agent_apply_operations_invalid',
			'Unsupported operation type at apply time.',
			[ 'status' => 409 ]
		);
	}

	/**
	 * 'end' sorts above every concrete path (applied first); 'start' below
	 * (applied last). Anchored inserts/removes/replaces use their frozen path.
	 *
	 * @param array<string, mixed> $operation
	 * @return int[]
	 */
	private static function effective_order_path( array $operation ): array {
		$placement = (string) ( $operation['placement'] ?? '' );

		if ( 'end' === $placement ) {
			return [ PHP_INT_MAX ];
		}

		if ( 'start' === $placement ) {
			return [ PHP_INT_MIN ];
		}

		return self::op_path( $operation ) ?? [ PHP_INT_MIN ];
	}

	/**
	 * Lexicographic compare of two 0-indexed paths. Ancestor/descendant pairs
	 * are already rejected upstream, so only sibling ordering matters.
	 *
	 * @param int[] $a
	 * @param int[] $b
	 */
	private static function compare_paths( array $a, array $b ): int {
		$len = max( count( $a ), count( $b ) );

		for ( $i = 0; $i < $len; $i++ ) {
			$av = $a[ $i ] ?? PHP_INT_MIN;
			$bv = $b[ $i ] ?? PHP_INT_MIN;

			if ( $av !== $bv ) {
				return $av <=> $bv;
			}
		}

		return 0;
	}

	/**
	 * @param array<string, mixed>|null $live
	 * @param array<string, mixed>      $operation
	 */
	private static function assert_expected_target( ?array $live, array $operation ): true|\WP_Error {
		$expected = is_array( $operation['expectedTarget'] ?? null ) ? $operation['expectedTarget'] : [];
		$name     = (string) ( $operation['expectedBlockName'] ?? ( $expected['name'] ?? '' ) );

		if ( null === $live ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'A targeted block no longer exists at its path.',
				[ 'status' => 409 ]
			);
		}

		if ( '' !== $name && (string) ( $live['blockName'] ?? '' ) !== $name ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'A targeted block changed type after the request.',
				[ 'status' => 409 ]
			);
		}

		if ( isset( $expected['childCount'] ) ) {
			$live_children = is_array( $live['innerBlocks'] ?? null ) ? count( $live['innerBlocks'] ) : 0;

			if ( $live_children !== (int) $expected['childCount'] ) {
				return new \WP_Error(
					'flavor_agent_apply_target_changed',
					'A targeted block changed its inner structure after the request.',
					[ 'status' => 409 ]
				);
			}
		}

		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function resolve_pattern_blocks( string $pattern_name ): array|\WP_Error {
		if ( '' === $pattern_name || ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_pattern_unavailable',
				'The requested pattern is not registered on this site.',
				[ 'status' => 409 ]
			);
		}

		$registered = \WP_Block_Patterns_Registry::get_instance()->get_registered( $pattern_name );
		$markup     = is_array( $registered ) ? (string) ( $registered['content'] ?? '' ) : '';

		if ( '' === trim( $markup ) ) {
			return new \WP_Error(
				'flavor_agent_apply_pattern_unavailable',
				'The requested pattern is not registered on this site (synced-only patterns are out of scope for v1).',
				[ 'status' => 409 ]
			);
		}

		$blocks = array_values(
			array_filter(
				parse_blocks( $markup ),
				static fn ( $b ): bool => is_array( $b ) && null !== ( $b['blockName'] ?? null )
			)
		);

		// Fail closed when the pattern markup carries no block delimiters: such a
		// pattern filters down to zero blocks, which would silently degrade an
		// insert/replace into a delete. Mirror the empty-markup error above.
		if ( [] === $blocks ) {
			return new \WP_Error(
				'flavor_agent_apply_pattern_unavailable',
				'The requested pattern resolved to no blocks and cannot be applied.',
				[ 'status' => 409 ]
			);
		}

		return $blocks;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed>             $operation
	 * @param array<int, array<string, mixed>> $pattern_blocks
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function apply_insert( array $blocks, array $operation, array $pattern_blocks ): array|\WP_Error {
		$placement = (string) ( $operation['placement'] ?? '' );
		$path      = self::op_path( $operation );

		if ( in_array( $placement, [ 'before_block_path', 'after_block_path' ], true ) ) {
			if ( null === $path ) {
				return new \WP_Error(
					'flavor_agent_apply_target_changed',
					'Insertion anchor path is missing.',
					[ 'status' => 409 ]
				);
			}

			$parent = array_slice( $path, 0, -1 );
			$index  = (int) end( $path ) + ( 'after_block_path' === $placement ? 1 : 0 );

			return BlockTreeMutator::insert( $blocks, $parent, $index, $pattern_blocks );
		}

		// start / end at top level.
		$index = 'end' === $placement ? count( $blocks ) : 0;

		return BlockTreeMutator::insert( $blocks, [], $index, $pattern_blocks );
	}
}
