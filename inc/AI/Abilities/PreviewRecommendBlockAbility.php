<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendBlockAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-block';
	protected const PARENT_CLASS   = RecommendBlockAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-block';
	protected const SIGNATURE_KEYS = [ 'resolvedContextSignature' ];
}
