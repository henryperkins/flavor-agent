<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\BlockAbilities;

final class RecommendBlockAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-block';
	protected const SURFACE              = 'block';
	protected const CAPABILITY           = 'edit_posts';
	protected const CALLBACK             = [ BlockAbilities::class, 'recommend_block' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'additional' ];
}
