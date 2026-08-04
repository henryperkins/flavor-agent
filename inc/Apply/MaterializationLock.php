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
		private readonly MaterializationWriteContext $context,
		private readonly string $option_name,
		private readonly string $owner,
		private readonly string $acquired_at
	) {
	}

	public static function acquire( string $surface, string $canonical_ref ): self|\WP_Error {
		$context = MaterializationWriteContext::capture();

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$database      = $context->database();
		$options_table = $context->options_table();

		if (
			! is_callable( [ $database, 'insert' ] )
			|| ! is_callable( [ $database, 'get_var' ] )
			|| ! is_callable( [ $database, 'delete' ] )
		) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not access the materialization lock store.',
				[ 'status' => 500 ]
			);
		}

		$option_name = self::option_name( $surface, $canonical_ref, $context->blog_id() );
		$acquired_at = gmdate( 'c' );

		try {
			$owner_token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not create a materialization lock owner.',
				[ 'status' => 500 ]
			);
		}

		$owner = wp_json_encode(
			[
				'version'    => 1,
				'owner'      => $owner_token,
				'acquiredAt' => $acquired_at,
			]
		);

		if ( ! is_string( $owner ) || '' === $owner ) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not encode a materialization lock owner.',
				[ 'status' => 500 ]
			);
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$inserted = self::insert_owner( $database, $options_table, $option_name, $owner );

			if ( 1 === (int) $inserted ) {
				return new self( $context, $option_name, $owner, $acquired_at );
			}

			$current_owner = self::read_owner( $database, $options_table, $option_name );

			if ( null !== $current_owner ) {
				$metadata = self::owner_metadata( $current_owner );
				$data     = [
					'status'         => 409,
					'lockOptionName' => $option_name,
				];

				if ( isset( $metadata['acquiredAt'] ) ) {
					$data['acquiredAt'] = $metadata['acquiredAt'];
				}

				return new \WP_Error(
					'flavor_agent_apply_materialization_locked',
					'Another Flavor Agent request is applying to this target. Retry the apply.',
					$data
				);
			}
		}

		return new \WP_Error(
			'flavor_agent_apply_lock_unavailable',
			'Flavor Agent could not create the materialization lock row.',
			[ 'status' => 500 ]
		);
	}

	public function release(): bool {
		if ( $this->released ) {
			return true;
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$deleted = $this->context->database()->delete(
				$this->context->options_table(),
				[
					'option_name'  => $this->option_name,
					'option_value' => $this->owner,
				],
				[ '%s', '%s' ]
			);

			if ( 1 === (int) $deleted ) {
				$this->released = true;

				return true;
			}

			$current_owner = self::read_owner( $this->context->database(), $this->context->options_table(), $this->option_name );

			if (
				( false !== $deleted && null === $current_owner )
				|| ( null !== $current_owner && ! hash_equals( $this->owner, $current_owner ) )
			) {
				$this->released = true;

				return true;
			}
		}

		if ( function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A stranded strict lock needs an operator-visible diagnostic and contains no target content or owner token.
			error_log(
				'[Flavor Agent] ' . (string) wp_json_encode(
					[
						'event'          => 'materialization_lock_release_failed',
						'lockOptionName' => $this->option_name,
						'acquiredAt'     => $this->acquired_at,
					]
				)
			);
		}

		return false;
	}

	public function context(): MaterializationWriteContext {
		return $this->context;
	}

	/**
	 * Operator-only owner-qualified recovery after independently confirming that
	 * the request is inactive and reconciling the live target.
	 *
	 * @return true|\WP_Error
	 */
	public static function recover_abandoned( string $surface, string $canonical_ref, string $observed_owner ): true|\WP_Error {
		$context = MaterializationWriteContext::capture();

		if (
			'' === $surface
			|| '' === $canonical_ref
			|| '' === $observed_owner
			|| is_wp_error( $context )
		) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not access the materialization lock store for recovery.',
				[ 'status' => 500 ]
			);
		}

		$database      = $context->database();
		$options_table = $context->options_table();
		$option_name   = self::option_name( $surface, $canonical_ref, $context->blog_id() );
		$authorized    = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );

		if ( ! $context->matches_current() ) {
			return self::recovery_context_error( $option_name );
		}

		if ( ! $authorized ) {
			return new \WP_Error(
				'flavor_agent_apply_target_forbidden',
				'Only an administrator can recover an abandoned Flavor Agent materialization lock.',
				[ 'status' => 403 ]
			);
		}

		$current_owner = self::read_owner( $database, $options_table, $option_name );

		if ( ! $context->matches_current() ) {
			return self::recovery_context_error( $option_name );
		}

		if ( null === $current_owner || ! hash_equals( $observed_owner, $current_owner ) ) {
			return new \WP_Error(
				'flavor_agent_apply_lock_recovery_conflict',
				'Flavor Agent did not recover the lock because its observed owner is no longer current.',
				[
					'status'         => 409,
					'lockOptionName' => $option_name,
				]
			);
		}

		$deleted = $database->delete(
			$options_table,
			[
				'option_name'  => $option_name,
				'option_value' => $observed_owner,
			],
			[ '%s', '%s' ]
		);

		if ( false === $deleted ) {
			return new \WP_Error(
				'flavor_agent_apply_lock_unavailable',
				'Flavor Agent could not remove the observed abandoned materialization lock.',
				[
					'status'         => 500,
					'lockOptionName' => $option_name,
				]
			);
		}

		if ( 1 !== (int) $deleted ) {
			return new \WP_Error(
				'flavor_agent_apply_lock_recovery_conflict',
				'Flavor Agent did not recover the lock because the owner changed before deletion.',
				[
					'status'         => 409,
					'lockOptionName' => $option_name,
				]
			);
		}

		return true;
	}

	private static function recovery_context_error( string $option_name ): \WP_Error {
		return new \WP_Error(
			'flavor_agent_apply_lock_unavailable',
			'Flavor Agent stopped lock recovery because the site database context changed.',
			[
				'status'         => 500,
				'lockOptionName' => $option_name,
			]
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

	private static function option_name( string $surface, string $canonical_ref, int $blog_id ): string {
		return self::OPTION_PREFIX . hash( 'sha256', $blog_id . "\0" . $surface . "\0" . $canonical_ref );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function owner_metadata( string $owner ): array {
		$metadata = json_decode( $owner, true );

		if (
			! is_array( $metadata )
			|| 1 !== ( $metadata['version'] ?? null )
			|| ! is_string( $metadata['owner'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $metadata['owner'] )
			|| ! is_string( $metadata['acquiredAt'] ?? null )
			|| 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$/D', $metadata['acquiredAt'] )
		) {
			return [];
		}

		$timestamp = strtotime( $metadata['acquiredAt'] );

		if ( false === $timestamp || gmdate( 'c', $timestamp ) !== $metadata['acquiredAt'] ) {
			return [];
		}

		return [ 'acquiredAt' => $metadata['acquiredAt'] ];
	}
}
