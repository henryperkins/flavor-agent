<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Append-only, retention-independent store for Ring III attestations.
 * Deliberately has no update/delete and is not registered with the activity
 * prune cron: durability is the proof.
 */
final class Repository {

	public const SCHEMA_OPTION  = 'flavor_agent_attestation_schema_version';
	public const SCHEMA_VERSION = 1;

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'flavor_agent_attestations';
	}

	public static function maybe_install(): void {
		if ( (int) \get_option( self::SCHEMA_OPTION, 0 ) < self::SCHEMA_VERSION || ! self::table_exists() ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return;
		}

		$table   = self::table_name();
		$charset = method_exists( $wpdb, 'get_charset_collate' )
			? (string) $wpdb->get_charset_collate()
			: '';
		$sql     = "CREATE TABLE {$table} (
			attestation_id varchar(64) NOT NULL,
			schema_version smallint NOT NULL DEFAULT 1,
			surface varchar(40) NOT NULL,
			subject_name varchar(191) NOT NULL,
			subject_scope varchar(40) NOT NULL,
			after_digest char(64) NOT NULL,
			statement_bytes longtext NOT NULL,
			signature_b64 text NOT NULL,
			key_id varchar(64) NOT NULL,
			reverts_attestation_id varchar(64) DEFAULT NULL,
			supersedes_attestation_id varchar(64) DEFAULT NULL,
			related_activity_id varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (attestation_id),
			KEY subject_name (subject_name),
			KEY reverts_attestation_id (reverts_attestation_id),
			KEY related_activity_id (related_activity_id)
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned schema creation for migration and activation paths.
			$wpdb->query( $sql );
		}

		\update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function insert( array $row ): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$record = [
			'attestation_id'            => (string) $row['attestation_id'],
			'schema_version'            => self::SCHEMA_VERSION,
			'surface'                   => (string) $row['surface'],
			'subject_name'              => (string) $row['subject_name'],
			'subject_scope'             => (string) $row['subject_scope'],
			'after_digest'              => (string) $row['after_digest'],
			'statement_bytes'           => (string) $row['statement_bytes'],
			'signature_b64'             => (string) $row['signature_b64'],
			'key_id'                    => (string) $row['key_id'],
			'reverts_attestation_id'    => isset( $row['reverts_attestation_id'] ) ? (string) $row['reverts_attestation_id'] : null,
			'supersedes_attestation_id' => isset( $row['supersedes_attestation_id'] ) ? (string) $row['supersedes_attestation_id'] : null,
			'related_activity_id'       => isset( $row['related_activity_id'] ) ? (string) $row['related_activity_id'] : null,
			'created_at'                => gmdate( 'Y-m-d H:i:s' ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin-owned attestation table must execute immediately.
		return false !== $wpdb->insert( self::table_name(), $record );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read from plugin-owned attestation table with prepared id.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE attestation_id = %s', $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find_by_reverts( string $id ): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read from plugin-owned attestation table with prepared id.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE reverts_attestation_id = %s ORDER BY created_at DESC', $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find_by_related_activity( string $activity_id ): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read from plugin-owned attestation table with prepared id.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE related_activity_id = %s AND reverts_attestation_id IS NULL ORDER BY created_at DESC', $activity_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private static function table_exists(): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$table = self::table_name();

		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function __construct() {}
}
