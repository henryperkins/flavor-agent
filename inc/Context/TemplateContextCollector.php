<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class TemplateContextCollector {

	public function __construct(
		private TemplateRepository $template_repository,
		private TemplateTypeResolver $template_type_resolver,
		private TemplateStructureAnalyzer $template_structure_analyzer,
		private PatternOverrideAnalyzer $pattern_override_analyzer,
		private ViewportVisibilityAnalyzer $viewport_visibility_analyzer,
		private PatternCandidateSelector $pattern_candidate_selector,
		private ThemeTokenCollector $theme_token_collector
	) {
	}

	/**
	 * Assemble context for a template recommendation.
	 *
	 * Template recommendations stay template-global unless a dedicated
	 * design update explicitly introduces inserter-root narrowing.
	 *
	 * @param string        $template_ref            Template identifier from the Site Editor.
	 * @param string|null   $template_type           Normalized template type. Derived if null.
	 * @param string[]|null $visible_pattern_names   Optional client-side visible pattern filter.
	 *                                               Applied before the candidate cap so that all
	 *                                               visible patterns are considered, not just the
	 *                                               first N unfiltered candidates.
	 * @return array|\WP_Error Template context or error.
	 */
	public function for_template(
		string $template_ref,
		?string $template_type = null,
		?array $visible_pattern_names = null
	): array|\WP_Error {
		$template = $this->template_repository->resolve_template( $template_ref );

		if ( ! $template ) {
			return new \WP_Error(
				'template_not_found',
				'Could not resolve the current template from the Site Editor context.',
				[ 'status' => 404 ]
			);
		}

		if ( null === $template_type ) {
			$template_type = $this->template_type_resolver->derive_template_type( $template_ref );
		}

		$available_parts      = $this->template_repository->for_template_parts( null, false );
		$part_area_lookup     = $this->template_repository->for_template_part_areas();
		$template_blocks      = parse_blocks( $template->content ?? '' );
		$slots                = $this->template_structure_analyzer->collect_template_part_slots(
			$template_blocks,
			$part_area_lookup
		);
		$top_level_block_tree = $this->template_structure_analyzer->summarize_template_block_tree(
			$template_blocks,
			$part_area_lookup
		);

		return [
			'templateRef'               => $template_ref,
			'templateType'              => $template_type,
			'title'                     => $template->title ?? $template_ref,
			'assignedParts'             => $slots['assignedParts'],
			'emptyAreas'                => $slots['emptyAreas'],
			'allowedAreas'              => $slots['allowedAreas'],
			'topLevelBlockTree'         => $top_level_block_tree,
			'currentPatternOverrides'   => $this->pattern_override_analyzer->collect_current_pattern_override_summary(
				$template_blocks,
				$part_area_lookup
			),
			'currentViewportVisibility' => $this->viewport_visibility_analyzer->collect_current_viewport_visibility_summary(
				$template_blocks,
				$part_area_lookup
			),
			'topLevelInsertionAnchors'  => $this->template_structure_analyzer->collect_template_insertion_anchors( $top_level_block_tree ),
			'structureStats'            => $this->template_structure_analyzer->collect_template_structure_stats(
				$template_blocks,
				$top_level_block_tree
			),
			'availableParts'            => $available_parts,
			'patterns'                  => $this->pattern_candidate_selector->collect_template_candidate_patterns(
				$template_type,
				$visible_pattern_names
			),
			'themeTokens'               => $this->theme_token_collector->for_tokens(),
		];
	}
}
