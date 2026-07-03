<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\PostBlocksAbilities;

final class RecommendPostBlocksAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-post-blocks';
	protected const SURFACE              = 'post-blocks';
	protected const CAPABILITY           = 'edit_posts';
	protected const CALLBACK             = [ PostBlocksAbilities::class, 'recommend_post_blocks' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'images', 'additional' ];
}
