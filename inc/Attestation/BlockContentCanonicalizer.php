<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Deterministic canonical bytes + sha256 digest of block content.
 * Single source of truth for the template executors' drift checks and Ring III
 * attestation digests, so the two can never diverge.
 */
final class BlockContentCanonicalizer {

	public static function bytes( string $content ): string {
		return serialize_blocks( parse_blocks( $content ) );
	}

	public static function digest( string $content ): string {
		return hash( 'sha256', self::bytes( $content ) );
	}

	private function __construct() {}
}
