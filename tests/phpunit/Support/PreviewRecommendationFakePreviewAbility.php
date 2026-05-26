<?php

declare(strict_types=1);

namespace FlavorAgent\Tests\Support;

use FlavorAgent\AI\Abilities\PreviewRecommendationAbility;

final class PreviewRecommendationFakePreviewAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-fake';
	protected const PARENT_CLASS   = PreviewRecommendationFakeParentAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-fake';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];
}
