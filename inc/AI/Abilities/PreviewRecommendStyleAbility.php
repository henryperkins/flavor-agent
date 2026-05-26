<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendStyleAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-style';
	protected const PARENT_CLASS   = RecommendStyleAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-style';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
