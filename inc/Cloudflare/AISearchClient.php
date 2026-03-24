<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

final class AISearchClient {

	private const DEFAULT_MAX_RESULTS       = 4;
	private const MAX_MAX_RESULTS           = 8;
	private const ALLOWED_DOC_HOST          = 'developer.wordpress.org';
	private const ALLOWED_SOURCE_KEY_PREFIX = 'developer.wordpress.org/';
	private const CACHE_KEY_PREFIX          = 'flavor_agent_ai_search_';
	private const CACHE_TTL                 = 21600;
	private const FAMILY_CACHE_PREFIX       = 'flavor_agent_docs_family_';
	private const FAMILY_CACHE_TTL          = 28800;
	private const ENTITY_CACHE_PREFIX       = 'flavor_agent_docs_entity_';
	private const ENTITY_CACHE_TTL          = 43200;
	private const VALIDATION_PROBE_QUERY    = 'block editor';
	private const VALIDATION_PROBE_RESULTS  = 3;
	private const PREWARM_STATE_OPTION      = 'flavor_agent_docs_prewarm_state';
	private const WARM_QUEUE_OPTION         = 'flavor_agent_docs_warm_queue';
	private const PREWARM_THROTTLE_SECONDS  = 3600;

	public const PREWARM_CRON_HOOK      = 'flavor_agent_prewarm_docs';
	public const CONTEXT_WARM_CRON_HOOK = 'flavor_agent_warm_docs_context';

	/**
	 * Entity keys and corresponding search queries for the initial warm set.
	 *
	 * Covers the highest-frequency block types used by the inspector surface,
	 * template entity keys used by the Site Editor, and core/navigation.
	 *
	 * @var array<string, string>
	 */
	private const WARM_SET = [
		'core/paragraph'   => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/paragraph. typography, spacing, color inspector controls.',
		'core/heading'     => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/heading. typography, spacing, color inspector controls.',
		'core/image'       => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/image. dimensions, border, color inspector controls.',
		'core/group'       => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/group. layout, spacing, background, border inspector controls.',
		'core/columns'     => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/columns. layout, spacing, color inspector controls.',
		'core/button'      => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/button. typography, color, border, spacing inspector controls.',
		'core/list'        => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/list. typography, spacing, color inspector controls.',
		'core/cover'       => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/cover. color overlay, dimensions, spacing, typography inspector controls.',
		'core/navigation'  => 'WordPress navigation block. menu structure and organization best practices. overlay responsive menu.',
		'template:single'  => 'WordPress block theme, site editor, and template part best practices. template type single. template files, template parts, block themes, and theme.json guidance.',
		'template:page'    => 'WordPress block theme, site editor, and template part best practices. template type page. template files, template parts, block themes, and theme.json guidance.',
		'template:archive' => 'WordPress block theme, site editor, and template part best practices. template type archive. template files, template parts, block themes, and theme.json guidance.',
		'template:home'    => 'WordPress block theme, site editor, and template part best practices. template type home. template files, template parts, block themes, and theme.json guidance.',
		'template:404'     => 'WordPress block theme, site editor, and template part best practices. template type 404. template files, template parts, block themes, and theme.json guidance.',
		'template:index'   => 'WordPress block theme, site editor, and template part best practices. template type index. template files, template parts, block themes, and theme.json guidance.',
		'template:search'  => 'WordPress block theme, site editor, and template part best practices. template type search. template files, template parts, block themes, and theme.json guidance.',
	];

	public static function is_configured(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): bool {
		$account_id  = self::resolve_config_value( $account_id, 'flavor_agent_cloudflare_ai_search_account_id' );
		$instance_id = self::resolve_config_value( $instance_id, 'flavor_agent_cloudflare_ai_search_instance_id' );
		$api_token   = self::resolve_config_value( $api_token, 'flavor_agent_cloudflare_ai_search_api_token' );

		return $account_id !== '' && $instance_id !== '' && $api_token !== '';
	}

