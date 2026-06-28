<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\TemplatePartPrompt;

/**
 * Server-side executor for governed external template-part structural applies.
 *
 * Mirrors StyleApplyExecutor: read the live part, re-validate operations and
 * expectedTarget fingerprints, mutate the parsed block tree atomically through
 * BlockTreeMutator, persist via core post APIs, and snapshot before/after
 * post_content. No attestation. See
 * docs/superpowers/specs/2026-06-24-template-part-external-apply-executor-design.md.
 *
 * `undo()` arrives in Task 6, at which point this class declares
 * `implements ExternalApplyExecutor`.
 */
final class TemplatePartApplyExecutor {

	/**
	 * Re-resolve the live part, re-validate every stored operation against a
	 * freshly collected live context, re-verify each path-addressed op's
	 * expectedTarget fingerprint, mutate the parsed block tree atomically, and
	 * persist. Any drift (re-validation, expectedTarget, or pattern resolution)
	 * aborts with zero writes.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{target: array<string, string>, before: array<string, string>, after: array<string, mixed>}|\WP_Error
	 */
	public static function execute( array $entry ): array|\WP_Error {
		$ref  = self::part_ref( $entry );
		$part = self::resolve_part( $ref );

		if ( is_wp_error( $part ) ) {
			return $part;
		}

		$before_content = (string) ( $part->content ?? '' );
		$apply          = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$operations     = is_array( $apply['operations'] ?? null ) ? $apply['operations'] : [];

		if ( [] === $operations ) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'No operations to apply.',
				[ 'status' => 409 ]
			);
		}

		// Re-validate against a freshly collected live context. No filter seam:
		// a governed write path must not be interceptable.
		$context = ServerCollector::for_template_part( $ref );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = TemplatePartPrompt::validate_operations_for_apply( $operations, $context );

		if (
			[] === $validated['operations']
			|| count( $validated['operations'] ) !== count( $operations )
		) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more template-part operations failed re-validation against the live execution contract.',
				[
					'status'            => 409,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$blocks = self::apply_operations( parse_blocks( $before_content ), $validated['operations'] );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$after_content = serialize_blocks( $blocks );
		$persisted     = self::persist( $part, $after_content );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return [
			'target' => [
				'templatePartId' => (string) ( $part->id ?? $ref ),
				'slug'           => (string) ( $part->slug ?? '' ),
				'area'           => (string) ( $part->area ?? '' ),
			],
			'before' => [ 'content' => $before_content ],
			'after'  => [
				'content'    => $after_content,
				'operations' => $validated['operations'],
			],
		];
	}

	/**
	 * Re-resolve the live template part and return the gate-2 drift baseline:
	 * the sha256 of its parsed -> reserialized live post_content.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function resolve_baseline( array $entry ): string|\WP_Error {
		$part = self::resolve_part( self::part_ref( $entry ) );

		return is_wp_error( $part )
			? $part
			: self::content_hash( (string) ( $part->content ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function part_ref( array $entry ): string {
		$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

		return trim( (string) ( $target['templatePartId'] ?? '' ) );
	}

	/**
	 * @return object|\WP_Error A WP_Block_Template-shaped object, or a fail-closed error.
	 */
	private static function resolve_part( string $ref ): object {
		if ( '' === $ref ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'Missing template-part identifier.',
				[ 'status' => 409 ]
			);
		}

		$part = ServerCollector::resolve_template_part_for_apply( $ref );

		// Error only when the part is genuinely missing (non-object). An
		// available-but-empty part is a legitimate apply target (e.g. a future
		// insert-into-empty); its empty content hashes to a valid, stable
		// baseline, so it must not be rejected here.
		if ( ! is_object( $part ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested template part is not available on this site.',
				[ 'status' => 404 ]
			);
		}

		return $part;
	}

	private static function content_hash( string $content ): string {
		return hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Apply every validated operation to the parsed block tree atomically.
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
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function apply_operations( array $blocks, array $operations ): array|\WP_Error {
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
	 * @param array<string, mixed> $operation
	 * @return int[]|null
	 */
	private static function op_path( array $operation ): ?array {
		if ( ! is_array( $operation['targetPath'] ?? null ) || [] === $operation['targetPath'] ) {
			return null;
		}

		return array_map( 'intval', $operation['targetPath'] );
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

		return array_values(
			array_filter(
				parse_blocks( $markup ),
				static fn ( $b ): bool => is_array( $b ) && null !== ( $b['blockName'] ?? null )
			)
		);
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

	/**
	 * Persist the mutated content: update a DB-backed part in place, or
	 * materialize a theme-file part into a wp_template_part post on first apply.
	 * Fails closed; invalidates caches after every write (R7).
	 *
	 * @return int|\WP_Error The persisted post id.
	 */
	private static function persist( object $part, string $content ): int|\WP_Error {
		$wp_id = (int) ( $part->wp_id ?? 0 );

		if ( $wp_id > 0 ) {
			$updated = wp_update_post(
				[
					'ID'           => $wp_id,
					'post_content' => $content,
				],
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			if ( 0 === (int) $updated ) {
				return new \WP_Error(
					'flavor_agent_apply_write_failed',
					'Flavor Agent could not write the template part entity.',
					[ 'status' => 500 ]
				);
			}

			self::invalidate_part_cache( (int) $updated );

			return (int) $updated;
		}

		// Materialize a theme-file part into a wp_template_part post (Site Editor parity).
		$slug       = sanitize_key( (string) ( $part->slug ?? '' ) );
		$stylesheet = function_exists( 'get_stylesheet' ) ? sanitize_key( (string) get_stylesheet() ) : '';

		if ( '' === $slug || '' === $stylesheet ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Cannot materialize a template part without a slug and active theme.',
				[ 'status' => 500 ]
			);
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => (string) ( $part->title ?? $slug ),
				'post_content' => $content,
				'tax_input'    => [
					'wp_theme'              => [ $stylesheet ],
					'wp_template_part_area' => [ sanitize_key( (string) ( $part->area ?? 'uncategorized' ) ) ],
				],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( 0 === (int) $post_id ) {
			return new \WP_Error(
				'flavor_agent_apply_write_failed',
				'Flavor Agent could not materialize the template part entity.',
				[ 'status' => 500 ]
			);
		}

		self::invalidate_part_cache( (int) $post_id );

		return (int) $post_id;
	}

	/**
	 * Invalidate the post cache after a write. In core, clean_post_cache() busts
	 * the 'posts' last_changed value that the wp_get_block_templates query cache
	 * keys on, so the block-template resolution path re-reads fresh content.
	 */
	private static function invalidate_part_cache( int $post_id ): void {
		if ( $post_id > 0 && function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}

	private function __construct() {}
}
