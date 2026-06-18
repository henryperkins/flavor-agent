<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Support\DocsGuidanceResult;
use FlavorAgent\Support\NormalizesInput;

final class WordPressDocsAbilities {
	use NormalizesInput;

	public const REQUIRED_CAPABILITY = 'manage_options';

	public static function can_search_wordpress_docs(): bool {
		return current_user_can( self::REQUIRED_CAPABILITY );
	}

	public static function search_wordpress_docs( mixed $input ): array|\WP_Error {
		$input = self::normalize_input( $input );
		$query = isset( $input['query'] ) ? sanitize_textarea_field( (string) $input['query'] ) : '';

		if ( $query === '' ) {
			return new \WP_Error(
				'missing_query',
				'A query is required.',
				[ 'status' => 400 ]
			);
		}

		$max_results = null;
		if ( isset( $input['maxResults'] ) ) {
			$max_results = max( 1, min( 8, (int) $input['maxResults'] ) );
		}

		$result = AISearchClient::search( $query, $max_results );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$docs_grounding = DocsGuidanceResult::from_guidance(
			is_array( $result['guidance'] ?? null ) ? $result['guidance'] : [],
			'direct',
			'live'
		);

		return [
			'query'                    => (string) ( $result['query'] ?? $query ),
			'guidance'                 => DocsGuidanceResult::guidance( $docs_grounding ),
			'docsGrounding'            => DocsGuidanceResult::public_summary( $docs_grounding ),
			'docsGroundingFingerprint' => DocsGuidanceResult::content_fingerprint( $docs_grounding ),
		];
	}
}
