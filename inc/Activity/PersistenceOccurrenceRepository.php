<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Private saved versions and durable verification cursors. */
final class PersistenceOccurrenceRepository {

	public const SCHEMA_OPTION  = 'flavor_agent_save_occurrence_schema_version';
	public const SCHEMA_VERSION = 1;
	public const AGE_OPTION     = 'flavor_agent_save_verification_age_days';
	public const CRON_HOOK      = 'flavor_agent_verify_saved_applies';
	public const BATCH_SIZE     = 25;

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'flavor_agent_save_occurrences';
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) < self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			snapshot_id varchar(64) NOT NULL,
			occurrence_id varchar(64) NOT NULL,
			entity_key varchar(64) NOT NULL,
			admin_post_type varchar(64) NOT NULL,
			admin_entity_id varchar(191) NOT NULL,
			entity_json longtext NOT NULL,
			metadata_json longtext NOT NULL,
			snapshot_json longtext NULL,
			candidate_before_id bigint(20) unsigned NOT NULL,
			candidate_since datetime NOT NULL,
			cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			has_inconclusive tinyint(1) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot_id (snapshot_id),
			UNIQUE KEY occurrence_entity (occurrence_id, entity_key),
			KEY eligibility (entity_key, candidate_before_id),
			KEY pending (status, id),
			KEY expires_at (expires_at)
		) {$charset}";
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			\dbDelta( $sql );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned schema; table and collation come from wpdb.
			$wpdb->query( $sql );
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function age_seconds(): int {
		$seconds   = max( 60, min( 604800, (int) ( (float) get_option( self::AGE_OPTION, 7 ) * 86400 ) ) );
		$retention = (int) get_option( 'flavor_agent_activity_retention_days', Repository::DEFAULT_RETENTION_DAYS );
		return $retention > 0 ? min( $seconds, max( 60, $retention * 86400 - 60 ) ) : $seconds;
	}

	public static function entity_key( array $entity ): string {
		return hash( 'sha256', (string) ( $entity['postType'] ?? '' ) . ':' . (string) ( $entity['ref'] ?? '' ) );
	}

	/** Capture precedes any comparison; its auto-increment ID orders all saves. */
	public static function capture( array $snapshot ): array|\WP_Error|null {
		global $wpdb;
		$entity     = is_array( $snapshot['entity'] ?? null ) ? $snapshot['entity'] : [];
		$occurrence = (string) ( $snapshot['saveOccurrenceId'] ?? '' );
		if ( '' === ( $entity['postType'] ?? '' ) || '' === ( $entity['ref'] ?? '' ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $occurrence ) ) {
			return new \WP_Error( 'flavor_agent_persistence_invalid_capture', 'The saved entity could not be identified.' );
		}
		$existing = self::find_occurrence( $occurrence, $entity );
		if ( is_array( $existing ) ) {
			return $existing;
		}
		$snapshot['candidateSince']    ??= gmdate( 'Y-m-d H:i:s', time() - self::age_seconds() );
		$snapshot['candidateBeforeId'] ??= PHP_INT_MAX;
		$last                            = self::candidate_rows( $snapshot, 0, 1, 'DESC' );
		if ( [] === $last ) {
			return null;
		}
		$snapshot['candidateBeforeId']  = (int) $last[0]['id'];
		$snapshot['snapshotId']         = 'save-' . bin2hex( random_bytes( 16 ) );
		$snapshot['capturedAt']         = gmdate( 'c' );
		$snapshot['contentFingerprint'] = hash( 'sha256', (string) ( $snapshot['content'] ?? '' ) );
		$payload                        = [
			'content' => (string) ( $snapshot['content'] ?? '' ),
			'schemas' => $snapshot['schemas'] ?? [],
		];
		$metadata                       = $snapshot;
		unset( $metadata['content'], $metadata['schemas'], $metadata['entity'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Durable private capture must precede asynchronous verification.
		$inserted = $wpdb->insert(
			self::table_name(),
			[
				'snapshot_id'         => $snapshot['snapshotId'],
				'occurrence_id'       => $occurrence,
				'entity_key'          => self::entity_key( $entity ),
				'admin_post_type'     => 'wp_global_styles' === $entity['postType'] ? 'global_styles' : $entity['postType'],
				'admin_entity_id'     => (string) $entity['ref'],
				'entity_json'         => Serializer::encode_json( $entity ),
				'metadata_json'       => Serializer::encode_json( $metadata ),
				'snapshot_json'       => Serializer::encode_json( $payload ),
				'candidate_before_id' => $snapshot['candidateBeforeId'],
				'candidate_since'     => $snapshot['candidateSince'],
				'cursor_id'           => 0,
				'has_inconclusive'    => 0,
				'status'              => 'pending',
				'created_at'          => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'          => gmdate( 'Y-m-d H:i:s', time() + self::age_seconds() ),
			]
		);
		if ( false === $inserted ) {
			return self::find_occurrence( $occurrence, $entity ) ?? new \WP_Error( 'flavor_agent_persistence_capture_failed', 'The saved version could not be recorded.' );
		}
		self::schedule();
		return self::find( $snapshot['snapshotId'] );
	}

	public static function find( string $snapshot_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private snapshot lookup by its unique key.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE snapshot_id = %s', self::table_name(), $snapshot_id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	public static function find_occurrence( string $occurrence_id, array $entity ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Correlation is scoped to the canonical physical entity.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id = %s AND entity_key = %s', self::table_name(), $occurrence_id, self::entity_key( $entity ) ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/** Indexed keyset pagination freezes membership without a truncated ID list. */
	public static function candidate_rows( array $snapshot, int $cursor, int $limit = self::BATCH_SIZE, string $direction = 'ASC' ): array {
		global $wpdb;
		$entity    = $snapshot['entity'] ?? [];
		$post_type = 'wp_global_styles' === ( $entity['postType'] ?? '' ) ? 'global_styles' : (string) ( $entity['postType'] ?? '' );
		$sql       = 'SELECT * FROM %i WHERE apply_lane = %s AND admin_post_type = %s AND admin_entity_id = %s AND id > %d AND id <= %d AND created_at >= %s ORDER BY id ' . ( 'DESC' === $direction ? 'DESC' : 'ASC' ) . ' LIMIT %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed SQL, prepared arguments, bounded keyset page.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, Repository::table_name(), 'editor-state', $post_type, (string) ( $entity['ref'] ?? '' ), $cursor, (int) ( $snapshot['candidateBeforeId'] ?? 0 ), (string) ( $snapshot['candidateSince'] ?? '' ), max( 1, min( self::BATCH_SIZE, $limit ) ) ), ARRAY_A );
		return array_values( array_filter( is_array( $rows ) ? $rows : [], static fn ( array $row ): bool => in_array( $row['activity_type'] ?? '', PersistenceOutcome::APPLY_TYPES, true ) ) );
	}

	public static function pending(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One private occurrence per worker invocation, oldest capture first.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d', self::table_name(), 'pending', 1 ), ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], is_array( $rows ) ? $rows : [] );
	}

	/** Compare-and-swap prevents an overlapping worker from moving a cursor back. */
	public static function advance( array $snapshot, int $cursor, bool $complete, bool $conclusive ): void {
		global $wpdb;
		$inconclusive = ! $conclusive || ! empty( $snapshot['hasInconclusive'] );
		$changes      = [
			'cursor_id'        => $cursor,
			'status'           => $complete ? 'complete' : 'pending',
			'has_inconclusive' => (int) $inconclusive,
		];
		if ( $complete && ! $inconclusive ) {
			$changes['snapshot_json'] = null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Durable continuation and payload cleanup retain eligibility metadata.
		$wpdb->update(
			self::table_name(),
			$changes,
			[
				'snapshot_id' => $snapshot['snapshotId'],
				'cursor_id'   => (int) ( $snapshot['cursorId'] ?? 0 ),
			]
		);
	}

	/** Coverage comes from durable capture boundaries, never client attempts. */
	public static function eligibility( array $apply ): array {
		global $wpdb;
		$unknown = [
			'eligible'           => false,
			'pending'            => false,
			'latestSequence'     => 0,
			'latestOccurrenceId' => null,
		];
		if ( 'editor-state' !== ( $apply['applyLane'] ?? null ) || ! in_array( $apply['type'] ?? '', PersistenceOutcome::APPLY_TYPES, true ) ) {
			return $unknown;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stored insertion ID establishes capture membership independently of presentation time.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, created_at FROM %i WHERE activity_id = %s', Repository::table_name(), (string) ( $apply['id'] ?? '' ) ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return $unknown;
		}
		$entity = self::entity_for_apply( $apply );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed per-entity metadata only; no private comparison payload is read or returned.
		$occurrences = $wpdb->get_results( $wpdb->prepare( 'SELECT id, occurrence_id, candidate_before_id, candidate_since, status, expires_at FROM %i WHERE entity_key = %s ORDER BY id DESC', self::table_name(), self::entity_key( $entity ) ), ARRAY_A );
		foreach ( is_array( $occurrences ) ? $occurrences : [] as $occurrence ) {
			if ( (int) $occurrence['candidate_before_id'] < (int) $row['id'] || (string) $occurrence['candidate_since'] > (string) $row['created_at'] ) {
				continue;
			}
			$unknown['eligible'] = true;
			$unknown['pending']  = $unknown['pending'] || ( 'pending' === $occurrence['status'] && strtotime( $occurrence['expires_at'] . ' UTC' ) > time() );
			if ( (int) $occurrence['id'] > $unknown['latestSequence'] ) {
				$unknown['latestSequence']     = (int) $occurrence['id'];
				$unknown['latestOccurrenceId'] = (string) $occurrence['occurrence_id'];
			}
		}
		return $unknown;
	}

	public static function entity_for_apply( array $apply ): array {
		$document  = is_array( $apply['document'] ?? null ) ? $apply['document'] : [];
		$post_type = (string) ( $document['postType'] ?? '' );
		return [
			'postType' => 'global_styles' === $post_type ? 'wp_global_styles' : $post_type,
			'ref'      => (string) ( $document['entityId'] ?? '' ),
		];
	}

	public static function expire( array $snapshot ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Expiry removes private payload, never fabricates a comparison verdict.
		$wpdb->update(
			self::table_name(),
			[
				'status'        => 'expired',
				'snapshot_json' => null,
			],
			[ 'snapshot_id' => $snapshot['snapshotId'] ]
		);
	}

	/** Daily maintenance erases private payloads and metadata with no surviving apply. */
	public static function cleanup(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An indexed expiry sweep erases content without inventing outcomes.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET snapshot_json = NULL, status = CASE WHEN status = 'pending' THEN 'expired' ELSE status END WHERE expires_at <= %s", self::table_name(), gmdate( 'Y-m-d H:i:s' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The candidate index bounds each existence check; metadata survives exactly as long as eligible activity.
		$wpdb->query( $wpdb->prepare( "DELETE occurrence FROM %i AS occurrence WHERE NOT EXISTS (SELECT 1 FROM %i AS original WHERE original.apply_lane = 'editor-state' AND original.admin_post_type = occurrence.admin_post_type AND original.admin_entity_id = occurrence.admin_entity_id AND original.id <= occurrence.candidate_before_id AND original.created_at >= occurrence.candidate_since)", self::table_name(), Repository::table_name() ) );
	}

	public static function schedule( int $delay = 1 ): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::CRON_HOOK );
		}
	}

	private static function hydrate( array $row ): array {
		return [
			...Serializer::decode_json( (string) ( $row['metadata_json'] ?? '' ) ),
			...Serializer::decode_json( (string) ( $row['snapshot_json'] ?? '' ) ),
			'entity'            => Serializer::decode_json( (string) ( $row['entity_json'] ?? '' ) ),
			'snapshotId'        => (string) $row['snapshot_id'],
			'saveOccurrenceId'  => (string) $row['occurrence_id'],
			'saveSequence'      => (int) $row['id'],
			'candidateBeforeId' => (int) $row['candidate_before_id'],
			'candidateSince'    => (string) $row['candidate_since'],
			'cursorId'          => (int) $row['cursor_id'],
			'hasInconclusive'   => ! empty( $row['has_inconclusive'] ),
			'status'            => (string) $row['status'],
			'expiresAt'         => (string) $row['expires_at'],
		];
	}
}
