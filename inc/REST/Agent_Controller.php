<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Activity\Permissions as ActivityPermissions;
use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Abilities\BlockAbilities;
use FlavorAgent\Abilities\NavigationAbilities;
use FlavorAgent\Abilities\PatternAbilities;
use FlavorAgent\Abilities\TemplateAbilities;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Support\StringArray;

final class Agent_Controller {

	private const NAMESPACE = 'flavor-agent/v1';

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
					'templatePartRef' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && $value !== '',
					],
					'prompt'          => [
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
						'scopeKey'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== $value,
						],
						'surface'    => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityType' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'entityRef'  => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'limit'      => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 20,
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
		$sanitized = self::normalize_structured_value( $value );

		return is_array( $sanitized ) ? $sanitized : [];
	}

	public static function sanitize_block_markup( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	private static function normalize_structured_value( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized[ $key ] = self::normalize_structured_value( $entry );
			}

			return $normalized;
		}

		if (
			is_string( $value )
			|| is_int( $value )
			|| is_float( $value )
			|| is_bool( $value )
			|| null === $value
		) {
			return $value;
		}

		return null;
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
				'payload'  => $result,
				'clientId' => $client_id,
			],
			200
		);
	}

	public static function handle_sync_patterns( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = PatternIndex::sync();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
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

		return new \WP_REST_Response( $result, 200 );
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

		return new \WP_REST_Response( $result, 200 );
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

		$result = TemplateAbilities::recommend_template( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
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

		$result = TemplateAbilities::recommend_template_part( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	public static function handle_get_activity( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ActivityPermissions::can_access_activity_request( $request ) ) {
			return ActivityPermissions::forbidden_error();
		}

		$entries = ActivityRepository::query(
			[
				'scopeKey'   => $request->get_param( 'scopeKey' ),
				'surface'    => $request->get_param( 'surface' ),
				'entityType' => $request->get_param( 'entityType' ),
				'entityRef'  => $request->get_param( 'entityRef' ),
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
