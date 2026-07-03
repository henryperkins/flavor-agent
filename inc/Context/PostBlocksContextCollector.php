<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

/**
 * Server-side context collector for the external post-blocks apply surface:
 * the "document target contract". Parses one post/page's live post_content and
 * publishes the executable-target allowlist (blockTree / operationTargets /
 * insertionAnchors / structuralConstraints) alongside a drift baseline hash.
 *
 * Unlike ServerCollector::for_block (which assembles client-supplied editor
 * context), everything here is server-collected — no client-supplied tree is
 * trusted for this surface.
 */
final class PostBlocksContextCollector {

	private const ALLOWED_POST_TYPES = [ 'post', 'page' ];

	private const ALLOWED_POST_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

	public function __construct(
		private TemplateStructureAnalyzer $template_structure_analyzer,
		private PatternCandidateSelector $pattern_candidate_selector,
		private ThemeTokenCollector $theme_token_collector
	) {
	}

	/**
	 * Assemble context for a single post/page document.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function for_post_blocks( int $post_id ): array|\WP_Error {
		$post = $this->resolve_post_for_apply( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$content                = (string) ( $post->post_content ?? '' );
		$blocks                 = parse_blocks( $content );
		$block_tree             = $this->template_structure_analyzer->summarize_template_part_block_tree( $blocks );
		$operation_targets      = $this->template_structure_analyzer->collect_post_operation_targets( $blocks );
		$insertion_anchors      = $this->template_structure_analyzer->collect_post_insertion_anchors( $operation_targets );
		$structural_constraints = $this->template_structure_analyzer->collect_template_part_structural_constraints( $blocks );
		$summary_stats          = $this->template_structure_analyzer->collect_template_part_block_stats( $blocks );
		$top_level_blocks       = array_values(
			array_filter(
				array_map(
					static fn( array $block ): string => (string) ( $block['blockName'] ?? '' ),
					array_filter( $blocks, 'is_array' )
				),
				static fn( string $name ): bool => '' !== $name
			)
		);

		return [
			'postId'                => (int) $post->ID,
			'postType'              => (string) ( $post->post_type ?? '' ),
			'postStatus'            => (string) ( $post->post_status ?? '' ),
			'title'                 => sanitize_text_field( (string) ( $post->post_title ?? '' ) ),
			'blockTree'             => $block_tree,
			'topLevelBlocks'        => $top_level_blocks,
			'blockCounts'           => $summary_stats['blockCounts'] ?? [],
			'structureStats'        => [
				'blockCount' => $summary_stats['blockCount'] ?? 0,
				'maxDepth'   => $summary_stats['maxDepth'] ?? 0,
			],
			'operationTargets'      => $operation_targets,
			'insertionAnchors'      => $insertion_anchors,
			'structuralConstraints' => $structural_constraints,
			'patterns'              => $this->pattern_candidate_selector->collect_template_candidate_patterns( null ),
			'themeTokens'           => $this->theme_token_collector->for_tokens(),
			'baselineContentHash'   => self::content_hash( $content ),
		];
	}

	/**
	 * Re-resolve the live post for a governed external apply. Fails closed on
	 * anything outside the post/page + editable-status allowlist (including
	 * password-protected posts, matching PostVoiceSampleCollector's exclusion).
	 *
	 * @return \WP_Post|\WP_Error
	 */
	public function resolve_post_for_apply( int $post_id ): object {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested post is not available on this site.',
				[ 'status' => 404 ]
			);
		}

		if ( ! in_array( (string) ( $post->post_type ?? '' ), self::ALLOWED_POST_TYPES, true ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'Post-blocks applies support posts and pages only.',
				[ 'status' => 409 ]
			);
		}

		if ( ! in_array( (string) ( $post->post_status ?? '' ), self::ALLOWED_POST_STATUSES, true ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'The requested post is not in an editable status.',
				[ 'status' => 409 ]
			);
		}

		if ( '' !== (string) ( $post->post_password ?? '' ) ) {
			return new \WP_Error(
				'flavor_agent_apply_target_unavailable',
				'Password-protected posts are not supported for post-blocks applies.',
				[ 'status' => 409 ]
			);
		}

		return $post;
	}

	/**
	 * Drift-baseline recipe shared with PostBlocksApplyExecutor::resolve_baseline:
	 * sha256 of the parsed -> reserialized content so insignificant serialization
	 * differences never read as drift.
	 */
	public static function content_hash( string $content ): string {
		return hash( 'sha256', serialize_blocks( parse_blocks( $content ) ) );
	}
}
