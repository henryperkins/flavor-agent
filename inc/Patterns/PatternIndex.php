<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\Context\ServerCollector;

final class PatternIndex {

	public const STATE_OPTION = 'flavor_agent_pattern_index_state';
	public const CRON_HOOK    = 'flavor_agent_reindex_patterns';

	private const LOCK_TRANSIENT = 'flavor_agent_sync_lock';
	private const LOCK_TTL       = 300;
	private const COOLDOWN       = 300;
	private const BATCH_SIZE     = 100;

	/** Increment when the embedding text template changes. */
	public const EMBEDDING_RECIPE_VERSION = 1;

	/** Fixed UUID v5 namespace (DNS namespace from RFC 4122). */
	private const UUID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

	public static function get_state(): array {
		$defaults = [
			'status'               => 'uninitialized',
			'fingerprint'          => '',
			'qdrant_url'           => '',
			'qdrant_collection'    => '',
			'azure_openai_endpoint'=> '',
			'embedding_deployment' => '',
			'last_synced_at'       => null,
			'last_attempt_at'      => null,
			'indexed_count'        => 0,
			'last_error'           => null,
			'pattern_fingerprints' => [],
		];

		return wp_parse_args( get_option( self::STATE_OPTION, $defaults ), $defaults );
	}

	public static function get_runtime_state(): array {
		$state = self::get_state();

		if ( $state['status'] !== 'ready' ) {
			return $state;
		}

		$is_stale = $state['qdrant_url'] !== get_option( 'flavor_agent_qdrant_url', '' )
			|| $state['qdrant_collection'] !== QdrantClient::get_collection_name()
			|| $state['azure_openai_endpoint'] !== get_option( 'flavor_agent_azure_openai_endpoint', '' )
			|| $state['embedding_deployment'] !== get_option( 'flavor_agent_azure_embedding_deployment', '' );

		if ( $is_stale ) {
			$state['status'] = 'stale';
			self::save_state( $state );
		}

		return $state;
	}

	public static function has_usable_index( array $state ): bool {
		return ! empty( $state['last_synced_at'] );
	}

	public static function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function recommendation_backends_configured(): bool {
		return (bool) (
			get_option( 'flavor_agent_azure_openai_endpoint', '' )
			&& get_option( 'flavor_agent_azure_openai_key', '' )
			&& get_option( 'flavor_agent_azure_embedding_deployment', '' )
			&& get_option( 'flavor_agent_qdrant_url', '' )
			&& get_option( 'flavor_agent_qdrant_key', '' )
		);
	}

	public static function mark_dirty(): void {
		$state = self::get_state();

		if ( $state['status'] === 'indexing' ) {
			return;
		}

		$state['status'] = self::has_usable_index( $state ) ? 'stale' : 'uninitialized';
		self::save_state( $state );
	}

	public static function handle_registry_change( ...$args ): void {
			self::mark_dirty();
			self::schedule_sync( true );
	}

	public static function handle_dependency_change( ...$args ): void {
			self::mark_dirty();
			self::schedule_sync( true );
	}

	public static function activate(): void {
		self::mark_dirty();
		self::schedule_sync( true );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::release_lock();
	}

	/**
	 * Compute a fingerprint that changes when any pattern metadata, content,
	 * or the embedding recipe version changes.
	 */
	public static function compute_fingerprint( array $patterns ): string {
		$entries = array_map( [ __CLASS__, 'compute_pattern_fingerprint' ], $patterns );
		sort( $entries );
		return md5( implode( "\n", $entries ) );
	}

