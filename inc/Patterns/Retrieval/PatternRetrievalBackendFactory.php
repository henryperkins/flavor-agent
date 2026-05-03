<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns\Retrieval;

use FlavorAgent\Admin\Settings\Config;

final class PatternRetrievalBackendFactory {

	public static function for_runtime_state( array $state ): PatternRetrievalBackend|\WP_Error {
		unset( $state );

		$backend = sanitize_key(
			(string) get_option(
				Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
				Config::PATTERN_BACKEND_QDRANT
			)
		);

		return match ( $backend ) {
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH => new CloudflareAISearchPatternRetrievalBackend(),
			Config::PATTERN_BACKEND_QDRANT, '' => new QdrantPatternRetrievalBackend(),
			default => new \WP_Error(
				'unsupported_pattern_retrieval_backend',
				'Unsupported pattern retrieval backend. Choose Qdrant or Cloudflare AI Search in Settings > Flavor Agent.',
				[ 'status' => 400 ]
			),
		};
	}

	public static function selected_backend(): string {
		$backend = sanitize_key(
			(string) get_option(
				Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
				Config::PATTERN_BACKEND_QDRANT
			)
		);

		return in_array( $backend, Config::PATTERN_BACKENDS, true )
			? $backend
			: Config::PATTERN_BACKEND_QDRANT;
	}
}
