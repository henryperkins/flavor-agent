<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

final class NavigationAbilities {

    public static function recommend_navigation( mixed $input ): \WP_Error {
        return new \WP_Error(
            'not_implemented',
            'Navigation recommendation is planned but not yet available.',
            [ 'status' => 501 ]
        );
    }
}
