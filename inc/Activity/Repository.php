<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class Repository {

	public const SCHEMA_OPTION          = 'flavor_agent_activity_schema_version';
	public const SCHEMA_VERSION         = 1;
	public const PRUNE_CRON_HOOK        = 'flavor_agent_prune_activity';
	public const DEFAULT_RETENTION_DAYS = 90;

	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT     = 100;
	private const TABLE_SUFFIX  = 'flavor_agent_activity';

	public static function maybe_install(): void {
		$installed_version = (int) get_option( self::SCHEMA_OPTION, 0 );

		if (
			$installed_version >= self::SCHEMA_VERSION
			&& self::table_exists()
		) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return;
		}

		$table_name = self::table_name();
		$charset    = method_exists( $wpdb, 'get_charset_collate' )
			? (string) $wpdb->get_charset_collate()
			: '';
		$sql        = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			activity_id varchar(191) NOT NULL,
			schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			surface varchar(32) NOT NULL,
			entity_type varchar(32) NOT NULL,
			entity_ref varchar(191) NOT NULL,
			document_scope_key varchar(191) NOT NULL,
			activity_type varchar(64) NOT NULL,
			suggestion text NOT NULL,
			suggestion_key varchar(191) NULL,
			target_json longtext NOT NULL,
			before_state longtext NOT NULL,
			after_state longtext NOT NULL,
			undo_state longtext NOT NULL,
			request_json longtext NOT NULL,
			document_json longtext NOT NULL,
			execution_result varchar(32) NOT NULL DEFAULT 'applied',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY activity_id (activity_id),
			KEY surface (surface),
			KEY entity_lookup (entity_type, entity_ref),
			KEY document_scope_key (document_scope_key),
			KEY user_created (user_id, created_at),
			KEY created_at (created_at)
		) {$charset}";

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';

			if ( file_exists( $upgrade_file ) ) {
				require_once $upgrade_file;
			}
		}

		if ( function_exists( 'dbDelta' ) ) {
			\dbDelta( $sql );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal schema definition for plugin-owned table.
			$wpdb->query( $sql );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( array $entry ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return new \WP_Error(
				'flavor_agent_activity_storage_unavailable',
				'Flavor Agent activity storage is unavailable.',
				[ 'status' => 500 ]
			);
		}

		$normalized  = Serializer::normalize_entry( $entry );
		$activity_id = '' !== $normalized['id']
			? (string) $normalized['id']
			: self::generate_activity_id();
		$scope_key   = is_array( $normalized['document'] ) ? (string) ( $normalized['document']['scopeKey'] ?? '' ) : '';
		$surface     = (string) $normalized['surface'];

		if ( '' === $scope_key || '' === $surface ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'Flavor Agent activity entries require both a scope and a surface.',
				[ 'status' => 400 ]
			);
		}

		$existing_row = self::find_row( $activity_id );

		if ( is_array( $existing_row ) ) {
			return self::merge_existing_entry( $existing_row, $normalized );
		}

		$timestamp = Serializer::normalize_timestamp( $normalized['timestamp'] ?? null );
		$entity    = Serializer::derive_entity( $normalized );
		$record    = [
			'activity_id'        => $activity_id,
			'schema_version'     => (int) $normalized['schemaVersion'],
			'user_id'            => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'surface'            => $surface,
			'entity_type'        => $entity['type'],
			'entity_ref'         => $entity['ref'],
			'document_scope_key' => $scope_key,
			'activity_type'      => (string) $normalized['type'],
			'suggestion'         => (string) $normalized['suggestion'],
			'suggestion_key'     => $normalized['suggestionKey'],
			'target_json'        => Serializer::encode_json( $normalized['target'] ),
			'before_state'       => Serializer::encode_json( $normalized['before'] ),
			'after_state'        => Serializer::encode_json( $normalized['after'] ),
			'undo_state'         => Serializer::encode_json( $normalized['undo'] ),
			'request_json'       => Serializer::encode_json( $normalized['request'] ),
			'document_json'      => Serializer::encode_json( $normalized['document'] ),
			'execution_result'   => (string) $normalized['executionResult'],
			'created_at'         => Serializer::mysql_datetime_from_timestamp( $timestamp ),
			'updated_at'         => Serializer::mysql_datetime_from_timestamp( $timestamp ),
		];
		$inserted  = $wpdb->insert( self::table_name(), $record );

		if ( false === $inserted ) {
			return new \WP_Error(
				'flavor_agent_activity_insert_failed',
				'Flavor Agent could not persist the activity entry.',
				[ 'status' => 500 ]
			);
		}

		$stored = self::find( $activity_id );

		if ( is_array( $stored ) ) {
			return $stored;
		}

		return Serializer::hydrate_row( $record );
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $filters ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return [];
		}

		$scope_key   = trim( (string) ( $filters['scopeKey'] ?? '' ) );
		$limit       = self::normalize_limit( $filters['limit'] ?? self::DEFAULT_LIMIT );
		$conditions  = [];
		$args        = [];
		$surface     = trim( (string) ( $filters['surface'] ?? '' ) );
		$entity_type = trim( (string) ( $filters['entityType'] ?? '' ) );
		$entity_ref  = trim( (string) ( $filters['entityRef'] ?? '' ) );
		$user_id     = (int) ( $filters['userId'] ?? 0 );

		if ( '' !== $scope_key ) {
			$conditions[] = 'document_scope_key = %s';
			$args[]       = $scope_key;
		}

		if ( '' !== $surface ) {
			$conditions[] = 'surface = %s';
			$args[]       = $surface;
		}

		if ( '' !== $entity_type ) {
			$conditions[] = 'entity_type = %s';
			$args[]       = $entity_type;
		}

		if ( '' !== $entity_ref ) {
			$conditions[] = 'entity_ref = %s';
			$args[]       = $entity_ref;
		}

		if ( $user_id > 0 ) {
			$conditions[] = 'user_id = %d';
			$args[]       = $user_id;
		}

		$args[] = $limit;
		$sql    = 'SELECT * FROM ' . self::table_name();

		if ( [] !== $conditions ) {
			$sql .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$rows = array_reverse( is_array( $rows ) ? $rows : [] );

		return array_map(
			static fn ( array $row ): array => Serializer::hydrate_row( $row ),
			$rows
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( string $activity_id ): ?array {
		$row = self::find_row( $activity_id );

		return is_array( $row ) ? Serializer::hydrate_row( $row ) : null;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_undo_status( string $activity_id, string $status, ?string $error = null ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return new \WP_Error(
				'flavor_agent_activity_storage_unavailable',
				'Flavor Agent activity storage is unavailable.',
				[ 'status' => 500 ]
			);
		}

		if ( ! in_array( $status, [ 'undone', 'failed' ], true ) ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_status',
				'Flavor Agent activity updates only support failed or undone status transitions.',
				[ 'status' => 400 ]
			);
		}

		$current_row = self::find_row( $activity_id );

		if ( ! is_array( $current_row ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$current_entry = Serializer::hydrate_row( $current_row );

		if ( 'undone' === $status && ! self::is_ordered_undo_eligible( $current_row ) ) {
			return new \WP_Error(
				'flavor_agent_activity_undo_blocked',
				'Undo blocked by newer AI actions.',
				[ 'status' => 409 ]
			);
		}

		$timestamp     = gmdate( 'c' );
		$error_message = (string) ( $current_entry['undo']['error'] ?? 'Undo failed.' );

		if ( 'failed' === $status && ! empty( $error ) ) {
			$error_message = $error;
		}

		$undo    = Serializer::normalize_undo_for_storage(
			[
				'status'    => $status,
				'error'     => 'failed' === $status ? $error_message : null,
				'updatedAt' => $timestamp,
				'undoneAt'  => 'undone' === $status
					? $timestamp
					: ( $current_entry['undo']['undoneAt'] ?? null ),
			],
			$timestamp
		);
		$updated = $wpdb->update(
			self::table_name(),
			[
				'undo_state' => Serializer::encode_json( $undo ),
				'updated_at' => Serializer::mysql_datetime_from_timestamp( $timestamp ),
			],
			[
				'activity_id' => $activity_id,
			]
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'flavor_agent_activity_update_failed',
				'Flavor Agent could not update the activity entry.',
				[ 'status' => 500 ]
			);
		}

		$stored = self::find( $activity_id );

		if ( is_array( $stored ) ) {
			return $stored;
		}

		return new \WP_Error(
			'flavor_agent_activity_not_found',
			'Flavor Agent could not find that activity entry.',
			[ 'status' => 404 ]
		);
	}

	/**
	 * Delete activity entries created before the given ISO 8601 timestamp.
	 *
	 * @return int Number of deleted rows, or 0 on failure.
	 */
	public static function delete_before( string $before_timestamp ): int {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$unix_timestamp = strtotime( $before_timestamp );

		if ( false === $unix_timestamp ) {
			return 0;
		}

		$table_name = self::table_name();
		$deleted    = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				$table_name,
				gmdate( 'Y-m-d H:i:s', $unix_timestamp )
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	public static function prune(): int {
		$retention_days = (int) get_option(
			'flavor_agent_activity_retention_days',
			self::DEFAULT_RETENTION_DAYS
		);

		if ( $retention_days <= 0 ) {
			return 0;
		}

		$seconds_per_day = defined( 'DAY_IN_SECONDS' ) ? \DAY_IN_SECONDS : 86400;
		$cutoff          = gmdate( 'c', time() - ( $retention_days * $seconds_per_day ) );

		return self::delete_before( $cutoff );
	}

	public static function ensure_prune_schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( false === wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PRUNE_CRON_HOOK );
		}
	}

	public static function table_name(): string {
		global $wpdb;

		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix )
			? (string) $wpdb->prefix
			: 'wp_';

		return $prefix . self::TABLE_SUFFIX;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function find_row( string $activity_id ): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return null;
		}

		$table_name = self::table_name();
		$sql        = $wpdb->prepare(
			'SELECT * FROM %i WHERE activity_id = %s LIMIT 1',
			$table_name,
			$activity_id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Preserve a newer local terminal undo state when a create retry hits an
	 * already-persisted activity row after the original response was lost.
	 *
	 * @param array<string, mixed> $existing_row
	 * @param array<string, mixed> $normalized
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function merge_existing_entry( array $existing_row, array $normalized ) {
		global $wpdb;

		$existing_entry  = Serializer::hydrate_row( $existing_row );
		$existing_undo   = is_array( $existing_entry['undo'] ?? null ) ? $existing_entry['undo'] : [];
		$incoming_undo   = is_array( $normalized['undo'] ?? null ) ? $normalized['undo'] : [];
		$existing_status = (string) ( $existing_undo['status'] ?? '' );
		$incoming_status = (string) ( $incoming_undo['status'] ?? '' );

		if (
			'available' !== $existing_status
			|| ! in_array( $incoming_status, [ 'failed', 'undone' ], true )
			|| ! is_object( $wpdb )
		) {
			return $existing_entry;
		}

		$updated_timestamp = Serializer::normalize_timestamp(
			$incoming_undo['updatedAt'] ?? $normalized['timestamp'] ?? null
		);
		$updated           = $wpdb->update(
			self::table_name(),
			[
				'undo_state'       => Serializer::encode_json( $incoming_undo ),
				'execution_result' => (string) $normalized['executionResult'],
				'updated_at'       => Serializer::mysql_datetime_from_timestamp( $updated_timestamp ),
			],
			[
				'activity_id' => (string) ( $existing_row['activity_id'] ?? '' ),
			]
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'flavor_agent_activity_update_failed',
				'Flavor Agent could not merge the pending activity entry.',
				[ 'status' => 500 ]
			);
		}

		$stored = self::find( (string) ( $existing_row['activity_id'] ?? '' ) );

		return is_array( $stored ) ? $stored : $existing_entry;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function is_ordered_undo_eligible( array $row ): bool {
		$current_entry = Serializer::hydrate_row( $row );
		$current_undo  = is_array( $current_entry['undo'] ?? null ) ? $current_entry['undo'] : [];

		if ( 'available' !== (string) ( $current_undo['status'] ?? '' ) ) {
			return false;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$table_name = self::table_name();
		$sql        = $wpdb->prepare(
			'SELECT activity_id, undo_state FROM %i WHERE entity_type = %s AND entity_ref = %s ORDER BY created_at ASC, id ASC',
			$table_name,
			(string) ( $row['entity_type'] ?? '' ),
			(string) ( $row['entity_ref'] ?? '' )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return false;
		}

		$current_activity_id = (string) ( $row['activity_id'] ?? '' );

		for ( $index = count( $rows ) - 1; $index >= 0; --$index ) {
			if ( (string) ( $rows[ $index ]['activity_id'] ?? '' ) === $current_activity_id ) {
				return true;
			}

			$undo = Serializer::decode_json(
				isset( $rows[ $index ]['undo_state'] ) ? (string) $rows[ $index ]['undo_state'] : ''
			);

			if ( 'undone' !== (string) ( $undo['status'] ?? '' ) ) {
				return false;
			}
		}

		return false;
	}

	private static function generate_activity_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return \wp_generate_uuid4();
		}

		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
	}

	private static function normalize_limit( $limit ): int {
		$normalized = (int) $limit;

		if ( $normalized <= 0 ) {
			return self::DEFAULT_LIMIT;
		}

		return min( self::MAX_LIMIT, $normalized );
	}

	private static function table_exists(): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$table_name = self::table_name();
		$result     = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return (string) $result === $table_name;
	}
}
