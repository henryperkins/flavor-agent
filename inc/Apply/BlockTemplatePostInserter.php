<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Guard the actual outer wp_insert_post() posts-table INSERT against site/table
 * context drift caused by filters or pre-insert hooks.
 */
final class BlockTemplatePostInserter {

	/**
	 * @param array<string, mixed> $postarr
	 * @return int|\WP_Error
	 */
	public static function insert(
		array $postarr,
		string $surface,
		MaterializationWriteContext $context
	): int|\WP_Error {
		if (
			! $context->matches_current()
			|| ! function_exists( 'add_action' )
			|| ! function_exists( 'remove_action' )
			|| ! function_exists( 'add_filter' )
			|| ! function_exists( 'remove_filter' )
			|| ! function_exists( 'wp_insert_post' )
			|| ! function_exists( 'wp_slash' )
		) {
			return self::write_failed();
		}

		$database              = $context->database();
		$posts_table           = $context->posts_table();
		$expected_insert_depth = self::wp_insert_post_depth() + 1;
		$expected_query_depth  = self::database_query_depth( $database ) + 1;
		$intercepted           = false;
		$guard_failed          = false;
		$context_blocked       = false;
		$pre_insert_seen       = false;
		$query_filter          = static function ( string $query ) use (
			$database,
			$posts_table,
			$context,
			$expected_insert_depth,
			$expected_query_depth,
			&$intercepted,
			&$guard_failed,
			&$context_blocked
		): string {
			if (
				$expected_insert_depth !== self::wp_insert_post_depth()
				|| self::is_read_only_query( $query )
			) {
				return $query;
			}

			// The posts-table INSERT has already been proven, but wp_insert_post()
			// still performs taxonomy and metadata writes before it returns. Core
			// can capture a table name before a filter runs, so ambient context alone
			// cannot prove that those later writes still target the captured site.
			if ( $intercepted ) {
				// Hook callbacks may intentionally switch sites for their own writes.
				// Their SQL is outside this guard's ownership; Core's next direct side
				// effect and the final context check still require restoration.
				if ( ! self::is_direct_core_post_insert_query( $database, $expected_query_depth ) ) {
					return $query;
				}

				if ( ! $context->matches_current() ) {
					$context_blocked = true;

					return '';
				}

				if ( ! self::targets_captured_materialization_table( $query, $context ) ) {
					$guard_failed = true;

					return '';
				}

				return $query;
			}

			if ( ! $context->matches_current() ) {
				$context_blocked = true;

				return '';
			}

			if ( ! self::is_direct_core_post_insert_query( $database, $expected_query_depth ) ) {
				return $query;
			}

			$intercepted = true;

			$table_pattern = preg_quote( $posts_table, '/' );

			if ( 1 !== preg_match( '/^\s*INSERT\s+INTO\s+`?' . $table_pattern . '`?\s*[\s(]/i', $query ) ) {
				$guard_failed = true;

				return '';
			}

			return $query;
		};
		$pre_insert_action     = static function () use (
			$context,
			$query_filter,
			&$pre_insert_seen,
			&$context_blocked
		): void {
			if ( $pre_insert_seen ) {
				return;
			}

			$pre_insert_seen = true;
			$context_blocked = ! $context->matches_current();
			add_filter( 'query', $query_filter, PHP_INT_MAX );
		};

		add_action( 'pre_post_insert', $pre_insert_action, PHP_INT_MAX, 4 );

		try {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		} catch ( \Throwable $error ) {
			if ( $intercepted ) {
				return ExistingPostContentCompensator::recovery_required(
					$surface,
					0,
					'Flavor Agent could not prove the final state after the guarded materialization insert was interrupted.'
				);
			}

			throw $error;
		} finally {
			remove_action( 'pre_post_insert', $pre_insert_action, PHP_INT_MAX );
			remove_filter( 'query', $query_filter, PHP_INT_MAX );
		}

		$persisted_post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $intercepted && ( $context_blocked || $guard_failed ) && $persisted_post_id > 0 ) {
			return ExistingPostContentCompensator::recovery_required(
				$surface,
				$persisted_post_id,
				'Flavor Agent materialized the block entity but blocked a later side effect after the posts-table row existed.'
			);
		}

