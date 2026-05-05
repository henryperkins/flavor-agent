<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\StyleAbilities;

final class RecommendStyleAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-style';
	protected const SURFACE              = 'style';
	protected const CAPABILITY           = 'edit_theme_options';
	protected const CALLBACK             = [ StyleAbilities::class, 'recommend_style' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'additional' ];
}
