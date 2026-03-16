<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Context\ServerCollector;

final class TemplateAbilities {

    public static function list_template_parts( array $input ): array {
        $area = $input['area'] ?? null;

        return [
            'templateParts' => ServerCollector::for_template_parts( $area ),
        ];
    }

    public static function recommend_template( array $input ): \WP_Error {
        return new \WP_Error(
            'not_implemented',
            'Template recommendation is planned but not yet available.',
            [ 'status' => 501 ]
        );
    }
}
