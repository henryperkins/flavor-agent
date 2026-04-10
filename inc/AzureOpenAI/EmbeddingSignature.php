<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

use FlavorAgent\OpenAI\Provider;

final class EmbeddingSignature {

	public const HASH_LENGTH = 16;

	/**
	 * @param array{provider?: mixed, model?: mixed} $config
	 * @return array{provider: string, model: string, dimension: int, signature_hash: string}
	 */
	public static function from_configuration( array $config, int $dimension ): array {
		$payload = [
			'provider'  => Provider::normalize_provider( (string) ( $config['provider'] ?? Provider::get() ) ),
			'model'     => trim( (string) ( $config['model'] ?? '' ) ),
			'dimension' => max( 0, $dimension ),
		];
		$json    = wp_json_encode( $payload );

		$payload['signature_hash'] = hash( 'sha256', false !== $json ? $json : '' );

		return $payload;
	}

	/**
	 * @return array{provider: string, model: string, dimension: int, signature_hash: string}
	 */
	public static function from_runtime( int $dimension ): array {
		return self::from_configuration( Provider::embedding_configuration(), $dimension );
	}

	/**
	 * @param array{signature_hash?: mixed} $signature
	 */
	public static function short_hash( array $signature ): string {
		return substr( (string) ( $signature['signature_hash'] ?? '' ), 0, self::HASH_LENGTH );
	}
}
