<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\NavigationPrompt;
use FlavorAgent\Support\CollectsDocsGuidance;
use FlavorAgent\Support\NormalizesInput;

final class NavigationAbilities {
	use NormalizesInput;

	/**
	 * Recommend navigation structure, overlay behavior, and organization.
	 *
	 * Accepts either a wp_navigation post ID (menuId) or serialized navigation
	 * block markup (navigationMarkup). At least one must be provided.
	 *
	 * @param mixed $input { menuId?: int, navigationMarkup?: string, prompt?: string }
	 * @return array|\WP_Error Suggestions payload or error.
	 */
	public static function recommend_navigation( mixed $input ): array|\WP_Error {
		$input = self::normalize_input( $input );

		$menu_id = isset( $input['menuId'] ) && is_numeric( $input['menuId'] )
			? (int) $input['menuId']
			: 0;
		$markup  = isset( $input['navigationMarkup'] ) && is_string( $input['navigationMarkup'] )
			? $input['navigationMarkup']
			: '';
		$prompt  = isset( $input['prompt'] ) && is_string( $input['prompt'] )
			? sanitize_textarea_field( $input['prompt'] )
			: '';

		if ( $menu_id <= 0 && $markup === '' ) {
			return new \WP_Error(
				'missing_navigation_input',
				'Either menuId or navigationMarkup is required.',
				[ 'status' => 400 ]
			);
		}

		$context = ServerCollector::for_navigation( $menu_id, $markup );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$system = NavigationPrompt::build_system();
		$user   = NavigationPrompt::build_user(
			$context,
			$prompt,
			self::collect_wordpress_docs_guidance( $context, $prompt )
		);

		$result = ResponsesClient::rank( $system, $user );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return NavigationPrompt::parse_response( $result, $context );
	}

	/**
	 * Normalize Abilities API object inputs to the array shape used internally.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>
	 */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
		return CollectsDocsGuidance::collect(
			static fn( array $request_context, string $request_prompt ): string => self::build_wordpress_docs_query( $request_context, $request_prompt ),
			static fn(): string => 'core/navigation',
			static fn( array $request_context ): array => self::build_wordpress_docs_family_context( $request_context ),
			$context,
			$prompt
		);
	}

	/**
	 * @return string Search query for WordPress docs grounding.
	 */
	private static function build_wordpress_docs_query( array $context, string $prompt ): string {
		$parts = [ 'WordPress navigation block' ];

		$location = (string) ( $context['location'] ?? '' );
		if ( $location !== '' && $location !== 'unknown' ) {
			$parts[] = $location . ' navigation';
		}

		$has_overlay  = ! empty( $context['overlayTemplateParts'] );
		$overlay_attr = $context['attributes']['overlayMenu'] ?? '';
		if ( $has_overlay || ( is_string( $overlay_attr ) && $overlay_attr !== 'never' ) ) {
			$parts[] = 'overlay responsive menu';
		}

		$max_depth = isset( $context['maxDepth'] ) ? (int) $context['maxDepth'] : 0;
		if ( $max_depth > 1 ) {
			$parts[] = 'nested navigation depth';
		}

		if ( ! empty( $context['structureSummary']['hasPageList'] ) ) {
			$parts[] = 'page list navigation';
		}

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'menu structure and organization best practices';

		return implode( '. ', $parts );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_wordpress_docs_family_context( array $context ): array {
		$location       = sanitize_key( (string) ( $context['location'] ?? '' ) );
		$has_overlay    = ! empty( $context['overlayTemplateParts'] );
		$overlay_attr   = $context['attributes']['overlayMenu'] ?? '';
		$uses_overlay   = $has_overlay || ( is_string( $overlay_attr ) && $overlay_attr !== 'never' );
		$family_context = [
			'surface'   => 'navigation',
			'entityKey' => 'core/navigation',
		];

		if ( $location !== '' && $location !== 'unknown' ) {
			$family_context['location'] = $location;
		}

		if ( $uses_overlay ) {
			$family_context['overlay'] = true;
		}

		return $family_context;
	}
}
