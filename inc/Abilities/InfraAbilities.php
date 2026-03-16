<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;

final class InfraAbilities {

    public static function get_theme_tokens( array $input ): array {
        return ServerCollector::for_tokens();
    }

    public static function check_status( array $input ): array {
        $api_key = get_option( 'flavor_agent_api_key', '' );
        $model   = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );

        return [
            'configured'        => ! empty( $api_key ),
            'model'             => $model,
            'availableAbilities' => [
                'flavor-agent/recommend-block',
                'flavor-agent/introspect-block',
                'flavor-agent/list-patterns',
                'flavor-agent/list-template-parts',
                'flavor-agent/get-theme-tokens',
                'flavor-agent/check-status',
            ],
        ];
    }
}
