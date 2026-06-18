<?php

declare(strict_types=1);

namespace FlavorAgent\AI\Abilities;

final class PreviewRecommendTemplateAbility extends PreviewRecommendationAbility {
	protected const ABILITY_NAME   = 'flavor-agent/preview-recommend-template';
	protected const PARENT_CLASS   = RecommendTemplateAbility::class;
	protected const PARENT_ABILITY = 'flavor-agent/recommend-template';
	protected const SIGNATURE_KEYS = [ 'reviewContextSignature', 'resolvedContextSignature' ];

	/**
	 * Prefill the Abilities Explorer with a template ref that resolves on the
	 * active theme, omitting the default when none does (e.g. a classic theme).
	 *
	 * @return array<string, mixed>
	 */
	protected function prefill_defaults(): array {
		$ref = $this->resolvable_template_ref( 'wp_template', 'index' );

		return '' !== $ref ? [ 'templateRef' => $ref ] : [];
	}
}
