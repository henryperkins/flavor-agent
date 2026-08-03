<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Strict per-target mutex for template and template-part persistence.
 *
 * Core's option helper is an upsert on current WordPress, so acquisition uses
 * wpdb::insert() directly: the options table's unique option_name index makes
 * one plain INSERT the atomic create-if-absent decision. The acquisition database
 * and options table remain bound to owner-qualified release even if hooks switch
 * blog context. Lock decisions bypass the option cache. Locks never expire or
 * transfer ownership; every normal path releases in finally, while an abandoned
 * or corrupt lock remains fail-closed for operator recovery.
 */
final class MaterializationLock {

	private const OPTION_PREFIX = 'flavor_agent_materialization_lock_';

	private bool $released = false;

	private function __construct(
		private readonly object $database,
		private readonly string $options_table,
		private readonly string $option_name,
		private readonly string $owner
	) {
	}

	public static function acquire( string $surface, string $canonical_ref ): self|\WP_Error {
		global $wpdb;

		if (
			! is_object( $wpdb )
			|| ! isset( $wpdb->options )
			|| ! is_callable( [ $wpdb, 'insert' ] )
			|| ! is_callable( [ $wpdb, 'prepare' ] )
			|| ! is_callable( [ $wpdb, 'get_var' ] )
			|| ! is_callable( [ $wpdb, 'delete' ] )
		) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not access the materialization lock store.',
				[ 'status' => 500 ]
			);
		}

		$database      = $wpdb;
		$options_table = (string) $database->options;

		if ( '' === $options_table ) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not access the materialization lock table.',
				[ 'status' => 500 ]
			);
		}

		$blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$option_name = self::OPTION_PREFIX . hash( 'sha256', $blog_id . "\0" . $surface . "\0" . $canonical_ref );

		try {
			$owner = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not create a materialization lock owner.',
				[ 'status' => 500 ]
			);
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$inserted = self::insert_owner( $database, $options_table, $option_name, $owner );

			if ( 1 === (int) $inserted ) {
				return new self( $database, $options_table, $option_name, $owner );
			}

			if ( null !== self::read_owner( $database, $options_table, $option_name ) ) {
				return new \WP_Error(
					'flavor_agent_apply_materialization_locked',
					'Another Flavor Agent request is applying to this target. Retry the apply.',
					[ 'status' => 409 ]
				);
			}
		}

		return new \WP_Error(
			'flavor_agent_apply_lock_unavailable',
			'Flavor Agent could not create the materialization lock row.',
			[ 'status' => 500 ]
		);
	}

	public function release(): void {
		if ( $this->released ) {
			return;
		}

		$this->released = true;

		$this->database->delete(
			$this->options_table,
			[
				'option_name'  => $this->option_name,
				'option_value' => $this->owner,
			],
			[ '%s', '%s' ]
		);
	}

	/**
	 * @return int|false
	 */
	private static function insert_owner( object $database, string $options_table, string $option_name, string $owner ): int|false {
		$restore_errors = is_callable( [ $database, 'suppress_errors' ] ) ? $database->suppress_errors( true ) : null;

		try {
			return $database->insert(
				$options_table,
				[
					'option_name'  => $option_name,
					'option_value' => $owner,
					'autoload'     => 'no',
				],
				[ '%s', '%s', '%s' ]
			);
		} finally {
			if ( null !== $restore_errors ) {
				$database->suppress_errors( $restore_errors );
			}
		}
	}

	private static function read_owner( object $database, string $options_table, string $option_name ): ?string {
		$value = $database->get_var(
			$database->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
				$options_table,
				$option_name
			)
		);

		return is_string( $value ) ? $value : null;
	}
}