	/**
	 * Validate that the configured Cloudflare AI Search instance is queryable.
	 *
	 * Uses a lightweight probe search so documented AI Search Run tokens can pass
	 * validation without requiring instance metadata read access.
	 *
	 * @return array{id: string, source: string, enabled: bool, paused: bool}|\WP_Error
	 */
	public static function validate_configuration(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array|\WP_Error {
		$config = self::get_config( $account_id, $instance_id, $api_token );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$guidance = self::validate_trusted_wordpress_docs_source( $config );

		if ( is_wp_error( $guidance ) ) {
			return $guidance;
		}

		return [
			'id'      => $config['instanceId'],
			'source'  => '',
			'enabled' => true,
			'paused'  => false,
		];
	}

	/**
	 * Query the configured Cloudflare AI Search instance for WordPress docs guidance.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function search( string $query, ?int $max_results = null ): array|\WP_Error {
		$query = sanitize_textarea_field( $query );

		if ( $query === '' ) {
			return new \WP_Error(
				'missing_query',
				'A search query is required.',
				[ 'status' => 400 ]
			);
		}

		$config = self::get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$result_limit = self::normalize_max_results( $max_results );
		$data         = self::request_search(
			$config,
			$query,
			$result_limit,
			'cloudflare_ai_search_error',
			'cloudflare_ai_search_parse_error',
			502
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result   = is_array( $data['result'] ?? null ) ? $data['result'] : [];
		$guidance = self::normalize_chunks(
			is_array( $result['chunks'] ?? null ) ? $result['chunks'] : [],
			$config['instanceId']
		);

		self::write_cached_guidance( $query, $result_limit, $guidance );

		return [
			'query'    => sanitize_text_field( (string) ( $result['search_query'] ?? $query ) ),
			'guidance' => $guidance,
		];
	}

	/**
	 * Best-effort search for prompt grounding. Never blocks recommendation flows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search( string $query, ?int $max_results = null ): array {
		$query = sanitize_textarea_field( $query );

		if ( $query === '' || ! self::is_configured() ) {
			return [];
		}

		$guidance = self::read_cached_guidance(
			$query,
			self::normalize_max_results( $max_results )
		);

		if ( ! is_array( $guidance ) ) {
			return [];
		}

		return $guidance;
	}

	/**
	 * Best-effort cache lookup for prompt grounding.
	 * Exact-query cache remains authoritative; entity cache is only a fallback.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search_with_entity_fallback( string $query, string $entity_key = '', ?int $max_results = null ): array {
		$guidance = self::maybe_search( $query, $max_results );

		if ( [] !== $guidance ) {
			return $guidance;
		}

		return self::maybe_search_entity( $entity_key );
	}

	/**
	 * Best-effort cache lookup for prompt grounding.
	 *
	 * Checks exact-query cache first, then a stable context-family cache, and
	 * finally falls back to the broad entity cache. If exact/family caches miss,
	 * it schedules an async warm so the next request can hit a more specific key.
	 *
	 * @param array<string, mixed> $family_context
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search_with_cache_fallbacks(
		string $query,
		string $entity_key = '',
		array $family_context = [],
		?int $max_results = null
	): array {
		$guidance = self::maybe_search( $query, $max_results );

		if ( [] !== $guidance ) {
			return $guidance;
		}

		$guidance = self::maybe_search_family( $family_context, $max_results );

		if ( [] !== $guidance ) {
			return $guidance;
		}

		$guidance = self::maybe_search_entity( $entity_key );

		self::schedule_context_warm( $query, $entity_key, $family_context, $max_results );

		return $guidance;
	}

	/**
	 * Best-effort family-context lookup for prompt grounding. Never blocks recommendation flows.
	 *
	 * @param array<string, mixed> $family_context
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search_family( array $family_context, ?int $max_results = null ): array {
		$family_context = self::normalize_family_context( $family_context );

		if ( [] === $family_context || ! self::is_configured() ) {
			return [];
		}

		$guidance = self::read_cached_guidance_by_key(
			self::build_family_cache_key(
				$family_context,
				self::normalize_max_results( $max_results )
			),
			self::FAMILY_CACHE_TTL
		);

		if ( ! is_array( $guidance ) ) {
			return [];
		}

		return $guidance;
	}

	/**
	 * Best-effort entity lookup for prompt grounding. Never blocks recommendation flows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search_entity( string $entity_key ): array {
		$entity_key = self::normalize_entity_key( $entity_key );

		if ( $entity_key === '' || ! self::is_configured() ) {
			return [];
		}

		$guidance = self::read_cached_guidance_by_key(
			self::build_entity_cache_key( $entity_key ),
			self::ENTITY_CACHE_TTL
		);

		if ( ! is_array( $guidance ) ) {
			return [];
		}

		return $guidance;
	}

	/**
	 * Perform an explicit search and seed the shared entity cache when possible.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function warm_entity( string $entity_key, string $query, ?int $max_results = null ): array|\WP_Error {
		$result = self::search( $query, $max_results );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::cache_entity_guidance( $entity_key, $result['guidance'] );

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	public static function cache_entity_guidance( string $entity_key, array $guidance ): void {
		$entity_key = self::normalize_entity_key( $entity_key );

		if ( $entity_key === '' ) {
			return;
		}

		self::write_cached_guidance_by_key(
			self::build_entity_cache_key( $entity_key ),
			$guidance,
			self::ENTITY_CACHE_TTL
		);
	}

	/**
	 * @param array<string, mixed>            $family_context
	 * @param array<int, array<string, mixed>> $guidance
	 */
	public static function cache_family_guidance( array $family_context, array $guidance, ?int $max_results = null ): void {
		$family_context = self::normalize_family_context( $family_context );

		if ( [] === $family_context ) {
			return;
		}

		self::write_cached_guidance_by_key(
			self::build_family_cache_key(
				$family_context,
				self::normalize_max_results( $max_results )
			),
			$guidance,
			self::FAMILY_CACHE_TTL
		);
	}

	/**
	 * Perform an explicit search and seed exact, family, and entity caches when possible.
	 *
	 * @param array<string, mixed> $family_context
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function warm_context(
		string $query,
		string $entity_key = '',
		array $family_context = [],
		?int $max_results = null
	): array|\WP_Error {
		$result = self::search( $query, $max_results );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::cache_family_guidance( $family_context, $result['guidance'], $max_results );
		self::cache_entity_guidance( $entity_key, $result['guidance'] );

		return $result;
	}

	public static function resolve_entity_key( string $entity_key = '', string $query = '' ): string {
		$entity_key = self::normalize_entity_key( $entity_key );

		if ( $entity_key !== '' ) {
			return $entity_key;
		}

		return self::infer_entity_key_from_query( $query );
	}

	public static function infer_entity_key_from_query( string $query ): string {
		$query = sanitize_textarea_field( $query );

		if ( $query === '' ) {
			return '';
		}

		if ( preg_match( '/\bblock type ([a-z0-9-]+\/[a-z0-9-]+)\b/i', $query, $matches ) ) {
			return self::normalize_entity_key( (string) ( $matches[1] ?? '' ) );
		}

		if ( preg_match( '/\btemplate type ([a-z0-9_-]+)\b/i', $query, $matches ) ) {
			return self::normalize_entity_key( 'template:' . (string) ( $matches[1] ?? '' ) );
		}

		if (
			preg_match( '/\b(block|gutenberg)\b/i', $query )
			&& preg_match( '/\b([a-z0-9-]+\/[a-z0-9-]+)\b/i', $query, $matches )
		) {
			return self::normalize_entity_key( (string) ( $matches[1] ?? '' ) );
		}

		return '';
	}

	/**
	 * Prewarm the entity cache for the standard warm set of entities.
	 *
	 * Iterates all entries in WARM_SET, calls warm_entity() for each, and records
	 * a structured result summary in the prewarm state option. Respects throttling
	 * based on credential fingerprint and elapsed time.
	 *
	 * @return array{warmed: int, failed: int, skipped: int, entities: array<string, string>}
	 */
	public static function prewarm(): array {
		$summary = [
			'warmed'   => 0,
			'failed'   => 0,
			'skipped'  => 0,
			'entities' => [],
		];

		if ( ! self::is_configured() ) {
			self::write_prewarm_state( $summary, 'not_configured' );

			return $summary;
		}

		$fingerprint = self::build_credentials_fingerprint();
		$state       = self::read_prewarm_state();

		if ( self::is_prewarm_throttled( $state, $fingerprint ) ) {
			$summary['skipped'] = count( self::WARM_SET );
			self::write_prewarm_state(
				$summary,
				$fingerprint,
				[
					'timestamp' => (string) ( $state['timestamp'] ?? '' ),
				]
			);

			return $summary;
		}

		foreach ( self::WARM_SET as $entity_key => $query ) {
			$result = self::warm_entity( $entity_key, $query );

			if ( is_wp_error( $result ) ) {
				++$summary['failed'];
				$summary['entities'][ $entity_key ] = 'error: ' . $result->get_error_message();
			} else {
				++$summary['warmed'];
				$summary['entities'][ $entity_key ] = 'ok (' . count( $result['guidance'] ) . ' chunks)';
			}
		}

		self::write_prewarm_state( $summary, $fingerprint );

		return $summary;
	}

	/**
	 * Schedule an async prewarm via WP-Cron if not already scheduled.
	 */
	public static function schedule_prewarm(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): void {
		if ( ! self::is_configured( $account_id, $instance_id, $api_token ) ) {
			return;
		}

		if ( wp_next_scheduled( self::PREWARM_CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + 5, self::PREWARM_CRON_HOOK );
	}

	/**
	 * Check whether a prewarm should run based on credential changes and throttle window.
	 */
	public static function should_prewarm(): bool {
		if ( ! self::is_configured() ) {
			return false;
		}

		$fingerprint = self::build_credentials_fingerprint();
		$state       = self::read_prewarm_state();

		return ! self::is_prewarm_throttled( $state, $fingerprint );
	}

	/**
	 * Return the current warm set entity keys and queries.
	 *
	 * @return array<string, string>
	 */
	public static function get_warm_set(): array {
		return self::WARM_SET;
	}

	/**
	 * Return the last prewarm state for admin diagnostics.
	 *
	 * @return array{timestamp: string, fingerprint: string, warmed: int, failed: int, skipped: int, status: string}
	 */
	public static function get_prewarm_state(): array {
		$state = self::read_prewarm_state();

		return [
			'timestamp'   => (string) ( $state['timestamp'] ?? '' ),
			'fingerprint' => (string) ( $state['fingerprint'] ?? '' ),
			'warmed'      => (int) ( $state['warmed'] ?? 0 ),
			'failed'      => (int) ( $state['failed'] ?? 0 ),
			'skipped'     => (int) ( $state['skipped'] ?? 0 ),
			'status'      => self::resolve_prewarm_status_label( $state ),
		];
	}

	/**
	 * Queue a best-effort async warm for exact/family/entity cache layers.
	 *
	 * @param array<string, mixed> $family_context
	 */
	public static function schedule_context_warm(
		string $query,
		string $entity_key = '',
		array $family_context = [],
		?int $max_results = null
	): void {
		if ( ! self::is_configured() ) {
			return;
		}

		$entry = self::normalize_context_warm_queue_entry(
			[
				'query'         => $query,
				'entityKey'     => $entity_key,
				'familyContext' => $family_context,
				'maxResults'    => self::normalize_max_results( $max_results ),
			]
		);

		if ( null === $entry ) {
			return;
		}

		$queue = self::read_context_warm_queue();
		$queue[ self::build_context_warm_queue_key( $entry ) ] = $entry;
		self::write_context_warm_queue( $queue );

		if ( wp_next_scheduled( self::CONTEXT_WARM_CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + 5, self::CONTEXT_WARM_CRON_HOOK );
	}

	/**
	 * Drain the queued async warm requests.
	 */
	public static function process_context_warm_queue(): void {
		$queue = self::read_context_warm_queue();

		if ( [] === $queue ) {
			return;
		}

		self::write_context_warm_queue( [] );

		foreach ( $queue as $entry ) {
			$normalized_entry = self::normalize_context_warm_queue_entry( $entry );

			if ( null === $normalized_entry ) {
				continue;
			}

			self::warm_context(
				$normalized_entry['query'],
				$normalized_entry['entityKey'],
				$normalized_entry['familyContext'],
				$normalized_entry['maxResults']
			);
		}
	}

	// ------------------------------------------------------------------
	// Prewarm internals
	// ------------------------------------------------------------------

	private static function resolve_config_value( ?string $value, string $option_name ): string {
		if ( null !== $value ) {
			return trim( $value );
		}

		return trim( (string) get_option( $option_name, '' ) );
	}

	private static function build_credentials_fingerprint(): string {
		$payload = wp_json_encode(
			[
				'account_id'  => self::resolve_config_value( null, 'flavor_agent_cloudflare_ai_search_account_id' ),
				'instance_id' => self::resolve_config_value( null, 'flavor_agent_cloudflare_ai_search_instance_id' ),
				'api_token'   => self::resolve_config_value( null, 'flavor_agent_cloudflare_ai_search_api_token' ),
			]
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			return '';
		}

		return md5( $payload );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function read_prewarm_state(): array {
		$state = get_option( self::PREWARM_STATE_OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * @return array<string, array{query: string, entityKey: string, familyContext: array<string, mixed>, maxResults: int}>
	 */
	private static function read_context_warm_queue(): array {
		$queue = get_option( self::WARM_QUEUE_OPTION, [] );

		if ( ! is_array( $queue ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $queue as $entry ) {
			$normalized_entry = self::normalize_context_warm_queue_entry( $entry );

			if ( null === $normalized_entry ) {
				continue;
			}

			$normalized[ self::build_context_warm_queue_key( $normalized_entry ) ] = $normalized_entry;
		}

		return $normalized;
	}

	/**
	 * @param array<string, array{query: string, entityKey: string, familyContext: array<string, mixed>, maxResults: int}> $queue
	 */
	private static function write_context_warm_queue( array $queue ): void {
		update_option( self::WARM_QUEUE_OPTION, $queue, false );
	}

	/**
	 * @return array{query: string, entityKey: string, familyContext: array<string, mixed>, maxResults: int}|null
	 */
	private static function normalize_context_warm_queue_entry( mixed $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$query = sanitize_textarea_field( (string) ( $entry['query'] ?? '' ) );

		if ( $query === '' ) {
			return null;
		}

		return [
			'query'         => $query,
			'entityKey'     => self::normalize_entity_key( (string) ( $entry['entityKey'] ?? '' ) ),
			'familyContext' => self::normalize_family_context(
				is_array( $entry['familyContext'] ?? null ) ? $entry['familyContext'] : []
			),
			'maxResults'    => self::normalize_max_results(
				isset( $entry['maxResults'] ) ? (int) $entry['maxResults'] : null
			),
		];
	}

	/**
	 * @param array{warmed: int, failed: int, skipped: int, entities: array<string, string>} $summary
	 * @param array{timestamp?: string} $meta
	 */
	private static function write_prewarm_state( array $summary, string $fingerprint, array $meta = [] ): void {
		$timestamp = isset( $meta['timestamp'] ) && is_string( $meta['timestamp'] ) && $meta['timestamp'] !== ''
			? $meta['timestamp']
			: gmdate( 'Y-m-d H:i:s' );

		$state = [
			'timestamp'   => $timestamp,
			'fingerprint' => $fingerprint,
			'warmed'      => $summary['warmed'],
			'failed'      => $summary['failed'],
			'skipped'     => $summary['skipped'],
		];

		update_option( self::PREWARM_STATE_OPTION, $state, false );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function is_prewarm_throttled( array $state, string $fingerprint ): bool {
		$last_fingerprint = (string) ( $state['fingerprint'] ?? '' );
		$last_timestamp   = (string) ( $state['timestamp'] ?? '' );

		if ( $last_fingerprint === '' || $last_timestamp === '' ) {
			return false;
		}

		if ( $last_fingerprint !== $fingerprint ) {
			return false;
		}

		$elapsed = time() - (int) strtotime( $last_timestamp . ' UTC' );

		return $elapsed < self::PREWARM_THROTTLE_SECONDS;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function resolve_prewarm_status_label( array $state ): string {
		if ( empty( $state['timestamp'] ) ) {
			return 'never';
		}

		$warmed  = (int) ( $state['warmed'] ?? 0 );
		$failed  = (int) ( $state['failed'] ?? 0 );
		$skipped = (int) ( $state['skipped'] ?? 0 );

		if ( $skipped > 0 && $warmed === 0 && $failed === 0 ) {
			return 'throttled';
		}

		if ( $failed > 0 && $warmed === 0 ) {
			return 'failed';
		}

		if ( $failed > 0 ) {
			return 'partial';
		}

		return 'ok';
	}

	/**
	 * @return array{instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string}|\WP_Error
	 */
	private static function get_config(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array|\WP_Error {
		$account_id  = trim( (string) ( null !== $account_id ? $account_id : get_option( 'flavor_agent_cloudflare_ai_search_account_id', '' ) ) );
		$instance_id = trim( (string) ( null !== $instance_id ? $instance_id : get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' ) ) );
		$api_token   = trim( (string) ( null !== $api_token ? $api_token : get_option( 'flavor_agent_cloudflare_ai_search_api_token', '' ) ) );

		if ( $account_id === '' || $instance_id === '' || $api_token === '' ) {
			return new \WP_Error(
				'missing_cloudflare_ai_search_credentials',
				'Cloudflare AI Search credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		return [
			'instanceId'  => $instance_id,
			'instanceUrl' => sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/instances/%s',
				rawurlencode( $account_id ),
				rawurlencode( $instance_id )
			),
			'searchUrl'   => sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/instances/%s/search',
				rawurlencode( $account_id ),
				rawurlencode( $instance_id )
			),
			'apiToken'    => $api_token,
		];
	}

	private static function normalize_max_results( ?int $max_results ): int {
		if ( null === $max_results ) {
			$max_results = (int) get_option(
				'flavor_agent_cloudflare_ai_search_max_results',
				self::DEFAULT_MAX_RESULTS
			);
		}

		return max( 1, min( self::MAX_MAX_RESULTS, (int) $max_results ) );
	}

	/**
	 * @param array{instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string} $config
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request_search(
		array $config,
		string $query,
		int $result_limit,
		string $error_code,
		string $parse_error_code,
		int $error_status
	): array|\WP_Error {
		$response = wp_remote_post(
			$config['searchUrl'],
			[
				'timeout' => 20,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $config['apiToken'],
				],
				'body'    => self::build_search_request_body( $query, $result_limit ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status !== 200 ) {
			$message = is_array( $data ) ? self::extract_error_message( $data, $status ) : "Cloudflare AI Search returned HTTP {$status}";

			return new \WP_Error(
				$error_code,
				$message,
				[ 'status' => $error_status ]
			);
		}

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error(
				$parse_error_code,
				'Failed to parse Cloudflare AI Search response.',
				[ 'status' => $error_status ]
			);
		}

		return $data;
	}

	private static function build_search_request_body( string $query, int $result_limit ): string {
		$body = wp_json_encode(
			[
				'messages'          => [
					[
						'role'    => 'user',
						'content' => $query,
					],
				],
				'ai_search_options' => [
					'retrieval' => [
						'retrieval_type'    => 'hybrid',
						'max_num_results'   => $result_limit,
						'match_threshold'   => 0.2,
						'context_expansion' => 1,
						'fusion_method'     => 'rrf',
						'return_on_failure' => true,
					],
				],
			]
		);

		return is_string( $body ) ? $body : '';
	}

	private static function build_cache_namespace(): string {
		$payload = wp_json_encode(
			[
				'account_id'  => self::resolve_config_value( null, 'flavor_agent_cloudflare_ai_search_account_id' ),
				'instance_id' => self::resolve_config_value( null, 'flavor_agent_cloudflare_ai_search_instance_id' ),
			]
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			return '';
		}

		return md5( $payload );
	}

	private static function build_cache_key( string $query, int $max_results ): string {
		$payload = wp_json_encode(
			[
				'namespace'  => self::build_cache_namespace(),
				'query'      => $query,
				'maxResults' => $max_results,
			]
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			$payload = "{$query}|{$max_results}";
		}

		return self::CACHE_KEY_PREFIX . md5( $payload );
	}

	/**
	 * @param array<string, mixed> $family_context
	 */
	private static function build_family_cache_key( array $family_context, int $max_results ): string {
		$payload = wp_json_encode(
			[
				'namespace'     => self::build_cache_namespace(),
				'familyContext' => self::normalize_family_context( $family_context ),
				'maxResults'    => $max_results,
			]
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			$payload = self::build_cache_namespace() . '|' . $max_results;
		}

		return self::FAMILY_CACHE_PREFIX . md5( $payload );
	}

	private static function build_entity_cache_key( string $entity_key ): string {
		return self::ENTITY_CACHE_PREFIX . md5( self::build_cache_namespace() . '|' . $entity_key );
	}

	/**
	 * @param array{query: string, entityKey: string, familyContext: array<string, mixed>, maxResults: int} $entry
	 */
	private static function build_context_warm_queue_key( array $entry ): string {
		$payload = wp_json_encode(
			[
				'namespace'     => self::build_cache_namespace(),
				'query'         => $entry['query'],
				'entityKey'     => $entry['entityKey'],
				'familyContext' => $entry['familyContext'],
				'maxResults'    => $entry['maxResults'],
			]
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			$payload = self::build_cache_namespace() . '|' . $entry['query'] . '|' . $entry['entityKey'];
		}

		return md5( $payload );
	}

	private static function normalize_entity_key( string $entity_key ): string {
		$entity_key = strtolower( trim( sanitize_text_field( $entity_key ) ) );

		if ( $entity_key === '' ) {
			return '';
		}

		if ( str_starts_with( $entity_key, 'template:' ) ) {
			$template_type = sanitize_key( substr( $entity_key, strlen( 'template:' ) ) );

			return $template_type !== '' ? 'template:' . $template_type : '';
		}

		return preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $entity_key ) === 1 ? $entity_key : '';
	}

	/**
	 * @param array<string, mixed> $family_context
	 * @return array<string, mixed>
	 */
	private static function normalize_family_context( array $family_context ): array {
		$normalized = self::normalize_family_context_value( $family_context );

		return is_array( $normalized ) ? $normalized : [];
	}

	/**
	 * @return array<string, mixed>|bool|float|int|string|null
	 */
	private static function normalize_family_context_value( mixed $value ): array|bool|float|int|string|null {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$is_list    = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized_entry = self::normalize_family_context_value( $entry );

				if ( null === $normalized_entry || [] === $normalized_entry || '' === $normalized_entry ) {
					continue;
				}

				if ( $is_list ) {
					$normalized[] = $normalized_entry;
				} else {
					$normalized[ sanitize_key( (string) $key ) ] = $normalized_entry;
				}
			}

			if ( ! $is_list ) {
				ksort( $normalized );
			}

			return $normalized;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = trim( sanitize_text_field( $value ) );

			return $value !== '' ? $value : null;
		}

		return null;
	}

	/**
	 * @param array{instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string} $config
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function validate_trusted_wordpress_docs_source( array $config ): array|\WP_Error {
		$data = self::request_search(
			$config,
			self::VALIDATION_PROBE_QUERY,
			self::VALIDATION_PROBE_RESULTS,
			'cloudflare_ai_search_validation_error',
			'cloudflare_ai_search_validation_parse_error',
			400
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result   = is_array( $data['result'] ?? null ) ? $data['result'] : [];
		$guidance = self::normalize_chunks(
			is_array( $result['chunks'] ?? null ) ? $result['chunks'] : [],
			$config['instanceId']
		);

		if ( [] === $guidance ) {
			return new \WP_Error(
				'cloudflare_ai_search_validation_untrusted_source',
				'Cloudflare AI Search validation could not confirm trusted developer.wordpress.org content from this instance. Use the official WordPress developer docs index before saving these credentials.',
				[ 'status' => 400 ]
			);
		}

		return $guidance;
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function read_cached_guidance( string $query, int $max_results ): ?array {
		return self::read_cached_guidance_by_key(
			self::build_cache_key( $query, $max_results ),
			self::CACHE_TTL
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function write_cached_guidance( string $query, int $max_results, array $guidance ): void {
		self::write_cached_guidance_by_key(
			self::build_cache_key( $query, $max_results ),
			$guidance,
			self::CACHE_TTL
		);
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function read_cached_guidance_by_key( string $cache_key, int $ttl ): ?array {
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			return null;
		}

		if ( ! is_array( $cached ) ) {
			delete_transient( $cache_key );

			return null;
		}

		$guidance = self::normalize_cached_guidance( $cached );

		if ( [] === $guidance && [] !== $cached ) {
			delete_transient( $cache_key );

			return null;
		}

		if ( $guidance !== $cached ) {
			set_transient( $cache_key, $guidance, $ttl );
		}

		return $guidance;
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function write_cached_guidance_by_key( string $cache_key, array $guidance, int $ttl ): void {
		set_transient(
			$cache_key,
			self::normalize_cached_guidance( $guidance ),
			$ttl
		);
	}

	/**
	 * @param array<int, mixed> $guidance
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_cached_guidance( array $guidance ): array {
		$normalized = [];

		foreach ( $guidance as $item ) {
			$normalized_item = self::normalize_cached_guidance_item( $item );

			if ( null === $normalized_item ) {
				continue;
			}

			$normalized[] = $normalized_item;
		}

		return $normalized;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function normalize_cached_guidance_item( mixed $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$source_key = sanitize_text_field( (string) ( $item['sourceKey'] ?? '' ) );
		$url        = self::normalize_trusted_guidance_url( $item['url'] ?? null );
		$excerpt    = self::sanitize_excerpt( (string) ( $item['excerpt'] ?? '' ) );

		if ( $url === '' || $excerpt === '' || ! self::is_allowed_guidance_source( $source_key, $url ) ) {
			return null;
		}

		return [
			'id'        => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
			'title'     => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'sourceKey' => $source_key,
			'url'       => $url,
			'excerpt'   => $excerpt,
			'score'     => isset( $item['score'] ) ? max( 0.0, min( 1.0, (float) $item['score'] ) ) : 0.0,
		];
	}

	/**
	 * @param array<int, mixed> $chunks Raw chunk list from Cloudflare AI Search.
	 * @param string|null       $instance_id Configured Cloudflare AI Search instance ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_chunks( array $chunks, ?string $instance_id = null ): array {
		$guidance = [];

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$item         = is_array( $chunk['item'] ?? null ) ? $chunk['item'] : [];
			$metadata     = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : [];
			$parsed_chunk = self::parse_chunk_text( (string) ( $chunk['text'] ?? '' ) );
			$source_key   = sanitize_text_field( (string) ( $item['key'] ?? '' ) );
			$has_url      = ( is_string( $metadata['url'] ?? null ) && trim( (string) $metadata['url'] ) !== '' ) || trim( $parsed_chunk['url'] ) !== '';
			$url          = self::normalize_guidance_url( $metadata['url'] ?? null, $parsed_chunk['url'] );
			$text         = self::sanitize_excerpt( $parsed_chunk['excerpt'] );

			if ( $url === '' && ! $has_url ) {
				$url = self::normalize_guidance_url_from_source_key( $source_key, $instance_id );
			}

			if ( $text === '' || $url === '' || ! self::is_allowed_guidance_source( $source_key, $url, $instance_id ) ) {
				continue;
			}

			$guidance[] = [
				'id'        => sanitize_text_field( (string) ( $chunk['id'] ?? '' ) ),
				'title'     => sanitize_text_field( (string) ( $metadata['title'] ?? '' ) ),
				'sourceKey' => $source_key,
				'url'       => $url,
				'excerpt'   => $text,
				'score'     => isset( $chunk['score'] ) ? max( 0.0, min( 1.0, (float) $chunk['score'] ) ) : 0.0,
			];
		}

		return $guidance;
	}

	private static function sanitize_excerpt( string $text ): string {
		$text = sanitize_textarea_field( $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		if ( strlen( $text ) > 360 ) {
			$text = substr( $text, 0, 357 ) . '...';
		}

		return $text;
	}

	private static function normalize_guidance_url( mixed $metadata_url, string $frontmatter_url ): string {
		$has_metadata_url    = is_string( $metadata_url ) && trim( $metadata_url ) !== '';
		$has_frontmatter_url = trim( $frontmatter_url ) !== '';

		$normalized_metadata_url    = self::normalize_trusted_guidance_url( $metadata_url );
		$normalized_frontmatter_url = self::normalize_trusted_guidance_url( $frontmatter_url );

		if ( $has_metadata_url && $normalized_metadata_url === '' ) {
			return '';
		}

		if ( $has_frontmatter_url && $normalized_frontmatter_url === '' ) {
			return '';
		}

		if (
			$normalized_metadata_url !== '' &&
			$normalized_frontmatter_url !== '' &&
			! self::guidance_urls_match( $normalized_metadata_url, $normalized_frontmatter_url )
		) {
			return '';
		}

		if ( $normalized_metadata_url !== '' ) {
			return $normalized_metadata_url;
		}

		return $normalized_frontmatter_url;
	}

	private static function normalize_trusted_guidance_url( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url = trim( $value );

		if ( $url === '' ) {
			return '';
		}

		$parts = parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = is_string( $parts['scheme'] ?? null ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_string( $parts['host'] ?? null ) ? strtolower( $parts['host'] ) : '';
		$path   = $parts['path'] ?? null;

		if (
			$scheme !== 'https' ||
			$host !== self::ALLOWED_DOC_HOST ||
			( isset( $parts['user'] ) && $parts['user'] !== '' ) ||
			( isset( $parts['pass'] ) && $parts['pass'] !== '' ) ||
			( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) ||
			! is_string( $path ) ||
			$path === ''
		) {
			return '';
		}

		$normalized_path = preg_replace( '#/+#', '/', $path );

		if (
			! is_string( $normalized_path ) ||
			$normalized_path === '' ||
			self::path_contains_untrusted_segments( $normalized_path )
		) {
			return '';
		}

		return 'https://' . self::ALLOWED_DOC_HOST . '/' . ltrim( $normalized_path, '/' );
	}

	private static function is_allowed_guidance_source( string $source_key, string $url, ?string $instance_id = null ): bool {
		$url_identity = self::normalize_guidance_identity( $url );

		if ( $url_identity === '' ) {
			return false;
		}

		if ( $source_key === '' ) {
			return true;
		}

		return self::normalize_source_key_identity( $source_key, $instance_id ) === $url_identity;
	}

	private static function normalize_source_key_identity( string $source_key, ?string $instance_id = null ): string {
		$url = self::normalize_guidance_url_from_source_key( $source_key, $instance_id );

		if ( $url === '' ) {
			return '';
		}

		return self::normalize_guidance_identity( $url );
	}

	private static function normalize_guidance_url_from_source_key( string $source_key, ?string $instance_id = null ): string {
		$normalized = strtolower( trim( $source_key ) );

		if ( $normalized === '' ) {
			return '';
		}

		$trusted_prefix = self::match_trusted_source_key_prefix( $normalized, $instance_id );

		if ( $trusted_prefix === '' ) {
			return '';
		}

		$path = trim( substr( $normalized, strlen( $trusted_prefix ) ), '/' );

		if (
			$path === '' ||
			str_contains( $path, '?' ) ||
			str_contains( $path, '#' ) ||
			str_contains( $path, '\\' ) ||
			self::path_contains_untrusted_segments( $path )
		) {
			return '';
		}

		$segments = array_values(
			array_filter(
				explode( '/', $path ),
				static fn ( string $segment ): bool => $segment !== ''
			)
		);

		if ( [] === $segments ) {
			return '';
		}

		$last_segment = $segments[ count( $segments ) - 1 ];

		if ( preg_match( '/^(?:part-\d+|index)\.md$/', $last_segment ) ) {
			array_pop( $segments );
			$last_segment = $segments[ count( $segments ) - 1 ] ?? '';
		}

		if ( is_string( $last_segment ) && preg_match( '/^[a-f0-9]{32,}$/', $last_segment ) ) {
			array_pop( $segments );
		}

		if ( [] === $segments ) {
			return '';
		}

		return self::normalize_trusted_guidance_url(
			'https://' . self::ALLOWED_DOC_HOST . '/' . implode( '/', $segments ) . '/'
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function trusted_source_key_prefixes( ?string $instance_id = null ): array {
		$prefixes             = [ self::ALLOWED_SOURCE_KEY_PREFIX ];
		$normalized_instance  = self::normalize_source_key_instance_id( $instance_id );

		if ( $normalized_instance !== '' ) {
			$prefixes[] = 'ai-search/' . $normalized_instance . '/' . self::ALLOWED_SOURCE_KEY_PREFIX;
		}

		return $prefixes;
	}

	private static function match_trusted_source_key_prefix( string $source_key, ?string $instance_id = null ): string {
		foreach ( self::trusted_source_key_prefixes( $instance_id ) as $prefix ) {
			if ( str_starts_with( $source_key, $prefix ) ) {
				return $prefix;
			}
		}

		return '';
	}

	private static function normalize_source_key_instance_id( ?string $instance_id = null ): string {
		$resolved = trim(
			(string) (
				null !== $instance_id
					? $instance_id
					: get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' )
			)
		);

		if ( $resolved === '' || str_contains( $resolved, '/' ) || str_contains( $resolved, '\\' ) ) {
			return '';
		}

		return strtolower( sanitize_text_field( $resolved ) );
	}

	private static function path_contains_untrusted_segments( string $path ): bool {
		$segments = array_values(
			array_filter(
				explode( '/', trim( $path, '/' ) ),
				static fn ( string $segment ): bool => $segment !== ''
			)
		);

		foreach ( $segments as $segment ) {
			$decoded = rawurldecode( $segment );

			if (
				$decoded === '.' ||
				$decoded === '..' ||
				str_contains( $decoded, '/' ) ||
				str_contains( $decoded, '\\' )
			) {
				return true;
			}
		}

		return false;
	}

	private static function normalize_guidance_identity( string $url ): string {
		$normalized = self::normalize_trusted_guidance_url( $url );

		if ( $normalized === '' ) {
			return '';
		}

		$path = parse_url( $normalized, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return '';
		}

		$normalized_path = rtrim( $path, '/' );

		if ( $normalized_path === '' ) {
			$normalized_path = '/';
		}

		return 'https://' . self::ALLOWED_DOC_HOST . $normalized_path;
	}

	private static function guidance_urls_match( string $left, string $right ): bool {
		return self::normalize_guidance_identity( $left ) === self::normalize_guidance_identity( $right );
	}

	/**
	 * @return array{excerpt: string, url: string}
	 */
	private static function parse_chunk_text( string $text ): array {
		$url     = '';
		$excerpt = $text;

		if ( str_starts_with( $text, "---\n" ) ) {
			$parts = explode( "\n---\n", $text, 2 );

			if ( count( $parts ) === 2 ) {
				$frontmatter = $parts[0];
				$excerpt     = $parts[1];

				if ( preg_match( '/(?:original_url|source_url):\s*"([^"]+)"/', $frontmatter, $matches ) ) {
					$url = (string) ( $matches[1] ?? '' );
				}
			}
		}

		return [
			'excerpt' => $excerpt,
			'url'     => $url,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function extract_error_message( array $data, int $status ): string {
		$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : [];

		if ( ! empty( $errors[0]['message'] ) && is_string( $errors[0]['message'] ) ) {
			return $errors[0]['message'];
		}

		if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}

		return "Cloudflare AI Search returned HTTP {$status}";
	}
}
