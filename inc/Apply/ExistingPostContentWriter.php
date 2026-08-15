<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Preserve WordPress's normal post-update filters/hooks while making the one
 * outer core posts-table UPDATE fail before mutation when its owner predicate
 * no longer matches.
 */
final class ExistingPostContentWriter {

	/**
	 * Core's posts-table fields that an existing-row Flavor Agent save is not
	 * authorized to change.
	 *
	 * @var list<string>
	 */
	private const UNCHANGED_FIELDS = [
		'post_author',
		'post_date',
		'post_date_gmt',
		'post_content_filtered',
		'post_title',
		'post_excerpt',
		'post_status',
		'post_type',
		'comment_status',
		'ping_status',
		'post_password',
		'post_name',
		'to_ping',
		'pinged',
		'post_parent',
		'menu_order',
		'post_mime_type',
		'guid',
	];

	/** @var list<string> */
	private const CORE_DATA_FIELDS = [
		'post_author',
		'post_date',
		'post_date_gmt',
		'post_content',
		'post_content_filtered',
		'post_title',
		'post_excerpt',
		'post_status',
		'post_type',
		'comment_status',
		'ping_status',
		'post_password',
		'post_name',
		'to_ping',
		'pinged',
		'post_modified',
		'post_modified_gmt',
		'post_parent',
		'menu_order',
		'post_mime_type',
		'guid',
	];

