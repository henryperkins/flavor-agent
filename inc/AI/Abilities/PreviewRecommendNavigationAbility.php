<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendNavigationAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-navigation';
	protected const PARENT_CLASS   = RecommendNavigationAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-navigation';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature' ];
}
