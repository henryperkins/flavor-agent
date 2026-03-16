<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;

final class PatternAbilities {

    public static function list_patterns( array $input ): array {
        $categories     = $input['categories'] ?? null;
        $block_types    = $input['blockTypes'] ?? null;
        $template_types = $input['templateTypes'] ?? null;

        return [
            'patterns' => ServerCollector::for_patterns( $categories, $block_types, $template_types ),
        ];
    }

    public static function recommend_patterns( array $input ): \WP_Error {
        return new \WP_Error(
            'not_implemented',
            'Pattern recommendation is planned but not yet available.',
            [ 'status' => 501 ]
        );
    }
}