	/**
	 * @return array{content: string}|\WP_Error
	 */
	public static function update(
		int $post_id,
		string $post_type,
		string $expected_post_name,
		string $expected_content,
		string $next_content,
		string $surface,
		array $meta_input,
		MaterializationWriteContext $context
	): array|\WP_Error {
		if (
			$post_id <= 0
			|| '' === $post_type
			|| ! $context->matches_current()
			|| ! function_exists( 'add_action' )
			|| ! function_exists( 'remove_action' )
			|| ! function_exists( 'add_filter' )
			|| ! function_exists( 'remove_filter' )
			|| ! function_exists( 'wp_slash' )
			|| ! function_exists( 'wp_unslash' )
			|| ! function_exists( 'wp_update_post' )
		) {
			return self::write_failed();
		}

		$database    = $context->database();
		$posts_table = $context->posts_table();
		$owner       = $context->read_post_row( $post_id );
		$owner_data  = is_object( $owner ) ? self::owner_data( $owner ) : null;

		if (
			! is_object( $owner )
			|| ! is_array( $owner_data )
			|| $post_type !== (string) ( $owner->post_type ?? '' )
			|| $expected_post_name !== (string) ( $owner->post_name ?? '' )
			|| ! hash_equals( $expected_content, (string) ( $owner->post_content ?? '' ) )
		) {
			return self::target_changed();
		}

		$expected_post_status   = (string) ( $owner->post_status ?? '' );
		$expected_post_password = (string) ( $owner->post_password ?? '' );
		$meta_guard             = ExistingPostMetaWriter::capture(
			$post_id,
			$post_type,
			$expected_post_name,
			$expected_post_status,
			$expected_post_password,
			$surface,
			$meta_input,
			$context
		);

		if ( is_wp_error( $meta_guard ) ) {
			return $meta_guard;
		}

		$expected_update_depth = self::wp_update_post_depth() + 1;
		$expected_query_depth  = self::database_query_depth( $database ) + 1;
		$core_data             = null;
		$pre_update_seen       = false;
		$intercepted           = false;
		$guard_installed       = false;
		$guard_failed          = false;
		$identity_blocked      = false;
		$context_blocked       = false;
		$filtered_content      = null;
		$written_modified      = null;
		$written_modified_gmt  = null;
		$write_exception       = null;
		$diagnostics           = null;

		try {
			$guard_marker = 'flavor-agent-existing-write-' . bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable ) {
			return self::write_failed();
		}

		$query_filter      = static function ( string $query ) use (
			$database,
			$posts_table,
			$post_id,
			$post_type,
			$expected_post_name,
			$expected_post_status,
			$expected_post_password,
			$expected_content,
			$owner_data,
			$context,
			$guard_marker,
			$expected_update_depth,
			$expected_query_depth,
			&$intercepted,
			&$guard_installed,
			&$guard_failed,
			&$identity_blocked,
			&$context_blocked,
			&$filtered_content,
			&$written_modified,
			&$written_modified_gmt,
			&$diagnostics
		): string {
			if (
				$expected_update_depth !== self::wp_update_post_depth()
				|| self::is_read_only_query( $query )
			) {
				return $query;
			}

			if ( $intercepted ) {
				if ( ! $context->matches_current() ) {
					$context_blocked = true;
				}

				return $query;
			}

			if ( ! $context->matches_current() ) {
				$context_blocked = true;

				return '';
			}

			if ( ! self::is_direct_core_post_update_query( $database, $expected_query_depth ) ) {
				return $query;
			}

			$intercepted = true;

			if ( $identity_blocked || $context_blocked || $guard_failed ) {
				$guard_failed = ! $identity_blocked && ! $context_blocked;

				return '';
			}

			$guarded = self::build_guarded_update(
				$query,
				$database,
				$posts_table,
				$post_id,
				$post_type,
				$expected_post_name,
				$expected_post_status,
				$expected_post_password,
				$expected_content,
				is_string( $filtered_content ) ? $filtered_content : '',
				(string) $owner_data['post_modified'],
				(string) $owner_data['post_modified_gmt'],
				is_string( $written_modified ) ? $written_modified : '',
				is_string( $written_modified_gmt ) ? $written_modified_gmt : '',
				$guard_marker
			);

			if ( ! is_string( $guarded ) ) {
				$guard_failed = true;

				return '';
			}

			$diagnostics = GuardedQueryDiagnostics::begin( $database, $guard_marker );

			if ( ! $diagnostics instanceof GuardedQueryDiagnostics ) {
				$guard_failed = true;

				return '';
			}

			$guard_installed = true;

			return $guarded;
		};
		$core_data_filter  = static function (
			array $data,
			array $postarr,
			array $unsanitized_postarr,
			bool $update
		) use (
			$post_id,
			$expected_update_depth,
			&$core_data
		): array {
			unset( $unsanitized_postarr );

			if (
				$update
				&& null === $core_data
				&& $expected_update_depth === self::wp_update_post_depth()
				&& $post_id === (int) ( $postarr['ID'] ?? 0 )
			) {
				$normalized = wp_unslash( $data );
				$core_data  = is_array( $normalized ) ? $normalized : null;
			}

			return $data;
		};
		$pre_update_action = static function (
			int $observed_post_id,
			array $data
		) use (
			$database,
			$posts_table,
			$post_id,
			$owner_data,
			$context,
			$expected_update_depth,
			$query_filter,
			$meta_guard,
			&$core_data,
			&$pre_update_seen,
			&$guard_failed,
			&$identity_blocked,
			&$context_blocked,
			&$filtered_content,
			&$written_modified,
			&$written_modified_gmt
		): void {
			if (
				$post_id !== $observed_post_id
				|| $expected_update_depth !== self::wp_update_post_depth()
				|| $pre_update_seen
			) {
				return;
			}

			$pre_update_seen = true;
			$context_blocked = ! $context->matches_current()
				|| $posts_table !== (string) ( $database->posts ?? '' );
			$validated       = self::validate_core_update_data( $data, $core_data, $owner_data );

			if ( ! is_array( $validated ) ) {
				$identity_blocked = true;
				$filtered_content = null;
			} else {
				$filtered_content     = $validated['content'];
				$written_modified     = $validated['post_modified'];
				$written_modified_gmt = $validated['post_modified_gmt'];
			}

			add_filter( 'query', $query_filter, PHP_INT_MAX );

			if (
				is_string( $filtered_content )
				&& is_string( $written_modified )
				&& is_string( $written_modified_gmt )
				&& ! $meta_guard->install( $filtered_content, $written_modified, $written_modified_gmt )
			) {
				$guard_failed = true;
			}
		};

		add_filter( 'wp_insert_post_data', $core_data_filter, PHP_INT_MIN, 4 );
		add_filter( 'wp_insert_attachment_data', $core_data_filter, PHP_INT_MIN, 4 );
		add_action( 'pre_post_update', $pre_update_action, PHP_INT_MAX, 2 );

		$postarr = [
			'ID'           => $post_id,
			'post_content' => $next_content,
		];

		if ( [] !== $meta_input ) {
			$postarr['meta_input'] = $meta_input;
		}

		try {
			$updated = wp_update_post( wp_slash( $postarr ), true );
		} catch ( \Throwable $exception ) {
			$write_exception = $exception;
			$updated         = null;
		} finally {
			remove_filter( 'wp_insert_post_data', $core_data_filter, PHP_INT_MIN );
			remove_filter( 'wp_insert_attachment_data', $core_data_filter, PHP_INT_MIN );
			remove_action( 'pre_post_update', $pre_update_action, PHP_INT_MAX );
			remove_filter( 'query', $query_filter, PHP_INT_MAX );
			$meta_guard->finish();

			if ( $diagnostics instanceof GuardedQueryDiagnostics ) {
				$diagnostics->finish();
			}
		}

		if ( $write_exception instanceof \Throwable ) {
			if ( $guard_installed ) {
				return ExistingPostContentCompensator::recovery_required(
					$surface,
					$post_id,
					'Flavor Agent could not prove the final state after the guarded existing-row write was interrupted.'
				);
			}

			throw $write_exception;
		}

		if ( $identity_blocked ) {
			return self::target_changed();
		}

		if ( $context_blocked || $guard_failed ) {
			if ( $intercepted && ! is_wp_error( $updated ) ) {
				return ExistingPostContentCompensator::recovery_required(
					$surface,
					$post_id,
					'Flavor Agent wrote the block entity but could not prove the complete guarded save lifecycle.'
				);
			}

			return self::write_failed();
		}

		if ( ! $context->matches_current() ) {
			return ExistingPostContentCompensator::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not prove the target site context after the guarded existing-row write.'
			);
		}

