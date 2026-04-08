<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class Repository {

	public const SCHEMA_OPTION                       = 'flavor_agent_activity_schema_version';
	public const SCHEMA_VERSION                      = 3;
	public const PRUNE_CRON_HOOK                     = 'flavor_agent_prune_activity';
	public const ADMIN_PROJECTION_BACKFILL_CRON_HOOK = 'flavor_agent_backfill_activity_admin_projection';
	public const DEFAULT_RETENTION_DAYS              = 90;
	public const DEFAULT_PER_PAGE                    = 20;
	public const MAX_PER_PAGE                        = 100;

	private const TABLE_SUFFIX                            = 'flavor_agent_activity';
	private const ADMIN_PROJECTION_BACKFILL_SIZE          = 250;
	private const ADMIN_PROJECTION_BACKFILL_CURSOR_OPTION = 'flavor_agent_activity_admin_projection_backfill_cursor';
	private const ADMIN_HISTORY_QUERY_ENTITY_BATCH_SIZE   = 50;
	private const ADMIN_HISTORY_QUERY_KEY_SEPARATOR       = "\x1F";
	private const ADMIN_PROJECTION_SELECT_SQL             = 'id, activity_id, user_id, surface, entity_type, entity_ref, document_scope_key, activity_type, suggestion, undo_state, execution_result, created_at, admin_post_type, admin_entity_id, admin_block_path, admin_operation_type, admin_operation_label, admin_provider, admin_model, admin_provider_path, admin_configuration_owner, admin_credential_source, admin_selected_provider, admin_request_ability, admin_request_route, admin_request_reference, admin_request_prompt, admin_search_text';

	public static function maybe_install(): void {
		$installed_version = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( $installed_version < self::SCHEMA_VERSION || ! self::table_exists() ) {
			self::install();
		}

		self::ensure_admin_projection_backfill_schedule();
	}

	public static function install(): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return;
		}

		$installed_version       = (int) get_option( self::SCHEMA_OPTION, 0 );
		$table_previously_exists = self::table_exists();
		$table_name              = self::table_name();
		$charset                 = method_exists( $wpdb, 'get_charset_collate' )
			? (string) $wpdb->get_charset_collate()
			: '';
		$sql                     = "CREATE TABLE {$table_name} (
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
			admin_post_type varchar(64) NOT NULL DEFAULT '',
			admin_entity_id varchar(191) NOT NULL DEFAULT '',
			admin_block_path varchar(191) NOT NULL DEFAULT '',
			admin_operation_type varchar(64) NOT NULL DEFAULT '',
			admin_operation_label varchar(191) NOT NULL DEFAULT '',
			admin_provider varchar(191) NOT NULL DEFAULT '',
			admin_model varchar(191) NOT NULL DEFAULT '',
			admin_provider_path varchar(255) NOT NULL DEFAULT '',
			admin_configuration_owner varchar(191) NOT NULL DEFAULT '',
			admin_credential_source varchar(64) NOT NULL DEFAULT '',
			admin_selected_provider varchar(191) NOT NULL DEFAULT '',
			admin_request_ability varchar(191) NOT NULL DEFAULT '',
			admin_request_route varchar(191) NOT NULL DEFAULT '',
			admin_request_reference varchar(191) NOT NULL DEFAULT '',
			admin_request_prompt longtext NULL,
			admin_search_text longtext NOT NULL,
			execution_result varchar(32) NOT NULL DEFAULT 'applied',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY activity_id (activity_id),
			KEY surface (surface),
			KEY entity_lookup (entity_type, entity_ref),
			KEY document_scope_key (document_scope_key),
			KEY user_created (user_id, created_at),
			KEY created_at (created_at),
			KEY admin_post_type (admin_post_type),
			KEY admin_post_entity (admin_post_type, admin_entity_id),
			KEY admin_operation_type (admin_operation_type),
			KEY admin_provider (admin_provider),
			KEY admin_provider_path (admin_provider_path(191)),
			KEY admin_configuration_owner (admin_configuration_owner),
			KEY admin_credential_source (admin_credential_source),
			KEY admin_selected_provider (admin_selected_provider)
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

		if ( $table_previously_exists && $installed_version < self::SCHEMA_VERSION ) {
			self::schedule_admin_projection_backfill();
		}
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

		$timestamp  = Serializer::normalize_timestamp( $normalized['timestamp'] ?? null );
		$entity     = Serializer::derive_entity( $normalized );
		$projection = self::build_admin_projection_from_entry( $normalized );
		$record     = [
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
		$record     = array_merge( $record, $projection );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin-owned activity log table must execute immediately.
		$inserted = $wpdb->insert( self::table_name(), $record );

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
	 *   summary: array{total: int, applied: int, undone: int, review: int, blocked: int, failed: int},
	 *   filterOptions: array{
	 *     surface: array<int, array{value: string, label: string}>,
	 *     operationType: array<int, array{value: string, label: string}>,
	 *     postType: array<int, array{value: string, label: string}>,
	 *     userId: array<int, array{value: string, label: string}>
	 *   }
	 * }
	 */
	public static function query_admin( array $filters ): array {
		$page              = self::normalize_page( $filters['page'] ?? 1 );
		$per_page          = self::normalize_per_page( $filters['perPage'] ?? self::DEFAULT_PER_PAGE );
		$sort_field        = self::normalize_sort_field( $filters['sortField'] ?? 'timestamp' );
		$sort_direction    = self::normalize_sort_direction( $filters['sortDirection'] ?? 'desc' );
		$timezone          = self::resolve_activity_timezone();
		$candidate_rows    = self::query_candidate_rows( $filters );
		$history_rows      = self::requires_full_history_resolution( $filters )
			? self::query_admin_history_rows( $candidate_rows )
			: $candidate_rows;
		$status_map        = self::resolve_admin_row_statuses( $history_rows );
		$resolved_records  = self::resolve_admin_records(
			$candidate_rows,
			$status_map,
			$timezone
		);
		$filter_context    = self::build_admin_filter_context( $filters, $timezone );
		$evaluated_records = self::evaluate_admin_records(
			$resolved_records,
			$filter_context
		);
		$filtered_records  = array_values(
			array_map(
				static fn ( array $evaluated_record ): array => $evaluated_record['record'],
				array_filter(
					$evaluated_records,
					static fn ( array $evaluated_record ): bool => self::record_matches_admin_filters(
						$evaluated_record,
						$filter_context['activeFilters']
					)
				)
			)
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
		$page_entries = self::load_admin_page_entries( $page_records );

		return [
			'entries'        => array_values( $page_entries ),
			'paginationInfo' => [
				'page'       => $page,
				'perPage'    => $per_page,
				'totalItems' => $total_items,
				'totalPages' => $total_pages,
			],
			'summary'        => self::build_admin_summary( $filtered_records ),
			'filterOptions'  => self::build_admin_filter_options(
				$evaluated_records,
				$filter_context
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

		$undo = Serializer::normalize_undo_for_storage(
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
		$deleted = $wpdb->query(
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

	public static function run_admin_projection_backfill(): int {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$cursor = get_option( self::ADMIN_PROJECTION_BACKFILL_CURSOR_OPTION, null );

		if ( null === $cursor ) {
			return 0;
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::ADMIN_PROJECTION_BACKFILL_CRON_HOOK );
		}

		$table_name = self::table_name();
		$sql        = $wpdb->prepare(
			'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
			$table_name,
			(int) $cursor,
			self::ADMIN_PROJECTION_BACKFILL_SIZE
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Reads the plugin-owned activity table in bounded batches to backfill derived admin projection columns.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) || [] === $rows ) {
			self::clear_admin_projection_backfill_state();

			return 0;
		}

		$processed = 0;
		$last_id   = (int) $cursor;

		foreach ( $rows as $row ) {
			$last_id     = max( $last_id, (int) ( $row['id'] ?? 0 ) );
			$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );

			if ( '' === $activity_id || ! self::row_requires_admin_projection_backfill( $row ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates the plugin-owned activity table in place after deriving projection metadata.
			$wpdb->update(
				$table_name,
				self::build_admin_projection_from_row( $row ),
				[
					'activity_id' => $activity_id,
				]
			);
			++$processed;
		}

		if ( count( $rows ) < self::ADMIN_PROJECTION_BACKFILL_SIZE ) {
			self::clear_admin_projection_backfill_state();
		} else {
			update_option(
				self::ADMIN_PROJECTION_BACKFILL_CURSOR_OPTION,
				$last_id,
				false
			);
			self::ensure_admin_projection_backfill_schedule();
		}

		return $processed;
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
		$updated = $wpdb->update(
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

	private static function schedule_admin_projection_backfill(): void {
		if ( null === get_option( self::ADMIN_PROJECTION_BACKFILL_CURSOR_OPTION, null ) ) {
			update_option( self::ADMIN_PROJECTION_BACKFILL_CURSOR_OPTION, 0, false );
		}

		self::ensure_admin_projection_backfill_schedule();
	}

	private static function ensure_admin_projection_backfill_schedule(): void {
		if (
			! self::admin_projection_backfill_pending()
			|| ! function_exists( 'wp_next_scheduled' )
			|| ! function_exists( 'wp_schedule_single_event' )
		) {
			return;
		}

		if ( false === wp_next_scheduled( self::ADMIN_PROJECTION_BACKFILL_CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::ADMIN_PROJECTION_BACKFILL_CRON_HOOK );
		}
	}

	private static function clear_admin_projection_backfill_state(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::ADMIN_PROJECTION_BACKFILL_CURSOR_OPTION );
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::ADMIN_PROJECTION_BACKFILL_CRON_HOOK );
		}
	}

	private static function admin_projection_backfill_pending(): bool {
		return null !== get_option( self::ADMIN_PROJECTION_BACKFILL_CURSOR_OPTION, null );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function row_requires_admin_projection_backfill( array $row ): bool {
		return '' === trim( (string) ( $row['admin_operation_type'] ?? '' ) )
			|| '' === trim( (string) ( $row['admin_operation_label'] ?? '' ) )
			|| '' === trim( (string) ( $row['admin_search_text'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function build_admin_projection_from_entry( array $entry ): array {
		$target            = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$before            = is_array( $entry['before'] ?? null ) ? $entry['before'] : [];
		$after             = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];
		$request           = is_array( $entry['request'] ?? null ) ? $entry['request'] : [];
		$document          = is_array( $entry['document'] ?? null ) ? $entry['document'] : [];
		$entity            = Serializer::derive_entity( $entry );
		$scope_key         = trim( (string) ( $document['scopeKey'] ?? '' ) );
		$surface           = trim( (string) ( $entry['surface'] ?? '' ) );
		$activity_type     = trim( (string) ( $entry['type'] ?? '' ) );
		$operation         = self::derive_admin_operation_metadata(
			$before,
			$after,
			$surface,
			$activity_type
		);
		$request_meta      = self::get_admin_request_meta( $request );
		$prompt            = trim( (string) ( $request['prompt'] ?? '' ) );
		$request_reference = trim( (string) ( $request['reference'] ?? '' ) );
		$post_type         = self::get_admin_post_type(
			[
				'document_scope_key' => $scope_key,
			],
			$document
		);
		$entity_id         = self::get_admin_entity_id(
			[
				'document_scope_key' => $scope_key,
			],
			$document
		);
		$block_path        = self::format_block_path( $target );
		$search_text       = self::build_admin_base_search_text(
			trim( (string) ( $entry['suggestion'] ?? '' ) ),
			trim( (string) ( $entity['ref'] ?? '' ) ),
			$surface,
			$target,
			$post_type,
			$entity_id,
			$block_path,
			$activity_type,
			$operation['value'],
			$operation['label'],
			$request_meta,
			$prompt,
			$request_reference
		);

		return [
			'admin_post_type'           => $post_type,
			'admin_entity_id'           => $entity_id,
			'admin_block_path'          => $block_path,
			'admin_operation_type'      => $operation['value'],
			'admin_operation_label'     => $operation['label'],
			'admin_provider'            => $request_meta['provider'],
			'admin_model'               => $request_meta['model'],
			'admin_provider_path'       => $request_meta['providerPath'],
			'admin_configuration_owner' => $request_meta['configurationOwner'],
			'admin_credential_source'   => $request_meta['credentialSource'],
			'admin_selected_provider'   => $request_meta['selectedProvider'],
			'admin_request_ability'     => $request_meta['requestAbility'],
			'admin_request_route'       => $request_meta['requestRoute'],
			'admin_request_reference'   => $request_reference,
			'admin_request_prompt'      => '' !== $prompt ? $prompt : null,
			'admin_search_text'         => $search_text,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function build_admin_projection_from_row( array $row ): array {
		return self::build_admin_projection_from_entry( Serializer::hydrate_row( $row ) );
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

		$conditions       = [];
		$args             = [];
		$scope_key        = trim( (string) ( $filters['scopeKey'] ?? '' ) );
		$surface          = trim( (string) ( $filters['surface'] ?? '' ) );
		$entity_type      = trim( (string) ( $filters['entityType'] ?? '' ) );
		$entity_ref       = trim( (string) ( $filters['entityRef'] ?? '' ) );
		$user_id          = self::normalize_optional_int( $filters['userId'] ?? null );
		$timezone         = self::resolve_activity_timezone();
		$surface_operator = self::normalize_filter_operator( $filters['surfaceOperator'] ?? 'is' );
		$user_id_operator = self::normalize_filter_operator( $filters['userIdOperator'] ?? 'is' );
		$select_sql       = self::should_select_full_admin_candidate_rows()
			? '*'
			: self::ADMIN_PROJECTION_SELECT_SQL;

		if ( '' !== $scope_key ) {
			$conditions[] = 'document_scope_key = %s';
			$args[]       = $scope_key;
		}

		if ( '' !== $surface ) {
			$conditions[] = 'isNot' === $surface_operator ? 'surface <> %s' : 'surface = %s';
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
			$conditions[] = 'isNot' === $user_id_operator ? 'user_id <> %d' : 'user_id = %d';
			$args[]       = $user_id;
		}

		// Projection-backed admin facets stay in the evaluated-record pass so
		// filterOptions can keep showing sibling values for the currently selected facet.
		foreach ( self::build_admin_day_sql_filters( $filters, $timezone ) as $filter ) {
			$conditions[] = $filter['clause'];
			$args         = array_merge( $args, $filter['args'] );
		}

		$sql = 'SELECT ' . $select_sql . ' FROM ' . self::table_name();

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

	private static function should_select_full_admin_candidate_rows(): bool {
		return self::admin_projection_backfill_pending();
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private static function requires_full_history_resolution( array $filters ): bool {
		if ( '' !== trim( (string) ( $filters['search'] ?? '' ) ) ) {
			return true;
		}

		if ( null !== self::normalize_optional_int( $filters['userId'] ?? null ) ) {
			return true;
		}

		if (
			'' !== trim( (string) ( $filters['provider'] ?? '' ) )
			|| '' !== trim( (string) ( $filters['providerPath'] ?? '' ) )
			|| '' !== trim( (string) ( $filters['configurationOwner'] ?? '' ) )
			|| '' !== trim( (string) ( $filters['credentialSource'] ?? '' ) )
			|| '' !== trim( (string) ( $filters['selectedProvider'] ?? '' ) )
			|| '' !== trim( (string) ( $filters['operationType'] ?? '' ) )
		) {
			return true;
		}

		return self::has_admin_day_filter( $filters );
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private static function has_admin_day_filter( array $filters ): bool {
		return '' !== trim( (string) ( $filters['day'] ?? '' ) )
			|| '' !== trim( (string) ( $filters['dayEnd'] ?? '' ) )
			|| null !== self::normalize_optional_int( $filters['dayRelativeValue'] ?? null );
	}

	/**
	 * @param array<int, array<string, mixed>> $candidate_rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function query_admin_history_rows( array $candidate_rows ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || [] === $candidate_rows ) {
			return $candidate_rows;
		}

		$entity_keys  = [];
		$history_rows = [];
		$seen         = [];

		foreach ( $candidate_rows as $index => $row ) {
			$key = self::get_admin_row_identity_key( $row, $index );

			if (
				'' === trim( (string) ( $row['entity_type'] ?? '' ) )
				&& '' === trim( (string) ( $row['entity_ref'] ?? '' ) )
			) {
				$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );

				if ( '' === $activity_id || isset( $seen[ $activity_id ] ) ) {
					continue;
				}

				$history_rows[]       = $row;
				$seen[ $activity_id ] = true;
				continue;
			}

			$entity_keys[ $key ] = [
				'entity_type' => trim( (string) ( $row['entity_type'] ?? '' ) ),
				'entity_ref'  => trim( (string) ( $row['entity_ref'] ?? '' ) ),
			];
		}

		foreach (
			array_chunk(
				array_values( $entity_keys ),
				self::ADMIN_HISTORY_QUERY_ENTITY_BATCH_SIZE
			) as $entity_chunk
		) {
			$rows = self::query_admin_history_chunk_rows( $entity_chunk );

			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );

				if ( '' === $activity_id || isset( $seen[ $activity_id ] ) ) {
					continue;
				}

				$history_rows[]       = $row;
				$seen[ $activity_id ] = true;
			}
		}

		usort(
			$history_rows,
			static function ( array $left, array $right ): int {
				$left_created  = (string) ( $left['created_at'] ?? '' );
				$right_created = (string) ( $right['created_at'] ?? '' );

				if ( $left_created === $right_created ) {
					return (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
				}

				return $left_created <=> $right_created;
			}
		);

		return $history_rows;
	}

	/**
	 * @param array<int, array{entity_type: string, entity_ref: string}> $entity_chunk
	 */
	private static function query_admin_history_chunk_rows( array $entity_chunk ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || [] === $entity_chunk ) {
			return [];
		}

		if ( self::admin_history_chunk_has_key_delimiter_conflicts( $entity_chunk ) ) {
			return self::query_admin_history_chunk_rows_exact( $entity_chunk );
		}

		$entity_keys = array_map(
			static fn ( array $entity ): string => (string) ( $entity['entity_type'] ?? '' )
				. self::ADMIN_HISTORY_QUERY_KEY_SEPARATOR
				. (string) ( $entity['entity_ref'] ?? '' ),
			$entity_chunk
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SELECT list is a fixed constant and dynamic values are passed as placeholders.
		$sql = $wpdb->prepare(
			'SELECT ' . self::ADMIN_PROJECTION_SELECT_SQL . ' FROM %i WHERE FIND_IN_SET(CONCAT(entity_type, %s, entity_ref), %s) > 0 ORDER BY created_at ASC, id ASC',
			self::table_name(),
			self::ADMIN_HISTORY_QUERY_KEY_SEPARATOR,
			implode( ',', $entity_keys )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads bounded entity histories from the plugin-owned activity table so blocked/applied status stays consistent across filtered admin views.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return array_values( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * @param array<int, array{entity_type: string, entity_ref: string}> $entity_chunk
	 */
	private static function query_admin_history_chunk_rows_exact( array $entity_chunk ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || [] === $entity_chunk ) {
			return [];
		}

		$rows = [];

		foreach ( $entity_chunk as $entity ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SELECT list is a fixed constant and entity values are passed as placeholders.
			$sql = $wpdb->prepare(
				'SELECT ' . self::ADMIN_PROJECTION_SELECT_SQL . ' FROM %i WHERE entity_type = %s AND entity_ref = %s ORDER BY created_at ASC, id ASC',
				self::table_name(),
				(string) ( $entity['entity_type'] ?? '' ),
				(string) ( $entity['entity_ref'] ?? '' )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Falls back to exact history lookups when a chunk contains query delimiters.
			$chunk_rows = $wpdb->get_results( $sql, ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			if ( is_array( $chunk_rows ) ) {
				$rows = array_merge( $rows, $chunk_rows );
			}
		}

		return $rows;
	}

	/**
	 * @param array<int, array{entity_type: string, entity_ref: string}> $entity_chunk
	 */
	private static function admin_history_chunk_has_key_delimiter_conflicts( array $entity_chunk ): bool {
		foreach ( $entity_chunk as $entity ) {
			$entity_type = (string) ( $entity['entity_type'] ?? '' );
			$entity_ref  = (string) ( $entity['entity_ref'] ?? '' );

			if (
				str_contains( $entity_type, ',' )
				|| str_contains( $entity_ref, ',' )
				|| str_contains( $entity_type, self::ADMIN_HISTORY_QUERY_KEY_SEPARATOR )
				|| str_contains( $entity_ref, self::ADMIN_HISTORY_QUERY_KEY_SEPARATOR )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<string, string>
	 */
	private static function resolve_admin_row_statuses( array $rows ): array {
		$entity_indexes = [];
		$status_map     = [];
		$review_indexes = [];

		foreach ( $rows as $index => $row ) {
			$key = self::get_admin_row_identity_key( $row, $index );

			if ( ! isset( $entity_indexes[ $key ] ) ) {
				$entity_indexes[ $key ] = [];
			}

			$entity_indexes[ $key ][] = $index;

			if ( self::is_review_only_row( $row ) ) {
				$activity_id                = trim( (string) ( $row['activity_id'] ?? '' ) );
				$status_map[ $activity_id ] = 'failed' === self::get_admin_row_undo_status( $row )
					? 'failed'
					: 'review';
				$review_indexes[ $index ]   = true;
			}
		}

		foreach ( $entity_indexes as $indexes ) {
			$has_active_newer_entry = false;

			for ( $index = count( $indexes ) - 1; $index >= 0; --$index ) {
				$entry_index = $indexes[ $index ];

				if ( isset( $review_indexes[ $entry_index ] ) ) {
					continue;
				}

				$row         = $rows[ $entry_index ];
				$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );
				$undo_status = self::get_admin_row_undo_status( $row );

				if ( '' === $activity_id ) {
					continue;
				}

				if ( 'undone' === $undo_status ) {
					$status_map[ $activity_id ] = 'undone';
					continue;
				}

				if ( $has_active_newer_entry ) {
					$status_map[ $activity_id ] = 'blocked';
					continue;
				}

				if ( 'failed' === $undo_status ) {
					$status_map[ $activity_id ] = 'failed';
					$has_active_newer_entry     = true;
					continue;
				}

				$status_map[ $activity_id ] = 'applied';
				$has_active_newer_entry     = true;
			}
		}

		return $status_map;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, string> $status_map
	 * @return array<int, array{row: array<string, mixed>, meta: array<string, mixed>}>
	 */
	private static function resolve_admin_records(
		array $rows,
		array $status_map,
		\DateTimeZone $timezone
	): array {
		$records = [];

		foreach ( $rows as $row ) {
			$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );
			$status      = $status_map[ $activity_id ] ?? (
				'failed' === self::get_admin_row_undo_status( $row ) && self::is_review_only_row( $row )
					? 'failed'
					: ( self::is_review_only_row( $row ) ? 'review' : 'applied' )
			);
			$records[]   = self::build_admin_record_from_row(
				$row,
				$status,
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
		$target            = Serializer::decode_json( isset( $row['target_json'] ) ? (string) $row['target_json'] : '' );
		$document          = Serializer::decode_json( isset( $row['document_json'] ) ? (string) $row['document_json'] : '' );
		$before            = Serializer::decode_json( isset( $row['before_state'] ) ? (string) $row['before_state'] : '' );
		$after             = Serializer::decode_json( isset( $row['after_state'] ) ? (string) $row['after_state'] : '' );
		$request           = Serializer::decode_json( isset( $row['request_json'] ) ? (string) $row['request_json'] : '' );
		$post_type         = self::get_admin_post_type( $row, $document );
		$entity_id         = self::get_admin_entity_id( $row, $document );
		$operation         = self::derive_admin_operation_metadata(
			$before,
			$after,
			trim( (string) ( $row['surface'] ?? '' ) ),
			trim( (string) ( $row['activity_type'] ?? '' ) ),
			$row
		);
		$operation_type    = $operation['value'];
		$operation_label   = $operation['label'];
		$request_meta      = self::get_admin_request_meta( $request, $row );
		$timestamp_data    = self::get_timestamp_data(
			self::mysql_datetime_to_timestamp( (string) ( $row['created_at'] ?? '' ) ),
			$timezone
		);
		$user_id           = (int) ( $row['user_id'] ?? 0 );
		$user_label        = self::resolve_admin_user_label( $user_id );
		$surface           = trim( (string) ( $row['surface'] ?? '' ) );
		$surface_label     = self::format_surface_label( $surface );
		$status_label      = 'failed' === $resolved_status && self::is_review_only_row( $row )
			? 'Request failed'
			: self::format_status_label( $resolved_status );
		$block_path        = trim( (string) ( $row['admin_block_path'] ?? '' ) );
		$block_path        = '' !== $block_path ? $block_path : self::format_block_path( $target );
		$request_prompt    = trim( (string) ( $row['admin_request_prompt'] ?? ( $request['prompt'] ?? '' ) ) );
		$request_reference = trim( (string) ( $row['admin_request_reference'] ?? ( $request['reference'] ?? '' ) ) );
		$base_search_text  = self::resolve_admin_base_search_text(
			$row,
			$surface,
			$target,
			$post_type,
			$entity_id,
			$block_path,
			$operation_type,
			$operation_label,
			$request_meta,
			$request_prompt,
			$request_reference
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
							$base_search_text,
							$surface_label,
							$status_label,
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
	 * @return array{total: int, applied: int, undone: int, review: int, blocked: int, failed: int}
	 */
	private static function build_admin_summary( array $records ): array {
		$summary = [
			'total'   => count( $records ),
			'applied' => 0,
			'undone'  => 0,
			'review'  => 0,
			'blocked' => 0,
			'failed'  => 0,
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

			if ( 'blocked' === $status ) {
				++$summary['blocked'];
				continue;
			}

			if ( 'failed' === $status ) {
				++$summary['failed'];
			}
		}

		return $summary;
	}

	/**
	 * @param array<int, array{row: array<string, mixed>, meta: array<string, mixed>}> $records
	 * @return array<int, array<string, mixed>>
	 */
	private static function load_admin_page_entries( array $records ): array {
		$stored_rows           = [];
		$activity_ids_to_fetch = [];

		foreach ( $records as $record ) {
			$row         = is_array( $record['row'] ?? null ) ? $record['row'] : [];
			$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );

			if ( '' === $activity_id ) {
				continue;
			}

			if ( self::row_contains_full_activity_payload( $row ) ) {
				$stored_rows[ $activity_id ] = $row;
				continue;
			}

			$activity_ids_to_fetch[] = $activity_id;
		}

		if ( [] !== $activity_ids_to_fetch ) {
			$stored_rows = array_merge(
				$stored_rows,
				self::find_rows_by_activity_ids( $activity_ids_to_fetch )
			);
		}

		return array_values(
			array_map(
				static fn ( array $record ): array => self::hydrate_admin_page_entry(
					$record,
					$stored_rows
				),
				$records
			)
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function row_contains_full_activity_payload( array $row ): bool {
		return array_key_exists( 'target_json', $row )
			&& array_key_exists( 'before_state', $row )
			&& array_key_exists( 'after_state', $row )
			&& array_key_exists( 'request_json', $row )
			&& array_key_exists( 'document_json', $row );
	}

	/**
	 * @param array{row: array<string, mixed>, meta: array<string, mixed>} $record
	 * @param array<string, array<string, mixed>> $stored_rows
	 */
	private static function hydrate_admin_page_entry( array $record, array $stored_rows ): array {
		$row             = is_array( $record['row'] ?? null ) ? $record['row'] : [];
		$activity_id     = trim( (string) ( $row['activity_id'] ?? '' ) );
		$stored_row      = $stored_rows[ $activity_id ] ?? null;
		$entry           = Serializer::hydrate_row( is_array( $stored_row ) ? $stored_row : $row );
		$entry['status'] = (string) ( $record['meta']['status'] ?? 'applied' );

		return $entry;
	}

	/**
	 * @param array<int, array{record: array{row: array<string, mixed>, meta: array<string, mixed>}, matches: array<string, bool>}> $evaluated_records
	 * @param array<string, mixed> $filter_context
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
		array $evaluated_records,
		array $filter_context
	): array {
		$definitions = [
			'surface'            => [
				'filterKey' => 'surface',
				'valueKey'  => 'surface',
				'labelKey'  => 'surfaceLabel',
			],
			'operationType'      => [
				'filterKey' => 'operationType',
				'valueKey'  => 'operationType',
				'labelKey'  => 'operationTypeLabel',
			],
			'postType'           => [
				'filterKey' => 'postType',
				'valueKey'  => 'postType',
				'labelKey'  => 'postType',
			],
			'userId'             => [
				'filterKey' => 'userId',
				'valueKey'  => 'userId',
				'labelKey'  => 'userLabel',
			],
			'provider'           => [
				'filterKey' => 'provider',
				'valueKey'  => 'provider',
				'labelKey'  => 'provider',
			],
			'providerPath'       => [
				'filterKey' => 'providerPath',
				'valueKey'  => 'providerPath',
				'labelKey'  => 'providerPath',
			],
			'configurationOwner' => [
				'filterKey' => 'configurationOwner',
				'valueKey'  => 'configurationOwner',
				'labelKey'  => 'configurationOwner',
			],
			'credentialSource'   => [
				'filterKey' => 'credentialSource',
				'valueKey'  => 'credentialSource',
				'labelKey'  => 'credentialSource',
			],
			'selectedProvider'   => [
				'filterKey' => 'selectedProvider',
				'valueKey'  => 'selectedProvider',
				'labelKey'  => 'selectedProvider',
			],
		];
		$options     = array_fill_keys( array_keys( $definitions ), [] );

		foreach ( $evaluated_records as $evaluated_record ) {
			$record = is_array( $evaluated_record['record'] ?? null )
				? $evaluated_record['record']
				: [];
			$meta   = is_array( $record['meta'] ?? null ) ? $record['meta'] : [];

			foreach ( $definitions as $option_key => $definition ) {
				if (
					! self::record_matches_admin_filters(
						$evaluated_record,
						$filter_context['activeFilters'],
						$definition['filterKey']
					)
				) {
					continue;
				}

				$value = trim( (string) ( $meta[ $definition['valueKey'] ] ?? '' ) );
				$label = trim(
					(string) ( $meta[ $definition['labelKey'] ] ?? $value )
				);

				if ( '' === $value || '' === $label ) {
					continue;
				}

				if ( 'userId' === $option_key && (int) $value <= 0 ) {
					continue;
				}

				if ( ! isset( $options[ $option_key ][ $value ] ) ) {
					$options[ $option_key ][ $value ] = $label;
				}
			}
		}

		return array_map(
			static function ( array $values ): array {
				asort( $values, SORT_NATURAL | SORT_FLAG_CASE );

				return array_values(
					array_map(
						static fn ( string $value, string $label ): array => [
							'value' => $value,
							'label' => $label,
						],
						array_keys( $values ),
						array_values( $values )
					)
				);
			},
			$options
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, mixed>
	 */
	private static function build_admin_filter_context(
		array $filters,
		\DateTimeZone $timezone
	): array {
		$context = [
			'filters'                    => $filters,
			'timezone'                   => $timezone,
			'search'                     => self::normalize_search_text( $filters['search'] ?? '' ),
			'surface'                    => trim( (string) ( $filters['surface'] ?? '' ) ),
			'surfaceOperator'            => self::normalize_filter_operator( $filters['surfaceOperator'] ?? 'is' ),
			'status'                     => trim( (string) ( $filters['status'] ?? '' ) ),
			'statusOperator'             => self::normalize_filter_operator( $filters['statusOperator'] ?? 'is' ),
			'postType'                   => trim( (string) ( $filters['postType'] ?? '' ) ),
			'postTypeOperator'           => self::normalize_filter_operator( $filters['postTypeOperator'] ?? 'is' ),
			'userId'                     => self::normalize_optional_int( $filters['userId'] ?? null ),
			'userIdOperator'             => self::normalize_filter_operator( $filters['userIdOperator'] ?? 'is' ),
			'operationType'              => trim( (string) ( $filters['operationType'] ?? '' ) ),
			'operationTypeOperator'      => self::normalize_filter_operator( $filters['operationTypeOperator'] ?? 'is' ),
			'provider'                   => trim( (string) ( $filters['provider'] ?? '' ) ),
			'providerOperator'           => self::normalize_filter_operator( $filters['providerOperator'] ?? 'is' ),
			'providerPath'               => trim( (string) ( $filters['providerPath'] ?? '' ) ),
			'providerPathOperator'       => self::normalize_filter_operator( $filters['providerPathOperator'] ?? 'is' ),
			'configurationOwner'         => trim( (string) ( $filters['configurationOwner'] ?? '' ) ),
			'configurationOwnerOperator' => self::normalize_filter_operator( $filters['configurationOwnerOperator'] ?? 'is' ),
			'credentialSource'           => trim( (string) ( $filters['credentialSource'] ?? '' ) ),
			'credentialSourceOperator'   => self::normalize_filter_operator( $filters['credentialSourceOperator'] ?? 'is' ),
			'selectedProvider'           => trim( (string) ( $filters['selectedProvider'] ?? '' ) ),
			'selectedProviderOperator'   => self::normalize_filter_operator( $filters['selectedProviderOperator'] ?? 'is' ),
			'entityId'                   => trim( (string) ( $filters['entityId'] ?? '' ) ),
			'entityIdOperator'           => self::normalize_text_filter_operator( $filters['entityIdOperator'] ?? 'contains' ),
			'blockPath'                  => trim( (string) ( $filters['blockPath'] ?? '' ) ),
			'blockPathOperator'          => self::normalize_text_filter_operator( $filters['blockPathOperator'] ?? 'contains' ),
		];

		$context['activeFilters'] = array_values(
			array_filter(
				[
					'' !== $context['search'] ? 'search' : null,
					'' !== $context['surface'] ? 'surface' : null,
					'' !== $context['status'] ? 'status' : null,
					'' !== $context['postType'] ? 'postType' : null,
					null !== $context['userId'] ? 'userId' : null,
					'' !== $context['operationType'] ? 'operationType' : null,
					'' !== $context['provider'] ? 'provider' : null,
					'' !== $context['providerPath'] ? 'providerPath' : null,
					'' !== $context['configurationOwner'] ? 'configurationOwner' : null,
					'' !== $context['credentialSource'] ? 'credentialSource' : null,
					'' !== $context['selectedProvider'] ? 'selectedProvider' : null,
					'' !== $context['entityId'] ? 'entityId' : null,
					'' !== $context['blockPath'] ? 'blockPath' : null,
					self::has_admin_day_filter( $filters ) ? 'day' : null,
				]
			)
		);

		return $context;
	}

	/**
	 * @param array<int, array{row: array<string, mixed>, meta: array<string, mixed>}> $records
	 * @param array<string, mixed> $filter_context
	 * @return array<int, array{record: array{row: array<string, mixed>, meta: array<string, mixed>}, matches: array<string, bool>}>
	 */
	private static function evaluate_admin_records(
		array $records,
		array $filter_context
	): array {
		return array_values(
			array_map(
				static fn ( array $record ): array => [
					'record'  => $record,
					'matches' => self::evaluate_admin_record_filters(
						$record,
						$filter_context
					),
				],
				$records
			)
		);
	}

	/**
	 * @param array{row: array<string, mixed>, meta: array<string, mixed>} $record
	 * @param array<string, mixed> $filter_context
	 * @return array<string, bool>
	 */
	private static function evaluate_admin_record_filters(
		array $record,
		array $filter_context
	): array {
		$meta = is_array( $record['meta'] ?? null ) ? $record['meta'] : [];

		return [
			'search'             => '' === $filter_context['search']
				|| str_contains(
					strtolower( (string) ( $meta['searchText'] ?? '' ) ),
					(string) $filter_context['search']
				),
			'surface'            => '' === $filter_context['surface']
				|| self::matches_explicit_filter(
					(string) ( $meta['surface'] ?? '' ),
					(string) $filter_context['surface'],
					(string) $filter_context['surfaceOperator']
				),
			'status'             => '' === $filter_context['status']
				|| self::matches_explicit_filter(
					(string) ( $meta['status'] ?? '' ),
					(string) $filter_context['status'],
					(string) $filter_context['statusOperator']
				),
			'postType'           => '' === $filter_context['postType']
				|| self::matches_explicit_filter(
					(string) ( $meta['postType'] ?? '' ),
					(string) $filter_context['postType'],
					(string) $filter_context['postTypeOperator']
				),
			'userId'             => null === $filter_context['userId']
				|| self::matches_explicit_filter(
					(string) (int) ( $meta['userId'] ?? 0 ),
					(string) $filter_context['userId'],
					(string) $filter_context['userIdOperator']
				),
			'operationType'      => '' === $filter_context['operationType']
				|| self::matches_explicit_filter(
					(string) ( $meta['operationType'] ?? '' ),
					(string) $filter_context['operationType'],
					(string) $filter_context['operationTypeOperator']
				),
			'provider'           => '' === $filter_context['provider']
				|| self::matches_explicit_filter(
					(string) ( $meta['provider'] ?? '' ),
					(string) $filter_context['provider'],
					(string) $filter_context['providerOperator']
				),
			'providerPath'       => '' === $filter_context['providerPath']
				|| self::matches_explicit_filter(
					(string) ( $meta['providerPath'] ?? '' ),
					(string) $filter_context['providerPath'],
					(string) $filter_context['providerPathOperator']
				),
			'configurationOwner' => '' === $filter_context['configurationOwner']
				|| self::matches_explicit_filter(
					(string) ( $meta['configurationOwner'] ?? '' ),
					(string) $filter_context['configurationOwner'],
					(string) $filter_context['configurationOwnerOperator']
				),
			'credentialSource'   => '' === $filter_context['credentialSource']
				|| self::matches_explicit_filter(
					(string) ( $meta['credentialSource'] ?? '' ),
					(string) $filter_context['credentialSource'],
					(string) $filter_context['credentialSourceOperator']
				),
			'selectedProvider'   => '' === $filter_context['selectedProvider']
				|| self::matches_explicit_filter(
					(string) ( $meta['selectedProvider'] ?? '' ),
					(string) $filter_context['selectedProvider'],
					(string) $filter_context['selectedProviderOperator']
				),
			'entityId'           => '' === $filter_context['entityId']
				|| self::matches_text_filter(
					(string) ( $meta['entityId'] ?? '' ),
					(string) $filter_context['entityId'],
					(string) $filter_context['entityIdOperator']
				),
			'blockPath'          => '' === $filter_context['blockPath']
				|| self::matches_text_filter(
					(string) ( $meta['blockPath'] ?? '' ),
					(string) $filter_context['blockPath'],
					(string) $filter_context['blockPathOperator']
				),
			'day'                => self::matches_day_filter(
				$meta,
				(array) $filter_context['filters'],
				$filter_context['timezone']
			),
		];
	}

	/**
	 * @param array{record: array{row: array<string, mixed>, meta: array<string, mixed>}, matches: array<string, bool>} $evaluated_record
	 * @param array<int, string> $active_filters
	 */
	private static function record_matches_admin_filters(
		array $evaluated_record,
		array $active_filters,
		?string $excluded_filter = null
	): bool {
		$matches = is_array( $evaluated_record['matches'] ?? null )
			? $evaluated_record['matches']
			: [];

		foreach ( $active_filters as $filter_key ) {
			if ( $filter_key === $excluded_filter ) {
				continue;
			}

			if ( ! ( $matches[ $filter_key ] ?? false ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, string> $activity_ids
	 * @return array<string, array<string, mixed>>
	 */
	private static function find_rows_by_activity_ids( array $activity_ids ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return [];
		}

		$activity_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( $activity_id ): string => trim( (string) $activity_id ),
						$activity_ids
					)
				)
			)
		);

		if ( [] === $activity_ids ) {
			return [];
		}

		$table_name = self::table_name();

		if ( 1 === count( $activity_ids ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads a single activity row needed to hydrate the current admin page.
			$row  = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE activity_id = %s LIMIT 1',
					$table_name,
					$activity_ids[0]
				),
				ARRAY_A
			);
			$rows = is_array( $row ) ? [ $row ] : [];
		} elseif ( self::activity_ids_have_list_delimiter_conflicts( $activity_ids ) ) {
			$rows = [];

			foreach ( $activity_ids as $activity_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Falls back to exact lookups when an activity ID contains the list delimiter.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE activity_id = %s LIMIT 1',
						$table_name,
						$activity_id
					),
					ARRAY_A
				);

				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads a bounded set of activity rows needed to hydrate the current admin page.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE FIND_IN_SET(activity_id, %s) > 0',
					$table_name,
					implode( ',', $activity_ids )
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$indexed_rows = [];

		foreach ( $rows as $row ) {
			$activity_id = trim( (string) ( $row['activity_id'] ?? '' ) );

			if ( '' === $activity_id ) {
				continue;
			}

			$indexed_rows[ $activity_id ] = $row;
		}

		return $indexed_rows;
	}

	/**
	 * @param array<int, string> $activity_ids
	 */
	private static function activity_ids_have_list_delimiter_conflicts( array $activity_ids ): bool {
		foreach ( $activity_ids as $activity_id ) {
			if ( str_contains( $activity_id, ',' ) ) {
				return true;
			}
		}

		return false;
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
		$projected_post_type = trim( (string) ( $row['admin_post_type'] ?? '' ) );

		if ( '' !== $projected_post_type ) {
			return $projected_post_type;
		}

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
		$projected_entity_id = trim( (string) ( $row['admin_entity_id'] ?? '' ) );

		if ( '' !== $projected_entity_id ) {
			return $projected_entity_id;
		}

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
		string $activity_type,
		array $row = []
	): array {
		$projected_operation_type  = trim( (string) ( $row['admin_operation_type'] ?? '' ) );
		$projected_operation_label = trim( (string) ( $row['admin_operation_label'] ?? '' ) );

		if ( '' !== $projected_operation_type && '' !== $projected_operation_label ) {
			return [
				'value' => $projected_operation_type,
				'label' => $projected_operation_label,
			];
		}

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
	 * @param array<string, mixed> $row
	 * @return array{
	 *   provider: string,
	 *   model: string,
	 *   providerPath: string,
	 *   configurationOwner: string,
	 *   credentialSource: string,
	 *   selectedProvider: string,
	 *   requestAbility: string,
	 *   requestRoute: string
	 * }
	 */
	private static function get_admin_request_meta( array $request, array $row = [] ): array {
		return [
			'provider'           => self::get_first_string(
				$request,
				[
					[ 'ai', 'backendLabel' ],
					[ 'ai', 'providerLabel' ],
					[ 'provider' ],
					[ 'providerName' ],
					[ 'metadata', 'provider' ],
					[ 'ai', 'provider' ],
					[ 'result', 'provider' ],
				],
				trim( (string) ( $row['admin_provider'] ?? '' ) )
			),
			'model'              => self::get_first_string(
				$request,
				[
					[ 'ai', 'model' ],
					[ 'model' ],
					[ 'modelName' ],
					[ 'metadata', 'model' ],
					[ 'result', 'model' ],
				],
				trim( (string) ( $row['admin_model'] ?? '' ) )
			),
			'providerPath'       => self::get_first_string(
				$request,
				[
					[ 'ai', 'pathLabel' ],
					[ 'pathLabel' ],
				],
				trim( (string) ( $row['admin_provider_path'] ?? '' ) )
			),
			'configurationOwner' => self::get_first_string(
				$request,
				[
					[ 'ai', 'ownerLabel' ],
					[ 'ownerLabel' ],
				],
				trim( (string) ( $row['admin_configuration_owner'] ?? '' ) )
			),
			'credentialSource'   => self::get_first_string(
				$request,
				[
					[ 'ai', 'credentialSourceLabel' ],
					[ 'ai', 'credentialSource' ],
					[ 'credentialSourceLabel' ],
					[ 'credentialSource' ],
				],
				trim( (string) ( $row['admin_credential_source'] ?? '' ) )
			),
			'selectedProvider'   => self::get_first_string(
				$request,
				[
					[ 'ai', 'selectedProviderLabel' ],
					[ 'ai', 'selectedProvider' ],
					[ 'selectedProviderLabel' ],
					[ 'selectedProvider' ],
				],
				trim( (string) ( $row['admin_selected_provider'] ?? '' ) )
			),
			'requestAbility'     => self::get_first_string(
				$request,
				[
					[ 'ai', 'ability' ],
					[ 'ability' ],
				],
				trim( (string) ( $row['admin_request_ability'] ?? '' ) )
			),
			'requestRoute'       => self::get_first_string(
				$request,
				[
					[ 'ai', 'route' ],
					[ 'route' ],
				],
				trim( (string) ( $row['admin_request_route'] ?? '' ) )
			),
		];
	}

	/**
	 * @param array<string, mixed> $value
	 * @param array<int, array<int, string>> $paths
	 */
	private static function get_first_string( array $value, array $paths, string $fallback = '' ): string {
		if ( '' !== trim( $fallback ) ) {
			return trim( $fallback );
		}

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
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $target
	 * @param array{
	 *   provider: string,
	 *   model: string,
	 *   providerPath: string,
	 *   configurationOwner: string,
	 *   credentialSource: string,
	 *   selectedProvider: string,
	 *   requestAbility: string,
	 *   requestRoute: string
	 * } $request_meta
	 */
	private static function resolve_admin_base_search_text(
		array $row,
		string $surface,
		array $target,
		string $post_type,
		string $entity_id,
		string $block_path,
		string $operation_type,
		string $operation_label,
		array $request_meta,
		string $request_prompt,
		string $request_reference
	): string {
		$projected_search_text = trim( (string) ( $row['admin_search_text'] ?? '' ) );

		if ( '' !== $projected_search_text ) {
			return $projected_search_text;
		}

		return self::build_admin_base_search_text(
			trim( (string) ( $row['suggestion'] ?? '' ) ),
			trim( (string) ( $row['entity_ref'] ?? '' ) ),
			$surface,
			$target,
			$post_type,
			$entity_id,
			$block_path,
			trim( (string) ( $row['activity_type'] ?? '' ) ),
			$operation_type,
			$operation_label,
			$request_meta,
			$request_prompt,
			$request_reference
		);
	}

	/**
	 * @param array<string, mixed> $target
	 * @param array{
	 *   provider: string,
	 *   model: string,
	 *   providerPath: string,
	 *   configurationOwner: string,
	 *   credentialSource: string,
	 *   selectedProvider: string,
	 *   requestAbility: string,
	 *   requestRoute: string
	 * } $request_meta
	 */
	private static function build_admin_base_search_text(
		string $suggestion,
		string $entity_ref,
		string $surface,
		array $target,
		string $post_type,
		string $entity_id,
		string $block_path,
		string $activity_type,
		string $operation_type,
		string $operation_label,
		array $request_meta,
		string $request_prompt,
		string $request_reference
	): string {
		return implode(
			' ',
			array_filter(
				[
					$suggestion,
					$entity_ref,
					self::build_entity_search_label( $surface, $target ),
					self::build_document_search_label( $post_type, $entity_id ),
					$block_path,
					$activity_type,
					...self::build_admin_operation_labels(
						$operation_type,
						$operation_label
					),
					$request_prompt,
					$request_reference,
					$request_meta['provider'],
					$request_meta['model'],
					$request_meta['providerPath'],
					$request_meta['configurationOwner'],
					$request_meta['credentialSource'],
					$request_meta['selectedProvider'],
					$request_meta['requestAbility'],
					$request_meta['requestRoute'],
				],
				static fn ( $value ): bool => is_string( $value ) && '' !== trim( $value )
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function build_admin_operation_labels(
		string $operation_type,
		string $operation_label
	): array {
		return array_values(
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
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return (string) $result === $table_name;
	}
}
