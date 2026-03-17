<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;

final class InfraAbilities {

    public static function get_theme_tokens( array $input ): array {
        return ServerCollector::for_tokens();
    }

    public static function check_status( array $input ): array {
        $anthropic_key   = get_option( 'flavor_agent_api_key', '' );
        $anthropic_model = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );

        $azure_endpoint  = get_option( 'flavor_agent_azure_openai_endpoint', '' );
        $azure_key       = get_option( 'flavor_agent_azure_openai_key', '' );
        $azure_embedding = get_option( 'flavor_agent_azure_embedding_deployment', '' );
        $azure_chat      = get_option( 'flavor_agent_azure_chat_deployment', '' );
        $qdrant_url      = get_option( 'flavor_agent_qdrant_url', '' );
        $qdrant_key      = get_option( 'flavor_agent_qdrant_key', '' );

        $anthropic_configured = ! empty( $anthropic_key );
        $azure_chat_configured = ! empty( $azure_endpoint ) && ! empty( $azure_key ) && ! empty( $azure_chat );
        $azure_configured     = $azure_chat_configured && ! empty( $azure_embedding );
        $qdrant_configured    = ! empty( $qdrant_url ) && ! empty( $qdrant_key );

        // Read-only abilities are always available.
        $abilities = [
            'flavor-agent/introspect-block',
            'flavor-agent/list-patterns',
            'flavor-agent/list-template-parts',
            'flavor-agent/get-theme-tokens',
            'flavor-agent/check-status',
        ];

        if ( $anthropic_configured ) {
            $abilities[] = 'flavor-agent/recommend-block';
        }

        if ( $azure_configured && $qdrant_configured ) {
            $abilities[] = 'flavor-agent/recommend-patterns';
        }

        if ( $azure_chat_configured ) {
            $abilities[] = 'flavor-agent/recommend-template';
        }

        return [
            'configured'         => $anthropic_configured,
            'model'              => $anthropic_configured ? $anthropic_model : null,
            'availableAbilities' => $abilities,
            'backends'           => [
                'anthropic'    => [
                    'configured' => $anthropic_configured,
                    'model'      => $anthropic_configured ? $anthropic_model : null,
                ],
                'azure_openai' => [
                    'configured'          => $azure_configured,
                    'chatDeployment'      => $azure_configured ? $azure_chat : null,
                    'embeddingDeployment' => $azure_configured ? $azure_embedding : null,
                ],
                'qdrant'       => [
                    'configured' => $qdrant_configured,
                ],
            ],
        ];
    }
}
