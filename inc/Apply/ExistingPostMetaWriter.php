<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Guard Core's in-order meta_input update for one existing metadata row.
 *
 * WordPress does not enforce uniqueness for (post_id, meta_key), so an absent
 * key cannot be inserted with a portable compare-and-swap. Existing-row writes
 * are allowed only when exactly one raw row was captured before the post write.
 */
final class ExistingPostMetaWriter {

	/** @var array<string, array{meta_id: int, raw_value: ?string}> */
	private array $snapshot;

	/** @var array<string, string> */
	private array $observed_values = [];

	/** @var array<string, bool> */
	private array $seen = [];

	/** @var array<string, bool> */
	private array $intercepted = [];

	/** @var array<string, bool> */
	private array $succeeded = [];

	/** @var list<GuardedQueryDiagnostics> */
	private array $diagnostics = [];

	private bool $installed = false;

	private bool $failed = false;

	private ?string $active_key = null;

	private string $written_content = '';

	private string $written_modified = '';

	private string $written_modified_gmt = '';

	private ?\Closure $metadata_filter = null;

	private ?\Closure $add_metadata_filter = null;

	private ?\Closure $query_filter = null;

	private ?\Closure $before_update_action = null;

	private ?\Closure $after_update_action = null;

	/**
	 * @param array<string, mixed> $meta_input
	 */
	private function __construct(
		private readonly int $post_id,
		private readonly string $post_type,
		private readonly string $post_name,
		private readonly string $post_status,
		private readonly string $post_password,
		private readonly string $surface,
		private readonly array $meta_input,
		array $snapshot,
		private readonly MaterializationWriteContext $context
	) {
		$this->snapshot = $snapshot;
	}

	/**
	 * @param array<string, mixed> $meta_input
	 */
	public static function capture(
		int $post_id,
		string $post_type,
		string $post_name,
		string $post_status,
		string $post_password,
		string $surface,
		array $meta_input,
		MaterializationWriteContext $context
	): self|\WP_Error {
		if ( [] === $meta_input ) {
			return new self( $post_id, $post_type, $post_name, $post_status, $post_password, $surface, [], [], $context );
		}

		if ( $post_id <= 0 || '' === $post_type || ! $context->matches_current() ) {
			return self::write_failed();
		}

		$snapshot = [];

		foreach ( $meta_input as $key => $next_value ) {
			$key = (string) $key;

			if ( '' === $key || ! is_string( $next_value ) ) {
				return self::write_failed();
			}

			$rows = $context->read_post_meta_rows( $post_id, $key );

			if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
				return self::write_failed();
			}

			$row       = $rows[0];
			$meta_id   = (int) ( $row->meta_id ?? 0 );
			$raw_value = $row->meta_value ?? null;

			if ( $meta_id <= 0 || ( null !== $raw_value && ! is_string( $raw_value ) ) ) {
				return self::write_failed();
			}

			$snapshot[ $key ] = [
				'meta_id'   => $meta_id,
				'raw_value' => $raw_value,
			];
		}

