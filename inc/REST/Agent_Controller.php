<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Abilities\PatternAbilities;
use FlavorAgent\LLM\Client;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\Patterns\PatternIndex;

final class Agent_Controller {

    private const NAMESPACE = 'flavor-agent/v1';

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/recommend-block', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_recommend_block' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'args'                => [
                'editorContext'  => [
                    'required'          => true,
                    'type'              => 'object',
                    'description'       => 'Block context snapshot from the editor.',
                    'validate_callback' => fn( $value ) => is_array( $value ) || is_object( $value ),
                ],
                'prompt'   => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'clientId' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/sync-patterns', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_sync_patterns' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/recommend-patterns', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_recommend_patterns' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'args'                => [
                'postType'     => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'templateType' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'blockContext' => [
                    'required' => false,
                    'type'     => 'object',
                ],
                'prompt'       => [
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
        ] );
    }

    public static function validate_string_array( $value ): bool {
        return is_array( $value );
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    public static function sanitize_string_array( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $entry ): string => sanitize_text_field( (string) $entry ),
                        $value
                    ),
                    static fn( string $entry ): bool => $entry !== ''
                )
            )
        );
    }

    public static function handle_recommend_block( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $api_key = get_option( 'flavor_agent_api_key' );
        if ( empty( $api_key ) ) {
            return new \WP_Error(
                'missing_api_key',
                'Configure your API key in Settings > Flavor Agent.',
                [ 'status' => 400 ]
            );
        }

        $context   = $request->get_param( 'editorContext' );
        $prompt    = $request->get_param( 'prompt' );
        $client_id = $request->get_param( 'clientId' );

        $system_prompt = Prompt::build_system();
        $user_prompt   = Prompt::build_user( $context, $prompt );

        $result = Client::chat( $system_prompt, $user_prompt, $api_key );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $payload = Prompt::parse_response( $result );

        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $payload = Prompt::enforce_block_context_rules( $payload, $context['block'] ?? [] );

        return new \WP_REST_Response( [
            'payload'  => $payload,
            'clientId' => $client_id,
        ], 200 );
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
}
