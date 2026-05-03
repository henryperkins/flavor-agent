<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Activity\Permissions as ActivityPermissions;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\Serializer;
use FlavorAgent\Abilities\BlockAbilities;
use FlavorAgent\Abilities\ContentAbilities;
use FlavorAgent\Abilities\NavigationAbilities;
use FlavorAgent\Abilities\PatternAbilities;
use FlavorAgent\Abilities\StyleAbilities;
use FlavorAgent\Abilities\TemplateAbilities;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Support\RequestTrace;
use FlavorAgent\Support\StringArray;

final class Agent_Controller {

	private const NAMESPACE = 'flavor-agent/v1';

	private const REQUEST_META_ROUTES = [
		'recommend-block'         => [
			'ability'             => 'flavor-agent/recommend-block',
			'route'               => 'POST /flavor-agent/v1/recommend-block',
			'includeProviderMeta' => true,
		],
		'recommend-content'       => [
			'ability'             => 'flavor-agent/recommend-content',
			'route'               => 'POST /flavor-agent/v1/recommend-content',
			'includeProviderMeta' => true,
		],
		'recommend-patterns'      => [
			'ability'             => 'flavor-agent/recommend-patterns',
			'route'               => 'POST /flavor-agent/v1/recommend-patterns',
			'includeProviderMeta' => true,
		],
		'recommend-navigation'    => [
			'ability'             => 'flavor-agent/recommend-navigation',
			'route'               => 'POST /flavor-agent/v1/recommend-navigation',
			'includeProviderMeta' => true,
		],
		'recommend-style'         => [
			'ability'             => 'flavor-agent/recommend-style',
			'route'               => 'POST /flavor-agent/v1/recommend-style',
			'includeProviderMeta' => true,
		],
		'recommend-template'      => [
			'ability'             => 'flavor-agent/recommend-template',
			'route'               => 'POST /flavor-agent/v1/recommend-template',
			'includeProviderMeta' => true,
		],
		'recommend-template-part' => [
			'ability'             => 'flavor-agent/recommend-template-part',
			'route'               => 'POST /flavor-agent/v1/recommend-template-part',
			'includeProviderMeta' => true,
		],
		'sync-patterns'           => [
			'route'               => 'POST /flavor-agent/v1/sync-patterns',
			'includeProviderMeta' => false,
		],
	];

	private const ACTIVITY_DAY_OPERATORS = [
		'on',
		'before',
		'after',
		'between',
		'inThePast',
		'over',
	];

	private const ACTIVITY_DAY_RELATIVE_UNITS = [
		'hours',
		'days',
		'weeks',
		'months',
		'years',
	];