	/**
	 * Deterministic UUID v5 from a pattern name.
	 */
	public static function pattern_uuid( string $name ): string {
		$ns_bytes = pack( 'H*', str_replace( '-', '', self::UUID_NAMESPACE ) );
		$hash     = sha1( $ns_bytes . $name );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			'5' . substr( $hash, 13, 3 ),
			dechex( 0x80 | ( hexdec( substr( $hash, 16, 2 ) ) & 0x3f ) ) . substr( $hash, 18, 2 ),
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Build condensed text representation for embedding (~500-800 tokens).
	 */
	public static function build_embedding_text( array $pattern ): string {
		$parts = [
			$pattern['title'] ?? '',
			$pattern['description'] ?? '',
		];

		$cats = $pattern['categories'] ?? [];
		if ( ! empty( $cats ) ) {
			$parts[] = 'Categories: ' . implode( ', ', $cats );
		}

		$bt = $pattern['blockTypes'] ?? [];
		if ( ! empty( $bt ) ) {
			$parts[] = 'Block types: ' . implode( ', ', $bt );
		}

		$tt = $pattern['templateTypes'] ?? [];
		if ( ! empty( $tt ) ) {
			$parts[] = 'Template types: ' . implode( ', ', $tt );
		}

		$content = $pattern['content'] ?? '';
		if ( $content !== '' ) {
			$parts[] = substr( $content, 0, 500 );
		}

		return implode( "\n", array_filter( $parts ) );
	}

	/**
	 * Run a full sync. Called by the manual REST route and cron hook.
	 *
	 * @return array|\WP_Error Sync result with indexed/removed counts.
	 */
	public static function sync(): array|\WP_Error {
		if ( ! self::acquire_lock() ) {
			return new \WP_Error( 'sync_locked', 'A sync is already in progress.', [ 'status' => 409 ] );
		}

		try {
			return self::do_sync();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Schedule a single background sync event with cooldown protection.
	 */
	public static function schedule_sync( bool $force = false ): void {
			$hook = self::CRON_HOOK;

		if ( ! self::recommendation_backends_configured() ) {
			return;
		}

		if ( wp_next_scheduled( $hook ) ) {
			return;
		}

		if ( ! $force ) {
			$state = self::get_state();
			if ( ! empty( $state['last_attempt_at'] ) ) {
				$last = strtotime( $state['last_attempt_at'] );
				if ( $last && ( time() - $last ) < self::COOLDOWN ) {
					return;
				}
			}
		}

		wp_schedule_single_event( time() + 5, $hook );
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	private static function acquire_lock(): bool {
		if ( get_transient( self::LOCK_TRANSIENT ) !== false ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );
		return true;
	}

	private static function release_lock(): void {
		delete_transient( self::LOCK_TRANSIENT );
	}

	private static function do_sync(): array|\WP_Error {
		// Step 3: Read all registered patterns.
		$patterns    = ServerCollector::for_patterns();
		$fingerprint = self::compute_fingerprint( $patterns );

		// Steps 4-6: Determine if re-index is needed.
		$state                = self::get_state();
		$qdrant_url           = get_option( 'flavor_agent_qdrant_url', '' );
		$qdrant_collection    = QdrantClient::get_collection_name();
		$azure_openai_endpoint = get_option( 'flavor_agent_azure_openai_endpoint', '' );
		$embedding_deployment = get_option( 'flavor_agent_azure_embedding_deployment', '' );

		$needs_reindex = $state['status'] !== 'ready'
			|| $state['fingerprint'] !== $fingerprint
			|| $state['qdrant_url'] !== $qdrant_url
			|| $state['qdrant_collection'] !== $qdrant_collection
			|| $state['azure_openai_endpoint'] !== $azure_openai_endpoint
			|| $state['embedding_deployment'] !== $embedding_deployment;

		if ( ! $needs_reindex ) {
			return [
				'indexed'     => $state['indexed_count'],
				'removed'     => 0,
				'fingerprint' => $fingerprint,
				'status'      => 'ready',
			];
		}

		$current                    = [];
		$current_pattern_fingerprints = [];
		foreach ( $patterns as $pattern ) {
			$uuid                                = self::pattern_uuid( $pattern['name'] ?? '' );
			$current[ $uuid ]                    = $pattern;
			$current_pattern_fingerprints[ $uuid ] = self::compute_pattern_fingerprint( $pattern );
		}

		$previous_pattern_fingerprints = is_array( $state['pattern_fingerprints'] ?? null )
			? $state['pattern_fingerprints']
			: [];
		$requires_full_reindex         = ! self::has_usable_index( $state )
			|| $state['qdrant_url'] !== $qdrant_url
			|| $state['qdrant_collection'] !== $qdrant_collection
			|| $state['azure_openai_endpoint'] !== $azure_openai_endpoint
			|| $state['embedding_deployment'] !== $embedding_deployment
			|| empty( $previous_pattern_fingerprints );

		// Step 7: Mark indexing before remote work starts.
		$state['status']          = 'indexing';
		$state['last_attempt_at'] = gmdate( 'c' );
		$state['last_error']      = null;
		self::save_state( $state );

		// Step 2: Ensure collection.
		$ensure = QdrantClient::ensure_collection();
		if ( is_wp_error( $ensure ) ) {
			self::save_error_state( $ensure->get_error_message() );
			return $ensure;
		}

		// Step 8: Diff existing vs. current.
		$existing_ids = QdrantClient::scroll_ids();
		if ( is_wp_error( $existing_ids ) ) {
			self::save_error_state( $existing_ids->get_error_message() );
			return $existing_ids;
		}

		$to_delete = [];
		foreach ( $existing_ids as $uuid => $name ) {
			if ( ! isset( $current[ $uuid ] ) ) {
				$to_delete[] = $uuid;
			}
		}

		$uuids_to_embed = [];
		foreach ( $current_pattern_fingerprints as $uuid => $pattern_fingerprint ) {
			if (
				$requires_full_reindex
				|| ! isset( $previous_pattern_fingerprints[ $uuid ] )
				|| $previous_pattern_fingerprints[ $uuid ] !== $pattern_fingerprint
			) {
				$uuids_to_embed[] = $uuid;
			}
		}

		$all_points  = [];
		$batch_texts = [];
		$batch_uuids = [];

		foreach ( $uuids_to_embed as $uuid ) {
			$p             = $current[ $uuid ];
			$batch_texts[] = self::build_embedding_text( $p );
			$batch_uuids[] = $uuid;

			if ( count( $batch_texts ) >= self::BATCH_SIZE ) {
				$points = self::embed_and_build_points( $batch_texts, $batch_uuids, $current );
				if ( is_wp_error( $points ) ) {
					self::save_error_state( $points->get_error_message() );
					return $points;
				}
				$all_points  = array_merge( $all_points, $points );
				$batch_texts = [];
				$batch_uuids = [];
			}
		}

		if ( ! empty( $batch_texts ) ) {
			$points = self::embed_and_build_points( $batch_texts, $batch_uuids, $current );
			if ( is_wp_error( $points ) ) {
				self::save_error_state( $points->get_error_message() );
				return $points;
			}
			$all_points = array_merge( $all_points, $points );
		}

		// Upsert in batches.
		foreach ( array_chunk( $all_points, self::BATCH_SIZE ) as $batch ) {
			$upsert = QdrantClient::upsert_points( $batch );
			if ( is_wp_error( $upsert ) ) {
				self::save_error_state( $upsert->get_error_message() );
				return $upsert;
			}
		}

		// Delete removed points.
		if ( ! empty( $to_delete ) ) {
			$delete = QdrantClient::delete_points( $to_delete );
			if ( is_wp_error( $delete ) ) {
				self::save_error_state( $delete->get_error_message() );
				return $delete;
			}
		}

		// Persist ready state.
		self::save_state( [
			'status'               => 'ready',
			'fingerprint'          => $fingerprint,
			'qdrant_url'           => $qdrant_url,
			'qdrant_collection'    => $qdrant_collection,
			'azure_openai_endpoint'=> $azure_openai_endpoint,
			'embedding_deployment' => $embedding_deployment,
			'last_synced_at'       => gmdate( 'c' ),
			'last_attempt_at'      => $state['last_attempt_at'],
			'indexed_count'        => count( $current ),
			'last_error'           => null,
			'pattern_fingerprints' => $current_pattern_fingerprints,
		] );

		return [
			'indexed'     => count( $uuids_to_embed ),
			'removed'     => count( $to_delete ),
			'fingerprint' => $fingerprint,
			'status'      => 'ready',
		];
	}

	/**
	 * @return array|\WP_Error Array of Qdrant point structures.
	 */
	private static function embed_and_build_points( array $texts, array $uuids, array $patterns ): array|\WP_Error {
		$vectors = EmbeddingClient::embed_batch( $texts );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		$points = [];
		foreach ( $uuids as $i => $uuid ) {
			$p        = $patterns[ $uuid ];
			$points[] = [
				'id'      => $uuid,
				'vector'  => $vectors[ $i ],
				'payload' => [
					'name'          => $p['name'],
					'title'         => $p['title'],
					'description'   => $p['description'] ?? '',
					'categories'    => $p['categories'] ?? [],
					'blockTypes'    => $p['blockTypes'] ?? [],
					'templateTypes' => $p['templateTypes'] ?? [],
					'content'       => $p['content'] ?? '',
				],
			];
		}

		return $points;
	}

	private static function save_error_state( string $error ): void {
		$state               = self::get_state();
		$state['status']     = 'error';
		$state['last_error'] = $error;
		self::save_state( $state );
	}

	private static function compute_pattern_fingerprint( array $pattern ): string {
		$entry = [
			$pattern['name'] ?? '',
			$pattern['title'] ?? '',
			$pattern['description'] ?? '',
			self::normalize_list( $pattern['categories'] ?? [] ),
			self::normalize_list( $pattern['blockTypes'] ?? [] ),
			self::normalize_list( $pattern['templateTypes'] ?? [] ),
			md5( $pattern['content'] ?? '' ),
			(string) self::EMBEDDING_RECIPE_VERSION,
		];

		return md5( implode( '|', $entry ) );
	}

	private static function normalize_list( array $values ): string {
		$values = array_values(
			array_filter(
				array_map( 'strval', $values ),
				static fn( string $value ): bool => $value !== ''
			)
		);
		sort( $values );
		return implode( ',', $values );
	}
}
