<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\RequestLoggingBridge;
use FlavorAgent\Activity\Serializer;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\Retrieval\PatternRetrievalBackendFactory;
use FlavorAgent\Support\FlavorAgentRequestTag;
use FlavorAgent\Support\JsonSchemaObjectCoercion;
use FlavorAgent\Support\RankingContract;
use FlavorAgent\Support\StringArray;
use FlavorAgent\Support\ValidationReason;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RecommendationAbilityExecution {

	/**
	 * @param callable(array<string, mixed>): mixed $callback
	 */
	public static function execute(
		string $surface,
		string $ability_name,
		mixed $input,
		callable $callback,
		array|string $guideline_context = []
	): mixed {
		$request_input          = self::normalize_input( $surface, $input );
		$execution_input        = self::build_execution_input( $request_input );
		$resolve_signature_only = self::is_signature_only_input( $request_input );
		$client_request         = self::sanitize_client_request( $request_input['clientRequest'] ?? null );
		$request_token          = self::latest_request_token( $ability_name, $surface, $client_request );
		$request_tag            = self::build_request_tag( $surface, $ability_name, $request_input, $client_request );

		FlavorAgentRequestTag::start( $request_tag );
		try {
			$result = self::execute_with_system_instruction(
				$callback,
				$execution_input,
				self::resolve_guidelines_prompt_context( $guideline_context )
			);
		} finally {
			FlavorAgentRequestTag::finish();
		}

		if ( \is_wp_error( $result ) ) {
			if ( ! $resolve_signature_only ) {
				$result = self::append_request_meta_to_error( $result, $ability_name, $request_tag );
			}

			if ( ! $resolve_signature_only && self::should_persist_activity_request_diagnostic( $ability_name, $surface, $client_request ) ) {
				self::persist_request_diagnostic_failure_activity(
					self::resolve_activity_surface( $surface, $request_input ),
					$result,
					self::resolve_request_diagnostic_document( $request_input['document'] ?? null, $ability_name ),
					self::build_request_diagnostic_target( $surface, $request_input ),
					$execution_input
				);
			}

			return $result;
		}

		if ( $resolve_signature_only || ! \is_array( $result ) ) {
			return \is_array( $result )
				? JsonSchemaObjectCoercion::coerce( $result, Registration::recommendation_output_schema( $ability_name ) )
				: $result;
		}

		$payload = self::append_request_meta( $result, $ability_name, $request_tag );
		if ( self::should_persist_activity_request_diagnostic( $ability_name, $surface, $client_request ) ) {
			self::persist_request_diagnostic_activity(
				self::resolve_activity_surface( $surface, $request_input ),
				$payload,
				self::resolve_request_diagnostic_document( $request_input['document'] ?? null, $ability_name ),
				self::build_request_diagnostic_target( $surface, $request_input ),
				$execution_input
			);
		}

		// Coerce empty object-typed fields to JSON objects ({}), per the ability
		// output_schema, only after activity persistence has read the payload as
		// PHP arrays. The Gutenberg ajv-draft-04 client (used by the no-signal
		// bridge transport, the Abilities Explorer, and MCP clients) rejects an
		// empty array where the schema declares type:object.
		return JsonSchemaObjectCoercion::coerce(
			$payload,
			Registration::recommendation_output_schema( $ability_name )
		);
	}

	/**
	 * @param array<string, mixed> $request_input
	 * @param array{sessionId: string, requestToken: int|null, abortId: string, aborted: bool, scopeKey: string} $client_request
	 */
	private static function build_request_tag(
		string $surface,
		string $ability_name,
		array $request_input,
		array $client_request
	): FlavorAgentRequestTag {
		$document  = \is_array( $request_input['document'] ?? null )
			? self::sanitize_structured_value( $request_input['document'] )
			: [];
		$scope_key = \trim( (string) ( $document['scopeKey'] ?? $client_request['scopeKey'] ?? '' ) );

		return new FlavorAgentRequestTag(
			$surface,
			$ability_name,
			$scope_key,
			$document,
			self::generate_request_log_token()
		);
	}

	private static function generate_request_log_token(): string {
		if ( \function_exists( 'wp_generate_uuid4' ) ) {
			return (string) \wp_generate_uuid4();
		}

		try {
			$bytes = \random_bytes( 16 );
		} catch ( \Throwable ) {
			$bytes = \hash( 'sha256', \uniqid( '', true ), true );
		}

		$bytes[6] = \chr( ( \ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = \chr( ( \ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = \bin2hex( $bytes );

		return \sprintf(
			'%s-%s-%s-%s-%s',
			\substr( $hex, 0, 8 ),
			\substr( $hex, 8, 4 ),
			\substr( $hex, 12, 4 ),
			\substr( $hex, 16, 4 ),
			\substr( $hex, 20, 12 )
		);
	}

	/**
	 * @param array<string, mixed>|string $guideline_context
	 */
	private static function resolve_guidelines_prompt_context( array|string $guideline_context ): string {
		if ( \is_string( $guideline_context ) ) {
			return \trim( $guideline_context );
		}

		$categories = \is_array( $guideline_context['categories'] ?? null )
			? StringArray::sanitize( $guideline_context['categories'] )
			: [];
		$block_name = \sanitize_text_field( (string) ( $guideline_context['blockName'] ?? '' ) );

		return \trim( Guidelines::format_prompt_context( $block_name, $categories ) );
	}

	/**
	 * @param callable(array<string, mixed>): mixed $callback
	 * @param array<string, mixed> $execution_input
	 */
	private static function execute_with_system_instruction( callable $callback, array $execution_input, string $system_instruction ): mixed {
		$system_instruction = \trim( $system_instruction );

		if ( '' === $system_instruction ) {
			return $callback( $execution_input );
		}

		$filter = static function ( string $instruction ) use ( $system_instruction ): string {
			return '' === \trim( $instruction )
				? $system_instruction
				: $system_instruction . "\n\n" . $instruction;
		};

		\add_filter( 'flavor_agent_recommendation_system_instruction', $filter, 10, 1 );

		try {
			return $callback( $execution_input );
		} finally {
			\remove_filter( 'flavor_agent_recommendation_system_instruction', $filter, 10 );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_input( string $surface, mixed $input ): array {
		$input = self::sanitize_structured_value( $input );

		if ( [] === $input ) {
			return [];
		}

		if ( isset( $input['document'] ) ) {
			$input['document'] = self::sanitize_structured_value( $input['document'] );
		}

		if ( isset( $input['prompt'] ) && \is_string( $input['prompt'] ) ) {
			$input['prompt'] = \trim( $input['prompt'] );
		}

		if ( isset( $input['resolveSignatureOnly'] ) ) {
			$input['resolveSignatureOnly'] = \filter_var(
				$input['resolveSignatureOnly'],
				\FILTER_VALIDATE_BOOLEAN
			);
		}

		if ( isset( $input['clientRequest'] ) ) {
			$input['clientRequest'] = self::sanitize_client_request( $input['clientRequest'] );
		}

		if ( 'block' === $surface ) {
			if ( isset( $input['editorContext'] ) ) {
				$input['editorContext'] = self::sanitize_structured_value( $input['editorContext'] );
			}
			if ( isset( $input['selectedBlock'] ) ) {
				$input['selectedBlock'] = self::sanitize_structured_value( $input['selectedBlock'] );
			}
			if ( isset( $input['clientId'] ) ) {
				$input['clientId'] = \sanitize_text_field( (string) $input['clientId'] );
			}
		}

		if ( isset( $input['visiblePatternNames'] ) ) {
			$input['visiblePatternNames'] = StringArray::sanitize( $input['visiblePatternNames'] );
		}

		if ( isset( $input['scope'] ) ) {
			$input['scope'] = self::sanitize_structured_value( $input['scope'] );
		}

		if ( isset( $input['styleContext'] ) ) {
			$input['styleContext'] = self::sanitize_structured_value( $input['styleContext'] );
		}

		if ( isset( $input['editorContext'] ) && 'block' !== $surface ) {
			$input['editorContext'] = self::sanitize_structured_value( $input['editorContext'] );
		}

		foreach ( [ 'postContext', 'blockContext', 'insertionContext', 'editorSlots', 'editorStructure' ] as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$input[ $field ] = self::sanitize_structured_value( $input[ $field ] );
			}
		}

		return $input;
	}

	/**
	 * @param array<string, mixed> $request_input
	 * @return array<string, mixed>
	 */
	private static function build_execution_input( array $request_input ): array {
		$execution_input = $request_input;
		unset(
			$execution_input['document'],
			$execution_input['clientId'],
			$execution_input['blockClientId'],
			$execution_input['clientRequest']
		);

		return $execution_input;
	}

	/**
	 * @return array{sessionId: string, requestToken: int|null, abortId: string, aborted: bool, scopeKey: string}
	 */
	private static function sanitize_client_request( mixed $value ): array {
		$value = \is_array( $value ) || \is_object( $value )
			? self::sanitize_structured_value( $value )
			: [];

		$request_token = isset( $value['requestToken'] ) && \is_numeric( $value['requestToken'] )
			? \max( 0, (int) $value['requestToken'] )
			: null;

		return [
			'sessionId'    => \sanitize_text_field( (string) ( $value['sessionId'] ?? '' ) ),
			'requestToken' => $request_token,
			'abortId'      => \sanitize_text_field( (string) ( $value['abortId'] ?? '' ) ),
			'aborted'      => \filter_var( $value['aborted'] ?? false, \FILTER_VALIDATE_BOOLEAN ),
			'scopeKey'     => \sanitize_text_field( (string) ( $value['scopeKey'] ?? '' ) ),
		];
	}

	/**
	 * @param array{sessionId: string, requestToken: int|null, abortId: string, aborted: bool, scopeKey: string} $client_request
	 */
	private static function latest_request_token( string $ability_name, string $surface, array $client_request ): ?int {
		if ( null === $client_request['requestToken'] || '' === $client_request['sessionId'] ) {
			return null;
		}

		$key    = self::client_request_transient_key( $ability_name, $surface, $client_request );
		$latest = \get_transient( $key );
		$latest = \is_numeric( $latest ) ? (int) $latest : null;

		if ( null === $latest || $client_request['requestToken'] >= $latest ) {
			\set_transient( $key, $client_request['requestToken'], 600 );

			return $client_request['requestToken'];
		}

		return $latest;
	}

	/**
	 * @param array{sessionId: string, requestToken: int|null, abortId: string, aborted: bool, scopeKey: string} $client_request
	 */
	private static function should_persist_activity_request_diagnostic( string $ability_name, string $surface, array $client_request ): bool {
		if ( ! self::should_persist_request_diagnostic( $ability_name, $surface, $client_request ) ) {
			return false;
		}

		return RequestLoggingBridge::should_persist_request_diagnostic();
	}

	/**
	 * @param array{sessionId: string, requestToken: int|null, abortId: string, aborted: bool, scopeKey: string} $client_request
	 */
	private static function should_persist_request_diagnostic( string $ability_name, string $surface, array $client_request ): bool {
		if ( $client_request['aborted'] ) {
			return false;
		}

		if ( null === $client_request['requestToken'] || '' === $client_request['sessionId'] ) {
			return true;
		}

		$key    = self::client_request_transient_key( $ability_name, $surface, $client_request );
		$latest = \get_transient( $key );
		$latest = \is_numeric( $latest ) ? (int) $latest : null;

		return null === $latest || $client_request['requestToken'] >= $latest;
	}

	/**
	 * @param array{sessionId: string, requestToken: int|null, abortId: string, aborted: bool, scopeKey: string} $client_request
	 */
	private static function client_request_transient_key( string $ability_name, string $surface, array $client_request ): string {
		$scope_key = '' !== $client_request['scopeKey'] ? $client_request['scopeKey'] : $surface;

		return 'flavor_agent_req_' . \md5(
			$ability_name . '|'
				. $client_request['sessionId'] . '|'
				. $scope_key . '|'
				. $client_request['abortId']
		);
	}

	private static function is_signature_only_input( array $input ): bool {
		return \filter_var(
			$input['resolveSignatureOnly'] ?? false,
			\FILTER_VALIDATE_BOOLEAN
		);
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	private static function sanitize_structured_value( mixed $value ): array {
		$sanitized = Serializer::normalize_structured_value( $value );

		return \is_array( $sanitized ) ? $sanitized : [];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function append_request_meta( array $payload, string $ability_name, FlavorAgentRequestTag $request_tag ): array {
		$request_meta = self::merge_request_meta(
			\is_array( $payload['requestMeta'] ?? null ) ? $payload['requestMeta'] : []
		);

		$request_meta['ability']            = $ability_name;
		$request_meta['executionTransport'] = 'wp-abilities';
		$request_meta['route']              = 'wp-abilities:' . $ability_name;

		if ( 'flavor-agent/recommend-patterns' === $ability_name ) {
			$request_meta = self::append_pattern_request_meta( $request_meta );
		}

		$request_meta                        = self::append_request_log_meta( $request_meta, $request_tag );
		$request_meta['learningAttribution'] = self::build_learning_attribution(
			$request_tag->surface(),
			$payload,
			$request_meta,
			$request_tag
		);

		$payload['requestMeta'] = $request_meta;

		return $payload;
	}

	private static function append_request_meta_to_error( \WP_Error $error, string $ability_name, FlavorAgentRequestTag $request_tag ): \WP_Error {
		$code         = $error->get_error_code();
		$data         = $error->get_error_data( $code );
		$data         = \is_array( $data )
			? $data
			: ( null !== $data ? [ 'originalData' => $data ] : [] );
		$request_meta = self::merge_request_meta(
			\is_array( $data['requestMeta'] ?? null ) ? $data['requestMeta'] : []
		);

		$request_meta['ability']            = $ability_name;
		$request_meta['executionTransport'] = 'wp-abilities';
		$request_meta['route']              = 'wp-abilities:' . $ability_name;

		if ( 'flavor-agent/recommend-patterns' === $ability_name ) {
			$request_meta = self::append_pattern_request_meta( $request_meta );
		}

		$request_meta                        = self::append_request_log_meta( $request_meta, $request_tag );
		$request_meta['learningAttribution'] = self::build_learning_attribution(
			$request_tag->surface(),
			$data,
			$request_meta,
			$request_tag
		);

		$data['requestMeta'] = $request_meta;

		return new \WP_Error(
			$code,
			$error->get_error_message( $code ),
			$data
		);
	}

	/**
	 * Merge callback-supplied request metadata over the diagnostics captured by
	 * the runtime so a partial recommendation payload cannot discard provider
	 * identity, transport, usage, or response details.
	 *
	 * @param array<string, mixed> $request_meta
	 * @return array<string, mixed>
	 */
	private static function merge_request_meta( array $request_meta ): array {
		$runtime_meta = Provider::active_chat_request_meta();
		$merged       = \array_replace( $runtime_meta, $request_meta );

		foreach ( [ 'tokenUsage', 'transport', 'requestSummary', 'responseSummary', 'errorSummary' ] as $key ) {
			$runtime_value = \is_array( $runtime_meta[ $key ] ?? null ) ? $runtime_meta[ $key ] : [];
			$request_value = \is_array( $request_meta[ $key ] ?? null ) ? $request_meta[ $key ] : [];

			if ( [] === $runtime_value && [] === $request_value ) {
				continue;
			}

			$merged[ $key ] = self::merge_request_meta_array( $runtime_value, $request_value );
		}

		return $merged;
	}

	/**
	 * Merge associative diagnostic maps while replacing list values atomically.
	 *
	 * @param array<mixed> $runtime_value
	 * @param array<mixed> $request_value
	 * @return array<mixed>
	 */
	private static function merge_request_meta_array( array $runtime_value, array $request_value ): array {
		if ( [] === $request_value ) {
			return $runtime_value;
		}

		if ( [] === $runtime_value || \array_is_list( $runtime_value ) || \array_is_list( $request_value ) ) {
			return $request_value;
		}

		$merged = $runtime_value;

		foreach ( $request_value as $key => $value ) {
			$merged[ $key ] = \is_array( $value ) && \is_array( $merged[ $key ] ?? null )
				? self::merge_request_meta_array( $merged[ $key ], $value )
				: $value;
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $request_meta
	 * @return array<string, mixed>
	 */
	private static function append_request_log_meta( array $request_meta, FlavorAgentRequestTag $request_tag ): array {
		$request_token = $request_tag->request_token();

		$request_meta['requestToken'] = $request_token;
		$request_meta['requestLogId'] = RequestLoggingBridge::consume_log_id( $request_token ) ?? '';

		return $request_meta;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $request_meta
	 * @return array<string, string>
	 */
	private static function build_learning_attribution(
		string $surface,
		array $payload,
		array $request_meta,
		FlavorAgentRequestTag $request_tag
	): array {
		$docs_grounding = \is_array( $payload['docsGrounding'] ?? null ) ? $payload['docsGrounding'] : [];
		$provider       = self::first_attribution_value(
			$request_meta,
			[
				'provider',
				'providerLabel',
				'backendLabel',
			]
		);
		$model          = self::bounded_attribution_value( $request_meta['model'] ?? '' );

		$attribution = [
			'generationId'                => self::generate_generation_id( $surface, $request_tag ),
			'sourceRequestSignature'      => self::first_attribution_value(
				$payload,
				[
					'sourceRequestSignature',
					'resolvedContextSignature',
					'reviewContextSignature',
				]
			),
			'guidelineVersion'            => Guidelines::version_id(),
			'docsContentFingerprint'      => self::bounded_attribution_value(
				$docs_grounding['contentFingerprint']
					?? $payload['docsGroundingFingerprint']
					?? ''
			),
			'docsRuntimeFingerprint'      => self::bounded_attribution_value(
				$docs_grounding['runtimeFingerprint'] ?? ''
			),
			'provider'                    => $provider,
			'model'                       => $model,
			'rankingVersion'              => RankingContract::CONTEXTUAL_RANKING_VERSION,
			'validationVocabularyVersion' => ValidationReason::VERSION,
		];

		return \array_filter(
			$attribution,
			static fn ( string $value ): bool => '' !== $value
		);
	}

	private static function generate_generation_id( string $surface, FlavorAgentRequestTag $request_tag ): string {
		$surface = \sanitize_key( $surface );

		return 'recgen:' . ( '' !== $surface ? $surface : 'recommendation' ) . ':' . $request_tag->request_token();
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, string> $keys
	 */
	private static function first_attribution_value( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = self::bounded_attribution_value( $payload[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private static function bounded_attribution_value( mixed $value ): string {
		if ( ! \is_scalar( $value ) && null !== $value ) {
			return '';
		}

		return \substr( \sanitize_text_field( (string) $value ), 0, 191 );
	}

	/**
	 * @param array<string, mixed> $request_meta
	 * @return array<string, mixed>
	 */
	private static function append_pattern_request_meta( array $request_meta ): array {
		$backend = PatternRetrievalBackendFactory::selected_backend();

		$request_meta['pattern_backend'] = $backend;
		$request_meta['patternBackend']  = [
			'id'    => $backend,
			'label' => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH === $backend
				? 'Cloudflare AI Search'
				: 'Qdrant',
		];

		if ( Config::PATTERN_BACKEND_QDRANT === $backend ) {
			$request_meta['embedding_provider'] = Provider::active_embedding_request_meta();
		} else {
			$request_meta['embedding_provider'] = [
				'provider'      => 'cloudflare_ai_search',
				'providerLabel' => 'Cloudflare AI Search managed embeddings',
				'backendLabel'  => 'Cloudflare AI Search',
				'model'         => 'managed by Cloudflare AI Search',
				'configured'    => true,
				'owner'         => 'cloudflare_ai_search',
				'ownerLabel'    => 'Settings > Flavor Agent',
				'pathLabel'     => 'Cloudflare AI Search private pattern index',
			];
		}

		return $request_meta;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function sanitize_activity_document( mixed $value ): ?array {
		if ( ! \is_array( $value ) && ! \is_object( $value ) ) {
			return null;
		}

		$document  = self::sanitize_structured_value( $value );
		$scope_key = \trim( (string) ( $document['scopeKey'] ?? '' ) );

		if ( '' === $scope_key ) {
			return null;
		}

		return [
			'scopeKey'   => $scope_key,
			'postType'   => \trim( (string) ( $document['postType'] ?? '' ) ),
			'entityId'   => \trim( (string) ( $document['entityId'] ?? '' ) ),
			'entityKind' => \trim( (string) ( $document['entityKind'] ?? '' ) ),
			'entityName' => \trim( (string) ( $document['entityName'] ?? '' ) ),
			'stylesheet' => \trim( (string) ( $document['stylesheet'] ?? '' ) ),
		];
	}

	/**
	 * Resolve the activity document for request-diagnostic persistence,
	 * synthesizing an external-origin scope when the caller supplied no usable
	 * scopeKey. Editor surfaces always send document.scopeKey; scope-less callers
	 * (Abilities Explorer click-runs, external MCP agents) would otherwise write
	 * no audit row at all, silently evading the server-side attribution the
	 * recommend abilities promise to that exact audience.
	 *
	 * @return array<string, mixed>
	 */
	private static function resolve_request_diagnostic_document( mixed $raw_document, string $ability_name ): array {
		$document = self::sanitize_activity_document( $raw_document );

		if ( \is_array( $document ) ) {
			return $document;
		}

		$fields = self::sanitize_structured_value( $raw_document );

		return [
			'scopeKey'   => self::external_fallback_scope_key( $ability_name ),
			'postType'   => \trim( (string) ( $fields['postType'] ?? '' ) ),
			'entityId'   => \trim( (string) ( $fields['entityId'] ?? '' ) ),
			'entityKind' => \trim( (string) ( $fields['entityKind'] ?? '' ) ),
			'entityName' => \trim( (string) ( $fields['entityName'] ?? '' ) ),
			'stylesheet' => \trim( (string) ( $fields['stylesheet'] ?? '' ) ),
		];
	}

	/**
	 * Build the synthesized scope key for a scope-less external recommend call,
	 * e.g. external:recommend-content. Grouping by the short ability name keeps
	 * the (indexed) document_scope_key attributable and parses cleanly through
	 * the admin projection's first-colon scope-key split.
	 */
	private static function external_fallback_scope_key( string $ability_name ): string {
		$position = \strrpos( $ability_name, '/' );
		$short    = false !== $position ? \substr( $ability_name, $position + 1 ) : $ability_name;
		$short    = \sanitize_key( $short );

		return 'external:' . ( '' !== $short ? $short : 'recommendation' );
	}

	/**
	 * Persist an activity entry, surfacing an otherwise-swallowed persistence
	 * failure rather than dropping the audit row silently.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function persist_activity_entry( string $surface, array $entry ): void {
		$created = ActivityRepository::create( $entry );

		if ( \is_wp_error( $created ) ) {
			self::log_request_diagnostic_persist_failure( $surface, $created );
		}
	}

	private static function log_request_diagnostic_persist_failure( string $surface, \WP_Error $error ): void {
		if ( ! \function_exists( 'error_log' ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surfaces an otherwise-swallowed request-diagnostic persistence failure.
		\error_log(
			\sprintf(
				'flavor_agent_request_diagnostic_persist_failed: surface=%s code=%s message=%s',
				$surface,
				$error->get_error_code(),
				$error->get_error_message()
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function resolve_activity_surface( string $surface, array $input ): string {
		if ( 'style' !== $surface ) {
			return $surface;
		}

		$scope = \is_array( $input['scope'] ?? null ) ? $input['scope'] : [];
		$name  = \sanitize_key( (string) ( $scope['surface'] ?? '' ) );

		if ( \in_array( $name, [ 'global-styles', 'style-book' ], true ) ) {
			return $name;
		}

		$document  = self::sanitize_activity_document( $input['document'] ?? null );
		$scope_key = \is_array( $document ) ? \trim( (string) ( $document['scopeKey'] ?? '' ) ) : '';

		return \str_starts_with( $scope_key, 'style_book:' ) ? 'style-book' : 'global-styles';
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function build_request_diagnostic_target( string $surface, array $input ): array {
		return match ( $surface ) {
			'block' => self::build_block_target( $input ),
			'content' => [
				'mode' => isset( $input['mode'] ) ? (string) $input['mode'] : 'draft',
			],
			'pattern' => [
				'postType' => isset( $input['postType'] ) ? (string) $input['postType'] : '',
			],
			'navigation' => [
				'clientId'  => \sanitize_text_field( (string) ( $input['blockClientId'] ?? '' ) ),
				'blockName' => 'core/navigation',
				'menuId'    => \max( 0, (int) ( $input['menuId'] ?? 0 ) ),
			],
			'template' => [
				'templateRef'  => \sanitize_text_field( (string) ( $input['templateRef'] ?? '' ) ),
				'templateType' => \sanitize_key( (string) ( $input['templateType'] ?? '' ) ),
			],
			'template-part' => [
				'templatePartRef' => \sanitize_text_field( (string) ( $input['templatePartRef'] ?? '' ) ),
			],
			'post-blocks' => [
				'postId' => \max( 0, (int) ( $input['postId'] ?? 0 ) ),
			],
			'style' => self::build_style_target( $input ),
			default => [],
		};
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function build_block_target( array $input ): array {
		$editor_context = \is_array( $input['editorContext'] ?? null ) ? $input['editorContext'] : [];
		$block          = \is_array( $editor_context['block'] ?? null ) ? $editor_context['block'] : [];
		$block_path     = self::sanitize_block_path( $block['blockPath'] ?? ( $input['blockPath'] ?? [] ) );

		$target = [
			'clientId'  => \sanitize_text_field( (string) ( $input['clientId'] ?? '' ) ),
			'blockName' => \sanitize_text_field( (string) ( $block['name'] ?? ( $input['selectedBlock']['blockName'] ?? '' ) ) ),
		];

		if ( [] !== $block_path ) {
			$target['blockPath'] = $block_path;
		}

		return $target;
	}

	/**
	 * @return array<int, int>
	 */
	private static function sanitize_block_path( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}

		$path = [];

		foreach ( $value as $segment ) {
			if ( ! \is_numeric( $segment ) ) {
				continue;
			}

			$segment = (int) $segment;

			if ( $segment < 0 ) {
				continue;
			}

			$path[] = $segment;
		}

		return $path;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function build_style_target( array $input ): array {
		$scope         = \is_array( $input['scope'] ?? null ) ? $input['scope'] : [];
		$style_context = \is_array( $input['styleContext'] ?? null ) ? $input['styleContext'] : [];
		$style_target  = \is_array( $style_context['styleBookTarget'] ?? null ) ? $style_context['styleBookTarget'] : [];

		return [
			'scopeKey'       => \sanitize_text_field( (string) ( $scope['scopeKey'] ?? '' ) ),
			'globalStylesId' => \sanitize_text_field( (string) ( $scope['globalStylesId'] ?? '' ) ),
			'blockName'      => \sanitize_text_field( (string) ( $scope['blockName'] ?? ( $style_target['blockName'] ?? '' ) ) ),
			'blockTitle'     => \sanitize_text_field( (string) ( $scope['blockTitle'] ?? ( $style_target['blockTitle'] ?? '' ) ) ),
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed>|null $document
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $request_context
	 */
	private static function persist_request_diagnostic_activity(
		string $surface,
		array $payload,
		?array $document,
		array $target,
		array $request_context
	): void {
		if ( ! \is_array( $document ) || '' === \trim( (string) ( $document['scopeKey'] ?? '' ) ) ) {
			return;
		}

		$reference      = self::build_request_diagnostic_reference( $surface, $target, $document );
		$after          = [
			'prompt'           => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
			'resultCount'      => self::get_request_result_count( $surface, $payload ),
			'explanation'      => \trim( (string) ( $payload['explanation'] ?? $payload['summary'] ?? '' ) ),
			'diagnosticDetail' => self::build_request_diagnostic_detail( $surface, $payload ),
			'requestContext'   => $request_context,
		];
		$docs_grounding = \is_array( $payload['docsGrounding'] ?? null ) ? $payload['docsGrounding'] : [];

		if ( [] !== $docs_grounding ) {
			$after['docsGrounding'] = $docs_grounding;
		}

		$pipeline_trace = self::build_request_diagnostic_pipeline_trace( $surface, $payload );
		if ( [] !== $pipeline_trace ) {
			$after['pipelineTrace'] = $pipeline_trace;
		}

		$pipeline_drop_reasons = self::build_request_diagnostic_pipeline_drop_reasons( $surface, $payload );
		if ( [] !== $pipeline_drop_reasons ) {
			$after['pipelineDropReasons'] = $pipeline_drop_reasons;
		}

		$model_request = self::sanitize_request_diagnostic_model_request( $payload );
		if ( [] !== $model_request ) {
			$after['modelRequest'] = $model_request;
		}

		$validation_aggregate = self::aggregate_validation_reasons( $surface, $payload );
		if ( [] !== $validation_aggregate ) {
			$after['validationReasons'] = $validation_aggregate;
		}

		self::persist_activity_entry(
			$surface,
			[
				'type'            => 'request_diagnostic',
				'surface'         => $surface,
				'target'          => \array_merge( $target, [ 'requestRef' => $reference ] ),
				'suggestion'      => self::build_request_diagnostic_title( $surface ),
				'before'          => [
					'prompt' => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
				],
				'after'           => $after,
				'request'         => [
					'prompt'              => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'reference'           => $reference,
					'ai'                  => \is_array( $payload['requestMeta'] ?? null ) ? $payload['requestMeta'] : [],
					'docsGrounding'       => $docs_grounding,
					'guidelineVersion'    => Guidelines::version_id(),
					'learningAttribution' => \is_array( $payload['requestMeta']['learningAttribution'] ?? null )
						? $payload['requestMeta']['learningAttribution']
						: [],
				],
				'document'        => $document,
				'executionResult' => 'review',
				'undo'            => [
					'canUndo'   => false,
					'status'    => 'review',
					'error'     => null,
					'updatedAt' => \gmdate( 'c' ),
				],
				'timestamp'       => \gmdate( 'c' ),
			]
		);
	}

	/**
	 * Audit-only: count validationReasons across an executable surface's suggestions.
	 * Identified by requestRef on the request_diagnostic row; never the loop join.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{reasonCounts: array<string, int>, validationVocabularyVersion: string}|array{}
	 */
	private static function aggregate_validation_reasons( string $surface, array $payload ): array {
		if ( ! \in_array( $surface, [ 'block', 'style', 'template', 'template-part', 'global-styles', 'style-book', 'post-blocks' ], true ) ) {
			return [];
		}

		$counts = [];
		foreach ( [ 'settings', 'styles', 'block', 'suggestions' ] as $list_key ) {
			$list = \is_array( $payload[ $list_key ] ?? null ) ? $payload[ $list_key ] : [];
			foreach ( $list as $suggestion ) {
				if ( ! \is_array( $suggestion ) ) {
					continue;
				}

				$reasons = \is_array( $suggestion['validationReasons'] ?? null ) ? $suggestion['validationReasons'] : [];
				foreach ( ValidationReason::normalize( $reasons ) as $reason ) {
					$counts[ $reason['code'] ] = ( $counts[ $reason['code'] ] ?? 0 ) + 1;
				}
			}
		}

		if ( [] === $counts ) {
			return [];
		}

		return [
			'reasonCounts'                => $counts,
			'validationVocabularyVersion' => ValidationReason::VERSION,
		];
	}

	/**
	 * Test seam for {@see self::aggregate_validation_reasons()}.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{reasonCounts: array<string, int>, validationVocabularyVersion: string}|array{}
	 */
	public static function aggregate_validation_reasons_for_tests( string $surface, array $payload ): array {
		return self::aggregate_validation_reasons( $surface, $payload );
	}

	/**
	 * @param array<string, mixed>|null $document
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $request_context
	 */
	private static function persist_request_diagnostic_failure_activity(
		string $surface,
		\WP_Error $error,
		?array $document,
		array $target,
		array $request_context
	): void {
		if ( ! \is_array( $document ) || '' === \trim( (string) ( $document['scopeKey'] ?? '' ) ) ) {
			return;
		}

		$reference  = self::build_request_diagnostic_reference( $surface, $target, $document );
		$message    = \trim( (string) $error->get_error_message() );
		$error_data = $error->get_error_data();
		$error_data = \is_array( $error_data ) ? $error_data : [];

		self::persist_activity_entry(
			$surface,
			[
				'id'              => '',
				'type'            => 'request_diagnostic',
				'surface'         => $surface,
				'target'          => \array_merge( $target, [ 'requestRef' => $reference ] ),
				'suggestion'      => self::build_failed_request_diagnostic_title( $surface ),
				'before'          => [
					'prompt' => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
				],
				'after'           => [
					'prompt'         => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'resultCount'    => 0,
					'requestContext' => $request_context,
				],
				'request'         => [
					'prompt'              => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'reference'           => $reference,
					'ai'                  => \is_array( $error_data['requestMeta'] ?? null ) ? $error_data['requestMeta'] : [],
					'guidelineVersion'    => Guidelines::version_id(),
					'learningAttribution' => \is_array( $error_data['requestMeta']['learningAttribution'] ?? null )
						? $error_data['requestMeta']['learningAttribution']
						: [],
					'error'               => [
						'code'    => \trim( (string) $error->get_error_code() ),
						'message' => $message,
						'data'    => $error_data,
					],
				],
				'document'        => $document,
				'executionResult' => 'review',
				'undo'            => [
					'canUndo'   => false,
					'status'    => 'failed',
					'error'     => $message,
					'updatedAt' => \gmdate( 'c' ),
				],
				'timestamp'       => \gmdate( 'c' ),
			]
		);
	}

	private static function build_request_diagnostic_title( string $surface ): string {
		return match ( $surface ) {
			'content' => \__( 'Content recommendation request', 'flavor-agent' ),
			'navigation' => \__( 'Navigation recommendation request', 'flavor-agent' ),
			'pattern' => \__( 'Pattern recommendation request', 'flavor-agent' ),
			'block' => \__( 'Block recommendation request', 'flavor-agent' ),
			'template' => \__( 'Template recommendation request', 'flavor-agent' ),
			'template-part' => \__( 'Template-part recommendation request', 'flavor-agent' ),
			'post-blocks' => \__( 'Post content structure recommendation request', 'flavor-agent' ),
			'global-styles' => \__( 'Global Styles recommendation request', 'flavor-agent' ),
			'style-book' => \__( 'Style Book recommendation request', 'flavor-agent' ),
			default => \__( 'AI request diagnostic', 'flavor-agent' ),
		};
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function build_request_diagnostic_detail( string $surface, array $payload ): string {
		if ( 'content' === $surface ) {
			$title = \trim( (string) ( $payload['title'] ?? '' ) );

			if ( '' !== $title ) {
				return $title;
			}

			return \trim( (string) ( $payload['summary'] ?? '' ) );
		}

		if ( 'navigation' === $surface ) {
			$suggestions = \is_array( $payload['suggestions'] ?? null ) ? $payload['suggestions'] : [];

			return \trim( (string) ( $suggestions[0]['label'] ?? '' ) );
		}

		if ( 'pattern' === $surface ) {
			$recommendations = \is_array( $payload['recommendations'] ?? null ) ? $payload['recommendations'] : [];

			return \trim( (string) ( $recommendations[0]['title'] ?? $recommendations[0]['name'] ?? '' ) );
		}

		if ( 'block' === $surface ) {
			return \trim( (string) ( $payload['explanation'] ?? '' ) );
		}

		if ( \in_array( $surface, [ 'template', 'template-part', 'global-styles', 'style-book', 'post-blocks' ], true ) ) {
			$suggestions = \is_array( $payload['suggestions'] ?? null ) ? $payload['suggestions'] : [];

			return \trim( (string) ( $suggestions[0]['label'] ?? $payload['explanation'] ?? '' ) );
		}

		return \trim( (string) ( $payload['explanation'] ?? $payload['summary'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, int>
	 */
	private static function build_request_diagnostic_pipeline_trace( string $surface, array $payload ): array {
		if ( 'pattern' !== $surface ) {
			return [];
		}

		$diagnostics = \is_array( $payload['diagnostics'] ?? null ) ? $payload['diagnostics'] : [];
		$trace       = \is_array( $diagnostics['pipelineTrace'] ?? null ) ? $diagnostics['pipelineTrace'] : [];

		if ( [] === $trace ) {
			return [];
		}

		return [
			'backendRetrieved'        => \max( 0, (int) ( $trace['backendRetrieved'] ?? 0 ) ),
			'visibleScopeDropped'     => \max( 0, (int) ( $trace['visibleScopeDropped'] ?? 0 ) ),
			'rehydrationDropped'      => \max( 0, (int) ( $trace['rehydrationDropped'] ?? 0 ) ),
			'candidatePool'           => \max( 0, (int) ( $trace['candidatePool'] ?? 0 ) ),
			'diversityDropped'        => \max( 0, (int) ( $trace['diversityDropped'] ?? 0 ) ),
			'llmReturned'             => \max( 0, (int) ( $trace['llmReturned'] ?? 0 ) ),
			'llmNameMismatchDropped'  => \max( 0, (int) ( $trace['llmNameMismatchDropped'] ?? 0 ) ),
			'llmMalformedDropped'     => \max( 0, (int) ( $trace['llmMalformedDropped'] ?? 0 ) ),
			'belowThresholdDropped'   => \max( 0, (int) ( $trace['belowThresholdDropped'] ?? 0 ) ),
			'duplicateRowsCollapsed'  => \max( 0, (int) ( $trace['duplicateRowsCollapsed'] ?? 0 ) ),
			'returnedRecommendations' => \max( 0, (int) ( $trace['returnedRecommendations'] ?? 0 ) ),
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, int>
	 */
	private static function build_request_diagnostic_pipeline_drop_reasons( string $surface, array $payload ): array {
		if ( 'pattern' !== $surface ) {
			return [];
		}

		$diagnostics = \is_array( $payload['diagnostics'] ?? null ) ? $payload['diagnostics'] : [];
		$reasons     = \is_array( $diagnostics['dropReasons'] ?? null ) ? $diagnostics['dropReasons'] : [];
		$allowed     = \array_fill_keys(
			[
				'visible_scope',
				'synced_rehydration_failed',
				'rehydration_failed',
				'llm_name_mismatch',
				'llm_malformed_recommendation',
				'below_threshold',
			],
			true
		);
		$clean       = [];

		foreach ( $reasons as $reason => $count ) {
			$reason = \sanitize_key( (string) $reason );
			if ( ! isset( $allowed[ $reason ] ) ) {
				continue;
			}

			$count = \max( 0, (int) $count );
			if ( $count > 0 ) {
				$clean[ $reason ] = $count;
			}
		}

		return $clean;
	}

	private static function sanitize_request_diagnostic_model_request( array $payload ): array {
		$diagnostics   = \is_array( $payload['diagnostics'] ?? null ) ? $payload['diagnostics'] : [];
		$model_request = \is_array( $diagnostics['modelRequest'] ?? null ) ? $diagnostics['modelRequest'] : [];

		if ( false !== ( $model_request['attempted'] ?? null ) ) {
			return [];
		}

		$reason = isset( $model_request['reason'] ) && \is_string( $model_request['reason'] )
			? \sanitize_key( $model_request['reason'] )
			: '';

		if ( ! \in_array( $reason, [ 'no_rankable_candidates', 'missing_visible_patterns' ], true ) ) {
			return [];
		}

		return [
			'attempted' => false,
			'reason'    => $reason,
		];
	}

	private static function build_failed_request_diagnostic_title( string $surface ): string {
		return match ( $surface ) {
			'content' => \__( 'Content request failed', 'flavor-agent' ),
			'navigation' => \__( 'Navigation request failed', 'flavor-agent' ),
			'pattern' => \__( 'Pattern request failed', 'flavor-agent' ),
			'block' => \__( 'Block request failed', 'flavor-agent' ),
			'template' => \__( 'Template request failed', 'flavor-agent' ),
			'template-part' => \__( 'Template-part request failed', 'flavor-agent' ),
			'post-blocks' => \__( 'Post content structure request failed', 'flavor-agent' ),
			'global-styles' => \__( 'Global Styles request failed', 'flavor-agent' ),
			'style-book' => \__( 'Style Book request failed', 'flavor-agent' ),
			default => \__( 'AI request failed', 'flavor-agent' ),
		};
	}

	/**
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $document
	 */
	private static function build_request_diagnostic_reference( string $surface, array $target, array $document ): string {
		$scope_key = \trim( (string) ( $document['scopeKey'] ?? '' ) );

		return match ( $surface ) {
			'block' => \sprintf(
				'block:%s:%s',
				$scope_key,
				\trim( (string) ( $target['clientId'] ?? 'unknown' ) )
			),
			'template' => \sprintf(
				'template:%s:%s',
				$scope_key,
				\trim( (string) ( $target['templateRef'] ?? 'unknown' ) )
			),
			'template-part' => \sprintf(
				'template-part:%s:%s',
				$scope_key,
				\trim( (string) ( $target['templatePartRef'] ?? 'unknown' ) )
			),
			'post-blocks' => \sprintf(
				'post-blocks:%s:%s',
				$scope_key,
				\trim( (string) ( $target['postId'] ?? 'unknown' ) )
			),
			'global-styles' => \sprintf(
				'global-styles:%s:%s',
				$scope_key,
				\trim( (string) ( $target['globalStylesId'] ?? 'unknown' ) )
			),
			'style-book' => \sprintf(
				'style-book:%s:%s:%s',
				$scope_key,
				\trim( (string) ( $target['globalStylesId'] ?? 'unknown' ) ),
				\trim( (string) ( $target['blockName'] ?? 'unknown' ) )
			),
			'navigation' => \sprintf(
				'navigation:%s:%s',
				$scope_key,
				\trim( (string) ( $target['clientId'] ?? 'unknown' ) )
			),
			'pattern' => \sprintf( 'pattern:%s', $scope_key ),
			'content' => \sprintf( 'content:%s', $scope_key ),
			default => \sprintf( '%s:%s', $surface, $scope_key ),
		};
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function get_request_result_count( string $surface, array $payload ): int {
		return match ( $surface ) {
			'navigation' => \is_array( $payload['suggestions'] ?? null ) ? \count( $payload['suggestions'] ) : 0,
			'pattern' => \is_array( $payload['recommendations'] ?? null ) ? \count( $payload['recommendations'] ) : 0,
			'content' => \trim( (string) ( $payload['content'] ?? '' ) ) !== '' ? 1 : 0,
			'block' => \count( \is_array( $payload['settings'] ?? null ) ? $payload['settings'] : [] )
				+ \count( \is_array( $payload['styles'] ?? null ) ? $payload['styles'] : [] )
				+ \count( \is_array( $payload['block'] ?? null ) ? $payload['block'] : [] ),
			'template', 'template-part', 'global-styles', 'style-book', 'post-blocks' => \is_array( $payload['suggestions'] ?? null ) ? \count( $payload['suggestions'] ) : 0,
			default => 0,
		};
	}
}
