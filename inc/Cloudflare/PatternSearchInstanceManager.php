<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Embeddings\BaseHttpClient;
use FlavorAgent\Patterns\PatternIndex;

final class PatternSearchInstanceManager extends BaseHttpClient {

	public const OWNER_MARKER_NAME   = '__flavor_agent_owner__';
	public const PROVISION_CRON_HOOK = 'flavor_agent_provision_pattern_ai_search';
	public const MANAGED_NAMESPACE   = 'default';

	/**
	 * Upper bound on how many provisioning cron runs may reschedule themselves
	 * while waiting for Cloudflare to finish indexing the owner marker (or to
	 * recover from a transient error) before the attempt is recorded as failed.
	 */
	public const MARKER_PROVISION_MAX_ATTEMPTS = 12;

	private const REQUEST_TIMEOUT                      = 20;
	private const MARKER_POLL_ATTEMPTS                 = 3;
	private const MARKER_POLL_INTERVAL                 = 2;
	private const MARKER_PROVISION_RETRY_DELAY         = 30;
	private const LIST_ITEMS_PER_PAGE                  = 50;
	private const SITE_HASH_LENGTH                     = 16;
	private const SUPPORTED_AI_SEARCH_EMBEDDING_MODELS = [
		'@cf/qwen/qwen3-embedding-0.6b',
		'@cf/baai/bge-m3',
		'@cf/baai/bge-large-en-v1.5',
		'@cf/google/embeddinggemma-300m',
		'google-ai-studio/gemini-embedding-001',
		'google-ai-studio/gemini-embedding-2-preview',
		'openai/text-embedding-3-small',
		'openai/text-embedding-3-large',
	];

	public static function managed_instance_id(): string {
		return 'flavor-agent-patterns-' . self::site_hash();
	}

	public static function managed_namespace(): string {
		return self::MANAGED_NAMESPACE;
	}

	public static function is_managed_instance_id( string $instance_id ): bool {
		return self::managed_instance_id() === trim( sanitize_text_field( $instance_id ) );
	}

	public static function site_hash(): string {
		$url = function_exists( 'home_url' ) ? home_url() : 'local';

		return substr( hash( 'sha256', strtolower( trim( (string) $url ) ) ), 0, self::SITE_HASH_LENGTH );
	}

	public static function credential_signature( string $account_id, string $api_token, string $embedding_model ): string {
		$payload = [
			self::site_hash(),
			trim( sanitize_text_field( $account_id ) ),
			wp_hash( trim( sanitize_text_field( $api_token ) ) ),
			self::normalize_embedding_model_for_ai_search( $embedding_model ),
		];

		return hash( 'sha256', implode( "\n", $payload ) );
	}

	/**
	 * @return array{
	 *   status: string,
	 *   signature: string,
	 *   requested_at: string,
	 *   completed_at: string,
	 *   managed_status: string,
	 *   last_error_code: string,
	 *   last_error: string,
	 *   owner_marker_repair: string,
	 *   marker_attempts: string
	 * }
	 */
	public static function get_provisioning_state(): array {
		$state = get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE, [] );

		if ( ! is_array( $state ) ) {
			$state = [];
		}

