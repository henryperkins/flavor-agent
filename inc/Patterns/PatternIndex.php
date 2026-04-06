<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\EmbeddingSignature;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\OpenAI\Provider;

final class PatternIndex {

	public const STATE_OPTION = 'flavor_agent_pattern_index_state';
	public const CRON_HOOK    = 'flavor_agent_reindex_patterns';

	private const LOCK_TRANSIENT = 'flavor_agent_sync_lock';
	private const LOCK_TTL       = 300;
	private const COOLDOWN       = 300;
	private const BATCH_SIZE     = 100;
	private const COMPATIBILITY_STALE_REASONS = [
		'embedding_signature_changed',
		'qdrant_url_changed',
		'openai_endpoint_changed',
		'collection_name_changed',
		'collection_missing',
		'collection_size_mismatch',
	];

	/** Increment when the embedding text template changes. */
	public const EMBEDDING_RECIPE_VERSION = 2;

	/** Fixed UUID v5 namespace (DNS namespace from RFC 4122). */
	private const UUID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

	public static function get_state(): array {
		$defaults = [
			'status'               => 'uninitialized',
			'fingerprint'          => '',
			'qdrant_url'           => '',
			'qdrant_collection'    => '',
			'openai_provider'      => '',
			'openai_endpoint'      => '',
			'embedding_model'      => '',
			'embedding_dimension'  => 0,
			'embedding_signature'  => '',
			'last_synced_at'       => null,
			'last_attempt_at'      => null,
			'indexed_count'        => 0,
			'last_error'           => null,
			'last_error_code'      => '',
			'last_error_status'    => 0,
			'last_error_retryable' => false,
			'last_error_retry_after' => null,
			'stale_reason'         => '',
			'stale_reasons'        => [],
			'pattern_fingerprints' => [],
		];

		return wp_parse_args( get_option( self::STATE_OPTION, $defaults ), $defaults );
	}

	public static function get_runtime_state(): array {
		$state = self::get_state();

		if ( $state['status'] !== 'ready' && ! self::has_usable_index( $state ) ) {
			return $state;
		}

		$stale_reasons = self::detect_runtime_stale_reasons( $state );

		if ( ! empty( $stale_reasons ) ) {
			$state['status']        = 'stale';
			$state['stale_reason']  = $stale_reasons[0];
			$state['stale_reasons'] = $stale_reasons;
			self::save_state( $state );
		}

		return $state;
	}

	public static function has_usable_index( array $state ): bool {
		return ! empty( $state['last_synced_at'] );
	}

	public static function save_state( array $state ): void {
		$state['embedding_dimension'] = max( 0, (int) ( $state['embedding_dimension'] ?? 0 ) );
		$state['embedding_signature'] = (string) ( $state['embedding_signature'] ?? '' );
		$state['last_error_code']      = (string) ( $state['last_error_code'] ?? '' );
		$state['last_error_status']    = max( 0, (int) ( $state['last_error_status'] ?? 0 ) );
		$state['last_error_retryable'] = ! empty( $state['last_error_retryable'] );
		$state['last_error_retry_after'] = isset( $state['last_error_retry_after'] ) && $state['last_error_retry_after'] !== null
			? max( 1, min( 60, (int) $state['last_error_retry_after'] ) )
			: null;
		$state['stale_reason']        = (string) ( $state['stale_reason'] ?? '' );
		$state['stale_reasons']       = self::normalize_stale_reasons( $state );

		update_option( self::STATE_OPTION, $state, false );
	}

	public static function recommendation_backends_configured(): bool {
		return (bool) (
			Provider::embedding_configured()
			&& get_option( 'flavor_agent_qdrant_url', '' )
			&& get_option( 'flavor_agent_qdrant_key', '' )
		);
	}

	public static function mark_dirty(): void {
		$state = self::get_state();

		if ( $state['status'] === 'indexing' ) {
			return;
		}

		if ( self::has_usable_index( $state ) ) {
			$state['status']        = 'stale';
			$state['stale_reason']  = 'pattern_registry_changed';
			$state['stale_reasons'] = [ 'pattern_registry_changed' ];
		} else {
			$state['status']        = 'uninitialized';
			$state['stale_reason']  = '';
			$state['stale_reasons'] = [];
		}
		self::save_state( $state );
	}

	public static function get_stale_reasons( array $state ): array {
		return self::normalize_stale_reasons( $state );
	}

	public static function has_compatibility_drift( array $state ): bool {
		return [] !== array_intersect(
			self::get_stale_reasons( $state ),
			self::COMPATIBILITY_STALE_REASONS
		);
	}

	public static function has_retryable_error( array $state ): bool {
		return ! empty( $state['last_error_retryable'] );
	}