		return $context->matches_current()
			? new self( $post_id, $post_type, $post_name, $post_status, $post_password, $surface, $meta_input, $snapshot, $context )
			: self::write_failed();
	}

	public function install( string $written_content, string $written_modified, string $written_modified_gmt ): bool {
		if ( [] === $this->meta_input ) {
			$this->written_content      = $written_content;
			$this->written_modified     = $written_modified;
			$this->written_modified_gmt = $written_modified_gmt;

			return true;
		}

		if (
			$this->installed
			|| ! $this->context->matches_current()
			|| ! function_exists( 'add_filter' )
			|| ! function_exists( 'remove_filter' )
			|| ! function_exists( 'add_action' )
			|| ! function_exists( 'remove_action' )
		) {
			return false;
		}

		$this->installed            = true;
		$this->written_content      = $written_content;
		$this->written_modified     = $written_modified;
		$this->written_modified_gmt = $written_modified_gmt;
		$database                   = $this->context->database();
		$postmeta_table             = $this->context->postmeta_table();
		$expected_update_depth      = self::wp_update_post_depth();

		$this->metadata_filter = function (
			mixed $check,
			int $object_id,
			string $meta_key,
			mixed $meta_value,
			mixed $prev_value
		) use ( $expected_update_depth ): mixed {
			if ( $this->post_id !== $object_id || ! array_key_exists( $meta_key, $this->meta_input ) ) {
				return $check;
			}

			if ( ! self::is_direct_core_metadata_call( $expected_update_depth ) ) {
				return $check;
			}

			$this->seen[ $meta_key ] = true;

			if (
				null !== $check
				|| '' !== $prev_value
				|| ! is_string( $meta_value )
				|| $meta_value !== $this->meta_input[ $meta_key ]
				|| null !== $this->active_key
				|| ! $this->context->matches_current()
			) {
				$this->failed = true;

				return false;
			}

			$this->active_key                   = $meta_key;
			$this->observed_values[ $meta_key ] = $meta_value;

			return null;
		};

		$this->add_metadata_filter = function (
			mixed $check,
			int $object_id,
			string $meta_key
		) use ( $expected_update_depth ): mixed {
			if (
				$this->post_id === $object_id
				&& array_key_exists( $meta_key, $this->meta_input )
				&& self::is_direct_core_metadata_call( $expected_update_depth )
			) {
				$this->failed     = true;
				$this->active_key = null;

				return false;
			}

			return $check;
		};

		$this->query_filter = function ( string $query ) use ( $database, $postmeta_table, $expected_update_depth ): string {
			if ( null === $this->active_key ) {
				return $query;
			}

			if ( ! self::is_direct_core_metadata_query( $database, $expected_update_depth ) ) {
				return $query;
			}

			$key           = $this->active_key;
			$table_pattern = preg_quote( $postmeta_table, '/' );

			if (
				$this->failed
				|| ! $this->context->matches_current()
				|| 1 !== preg_match( '/^\s*UPDATE\s+`?' . $table_pattern . '`?\s+SET\s+.+\s+WHERE\s+.+\s*$/is', $query )
			) {
				$this->failed     = true;
				$this->active_key = null;

				return '';
			}

			$guarded = $this->build_guarded_update( $key );

			if ( ! is_string( $guarded ) ) {
				$this->failed = true;

				return '';
			}

			try {
				$marker = 'flavor-agent-existing-meta-' . bin2hex( random_bytes( 8 ) );
			} catch ( \Throwable ) {
				$this->failed = true;

				return '';
			}

			$diagnostics = GuardedQueryDiagnostics::begin( $database, $marker );

			if ( ! $diagnostics instanceof GuardedQueryDiagnostics ) {
				$this->failed = true;

				return '';
			}

			$this->diagnostics[]       = $diagnostics;
			$this->intercepted[ $key ] = true;
			$this->active_key          = null;

			return $guarded . ' /* ' . $marker . ' */';
		};

		$this->before_update_action = function ( int $meta_id, int $object_id, string $meta_key ): void {
			if (
				$this->post_id !== $object_id
				|| ! array_key_exists( $meta_key, $this->meta_input )
			) {
				return;
			}

			if ( (int) $this->snapshot[ $meta_key ]['meta_id'] !== $meta_id ) {
				$this->failed = true;
			}

			remove_filter( 'query', $this->query_filter, PHP_INT_MAX );
			add_filter( 'query', $this->query_filter, PHP_INT_MAX );
		};

		$this->after_update_action = function ( int $meta_id, int $object_id, string $meta_key ): void {
			if (
				$this->post_id === $object_id
				&& array_key_exists( $meta_key, $this->meta_input )
				&& (int) $this->snapshot[ $meta_key ]['meta_id'] === $meta_id
				&& ( $this->intercepted[ $meta_key ] ?? false )
			) {
				$this->succeeded[ $meta_key ] = true;
			}
		};

		add_filter( 'update_post_metadata', $this->metadata_filter, PHP_INT_MAX, 5 );
		add_filter( 'add_post_metadata', $this->add_metadata_filter, PHP_INT_MAX, 5 );
		add_action( 'update_post_meta', $this->before_update_action, PHP_INT_MAX, 4 );
		add_action( 'updated_post_meta', $this->after_update_action, PHP_INT_MAX, 4 );
		add_filter( 'query', $this->query_filter, PHP_INT_MAX );

		return true;
	}

	public function finish(): void {
		if ( $this->installed ) {
			remove_filter( 'update_post_metadata', $this->metadata_filter, PHP_INT_MAX );
			remove_filter( 'add_post_metadata', $this->add_metadata_filter, PHP_INT_MAX );
			remove_action( 'update_post_meta', $this->before_update_action, PHP_INT_MAX );
			remove_action( 'updated_post_meta', $this->after_update_action, PHP_INT_MAX );
			remove_filter( 'query', $this->query_filter, PHP_INT_MAX );
			$this->installed = false;
		}

		foreach ( $this->diagnostics as $diagnostics ) {
			$diagnostics->finish();
		}
	}

	public function verify(): true|\WP_Error {
		if ( [] === $this->meta_input ) {
			return true;
		}

		if ( $this->failed || ! $this->context->matches_current() ) {
			return $this->recovery_required();
		}

		foreach ( $this->meta_input as $key => $_next_value ) {
			$key          = (string) $key;
			$observed     = $this->observed_values[ $key ] ?? null;
			$expected_raw = is_string( $observed ) ? self::serialize_meta_value( $observed ) : null;

			if ( ! ( $this->seen[ $key ] ?? false ) || ! is_string( $observed ) || ! is_string( $expected_raw ) ) {
				return $this->recovery_required();
			}

			$was_noop = ! ( $this->intercepted[ $key ] ?? false )
				&& $this->snapshot[ $key ]['raw_value'] === $expected_raw;

			if ( ! $was_noop && ! ( $this->succeeded[ $key ] ?? false ) ) {
				return $this->recovery_required();
			}

			$rows = $this->context->read_post_meta_rows( $this->post_id, $key );

			if (
				! is_array( $rows )
				|| 1 !== count( $rows )
				|| (int) ( $rows[0]->meta_id ?? 0 ) !== (int) $this->snapshot[ $key ]['meta_id']
				|| $expected_raw !== ( $rows[0]->meta_value ?? null )
			) {
				return $this->recovery_required();
			}
		}

		$post = $this->context->read_post_row( $this->post_id );

		return is_object( $post )
			&& $this->post_type === (string) ( $post->post_type ?? '' )
			&& $this->post_name === (string) ( $post->post_name ?? '' )
			&& $this->post_status === (string) ( $post->post_status ?? '' )
			&& $this->post_password === (string) ( $post->post_password ?? '' )
			&& hash_equals( $this->written_content, (string) ( $post->post_content ?? '' ) )
			&& hash_equals( $this->written_modified, (string) ( $post->post_modified ?? '' ) )
			&& hash_equals( $this->written_modified_gmt, (string) ( $post->post_modified_gmt ?? '' ) )
			? true
			: $this->recovery_required();
	}

	private function build_guarded_update( string $meta_key ): ?string {
		$snapshot   = $this->snapshot[ $meta_key ] ?? null;
		$observed   = $this->observed_values[ $meta_key ] ?? null;
		$next_value = is_string( $observed ) ? self::serialize_meta_value( $observed ) : null;

		if ( ! is_array( $snapshot ) || ! is_string( $next_value ) ) {
			return null;
		}

		$database       = $this->context->database();
		$metadata_guard = self::raw_value_guard( $database, '`meta_value`', $snapshot['raw_value'] );
		$content_guard  = self::raw_value_guard( $database, '`owner`.`post_content`', $this->written_content );

		if ( ! is_string( $metadata_guard ) || ! is_string( $content_guard ) ) {
			return null;
		}

		$identity_guard = $database->prepare(
			'`meta_id` = %d AND `post_id` = %d AND HEX(`meta_key`) = %s AND ' . $metadata_guard
			. ' AND EXISTS (SELECT 1 FROM %i AS `owner` WHERE `owner`.`ID` = %d'
			. ' AND HEX(`owner`.`post_type`) = %s AND HEX(`owner`.`post_name`) = %s'
			. ' AND HEX(`owner`.`post_status`) = %s AND HEX(`owner`.`post_password`) = %s'
			. ' AND HEX(`owner`.`post_modified`) = %s AND HEX(`owner`.`post_modified_gmt`) = %s'
			. ' AND ' . $content_guard . ')',
			(int) $snapshot['meta_id'],
			$this->post_id,
			strtoupper( bin2hex( $meta_key ) ),
			$this->context->posts_table(),
			$this->post_id,
			strtoupper( bin2hex( $this->post_type ) ),
			strtoupper( bin2hex( $this->post_name ) ),
			strtoupper( bin2hex( $this->post_status ) ),
			strtoupper( bin2hex( $this->post_password ) ),
			strtoupper( bin2hex( $this->written_modified ) ),
			strtoupper( bin2hex( $this->written_modified_gmt ) )
		);

		$set_assignment = $database->prepare( '`meta_value` = %s', $next_value );

		if ( ! is_string( $identity_guard ) || ! is_string( $set_assignment ) ) {
			return null;
		}

		$quoted_table = '`' . str_replace( '`', '``', $this->context->postmeta_table() ) . '`';

		return 'UPDATE ' . $quoted_table
			. ' SET ' . $set_assignment
			. ' WHERE ' . $identity_guard;
	}

	private static function serialize_meta_value( string $value ): ?string {
		if ( ! function_exists( 'maybe_serialize' ) ) {
			return null;
		}

		try {
			$serialized = maybe_serialize( $value );
		} catch ( \Throwable ) {
			return null;
		}

		return is_string( $serialized ) ? $serialized : null;
	}

	private static function raw_value_guard( object $database, string $column, ?string $expected ): ?string {
		if ( null === $expected ) {
			return $column . ' IS NULL';
		}

		try {
			$prefix_one = bin2hex( random_bytes( 16 ) );
			$suffix_one = bin2hex( random_bytes( 16 ) );
			$prefix_two = bin2hex( random_bytes( 16 ) );
			$suffix_two = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}

		$expected_hex = strtoupper( bin2hex( $expected ) );

		return $database->prepare(
			'OCTET_LENGTH(' . $column . ') = %d'
			. ' AND MD5(CONCAT(%s, HEX(' . $column . '), %s)) = %s'
			. ' AND MD5(CONCAT(%s, HEX(' . $column . '), %s)) = %s',
			strlen( $expected ),
			$prefix_one,
			$suffix_one,
			md5( $prefix_one . $expected_hex . $suffix_one ),
			$prefix_two,
			$suffix_two,
			md5( $prefix_two . $expected_hex . $suffix_two )
		);
	}

	private static function is_direct_core_metadata_call( int $expected_update_depth ): bool {
		if ( $expected_update_depth !== self::wp_update_post_depth() ) {
			return false;
		}

		$seen_update_metadata = false;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own metadata update apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( ! $seen_update_metadata ) {
				if ( 'update_metadata' === (string) ( $frame['function'] ?? '' ) ) {
					$seen_update_metadata = true;
				}

				continue;
			}

			if ( self::is_hook_boundary( $frame ) ) {
				return false;
			}

			if ( in_array( (string) ( $frame['function'] ?? '' ), [ 'wp_insert_post', 'wp_update_post' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_direct_core_metadata_query( object $database, int $expected_update_depth ): bool {
		if ( $expected_update_depth !== self::wp_update_post_depth() ) {
			return false;
		}

		$seen_database_update = false;
		$seen_update_metadata = false;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own metadata update apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( ! $seen_database_update ) {
				if ( $database === ( $frame['object'] ?? null ) && 'update' === (string) ( $frame['function'] ?? '' ) ) {
					$seen_database_update = true;
				}

				continue;
			}

			if ( ! $seen_update_metadata ) {
				if ( self::is_hook_boundary( $frame ) ) {
					return false;
				}

				if ( 'update_metadata' === (string) ( $frame['function'] ?? '' ) ) {
					$seen_update_metadata = true;
				}

				continue;
			}

			if ( self::is_hook_boundary( $frame ) ) {
				return false;
			}

			if ( in_array( (string) ( $frame['function'] ?? '' ), [ 'wp_insert_post', 'wp_update_post' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function wp_update_post_depth(): int {
		$depth = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own metadata update apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( 'wp_update_post' === (string) ( $frame['function'] ?? '' ) ) {
				++$depth;
			}
		}

		return $depth;
	}

	/** @param array<string, mixed> $frame */
	private static function is_hook_boundary( array $frame ): bool {
		return 'WP_Hook' === (string) ( $frame['class'] ?? '' )
			|| in_array(
				(string) ( $frame['function'] ?? '' ),
				[ 'do_action', 'do_action_ref_array', 'apply_filters', 'call_user_func', 'call_user_func_array' ],
				true
			);
	}

	private static function write_failed(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_write_failed',
			'Flavor Agent could not bind an atomic existing-row Block Hooks metadata update.',
			[ 'status' => 500 ]
		);
	}

	private function recovery_required(): \WP_Error {
		return ExistingPostContentCompensator::recovery_required(
			$this->surface,
			$this->post_id,
			'Flavor Agent could not prove the guarded in-order Block Hooks metadata transition.',
			409
		);
	}
}