		if ( ! $context->matches_current() ) {
			if ( $context_blocked && is_wp_error( $post_id ) ) {
				return self::write_failed();
			}

			return ExistingPostContentCompensator::recovery_required(
				$surface,
				$persisted_post_id,
				'Flavor Agent could not prove the target site context after the guarded materialization insert.'
			);
		}

		if ( $context_blocked || $guard_failed ) {
			return self::write_failed();
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! $pre_insert_seen || ! $intercepted || (int) $post_id <= 0 ) {
			return ExistingPostContentCompensator::recovery_required(
				$surface,
				(int) $post_id,
				'Flavor Agent could not prove that materialization used its site-bound insert guard.'
			);
		}

		return (int) $post_id;
	}

	private static function is_read_only_query( string $query ): bool {
		return 1 === preg_match(
			'/^\s*(?:(?:\/\*.*?\*\/|--[^\r\n]*|#[^\r\n]*)\s*)*(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|PRAGMA)\b/is',
			$query
		);
	}

	private static function wp_insert_post_depth(): int {
		$depth = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own post insert apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( 'wp_insert_post' === (string) ( $frame['function'] ?? '' ) ) {
				++$depth;
			}
		}

		return $depth;
	}

	private static function database_query_depth( object $database ): int {
		$query_depth  = 0;
		$insert_depth = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own post insert apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( $database !== ( $frame['object'] ?? null ) ) {
				continue;
			}

			if ( 'query' === (string) ( $frame['function'] ?? '' ) ) {
				++$query_depth;
			} elseif ( 'insert' === (string) ( $frame['function'] ?? '' ) ) {
				++$insert_depth;
			}
		}

		return $query_depth > 0 ? $query_depth : $insert_depth;
	}

	private static function is_direct_core_post_insert_query( object $database, int $expected_query_depth ): bool {
		if ( $expected_query_depth !== self::database_query_depth( $database ) ) {
			return false;
		}

		$seen_database_query = false;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Reentrancy detection, not debug output: the guarded writer must tell Core's own post insert apart from a third-party hook callback before it allows the write.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( ! $seen_database_query ) {
				if ( $database === ( $frame['object'] ?? null ) && 'query' === (string) ( $frame['function'] ?? '' ) ) {
					$seen_database_query = true;
				}

				continue;
			}

			// Skip wpdb's query/insert/update/delete implementation frames. The
			// first non-database frames identify whether Core reached the mutation
			// directly or through a plugin/theme hook callback.
			if ( $database === ( $frame['object'] ?? null ) ) {
				continue;
			}

			if (
				'WP_Hook' === (string) ( $frame['class'] ?? '' )
				|| in_array( (string) ( $frame['function'] ?? '' ), [ 'do_action', 'do_action_ref_array', 'apply_filters', 'call_user_func', 'call_user_func_array' ], true )
			) {
				return false;
			}

			if ( 'wp_insert_post' === (string) ( $frame['function'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function targets_captured_materialization_table(
		string $query,
		MaterializationWriteContext $context
	): bool {
		$table = self::mutation_target_table( $query );

		if ( null === $table ) {
			return false;
		}

		return in_array(
			$table,
			[
				$context->posts_table(),
				$context->postmeta_table(),
				$context->terms_table(),
				$context->term_taxonomy_table(),
				$context->term_relationships_table(),
				$context->options_table(),
			],
			true
		);
	}

	private static function mutation_target_table( string $query ): ?string {
		$matched = preg_match(
			'/^\s*(?:(?:\/\*.*?\*\/|--[^\r\n]*|#[^\r\n]*)\s*)*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:`((?:``|[^`])+)`|([^\s(]+))/is',
			$query,
			$matches
		);

		if ( 1 !== $matched ) {
			return null;
		}

		if ( '' !== (string) ( $matches[1] ?? '' ) ) {
			return str_replace( '``', '`', (string) $matches[1] );
		}

		return rtrim( (string) ( $matches[2] ?? '' ), ';' );
	}

	private static function write_failed(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_write_failed',
			'Flavor Agent could not complete the guarded materialization insert.',
			[ 'status' => 500 ]
		);
	}

	private function __construct() {}
}
