<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\PatternAbilities;

final class RecommendPatternsAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-patterns';
	protected const SURFACE              = 'pattern';
	protected const CAPABILITY           = 'edit_posts';
	protected const CALLBACK             = [ PatternAbilities::class, 'recommend_patterns' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'additional' ];
}
