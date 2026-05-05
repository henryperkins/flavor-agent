<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

final class WorkersAIEmbeddingConfiguration {

	public const PROVIDER         = 'cloudflare_workers_ai';
	public const DEFAULT_MODEL    = '@cf/qwen/qwen3-embedding-0.6b';
	public const MAX_BATCH_INPUTS = 32;

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function get( array $overrides = [] ): array {
		$account_id = self::option_value( $overrides, 'flavor_agent_cloudflare_workers_ai_account_id' );
		$api_token  = self::option_value( $overrides, 'flavor_agent_cloudflare_workers_ai_api_token' );
		$model      = self::option_value( $overrides, 'flavor_agent_cloudflare_workers_ai_embedding_model' );

		if ( '' === $model ) {
			$model = self::DEFAULT_MODEL;
		}

		$endpoint = '' !== $account_id
			? sprintf( 'https://api.cloudflare.com/client/v4/accounts/%s/ai/v1', rawurlencode( $account_id ) )
			: '';

		return [
			'provider'   => self::PROVIDER,
			'endpoint'   => $endpoint,
			'api_key'    => $api_token,
			'model'      => $model,
			'configured' => '' !== $account_id && '' !== $api_token && '' !== $model,
			'headers'    => [
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			],
			'url'        => '' !== $endpoint ? $endpoint . '/embeddings' : '',
			'label'      => 'Cloudflare Workers AI embeddings',
		];
	}

	/**
	 * @param array<string, string> $overrides
	 */
	private static function option_value( array $overrides, string $option ): string {
		if ( array_key_exists( $option, $overrides ) ) {
			return trim( sanitize_text_field( (string) $overrides[ $option ] ) );
		}

		return trim( sanitize_text_field( (string) get_option( $option, '' ) ) );
	}
}
