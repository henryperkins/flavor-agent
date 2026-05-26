<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendTemplatePartAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-template-part';
	protected const PARENT_CLASS   = RecommendTemplatePartAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-template-part';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
