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
	 * @return array{target: array{postId: int, postType: string}, document: array{entityId: string, postType: string, scopeKey: string}}|\WP_Error
	 */
	public static function resolve_target_identity( array $entry ): array|\WP_Error {
		$target  = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$post_id = self::canonical_post_id( $target['postId'] ?? null );

		if ( 'post-blocks' !== (string) ( $entry['surface'] ?? '' ) || null === $post_id ) {
			return self::target_mismatch();
		}

		$actual_post_type = function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : false;
		$stored_post_type = $target['postType'] ?? null;

		if (
			! is_string( $actual_post_type )
			|| '' === $actual_post_type
			|| ! is_string( $stored_post_type )
			|| '' === $stored_post_type
			|| $stored_post_type !== $actual_post_type
		) {
			return self::target_mismatch();
		}

		return [
			'target'   => [
				'postId'   => $post_id,
				'postType' => $actual_post_type,
			],
			'document' => [
				'entityId' => (string) $post_id,
				'postType' => $actual_post_type,
				'scopeKey' => $actual_post_type . ':' . $post_id,
			],
		];
	}

	/** @param array<string, mixed> $entry */
	public static function authorize_target( array $entry ): true|\WP_Error {
		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( ! self::has_matching_document( $entry, $identity['document'] ) ) {
			return self::target_mismatch();
		}

		return current_user_can( 'edit_post', $identity['target']['postId'] ) ? true : self::target_forbidden();
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{target: array<string, mixed>, before: array<string, string>, after: array<string, mixed>}|\WP_Error
	 */
	public static function execute( array $entry ): array|\WP_Error {
		$write_context = MaterializationWriteContext::capture();

		if ( is_wp_error( $write_context ) ) {
			return $write_context;
		}

		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$post_id = $identity['target']['postId'];
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
		$fresh = self::assert_post_unchanged( $post_id, $before_hash );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$authorized = self::authorize_target( $entry );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		if ( ! $write_context->matches_current() ) {
			return self::write_context_changed();
		}

		$persisted = self::persist(
			$post_id,
			$identity['target']['postType'],
			(string) ( $fresh->post_name ?? '' ),
			(string) ( $fresh->post_content ?? '' ),
			$after_content,
			$write_context
		);

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return [
			'target' => array_merge(
				$identity['target'],
				[ 'title' => (string) ( $fresh->post_title ?? '' ) ]
			),
			'before' => [ 'content' => $before_content ],
			'after'  => [
				// The guarded writer's read-back, not the locally serialized string:
				// save filters may rewrite content, and recording the local value can
				// make the apply permanently unrevertable.
				'content'    => $persisted['content'],
				'operations' => $executable_operations,
			],
		];
	}

	/**
	 * Re-resolve the live post and return the gate-2 drift baseline: the
	 * sha256 of its parsed -> reserialized live post_content. Identical recipe
	 * to PostBlocksContextCollector::content_hash / baselineContentHash.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function resolve_baseline( array $entry ): string|\WP_Error {
		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$post = ServerCollector::resolve_post_for_apply( $identity['target']['postId'] );

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
		$write_context = MaterializationWriteContext::capture();

		if ( is_wp_error( $write_context ) ) {
			return $write_context;
		}

		$identity = self::resolve_target_identity( $entry );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$post_id = $identity['target']['postId'];
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

		$authorized = self::authorize_target( $entry );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		if ( ! $write_context->matches_current() ) {
			return self::write_context_changed();
		}

		$persisted = self::persist(
			$post_id,
			$identity['target']['postType'],
			(string) ( $unchanged->post_name ?? '' ),
			(string) ( $unchanged->post_content ?? '' ),
			(string) $before['content'],
			$write_context
		);

		return is_wp_error( $persisted ) ? $persisted : [ 'result' => 'undone' ];
	}

	private static function canonical_post_id( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) || (string) (int) $value !== $value ) {
			return null;
		}

		return (int) $value;
	}

	/** @param array<string, mixed> $entry @param array{entityId: string, postType: string, scopeKey: string} $canonical */
	private static function has_matching_document( array $entry, array $canonical ): bool {
		$document = is_array( $entry['document'] ?? null ) ? $entry['document'] : [];

		foreach ( $canonical as $field => $value ) {
			if ( ! array_key_exists( $field, $document ) || $value !== $document[ $field ] ) {
				return false;
			}
		}

		return true;
	}

	private static function target_mismatch(): \WP_Error {
		return new \WP_Error( 'flavor_agent_apply_target_mismatch', 'The stored post target does not match its canonical document identity.', [ 'status' => 409 ] );
	}

	private static function target_forbidden(): \WP_Error {
		return new \WP_Error( 'flavor_agent_apply_target_forbidden', 'You are not allowed to apply changes to this post.', [ 'status' => 403 ] );
	}

	private static function content_hash( string $content ): string {
		return hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Final concurrency gate: re-resolve the live post immediately before a
	 * write and fail closed if its parsed -> reserialized content hash moved
	 * since the value captured at the start of the operation.
	 *
	 * @return object A post object or WP_Error.
	 */
	private static function assert_post_unchanged( int $post_id, string $expected_hash ): object {
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

		return $current;
	}

	/**
	 * Persist through core so save filters, revisions, and post lifecycle hooks
	 * retain their normal behavior. The shared writer binds the actual posts-table
	 * mutation to the exact content and canonical identity captured above.
	 *
	 * @return array{content: string}|\WP_Error
	 */
	private static function persist(
		int $post_id,
		string $post_type,
		string $post_name,
		string $expected_content,
		string $content,
		MaterializationWriteContext $context
	): array|\WP_Error {
		return ExistingPostContentWriter::update(
			$post_id,
			$post_type,
			$post_name,
			$expected_content,
			$content,
			'post-blocks',
			[],
			$context
		);
	}

	private static function write_context_changed(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_write_context_changed',
			'The target site database context changed before Flavor Agent could persist this post.',
			[ 'status' => 409 ]
		);
	}
}
