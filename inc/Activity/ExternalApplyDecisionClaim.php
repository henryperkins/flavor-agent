<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/**
 * Canonical format rules for the internal external-apply decision owner claim.
 */
final class ExternalApplyDecisionClaim {

	public const PREFIX          = 'claim:';
	public const SQL_HEX_PATTERN = '^636C61696D3A(3[0-9]|6[1-6]){24}$';

	private const ACTIVE_PATTERN = '/^claim:[a-f0-9]{24}$/D';

	public static function is_active( string $value ): bool {
		return 1 === preg_match( self::ACTIVE_PATTERN, $value );
	}

	public static function has_normalized_prefix( string $value ): bool {
		return 0 === strncasecmp( trim( $value ), self::PREFIX, strlen( self::PREFIX ) );
	}

	private function __construct() {}
}
