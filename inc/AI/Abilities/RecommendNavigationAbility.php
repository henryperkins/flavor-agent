<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\NavigationAbilities;

final class RecommendNavigationAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-navigation';
	protected const SURFACE              = 'navigation';
	protected const CAPABILITY           = 'edit_theme_options';
	protected const CALLBACK             = [ NavigationAbilities::class, 'recommend_navigation' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'additional' ];
}
