<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class Repository {

	public const SCHEMA_OPTION          = 'flavor_agent_activity_schema_version';
	public const SCHEMA_VERSION         = 1;
	public const PRUNE_CRON_HOOK        = 'flavor_agent_prune_activity';
	public const DEFAULT_RETENTION_DAYS = 90;
	public const DEFAULT_PER_PAGE       = 20;
	public const MAX_PER_PAGE           = 100;

	private const TABLE_SUFFIX = 'flavor_agent_activity';

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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Internal schema definition for a plugin-owned table; schema creation is not cacheable.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin-owned activity log table must execute immediately.
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
		$limit       = self::normalize_per_page( $filters['limit'] ?? self::DEFAULT_PER_PAGE );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Query targets the plugin-owned activity table and is prepared in the same call.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$rows = array_reverse( is_array( $rows ) ? $rows : [] );

		return array_map(
			static fn ( array $row ): array => Serializer::hydrate_row( $row ),
			$rows
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{
	 *   entries: array<int, array<string, mixed>>,
	 *   paginationInfo: array{page: int, perPage: int, totalItems: int, totalPages: int},
	 *   summary: array{total: int, applied: int, undone: int, review: int},
	 *   filterOptions: array{
	 *     surface: array<int, array{value: string, label: string}>,
	 *     operationType: array<int, array{value: string, label: string}>,
	 *     postType: array<int, array{value: string, label: string}>,
	 *     userId: array<int, array{value: string, label: string}>
	 *   }
	 * }
	 */
	public static function query_admin( array $filters ): array {
		$page             = self::normalize_page( $filters['page'] ?? 1 );
		$per_page         = self::normalize_per_page( $filters['perPage'] ?? self::DEFAULT_PER_PAGE );
		$sort_field       = self::normalize_sort_field( $filters['sortField'] ?? 'timestamp' );
		$sort_direction   = self::normalize_sort_direction( $filters['sortDirection'] ?? 'desc' );
		$timezone         = self::resolve_activity_timezone();
		$candidate_rows   = self::query_candidate_rows( $filters );
		$resolved_records = self::resolve_admin_records(
			$candidate_rows,
			$timezone
		);
		$filtered_records = self::filter_admin_records(
			$resolved_records,
			$filters,
			$timezone
		);

		self::sort_admin_records(
			$filtered_records,
			$sort_field,
			$sort_direction
		);

		$total_items  = count( $filtered_records );
		$total_pages  = $total_items > 0
			? (int) ceil( $total_items / $per_page )
			: 0;
		$page         = min( $page, $total_pages > 0 ? $total_pages : 1 );
		$offset       = ( $page - 1 ) * $per_page;
		$page_records = array_slice( $filtered_records, $offset, $per_page );

		return [
			'entries'        => array_values(
				array_map(
					static fn ( array $record ): array => self::hydrate_admin_page_entry( $record ),
					$page_records
				)
			),
			'paginationInfo' => [
				'page'       => $page,
				'perPage'    => $per_page,
				'totalItems' => $total_items,
				'totalPages' => $total_pages,
			],
			'summary'        => self::build_admin_summary( $filtered_records ),
			'filterOptions'  => self::build_admin_filter_options(
				$resolved_records,
				$filters,
				$timezone
			),
		];
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
		$current_undo  = is_array( $current_entry['undo'] ?? null ) ? $current_entry['undo'] : [];

		if ( 'available' !== (string) ( $current_undo['status'] ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_undo_transition',
				'Flavor Agent only allows undo status changes from the available state.',
				[ 'status' => 409 ]
			);
		}

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin-owned activity log table must execute immediately.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes from the plugin-owned activity log table must execute immediately.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Reads from the plugin-owned activity table are prepared above and should bypass object caching.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin-owned activity log table must execute immediately.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Reads from the plugin-owned activity table are prepared above and should bypass object caching.
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

	private static function normalize_per_page( $limit ): int {
		$normalized = (int) $limit;

		if ( $normalized <= 0 ) {
			return self::DEFAULT_PER_PAGE;
		}

		return min( self::MAX_PER_PAGE, $normalized );
	}

	private static function normalize_page( $page ): int {
		$normalized = (int) $page;

		return $normalized > 0 ? $normalized : 1;
	}

	private static function normalize_sort_field( $sort_field ): string {
		$normalized = trim( (string) $sort_field );

		if ( in_array(
			$normalized,
			[
				'status',
				'surface',
				'postType',
				'userId',
				'operationType',
				'provider',
				'providerPath',
				'configurationOwner',
				'credentialSource',
				'selectedProvider',
			],
			true
		) ) {
			return $normalized;
		}

		return 'timestamp';
	}

	private static function normalize_sort_direction( $direction ): string {
		return 'asc' === strtolower( trim( (string) $direction ) )
			? 'asc'
			: 'desc';
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	private static function query_candidate_rows( array $filters ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return [];
		}

		$conditions  = [];
		$args        = [];
		$scope_key   = trim( (string) ( $filters['scopeKey'] ?? '' ) );
		$surface     = trim( (string) ( $filters['surface'] ?? '' ) );
		$entity_type = trim( (string) ( $filters['entityType'] ?? '' ) );
		$entity_ref  = trim( (string) ( $filters['entityRef'] ?? '' ) );
		$user_id     = self::normalize_optional_int( $filters['userId'] ?? null );
		$timezone    = self::resolve_activity_timezone();

		if ( '' !== $scope_key ) {
			$conditions[] = 'document_scope_key = %s';
			$args[]       = $scope_key;
		}

		if (
			'' !== $surface
			&& 'isNot' !== self::normalize_filter_operator( $filters['surfaceOperator'] ?? 'is' )
		) {
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

		if ( null !== $user_id ) {
			$user_id_operator = self::normalize_filter_operator( $filters['userIdOperator'] ?? 'is' );
			$conditions[]     = 'isNot' === $user_id_operator ? 'user_id <> %d' : 'user_id = %d';
			$args[]           = $user_id;
		}

		foreach ( self::build_admin_day_sql_filters( $filters, $timezone ) as $filter ) {
			$conditions[] = $filter['clause'];
			$args         = array_merge( $args, $filter['args'] );
		}

		$sql = 'SELECT * FROM ' . self::table_name();

		if ( [] !== $conditions ) {
			$sql .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		$sql .= ' ORDER BY created_at ASC, id ASC';
		if ( [] !== $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from allow-listed column names and %s/%d placeholders only.
			$sql = $wpdb->prepare( $sql, $args );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Query reads the plugin-owned activity table; it is either static or prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_values( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array{row: array<string, mixed>, meta: array<string, mixed>}>
	 */
	private static function resolve_admin_records(
		array $rows,
		\DateTimeZone $timezone
	): array {
		$entity_indexes = [];

		foreach ( $rows as $index => $row ) {
			$key = self::get_admin_row_identity_key( $row, $index );

			if ( ! isset( $entity_indexes[ $key ] ) ) {
				$entity_indexes[ $key ] = [];
			}

			$entity_indexes[ $key ][] = $index;
		}

		$resolved_statuses = array_fill( 0, count( $rows ), 'applied' );
		$review_indexes     = [];

		foreach ( $rows as $index => $row ) {
			if ( self::is_review_only_row( $row ) ) {
				$resolved_statuses[ $index ] = 'failed' === self::get_admin_row_undo_status( $row )
					? 'failed'
					: 'review';
				$review_indexes[ $index ]    = true;
			}
		}

		foreach ( $entity_indexes as $indexes ) {
			$has_active_newer_entry = false;

			for ( $index = count( $indexes ) - 1; $index >= 0; --$index ) {
				$entry_index = $indexes[ $index ];

				if ( isset( $review_indexes[ $entry_index ] ) ) {
					continue;
				}

				$undo_status = self::get_admin_row_undo_status( $rows[ $entry_index ] );

				if ( 'undone' === $undo_status ) {
					$resolved_statuses[ $entry_index ] = 'undone';
					continue;
				}

				if ( $has_active_newer_entry ) {
					$resolved_statuses[ $entry_index ] = 'blocked';
					continue;
				}

				if ( 'failed' === $undo_status ) {
					$resolved_statuses[ $entry_index ] = 'failed';
					$has_active_newer_entry            = true;
					continue;
				}

				$resolved_statuses[ $entry_index ] = 'applied';
				$has_active_newer_entry            = true;
			}
		}

		$records = [];

		foreach ( $rows as $index => $row ) {
			$records[] = self::build_admin_record_from_row(
				$row,
				(string) $resolved_statuses[ $index ],
				$timezone
			);
		}

		return $records;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{row: array<string, mixed>, meta: array<string, mixed>}
	 */
	private static function build_admin_record_from_row(
		array $row,
		string $resolved_status,
		\DateTimeZone $timezone
	): array {
		$target           = Serializer::decode_json( isset( $row['target_json'] ) ? (string) $row['target_json'] : '' );
		$document         = Serializer::decode_json( isset( $row['document_json'] ) ? (string) $row['document_json'] : '' );
		$before           = Serializer::decode_json( isset( $row['before_state'] ) ? (string) $row['before_state'] : '' );
		$after            = Serializer::decode_json( isset( $row['after_state'] ) ? (string) $row['after_state'] : '' );
		$request          = Serializer::decode_json( isset( $row['request_json'] ) ? (string) $row['request_json'] : '' );
		$post_type        = self::get_admin_post_type( $row, $document );
		$entity_id        = self::get_admin_entity_id( $row, $document );
		$operation        = self::derive_admin_operation_metadata(
			$before,
			$after,
			trim( (string) ( $row['surface'] ?? '' ) ),
			trim( (string) ( $row['activity_type'] ?? '' ) )
		);
		$operation_type   = $operation['value'];
		$operation_label  = $operation['label'];
		$request_meta     = self::get_admin_request_meta( $request );
		$timestamp_data   = self::get_timestamp_data(
			self::mysql_datetime_to_timestamp( (string) ( $row['created_at'] ?? '' ) ),
			$timezone
		);
		$user_id          = (int) ( $row['user_id'] ?? 0 );
		$user_label       = self::resolve_admin_user_label( $user_id );
		$surface          = trim( (string) ( $row['surface'] ?? '' ) );
		$surface_label    = self::format_surface_label( $surface );
		$status_label     = self::format_status_label( $resolved_status );
		$block_path       = self::format_block_path( $target );
		$operation_labels = array_values(
			array_filter(
				array_unique(
					[
						$operation_label,
						self::format_admin_operation_filter_label( $operation_type ),
					]
				),
				static fn ( string $value ): bool => '' !== trim( $value )
			)
		);

		return [
			'row'  => $row,
			'meta' => [
				'status'             => $resolved_status,
				'statusLabel'        => $status_label,
				'surface'            => $surface,
				'surfaceLabel'       => $surface_label,
				'postType'           => $post_type,
				'entityId'           => $entity_id,
				'blockPath'          => $block_path,
				'userId'             => $user_id,
				'userLabel'          => $user_label,
				'operationType'      => $operation_type,
				'operationTypeLabel' => $operation_label,
				'provider'           => $request_meta['provider'],
				'providerPath'       => $request_meta['providerPath'],
				'configurationOwner' => $request_meta['configurationOwner'],
				'credentialSource'   => $request_meta['credentialSource'],
				'selectedProvider'   => $request_meta['selectedProvider'],
				'dayKey'             => $timestamp_data['dayKey'],
				'timestampUnix'      => $timestamp_data['timestampUnix'],
				'timestamp'          => self::mysql_datetime_to_timestamp( (string) ( $row['created_at'] ?? '' ) ),
				'searchText'         => implode(
					' ',
					array_filter(
						[
							trim( (string) ( $row['suggestion'] ?? '' ) ),
							self::build_entity_search_label( $surface, $target ),
							self::build_document_search_label( $post_type, $entity_id ),
							$block_path,
							trim( (string) ( $row['activity_type'] ?? '' ) ),
							$surface_label,
							$status_label,
							...$operation_labels,
							trim( (string) ( $request['prompt'] ?? '' ) ),
							trim( (string) ( $request['reference'] ?? '' ) ),
							$request_meta['provider'],
							$request_meta['model'],
							$user_label,
						],
						static fn ( $value ): bool => is_string( $value ) && '' !== trim( $value )
					)
				),
			],
		];
	}

	/**
	 * @param array<int, array{row: array<string, mixed>, meta: array<string, mixed>}> $records
	 * @param array<string, mixed> $filters
	 * @return array<int, array{row: array<string, mixed>, meta: array<string, mixed>}>
	 */
	private static function filter_admin_records(
		array $records,
		array $filters,
		\DateTimeZone $timezone
	): array {
		$search                  = self::normalize_search_text( $filters['search'] ?? '' );
		$surface_operator        = self::normalize_filter_operator( $filters['surfaceOperator'] ?? 'is' );
		$status_operator         = self::normalize_filter_operator( $filters['statusOperator'] ?? 'is' );
		$post_type_operator      = self::normalize_filter_operator( $filters['postTypeOperator'] ?? 'is' );
		$user_id_operator        = self::normalize_filter_operator( $filters['userIdOperator'] ?? 'is' );
		$operation_type_operator = self::normalize_filter_operator( $filters['operationTypeOperator'] ?? 'is' );
		$provider_operator       = self::normalize_filter_operator( $filters['providerOperator'] ?? 'is' );
		$provider_path_operator  = self::normalize_filter_operator( $filters['providerPathOperator'] ?? 'is' );
		$configuration_owner_operator = self::normalize_filter_operator( $filters['configurationOwnerOperator'] ?? 'is' );
		$credential_source_operator = self::normalize_filter_operator( $filters['credentialSourceOperator'] ?? 'is' );
		$selected_provider_operator = self::normalize_filter_operator( $filters['selectedProviderOperator'] ?? 'is' );
		$entity_id_operator      = self::normalize_text_filter_operator( $filters['entityIdOperator'] ?? 'contains' );
		$block_path_operator     = self::normalize_text_filter_operator( $filters['blockPathOperator'] ?? 'contains' );
		$surface                 = trim( (string) ( $filters['surface'] ?? '' ) );
		$status                  = trim( (string) ( $filters['status'] ?? '' ) );
		$post_type               = trim( (string) ( $filters['postType'] ?? '' ) );
		$operation_type          = trim( (string) ( $filters['operationType'] ?? '' ) );
		$provider                = trim( (string) ( $filters['provider'] ?? '' ) );
		$provider_path           = trim( (string) ( $filters['providerPath'] ?? '' ) );
		$configuration_owner     = trim( (string) ( $filters['configurationOwner'] ?? '' ) );
		$credential_source       = trim( (string) ( $filters['credentialSource'] ?? '' ) );
		$selected_provider       = trim( (string) ( $filters['selectedProvider'] ?? '' ) );
		$entity_id               = trim( (string) ( $filters['entityId'] ?? '' ) );
		$block_path              = trim( (string) ( $filters['blockPath'] ?? '' ) );
		$user_id                 = self::normalize_optional_int( $filters['userId'] ?? null );

		return array_values(
			array_filter(
				$records,
				static function ( array $record ) use (
					$search,
					$surface_operator,
					$status_operator,
					$post_type_operator,
					$user_id_operator,
					$operation_type_operator,
					$provider_operator,
					$provider_path_operator,
					$configuration_owner_operator,
					$credential_source_operator,
					$selected_provider_operator,
					$entity_id_operator,
					$block_path_operator,
					$surface,
					$status,
					$post_type,
					$operation_type,
					$provider,
					$provider_path,
					$configuration_owner,
					$credential_source,
					$selected_provider,
					$entity_id,
					$block_path,
					$user_id,
					$filters,
					$timezone
				): bool {
					$meta = is_array( $record['meta'] ?? null ) ? $record['meta'] : [];

					if (
						'' !== $search
						&& ! str_contains(
							strtolower( (string) ( $meta['searchText'] ?? '' ) ),
							$search
						)
					) {
						return false;
					}

					if (
						'' !== $surface
						&& ! self::matches_explicit_filter(
							(string) ( $meta['surface'] ?? '' ),
							$surface,
							$surface_operator
						)
					) {
						return false;
					}

					if (
						'' !== $status
						&& ! self::matches_explicit_filter(
							(string) ( $meta['status'] ?? '' ),
							$status,
							$status_operator
						)
					) {
						return false;
					}

					if (
						'' !== $post_type
						&& ! self::matches_explicit_filter(
							(string) ( $meta['postType'] ?? '' ),
							$post_type,
							$post_type_operator
						)
					) {
						return false;
					}

					if (
						null !== $user_id
						&& ! self::matches_explicit_filter(
							(string) (int) ( $meta['userId'] ?? 0 ),
							(string) $user_id,
							$user_id_operator
						)
					) {
						return false;
					}

					if (
						'' !== $operation_type
						&& ! self::matches_explicit_filter(
							(string) ( $meta['operationType'] ?? '' ),
							$operation_type,
							$operation_type_operator
						)
					) {
						return false;
					}

					if (
						'' !== $provider
						&& ! self::matches_explicit_filter(
							(string) ( $meta['provider'] ?? '' ),
							$provider,
							$provider_operator
						)
					) {
						return false;
					}

					if (
						'' !== $provider_path
						&& ! self::matches_explicit_filter(
							(string) ( $meta['providerPath'] ?? '' ),
							$provider_path,
							$provider_path_operator
						)
					) {
						return false;
					}

					if (
						'' !== $configuration_owner
						&& ! self::matches_explicit_filter(
							(string) ( $meta['configurationOwner'] ?? '' ),
							$configuration_owner,
							$configuration_owner_operator
						)
					) {
						return false;
					}

					if (
						'' !== $credential_source
						&& ! self::matches_explicit_filter(
							(string) ( $meta['credentialSource'] ?? '' ),
							$credential_source,
							$credential_source_operator
						)
					) {
						return false;
					}

					if (
						'' !== $selected_provider
						&& ! self::matches_explicit_filter(
							(string) ( $meta['selectedProvider'] ?? '' ),
							$selected_provider,
							$selected_provider_operator
						)
					) {
						return false;
					}

					if (
						'' !== $entity_id
						&& ! self::matches_text_filter(
							(string) ( $meta['entityId'] ?? '' ),
							$entity_id,
							$entity_id_operator
						)
					) {
						return false;
					}

					if (
						'' !== $block_path
						&& ! self::matches_text_filter(
							(string) ( $meta['blockPath'] ?? '' ),
							$block_path,
							$block_path_operator
						)
					) {
						return false;
					}

					return self::matches_day_filter( $meta, $filters, $timezone );
				}
			)
		);
	}

	/**
	 * @param array<int, array{row: array<string, mixed>, meta: array<string, mixed>}> $records
	 */
	private static function sort_admin_records(
		array &$records,
		string $sort_field,
		string $sort_direction
	): void {
		usort(
			$records,
			static function ( array $left, array $right ) use ( $sort_field, $sort_direction ): int {
				$left_meta  = is_array( $left['meta'] ?? null ) ? $left['meta'] : [];
				$right_meta = is_array( $right['meta'] ?? null ) ? $right['meta'] : [];
				$left_row   = is_array( $left['row'] ?? null ) ? $left['row'] : [];
				$right_row  = is_array( $right['row'] ?? null ) ? $right['row'] : [];
				$result     = 0;

				switch ( $sort_field ) {
					case 'status':
						$result = strcmp(
							(string) ( $left_meta['status'] ?? '' ),
							(string) ( $right_meta['status'] ?? '' )
						);
						break;
					case 'surface':
						$result = strcmp(
							(string) ( $left_meta['surfaceLabel'] ?? $left_meta['surface'] ?? '' ),
							(string) ( $right_meta['surfaceLabel'] ?? $right_meta['surface'] ?? '' )
						);
						break;
					case 'postType':
						$result = strcmp(
							(string) ( $left_meta['postType'] ?? '' ),
							(string) ( $right_meta['postType'] ?? '' )
						);
						break;
					case 'userId':
						$result = (int) ( $left_meta['userId'] ?? 0 ) <=> (int) ( $right_meta['userId'] ?? 0 );
						break;
					case 'operationType':
						$result = strcmp(
							(string) ( $left_meta['operationType'] ?? '' ),
							(string) ( $right_meta['operationType'] ?? '' )
						);
						break;
					case 'provider':
						$result = strcmp(
							(string) ( $left_meta['provider'] ?? '' ),
							(string) ( $right_meta['provider'] ?? '' )
						);
						break;
					case 'providerPath':
						$result = strcmp(
							(string) ( $left_meta['providerPath'] ?? '' ),
							(string) ( $right_meta['providerPath'] ?? '' )
						);
						break;
					case 'configurationOwner':
						$result = strcmp(
							(string) ( $left_meta['configurationOwner'] ?? '' ),
							(string) ( $right_meta['configurationOwner'] ?? '' )
						);
						break;
					case 'credentialSource':
						$result = strcmp(
							(string) ( $left_meta['credentialSource'] ?? '' ),
							(string) ( $right_meta['credentialSource'] ?? '' )
						);
						break;
					case 'selectedProvider':
						$result = strcmp(
							(string) ( $left_meta['selectedProvider'] ?? '' ),
							(string) ( $right_meta['selectedProvider'] ?? '' )
						);
						break;
					default:
						$result = strcmp(
							(string) ( $left_meta['timestamp'] ?? '' ),
							(string) ( $right_meta['timestamp'] ?? '' )
						);
						break;
				}

				if ( 0 === $result ) {
					$result = strcmp(
						(string) ( $left_row['activity_id'] ?? '' ),
						(string) ( $right_row['activity_id'] ?? '' )
					);
				}

				return 'asc' === $sort_direction ? $result : -1 * $result;
			}
		);
	}

	/**
	 * @param array<int, array{row: array<string, mixed>, meta: array<string, mixed>}> $records
	 * @return array{total: int, applied: int, undone: int, review: int}
	 */
	private static function build_admin_summary( array $records ): array {
		$summary = [
			'total'   => count( $records ),
			'applied' => 0,
			'undone'  => 0,
			'review'  => 0,
		];

		foreach ( $records as $record ) {
			$status = (string) ( $record['meta']['status'] ?? '' );

			if ( 'review' === $status ) {
				++$summary['review'];
				continue;
			}

			if ( 'applied' === $status ) {
				++$summary['applied'];
				continue;
			}

			if ( 'undone' === $status ) {
				++$summary['undone'];
				continue;
			}

			if ( in_array( $status, [ 'blocked', 'failed' ], true ) ) {
				++$summary['review'];
			}
		}

		return $summary;
	}

	/**
	 * @param array{row: array<string, mixed>, meta: array<string, mixed>} $record
	 */
	private static function hydrate_admin_page_entry( array $record ): array {
		$row             = is_array( $record['row'] ?? null ) ? $record['row'] : [];
		$entry           = Serializer::hydrate_row( $row );
		$entry['status'] = (string) ( $record['meta']['status'] ?? 'applied' );

		return $entry;
	}

	/**
	 * @param array<int, array{row: array<string, mixed>, meta: array<string, mixed>}> $records
	 * @param array<string, mixed> $filters
	 * @return array{
	 *   surface: array<int, array{value: string, label: string}>,
	 *   operationType: array<int, array{value: string, label: string}>,
	 *   postType: array<int, array{value: string, label: string}>,
	 *   userId: array<int, array{value: string, label: string}>,
	 *   provider: array<int, array{value: string, label: string}>,
	 *   providerPath: array<int, array{value: string, label: string}>,
	 *   configurationOwner: array<int, array{value: string, label: string}>,
	 *   credentialSource: array<int, array{value: string, label: string}>,
	 *   selectedProvider: array<int, array{value: string, label: string}>
	 * }
	 */
	private static function build_admin_filter_options(
		array $records,
		array $filters,
		\DateTimeZone $timezone
	): array {
		return [
			'surface'       => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'surface' ),
					$timezone
				),
				'surface',
				'surfaceLabel'
			),
			'operationType' => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'operationType' ),
					$timezone
				),
				'operationType',
				'operationTypeLabel'
			),
			'postType'      => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'postType' ),
					$timezone
				),
				'postType',
				'postType'
			),
			'userId'        => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'userId' ),
					$timezone
				),
				'userId',
				'userLabel'
			),
			'provider'      => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'provider' ),
					$timezone
				),
				'provider',
				'provider'
			),
			'providerPath'  => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'providerPath' ),
					$timezone
				),
				'providerPath',
				'providerPath'
			),
			'configurationOwner' => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'configurationOwner' ),
					$timezone
				),
				'configurationOwner',
				'configurationOwner'
			),
			'credentialSource' => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'credentialSource' ),
					$timezone
				),
				'credentialSource',
				'credentialSource'
			),
			'selectedProvider' => self::collect_admin_filter_options(
				self::filter_admin_records(
					$records,
					self::without_admin_filter( $filters, 'selectedProvider' ),
					$timezone
				),
				'selectedProvider',
				'selectedProvider'
			),
		];
	}

	/**
	 * @param array<int, array{row: array<string, mixed>, meta: array<string, mixed>}> $records
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function collect_admin_filter_options(
		array $records,
		string $value_key,
		string $label_key
	): array {
		$options = [];

		foreach ( $records as $record ) {
			$meta  = is_array( $record['meta'] ?? null ) ? $record['meta'] : [];
			$value = trim( (string) ( $meta[ $value_key ] ?? '' ) );
			$label = trim( (string) ( $meta[ $label_key ] ?? $value ) );

			if ( '' === $value || '' === $label ) {
				continue;
			}

			if ( 'userId' === $value_key && (int) $value <= 0 ) {
				continue;
			}

			if ( ! isset( $options[ $value ] ) ) {
				$options[ $value ] = $label;
			}
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return array_values(
			array_map(
				static fn ( string $value, string $label ): array => [
					'value' => $value,
					'label' => $label,
				],
				array_keys( $options ),
				array_values( $options )
			)
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, mixed>
	 */
	private static function without_admin_filter( array $filters, string $field ): array {
		unset( $filters[ $field ], $filters[ $field . 'Operator' ] );

		return $filters;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function get_admin_row_identity_key( array $row, int $fallback_index ): string {
		$type = trim( (string) ( $row['entity_type'] ?? '' ) );
		$ref  = trim( (string) ( $row['entity_ref'] ?? '' ) );

		if ( '' !== $type || '' !== $ref ) {
			return $type . ':' . $ref;
		}

		$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );

		return 'activity:' . ( '' !== $activity_id ? $activity_id : (string) $fallback_index );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function get_admin_row_undo_status( array $row ): string {
		$undo   = Serializer::decode_json( isset( $row['undo_state'] ) ? (string) $row['undo_state'] : '' );
		$status = trim( (string) ( $undo['status'] ?? 'available' ) );

		return in_array( $status, [ 'undone', 'failed', 'review' ], true ) ? $status : 'available';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function is_review_only_row( array $row ): bool {
		$activity_type    = trim( (string) ( $row['activity_type'] ?? '' ) );
		$execution_result = trim( (string) ( $row['execution_result'] ?? '' ) );

		return 'request_diagnostic' === $activity_type || 'review' === $execution_result;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $document
	 */
	private static function get_admin_post_type( array $row, array $document ): string {
		$post_type = trim( (string) ( $document['postType'] ?? '' ) );

		if ( '' !== $post_type ) {
			return $post_type;
		}

		$scope_key = trim( (string) ( $document['scopeKey'] ?? $row['document_scope_key'] ?? '' ) );

		if ( ! str_contains( $scope_key, ':' ) ) {
			return '';
		}

		$parts = explode( ':', $scope_key, 2 );

		return trim( (string) ( $parts[0] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $document
	 */
	private static function get_admin_entity_id( array $row, array $document ): string {
		$entity_id = trim( (string) ( $document['entityId'] ?? '' ) );

		if ( '' !== $entity_id ) {
			return $entity_id;
		}

		$scope_key = trim( (string) ( $document['scopeKey'] ?? $row['document_scope_key'] ?? '' ) );

		if ( ! str_contains( $scope_key, ':' ) ) {
			return '';
		}

		$parts = explode( ':', $scope_key, 2 );

		return trim( (string) ( $parts[1] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array{value: string, label: string}
	 */
	private static function derive_admin_operation_metadata(
		array $before,
		array $after,
		string $surface,
		string $activity_type
	): array {
		if ( 'request_diagnostic' === $activity_type ) {
			return [
				'value' => 'request-diagnostic',
				'label' => 'Request diagnostic',
			];
		}

		$primary_operation = self::get_admin_primary_operation( $before, $after );
		$operation_type    = trim( (string) ( $primary_operation['type'] ?? '' ) );

		if ( in_array( $operation_type, [ 'insert_pattern', 'insert_block' ], true ) ) {
			return [
				'value' => 'insert',
				'label' => 'insert_pattern' === $operation_type
					? 'Insert pattern'
					: 'Insert block',
			];
		}

		if ( in_array( $operation_type, [ 'replace_template_part', 'assign_template_part' ], true ) ) {
			return [
				'value' => 'replace',
				'label' => 'replace_template_part' === $operation_type
					? 'Replace template part'
					: 'Assign template part',
			];
		}

		if ( self::has_admin_style_mutation( $before, $after ) ) {
			return [
				'value' => 'apply-style',
				'label' => 'Apply style',
			];
		}

		if ( 'block' === $surface ) {
			return [
				'value' => 'modify-attributes',
				'label' => 'Modify attributes',
			];
		}

		$resolved_type = '' !== $operation_type
			? $operation_type
			: ( '' !== $activity_type ? $activity_type : 'recorded' );

		return [
			'value' => $resolved_type,
			'label' => self::humanize_label( $resolved_type ),
		];
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	private static function get_admin_primary_operation( array $before, array $after ): array {
		if ( isset( $after['operations'] ) && is_array( $after['operations'] ) && isset( $after['operations'][0] ) && is_array( $after['operations'][0] ) ) {
			return $after['operations'][0];
		}

		if ( isset( $before['operations'] ) && is_array( $before['operations'] ) && isset( $before['operations'][0] ) && is_array( $before['operations'][0] ) ) {
			return $before['operations'][0];
		}

		return [];
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 */
	private static function has_admin_style_mutation( array $before, array $after ): bool {
		$before_attributes = is_array( $before['attributes'] ?? null )
			? $before['attributes']
			: [];
		$after_attributes  = is_array( $after['attributes'] ?? null )
			? $after['attributes']
			: [];
		$style_keys        = [
			'align',
			'backgroundColor',
			'borderColor',
			'className',
			'fontFamily',
			'fontSize',
			'gradient',
			'layout',
			'style',
			'textAlign',
			'textColor',
			'width',
		];

		foreach ( $style_keys as $style_key ) {
			if (
				self::activity_values_equal(
					$before_attributes[ $style_key ] ?? null,
					$after_attributes[ $style_key ] ?? null
				)
			) {
				continue;
			}

			return true;
		}

		return false;
	}

	private static function activity_values_equal( $left, $right ): bool {
		if ( $left === $right ) {
			return true;
		}

		if ( is_array( $left ) || is_array( $right ) ) {
			if ( ! is_array( $left ) || ! is_array( $right ) ) {
				return false;
			}

			if ( count( $left ) !== count( $right ) ) {
				return false;
			}

			foreach ( array_keys( $left ) as $key ) {
				if ( ! array_key_exists( $key, $right ) ) {
					return false;
				}

				if ( ! self::activity_values_equal( $left[ $key ], $right[ $key ] ) ) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $request
	 * @return array{provider: string, model: string, providerPath: string, configurationOwner: string, credentialSource: string, selectedProvider: string}
	 */
	private static function get_admin_request_meta( array $request ): array {
		return [
			'provider' => self::get_first_string(
				$request,
				[
					[ 'ai', 'backendLabel' ],
					[ 'ai', 'providerLabel' ],
					[ 'provider' ],
					[ 'providerName' ],
					[ 'metadata', 'provider' ],
					[ 'ai', 'provider' ],
					[ 'result', 'provider' ],
				]
			),
			'model'    => self::get_first_string(
				$request,
				[
					[ 'ai', 'model' ],
					[ 'model' ],
					[ 'modelName' ],
					[ 'metadata', 'model' ],
					[ 'result', 'model' ],
				]
			),
			'providerPath' => self::get_first_string(
				$request,
				[
					[ 'ai', 'pathLabel' ],
					[ 'pathLabel' ],
				]
			),
			'configurationOwner' => self::get_first_string(
				$request,
				[
					[ 'ai', 'ownerLabel' ],
					[ 'ownerLabel' ],
				]
			),
			'credentialSource' => self::get_first_string(
				$request,
				[
					[ 'ai', 'credentialSourceLabel' ],
					[ 'ai', 'credentialSource' ],
					[ 'credentialSourceLabel' ],
					[ 'credentialSource' ],
				]
			),
			'selectedProvider' => self::get_first_string(
				$request,
				[
					[ 'ai', 'selectedProviderLabel' ],
					[ 'ai', 'selectedProvider' ],
					[ 'selectedProviderLabel' ],
					[ 'selectedProvider' ],
				]
			),
		];
	}

	/**
	 * @param array<string, mixed> $value
	 * @param array<int, array<int, string>> $paths
	 */
	private static function get_first_string( array $value, array $paths ): string {
		foreach ( $paths as $path ) {
			$current = $value;
			$valid   = true;

			foreach ( $path as $key ) {
				if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
					$valid = false;
					break;
				}

				$current = $current[ $key ];
			}

			if ( $valid && is_string( $current ) && '' !== trim( $current ) ) {
				return trim( $current );
			}
		}

		return '';
	}

	private static function mysql_datetime_to_timestamp( string $value ): string {
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $exception ) {
			return gmdate( 'c' );
		}

		return gmdate( 'c', $date->getTimestamp() );
	}

	private static function resolve_admin_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'get_userdata' ) ) {
			$user = \get_userdata( $user_id );

			if ( is_object( $user ) && isset( $user->display_name ) ) {
				$display_name = trim( (string) $user->display_name );

				if ( '' !== $display_name ) {
					return $display_name;
				}
			}
		}

		return sprintf( 'User #%d', $user_id );
	}

	/**
	 * @return array{dayKey: string, timestampUnix: int}
	 */
	private static function get_timestamp_data(
		string $timestamp,
		\DateTimeZone $timezone
	): array {
		try {
			$date = new \DateTimeImmutable( $timestamp !== '' ? $timestamp : 'now' );
		} catch ( \Exception $exception ) {
			$date = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		}

		$localized = $date->setTimezone( $timezone );

		return [
			'dayKey'        => $localized->format( 'Y-m-d' ),
			'timestampUnix' => $localized->getTimestamp(),
		];
	}

	/**
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $filters
	 */
	private static function matches_day_filter(
		array $meta,
		array $filters,
		\DateTimeZone $timezone
	): bool {
		$entry_day    = trim( (string) ( $meta['dayKey'] ?? '' ) );
		$day_operator = trim( (string) ( $filters['dayOperator'] ?? 'on' ) );

		if ( '' === $entry_day ) {
			return false;
		}

		switch ( $day_operator ) {
			case 'inThePast':
			case 'over':
				$relative_value = self::normalize_optional_int( $filters['dayRelativeValue'] ?? null );
				$relative_unit  = trim( (string) ( $filters['dayRelativeUnit'] ?? 'days' ) );
				$entry_time     = (int) ( $meta['timestampUnix'] ?? 0 );

				if ( null === $relative_value || $relative_value <= 0 || $entry_time <= 0 ) {
					return true;
				}

				$threshold = self::resolve_relative_threshold_timestamp(
					$relative_value,
					$relative_unit,
					$timezone
				);

				if ( null === $threshold ) {
					return true;
				}

				return 'inThePast' === $day_operator
					? $entry_time >= $threshold
					: $entry_time < $threshold;
			case 'before':
			case 'after':
			case 'between':
			case 'on':
			default:
				$day_value = trim( (string) ( $filters['day'] ?? '' ) );

				if ( '' === $day_value ) {
					return true;
				}

				if ( 'before' === $day_operator ) {
					return $entry_day < $day_value;
				}

				if ( 'after' === $day_operator ) {
					return $entry_day > $day_value;
				}

				if ( 'between' === $day_operator ) {
					$day_end = trim( (string) ( $filters['dayEnd'] ?? '' ) );

					return '' !== $day_end
						&& $day_value <= $day_end
						&& $entry_day >= $day_value
						&& $entry_day <= $day_end;
				}

				return $entry_day === $day_value;
		}
	}

	private static function resolve_relative_threshold_timestamp(
		int $value,
		string $unit,
		\DateTimeZone $timezone
	): ?int {
		$interval_spec = match ( $unit ) {
			'hours'  => 'PT' . $value . 'H',
			'weeks'  => 'P' . $value . 'W',
			'months' => 'P' . $value . 'M',
			'years'  => 'P' . $value . 'Y',
			default  => 'P' . $value . 'D',
		};

		try {
			$now      = new \DateTimeImmutable( 'now', $timezone );
			$interval = new \DateInterval( $interval_spec );
		} catch ( \Exception $exception ) {
			return null;
		}

		return $now->sub( $interval )->getTimestamp();
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array{clause: string, args: array<int, string>}>
	 */
	private static function build_admin_day_sql_filters( array $filters, \DateTimeZone $timezone ): array {
		$day_operator = trim( (string) ( $filters['dayOperator'] ?? 'on' ) );
		$filters_sql  = [];

		switch ( $day_operator ) {
			case 'inThePast':
			case 'over':
				$relative_value = self::normalize_optional_int( $filters['dayRelativeValue'] ?? null );
				$relative_unit  = trim( (string) ( $filters['dayRelativeUnit'] ?? 'days' ) );

				if ( null === $relative_value || $relative_value <= 0 ) {
					return [];
				}

				$threshold = self::resolve_relative_threshold_timestamp(
					$relative_value,
					$relative_unit,
					$timezone
				);

				if ( null === $threshold ) {
					return [];
				}

				$filters_sql[] = [
					'clause' => 'created_at ' . ( 'inThePast' === $day_operator ? '>=' : '<' ) . ' %s',
					'args'   => [ gmdate( 'Y-m-d H:i:s', $threshold ) ],
				];
				return $filters_sql;
			case 'between':
				$day_start = trim( (string) ( $filters['day'] ?? '' ) );
				$day_end   = trim( (string) ( $filters['dayEnd'] ?? '' ) );

				if ( '' === $day_start || '' === $day_end ) {
					return [];
				}

				$start_bounds = self::resolve_local_day_bounds( $day_start, $timezone );
				$end_bounds   = self::resolve_local_day_bounds( $day_end, $timezone );

				if ( null === $start_bounds || null === $end_bounds || $start_bounds['start'] > $end_bounds['start'] ) {
					return [];
				}

				$filters_sql[] = [
					'clause' => 'created_at >= %s AND created_at < %s',
					'args'   => [
						gmdate( 'Y-m-d H:i:s', $start_bounds['start'] ),
						gmdate( 'Y-m-d H:i:s', $end_bounds['end'] ),
					],
				];
				return $filters_sql;
			case 'before':
			case 'after':
			case 'on':
			default:
				$day = trim( (string) ( $filters['day'] ?? '' ) );

				if ( '' === $day ) {
					return [];
				}

				$bounds = self::resolve_local_day_bounds( $day, $timezone );

				if ( null === $bounds ) {
					return [];
				}

				if ( 'before' === $day_operator ) {
					$filters_sql[] = [
						'clause' => 'created_at < %s',
						'args'   => [ gmdate( 'Y-m-d H:i:s', $bounds['start'] ) ],
					];
					return $filters_sql;
				}

				if ( 'after' === $day_operator ) {
					$filters_sql[] = [
						'clause' => 'created_at >= %s',
						'args'   => [ gmdate( 'Y-m-d H:i:s', $bounds['end'] ) ],
					];
					return $filters_sql;
				}

				$filters_sql[] = [
					'clause' => 'created_at >= %s AND created_at < %s',
					'args'   => [
						gmdate( 'Y-m-d H:i:s', $bounds['start'] ),
						gmdate( 'Y-m-d H:i:s', $bounds['end'] ),
					],
				];
				return $filters_sql;
		}
	}

	/**
	 * @return array{start: int, end: int}|null
	 */
	private static function resolve_local_day_bounds( string $day, \DateTimeZone $timezone ): ?array {
		try {
			$start = new \DateTimeImmutable( $day . ' 00:00:00', $timezone );
		} catch ( \Exception $exception ) {
			return null;
		}

		return [
			'start' => $start->getTimestamp(),
			'end'   => $start->modify( '+1 day' )->getTimestamp(),
		];
	}

	private static function normalize_search_text( $search ): string {
		return strtolower( trim( (string) $search ) );
	}

	private static function normalize_filter_operator( $operator ): string {
		return 'isNot' === trim( (string) $operator ) ? 'isNot' : 'is';
	}

	private static function normalize_text_filter_operator( $operator ): string {
		return match ( trim( (string) $operator ) ) {
			'notContains' => 'notContains',
			'startsWith'  => 'startsWith',
			default       => 'contains',
		};
	}

	private static function matches_explicit_filter(
		string $value,
		string $expected,
		string $operator
	): bool {
		$matches = $value === $expected;

		return 'isNot' === $operator ? ! $matches : $matches;
	}

	private static function matches_text_filter(
		string $value,
		string $expected,
		string $operator
	): bool {
		$normalized_value    = strtolower( $value );
		$normalized_expected = strtolower( $expected );

		if ( '' === $normalized_expected ) {
			return true;
		}

		return match ( $operator ) {
			'notContains' => ! str_contains( $normalized_value, $normalized_expected ),
			'startsWith'  => str_starts_with( $normalized_value, $normalized_expected ),
			default       => str_contains( $normalized_value, $normalized_expected ),
		};
	}

	private static function normalize_optional_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$normalized = (int) $value;

		return $normalized > 0 ? $normalized : null;
	}

	private static function resolve_activity_timezone(): \DateTimeZone {
		$timezone_name = '';

		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezone_name = (string) wp_timezone_string();
		}

		if ( '' === $timezone_name ) {
			$timezone_name = (string) get_option( 'timezone_string', 'UTC' );
		}

		if ( '' === $timezone_name ) {
			$timezone_name = 'UTC';
		}

		try {
			return new \DateTimeZone( $timezone_name );
		} catch ( \Exception $exception ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	private static function format_status_label( string $status ): string {
		return match ( $status ) {
			'review'  => 'Review',
			'undone'  => 'Undone',
			'blocked' => 'Undo blocked',
			'failed'  => 'Undo unavailable',
			default   => 'Applied',
		};
	}

	private static function format_surface_label( string $surface ): string {
		return match ( $surface ) {
			'content'       => 'Content',
			'navigation'    => 'Navigation',
			'pattern'       => 'Pattern',
			'template'      => 'Template',
			'template-part' => 'Template part',
			'global-styles' => 'Global Styles',
			'style-book'    => 'Style Book',
			'block'         => 'Block',
			default         => 'Activity',
		};
	}

	private static function format_admin_operation_filter_label( string $operation_type ): string {
		return match ( $operation_type ) {
			'insert'            => 'Insert pattern',
			'replace'           => 'Replace template part',
			'apply-style'       => 'Apply style',
			'modify-attributes' => 'Modify attributes',
			default             => self::humanize_label( $operation_type ),
		};
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private static function build_entity_search_label( string $surface, array $target ): string {
		if ( 'template' === $surface ) {
			return trim( (string) ( $target['templateRef'] ?? '' ) );
		}

		if ( 'template-part' === $surface ) {
			return trim( (string) ( $target['templatePartRef'] ?? '' ) );
		}

		if ( in_array( $surface, [ 'global-styles', 'style-book' ], true ) ) {
			return implode(
				' ',
				array_filter(
					[
						trim( (string) ( $target['globalStylesId'] ?? '' ) ),
						trim( (string) ( $target['blockTitle'] ?? '' ) ),
						trim( (string) ( $target['blockName'] ?? '' ) ),
					],
					static fn ( $value ): bool => '' !== $value
				)
			);
		}

		return implode(
			' ',
			array_filter(
				[
					self::humanize_block_name( trim( (string) ( $target['blockName'] ?? '' ) ) ),
					self::format_block_path( $target ),
				],
				static fn ( $value ): bool => '' !== $value
			)
		);
	}

	private static function build_document_search_label(
		string $post_type,
		string $entity_id
	): string {
		if ( '' === $post_type && '' === $entity_id ) {
			return '';
		}

		if ( '' === $entity_id ) {
			return $post_type;
		}

		return $post_type . ' ' . $entity_id;
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private static function format_block_path( array $target ): string {
		$block_path = is_array( $target['blockPath'] ?? null ) ? $target['blockPath'] : [];

		if ( [] === $block_path ) {
			return '';
		}

		$segments   = self::format_block_path_segments( $block_path );
		$block_name = trim( (string) ( $target['blockName'] ?? '' ) );

		if ( '' === $block_name ) {
			return $segments;
		}

		return self::humanize_block_name( $block_name ) . ' · ' . $segments;
	}

	/**
	 * @param array<int, mixed> $block_path
	 */
	private static function format_block_path_segments( array $block_path ): string {
		return implode(
			' → ',
			array_map(
				static fn ( $value ): string => (string) ( (int) $value + 1 ),
				$block_path
			)
		);
	}

	private static function humanize_block_name( string $block_name ): string {
		$normalized = preg_replace( '#^core/#', '', $block_name ) ?? $block_name;

		return self::humanize_label( $normalized );
	}

	private static function humanize_label( string $value ): string {
		$normalized = trim( $value );

		if ( '' === $normalized ) {
			return '';
		}

		$parts = preg_split( '/[\s\/_-]+/', $normalized );
		$parts = is_array( $parts ) ? $parts : [];
		$parts = array_map(
			static fn ( string $part ): string => ucfirst( strtolower( $part ) ),
			array_values( array_filter( $parts, static fn ( string $part ): bool => '' !== $part ) )
		);

		return implode( ' ', $parts );
	}

	private static function table_exists(): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$table_name = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence checks read plugin-owned schema metadata directly.
		$result     = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return (string) $result === $table_name;
	}
}
