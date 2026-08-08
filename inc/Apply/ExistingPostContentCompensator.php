<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Owner-safe compensation for an existing block entity whose post-write
 * canonical identity could not be proven.
 */
final class ExistingPostContentCompensator {

	/**
	 * Restore only the content written by this request. The exact written value
	 * stays in the SQL predicate so a later editor save always wins the CAS.
	 *
	 * @return true|\WP_Error
	 */
	public static function restore(
		int $post_id,
		string $post_type,
		string $written_content,
		string $before_content,
		string $surface,
		MaterializationWriteContext $context
	): true|\WP_Error {
		$database    = $context->database();
		$posts_table = $context->posts_table();

		if (
			$post_id <= 0
			|| '' === $post_type
			|| ! is_callable( [ $database, 'prepare' ] )
			|| ! is_callable( [ $database, 'query' ] )
		) {
			return self::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not access the post store needed to restore the prior content.',
				500
			);
		}

		// Compare the exact bytes rather than the posts table's case/accent-
		// insensitive text collation. A collation-equivalent editor change must
		// never be mistaken for content owned by this request.
		try {
			$prefix_one   = bin2hex( random_bytes( 16 ) );
			$suffix_one   = bin2hex( random_bytes( 16 ) );
			$prefix_two   = bin2hex( random_bytes( 16 ) );
			$suffix_two   = bin2hex( random_bytes( 16 ) );
			$guard_marker = 'flavor-agent-content-restore-' . bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable ) {
			return self::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not create a guarded content-restoration predicate.',
				500
			);
		}

		$written_hex = strtoupper( bin2hex( $written_content ) );
		$digest_one  = md5( $prefix_one . $written_hex . $suffix_one );
		$digest_two  = md5( $prefix_two . $written_hex . $suffix_two );
		$sql         = $database->prepare(
			'UPDATE %i SET post_content = UNHEX(%s) WHERE ID = %d AND HEX(post_type) = %s'
			. ' AND OCTET_LENGTH(post_content) = %d'
			. ' AND MD5(CONCAT(%s, HEX(post_content), %s)) = %s'
			. ' AND MD5(CONCAT(%s, HEX(post_content), %s)) = %s'
			. ' /* ' . $guard_marker . ' */',
			$posts_table,
			strtoupper( bin2hex( $before_content ) ),
			$post_id,
			strtoupper( bin2hex( $post_type ) ),
			strlen( $written_content ),
			$prefix_one,
			$suffix_one,
			$digest_one,
			$prefix_two,
			$suffix_two,
			$digest_two
		);
		$diagnostics = GuardedQueryDiagnostics::begin( $database, $guard_marker );

		if ( ! $diagnostics instanceof GuardedQueryDiagnostics ) {
			return self::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not protect guarded content-restoration diagnostics.',
				500
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Owner-safe compensation must compare the exact just-written bytes at the database write boundary; $sql is prepared immediately above.
		try {
			$restored = $database->query( $sql );
		} finally {
			$diagnostics->finish();
		}

		if ( false === $restored ) {
			return self::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not restore the prior content after the target identity changed.',
				500
			);
		}

		// Zero changed rows can mean the exact prior bytes are already present.
		// Let the context-bound read-back prove that safe idempotent outcome.
		if ( ! in_array( (int) $restored, [ 0, 1 ], true ) ) {
			return self::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent did not restore prior content because the entity changed again. Reconcile it manually.',
				409
			);
		}

		if ( $context->matches_current() && function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		$post = $context->read_post_row( $post_id );

		if (
			! is_object( $post )
			|| $post_type !== (string) ( $post->post_type ?? '' )
			|| ! hash_equals( $before_content, (string) ( $post->post_content ?? '' ) )
		) {
			return self::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not verify that the prior content was restored. Reconcile it manually.',
				500
			);
		}

		return true;
	}

	public static function recovery_required( string $surface, int $post_id, string $message, int $status = 500 ): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_recovery_required',
			$message,
			[
				'status'           => $status,
				'recoveryRequired' => true,
				'surface'          => $surface,
				'postId'           => $post_id,
			]
		);
	}

	private function __construct() {}
}
