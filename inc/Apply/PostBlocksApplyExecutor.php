<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\PostBlocksPrompt;

/**
 * Server-side executor for governed external post-blocks structural applies:
 * path-addressed insert_pattern / replace_block_with_pattern / remove_block
 * operations (≤3, overlap-free) against one post or page's post_content.
 *
 * Mirrors TemplatePartApplyExecutor: read the live post, re-validate every
 * stored operation against a freshly collected live document target contract
 * (which enforces the post-type/status allowlist and lock-aware target
 * exclusion), re-verify each op's expectedTarget fingerprint, mutate the
 * parsed block tree atomically through the shared StructuralOperationsApplier,
 * persist via wp_update_post, and snapshot before/after post_content. Any
 * drift fails closed with zero writes. No attestation: Ring III now covers the
 * style, template, and template-part lanes, but post-blocks stays excluded
 * because its non-public post_content is incompatible with the public
 * subject-state contract an attestation verifier has to be able to re-read.
 */
final class PostBlocksApplyExecutor implements ExternalApplyExecutor {

	/**
	 * @param array<string, mixed> $entry
	 * @return array{target: array<string, mixed>, before: array<string, string>, after: array<string, mixed>}|\WP_Error
	 */
	public static function execute( array $entry ): array|\WP_Error {
		$post_id = self::post_id( $entry );
		$post    = ServerCollector::resolve_post_for_apply( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$before_content = (string) ( $post->post_content ?? '' );
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

		// Re-validate against a freshly collected live document target contract.
		// No filter seam: a governed write path must not be interceptable. Lock
		// exclusion re-applies here, so an op addressing a locked path is
		// rejected even if a stale allowlist offered it at request time.
		$context = ServerCollector::for_post_blocks( $post_id );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$validated = PostBlocksPrompt::validate_operations_for_apply( $operations, $context );

		if (
			[] === $validated['operations']
			|| count( $validated['operations'] ) !== count( $operations )
		) {
			return new \WP_Error(
				'flavor_agent_apply_operations_invalid',
				'One or more post-blocks operations failed re-validation against the live execution contract.',
				[
					'status'            => 409,
					'validationReasons' => $validated['reasons'],
				]
			);
		}

		$executable_operations = StructuralOperationsApplier::restore_requested_expected_targets(
			$validated['operations'],
			$operations
		);

		$blocks = StructuralOperationsApplier::apply_operations( parse_blocks( $before_content ), $executable_operations );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		$after_content = serialize_blocks( $blocks );

		// Final concurrency gate (parity with TemplatePartApplyExecutor):
		// re-resolve the live post immediately before the write and fail closed
		// if its content moved since the start-of-execute read, so a concurrent
		// editor save in the read -> write window is never silently overwritten.
		$unchanged = self::assert_post_unchanged( $post_id, $before_hash );

		if ( is_wp_error( $unchanged ) ) {
			return $unchanged;
		}

		$persisted = self::persist( $post_id, $after_content );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		$persisted_content = self::resolve_persisted_content( $persisted );

		if ( is_wp_error( $persisted_content ) ) {
			return $persisted_content;
		}

		return [
			'target' => [
				'postId'   => $post_id,
				'postType' => (string) ( $post->post_type ?? '' ),
				'title'    => (string) ( $post->post_title ?? '' ),
			],
			'before' => [ 'content' => $before_content ],
			'after'  => [
				// The read-back, not the locally serialized string: see
				// resolve_persisted_content() for why recording the latter makes
				// the apply permanently unrevertable.
				'content'    => $persisted_content,
				'operations' => $executable_operations,
			],
		];
	}

	/**
	 * Read back what wp_update_post actually stored.
	 *
	 * A save filter can rewrite post_content on write — wp_filter_post_kses runs
	 * whenever the approving user lacks unfiltered_html (every non-super-admin on
	 * multisite, and everyone under DISALLOW_UNFILTERED_HTML), and any third-party
	 * content_save_pre / wp_insert_post_data filter has the same effect. Recording
	 * the locally serialized string instead would leave the activity row
	 * describing content that is not on the site, and undo's
	 * hash_equals( $live_hash, $after_hash ) guard would never match again, so the
	 * governed apply could never be reversed through the plugin's own undo path.
	 *
	 * Parity with TemplateApplyExecutor / TemplatePartApplyExecutor.
	 */
	private static function resolve_persisted_content( int $post_id ): string|\WP_Error {
		$post = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) ) {
			return new \WP_Error(
				'flavor_agent_apply_post_write_read_failed',
				'Flavor Agent wrote the post but could not confirm its persisted content.',
				[ 'status' => 500 ]
			);
		}

