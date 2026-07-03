<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\PostBlocksPrompt;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\DocsGuidanceResult;
use FlavorAgent\Support\NormalizesInput;
use FlavorAgent\Support\RecommendationResolvedSignature;
use FlavorAgent\Support\RecommendationReviewSignature;

/**
 * Handler for the post-blocks recommendation surface: structural suggestions
 * (insert_pattern / replace_block_with_pattern / remove_block) over one post
 * or page document's block tree, using a server-collected context only — no
 * client-supplied tree is trusted for this surface.
 */
final class PostBlocksAbilities {
	use NormalizesInput;

	private const REVIEW_PATTERN_LIMIT = 30;

	/**
	 * Recommend structural improvements for a single post/page document.
	 *
	 * @param array $input { postId: int, prompt?: string, resolveSignatureOnly?: bool }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_post_blocks( mixed $input ): array|\WP_Error {
		$input                  = self::normalize_input( $input );
		$resolve_signature_only = filter_var(
			$input['resolveSignatureOnly'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		);
		$post_id                = (int) ( $input['postId'] ?? 0 );
		$prompt                 = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'missing_post_id',
				'A postId is required.',
				[ 'status' => 400 ]
			);
		}

		$context = ServerCollector::for_post_blocks( $post_id );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$docs_result                = self::collect_post_blocks_wordpress_docs_guidance_result(
			$context,
			$prompt,
			[
				'signatureOnly' => $resolve_signature_only,
			]
		);
		$docs_grounding_fingerprint = DocsGuidanceResult::content_fingerprint( $docs_result );

		$resolved_context_signature = RecommendationResolvedSignature::from_payload(
			'post-blocks',
			[
				'context'                  => $context,
				'prompt'                   => $prompt,
				'docsGroundingFingerprint' => $docs_grounding_fingerprint,
			]
		);

		$review_context_signature = self::build_post_blocks_review_context_signature(
			$context,
			$docs_grounding_fingerprint
		);

		if ( $resolve_signature_only ) {
			return [
				'reviewContextSignature'   => $review_context_signature,
				'resolvedContextSignature' => $resolved_context_signature,
				'docsGrounding'            => DocsGuidanceResult::public_summary( $docs_result ),
				'docsGroundingFingerprint' => $docs_grounding_fingerprint,
			];
		}

		$docs_guidance = DocsGuidanceResult::guidance( $docs_result );
		$system        = PostBlocksPrompt::build_system();
		$user          = PostBlocksPrompt::build_user(
			$context,
			$prompt,
			$docs_guidance
		);

		$result = ResponsesClient::rank(
			$system,
			$user,
			null,
			ResponseSchema::get( 'post_blocks' ),
			'flavor_agent_post_blocks'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = PostBlocksPrompt::parse_response(
			$result,
			$context,
			[
				'surface'       => 'post-blocks',
				'context'       => $context,
				'prompt'        => $prompt,
				'docsGrounding' => DocsGuidanceResult::public_summary( $docs_result ),
			]
		);

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['operationTargets']         = is_array( $context['operationTargets'] ?? null ) ? $context['operationTargets'] : [];
		$payload['insertionAnchors']         = is_array( $context['insertionAnchors'] ?? null ) ? $context['insertionAnchors'] : [];
		$payload['reviewContextSignature']   = $review_context_signature;
		$payload['resolvedContextSignature'] = $resolved_context_signature;
		$payload['docsGrounding']            = DocsGuidanceResult::public_summary( $docs_result );
		$payload['docsGroundingFingerprint'] = $docs_grounding_fingerprint;

		return $payload;
	}

	/**
	 * Review-context signature over the durable review identity: the document,
	 * the candidate patterns, theme tokens, and the docs-grounding fingerprint.
	 */
	private static function build_post_blocks_review_context_signature( array $context, string $docs_grounding_fingerprint = '' ): string {
		$patterns   = [];
		$candidates = is_array( $context['patterns'] ?? null ) ? $context['patterns'] : [];

		foreach ( array_slice( $candidates, 0, self::REVIEW_PATTERN_LIMIT ) as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $pattern['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$patterns[] = [
				'name'        => $name,
				'title'       => sanitize_text_field( (string) ( $pattern['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $pattern['description'] ?? '' ) ),
			];
		}

		return RecommendationReviewSignature::from_payload(
			'post-blocks',
			[
				'document'                 => [
					'postId'   => (int) ( $context['postId'] ?? 0 ),
					'postType' => sanitize_key( (string) ( $context['postType'] ?? '' ) ),
					'title'    => sanitize_text_field( (string) ( $context['title'] ?? '' ) ),
				],
				'patterns'                 => $patterns,
				'themeTokens'              => is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : [],
				'docsGroundingFingerprint' => sanitize_text_field( $docs_grounding_fingerprint ),
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function collect_post_blocks_wordpress_docs_guidance_result( array $context, string $prompt, array $options = [] ): array {
		return CollectsDocsGuidance::collect_result(
			static fn( array $request_context, string $request_prompt ): string => self::build_post_blocks_wordpress_docs_query( $request_context, $request_prompt ),
			$context,
			$prompt,
			[ 'mode' => empty( $options['signatureOnly'] ) ? 'recommendation' : 'signature' ]
		);
	}

	private static function build_post_blocks_wordpress_docs_query( array $context, string $prompt ): string {
		$top_level_blocks = is_array( $context['topLevelBlocks'] ?? null ) ? $context['topLevelBlocks'] : [];
		$post_type        = sanitize_key( (string) ( $context['postType'] ?? '' ) );
		$parts            = [ 'WordPress block editor post content structure and pattern best practices' ];

		if ( '' !== $post_type ) {
			$parts[] = "post type {$post_type}";
		}

		if ( [] !== $top_level_blocks ) {
			$parts[] = 'top-level blocks ' . implode(
				', ',
				array_slice(
					array_map( 'sanitize_text_field', $top_level_blocks ),
					0,
					6
				)
			);
		}

		$prompt = sanitize_text_field( $prompt );

		if ( '' !== $prompt ) {
			$parts[] = $prompt;
		}

		return implode( '; ', $parts );
	}
}
