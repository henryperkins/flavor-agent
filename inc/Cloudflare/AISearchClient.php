<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

final class AISearchClient {

	private const DEFAULT_MAX_RESULTS        = 4;
	private const MAX_MAX_RESULTS            = 8;
	private const ALLOWED_DOC_HOST           = 'developer.wordpress.org';
	private const ALLOWED_SOURCE_KEY_PREFIX  = 'developer.wordpress.org/';
	private const CACHE_KEY_PREFIX           = 'flavor_agent_ai_search_';
	private const CACHE_TTL                  = 21600;
	private const FAMILY_CACHE_PREFIX        = 'flavor_agent_docs_family_';
	private const FAMILY_CACHE_TTL           = 28800;
	private const ENTITY_CACHE_PREFIX        = 'flavor_agent_docs_entity_';
	private const ENTITY_CACHE_TTL           = 43200;
	private const VALIDATION_PROBE_QUERY     = 'block editor';
	private const VALIDATION_PROBE_RESULTS   = 3;
	private const DEFAULT_PUBLIC_SEARCH_URL  = 'https://c5d54c4a-27df-4034-80da-ca6054684fcd.search.ai.cloudflare.com/search';
	private const PUBLIC_HOST_SUFFIX         = '.search.ai.cloudflare.com';
	private const PREWARM_STATE_OPTION       = 'flavor_agent_docs_prewarm_state';
	private const RUNTIME_STATE_OPTION       = 'flavor_agent_docs_runtime_state';
	private const WARM_QUEUE_OPTION          = 'flavor_agent_docs_warm_queue';
	private const PREWARM_THROTTLE_SECONDS   = 3600;
	private const FOREGROUND_WARM_LOCK_PREFIX = 'flavor_agent_docs_foreground_warm_';
	private const FOREGROUND_WARM_LOCK_TTL    = 30;
	private const FOREGROUND_WARM_TIMEOUT     = 5;
	private const WARM_QUEUE_RETRY_BASE_SECONDS = 60;
	private const WARM_QUEUE_RETRY_MAX_SECONDS  = 900;
	private const MAX_FAMILY_CONTEXT_DEPTH   = 8;
	private const GUIDANCE_BLOCK_EDITOR_KEY  = 'guidance:block-editor';
	private const GUIDANCE_GLOBAL_STYLES_KEY = 'guidance:global-styles';
	private const GUIDANCE_STYLE_BOOK_KEY    = 'guidance:style-book';
	private const GUIDANCE_TEMPLATE_KEY      = 'guidance:template';
	private const GUIDANCE_TEMPLATE_PART_KEY = 'guidance:template-part';

	public const PREWARM_CRON_HOOK      = 'flavor_agent_prewarm_docs';
	public const CONTEXT_WARM_CRON_HOOK = 'flavor_agent_warm_docs_context';

	/**
	 * Entity keys and corresponding search queries for the initial warm set.
	 *
	 * Covers the highest-frequency block types used by the inspector surface,
	 * template entity keys used by the Site Editor, core/template-part,
	 * core/navigation, and generic fallback guidance for long-tail misses.
	 *
	 * @var array<string, string>
	 */
	private const WARM_SET = [
		'core/paragraph'                 => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/paragraph. typography, spacing, color inspector controls.',
		'core/heading'                   => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/heading. typography, spacing, color inspector controls.',
		'core/image'                     => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/image. dimensions, border, color inspector controls.',
		'core/group'                     => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/group. layout, spacing, background, border inspector controls.',
		'core/columns'                   => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/columns. layout, spacing, color inspector controls.',
		'core/button'                    => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/button. typography, color, border, spacing inspector controls.',
		'core/list'                      => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/list. typography, spacing, color inspector controls.',
		'core/cover'                     => 'WordPress Gutenberg block editor best practices and design tool guidance. block type core/cover. color overlay, dimensions, spacing, typography inspector controls.',
		'core/template-part'             => 'WordPress block theme template parts. template part structure, composition, areas, block patterns, and theme.json guidance.',
		'core/navigation'                => 'WordPress navigation block. menu structure and organization best practices. overlay responsive menu.',
		'template:single'                => 'WordPress block theme, site editor, and template part best practices. template type single. template files, template parts, block themes, and theme.json guidance.',
		'template:page'                  => 'WordPress block theme, site editor, and template part best practices. template type page. template files, template parts, block themes, and theme.json guidance.',
		'template:archive'               => 'WordPress block theme, site editor, and template part best practices. template type archive. template files, template parts, block themes, and theme.json guidance.',
		'template:home'                  => 'WordPress block theme, site editor, and template part best practices. template type home. template files, template parts, block themes, and theme.json guidance.',
		'template:404'                   => 'WordPress block theme, site editor, and template part best practices. template type 404. template files, template parts, block themes, and theme.json guidance.',
		'template:index'                 => 'WordPress block theme, site editor, and template part best practices. template type index. template files, template parts, block themes, and theme.json guidance.',
		'template:search'                => 'WordPress block theme, site editor, and template part best practices. template type search. template files, template parts, block themes, and theme.json guidance.',
		self::GUIDANCE_BLOCK_EDITOR_KEY  => 'WordPress Gutenberg block editor best practices. block settings, styles, inspector controls, block supports, and theme.json guidance.',
		self::GUIDANCE_GLOBAL_STYLES_KEY => 'WordPress Global Styles and theme.json guidance. presets, style variations, editor controls, and site-wide styling best practices.',
		self::GUIDANCE_STYLE_BOOK_KEY    => 'WordPress Style Book guidance. block-level styling, supported style paths, theme.json-backed controls, and editor best practices.',
		self::GUIDANCE_TEMPLATE_KEY      => 'WordPress block theme and Site Editor guidance. templates, template hierarchy, template parts, patterns, and theme.json best practices.',
		self::GUIDANCE_TEMPLATE_PART_KEY => 'WordPress template part guidance. template-part structure, areas, patterns, layout, and theme.json best practices.',
	];