	public static function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/recommend-block',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_block' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'edit_posts' ),
				'args'                => [
					'editorContext'        => [
						'required'          => true,
						'type'              => 'object',
						'description'       => 'Block context snapshot from the editor.',
						'validate_callback' => static fn( mixed $value ): bool => \is_array( $value ) || \is_object( $value ),
					],
					'prompt'               => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'clientId'             => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'document'             => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'resolveSignatureOnly' => [
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/recommend-content',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_content' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'edit_posts' ),
				'args'                => [
					'mode'         => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'prompt'       => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => [ __CLASS__, 'sanitize_block_markup' ],
					],
					'voiceProfile' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => [ __CLASS__, 'sanitize_block_markup' ],
					],
					'postContext'  => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'document'     => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/sync-patterns',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_sync_patterns' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'manage_options' ),
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/recommend-patterns',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_patterns' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'edit_posts' ),
				'args'                => [
					'postType'            => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'templateType'        => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'blockContext'        => [
						'required' => false,
						'type'     => 'object',
					],
					'insertionContext'    => [
						'required' => false,
						'type'     => 'object',
					],
					'prompt'              => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'visiblePatternNames' => [
						'required'          => false,
						'type'              => 'array',
						'validate_callback' => [ __CLASS__, 'validate_string_array' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_string_array' ],
					],
					'document'            => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/recommend-navigation',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_navigation' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'edit_theme_options' ),
				'args'                => [
					'menuId'               => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'navigationMarkup'     => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => [ __CLASS__, 'sanitize_block_markup' ],
						'validate_callback' => static fn( mixed $value ): bool => \is_string( $value ),
					],
					'prompt'               => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'resolveSignatureOnly' => [
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
					'editorContext'        => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'document'             => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/recommend-style',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_style' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'edit_theme_options' ),
				'args'                => [
					'scope'                => [
						'required'          => true,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'styleContext'         => [
						'required'          => true,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'prompt'               => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'document'             => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'resolveSignatureOnly' => [
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/recommend-template',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_template' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'edit_theme_options' ),
				'args'                => [
					'templateRef'          => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( mixed $value ): bool => \is_string( $value ) && $value !== '',
					],
					'templateType'         => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'prompt'               => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'visiblePatternNames'  => [
						'required'          => false,
						'type'              => 'array',
						'validate_callback' => [ __CLASS__, 'validate_string_array' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_string_array' ],
					],
					'editorSlots'          => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'editorStructure'      => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'document'             => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'resolveSignatureOnly' => [
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/recommend-template-part',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_template_part' ],
				'permission_callback' => static fn(): bool => \current_user_can( 'edit_theme_options' ),
				'args'                => [
					'templatePartRef'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( mixed $value ): bool => \is_string( $value ) && $value !== '',
					],
					'prompt'               => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'visiblePatternNames'  => [
						'required'          => false,
						'type'              => 'array',
						'validate_callback' => [ __CLASS__, 'validate_string_array' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_string_array' ],
					],
					'editorStructure'      => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'document'             => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'resolveSignatureOnly' => [
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/activity',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'handle_get_activity' ],
					'permission_callback' => [ ActivityPermissions::class, 'can_access_activity_request' ],
					'args'                => [
						'scopeKey'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'global'                     => [
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => static fn( mixed $value ): bool => \in_array( $value, [ true, 1, '1', 'true', 'yes' ], true ),
						],
						'surface'                    => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityType'                 => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityRef'                  => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'userId'                     => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'postType'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'provider'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'providerPath'               => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'configurationOwner'         => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'credentialSource'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'selectedProvider'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityId'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'blockPath'                  => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'operationType'              => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'status'                     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'page'                       => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						],
						'perPage'                    => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						],
						'search'                     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sortField'                  => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sortDirection'              => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'surfaceOperator'            => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'statusOperator'             => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'postTypeOperator'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'providerOperator'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'providerPathOperator'       => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'configurationOwnerOperator' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'credentialSourceOperator'   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'selectedProviderOperator'   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityIdOperator'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'blockPathOperator'          => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'userIdOperator'             => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'operationTypeOperator'      => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'day'                        => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayEnd'                     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayOperator'                => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayRelativeValue'           => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'dayRelativeUnit'            => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'limit'                      => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						],
						'groupBySurface'             => [
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'surfaceLimit'               => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_SURFACE_LIMIT,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'handle_create_activity' ],
					'permission_callback' => [ ActivityPermissions::class, 'can_access_activity_request' ],
					'args'                => [
						'entry' => [
							'required'          => true,
							'type'              => 'object',
							'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
							'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
						],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/activity/(?P<id>[A-Za-z0-9._:-]+)/undo',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_update_activity_undo' ],
				'permission_callback' => [ ActivityPermissions::class, 'can_access_activity_request' ],
				'args'                => [
					'id'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'undone',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'error'  => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);
	}

	public static function validate_string_array( mixed $value ): bool {
		if ( ! \is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $entry ) {
			if ( ! \is_string( $entry ) ) {
				return false;
			}
		}

		return true;
	}

	public static function validate_structured_value( mixed $value ): bool {
		return \is_array( $value ) || \is_object( $value );
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	public static function sanitize_string_array( mixed $value ): array {
		return StringArray::sanitize( $value );
	}

	public static function sanitize_structured_value( mixed $value ): array {
		$sanitized = Serializer::normalize_structured_value( $value );

		return \is_array( $sanitized ) ? $sanitized : [];
	}

	public static function sanitize_block_markup( mixed $value ): string {
		return \is_string( $value ) ? \trim( $value ) : '';
	}

	public static function handle_recommend_block( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client_id              = $request->get_param( 'clientId' );
		$resolve_signature_only = self::is_signature_only_request( $request );
		$trace_consumed         = RequestTrace::is_consumed();

		if ( $trace_consumed ) {
			RequestTrace::start(
				'recommend-block',
				self::build_recommend_block_trace_context( $request, $resolve_signature_only ),
				'rest.recommend_block.start'
			);
		}
		$input = [
			'editorContext'        => $request->get_param( 'editorContext' ),
			'prompt'               => $request->get_param( 'prompt' ),
			'resolveSignatureOnly' => $resolve_signature_only,
		];

		try {
			$result = BlockAbilities::recommend_block( $input );
		} catch ( \Throwable $throwable ) {
			$throwable_context = self::build_trace_throwable_context( $throwable );
			if ( $trace_consumed ) {
				RequestTrace::event(
					'rest.recommend_block.throwable',
					$throwable_context
				);
				RequestTrace::finish(
					'rest.recommend_block.finish',
					array_merge(
						[ 'outcome' => 'throwable' ],
						$throwable_context
					)
				);
			}

			throw $throwable;
		}

		if ( \is_wp_error( $result ) ) {
			$result = self::append_request_meta_to_error_for_route( $result, 'recommend-block' );
			if ( $trace_consumed ) {
				RequestTrace::event(
					'rest.recommend_block.error',
					self::build_trace_error_context( $result )
				);
			}

			if ( ! $resolve_signature_only ) {
				self::persist_request_diagnostic_failure_activity(
					'block',
					$result,
					self::sanitize_activity_document( $request->get_param( 'document' ) ),
					self::build_block_request_diagnostic_target( $request ),
					$input
				);
			}

			if ( $trace_consumed ) {
				RequestTrace::finish(
					'rest.recommend_block.finish',
					array_merge(
						[ 'outcome' => 'error' ],
						self::build_trace_error_context( $result )
					)
				);
			}

			return $result;
		}

		$payload = $resolve_signature_only
			? $result
			: self::append_request_meta_for_route( $result, 'recommend-block' );
		if ( $trace_consumed ) {
			RequestTrace::event(
				'rest.recommend_block.payload_ready',
				self::build_recommendation_payload_trace_context( $payload )
			);
		}

		if ( ! $resolve_signature_only ) {
			self::persist_request_diagnostic_activity(
				'block',
				$payload,
				self::sanitize_activity_document( $request->get_param( 'document' ) ),
				self::build_block_request_diagnostic_target( $request ),
				$input
			);
		}

		if ( $trace_consumed ) {
			RequestTrace::finish(
				'rest.recommend_block.finish',
				array_merge(
					[ 'outcome' => 'success' ],
					self::build_recommendation_payload_trace_context( $payload )
				)
			);
		}

		return new \WP_REST_Response(
			[
				'payload'  => $payload,
				'clientId' => $client_id,
			],
			200
		);
	}

	/**
	 * Handle POST /recommend-content with a thin ability adapter.
	 */
	public static function handle_recommend_content( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = [];

		$mode = $request->get_param( 'mode' );
		if ( \is_string( $mode ) && $mode !== '' ) {
			$input['mode'] = $mode;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( \is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = self::sanitize_block_markup( $prompt );
		}

		$voice_profile = $request->get_param( 'voiceProfile' );
		if ( \is_string( $voice_profile ) && $voice_profile !== '' ) {
			$input['voiceProfile'] = self::sanitize_block_markup( $voice_profile );
		}

		$post_context = $request->get_param( 'postContext' );
		if ( \is_array( $post_context ) || \is_object( $post_context ) ) {
			$input['postContext'] = self::sanitize_structured_value( $post_context );
		}

		$result = ContentAbilities::recommend_content( $input );

		if ( \is_wp_error( $result ) ) {
			$result = self::append_request_meta_to_error_for_route( $result, 'recommend-content' );
			self::persist_request_diagnostic_failure_activity(
				'content',
				$result,
				self::sanitize_activity_document( $request->get_param( 'document' ) ),
				[
					'mode' => isset( $input['mode'] ) ? (string) $input['mode'] : 'draft',
				],
				array_merge(
					$input,
					[
						'prompt' => isset( $input['prompt'] ) ? (string) $input['prompt'] : '',
					]
				)
			);

			return $result;
		}

		$payload = self::append_request_meta_for_route( $result, 'recommend-content' );
		self::persist_request_diagnostic_activity(
			'content',
			$payload,
			self::sanitize_activity_document( $request->get_param( 'document' ) ),
			[
				'mode' => isset( $input['mode'] ) ? (string) $input['mode'] : 'draft',
			],
			array_merge(
				$input,
				[
					'prompt' => isset( $input['prompt'] ) ? (string) $input['prompt'] : '',
				]
			)
		);

		return new \WP_REST_Response( $payload, 200 );
	}

	public static function handle_sync_patterns( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = PatternIndex::sync();

		if ( \is_wp_error( $result ) ) {
			return self::append_request_meta_to_error_for_route( $result, 'sync-patterns' );
		}

		$result['runtimeState'] = PatternIndex::get_runtime_state();

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'sync-patterns' ),
			200
		);
	}

	public static function handle_recommend_patterns( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = [
			'postType' => $request->get_param( 'postType' ),
		];

		$template_type = $request->get_param( 'templateType' );
		if ( \is_string( $template_type ) && $template_type !== '' ) {
			$input['templateType'] = $template_type;
		}

		$block_context = $request->get_param( 'blockContext' );
		if ( \is_array( $block_context ) && ! empty( $block_context ) ) {
			$input['blockContext'] = $block_context;
		}

		$insertion_context = $request->get_param( 'insertionContext' );
		if ( \is_array( $insertion_context ) && ! empty( $insertion_context ) ) {
			$input['insertionContext'] = $insertion_context;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( \is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		if ( $request->has_param( 'visiblePatternNames' ) ) {
			$input['visiblePatternNames'] = self::sanitize_string_array(
				$request->get_param( 'visiblePatternNames' )
			);
		}

		$result = PatternAbilities::recommend_patterns( $input );

		if ( \is_wp_error( $result ) ) {
			$result = self::append_request_meta_to_error_for_route( $result, 'recommend-patterns' );
			self::persist_request_diagnostic_failure_activity(
				'pattern',
				$result,
				self::sanitize_activity_document( $request->get_param( 'document' ) ),
				[
					'postType' => isset( $input['postType'] ) ? (string) $input['postType'] : '',
				],
				$input
			);

			return $result;
		}

		$payload = self::append_request_meta_for_route( $result, 'recommend-patterns' );
		self::persist_request_diagnostic_activity(
			'pattern',
			$payload,
			self::sanitize_activity_document( $request->get_param( 'document' ) ),
			[
				'postType' => isset( $input['postType'] ) ? (string) $input['postType'] : '',
			],
			$input
		);

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Handle POST /recommend-navigation with a thin ability adapter.
	 */
	public static function handle_recommend_navigation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$resolve_signature_only = self::is_signature_only_request( $request );
		$input                  = [
			'resolveSignatureOnly' => $resolve_signature_only,
		];
		$menu_id                = \max( 0, (int) $request->get_param( 'menuId' ) );

		if ( $menu_id > 0 ) {
			$input['menuId'] = $menu_id;
		}

		$navigation_markup = self::sanitize_block_markup(
			$request->get_param( 'navigationMarkup' )
		);
		if ( $navigation_markup !== '' ) {
			$input['navigationMarkup'] = $navigation_markup;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( \is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		$editor_context = $request->get_param( 'editorContext' );
		if ( \is_array( $editor_context ) || \is_object( $editor_context ) ) {
			$input['editorContext'] = self::sanitize_structured_value( $editor_context );
		}

		$result = NavigationAbilities::recommend_navigation( $input );

		if ( \is_wp_error( $result ) ) {
			$result = self::append_request_meta_to_error_for_route( $result, 'recommend-navigation' );

			if ( ! $resolve_signature_only ) {
				self::persist_request_diagnostic_failure_activity(
					'navigation',
					$result,
					self::sanitize_activity_document( $request->get_param( 'document' ) ),
					[
						'clientId'  => sanitize_text_field( (string) $request->get_param( 'blockClientId' ) ),
						'blockName' => 'core/navigation',
						'menuId'    => $menu_id > 0 ? $menu_id : 0,
					],
					$input
				);
			}

			return $result;
		}

		if ( $resolve_signature_only ) {
			return new \WP_REST_Response( $result, 200 );
		}

		$payload = self::append_request_meta_for_route( $result, 'recommend-navigation' );
		self::persist_request_diagnostic_activity(
			'navigation',
			$payload,
			self::sanitize_activity_document( $request->get_param( 'document' ) ),
			[
				'clientId'  => sanitize_text_field( (string) $request->get_param( 'blockClientId' ) ),
				'blockName' => 'core/navigation',
				'menuId'    => $menu_id > 0 ? $menu_id : 0,
			],
			$input
		);

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Signature-only requests resolve the current server apply-freshness signature.
	 */
	private static function is_signature_only_request( \WP_REST_Request $request ): bool {
		return filter_var(
			$request->get_param( 'resolveSignatureOnly' ),
			FILTER_VALIDATE_BOOLEAN
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_recommend_block_trace_context( \WP_REST_Request $request, bool $resolve_signature_only ): array {
		$editor_context = $request->get_param( 'editorContext' );
		$editor_context = \is_array( $editor_context ) || \is_object( $editor_context )
			? self::sanitize_structured_value( $editor_context )
			: [];
		$block          = \is_array( $editor_context['block'] ?? null ) ? $editor_context['block'] : [];
		$document       = self::sanitize_activity_document( $request->get_param( 'document' ) );
		$prompt         = $request->get_param( 'prompt' );

		return [
			'clientId'             => sanitize_text_field( (string) $request->get_param( 'clientId' ) ),
			'route'                => 'POST /flavor-agent/v1/recommend-block',
			'resolveSignatureOnly' => $resolve_signature_only,
			'promptChars'          => \is_string( $prompt ) ? strlen( $prompt ) : 0,
			'blockName'            => sanitize_text_field( (string) ( $block['name'] ?? '' ) ),
			'editingMode'          => sanitize_key( (string) ( $block['editingMode'] ?? '' ) ),
			'hasDocumentScope'     => \is_array( $document ),
			'documentScopeKey'     => \is_array( $document ) ? (string) ( $document['scopeKey'] ?? '' ) : '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_recommendation_payload_trace_context( array $payload ): array {
		$request_meta = \is_array( $payload['requestMeta'] ?? null ) ? $payload['requestMeta'] : [];

		return [
			'counts'               => [
				'settings' => \is_array( $payload['settings'] ?? null ) ? \count( $payload['settings'] ) : 0,
				'styles'   => \is_array( $payload['styles'] ?? null ) ? \count( $payload['styles'] ) : 0,
				'block'    => \is_array( $payload['block'] ?? null ) ? \count( $payload['block'] ) : 0,
			],
			'hasExecutionContract' => \is_array( $payload['executionContract'] ?? null ),
			'hasRequestMeta'       => [] !== $request_meta,
			'requestMeta'          => self::summarize_trace_request_meta( $request_meta ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function summarize_trace_request_meta( array $request_meta ): array {
		if ( [] === $request_meta ) {
			return [];
		}

		return array_filter(
			[
				'provider'        => \is_string( $request_meta['provider'] ?? null ) ? $request_meta['provider'] : '',
				'model'           => \is_string( $request_meta['model'] ?? null ) ? $request_meta['model'] : '',
				'transport'       => \is_array( $request_meta['transport'] ?? null ) ? $request_meta['transport'] : [],
				'requestSummary'  => \is_array( $request_meta['requestSummary'] ?? null ) ? $request_meta['requestSummary'] : [],
				'responseSummary' => \is_array( $request_meta['responseSummary'] ?? null ) ? $request_meta['responseSummary'] : [],
				'errorSummary'    => \is_array( $request_meta['errorSummary'] ?? null ) ? $request_meta['errorSummary'] : [],
			],
			static fn ( mixed $value ): bool => [] !== $value && '' !== $value
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_trace_error_context( \WP_Error $error ): array {
		$data = $error->get_error_data();
		$data = \is_array( $data ) ? $data : [];

		return [
			'error'       => [
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'status'  => \is_numeric( $data['status'] ?? null ) ? (int) $data['status'] : null,
			],
			'requestMeta' => self::summarize_trace_request_meta(
				\is_array( $data['requestMeta'] ?? null ) ? $data['requestMeta'] : []
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_trace_throwable_context( \Throwable $throwable ): array {
		return [
			'throwable' => [
				'class'   => get_class( $throwable ),
				'message' => $throwable->getMessage(),
				'file'    => $throwable->getFile(),
				'line'    => $throwable->getLine(),
			],
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function append_request_meta_for_route( array $payload, string $route_key ): array {
		$route_definition = self::REQUEST_META_ROUTES[ $route_key ] ?? null;

		if ( ! \is_array( $route_definition ) ) {
			return $payload;
		}

		$request_meta = ! empty( $route_definition['includeProviderMeta'] )
			? Provider::active_chat_request_meta()
			: [];

		if ( ! empty( $route_definition['ability'] ) && \is_string( $route_definition['ability'] ) ) {
			$request_meta['ability'] = $route_definition['ability'];
		}

		$request_meta['route']  = $route_definition['route'];
		$payload['requestMeta'] = $request_meta;

		return $payload;
	}

	private static function append_request_meta_to_error_for_route( \WP_Error $error, string $route_key ): \WP_Error {
		$route_definition = self::REQUEST_META_ROUTES[ $route_key ] ?? null;

		if ( ! \is_array( $route_definition ) ) {
			return $error;
		}

		$code         = $error->get_error_code();
		$data         = $error->get_error_data( $code );
		$data         = \is_array( $data )
			? $data
			: ( null !== $data ? [ 'originalData' => $data ] : [] );
		$request_meta = \is_array( $data['requestMeta'] ?? null )
			? $data['requestMeta']
			: [];

		if ( [] === $request_meta && ! empty( $route_definition['includeProviderMeta'] ) ) {
			$request_meta = Provider::active_chat_request_meta();
		}

		if ( ! empty( $route_definition['ability'] ) && \is_string( $route_definition['ability'] ) ) {
			$request_meta['ability'] = $route_definition['ability'];
		}

		$request_meta['route'] = $route_definition['route'];
		$data['requestMeta']   = $request_meta;

		return new \WP_Error(
			$code,
			$error->get_error_message( $code ),
			$data
		);
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>|null
	 */
	private static function sanitize_activity_document( mixed $value ): ?array {
		if ( ! \is_array( $value ) && ! \is_object( $value ) ) {
			return null;
		}

		$document  = self::sanitize_structured_value( $value );
		$scope_key = trim( (string) ( $document['scopeKey'] ?? '' ) );

		if ( '' === $scope_key ) {
			return null;
		}

		return [
			'scopeKey'   => $scope_key,
			'postType'   => trim( (string) ( $document['postType'] ?? '' ) ),
			'entityId'   => trim( (string) ( $document['entityId'] ?? '' ) ),
			'entityKind' => trim( (string) ( $document['entityKind'] ?? '' ) ),
			'entityName' => trim( (string) ( $document['entityName'] ?? '' ) ),
			'stylesheet' => trim( (string) ( $document['stylesheet'] ?? '' ) ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_block_request_diagnostic_target( \WP_REST_Request $request ): array {
		$editor_context = $request->get_param( 'editorContext' );
		$editor_context = \is_array( $editor_context ) || \is_object( $editor_context )
			? self::sanitize_structured_value( $editor_context )
			: [];
		$block          = \is_array( $editor_context['block'] ?? null ) ? $editor_context['block'] : [];

		return [
			'clientId'  => sanitize_text_field( (string) $request->get_param( 'clientId' ) ),
			'blockName' => sanitize_text_field( (string) ( $block['name'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function build_template_request_diagnostic_target( array $input ): array {
		return [
			'templateRef'  => sanitize_text_field( (string) ( $input['templateRef'] ?? '' ) ),
			'templateType' => sanitize_key( (string) ( $input['templateType'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function build_template_part_request_diagnostic_target( array $input ): array {
		return [
			'templatePartRef' => sanitize_text_field( (string) ( $input['templatePartRef'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed>|null $document
	 */
	private static function resolve_style_request_diagnostic_surface( array $input, ?array $document ): string {
		$scope   = \is_array( $input['scope'] ?? null ) ? $input['scope'] : [];
		$surface = sanitize_key( (string) ( $scope['surface'] ?? '' ) );

		if ( \in_array( $surface, [ 'global-styles', 'style-book' ], true ) ) {
			return $surface;
		}

		$scope_key = \is_array( $document ) ? trim( (string) ( $document['scopeKey'] ?? '' ) ) : '';

		return str_starts_with( $scope_key, 'style_book:' ) ? 'style-book' : 'global-styles';
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function build_style_request_diagnostic_target( array $input ): array {
		$scope         = \is_array( $input['scope'] ?? null ) ? $input['scope'] : [];
		$style_context = \is_array( $input['styleContext'] ?? null ) ? $input['styleContext'] : [];
		$style_target  = \is_array( $style_context['styleBookTarget'] ?? null ) ? $style_context['styleBookTarget'] : [];

		return [
			'scopeKey'       => sanitize_text_field( (string) ( $scope['scopeKey'] ?? '' ) ),
			'globalStylesId' => sanitize_text_field( (string) ( $scope['globalStylesId'] ?? '' ) ),
			'blockName'      => sanitize_text_field( (string) ( $scope['blockName'] ?? ( $style_target['blockName'] ?? '' ) ) ),
			'blockTitle'     => sanitize_text_field( (string) ( $scope['blockTitle'] ?? ( $style_target['blockTitle'] ?? '' ) ) ),
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
		if ( ! \is_array( $document ) || '' === trim( (string) ( $document['scopeKey'] ?? '' ) ) ) {
			return;
		}

		$reference = self::build_request_diagnostic_reference( $surface, $target, $document );

		ActivityRepository::create(
			[
				'type'            => 'request_diagnostic',
				'surface'         => $surface,
				'target'          => array_merge( $target, [ 'requestRef' => $reference ] ),
				'suggestion'      => self::build_request_diagnostic_title( $surface, $payload ),
				'before'          => [
					'prompt' => trim( (string) ( $request_context['prompt'] ?? '' ) ),
				],
				'after'           => [
					'prompt'         => trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'resultCount'    => self::get_request_result_count( $surface, $payload ),
					'explanation'    => trim( (string) ( $payload['explanation'] ?? $payload['summary'] ?? '' ) ),
					'requestContext' => $request_context,
				],
				'request'         => [
					'prompt'    => trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'reference' => $reference,
					'ai'        => \is_array( $payload['requestMeta'] ?? null ) ? $payload['requestMeta'] : [],
				],
				'document'        => $document,
				'executionResult' => 'review',
				'undo'            => [
					'canUndo'   => false,
					'status'    => 'review',
					'error'     => null,
					'updatedAt' => gmdate( 'c' ),
				],
				'timestamp'       => gmdate( 'c' ),
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
		if ( ! \is_array( $document ) || '' === trim( (string) ( $document['scopeKey'] ?? '' ) ) ) {
			return;
		}

		$reference  = self::build_request_diagnostic_reference( $surface, $target, $document );
		$message    = trim( (string) $error->get_error_message() );
		$error_data = $error->get_error_data();
		$error_data = \is_array( $error_data ) ? $error_data : [];

		ActivityRepository::create(
			[
				'id'              => '',
				'type'            => 'request_diagnostic',
				'surface'         => $surface,
				'target'          => array_merge( $target, [ 'requestRef' => $reference ] ),
				'suggestion'      => self::build_failed_request_diagnostic_title( $surface, $message ),
				'before'          => [
					'prompt' => trim( (string) ( $request_context['prompt'] ?? '' ) ),
				],
				'after'           => [
					'prompt'         => trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'resultCount'    => 0,
					'requestContext' => $request_context,
				],
				'request'         => [
					'prompt'    => trim( (string) ( $request_context['prompt'] ?? '' ) ),
					'reference' => $reference,
					'ai'        => \is_array( $error_data['requestMeta'] ?? null ) ? $error_data['requestMeta'] : [],
					'error'     => [
						'code'    => trim( (string) $error->get_error_code() ),
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
					'updatedAt' => gmdate( 'c' ),
				],
				'timestamp'       => gmdate( 'c' ),
			]
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function build_request_diagnostic_title( string $surface, array $payload ): string {
		if ( 'content' === $surface ) {
			$title = trim( (string) ( $payload['title'] ?? '' ) );

			if ( '' !== $title ) {
				return $title;
			}

			$summary = trim( (string) ( $payload['summary'] ?? '' ) );

			return '' !== $summary ? $summary : 'Content recommendation request';
		}

		if ( 'navigation' === $surface ) {
			$suggestions = \is_array( $payload['suggestions'] ?? null ) ? $payload['suggestions'] : [];
			$label       = trim( (string) ( $suggestions[0]['label'] ?? '' ) );

			return '' !== $label ? $label : 'Navigation recommendation request';
		}

		if ( 'pattern' === $surface ) {
			$recommendations = \is_array( $payload['recommendations'] ?? null ) ? $payload['recommendations'] : [];
			$label           = trim( (string) ( $recommendations[0]['title'] ?? $recommendations[0]['name'] ?? '' ) );

			return '' !== $label ? $label : 'Pattern recommendation request';
		}

		if ( 'block' === $surface ) {
			$explanation = trim( (string) ( $payload['explanation'] ?? '' ) );

			return '' !== $explanation ? $explanation : 'Block recommendation request';
		}

		if ( \in_array( $surface, [ 'template', 'template-part', 'global-styles', 'style-book' ], true ) ) {
			$suggestions = \is_array( $payload['suggestions'] ?? null ) ? $payload['suggestions'] : [];
			$label       = trim( (string) ( $suggestions[0]['label'] ?? $payload['explanation'] ?? '' ) );

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
		$scope_key = trim( (string) ( $document['scopeKey'] ?? '' ) );

		return match ( $surface ) {
			'block' => sprintf(
				'block:%s:%s',
				$scope_key,
				trim( (string) ( $target['clientId'] ?? 'unknown' ) )
			),
			'template' => sprintf(
				'template:%s:%s',
				$scope_key,
				trim( (string) ( $target['templateRef'] ?? 'unknown' ) )
			),
			'template-part' => sprintf(
				'template-part:%s:%s',
				$scope_key,
				trim( (string) ( $target['templatePartRef'] ?? 'unknown' ) )
			),
			'global-styles' => sprintf(
				'global-styles:%s:%s',
				$scope_key,
				trim( (string) ( $target['globalStylesId'] ?? 'unknown' ) )
			),
			'style-book' => sprintf(
				'style-book:%s:%s:%s',
				$scope_key,
				trim( (string) ( $target['globalStylesId'] ?? 'unknown' ) ),
				trim( (string) ( $target['blockName'] ?? 'unknown' ) )
			),
			'navigation' => sprintf(
				'navigation:%s:%s',
				$scope_key,
				trim( (string) ( $target['clientId'] ?? 'unknown' ) )
			),
			'pattern' => sprintf( 'pattern:%s', $scope_key ),
			'content' => sprintf( 'content:%s', $scope_key ),
			default => sprintf( '%s:%s', $surface, $scope_key ),
		};
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function get_request_result_count( string $surface, array $payload ): int {
		return match ( $surface ) {
			'navigation' => \is_array( $payload['suggestions'] ?? null ) ? count( $payload['suggestions'] ) : 0,
			'pattern' => \is_array( $payload['recommendations'] ?? null ) ? count( $payload['recommendations'] ) : 0,
			'content' => trim( (string) ( $payload['content'] ?? '' ) ) !== '' ? 1 : 0,
			'block' => count( \is_array( $payload['settings'] ?? null ) ? $payload['settings'] : [] )
				+ count( \is_array( $payload['styles'] ?? null ) ? $payload['styles'] : [] )
				+ count( \is_array( $payload['block'] ?? null ) ? $payload['block'] : [] ),
			'template', 'template-part', 'global-styles', 'style-book' => \is_array( $payload['suggestions'] ?? null ) ? count( $payload['suggestions'] ) : 0,
			default => 0,
		};
	}

	/**
	 * Handle POST /recommend-template with a thin ability adapter.
	 */
	public static function handle_recommend_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$resolve_signature_only = self::is_signature_only_request( $request );
		$input                  = [
			'templateRef' => $request->get_param( 'templateRef' ),
		];

		$template_type = $request->get_param( 'templateType' );
		if ( \is_string( $template_type ) && $template_type !== '' ) {
			$input['templateType'] = $template_type;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( \is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		if ( $request->has_param( 'visiblePatternNames' ) ) {
			$input['visiblePatternNames'] = self::sanitize_string_array(
				$request->get_param( 'visiblePatternNames' )
			);
		}

		$editor_slots = $request->get_param( 'editorSlots' );
		if ( \is_array( $editor_slots ) || \is_object( $editor_slots ) ) {
			$input['editorSlots'] = self::sanitize_structured_value( $editor_slots );
		}

		$editor_structure = $request->get_param( 'editorStructure' );
		if ( \is_array( $editor_structure ) || \is_object( $editor_structure ) ) {
			$input['editorStructure'] = self::sanitize_structured_value( $editor_structure );
		}

		$input['resolveSignatureOnly'] = $resolve_signature_only;

		$result = TemplateAbilities::recommend_template( $input );

		if ( \is_wp_error( $result ) ) {
			$result = self::append_request_meta_to_error_for_route( $result, 'recommend-template' );

			if ( ! $resolve_signature_only ) {
				self::persist_request_diagnostic_failure_activity(
					'template',
					$result,
					self::sanitize_activity_document( $request->get_param( 'document' ) ),
					self::build_template_request_diagnostic_target( $input ),
					$input
				);
			}

			return $result;
		}

		$payload = $resolve_signature_only
			? $result
			: self::append_request_meta_for_route( $result, 'recommend-template' );

		if ( ! $resolve_signature_only ) {
			self::persist_request_diagnostic_activity(
				'template',
				$payload,
				self::sanitize_activity_document( $request->get_param( 'document' ) ),
				self::build_template_request_diagnostic_target( $input ),
				$input
			);
		}

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Handle POST /recommend-style with a thin ability adapter.
	 */
	public static function handle_recommend_style( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$resolve_signature_only = self::is_signature_only_request( $request );
		$input                  = [
			'scope'                => self::sanitize_structured_value(
				$request->get_param( 'scope' )
			),
			'styleContext'         => self::sanitize_structured_value(
				$request->get_param( 'styleContext' )
			),
			'resolveSignatureOnly' => $resolve_signature_only,
		];

		$prompt = $request->get_param( 'prompt' );
		if ( \is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		$result   = StyleAbilities::recommend_style( $input );
		$document = self::sanitize_activity_document( $request->get_param( 'document' ) );
		$surface  = self::resolve_style_request_diagnostic_surface( $input, $document );
		$target   = self::build_style_request_diagnostic_target( $input );

		if ( \is_wp_error( $result ) ) {
			$result = self::append_request_meta_to_error_for_route( $result, 'recommend-style' );

			if ( ! $resolve_signature_only ) {
				self::persist_request_diagnostic_failure_activity(
					$surface,
					$result,
					$document,
					$target,
					$input
				);
			}

			return $result;
		}

		$payload = $resolve_signature_only
			? $result
			: self::append_request_meta_for_route( $result, 'recommend-style' );

		if ( ! $resolve_signature_only ) {
			self::persist_request_diagnostic_activity(
				$surface,
				$payload,
				$document,
				$target,
				$input
			);
		}

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Handle POST /recommend-template-part with a thin ability adapter.
	 */
	public static function handle_recommend_template_part( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$resolve_signature_only = self::is_signature_only_request( $request );
		$input                  = [
			'templatePartRef' => $request->get_param( 'templatePartRef' ),
		];

		$prompt = $request->get_param( 'prompt' );
		if ( \is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		if ( $request->has_param( 'visiblePatternNames' ) ) {
			$input['visiblePatternNames'] = self::sanitize_string_array(
				$request->get_param( 'visiblePatternNames' )
			);
		}

		$editor_structure = $request->get_param( 'editorStructure' );
		if ( \is_array( $editor_structure ) || \is_object( $editor_structure ) ) {
			$input['editorStructure'] = self::sanitize_structured_value( $editor_structure );
		}

		$input['resolveSignatureOnly'] = $resolve_signature_only;

		$result = TemplateAbilities::recommend_template_part( $input );

		if ( \is_wp_error( $result ) ) {
			$result = self::append_request_meta_to_error_for_route( $result, 'recommend-template-part' );

			if ( ! $resolve_signature_only ) {
				self::persist_request_diagnostic_failure_activity(
					'template-part',
					$result,
					self::sanitize_activity_document( $request->get_param( 'document' ) ),
					self::build_template_part_request_diagnostic_target( $input ),
					$input
				);
			}

			return $result;
		}

		$payload = $resolve_signature_only
			? $result
			: self::append_request_meta_for_route( $result, 'recommend-template-part' );

		if ( ! $resolve_signature_only ) {
			self::persist_request_diagnostic_activity(
				'template-part',
				$payload,
				self::sanitize_activity_document( $request->get_param( 'document' ) ),
				self::build_template_part_request_diagnostic_target( $input ),
				$input
			);
		}

		return new \WP_REST_Response( $payload, 200 );
	}

	public static function handle_get_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$is_global_request = true === $request->get_param( 'global' )
			|| '' === \trim( (string) $request->get_param( 'scopeKey' ) );

		if ( $is_global_request ) {
			$date_filter_error = self::validate_admin_activity_date_filter_request( $request );

			if ( \is_wp_error( $date_filter_error ) ) {
				return $date_filter_error;
			}

			$result = ActivityRepository::query_admin(
				[
					'scopeKey'                   => $request->get_param( 'scopeKey' ),
					'surface'                    => $request->get_param( 'surface' ),
					'surfaceOperator'            => $request->get_param( 'surfaceOperator' ),
					'status'                     => $request->get_param( 'status' ),
					'statusOperator'             => $request->get_param( 'statusOperator' ),
					'postType'                   => $request->get_param( 'postType' ),
					'postTypeOperator'           => $request->get_param( 'postTypeOperator' ),
					'provider'                   => $request->get_param( 'provider' ),
					'providerOperator'           => $request->get_param( 'providerOperator' ),
					'providerPath'               => $request->get_param( 'providerPath' ),
					'providerPathOperator'       => $request->get_param( 'providerPathOperator' ),
					'configurationOwner'         => $request->get_param( 'configurationOwner' ),
					'configurationOwnerOperator' => $request->get_param( 'configurationOwnerOperator' ),
					'credentialSource'           => $request->get_param( 'credentialSource' ),
					'credentialSourceOperator'   => $request->get_param( 'credentialSourceOperator' ),
					'selectedProvider'           => $request->get_param( 'selectedProvider' ),
					'selectedProviderOperator'   => $request->get_param( 'selectedProviderOperator' ),
					'entityId'                   => $request->get_param( 'entityId' ),
					'entityIdOperator'           => $request->get_param( 'entityIdOperator' ),
					'blockPath'                  => $request->get_param( 'blockPath' ),
					'blockPathOperator'          => $request->get_param( 'blockPathOperator' ),
					'userId'                     => $request->get_param( 'userId' ),
					'userIdOperator'             => $request->get_param( 'userIdOperator' ),
					'operationType'              => $request->get_param( 'operationType' ),
					'operationTypeOperator'      => $request->get_param( 'operationTypeOperator' ),
					'entityType'                 => $request->get_param( 'entityType' ),
					'entityRef'                  => $request->get_param( 'entityRef' ),
					'page'                       => $request->get_param( 'page' ),
					'perPage'                    => $request->get_param( 'perPage' ),
					'search'                     => $request->get_param( 'search' ),
					'sortField'                  => $request->get_param( 'sortField' ),
					'sortDirection'              => $request->get_param( 'sortDirection' ),
					'day'                        => $request->get_param( 'day' ),
					'dayEnd'                     => $request->get_param( 'dayEnd' ),
					'dayOperator'                => $request->get_param( 'dayOperator' ),
					'dayRelativeValue'           => $request->get_param( 'dayRelativeValue' ),
					'dayRelativeUnit'            => $request->get_param( 'dayRelativeUnit' ),
				]
			);

			return new \WP_REST_Response( $result, 200 );
		}

		$activity_filters = [
			'scopeKey'   => $request->get_param( 'scopeKey' ),
			'surface'    => $request->get_param( 'surface' ),
			'entityType' => $request->get_param( 'entityType' ),
			'entityRef'  => $request->get_param( 'entityRef' ),
			'userId'     => $request->get_param( 'userId' ),
			'limit'      => $request->get_param( 'limit' ),
		];

		$entries = true === $request->get_param( 'groupBySurface' )
			? ActivityRepository::query_grouped_by_surface(
				array_merge(
					$activity_filters,
					[
						'surfaceLimit' => $request->get_param( 'surfaceLimit' ),
					]
				)
			)
			: ActivityRepository::query( $activity_filters );

		return new \WP_REST_Response(
			[
				'entries' => $entries,
			],
			200
		);
	}

	private static function validate_admin_activity_date_filter_request( \WP_REST_Request $request ): ?\WP_Error {
		$operator = \trim( (string) ( $request->get_param( 'dayOperator' ) ?? 'on' ) );
		$operator = '' !== $operator ? $operator : 'on';
		$day      = \trim( (string) ( $request->get_param( 'day' ) ?? '' ) );
		$day_end  = \trim( (string) ( $request->get_param( 'dayEnd' ) ?? '' ) );

		if ( $request->has_param( 'dayOperator' ) && ! \in_array( $operator, self::ACTIVITY_DAY_OPERATORS, true ) ) {
			return self::invalid_admin_activity_date_filter_error( 'Unsupported activity date filter operator.' );
		}

		if ( '' !== $day && ! self::is_valid_activity_day( $day ) ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date filters must use YYYY-MM-DD dates.' );
		}

		if ( '' !== $day_end && ! self::is_valid_activity_day( $day_end ) ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date range filters must use YYYY-MM-DD dates.' );
		}

		if ( 'between' === $operator ) {
			if ( '' === $day && '' === $day_end && $request->has_param( 'dayOperator' ) ) {
				return self::invalid_admin_activity_date_filter_error( 'Activity date range filters require both start and end dates.' );
			}

			if ( ( '' === $day ) !== ( '' === $day_end ) ) {
				return self::invalid_admin_activity_date_filter_error( 'Activity date range filters require both start and end dates.' );
			}

			if ( '' !== $day && '' !== $day_end && $day > $day_end ) {
				return self::invalid_admin_activity_date_filter_error( 'Activity date range start must be on or before the end date.' );
			}

			return null;
		}

		if ( '' !== $day_end ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date range end is only supported with between filters.' );
		}

		if ( \in_array( $operator, [ 'inThePast', 'over' ], true ) ) {
			$relative_value = $request->get_param( 'dayRelativeValue' );
			$relative_unit  = \trim( (string) ( $request->get_param( 'dayRelativeUnit' ) ?? 'days' ) );
			$relative_unit  = '' !== $relative_unit ? $relative_unit : 'days';

			if ( ! $request->has_param( 'dayRelativeValue' ) || (int) $relative_value <= 0 ) {
				return self::invalid_admin_activity_date_filter_error( 'Relative activity date filters require a positive value.' );
			}

			if ( ! \in_array( $relative_unit, self::ACTIVITY_DAY_RELATIVE_UNITS, true ) ) {
				return self::invalid_admin_activity_date_filter_error( 'Unsupported relative activity date filter unit.' );
			}

			return null;
		}

		if ( $request->has_param( 'dayRelativeValue' ) && (int) $request->get_param( 'dayRelativeValue' ) > 0 ) {
			return self::invalid_admin_activity_date_filter_error( 'Relative activity date filters require a relative date operator.' );
		}

		if ( $request->has_param( 'dayOperator' ) && '' === $day ) {
			return self::invalid_admin_activity_date_filter_error( 'Activity date filters require a date value.' );
		}

		return null;
	}

	private static function invalid_admin_activity_date_filter_error( string $message ): \WP_Error {
		return new \WP_Error(
			'flavor_agent_activity_invalid_date_filter',
			$message,
			[ 'status' => 400 ]
		);
	}

	private static function is_valid_activity_day( string $day ): bool {
		$date   = \DateTimeImmutable::createFromFormat(
			'!Y-m-d',
			$day,
			new \DateTimeZone( 'UTC' )
		);
		$errors = \DateTimeImmutable::getLastErrors();

		return false !== $date
			&& ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) )
			&& $date->format( 'Y-m-d' ) === $day;
	}

	public static function handle_create_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$entry = $request->get_param( 'entry' );

		if ( ! \is_array( $entry ) && ! \is_object( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'Flavor Agent activity entries must be structured objects.',
				[ 'status' => 400 ]
			);
		}

		$result = ActivityRepository::create(
			self::sanitize_structured_value( $entry )
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'entry' => $result,
			],
			200
		);
	}

	public static function handle_update_activity_undo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$status = (string) $request->get_param( 'status' );
		$result = ActivityRepository::update_undo_status(
			(string) $request->get_param( 'id' ),
			'' !== $status ? $status : 'undone',
			$request->has_param( 'error' )
				? (string) $request->get_param( 'error' )
				: null
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'entry' => $result,
			],
			200
		);
	}
}
