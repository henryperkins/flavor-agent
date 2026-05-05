<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

use FlavorAgent\Abilities\TemplateAbilities;

final class RecommendTemplatePartAbility extends RecommendationAbility {
	protected const ABILITY_NAME         = 'flavor-agent/recommend-template-part';
	protected const SURFACE              = 'template-part';
	protected const CAPABILITY           = 'edit_theme_options';
	protected const CALLBACK             = [ TemplateAbilities::class, 'recommend_template_part' ];
	protected const GUIDELINE_CATEGORIES = [ 'site', 'copy', 'additional' ];
}