	public static function is_configured(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): bool {
		if ( self::has_explicit_legacy_config_request( $account_id, $instance_id, $api_token ) ) {
			return self::has_legacy_credentials( $account_id, $instance_id, $api_token );
		}

		return ! is_wp_error( self::get_config() );
	}

	public static function configured_instance_id(): ?string {
		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return null;
		}

		return '' !== $config['instanceId'] ? $config['instanceId'] : null;
	}

	/**
	 * Validate that the resolved Cloudflare AI Search backend is queryable.
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
	 * Query the resolved Cloudflare AI Search backend for WordPress docs guidance.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function search( string $query, ?int $max_results = null ): array|\WP_Error {
		return self::search_live( $query, $max_results );
	}

	/**
	 * Query the resolved Cloudflare AI Search backend for WordPress docs guidance.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	private static function search_live(
		string $query,
		?int $max_results = null,
		string $runtime_mode = 'direct',
		?int $timeout = null
	): array|\WP_Error {
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
			self::record_runtime_search_error( $runtime_mode, $config );
			return $config;
		}

		$result_limit = self::normalize_max_results( $max_results );
		$data         = self::request_search(
			$config,
			$query,
			$result_limit,
			'cloudflare_ai_search_error',
			'cloudflare_ai_search_parse_error',
			502,
			$timeout
		);

		if ( is_wp_error( $data ) ) {
			self::record_runtime_search_error( $runtime_mode, $data );
			return $data;
		}

		$result   = is_array( $data['result'] ?? null ) ? $data['result'] : [];
		$guidance = self::normalize_chunks(
			is_array( $result['chunks'] ?? null ) ? $result['chunks'] : [],
			$config['instanceId']
		);

		self::write_cached_guidance( $query, $result_limit, $guidance );

		if ( [] === $guidance ) {
			self::record_runtime_search_empty_result( $runtime_mode );
		} else {
			self::record_runtime_search_success( $runtime_mode, $guidance );
		}

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
			self::record_runtime_served_guidance( 'exact', 'cache' );
			return $guidance;
		}

		$guidance = self::maybe_search_family( $family_context, $max_results );

		if ( [] !== $guidance ) {
			self::record_runtime_served_guidance( 'family', 'cache' );
			return $guidance;
		}

		$fallback_type = 'entity';
		$guidance = self::maybe_search_entity( $entity_key );

		if ( [] === $guidance ) {
			$guidance = self::maybe_search_entity(
				self::resolve_generic_entity_fallback( $entity_key, $family_context )
			);
			$fallback_type = [] === $guidance ? 'none' : 'generic';
		}

		if ( in_array( $fallback_type, [ 'generic', 'none' ], true ) ) {
			$fresh_guidance = self::maybe_foreground_warm_context(
				$query,
				$entity_key,
				$family_context,
				$max_results
			);

			if ( [] !== $fresh_guidance ) {
				self::record_runtime_served_guidance( 'fresh', 'foreground' );
				return $fresh_guidance;
			}
		}

		self::schedule_context_warm( $query, $entity_key, $family_context, $max_results );
		self::record_runtime_served_guidance( $fallback_type, 'cache' );

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
	public static function warm_entity(
		string $entity_key,
		string $query,
		?int $max_results = null,
		string $runtime_mode = 'direct',
		?int $timeout = null
	): array|\WP_Error {
		$result = self::search_live( $query, $max_results, $runtime_mode, $timeout );

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
		?int $max_results = null,
		string $runtime_mode = 'direct',
		?int $timeout = null
	): array|\WP_Error {
		$result = self::search_live( $query, $max_results, $runtime_mode, $timeout );

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
			$result = self::warm_entity( $entity_key, $query, null, 'prewarm' );

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
		if ( ! self::should_prewarm( $account_id, $instance_id, $api_token ) ) {
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
	public static function should_prewarm(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): bool {
		if ( ! self::is_configured( $account_id, $instance_id, $api_token ) ) {
			return false;
		}

		$fingerprint = self::build_credentials_fingerprint(
			$account_id,
			$instance_id,
			$api_token
		);
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
	 * Return the current docs-grounding runtime health for admin diagnostics.
	 *
	 * @return array{
	 *   status: string,
	 *   queueDepth: int,
	 *   nextQueueAttemptAt: string,
	 *   lastSearchAt: string,
	 *   lastSearchMode: string,
	 *   lastResultCount: int,
	 *   lastTrustedSuccessAt: string,
	 *   lastTrustedSuccessMode: string,
	 *   lastServedAt: string,
	 *   lastServedMode: string,
	 *   lastFallbackType: string,
	 *   lastErrorAt: string,
	 *   lastErrorMode: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }
	 */
	public static function get_runtime_state(): array {
		if ( ! self::is_configured() ) {
			return [
				'status'                => 'off',
				'queueDepth'            => 0,
				'nextQueueAttemptAt'    => '',
				'lastSearchAt'          => '',
				'lastSearchMode'        => '',
				'lastResultCount'       => 0,
				'lastTrustedSuccessAt'  => '',
				'lastTrustedSuccessMode'=> '',
				'lastServedAt'          => '',
				'lastServedMode'        => '',
				'lastFallbackType'      => '',
				'lastErrorAt'           => '',
				'lastErrorMode'         => '',
				'lastErrorCode'         => '',
				'lastErrorMessage'      => '',
			];
		}

		$state              = self::read_runtime_state();
		$queue              = self::read_context_warm_queue();
		$next_queue_attempt = self::resolve_next_context_warm_attempt( $queue );

		return [
			'status'                => self::resolve_runtime_status( $state, $queue ),
			'queueDepth'            => count( $queue ),
			'nextQueueAttemptAt'    => $next_queue_attempt > 0 ? gmdate( 'Y-m-d H:i:s', $next_queue_attempt ) : '',
			'lastSearchAt'          => (string) ( $state['lastSearchAt'] ?? '' ),
			'lastSearchMode'        => (string) ( $state['lastSearchMode'] ?? '' ),
			'lastResultCount'       => (int) ( $state['lastResultCount'] ?? 0 ),
			'lastTrustedSuccessAt'  => (string) ( $state['lastTrustedSuccessAt'] ?? '' ),
			'lastTrustedSuccessMode'=> (string) ( $state['lastTrustedSuccessMode'] ?? '' ),
			'lastServedAt'          => (string) ( $state['lastServedAt'] ?? '' ),
			'lastServedMode'        => (string) ( $state['lastServedMode'] ?? '' ),
			'lastFallbackType'      => (string) ( $state['lastFallbackType'] ?? '' ),
			'lastErrorAt'           => (string) ( $state['lastErrorAt'] ?? '' ),
			'lastErrorMode'         => (string) ( $state['lastErrorMode'] ?? '' ),
			'lastErrorCode'         => (string) ( $state['lastErrorCode'] ?? '' ),
			'lastErrorMessage'      => (string) ( $state['lastErrorMessage'] ?? '' ),
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
		$key   = self::build_context_warm_queue_key( $entry );

		if ( isset( $queue[ $key ] ) ) {
			$existing_entry = self::normalize_context_warm_queue_entry( $queue[ $key ] );

			if ( null !== $existing_entry ) {
				$entry['attempts']         = $existing_entry['attempts'];
				$entry['nextAttemptAt']    = $existing_entry['nextAttemptAt'];
				$entry['lastErrorAt']      = $existing_entry['lastErrorAt'];
				$entry['lastErrorCode']    = $existing_entry['lastErrorCode'];
				$entry['lastErrorMessage'] = $existing_entry['lastErrorMessage'];
			}
		}

		$queue[ $key ] = $entry;
		self::write_context_warm_queue( $queue );
		self::sync_context_warm_schedule( $queue );
	}

	/**
	 * Drain the queued async warm requests.
	 */
	public static function process_context_warm_queue(): void {
		$queue = self::read_context_warm_queue();

		if ( [] === $queue ) {
			self::sync_context_warm_schedule( [] );
			return;
		}

		wp_clear_scheduled_hook( self::CONTEXT_WARM_CRON_HOOK );

		if ( ! self::is_configured() ) {
			self::write_context_warm_queue( [] );
			self::sync_context_warm_schedule( [] );
			return;
		}

		$now = time();

		foreach ( $queue as $key => $entry ) {
			$normalized_entry = self::normalize_context_warm_queue_entry( $entry );

			if ( null === $normalized_entry ) {
				unset( $queue[ $key ] );
				continue;
			}

			if ( $normalized_entry['nextAttemptAt'] > $now ) {
				continue;
			}

			$result = self::warm_context(
				$normalized_entry['query'],
				$normalized_entry['entityKey'],
				$normalized_entry['familyContext'],
				$normalized_entry['maxResults'],
				'async'
			);

			if ( is_wp_error( $result ) ) {
				$queue[ $key ] = self::build_retry_context_warm_queue_entry(
					$normalized_entry,
					$result->get_error_code(),
					$result->get_error_message()
				);
				continue;
			}

			if ( [] === $result['guidance'] ) {
				$queue[ $key ] = self::build_retry_context_warm_queue_entry(
					$normalized_entry,
					'empty_guidance',
					'Grounding search returned no trusted guidance.'
				);
				continue;
			}

			unset( $queue[ $key ] );
		}

		self::write_context_warm_queue( $queue );
		self::sync_context_warm_schedule( $queue );
	}

	/**
	 * Attempt a single foreground warm when only broad fallback guidance is available.
	 *
	 * @param array<string, mixed> $family_context
	 * @return array<int, array<string, mixed>>
	 */
	private static function maybe_foreground_warm_context(
		string $query,
		string $entity_key = '',
		array $family_context = [],
		?int $max_results = null
	): array {
		if ( ! self::is_configured() ) {
			return [];
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
			return [];
		}

		$lock_key = self::FOREGROUND_WARM_LOCK_PREFIX . self::build_context_warm_queue_key( $entry );

		if ( false !== get_transient( $lock_key ) ) {
			return [];
		}

		set_transient( $lock_key, time(), self::FOREGROUND_WARM_LOCK_TTL );

		try {
			$result = self::warm_context(
				$entry['query'],
				$entry['entityKey'],
				$entry['familyContext'],
				$entry['maxResults'],
				'foreground',
				self::FOREGROUND_WARM_TIMEOUT
			);

			if ( is_wp_error( $result ) ) {
				return [];
			}

			return $result['guidance'];
		} finally {
			delete_transient( $lock_key );
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

	private static function has_explicit_legacy_config_request(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): bool {
		return null !== $account_id || null !== $instance_id || null !== $api_token;
	}

	private static function has_legacy_credentials(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): bool {
		return trim( (string) $account_id ) !== ''
			&& trim( (string) $instance_id ) !== ''
			&& trim( (string) $api_token ) !== '';
	}

	private static function build_credentials_fingerprint(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): string {
		$payload = wp_json_encode(
			self::build_config_identity_payload(
				true,
				$account_id,
				$instance_id,
				$api_token
			)
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			return '';
		}

		return md5( $payload );
	}

	/**
	 * @return array<string, string>
	 */
	private static function build_config_identity_payload(
		bool $include_secret = false,
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array {
		$config = self::get_config( $account_id, $instance_id, $api_token );

		if ( is_wp_error( $config ) ) {
			return [];
		}

		$payload = [
			'mode'       => $config['mode'],
			'instanceId' => $config['instanceId'],
			'searchUrl'  => $config['searchUrl'],
		];

		if ( $include_secret && $config['apiToken'] !== '' ) {
			$payload['apiToken'] = $config['apiToken'];
		}

		return $payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function read_prewarm_state(): array {
		$state = get_option( self::PREWARM_STATE_OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * @return array{
	 *   lastSearchAt: string,
	 *   lastSearchMode: string,
	 *   lastResultCount: int,
	 *   lastTrustedSuccessAt: string,
	 *   lastTrustedSuccessMode: string,
	 *   lastServedAt: string,
	 *   lastServedMode: string,
	 *   lastFallbackType: string,
	 *   lastErrorAt: string,
	 *   lastErrorMode: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }
	 */
	private static function read_runtime_state(): array {
		$state = get_option( self::RUNTIME_STATE_OPTION, [] );

		return self::normalize_runtime_state( is_array( $state ) ? $state : [] );
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array{
	 *   lastSearchAt: string,
	 *   lastSearchMode: string,
	 *   lastResultCount: int,
	 *   lastTrustedSuccessAt: string,
	 *   lastTrustedSuccessMode: string,
	 *   lastServedAt: string,
	 *   lastServedMode: string,
	 *   lastFallbackType: string,
	 *   lastErrorAt: string,
	 *   lastErrorMode: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }
	 */
	private static function normalize_runtime_state( array $state ): array {
		return [
			'lastSearchAt'           => self::normalize_runtime_timestamp( $state['lastSearchAt'] ?? '' ),
			'lastSearchMode'         => sanitize_key( (string) ( $state['lastSearchMode'] ?? '' ) ),
			'lastResultCount'        => max( 0, (int) ( $state['lastResultCount'] ?? 0 ) ),
			'lastTrustedSuccessAt'   => self::normalize_runtime_timestamp( $state['lastTrustedSuccessAt'] ?? '' ),
			'lastTrustedSuccessMode' => sanitize_key( (string) ( $state['lastTrustedSuccessMode'] ?? '' ) ),
			'lastServedAt'           => self::normalize_runtime_timestamp( $state['lastServedAt'] ?? '' ),
			'lastServedMode'         => sanitize_key( (string) ( $state['lastServedMode'] ?? '' ) ),
			'lastFallbackType'       => sanitize_key( (string) ( $state['lastFallbackType'] ?? '' ) ),
			'lastErrorAt'            => self::normalize_runtime_timestamp( $state['lastErrorAt'] ?? '' ),
			'lastErrorMode'          => sanitize_key( (string) ( $state['lastErrorMode'] ?? '' ) ),
			'lastErrorCode'          => sanitize_key( (string) ( $state['lastErrorCode'] ?? '' ) ),
			'lastErrorMessage'       => sanitize_text_field( (string) ( $state['lastErrorMessage'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function write_runtime_state( array $state ): void {
		update_option(
			self::RUNTIME_STATE_OPTION,
			self::normalize_runtime_state( $state ),
			false
		);
	}

	private static function normalize_runtime_timestamp( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( $value ) );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp || $timestamp <= 0 ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private static function current_runtime_timestamp(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function parse_runtime_timestamp( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? 0 : max( 0, (int) $timestamp );
	}

	/**
	 * @return array<string, array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }>
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
	 * @param array<string, array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }> $queue
	 */
	private static function write_context_warm_queue( array $queue ): void {
		update_option( self::WARM_QUEUE_OPTION, $queue, false );
	}

	/**
	 * @return array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }|null
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
			'attempts'         => max( 0, (int) ( $entry['attempts'] ?? 0 ) ),
			'nextAttemptAt'    => max( 0, (int) ( $entry['nextAttemptAt'] ?? 0 ) ),
			'lastErrorAt'      => self::normalize_runtime_timestamp( $entry['lastErrorAt'] ?? '' ),
			'lastErrorCode'    => sanitize_key( (string) ( $entry['lastErrorCode'] ?? '' ) ),
			'lastErrorMessage' => sanitize_text_field( (string) ( $entry['lastErrorMessage'] ?? '' ) ),
		];
	}

	/**
	 * @param array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * } $entry
	 * @return array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }
	 */
	private static function build_retry_context_warm_queue_entry(
		array $entry,
		string $error_code,
		string $error_message
	): array {
		$attempts = max( 0, (int) ( $entry['attempts'] ?? 0 ) ) + 1;

		$entry['attempts']         = $attempts;
		$entry['nextAttemptAt']    = time() + self::resolve_context_warm_retry_delay( $attempts );
		$entry['lastErrorAt']      = self::current_runtime_timestamp();
		$entry['lastErrorCode']    = sanitize_key( $error_code );
		$entry['lastErrorMessage'] = sanitize_text_field( $error_message );

		return $entry;
	}

	private static function resolve_context_warm_retry_delay( int $attempts ): int {
		$attempts = max( 1, $attempts );
		$delay    = (int) ( self::WARM_QUEUE_RETRY_BASE_SECONDS * pow( 2, max( 0, $attempts - 1 ) ) );

		return max(
			self::WARM_QUEUE_RETRY_BASE_SECONDS,
			min( self::WARM_QUEUE_RETRY_MAX_SECONDS, $delay )
		);
	}

	/**
	 * @param array<string, array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }> $queue
	 */
	private static function sync_context_warm_schedule( array $queue ): void {
		$existing_schedule = wp_next_scheduled( self::CONTEXT_WARM_CRON_HOOK );
		$next_attempt      = self::resolve_next_context_warm_attempt( $queue );

		if ( $next_attempt <= 0 ) {
			if ( false !== $existing_schedule ) {
				wp_clear_scheduled_hook( self::CONTEXT_WARM_CRON_HOOK );
			}

			return;
		}

		$scheduled_for = max( time() + 5, $next_attempt );

		if ( false !== $existing_schedule ) {
			$existing_schedule = (int) $existing_schedule;

			if ( $existing_schedule >= time() && $existing_schedule <= $scheduled_for ) {
				return;
			}

			wp_clear_scheduled_hook( self::CONTEXT_WARM_CRON_HOOK );
		}

		wp_schedule_single_event( $scheduled_for, self::CONTEXT_WARM_CRON_HOOK );
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

	private static function record_runtime_search_error( string $runtime_mode, \WP_Error $error ): void {
		$state                    = self::read_runtime_state();
		$timestamp                = self::current_runtime_timestamp();
		$state['lastSearchAt']    = $timestamp;
		$state['lastSearchMode']  = sanitize_key( $runtime_mode );
		$state['lastResultCount'] = 0;
		$state['lastErrorAt']     = $timestamp;
		$state['lastErrorMode']   = sanitize_key( $runtime_mode );
		$state['lastErrorCode']   = sanitize_key( $error->get_error_code() );
		$state['lastErrorMessage'] = sanitize_text_field( $error->get_error_message() );

		self::write_runtime_state( $state );
	}

	private static function record_runtime_search_empty_result( string $runtime_mode ): void {
		$state                    = self::read_runtime_state();
		$state['lastSearchAt']    = self::current_runtime_timestamp();
		$state['lastSearchMode']  = sanitize_key( $runtime_mode );
		$state['lastResultCount'] = 0;
		$state['lastErrorAt']     = '';
		$state['lastErrorMode']   = '';
		$state['lastErrorCode']   = '';
		$state['lastErrorMessage'] = '';

		self::write_runtime_state( $state );
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function record_runtime_search_success( string $runtime_mode, array $guidance ): void {
		$state                           = self::read_runtime_state();
		$timestamp                       = self::current_runtime_timestamp();
		$state['lastSearchAt']           = $timestamp;
		$state['lastSearchMode']         = sanitize_key( $runtime_mode );
		$state['lastResultCount']        = count( $guidance );
		$state['lastTrustedSuccessAt']   = $timestamp;
		$state['lastTrustedSuccessMode'] = sanitize_key( $runtime_mode );
		$state['lastErrorAt']            = '';
		$state['lastErrorMode']          = '';
		$state['lastErrorCode']          = '';
		$state['lastErrorMessage']       = '';

		self::write_runtime_state( $state );
	}

	private static function record_runtime_served_guidance( string $fallback_type, string $served_mode ): void {
		$state                     = self::read_runtime_state();
		$state['lastServedAt']     = self::current_runtime_timestamp();
		$state['lastServedMode']   = sanitize_key( $served_mode );
		$state['lastFallbackType'] = sanitize_key( $fallback_type );

		self::write_runtime_state( $state );
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string, array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }> $queue
	 */
	private static function resolve_runtime_status( array $state, array $queue ): string {
		$last_search_at  = self::parse_runtime_timestamp( (string) ( $state['lastSearchAt'] ?? '' ) );
		$last_success_at = self::parse_runtime_timestamp( (string) ( $state['lastTrustedSuccessAt'] ?? '' ) );
		$last_error_at   = self::parse_runtime_timestamp( (string) ( $state['lastErrorAt'] ?? '' ) );
		$last_result_count = (int) ( $state['lastResultCount'] ?? 0 );

		if ( [] !== $queue ) {
			if ( $last_error_at > $last_success_at ) {
				return 'retrying';
			}

			return 'warming';
		}

		if ( $last_error_at > 0 && $last_success_at <= 0 ) {
			return 'error';
		}

		if ( $last_error_at > $last_success_at ) {
			return 'degraded';
		}

		if ( $last_search_at > $last_success_at && $last_result_count <= 0 ) {
			return 'degraded';
		}

		if ( $last_success_at > 0 ) {
			return 'healthy';
		}

		if ( ! empty( $state['lastServedAt'] ) ) {
			return 'cache';
		}

		return 'idle';
	}

	/**
	 * @param array<string, array{
	 *   query: string,
	 *   entityKey: string,
	 *   familyContext: array<string, mixed>,
	 *   maxResults: int,
	 *   attempts: int,
	 *   nextAttemptAt: int,
	 *   lastErrorAt: string,
	 *   lastErrorCode: string,
	 *   lastErrorMessage: string
	 * }> $queue
	 */
	private static function resolve_next_context_warm_attempt( array $queue ): int {
		if ( [] === $queue ) {
			return 0;
		}

		$next_attempt = 0;
		$now          = time();

		foreach ( $queue as $entry ) {
			$normalized_entry = self::normalize_context_warm_queue_entry( $entry );

			if ( null === $normalized_entry ) {
				continue;
			}

			$candidate = $normalized_entry['nextAttemptAt'] > 0
				? $normalized_entry['nextAttemptAt']
				: $now;

			if ( 0 === $next_attempt || $candidate < $next_attempt ) {
				$next_attempt = $candidate;
			}
		}

		return $next_attempt;
	}

	/**
	 * @return array{mode: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string}|\WP_Error
	 */
	private static function get_config(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array|\WP_Error {
		if ( self::has_explicit_legacy_config_request( $account_id, $instance_id, $api_token ) ) {
			return self::build_legacy_config(
				trim( (string) $account_id ),
				trim( (string) $instance_id ),
				trim( (string) $api_token )
			);
		}

		$account_id  = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_account_id', '' ) );
		$instance_id = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' ) );
		$api_token   = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_api_token', '' ) );

		if ( self::has_legacy_credentials( $account_id, $instance_id, $api_token ) ) {
			return self::build_legacy_config( $account_id, $instance_id, $api_token );
		}

		$public_search_url = self::get_public_search_url();

		if ( '' !== $public_search_url ) {
			return self::build_public_config( $public_search_url );
		}

		return new \WP_Error(
			'missing_cloudflare_ai_search_credentials',
			'Cloudflare AI Search is not configured.',
			[ 'status' => 400 ]
		);
	}

	/**
	 * @return array{mode: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string}|\WP_Error
	 */
	private static function build_legacy_config( string $account_id, string $instance_id, string $api_token ): array|\WP_Error {
		if ( ! self::has_legacy_credentials( $account_id, $instance_id, $api_token ) ) {
			return new \WP_Error(
				'missing_cloudflare_ai_search_credentials',
				'Cloudflare AI Search credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		return [
			'mode'        => 'legacy',
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

	/**
	 * @return array{mode: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string}|\WP_Error
	 */
	private static function build_public_config( string $search_url ): array|\WP_Error {
		$normalized_search_url = self::normalize_public_search_url( $search_url );

		if ( '' === $normalized_search_url ) {
			return new \WP_Error(
				'missing_cloudflare_ai_search_credentials',
				'Cloudflare AI Search public search URL is invalid.',
				[ 'status' => 400 ]
			);
		}

		return [
			'mode'        => 'public',
			'instanceId'  => self::extract_public_instance_id( $normalized_search_url ),
			'instanceUrl' => '',
			'searchUrl'   => $normalized_search_url,
			'apiToken'    => '',
		];
	}

	private static function get_public_search_url(): string {
		$search_url = self::DEFAULT_PUBLIC_SEARCH_URL;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the public Cloudflare AI Search endpoint used for trusted WordPress docs grounding.
			 *
			 * Return an empty string to disable the built-in public endpoint and require legacy credentials.
			 *
			 * @param string $public_search_url Public AI Search `/search` endpoint.
			 */
			$search_url = apply_filters(
				'flavor_agent_cloudflare_ai_search_public_search_url',
				$search_url
			);
		}

		return self::normalize_public_search_url( is_string( $search_url ) ? $search_url : '' );
	}

	private static function normalize_public_search_url( string $search_url ): string {
		$search_url = trim( $search_url );

		if ( '' === $search_url ) {
			return '';
		}

		$parts = wp_parse_url( $search_url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if (
			'https' !== $scheme ||
			'' === $host ||
			! str_ends_with( $host, self::PUBLIC_HOST_SUFFIX ) ||
			( isset( $parts['user'] ) && '' !== (string) $parts['user'] ) ||
			( isset( $parts['pass'] ) && '' !== (string) $parts['pass'] ) ||
			( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ||
			isset( $parts['query'] ) ||
			isset( $parts['fragment'] )
		) {
			return '';
		}

		$path = preg_replace( '#/+#', '/', $path );
		$path = is_string( $path ) ? rtrim( $path, '/' ) : '';

		if ( '' === $path ) {
			$path = '/search';
		} elseif ( '/mcp' === $path ) {
			$path = '/search';
		}

		if ( '/search' !== $path ) {
			return '';
		}

		return 'https://' . $host . $path;
	}

	private static function extract_public_instance_id( string $search_url ): string {
		$parts = wp_parse_url( $search_url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( ! str_ends_with( $host, self::PUBLIC_HOST_SUFFIX ) ) {
			return '';
		}

		$instance_id = substr( $host, 0, -strlen( self::PUBLIC_HOST_SUFFIX ) );

		if ( ! is_string( $instance_id ) || '' === $instance_id || str_contains( $instance_id, '.' ) ) {
			return '';
		}

		return strtolower( sanitize_text_field( $instance_id ) );
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
	 * @param array{mode: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string} $config
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request_search(
		array $config,
		string $query,
		int $result_limit,
		string $error_code,
		string $parse_error_code,
		int $error_status,
		?int $timeout = null
	): array|\WP_Error {
		$headers = [
			'Content-Type' => 'application/json',
		];

		if ( $config['apiToken'] !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $config['apiToken'];
		}

		$response = wp_remote_post(
			$config['searchUrl'],
			[
				'timeout' => null !== $timeout ? max( 1, $timeout ) : 20,
				'headers' => $headers,
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
		$payload = wp_json_encode( self::build_config_identity_payload() );

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

		if ( str_starts_with( $entity_key, 'guidance:' ) ) {
			$guidance_slug = sanitize_key( substr( $entity_key, strlen( 'guidance:' ) ) );

			return $guidance_slug !== '' ? 'guidance:' . $guidance_slug : '';
		}

		return preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $entity_key ) === 1 ? $entity_key : '';
	}

	/**
	 * @param array<string, mixed> $family_context
	 */
	private static function resolve_generic_entity_fallback( string $entity_key, array $family_context = [] ): string {
		$entity_key = self::normalize_entity_key( $entity_key );
		$surface    = sanitize_key( (string) ( $family_context['surface'] ?? '' ) );

		if ( self::GUIDANCE_GLOBAL_STYLES_KEY === $entity_key || 'global-styles' === $surface ) {
			return self::GUIDANCE_GLOBAL_STYLES_KEY;
		}

		if ( self::GUIDANCE_STYLE_BOOK_KEY === $entity_key || 'style-book' === $surface ) {
			return self::GUIDANCE_STYLE_BOOK_KEY;
		}

		if ( 'core/template-part' === $entity_key || 'template-part' === $surface ) {
			return self::GUIDANCE_TEMPLATE_PART_KEY;
		}

		if ( str_starts_with( $entity_key, 'template:' ) || 'template' === $surface ) {
			return self::GUIDANCE_TEMPLATE_KEY;
		}

		if (
			preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $entity_key ) === 1 ||
			'block' === $surface
		) {
			return self::GUIDANCE_BLOCK_EDITOR_KEY;
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $family_context
	 * @return array<string, mixed>
	 */
	private static function normalize_family_context( array $family_context ): array {
		$normalized = self::normalize_family_context_value( $family_context, 0, [] );

		return is_array( $normalized ) ? $normalized : [];
	}

	/**
	 * @param array<int, true> $seen_object_ids
	 * @return array<string, mixed>|bool|float|int|string|null
	 */
	private static function normalize_family_context_value(
		mixed $value,
		int $depth = 0,
		array $seen_object_ids = []
	): array|bool|float|int|string|null {
		if ( $depth > self::MAX_FAMILY_CONTEXT_DEPTH ) {
			return null;
		}

		if ( is_object( $value ) ) {
			$object_id = spl_object_id( $value );

			if ( isset( $seen_object_ids[ $object_id ] ) ) {
				return null;
			}

			$seen_object_ids[ $object_id ] = true;
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$is_list    = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized_entry = self::normalize_family_context_value(
					$entry,
					$depth + 1,
					$seen_object_ids
				);

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
	 * @param array{mode: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string} $config
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
	 * @param string|null       $instance_id Resolved Cloudflare AI Search instance ID.
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
			$url          = self::normalize_guidance_url( $metadata['url'] ?? null, $parsed_chunk['url'] );
			$text         = self::sanitize_excerpt( $parsed_chunk['excerpt'] );

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

		if ( self::string_length( $text ) > 360 ) {
			$text = self::string_substr( $text, 0, 357 ) . '...';
		}

		return $text;
	}

	private static function string_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		if ( preg_match( '//u', $value ) ) {
			preg_match_all( '/./us', $value, $matches );
			return count( $matches[0] ?? [] );
		}

		return strlen( $value );
	}

	private static function string_substr( string $value, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, $start, $length, 'UTF-8' );
		}

		if ( preg_match( '//u', $value ) ) {
			$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

			if ( ! is_array( $characters ) ) {
				return '';
			}

			return implode( '', array_slice( $characters, $start, $length ) );
		}

		return substr( $value, $start, $length );
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

		$parts = wp_parse_url( $url );

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
		$prefixes            = [ self::ALLOWED_SOURCE_KEY_PREFIX ];
		$normalized_instance = self::normalize_source_key_instance_id( $instance_id );

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

		if ( '' === $resolved ) {
			$resolved = self::extract_public_instance_id( self::get_public_search_url() );
		}

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

		$path = wp_parse_url( $normalized, PHP_URL_PATH );

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
		$normalized_text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$url             = '';
		$excerpt         = $normalized_text;

		if ( preg_match( '/\A---\n(.*?)\n---(?:\n|$)(.*)\z/s', $normalized_text, $matches ) ) {
			$frontmatter = (string) ( $matches[1] ?? '' );
			$excerpt     = (string) ( $matches[2] ?? '' );

			if ( preg_match( '/(?:original_url|source_url):\s*"([^"]+)"/', $frontmatter, $url_matches ) ) {
				$url = (string) ( $url_matches[1] ?? '' );
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