		return (string) ( $post->post_content ?? '' );
	}

	/**
	 * Re-resolve the live post and return the gate-2 drift baseline: the
	 * sha256 of its parsed -> reserialized live post_content. Identical recipe
	 * to PostBlocksContextCollector::content_hash / baselineContentHash.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function resolve_baseline( array $entry ): string|\WP_Error {
		$post = ServerCollector::resolve_post_for_apply( self::post_id( $entry ) );

		return is_wp_error( $post )
			? $post
			: self::content_hash( (string) ( $post->post_content ?? '' ) );
	}

	/**
	 * Server-side undo with the exact equality semantics the template lanes
	 * use: re-resolve the live post, then live == before → already undone (no
	 * write); live != after → drift failure (fail closed, no write); else
	 * restore the before snapshot. Hashes compare parsed -> reserialized
	 * content so insignificant serialization differences never read as drift.
	 *
	 * @param array<string, mixed> $entry Hydrated activity entry.
	 * @return array{result: string}|\WP_Error
	 */
	public static function undo( array $entry ): array|\WP_Error {
		$post_id = self::post_id( $entry );
		$post    = ServerCollector::resolve_post_for_apply( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
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

		$live_hash   = self::content_hash( (string) ( $post->post_content ?? '' ) );
		$before_hash = self::content_hash( (string) $before['content'] );
		$after_hash  = self::content_hash( (string) $after['content'] );

		if ( hash_equals( $live_hash, $before_hash ) ) {
			return [ 'result' => 'already_undone' ];
		}

		if ( ! hash_equals( $live_hash, $after_hash ) ) {
			return new \WP_Error(
				'flavor_agent_undo_drift',
				'The post changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
				[ 'status' => 409 ]
			);
		}

		// Final concurrency gate before the restore write: re-resolve and fail
		// closed if the live post moved after the drift check but before the write.
		$unchanged = self::assert_post_unchanged( $post_id, $live_hash );

		if ( is_wp_error( $unchanged ) ) {
			return $unchanged;
		}

		$persisted = self::persist( $post_id, (string) $before['content'] );

		return is_wp_error( $persisted ) ? $persisted : [ 'result' => 'undone' ];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function post_id( array $entry ): int {
		$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];

		return (int) ( $target['postId'] ?? 0 );
	}

	private static function content_hash( string $content ): string {
		return hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Final concurrency gate: re-resolve the live post immediately before a
	 * write and fail closed if its parsed -> reserialized content hash moved
	 * since the value captured at the start of the operation.
	 *
	 * @return true|\WP_Error
	 */
	private static function assert_post_unchanged( int $post_id, string $expected_hash ): true|\WP_Error {
		$current = ServerCollector::resolve_post_for_apply( $post_id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( ! hash_equals( self::content_hash( (string) ( $current->post_content ?? '' ) ), $expected_hash ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_changed',
				'The post changed before Flavor Agent could persist this operation. Regenerate the request and try again.',
				[ 'status' => 409 ]
			);
		}

		return true;
	}

	/**
	 * Persist the mutated content through wp_update_post. Creating a revision
	 * is expected and desirable (extra recovery evidence). Fails closed;
	 * invalidates the post cache after every write.
	 *
	 * @return int|\WP_Error The persisted post id.
	 */
	private static function persist( int $post_id, string $content ): int|\WP_Error {
		$updated = wp_update_post(
			[
				'ID'           => $post_id,
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
				'Flavor Agent could not write the post entity.',
				[ 'status' => 500 ]
			);
		}

		clean_post_cache( (int) $updated );

		return (int) $updated;
	}
}
