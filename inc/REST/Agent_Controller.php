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
		'sync-patterns'          => [
			'route'               => 'POST /flavor-agent/v1/sync-patterns',
			'includeProviderMeta' => false,
		],
	];

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/recommend-block',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_block' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => [
					'editorContext' => [
						'required'          => true,
						'type'              => 'object',
						'description'       => 'Block context snapshot from the editor.',
						'validate_callback' => fn( $value ) => is_array( $value ) || is_object( $value ),
					],
					'prompt'        => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'clientId'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommend-content',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_content' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
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
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/sync-patterns',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_sync_patterns' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommend-patterns',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_patterns' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
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
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommend-navigation',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_navigation' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'args'                => [
					'menuId'           => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'navigationMarkup' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => [ __CLASS__, 'sanitize_block_markup' ],
						'validate_callback' => static fn( $value ): bool => is_string( $value ),
					],
					'prompt'           => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommend-style',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_style' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'args'                => [
					'scope'        => [
						'required'          => true,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'styleContext' => [
						'required'          => true,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'prompt'       => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommend-template',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_template' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'args'                => [
					'templateRef'         => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && $value !== '',
					],
					'templateType'        => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'prompt'              => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'visiblePatternNames' => [
						'required'          => false,
						'type'              => 'array',
						'validate_callback' => [ __CLASS__, 'validate_string_array' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_string_array' ],
					],
					'editorSlots'         => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
					'editorStructure'     => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommend-template-part',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_recommend_template_part' ],
				'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
				'args'                => [
					'templatePartRef'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && $value !== '',
					],
					'prompt'              => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'visiblePatternNames' => [
						'required'          => false,
						'type'              => 'array',
						'validate_callback' => [ __CLASS__, 'validate_string_array' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_string_array' ],
					],
					'editorStructure'     => [
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => [ __CLASS__, 'validate_structured_value' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_structured_value' ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/activity',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'handle_get_activity' ],
					'permission_callback' => [ ActivityPermissions::class, 'can_access_activity_request' ],
					'args'                => [
						'scopeKey'              => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'global'                => [
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => static fn( $value ): bool => in_array( $value, [ true, 1, '1', 'true', 'yes' ], true ),
						],
						'surface'               => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityType'            => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityRef'             => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'userId'                => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'postType'              => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityId'              => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'blockPath'             => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'operationType'         => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'status'                => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'page'                  => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						],
						'perPage'               => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						],
						'search'                => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sortField'             => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'sortDirection'         => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'surfaceOperator'       => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'statusOperator'        => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'postTypeOperator'      => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityIdOperator'      => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'blockPathOperator'     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'userIdOperator'        => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'operationTypeOperator' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'day'                   => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayEnd'                => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayOperator'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'dayRelativeValue'      => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'dayRelativeUnit'       => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'limit'                 => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => ActivityRepository::DEFAULT_PER_PAGE,
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

		register_rest_route(
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

	public static function validate_string_array( $value ): bool {
		return is_array( $value );
	}

	public static function validate_structured_value( $value ): bool {
		return is_array( $value ) || is_object( $value );
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	public static function sanitize_string_array( $value ): array {
		return StringArray::sanitize( $value );
	}

	public static function sanitize_structured_value( $value ): array {
		$sanitized = Serializer::normalize_structured_value( $value );

		return is_array( $sanitized ) ? $sanitized : [];
	}

	public static function sanitize_block_markup( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	public static function handle_recommend_block( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client_id = $request->get_param( 'clientId' );
		$result    = BlockAbilities::recommend_block(
			[
				'editorContext' => $request->get_param( 'editorContext' ),
				'prompt'        => $request->get_param( 'prompt' ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'payload'  => self::append_request_meta_for_route( $result, 'recommend-block' ),
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
		if ( is_string( $mode ) && $mode !== '' ) {
			$input['mode'] = $mode;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = self::sanitize_block_markup( $prompt );
		}

		$voice_profile = $request->get_param( 'voiceProfile' );
		if ( is_string( $voice_profile ) && $voice_profile !== '' ) {
			$input['voiceProfile'] = self::sanitize_block_markup( $voice_profile );
		}

		$post_context = $request->get_param( 'postContext' );
		if ( is_array( $post_context ) || is_object( $post_context ) ) {
			$input['postContext'] = self::sanitize_structured_value( $post_context );
		}

		$result = ContentAbilities::recommend_content( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'recommend-content' ),
			200
		);
	}

	public static function handle_sync_patterns( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = PatternIndex::sync();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

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
		if ( is_string( $template_type ) && $template_type !== '' ) {
			$input['templateType'] = $template_type;
		}

		$block_context = $request->get_param( 'blockContext' );
		if ( is_array( $block_context ) && ! empty( $block_context ) ) {
			$input['blockContext'] = $block_context;
		}

		$insertion_context = $request->get_param( 'insertionContext' );
		if ( is_array( $insertion_context ) && ! empty( $insertion_context ) ) {
			$input['insertionContext'] = $insertion_context;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		if ( $request->has_param( 'visiblePatternNames' ) ) {
			$input['visiblePatternNames'] = self::sanitize_string_array(
				$request->get_param( 'visiblePatternNames' )
			);
		}

		$result = PatternAbilities::recommend_patterns( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'recommend-patterns' ),
			200
		);
	}

	/**
	 * Handle POST /recommend-navigation with a thin ability adapter.
	 */
	public static function handle_recommend_navigation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input   = [];
		$menu_id = max( 0, (int) $request->get_param( 'menuId' ) );

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
		if ( is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		$result = NavigationAbilities::recommend_navigation( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'recommend-navigation' ),
			200
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function append_request_meta_for_route( array $payload, string $route_key ): array {
		$route_definition = self::REQUEST_META_ROUTES[ $route_key ] ?? null;

		if ( ! is_array( $route_definition ) ) {
			return $payload;
		}

		$request_meta = ! empty( $route_definition['includeProviderMeta'] )
			? Provider::active_chat_request_meta()
			: [];

		if ( ! empty( $route_definition['ability'] ) && is_string( $route_definition['ability'] ) ) {
			$request_meta['ability'] = $route_definition['ability'];
		}

		$request_meta['route'] = $route_definition['route'];
		$payload['requestMeta'] = $request_meta;

		return $payload;
	}

	/**
	 * Handle POST /recommend-template with a thin ability adapter.
	 */
	public static function handle_recommend_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = [
			'templateRef' => $request->get_param( 'templateRef' ),
		];

		$template_type = $request->get_param( 'templateType' );
		if ( is_string( $template_type ) && $template_type !== '' ) {
			$input['templateType'] = $template_type;
		}

		$prompt = $request->get_param( 'prompt' );
		if ( is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		if ( $request->has_param( 'visiblePatternNames' ) ) {
			$input['visiblePatternNames'] = self::sanitize_string_array(
				$request->get_param( 'visiblePatternNames' )
			);
		}

		$editor_slots = $request->get_param( 'editorSlots' );
		if ( is_array( $editor_slots ) || is_object( $editor_slots ) ) {
			$input['editorSlots'] = self::sanitize_structured_value( $editor_slots );
		}

		$editor_structure = $request->get_param( 'editorStructure' );
		if ( is_array( $editor_structure ) || is_object( $editor_structure ) ) {
			$input['editorStructure'] = self::sanitize_structured_value( $editor_structure );
		}

		$result = TemplateAbilities::recommend_template( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'recommend-template' ),
			200
		);
	}

	/**
	 * Handle POST /recommend-style with a thin ability adapter.
	 */
	public static function handle_recommend_style( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = [
			'scope'        => self::sanitize_structured_value(
				$request->get_param( 'scope' )
			),
			'styleContext' => self::sanitize_structured_value(
				$request->get_param( 'styleContext' )
			),
		];

		$prompt = $request->get_param( 'prompt' );
		if ( is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		$result = StyleAbilities::recommend_style( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'recommend-style' ),
			200
		);
	}

	/**
	 * Handle POST /recommend-template-part with a thin ability adapter.
	 */
	public static function handle_recommend_template_part( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = [
			'templatePartRef' => $request->get_param( 'templatePartRef' ),
		];

		$prompt = $request->get_param( 'prompt' );
		if ( is_string( $prompt ) && $prompt !== '' ) {
			$input['prompt'] = $prompt;
		}

		if ( $request->has_param( 'visiblePatternNames' ) ) {
			$input['visiblePatternNames'] = self::sanitize_string_array(
				$request->get_param( 'visiblePatternNames' )
			);
		}

		$editor_structure = $request->get_param( 'editorStructure' );
		if ( is_array( $editor_structure ) || is_object( $editor_structure ) ) {
			$input['editorStructure'] = self::sanitize_structured_value( $editor_structure );
		}

		$result = TemplateAbilities::recommend_template_part( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			self::append_request_meta_for_route( $result, 'recommend-template-part' ),
			200
		);
	}

	public static function handle_get_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$is_global_request = true === $request->get_param( 'global' )
			|| '' === trim( (string) $request->get_param( 'scopeKey' ) );

		if ( $is_global_request ) {
			$result = ActivityRepository::query_admin(
				[
					'scopeKey'              => $request->get_param( 'scopeKey' ),
					'surface'               => $request->get_param( 'surface' ),
					'surfaceOperator'       => $request->get_param( 'surfaceOperator' ),
					'status'                => $request->get_param( 'status' ),
					'statusOperator'        => $request->get_param( 'statusOperator' ),
					'postType'              => $request->get_param( 'postType' ),
					'postTypeOperator'      => $request->get_param( 'postTypeOperator' ),
					'entityId'              => $request->get_param( 'entityId' ),
					'entityIdOperator'      => $request->get_param( 'entityIdOperator' ),
					'blockPath'             => $request->get_param( 'blockPath' ),
					'blockPathOperator'     => $request->get_param( 'blockPathOperator' ),
					'userId'                => $request->get_param( 'userId' ),
					'userIdOperator'        => $request->get_param( 'userIdOperator' ),
					'operationType'         => $request->get_param( 'operationType' ),
					'operationTypeOperator' => $request->get_param( 'operationTypeOperator' ),
					'entityType'            => $request->get_param( 'entityType' ),
					'entityRef'             => $request->get_param( 'entityRef' ),
					'page'                  => $request->get_param( 'page' ),
					'perPage'               => $request->get_param( 'perPage' ),
					'search'                => $request->get_param( 'search' ),
					'sortField'             => $request->get_param( 'sortField' ),
					'sortDirection'         => $request->get_param( 'sortDirection' ),
					'day'                   => $request->get_param( 'day' ),
					'dayEnd'                => $request->get_param( 'dayEnd' ),
					'dayOperator'           => $request->get_param( 'dayOperator' ),
					'dayRelativeValue'      => $request->get_param( 'dayRelativeValue' ),
					'dayRelativeUnit'       => $request->get_param( 'dayRelativeUnit' ),
				]
			);

			return new \WP_REST_Response( $result, 200 );
		}

		$entries = ActivityRepository::query(
			[
				'scopeKey'   => $request->get_param( 'scopeKey' ),
				'surface'    => $request->get_param( 'surface' ),
				'entityType' => $request->get_param( 'entityType' ),
				'entityRef'  => $request->get_param( 'entityRef' ),
				'userId'     => $request->get_param( 'userId' ),
				'limit'      => $request->get_param( 'limit' ),
			]
		);

		return new \WP_REST_Response(
			[
				'entries' => $entries,
			],
			200
		);
	}

	public static function handle_create_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$entry = $request->get_param( 'entry' );

		if ( ! is_array( $entry ) && ! is_object( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_entry',
				'Flavor Agent activity entries must be structured objects.',
				[ 'status' => 400 ]
			);
		}

		$result = ActivityRepository::create(
			self::sanitize_structured_value( $entry )
		);

		if ( is_wp_error( $result ) ) {
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

		if ( is_wp_error( $result ) ) {
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
