<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\ContentAbilities;

final class RecommendContentAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-content';
	protected const SURFACE              = 'content';
	protected const CAPABILITY           = 'edit_posts';
	protected const CALLBACK             = [ ContentAbilities::class, 'recommend_content' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'additional' ];
}
