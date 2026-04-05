<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class TemplatePartContextCollector {

	public function __construct(
		private TemplateRepository $template_repository,
		private TemplateStructureAnalyzer $template_structure_analyzer,
		private PatternOverrideAnalyzer $pattern_override_analyzer,
		private PatternCandidateSelector $pattern_candidate_selector,
		private ThemeTokenCollector $theme_token_collector
	) {
	}

	/**
	 * Assemble context for a single template-part document.
	 *
	 * @param string $template_part_ref Template-part identifier from the Site Editor.
	 * @return array|\WP_Error Template-part context or error.
	 */
	public function for_template_part( string $template_part_ref, ?array $visible_pattern_names = null ): array|\WP_Error {
		$template_part = $this->template_repository->resolve_template_part( $template_part_ref );

		if ( ! $template_part ) {
			return new \WP_Error(
				'template_part_not_found',
				'Could not resolve the current template part from the Site Editor context.',
				[ 'status' => 404 ]
			);
		}

		$content                = (string) ( $template_part->content ?? '' );
		$slug                   = sanitize_key( (string) ( $template_part->slug ?? '' ) );
		$area                   = sanitize_key( (string) ( $template_part->area ?? '' ) );
		$title_source           = (string) ( $template_part->title ?? '' );
		$title                  = sanitize_text_field(
			'' !== $title_source
				? $title_source
				: ( '' !== $slug ? $slug : $template_part_ref )
		);
		$blocks                 = parse_blocks( $content );
		$block_tree             = $this->template_structure_analyzer->summarize_template_part_block_tree( $blocks );
		$operation_targets      = $this->template_structure_analyzer->collect_template_part_operation_targets( $blocks );
		$insertion_anchors      = $this->template_structure_analyzer->collect_template_part_insertion_anchors( $operation_targets );
		$structural_constraints = $this->template_structure_analyzer->collect_template_part_structural_constraints( $blocks );
		$top_level_blocks       = array_values(
			array_filter(
				array_map(
					static fn( array $block ): string => (string) ( $block['blockName'] ?? '' ),
					array_filter( $blocks, 'is_array' )
				),
				static fn( string $name ): bool => '' !== $name
			)
		);
		$summary_stats          = $this->template_structure_analyzer->collect_template_part_block_stats( $blocks );
		$block_counts           = $summary_stats['blockCounts'];

		return [
			'templatePartRef'         => $this->template_repository->resolve_template_part_ref( $template_part_ref, $template_part ),
			'slug'                    => $slug,
			'title'                   => '' !== $title ? $title : $template_part_ref,
			'area'                    => $area,
			'blockTree'               => $block_tree,
			'topLevelBlocks'          => $top_level_blocks,
			'currentPatternOverrides' => $this->pattern_override_analyzer->collect_current_pattern_override_summary( $blocks ),
			'blockCounts'             => $block_counts,
			'structureStats'          => [
				'blockCount'            => $summary_stats['blockCount'],
				'maxDepth'              => $summary_stats['maxDepth'],
				'hasNavigation'         => ! empty( $block_counts['core/navigation'] ),
				'containsLogo'          => ! empty( $block_counts['core/site-logo'] ),
				'containsSiteTitle'     => ! empty( $block_counts['core/site-title'] ),
				'containsSearch'        => ! empty( $block_counts['core/search'] ),
				'containsSocialLinks'   => ! empty( $block_counts['core/social-links'] ),
				'containsQuery'         => ! empty( $block_counts['core/query'] ),
				'containsColumns'       => ! empty( $block_counts['core/columns'] ),
				'containsButtons'       => ! empty( $block_counts['core/buttons'] ),
				'containsSpacer'        => ! empty( $block_counts['core/spacer'] ),
				'containsSeparator'     => ! empty( $block_counts['core/separator'] ),
				'firstTopLevelBlock'    => $top_level_blocks[0] ?? '',
				'lastTopLevelBlock'     => count( $top_level_blocks ) > 0 ? $top_level_blocks[ count( $top_level_blocks ) - 1 ] : '',
				'hasSingleWrapperGroup' => 1 === count( $top_level_blocks ) && 'core/group' === $top_level_blocks[0],
				'isNearlyEmpty'         => $summary_stats['blockCount'] <= 1,
			],
			'operationTargets'        => $operation_targets,
			'insertionAnchors'        => $insertion_anchors,
			'structuralConstraints'   => $structural_constraints,
			'patterns'                => $this->pattern_candidate_selector->collect_template_part_candidate_patterns(
				$area,
				$visible_pattern_names
			),
			'themeTokens'             => $this->theme_token_collector->for_tokens(),
		];
	}
}
