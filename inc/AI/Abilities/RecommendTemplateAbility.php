<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\TemplateAbilities;

final class RecommendTemplateAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-template';
	protected const SURFACE              = 'template';
	protected const CAPABILITY           = 'edit_theme_options';
	protected const CALLBACK             = [ TemplateAbilities::class, 'recommend_template' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'additional' ];
}
