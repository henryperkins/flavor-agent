<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\Serializer;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\Retrieval\PatternRetrievalBackendFactory;
use FlavorAgent\Support\StringArray;

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
		$result                 = self::execute_with_system_instruction(
			$callback,
			$execution_input,
			self::resolve_guidelines_prompt_context( $guideline_context )
		);

		if ( \is_wp_error( $result ) ) {
			if ( ! $resolve_signature_only && self::should_persist_request_diagnostic( $ability_name, $surface, $client_request ) ) {
				$result = self::append_request_meta_to_error( $result, $ability_name );
				self::persist_request_diagnostic_failure_activity(
					self::resolve_activity_surface( $surface, $request_input ),
					$result,
					self::sanitize_activity_document( $request_input['document'] ?? null ),
					self::build_request_diagnostic_target( $surface, $request_input ),
					$execution_input
				);
			}

			return $result;
		}

		if ( $resolve_signature_only || ! \is_array( $result ) ) {
			return $result;
		}

		$payload = self::append_request_meta( $result, $ability_name );
		if ( self::should_persist_request_diagnostic( $ability_name, $surface, $client_request ) ) {
			self::persist_request_diagnostic_activity(
				self::resolve_activity_surface( $surface, $request_input ),
				$payload,
				self::sanitize_activity_document( $request_input['document'] ?? null ),
				self::build_request_diagnostic_target( $surface, $request_input ),
				$execution_input
			);
		}

		return $payload;
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
	private static function append_request_meta( array $payload, string $ability_name ): array {
		$request_meta = \is_array( $payload['requestMeta'] ?? null )
			? $payload['requestMeta']
			: Provider::active_chat_request_meta();

		$request_meta['ability']            = $ability_name;
		$request_meta['executionTransport'] = 'wp-abilities';
		$request_meta['route']              = 'wp-abilities:' . $ability_name;

		if ( 'flavor-agent/recommend-patterns' === $ability_name ) {
			$request_meta = self::append_pattern_request_meta( $request_meta );
		}

		$payload['requestMeta'] = $request_meta;

		return $payload;
	}

	private static function append_request_meta_to_error( \WP_Error $error, string $ability_name ): \WP_Error {
		$code         = $error->get_error_code();
		$data         = $error->get_error_data( $code );
		$data         = \is_array( $data )
			? $data
			: ( null !== $data ? [ 'originalData' => $data ] : [] );
		$request_meta = \is_array( $data['requestMeta'] ?? null )
			? $data['requestMeta']
			: Provider::active_chat_request_meta();

		$request_meta['ability']            = $ability_name;
		$request_meta['executionTransport'] = 'wp-abilities';
		$request_meta['route']              = 'wp-abilities:' . $ability_name;

		if ( 'flavor-agent/recommend-patterns' === $ability_name ) {
			$request_meta = self::append_pattern_request_meta( $request_meta );
		}

		$data['requestMeta'] = $request_meta;

		return new \WP_Error(
			$code,
			$error->get_error_message( $code ),
			$data
		);
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

		return [
			'clientId'  => \sanitize_text_field( (string) ( $input['clientId'] ?? '' ) ),
			'blockName' => \sanitize_text_field( (string) ( $block['name'] ?? ( $input['selectedBlock']['blockName'] ?? '' ) ) ),
		];
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

		$reference = self::build_request_diagnostic_reference( $surface, $target, $document );

		ActivityRepository::create(
			[
				'type'            => 'request_diagnostic',
				'surface'         => $surface,
				'target'          => \array_merge( $target, [ 'requestRef' => $reference ] ),
				'suggestion'      => self::build_request_diagnostic_title( $surface, $payload ),
				'before'          => [
					'prompt' => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
				],
				'after'           => [
					'prompt'         => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'resultCount'    => self::get_request_result_count( $surface, $payload ),
					'explanation'    => \trim( (string) ( $payload['explanation'] ?? $payload['summary'] ?? '' ) ),
					'requestContext' => $request_context,
				],
				'request'         => [
					'prompt'    => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'reference' => $reference,
					'ai'        => \is_array( $payload['requestMeta'] ?? null ) ? $payload['requestMeta'] : [],
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

		ActivityRepository::create(
			[
				'id'              => '',
				'type'            => 'request_diagnostic',
				'surface'         => $surface,
				'target'          => \array_merge( $target, [ 'requestRef' => $reference ] ),
				'suggestion'      => self::build_failed_request_diagnostic_title( $surface, $message ),
				'before'          => [
					'prompt' => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
				],
				'after'           => [
					'prompt'         => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'resultCount'    => 0,
					'requestContext' => $request_context,
				],
				'request'         => [
					'prompt'    => \trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'reference' => $reference,
					'ai'        => \is_array( $error_data['requestMeta'] ?? null ) ? $error_data['requestMeta'] : [],
					'error'     => [
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

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function build_request_diagnostic_title( string $surface, array $payload ): string {
		if ( 'content' === $surface ) {
			$title = \trim( (string) ( $payload['title'] ?? '' ) );

			if ( '' !== $title ) {
				return $title;
			}

			$summary = \trim( (string) ( $payload['summary'] ?? '' ) );

			return '' !== $summary ? $summary : 'Content recommendation request';
		}

		if ( 'navigation' === $surface ) {
			$suggestions = \is_array( $payload['suggestions'] ?? null ) ? $payload['suggestions'] : [];
			$label       = \trim( (string) ( $suggestions[0]['label'] ?? '' ) );

			return '' !== $label ? $label : 'Navigation recommendation request';
		}

		if ( 'pattern' === $surface ) {
			$recommendations = \is_array( $payload['recommendations'] ?? null ) ? $payload['recommendations'] : [];
			$label           = \trim( (string) ( $recommendations[0]['title'] ?? $recommendations[0]['name'] ?? '' ) );

			return '' !== $label ? $label : 'Pattern recommendation request';
		}

		if ( 'block' === $surface ) {
			$explanation = \trim( (string) ( $payload['explanation'] ?? '' ) );

			return '' !== $explanation ? $explanation : 'Block recommendation request';
		}

		if ( \in_array( $surface, [ 'template', 'template-part', 'global-styles', 'style-book' ], true ) ) {
			$suggestions = \is_array( $payload['suggestions'] ?? null ) ? $payload['suggestions'] : [];
			$label       = \trim( (string) ( $suggestions[0]['label'] ?? $payload['explanation'] ?? '' ) );

			if ( '' !== $label ) {
				return $label;
			}

			return match ( $surface ) {
				'template' => 'Template recommendation request',
				'template-part' => 'Template-part recommendation request',
				'global-styles' => 'Global Styles recommendation request',
				'style-book' => 'Style Book recommendation request',
				default => 'AI request diagnostic',
			};
		}

		return 'AI request diagnostic';
	}

	private static function build_failed_request_diagnostic_title( string $surface, string $message ): string {
		$label = match ( $surface ) {
			'content' => 'Content request failed',
			'navigation' => 'Navigation request failed',
			'pattern' => 'Pattern request failed',
			'block' => 'Block request failed',
			'template' => 'Template request failed',
			'template-part' => 'Template-part request failed',
			'global-styles' => 'Global Styles request failed',
			'style-book' => 'Style Book request failed',
			default => 'AI request failed',
		};

		return '' !== $message ? $label . ': ' . $message : $label;
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
			'template', 'template-part', 'global-styles', 'style-book' => \is_array( $payload['suggestions'] ?? null ) ? \count( $payload['suggestions'] ) : 0,
			default => 0,
		};
	}
}
