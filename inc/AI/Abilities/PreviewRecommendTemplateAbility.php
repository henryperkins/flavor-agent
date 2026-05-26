<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendTemplateAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-template';
	protected const PARENT_CLASS   = RecommendTemplateAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-template';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
