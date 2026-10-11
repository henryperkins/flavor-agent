<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns\Retrieval;

use FlavorAgent\Admin\Settings\Config;

final class PatternRetrievalBackendFactory {

	public static function for_runtime_state( array $state ): PatternRetrievalBackend|\WP_Error {
		unset( $state );

		$backend = self::selected_backend();

		return match ( $backend ) {
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH => new CloudflareAISearchPatternRetrievalBackend(),
			default => new \WP_Error(
				'unsupported_pattern_retrieval_backend',
				'Unsupported pattern store setting. Save Cloudflare AI Search settings in Settings > Flavor Agent, then sync the pattern catalog.',
				[ 'status' => 400 ]
			),
		};
	}

	public static function selected_backend(): string {
		$value   = get_option( Config::OPTION_PATTERN_RETRIEVAL_BACKEND, '' );
		$backend = is_string( $value ) ? $value : 'unsupported_backend';

		// Selector compatibility does not establish the provenance of an index.
		// Unknown values stay blocked until an administrator explicitly saves
		// valid Cloudflare settings.
		return in_array( $backend, [ '', 'qdrant' ], true )
			? Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH
			: $backend;
	}
}
