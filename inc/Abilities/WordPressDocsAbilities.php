<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Cloudflare\AISearchClient;
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

		$entity_key = isset( $input['entityKey'] ) ? (string) $input['entityKey'] : '';
		$entity_key = AISearchClient::resolve_entity_key( $entity_key, $query );

		if ( $entity_key !== '' ) {
			return AISearchClient::warm_entity( $entity_key, $query, $max_results );
		}

		return AISearchClient::search( $query, $max_results );
	}
}
