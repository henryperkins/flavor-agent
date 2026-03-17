<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Abilities\BlockAbilities;
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
				],
			]
		);
	}

	public static function validate_string_array( $value ): bool {
		return is_array( $value );
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	public static function sanitize_string_array( $value ): array {
		return StringArray::sanitize( $value );
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

		$result = TemplateAbilities::recommend_template( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}
}
