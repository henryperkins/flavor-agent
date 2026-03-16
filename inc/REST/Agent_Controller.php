<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\LLM\Client;
use FlavorAgent\LLM\Prompt;

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

        return new \WP_REST_Response( [
            'payload'  => $payload,
            'clientId' => $client_id,
        ], 200 );
    }
}