		if ( is_wp_error( $updated ) ) {
			if ( $guard_installed ) {
				if ( function_exists( 'clean_post_cache' ) ) {
					clean_post_cache( $post_id );
				}

				$post = $context->read_post_row( $post_id );

				if ( ! is_object( $post ) ) {
					return ExistingPostContentCompensator::recovery_required(
						$surface,
						$post_id,
						'Flavor Agent could not read back the target after the guarded existing-row write failed.'
					);
				}

				$identity_matches  = $post_type === (string) ( $post->post_type ?? '' )
					&& $expected_post_name === (string) ( $post->post_name ?? '' )
					&& $expected_post_status === (string) ( $post->post_status ?? '' )
					&& $expected_post_password === (string) ( $post->post_password ?? '' );
				$read_content      = (string) ( $post->post_content ?? '' );
				$read_modified     = (string) ( $post->post_modified ?? '' );
				$read_modified_gmt = (string) ( $post->post_modified_gmt ?? '' );

				if (
					$identity_matches
					&& hash_equals( $expected_content, $read_content )
					&& hash_equals( (string) $owner_data['post_modified'], $read_modified )
					&& hash_equals( (string) $owner_data['post_modified_gmt'], $read_modified_gmt )
				) {
					return self::write_failed();
				}

				if (
					$identity_matches
					&& is_string( $filtered_content )
					&& hash_equals( $filtered_content, $read_content )
				) {
					return ExistingPostContentCompensator::recovery_required(
						$surface,
						$post_id,
						'Flavor Agent could not disambiguate whether the guarded existing-row write reached the database.'
					);
				}

				return self::target_changed();
			}

			return $updated;
		}

