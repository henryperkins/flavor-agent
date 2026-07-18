<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Attestation\BlockContentCanonicalizer;
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
 * `undo()` re-resolves the live part and restores the before snapshot under the
 * same equality semantics as StyleApplyExecutor::undo, completing the
 * resolve_baseline + execute + undo trio the ExternalApplyExecutor contract
 * requires.
 */
final class TemplatePartApplyExecutor implements ExternalApplyExecutor {

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
		$before_hash    = self::content_hash( $before_content );
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

		// Final concurrency gate (parity with StyleApplyExecutor::assert_global_styles_entity_unchanged):
		// re-resolve the live part immediately before the write and fail closed if its
		// content moved since the start-of-execute read, so a concurrent Site Editor /
		// wp-cli save in the read -> write window is never silently overwritten.
		$unchanged = self::assert_part_unchanged( $ref, $before_hash );

		if ( is_wp_error( $unchanged ) ) {
			return $unchanged;
		}

		$persisted = self::persist( $part, $after_content );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return [
			'target' => [
				'templatePartId'  => (string) ( $part->id ?? $ref ),
				'templatePartRef' => (string) ( $part->id ?? $ref ),
				'slug'            => (string) ( $part->slug ?? '' ),
				'area'            => (string) ( $part->area ?? '' ),
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
		$content = self::resolve_live_content( self::part_ref( $entry ) );

		return is_wp_error( $content )
			? $content
			: self::content_hash( $content );
	}

	/**
	 * Resolve the live template-part content for public subject-state verification.
	 */
	public static function resolve_live_content( string $ref ): string|\WP_Error {
		$part = self::resolve_part( $ref );

		return is_wp_error( $part )
			? $part
			: (string) ( $part->content ?? '' );
	}

	/**
	 * Server-side undo with the exact equality semantics StyleApplyExecutor uses:
	 * re-resolve the live part, then live == before → already undone (no write);
	 * live != after → drift failure (fail closed, no write); else restore the
	 * before snapshot. Hashes compare parsed -> reserialized content so that
	 * insignificant serialization differences never read as drift.
	 *
	 * @param array<string, mixed> $entry Hydrated activity entry.
	 * @return array{result: string}|\WP_Error
	 */
	public static function undo( array $entry ): array|\WP_Error {
		$ref  = self::part_ref( $entry );
		$part = self::resolve_part( $ref );

		if ( is_wp_error( $part ) ) {
			return $part;
		}

		$before = is_array( $entry['before'] ?? null ) ? $entry['before'] : [];
		$after  = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];

		if ( ! array_key_exists( 'content', $before ) || ! array_key_exists( 'content', $after ) ) {
			return new \WP_Error(
				'flavor_agent_undo_snapshot_unsupported',
				'This activity row does not record the before/after content snapshots needed for a server-side undo.',
				[ 'status' => 409 ]
			);
		}

		$live_hash   = self::content_hash( (string) ( $part->content ?? '' ) );
		$before_hash = self::content_hash( (string) $before['content'] );
		$after_hash  = self::content_hash( (string) $after['content'] );

		if ( hash_equals( $live_hash, $before_hash ) ) {
			return [ 'result' => 'already_undone' ];
		}

		if ( ! hash_equals( $live_hash, $after_hash ) ) {
			return new \WP_Error(
				'flavor_agent_undo_drift',
				'The template part changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
				[ 'status' => 409 ]
			);
		}

		// Final concurrency gate before the restore write, mirroring
		// StyleApplyExecutor::undo: re-resolve and fail closed if the live part moved
		// after the drift check but before the write.
		$unchanged = self::assert_part_unchanged( $ref, $live_hash );

		if ( is_wp_error( $unchanged ) ) {
			return $unchanged;
		}

		$persisted = self::persist( $part, (string) $before['content'] );

		return is_wp_error( $persisted ) ? $persisted : [ 'result' => 'undone' ];
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
		return BlockContentCanonicalizer::digest( $content );
	}

	/**
	 * Final concurrency gate, mirroring StyleApplyExecutor::assert_global_styles_entity_unchanged:
	 * re-resolve the live part immediately before a write and fail closed if its
	 * parsed -> reserialized content hash moved since the value captured at the start
	 * of the operation. Closes the read -> write window so a concurrent save is never
	 * silently overwritten.
	 *
	 * @return true|\WP_Error
	 */
	private static function assert_part_unchanged( string $ref, string $expected_hash ): true|\WP_Error {
		$current_content = self::resolve_live_content( $ref );

		if ( is_wp_error( $current_content ) ) {
			return $current_content;
		}

		if ( ! hash_equals( self::content_hash( $current_content ), $expected_hash ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The template part changed before Flavor Agent could persist this operation. Regenerate the request and try again.',
				[ 'status' => 409 ]
			);
		}

		return true;
	}

	/**
	 * Delegates to the shared StructuralOperationsApplier (three fail-closed
	 * phases, lexicographic-descending single pass). Kept as a private seam so
	 * the ordering + fail-closed guards remain provable per-executor by test.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function apply_operations( array $blocks, array $operations ): array|\WP_Error {
		return StructuralOperationsApplier::apply_operations( $blocks, $operations );
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return int[]|null
	 */
	private static function op_path( array $operation ): ?array {
		return StructuralOperationsApplier::op_path( $operation );
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
