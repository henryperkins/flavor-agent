<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendPostBlocksAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-post-blocks';
	protected const PARENT_CLASS   = RecommendPostBlocksAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-post-blocks';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