		if ( ! $pre_update_seen || ! $intercepted || ! $guard_installed ) {
			return ExistingPostContentCompensator::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not prove that the existing-row write used its conditional database guard.'
			);
		}

		if (
			(int) $updated !== $post_id
			|| ! is_string( $filtered_content )
			|| ! is_string( $written_modified )
			|| ! is_string( $written_modified_gmt )
		) {
			return ExistingPostContentCompensator::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not prove the result returned after the guarded existing-row write.'
			);
		}

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		$post = $context->read_post_row( $post_id );

		if ( ! is_object( $post ) ) {
			return ExistingPostContentCompensator::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent could not read back the existing-row write after its conditional database query.'
			);
		}

		if (
			$post_type !== (string) ( $post->post_type ?? '' )
			|| $expected_post_name !== (string) ( $post->post_name ?? '' )
			|| $expected_post_status !== (string) ( $post->post_status ?? '' )
			|| $expected_post_password !== (string) ( $post->post_password ?? '' )
			|| ! hash_equals( $filtered_content, (string) ( $post->post_content ?? '' ) )
			|| ! hash_equals( $written_modified, (string) ( $post->post_modified ?? '' ) )
			|| ! hash_equals( $written_modified_gmt, (string) ( $post->post_modified_gmt ?? '' ) )
		) {
			return ExistingPostContentCompensator::recovery_required(
				$surface,
				$post_id,
				'Flavor Agent wrote the block entity but its target identity or content changed before final readback.',
				409
			);
		}

		$metadata_persisted = $meta_guard->verify();

		if ( is_wp_error( $metadata_persisted ) ) {
			return $metadata_persisted;
		}

		return [ 'content' => $filtered_content ];
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function owner_data( object $owner ): ?array {
		$values = [];

		foreach ( self::CORE_DATA_FIELDS as $field ) {
			if ( ! property_exists( $owner, $field ) ) {
				return null;
			}

			$value = $owner->{$field};

			if ( ! is_scalar( $value ) && null !== $value ) {
				return null;
			}

			$values[ $field ] = (string) $value;
		}

		return $values;
	}

	/**
	 * Compare both Core's pre-filter data and the final filtered data with the
	 * raw owner row. Only post_content and Core's own modified timestamps may
	 * differ from the captured row.
	 *
	 * @param array<string, mixed>  $data       Final data passed to pre_post_update.
	 * @param array<string, mixed>|null $core_data Core data captured before ordinary save filters.
	 * @param array<string, string> $owner_data Raw context-bound owner snapshot.
	 * @return array{content: string, post_modified: string, post_modified_gmt: string}|null
	 */
	private static function validate_core_update_data( array $data, ?array $core_data, array $owner_data ): ?array {
		if (
			! is_array( $core_data )
			|| ! self::has_exact_core_fields( $core_data )
			|| ! self::has_exact_core_fields( $data )
			|| ! is_string( $data['post_content'] )
			|| ! is_string( $core_data['post_modified'] )
			|| ! is_string( $core_data['post_modified_gmt'] )
			|| ! is_string( $data['post_modified'] )
			|| ! is_string( $data['post_modified_gmt'] )
			|| '' === $core_data['post_modified']
			|| '' === $core_data['post_modified_gmt']
			|| ! hash_equals( $core_data['post_modified'], $data['post_modified'] )
			|| ! hash_equals( $core_data['post_modified_gmt'], $data['post_modified_gmt'] )
		) {
			return null;
		}

		foreach ( self::UNCHANGED_FIELDS as $field ) {
			if (
				! array_key_exists( $field, $owner_data )
				|| ( ! is_scalar( $core_data[ $field ] ) && null !== $core_data[ $field ] )
				|| ( ! is_scalar( $data[ $field ] ) && null !== $data[ $field ] )
				|| ! hash_equals( $owner_data[ $field ], (string) $core_data[ $field ] )
				|| ! hash_equals( $owner_data[ $field ], (string) $data[ $field ] )
			) {
				return null;
			}
		}

		return [
			'content'           => $data['post_content'],
			'post_modified'     => $data['post_modified'],
			'post_modified_gmt' => $data['post_modified_gmt'],
		];
	}

	/** @param array<string, mixed> $data */
	private static function has_exact_core_fields( array $data ): bool {
		$actual   = array_keys( $data );
		$expected = self::CORE_DATA_FIELDS;

		if ( count( $actual ) !== count( $expected ) ) {
			return false;
		}

		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );

		return $actual === $expected;
	}

	private static function is_read_only_query( string $query ): bool {
		return 1 === preg_match(
			'/^\s*(?:(?:\/\*.*?\*\/|--[^\r\n]*|#[^\r\n]*)\s*)*(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|PRAGMA)\b/is',
			$query
		);
	}

	private static function build_guarded_update(
		string $query,
		object $database,
		string $posts_table,
		int $post_id,
		string $post_type,
		string $expected_post_name,
		string $expected_post_status,
		string $expected_post_password,
		string $expected_content,
		string $next_content,
		string $expected_modified,
		string $expected_modified_gmt,
		string $next_modified,
		string $next_modified_gmt,
		string $guard_marker
	): ?string {
		$table_pattern = preg_quote( $posts_table, '/' );
		$id_pattern    = preg_quote( (string) $post_id, '/' );

		if (
			1 !== preg_match(
				'/^\s*UPDATE\s+`?' . $table_pattern . '`?\s+SET\s+(.+)\s+WHERE\s+`?ID`?\s*=\s*[\'\"]?' . $id_pattern . '[\'\"]?\s*;?\s*$/is',
				$query,
				$matches
			)
		) {
			return null;
		}

		$update_list = trim( (string) ( $matches[1] ?? '' ) );

		if ( '' === $update_list ) {
			return null;
		}

		try {
			$prefix_one = bin2hex( random_bytes( 16 ) );
			$suffix_one = bin2hex( random_bytes( 16 ) );
			$prefix_two = bin2hex( random_bytes( 16 ) );
			$suffix_two = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}

		$expected_hex     = strtoupper( bin2hex( $expected_content ) );
		$digest_one       = md5( $prefix_one . $expected_hex . $suffix_one );
		$digest_two       = md5( $prefix_two . $expected_hex . $suffix_two );
		$guard_assignment = $database->prepare(
			'`ID` = CASE WHEN HEX(`post_type`) = %s AND HEX(`post_name`) = %s'
			. ' AND HEX(`post_status`) = %s AND HEX(`post_password`) = %s'
			. ' AND HEX(`post_modified`) = %s AND HEX(`post_modified_gmt`) = %s'
			. ' AND OCTET_LENGTH(`post_content`) = %d'
			. ' AND MD5(CONCAT(%s, HEX(`post_content`), %s)) = %s'
			. ' AND MD5(CONCAT(%s, HEX(`post_content`), %s)) = %s'
			. ' THEN `ID` ELSE ABS(-9223372036854775808) END',
			strtoupper( bin2hex( $post_type ) ),
			strtoupper( bin2hex( $expected_post_name ) ),
			strtoupper( bin2hex( $expected_post_status ) ),
			strtoupper( bin2hex( $expected_post_password ) ),
			strtoupper( bin2hex( $expected_modified ) ),
			strtoupper( bin2hex( $expected_modified_gmt ) ),
			strlen( $expected_content ),
			$prefix_one,
			$suffix_one,
			$digest_one,
			$prefix_two,
			$suffix_two,
			$digest_two
		);

		if ( ! is_string( $guard_assignment ) ) {
			return null;
		}

		$content_assignment      = $database->prepare( '`post_content` = %s', $next_content );
		$modified_assignment     = $database->prepare( '`post_modified` = %s', $next_modified );
		$modified_gmt_assignment = $database->prepare( '`post_modified_gmt` = %s', $next_modified_gmt );

		if (
			! is_string( $content_assignment )
			|| ! is_string( $modified_assignment )
			|| ! is_string( $modified_gmt_assignment )
		) {
			return null;
		}

		$quoted_table = '`' . str_replace( '`', '``', $posts_table ) . '`';

		return 'UPDATE ' . $quoted_table
			. ' SET ' . $guard_assignment . ', ' . $content_assignment
			. ', ' . $modified_assignment . ', ' . $modified_gmt_assignment
			. ' WHERE `ID` = ' . $post_id
			. ' /* ' . $guard_marker . ' */';
	}

	private static function wp_update_post_depth(): int {
		$depth = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own post update apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( 'wp_update_post' === (string) ( $frame['function'] ?? '' ) ) {
				++$depth;
			}
		}

		return $depth;
	}

	private static function database_query_depth( object $database ): int {
		$query_depth  = 0;
		$update_depth = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own post update apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( $database !== ( $frame['object'] ?? null ) ) {
				continue;
			}

			if ( 'query' === (string) ( $frame['function'] ?? '' ) ) {
				++$query_depth;
			} elseif ( 'update' === (string) ( $frame['function'] ?? '' ) ) {
				++$update_depth;
			}
		}

		// The unit-test wpdb double invokes the query filter directly from
		// update(); production wpdb always routes it through query().
		return $query_depth > 0 ? $query_depth : $update_depth;
	}

	private static function is_direct_core_post_update_query( object $database, int $expected_query_depth ): bool {
		if ( $expected_query_depth !== self::database_query_depth( $database ) ) {
			return false;
		}

		$seen_database_update = false;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own post update apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( ! $seen_database_update ) {
				if ( $database === ( $frame['object'] ?? null ) && 'update' === (string) ( $frame['function'] ?? '' ) ) {
					$seen_database_update = true;
				}

				continue;
			}

			if (
				'WP_Hook' === (string) ( $frame['class'] ?? '' )
				|| in_array( (string) ( $frame['function'] ?? '' ), [ 'do_action', 'do_action_ref_array', 'apply_filters', 'call_user_func', 'call_user_func_array' ], true )
			) {
				return false;
			}

			if ( in_array( (string) ( $frame['function'] ?? '' ), [ 'wp_insert_post', 'wp_update_post' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function target_changed(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_target_changed',
			'The block entity changed before Flavor Agent could complete its conditional write.',
			[ 'status' => 409 ]
		);
	}

	private static function write_failed(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_write_failed',
			'Flavor Agent could not complete the guarded existing-row write.',
			[ 'status' => 500 ]
		);
	}

	private function __construct() {}
}