		return [
			'status'              => sanitize_key( (string) ( $state['status'] ?? '' ) ),
			'signature'           => sanitize_text_field( (string) ( $state['signature'] ?? '' ) ),
			'requested_at'        => sanitize_text_field( (string) ( $state['requested_at'] ?? '' ) ),
			'completed_at'        => sanitize_text_field( (string) ( $state['completed_at'] ?? '' ) ),
			'managed_status'      => sanitize_key( (string) ( $state['managed_status'] ?? '' ) ),
			'last_error_code'     => sanitize_key( (string) ( $state['last_error_code'] ?? '' ) ),
			'last_error'          => sanitize_text_field( (string) ( $state['last_error'] ?? '' ) ),
			'owner_marker_repair' => sanitize_key( (string) ( $state['owner_marker_repair'] ?? '' ) ),
			'marker_attempts'     => (string) (int) ( $state['marker_attempts'] ?? 0 ),
		];
	}

	public static function schedule_managed_instance_provisioning( string $signature ): void {
		$previous_state = self::get_provisioning_state();

		self::save_provisioning_state(
			[
				'status'              => 'provisioning',
				'signature'           => $signature,
				'requested_at'        => gmdate( 'c' ),
				'owner_marker_repair' => self::can_repair_missing_owner_marker_from_state( $previous_state, $signature ) ? '1' : '',
			]
		);

		if ( ! wp_next_scheduled( self::PROVISION_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::PROVISION_CRON_HOOK );
		}
	}

	public static function process_managed_instance_provisioning(): void {
		$state = self::get_provisioning_state();

		if ( 'provisioning' !== $state['status'] ) {
			return;
		}

		if (
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH !== sanitize_key(
				(string) get_option( Config::OPTION_PATTERN_RETRIEVAL_BACKEND, Config::PATTERN_BACKEND_QDRANT )
			)
		) {
			return;
		}

		$account_id      = trim( (string) get_option( 'flavor_agent_cloudflare_workers_ai_account_id', '' ) );
		$api_token       = trim( (string) get_option( 'flavor_agent_cloudflare_workers_ai_api_token', '' ) );
		$embedding_model = trim(
			(string) get_option(
				'flavor_agent_cloudflare_workers_ai_embedding_model',
				WorkersAIEmbeddingConfiguration::DEFAULT_MODEL
			)
		);
		$signature       = self::credential_signature( $account_id, $api_token, $embedding_model );

		if ( '' !== $state['signature'] && $state['signature'] !== $signature ) {
			delete_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE );
			self::save_provisioning_state(
				[
					'status'          => 'stale',
					'signature'       => $state['signature'],
					'last_error_code' => 'cloudflare_pattern_ai_search_signature_changed',
					'last_error'      => 'Embedding Model credentials changed before managed pattern index provisioning finished. Save settings again to restart provisioning.',
					'completed_at'    => gmdate( 'c' ),
				]
			);
			return;
		}

		self::raise_request_time_limit();

		$managed = self::ensure_managed_instance(
			$account_id,
			$api_token,
			$embedding_model,
			'1' === $state['owner_marker_repair']
		);

		if ( is_wp_error( $managed ) ) {
			if ( self::should_retry_marker_provisioning( $managed, $state ) ) {
				self::reschedule_marker_provisioning( $state, $signature, $managed );
				return;
			}

			delete_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE );
			self::save_provisioning_state(
				[
					'status'          => 'error',
					'signature'       => $signature,
					'last_error_code' => (string) $managed->get_error_code(),
					'last_error'      => $managed->get_error_message(),
					'completed_at'    => gmdate( 'c' ),
				]
			);
			return;
		}

		update_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID, $managed['instance_id'], false );
		update_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE, self::managed_namespace(), false );
		update_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE, $signature, false );
		self::save_provisioning_state(
			[
				'status'         => 'ready',
				'signature'      => $signature,
				'managed_status' => (string) ( $managed['status'] ?? 'ready' ),
				'completed_at'   => gmdate( 'c' ),
			]
		);
		PatternIndex::mark_dirty( 'cloudflare_ai_search_signature_changed' );
		PatternIndex::schedule_sync( true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function build_create_payload( string $embedding_model ): array {
		return [
			'id'                     => self::managed_instance_id(),
			'embedding_model'        => self::normalize_embedding_model_for_ai_search( $embedding_model ),
			'chunk'                  => true,
			'chunk_size'             => 1024,
			'chunk_overlap'          => 15,
			'custom_metadata'        => self::expected_custom_metadata(),
			'fusion_method'          => 'rrf',
			'index_method'           => [
				'keyword' => true,
				'vector'  => true,
			],
			'indexing_options'       => [
				'keyword_tokenizer' => 'porter',
			],
			'max_num_results'        => 50,
			'retrieval_options'      => [
				'keyword_match_mode' => 'or',
			],
			'rewrite_query'          => false,
			'reranking'              => false,
			'cache'                  => false,
			'public_endpoint_params' => [
				'enabled'                   => false,
				'search_endpoint'           => [ 'disabled' => true ],
				'chat_completions_endpoint' => [ 'disabled' => true ],
				'mcp'                       => [ 'disabled' => true ],
			],
		];
	}

	/**
	 * @return array{instance_id: string, status: string}|\WP_Error
	 */
	public static function ensure_managed_instance(
		string $account_id,
		string $api_token,
		string $embedding_model,
		bool $repair_missing_owner_marker = false
	): array|\WP_Error {
		$config = self::normalize_credentials( $account_id, $api_token );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$instances = self::list_instances( $config['account_id'], $config['api_token'] );

		if ( is_wp_error( $instances ) ) {
			return $instances;
		}

		foreach ( $instances as $instance ) {
			$compatible = self::assert_compatible_instance( $instance, $embedding_model );

			if ( is_wp_error( $compatible ) ) {
				return $compatible;
			}

			$marker = self::validate_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

			if ( is_wp_error( $marker ) ) {
				if ( $repair_missing_owner_marker && self::is_owner_marker_missing_error( $marker ) ) {
					$repaired = self::repair_missing_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

					if ( is_wp_error( $repaired ) ) {
						return $repaired;
					}

					return [
						'instance_id' => self::managed_instance_id(),
						'status'      => 'repaired_owner_marker',
					];
				}

				return $marker;
			}

			return [
				'instance_id' => self::managed_instance_id(),
				'status'      => 'adopted',
			];
		}

		$created = self::create_instance( $config['account_id'], $config['api_token'], $embedding_model );

		if ( is_wp_error( $created ) ) {
			if ( self::is_create_conflict_error( $created ) ) {
				return self::try_adopt_after_create_conflict( $config['account_id'], $config['api_token'], $embedding_model );
			}

			return $created;
		}

		$marker = self::write_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

		if ( is_wp_error( $marker ) ) {
			return $marker;
		}

		$validated_marker = self::confirm_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

		if ( is_wp_error( $validated_marker ) ) {
			return $validated_marker;
		}

		return [
			'instance_id' => self::managed_instance_id(),
			'status'      => 'created',
		];
	}

	/**
	 * @return array<int, array{field_name: string, data_type: string}>
	 */
	private static function expected_custom_metadata(): array {
		return [
			[
				'field_name' => 'pattern_name',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'candidate_type',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'source',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'synced_id',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'public_safe',
				'data_type'  => 'boolean',
			],
		];
	}

	public static function normalize_embedding_model_for_ai_search( string $embedding_model ): string {
		$embedding_model = trim( sanitize_text_field( $embedding_model ) );

		return in_array( $embedding_model, self::SUPPORTED_AI_SEARCH_EMBEDDING_MODELS, true )
			? $embedding_model
			: WorkersAIEmbeddingConfiguration::DEFAULT_MODEL;
	}

	public static function embedding_model_supported_by_ai_search( string $embedding_model ): bool {
		$embedding_model = trim( sanitize_text_field( $embedding_model ) );

		return '' !== $embedding_model
			&& in_array( $embedding_model, self::SUPPORTED_AI_SEARCH_EMBEDDING_MODELS, true );
	}

	/**
	 * @param array<string, string> $state
	 */
	private static function save_provisioning_state( array $state ): void {
		update_option(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE,
			array_merge(
				[
					'status'              => '',
					'signature'           => '',
					'requested_at'        => '',
					'completed_at'        => '',
					'managed_status'      => '',
					'last_error_code'     => '',
					'last_error'          => '',
					'owner_marker_repair' => '',
					'marker_attempts'     => '',
				],
				array_map( 'sanitize_text_field', $state )
			),
			false
		);
	}

		/**
		 * @param array{signature: string, last_error_code: string} $state
		 */
	private static function can_repair_missing_owner_marker_from_state( array $state, string $signature ): bool {
		return $signature === $state['signature']
			&& in_array(
				$state['last_error_code'],
				[
					'cloudflare_pattern_ai_search_owner_marker_error',
					'cloudflare_pattern_ai_search_owner_marker_missing',
				],
				true
			);
	}

	/**
	 * @return array{account_id: string, api_token: string}|\WP_Error
	 */
	private static function normalize_credentials( string $account_id, string $api_token ): array|\WP_Error {
		$account_id = trim( sanitize_text_field( $account_id ) );
		$api_token  = trim( sanitize_text_field( $api_token ) );

		if ( '' === $account_id || '' === $api_token ) {
			return new \WP_Error(
				'missing_cloudflare_pattern_ai_search_credentials',
				'Cloudflare AI Search Pattern Storage needs the Embedding Model account ID and API token.',
				[ 'status' => 400 ]
			);
		}

		return [
			'account_id' => $account_id,
			'api_token'  => $api_token,
		];
	}

	private static function instances_url( string $account_id ): string {
		return sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/instances',
			rawurlencode( $account_id )
		);
	}

	private static function instance_items_url( string $account_id, string $instance_id ): string {
		return sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/namespaces/%s/instances/%s/items',
			rawurlencode( $account_id ),
			rawurlencode( self::managed_namespace() ),
			rawurlencode( $instance_id )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function authorization_headers( string $api_token ): array {
		return [ 'Authorization' => 'Bearer ' . $api_token ];
	}

	/**
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function list_instances( string $account_id, string $api_token ): array|\WP_Error {
		$instances = [];
		$page      = 1;
		$per_page  = 100;

		do {
			$response = self::request_json(
				add_query_arg(
					[
						'search'   => self::managed_instance_id(),
						'page'     => $page,
						'per_page' => $per_page,
					],
					self::instances_url( $account_id )
				),
				[
					'method'  => 'GET',
					'headers' => self::authorization_headers( $api_token ),
				],
				'Cloudflare AI Search instance list',
				self::REQUEST_TIMEOUT
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
				return new \WP_Error(
					'cloudflare_pattern_ai_search_instance_list_error',
					self::remote_failure_message( 'Cloudflare AI Search instance list failed.', $response ),
					self::remote_failure_data( $response )
				);
			}

			$result = is_array( $response['data']['result'] ?? null ) ? $response['data']['result'] : [];
			foreach ( $result as $instance ) {
				if ( is_array( $instance ) && self::managed_instance_id() === (string) ( $instance['id'] ?? '' ) ) {
					$instances[] = $instance;
				}
			}

			$result_info = is_array( $response['data']['result_info'] ?? null ) ? $response['data']['result_info'] : [];
			$total_count = max( 0, (int) ( $result_info['total_count'] ?? count( $result ) ) );
			$page_size   = max( 1, (int) ( $result_info['per_page'] ?? $per_page ) );
			$total_pages = max( 1, (int) ceil( $total_count / $page_size ) );
			++$page;
		} while ( $page <= $total_pages && [] === $instances );

		return $instances;
	}

	private static function create_instance( string $account_id, string $api_token, string $embedding_model ): true|\WP_Error {
		$response = self::post_json(
			self::instances_url( $account_id ),
			array_merge( self::authorization_headers( $api_token ), [ 'Content-Type' => 'application/json' ] ),
			self::encode_json( self::build_create_payload( $embedding_model ) ),
			'Cloudflare AI Search instance create',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! in_array( $response['status'], [ 200, 201 ], true ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_instance_create_error',
				self::remote_failure_message( 'Cloudflare AI Search managed pattern index could not be created.', $response ),
				self::remote_failure_data( $response )
			);
		}

		return true;
	}

	private static function encode_json( mixed $value ): string {
		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @return array<int, array{field_name: string, data_type: string}>
	 */
	private static function normalize_custom_metadata_schema( mixed $schema ): array {
		if ( ! is_array( $schema ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $schema as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$normalized[] = [
				'field_name' => strtolower( sanitize_key( (string) ( $field['field_name'] ?? '' ) ) ),
				'data_type'  => strtolower( sanitize_key( (string) ( $field['data_type'] ?? '' ) ) ),
			];
		}

		usort(
			$normalized,
			static fn( array $a, array $b ): int => strcmp( $a['field_name'], $b['field_name'] )
		);

		return $normalized;
	}

	private static function assert_compatible_instance( array $instance, string $embedding_model ): true|\WP_Error {
		$actual   = self::normalize_custom_metadata_schema( $instance['custom_metadata'] ?? [] );
		$expected = self::normalize_custom_metadata_schema( self::expected_custom_metadata() );

		if ( $actual !== $expected ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_incompatible_schema',
				'The existing Cloudflare AI Search managed pattern index does not use the Flavor Agent metadata schema.',
				[ 'status' => 409 ]
			);
		}

		$actual_model_raw = trim( sanitize_text_field( (string) ( $instance['embedding_model'] ?? '' ) ) );
		$actual_model     = self::normalize_embedding_model_for_ai_search( $actual_model_raw );
		$expected_model   = self::normalize_embedding_model_for_ai_search( $embedding_model );

		if ( '' === $actual_model_raw || $actual_model !== $expected_model ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_embedding_model_mismatch',
				'The existing Cloudflare AI Search managed pattern index uses a different embedding model.',
				[
					'status'         => 409,
					'expected_model' => $expected_model,
					'actual_model'   => $actual_model_raw,
				]
			);
		}

		return true;
	}

	/**
	 * @return array{pattern_name: string, candidate_type: string, source: string, synced_id: string, public_safe: bool}
	 */
	private static function owner_marker_metadata(): array {
		return [
			'pattern_name'   => self::OWNER_MARKER_NAME,
			'candidate_type' => 'flavor_agent_owner',
			'source'         => 'flavor_agent',
			'synced_id'      => self::site_hash(),
			'public_safe'    => true,
		];
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private static function owner_marker_filter(): array {
		return [
			'pattern_name'   => [ '$eq' => self::OWNER_MARKER_NAME ],
			'candidate_type' => [ '$eq' => 'flavor_agent_owner' ],
			'source'         => [ '$eq' => 'flavor_agent' ],
			'synced_id'      => [ '$eq' => self::site_hash() ],
		];
	}

	private static function owner_marker_list_url( string $account_id, string $instance_id ): string {
		return add_query_arg(
			[
				'metadata_filter' => self::encode_json( self::owner_marker_filter() ),
				'per_page'        => 5,
			],
			self::instance_items_url( $account_id, $instance_id )
		);
	}

	private static function write_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
		$response = self::post_multipart(
			self::instance_items_url( $account_id, $instance_id ),
			self::authorization_headers( $api_token ),
			[
				'metadata'            => self::encode_item_upload_metadata( self::owner_marker_metadata() ),
				'wait_for_completion' => 'false',
			],
			[
				'name'         => 'file',
				'filename'     => self::OWNER_MARKER_NAME . '.md',
				'contents'     => "# Flavor Agent Owner\n\nSite hash: " . self::site_hash() . "\n",
				'content_type' => 'text/markdown; charset=UTF-8',
			],
			'Cloudflare AI Search owner marker upload',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! in_array( $response['status'], [ 200, 201, 202 ], true ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_owner_marker_error',
				self::remote_failure_message( 'Flavor Agent could not write the Cloudflare AI Search owner marker.', $response ),
				self::remote_failure_data( $response )
			);
		}

		return true;
	}

	private static function validate_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
		$response = self::request_json(
			self::owner_marker_list_url( $account_id, $instance_id ),
			[
				'method'  => 'GET',
				'headers' => self::authorization_headers( $api_token ),
			],
			'Cloudflare AI Search owner marker read',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_owner_marker_error',
				self::remote_failure_message( 'Flavor Agent could not read the Cloudflare AI Search owner marker.', $response ),
				self::remote_failure_data( $response )
			);
		}

		$items = is_array( $response['data']['result'] ?? null ) ? $response['data']['result'] : [];

		if ( [] === $items ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_owner_marker_missing',
				'The existing Cloudflare AI Search managed pattern index is missing the Flavor Agent owner marker.',
				[ 'status' => 409 ]
			);
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$metadata = is_array( $item['metadata'] ?? null )
				? $item['metadata']
				: ( is_array( $item['item']['metadata'] ?? null ) ? $item['item']['metadata'] : [] );

			if ( self::normalize_owner_marker_metadata( $metadata ) === self::owner_marker_metadata() ) {
				return true;
			}
		}

		return new \WP_Error(
			'cloudflare_pattern_ai_search_owner_marker_mismatch',
			'The existing Cloudflare AI Search managed pattern index belongs to a different Flavor Agent install.',
			[ 'status' => 409 ]
		);
	}

	/**
	 * Confirm the owner marker is visible, doing a short bounded poll while
	 * Cloudflare finishes indexing the asynchronously-uploaded marker item.
	 *
	 * The upload is sent with wait_for_completion=false so no single request
	 * holds the connection open through embedding/indexing, so the marker may
	 * not appear on the first read. This poll is intentionally short (a fixed
	 * interval, capped attempts) to keep the cron worker well under typical
	 * max_execution_time; when it is exhausted the caller reschedules the
	 * provisioning cron to keep waiting. Retry the "not yet visible" condition
	 * and transient transport/upstream errors; a mismatch (foreign owner) is
	 * terminal.
	 */
	private static function confirm_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
		$attempts = self::marker_poll_attempts();
		$interval = self::marker_poll_interval();
		$last     = new \WP_Error(
			'cloudflare_pattern_ai_search_owner_marker_missing',
			'The existing Cloudflare AI Search managed pattern index is missing the Flavor Agent owner marker.',
			[ 'status' => 409 ]
		);

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$result = self::validate_owner_marker( $account_id, $api_token, $instance_id );

			if ( ! is_wp_error( $result ) ) {
				return true;
			}

			if ( ! self::is_owner_marker_missing_error( $result ) && ! self::is_transient_remote_error( $result ) ) {
				return $result;
			}

			$last = $result;

			if ( $attempt < $attempts ) {
				self::pause( $interval );
			}
		}

		return $last;
	}

	private static function marker_poll_attempts(): int {
		$attempts = (int) apply_filters(
			'flavor_agent_cloudflare_pattern_ai_search_marker_poll_attempts',
			self::MARKER_POLL_ATTEMPTS
		);

		return max( 1, min( 20, $attempts ) );
	}

	private static function marker_poll_interval(): int {
		$interval = (int) apply_filters(
			'flavor_agent_cloudflare_pattern_ai_search_marker_poll_interval',
			self::MARKER_POLL_INTERVAL
		);

		return max( 0, min( 60, $interval ) );
	}

	private static function pause( int $seconds ): void {
		$seconds = (int) apply_filters(
			'flavor_agent_cloudflare_pattern_ai_search_marker_poll_sleep',
			$seconds
		);

		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
	}

	/**
	 * Best-effort removal of the execution-time ceiling for the provisioning
	 * cron worker. The bounded in-request poll plus cron reschedule already keep
	 * each run short; this is defense-in-depth for hosts that run WP-Cron inside
	 * a request with a low max_execution_time.
	 */
	private static function raise_request_time_limit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
	}

	private static function repair_missing_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
		$item_ids = self::list_builtin_item_ids( $account_id, $api_token, $instance_id );

		if ( is_wp_error( $item_ids ) ) {
			return $item_ids;
		}

		if ( [] !== $item_ids ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_owner_marker_missing',
				'The existing Cloudflare AI Search managed pattern index is missing the Flavor Agent owner marker and already contains items. Remove the conflicting Cloudflare instance and save settings again.',
				[
					'status'     => 409,
					'item_count' => count( $item_ids ),
				]
			);
		}

		$marker = self::write_owner_marker( $account_id, $api_token, $instance_id );

		if ( is_wp_error( $marker ) ) {
			return $marker;
		}

		return self::confirm_owner_marker( $account_id, $api_token, $instance_id );
	}

	/**
	 * @return array<int, string>|\WP_Error
	 */
	private static function list_builtin_item_ids( string $account_id, string $api_token, string $instance_id ): array|\WP_Error {
		$item_ids = [];
		$page     = 1;

		do {
			$response = self::request_json(
				add_query_arg(
					[
						'page'     => $page,
						'per_page' => self::LIST_ITEMS_PER_PAGE,
						'source'   => 'builtin',
					],
					self::instance_items_url( $account_id, $instance_id )
				),
				[
					'method'  => 'GET',
					'headers' => self::authorization_headers( $api_token ),
				],
				'Cloudflare AI Search managed pattern item list',
				self::REQUEST_TIMEOUT
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
				return new \WP_Error(
					'cloudflare_pattern_ai_search_owner_marker_repair_item_list_error',
					self::remote_failure_message( 'Flavor Agent could not inspect the Cloudflare AI Search managed pattern index before repairing ownership.', $response ),
					self::remote_failure_data( $response )
				);
			}

			$result = is_array( $response['data']['result'] ?? null ) ? $response['data']['result'] : [];
			foreach ( $result as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$item_id = trim( sanitize_text_field( (string) ( $item['id'] ?? '' ) ) );

				if ( '' !== $item_id ) {
					$item_ids[ $item_id ] = $item_id;
				}
			}

			$result_info = is_array( $response['data']['result_info'] ?? null ) ? $response['data']['result_info'] : [];
			$total_count = max( 0, (int) ( $result_info['total_count'] ?? count( $result ) ) );
			$page_size   = max( 1, (int) ( $result_info['per_page'] ?? self::LIST_ITEMS_PER_PAGE ) );
			$total_pages = max( 1, (int) ceil( $total_count / $page_size ) );
			++$page;
		} while ( $page <= $total_pages );

		return array_values( $item_ids );
	}

	/**
	 * @return array{pattern_name: string, candidate_type: string, source: string, synced_id: string, public_safe: bool}
	 */
	private static function normalize_owner_marker_metadata( array $metadata ): array {
		return [
			'pattern_name'   => sanitize_text_field( (string) ( $metadata['pattern_name'] ?? '' ) ),
			'candidate_type' => sanitize_text_field( (string) ( $metadata['candidate_type'] ?? '' ) ),
			'source'         => sanitize_text_field( (string) ( $metadata['source'] ?? '' ) ),
			'synced_id'      => sanitize_text_field( (string) ( $metadata['synced_id'] ?? '' ) ),
			'public_safe'    => rest_sanitize_boolean( $metadata['public_safe'] ?? false ),
		];
	}

	private static function is_create_conflict_error( \WP_Error $error ): bool {
		$data = $error->get_error_data();

		return is_array( $data ) && 409 === (int) ( $data['http_status'] ?? 0 );
	}

	private static function is_owner_marker_missing_error( \WP_Error $error ): bool {
		return 'cloudflare_pattern_ai_search_owner_marker_missing' === $error->get_error_code();
	}

	private static function should_retry_marker_provisioning( \WP_Error $error, array $state ): bool {
		if ( ! self::is_retryable_provisioning_error( $error ) ) {
			return false;
		}

		return (int) ( $state['marker_attempts'] ?? 0 ) < self::MARKER_PROVISION_MAX_ATTEMPTS;
	}

	/**
	 * A provisioning attempt is worth rescheduling when the managed instance
	 * exists (or was just created) but the owner marker has not finished indexing
	 * yet, or when a transient transport/upstream error interrupted the run. The
	 * "already contains items" conflict reuses the missing code but is terminal,
	 * so it is excluded via its item_count signal.
	 */
	private static function is_retryable_provisioning_error( \WP_Error $error ): bool {
		if ( self::is_owner_marker_missing_error( $error ) ) {
			$data = $error->get_error_data();

			return ! is_array( $data ) || empty( $data['item_count'] );
		}

		return self::is_transient_remote_error( $error );
	}

	/**
	 * Transient = retryable rate limit (429), any 5xx upstream response, or a
	 * transport-level timeout (normalize_transport_error maps these to status
	 * 504). Client errors (4xx other than 429) and ownership mismatches are
	 * terminal and must not loop the provisioning cron.
	 */
	private static function is_transient_remote_error( \WP_Error $error ): bool {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( ! empty( $data['retryable'] ) ) {
			return true;
		}

		if ( (int) ( $data['http_status'] ?? 0 ) >= 500 ) {
			return true;
		}

		return 504 === (int) ( $data['status'] ?? 0 );
	}

	private static function reschedule_marker_provisioning( array $state, string $signature, \WP_Error $error ): void {
		// owner_marker_repair is intentionally dropped: the marker upload has
		// already been attempted this cycle, so later runs only re-validate
		// (re-running repair would risk uploading a duplicate marker item).
		self::save_provisioning_state(
			[
				'status'          => 'provisioning',
				'signature'       => $signature,
				'requested_at'    => (string) ( $state['requested_at'] ?? '' ),
				'managed_status'  => 'awaiting_owner_marker',
				'marker_attempts' => (string) ( (int) ( $state['marker_attempts'] ?? 0 ) + 1 ),
				'last_error_code' => (string) $error->get_error_code(),
				'last_error'      => $error->get_error_message(),
			]
		);

		if ( ! wp_next_scheduled( self::PROVISION_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::MARKER_PROVISION_RETRY_DELAY, self::PROVISION_CRON_HOOK );
		}
	}

	/**
	 * @param array{status: int, data: mixed, body_bytes?: int} $response
	 */
	private static function remote_failure_message( string $fallback, array $response ): string {
		$status         = (int) ( $response['status'] ?? 0 );
		$remote_message = self::extract_remote_error_message( $response['data'] ?? null );
		$fallback       = rtrim( trim( $fallback ), ". \t\n\r\0\x0B" );

		if ( 0 < $status && '' !== $remote_message ) {
			return sprintf( '%1$s (HTTP %2$d): %3$s.', $fallback, $status, rtrim( $remote_message, ". \t\n\r\0\x0B" ) );
		}

		if ( 0 < $status ) {
			return sprintf( '%1$s (HTTP %2$d).', $fallback, $status );
		}

		return $fallback . '.';
	}

	/**
	 * @param array{status: int, data: mixed, body_bytes?: int} $response
	 * @return array{status: int, http_status: int, response_body_bytes: int, remote_error_message: string}
	 */
	private static function remote_failure_data( array $response ): array {
		return [
			'status'               => 502,
			'http_status'          => (int) ( $response['status'] ?? 0 ),
			'response_body_bytes'  => (int) ( $response['body_bytes'] ?? 0 ),
			'remote_error_message' => self::extract_remote_error_message( $response['data'] ?? null ),
		];
	}

	private static function extract_remote_error_message( mixed $data ): string {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$messages = [];

		if ( isset( $data['message'] ) && is_scalar( $data['message'] ) ) {
			$messages[] = (string) $data['message'];
		}

		if ( isset( $data['error'] ) && is_array( $data['error'] ) && isset( $data['error']['message'] ) && is_scalar( $data['error']['message'] ) ) {
			$messages[] = (string) $data['error']['message'];
		}

		if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			foreach ( $data['errors'] as $error ) {
				if ( is_array( $error ) && isset( $error['message'] ) && is_scalar( $error['message'] ) ) {
					$messages[] = (string) $error['message'];
				} elseif ( is_scalar( $error ) ) {
					$messages[] = (string) $error;
				}
			}
		}

		$messages = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( string $message ): string => trim( sanitize_text_field( $message ) ),
						$messages
					)
				)
			)
		);

		return implode( '; ', $messages );
	}

	/**
	 * @return array{instance_id: string, status: string}|\WP_Error
	 */
	private static function try_adopt_after_create_conflict( string $account_id, string $api_token, string $embedding_model ): array|\WP_Error {
		$instances = self::list_instances( $account_id, $api_token );

		if ( is_wp_error( $instances ) ) {
			return $instances;
		}

		foreach ( $instances as $instance ) {
			$compatible = self::assert_compatible_instance( $instance, $embedding_model );

			if ( is_wp_error( $compatible ) ) {
				return $compatible;
			}

			$marker = self::validate_owner_marker( $account_id, $api_token, self::managed_instance_id() );

			if ( is_wp_error( $marker ) ) {
				return $marker;
			}

			return [
				'instance_id' => self::managed_instance_id(),
				'status'      => 'adopted_after_conflict',
			];
		}

		return new \WP_Error(
			'cloudflare_pattern_ai_search_instance_create_conflict',
			'Cloudflare reported a managed pattern index conflict, but the instance could not be adopted safely.',
			[ 'status' => 409 ]
		);
	}

	private function __construct() {}
}
