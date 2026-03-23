<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\NavigationPrompt;

final class NavigationAbilities {

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
	private static function normalize_input( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		return is_array( $input ) ? $input : [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_wordpress_docs_guidance( array $context, string $prompt ): array {
		$query      = self::build_wordpress_docs_query( $context, $prompt );
		$entity_key = 'core/navigation';

		return AISearchClient::maybe_search_with_entity_fallback( $query, $entity_key );
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

		if ( $prompt !== '' ) {
			$parts[] = $prompt;
		}

		$parts[] = 'menu structure and organization best practices';

		return implode( '. ', $parts );
	}
}