	public static function mark_stale( array $reasons ): void {
		$reasons = array_values(
			array_filter(
				array_unique(
					array_map(
						static fn( mixed $reason ): string => is_string( $reason ) ? $reason : '',
						$reasons
					)
				)
			)
		);
		$state   = self::get_state();

		$state['status']        = 'stale';
		$state['stale_reason']  = $reasons[0] ?? '';
		$state['stale_reasons'] = $reasons;
		$state                  = self::clear_error_metadata( $state );

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

		$pattern_overrides = is_array( $pattern['patternOverrides'] ?? null )
			? $pattern['patternOverrides']
			: [];
		if ( ! empty( $pattern_overrides['hasOverrides'] ) ) {
			$parts[] = 'Pattern overrides: yes';

			$override_attributes = is_array( $pattern_overrides['overrideAttributes'] ?? null )
				? $pattern_overrides['overrideAttributes']
				: [];
			foreach ( $override_attributes as $block_name => $attributes ) {
				if ( ! is_string( $block_name ) || ! is_array( $attributes ) || [] === $attributes ) {
					continue;
				}

				$parts[] = sprintf(
					'Override-ready %s: %s',
					$block_name,
					implode( ', ', array_map( 'strval', $attributes ) )
				);
			}
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
		$state       = self::clear_error_metadata( self::get_state() );

		$state['last_attempt_at'] = gmdate( 'c' );
		self::save_state( $state );
		$probe       = self::probe_active_embedding_signature();

		if ( is_wp_error( $probe ) ) {
			self::save_error_state( $probe );
			return $probe;
		}

		// Steps 4-6: Determine if re-index is needed.
		$qdrant_url        = get_option( 'flavor_agent_qdrant_url', '' );
		$qdrant_collection = QdrantClient::get_collection_name( $probe['signature'] );
		$openai_provider   = $probe['signature']['provider'];
		$openai_endpoint   = $probe['endpoint'];
		$embedding_model   = $probe['signature']['model'];
		$embedding_dimension = $probe['signature']['dimension'];
		$embedding_signature = $probe['signature']['signature_hash'];
		$previous_pattern_fingerprints = is_array( $state['pattern_fingerprints'] ?? null )
			? $state['pattern_fingerprints']
			: [];
		$has_usable_index = self::has_usable_index( $state );

		$needs_reindex = ! $has_usable_index
			|| $state['fingerprint'] !== $fingerprint
			|| $state['qdrant_url'] !== $qdrant_url
			|| $state['qdrant_collection'] !== $qdrant_collection
			|| $state['openai_endpoint'] !== $openai_endpoint
			|| $state['embedding_signature'] !== $embedding_signature
			|| empty( $previous_pattern_fingerprints );

		$needs_state_refresh = $needs_reindex
			|| $state['status'] !== 'ready'
			|| $state['openai_provider'] !== $openai_provider
			|| $state['openai_endpoint'] !== $openai_endpoint
			|| $state['embedding_model'] !== $embedding_model
			|| (int) $state['embedding_dimension'] !== $embedding_dimension
			|| $state['embedding_signature'] !== $embedding_signature
			|| ! empty( self::get_stale_reasons( $state ) );

		if ( ! $needs_state_refresh ) {
			return [
				'indexed'     => $state['indexed_count'],
				'removed'     => 0,
				'fingerprint' => $fingerprint,
				'status'      => 'ready',
			];
		}

		if ( ! $needs_reindex ) {
			self::save_state(
				array_merge(
					$state,
					[
						'status'              => 'ready',
						'fingerprint'         => $fingerprint,
						'qdrant_url'          => $qdrant_url,
						'qdrant_collection'   => $qdrant_collection,
						'openai_provider'     => $openai_provider,
						'openai_endpoint'     => $openai_endpoint,
						'embedding_model'     => $embedding_model,
						'embedding_dimension' => $embedding_dimension,
						'embedding_signature' => $embedding_signature,
						'last_synced_at'      => gmdate( 'c' ),
						'last_error'          => null,
						'stale_reason'        => '',
						'stale_reasons'       => [],
					]
				)
			);

			return [
				'indexed'     => 0,
				'removed'     => 0,
				'fingerprint' => $fingerprint,
				'status'      => 'ready',
			];
		}

		$current                      = [];
		$current_pattern_fingerprints = [];
		foreach ( $patterns as $pattern ) {
			$uuid                                  = self::pattern_uuid( $pattern['name'] ?? '' );
			$current[ $uuid ]                      = $pattern;
			$current_pattern_fingerprints[ $uuid ] = self::compute_pattern_fingerprint( $pattern );
		}

		$requires_full_reindex         = ! $has_usable_index
			|| $state['qdrant_url'] !== $qdrant_url
			|| $state['qdrant_collection'] !== $qdrant_collection
			|| $state['embedding_signature'] !== $embedding_signature
			|| empty( $previous_pattern_fingerprints );

		// Step 7: Mark indexing before remote work starts.
		$state['status']          = 'indexing';
		$state['last_attempt_at'] = gmdate( 'c' );
		$state['last_error']      = null;
		$state['stale_reason']    = '';
		$state['stale_reasons']   = [];
		self::save_state( $state );

		// Step 2: Ensure collection.
		$ensure = QdrantClient::ensure_collection( $qdrant_collection, $embedding_dimension );
		if ( is_wp_error( $ensure ) ) {
			self::save_error_state( $ensure );
			return $ensure;
		}

		// Step 8: Diff existing vs. current.
		$existing_ids = QdrantClient::scroll_ids( $qdrant_collection );
		if ( is_wp_error( $existing_ids ) ) {
			self::save_error_state( $existing_ids );
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
					self::save_error_state( $points );
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
				self::save_error_state( $points );
				return $points;
			}
			$all_points = array_merge( $all_points, $points );
		}

		// Upsert in batches.
		foreach ( array_chunk( $all_points, self::BATCH_SIZE ) as $batch ) {
			$upsert = QdrantClient::upsert_points( $batch, $qdrant_collection );
			if ( is_wp_error( $upsert ) ) {
				self::save_error_state( $upsert );
				return $upsert;
			}
		}

		// Delete removed points.
		if ( ! empty( $to_delete ) ) {
			$delete = QdrantClient::delete_points( $to_delete, $qdrant_collection );
			if ( is_wp_error( $delete ) ) {
				self::save_error_state( $delete );
				return $delete;
			}
		}

		// Persist ready state.
		self::save_state(
			[
				'status'               => 'ready',
				'fingerprint'          => $fingerprint,
				'qdrant_url'           => $qdrant_url,
				'qdrant_collection'    => $qdrant_collection,
				'openai_provider'      => $openai_provider,
				'openai_endpoint'      => $openai_endpoint,
				'embedding_model'      => $embedding_model,
				'embedding_dimension'  => $embedding_dimension,
				'embedding_signature'  => $embedding_signature,
				'last_synced_at'       => gmdate( 'c' ),
				'last_attempt_at'      => $state['last_attempt_at'],
				'indexed_count'        => count( $current ),
				'last_error'           => null,
				'stale_reason'         => '',
				'stale_reasons'        => [],
				'pattern_fingerprints' => $current_pattern_fingerprints,
			]
		);

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
					'patternOverrides' => $p['patternOverrides'] ?? [],
					'content'       => $p['content'] ?? '',
				],
			];
		}

		return $points;
	}

	/**
	 * @return array{signature: array{provider: string, model: string, dimension: int, signature_hash: string}, endpoint: string}|\WP_Error
	 */
	private static function probe_active_embedding_signature(): array|\WP_Error {
		$vector = EmbeddingClient::embed( 'flavor agent pattern index signature probe' );

		if ( is_wp_error( $vector ) ) {
			return $vector;
		}

		$embedding_config = \FlavorAgent\OpenAI\Provider::embedding_configuration();

		return [
			'signature' => EmbeddingSignature::from_configuration( $embedding_config, count( $vector ) ),
			'endpoint'  => (string) ( $embedding_config['endpoint'] ?? '' ),
		];
	}

	/**
	 * @param array<string, mixed> $state
	 * @return string[]
	 */
	private static function detect_runtime_stale_reasons( array $state ): array {
		$current_config = \FlavorAgent\OpenAI\Provider::embedding_configuration();
		$reasons        = [];

		if ( (string) ( $state['qdrant_url'] ?? '' ) !== (string) get_option( 'flavor_agent_qdrant_url', '' ) ) {
			$reasons[] = 'qdrant_url_changed';
		}

		if (
			(string) ( $state['openai_provider'] ?? '' ) !== (string) ( $current_config['provider'] ?? '' )
			|| (string) ( $state['embedding_model'] ?? '' ) !== (string) ( $current_config['model'] ?? '' )
		) {
			$reasons[] = 'embedding_signature_changed';
		}

		if ( (string) ( $state['openai_endpoint'] ?? '' ) !== (string) ( $current_config['endpoint'] ?? '' ) ) {
			$reasons[] = 'openai_endpoint_changed';
		}

		$current_dimension = max( 0, (int) ( $state['embedding_dimension'] ?? 0 ) );
		$expected_collection = QdrantClient::get_collection_name(
			EmbeddingSignature::from_configuration( $current_config, $current_dimension )
		);

		if ( (string) ( $state['qdrant_collection'] ?? '' ) !== $expected_collection ) {
			$reasons[] = 'collection_name_changed';
		}

		return array_values( array_unique( $reasons ) );
	}

	/**
	 * @param array<string, mixed> $state
	 * @return string[]
	 */
	private static function normalize_stale_reasons( array $state ): array {
		$reasons = [];

		if ( isset( $state['stale_reasons'] ) && is_array( $state['stale_reasons'] ) ) {
			foreach ( $state['stale_reasons'] as $reason ) {
				if ( is_string( $reason ) && '' !== $reason ) {
					$reasons[] = $reason;
				}
			}
		}

		if (
			[] === $reasons
			&& isset( $state['stale_reason'] )
			&& is_string( $state['stale_reason'] )
			&& '' !== $state['stale_reason']
		) {
			$reasons[] = $state['stale_reason'];
		}

		return array_values( array_unique( $reasons ) );
	}

	private static function save_error_state( string|\WP_Error $error ): void {
		$state = self::get_state();

		if ( is_wp_error( $error ) ) {
			$data                           = $error->get_error_data();
			$state['last_error']           = $error->get_error_message();
			$state['last_error_code']      = (string) $error->get_error_code();
			$state['last_error_status']    = is_array( $data ) ? max( 0, (int) ( $data['status'] ?? 0 ) ) : 0;
			$state['last_error_retryable'] = is_array( $data ) && ! empty( $data['retryable'] );
			$state['last_error_retry_after'] = is_array( $data ) && isset( $data['retry_after'] ) && $data['retry_after'] !== null
				? max( 1, min( 60, (int) $data['retry_after'] ) )
				: null;
		} else {
			$state               = self::clear_error_metadata( $state );
			$state['last_error'] = $error;
		}

		$state['status']        = 'error';
		$state['stale_reason']  = '';
		$state['stale_reasons'] = [];
		self::save_state( $state );

		if ( is_wp_error( $error ) ) {
			self::schedule_retry_for_error( $error );
		}
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function clear_error_metadata( array $state ): array {
		$state['last_error']             = null;
		$state['last_error_code']        = '';
		$state['last_error_status']      = 0;
		$state['last_error_retryable']   = false;
		$state['last_error_retry_after'] = null;

		return $state;
	}

	private static function schedule_retry_for_error( \WP_Error $error ): void {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) || empty( $data['retryable'] ) || ! self::recommendation_backends_configured() ) {
			return;
		}

		$retry_after = isset( $data['retry_after'] ) && $data['retry_after'] !== null
			? max( 1, min( 60, (int) $data['retry_after'] ) )
			: 5;
		$hook        = self::CRON_HOOK;
		$target_time = time() + $retry_after;
		$scheduled   = wp_next_scheduled( $hook );

		if ( false !== $scheduled && $scheduled <= $target_time ) {
			return;
		}

		if ( false !== $scheduled ) {
			wp_clear_scheduled_hook( $hook );
		}

		wp_schedule_single_event( $target_time, $hook );
	}

	private static function compute_pattern_fingerprint( array $pattern ): string {
		$entry = [
			$pattern['name'] ?? '',
			$pattern['title'] ?? '',
			$pattern['description'] ?? '',
			self::normalize_list( $pattern['categories'] ?? [] ),
			self::normalize_list( $pattern['blockTypes'] ?? [] ),
			self::normalize_list( $pattern['templateTypes'] ?? [] ),
			self::normalize_pattern_overrides( $pattern['patternOverrides'] ?? [] ),
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

	/**
	 * @param array<string, mixed> $pattern_overrides
	 */
	private static function normalize_pattern_overrides( array $pattern_overrides ): string {
		$parts = [];

		$parts[] = ! empty( $pattern_overrides['hasOverrides'] ) ? '1' : '0';
		$parts[] = ! empty( $pattern_overrides['usesDefaultBinding'] ) ? '1' : '0';
		$parts[] = (string) (int) ( $pattern_overrides['blockCount'] ?? 0 );
		$parts[] = self::normalize_list(
			is_array( $pattern_overrides['blockNames'] ?? null )
				? $pattern_overrides['blockNames']
				: []
		);

		foreach ( [ 'bindableAttributes', 'overrideAttributes', 'unsupportedAttributes' ] as $map_key ) {
			$map = is_array( $pattern_overrides[ $map_key ] ?? null )
				? $pattern_overrides[ $map_key ]
				: [];
			ksort( $map );

			foreach ( $map as $block_name => $attributes ) {
				if ( ! is_string( $block_name ) || ! is_array( $attributes ) ) {
					continue;
				}

				$parts[] = $map_key . ':' . $block_name . ':' . self::normalize_list( $attributes );
			}
		}

		return implode( '|', $parts );
	}
}
