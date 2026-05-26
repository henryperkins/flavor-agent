<?php

declare(strict_types=1);

namespace FlavorAgent\Tests\Support;

use WordPress\AI\Abstracts\Abstract_Ability;

final class PreviewRecommendationFakeParentAbility extends Abstract_Ability {

	/** @var array<int, mixed> */
	public static array $executions = [];

	/** @var array<int, mixed> */
	public static array $permission_calls = [];

	public static mixed $execution_result = [];

	public static bool $permission_result = true;

	public static function reset(): void {
		self::$executions        = [];
		self::$permission_calls  = [];
		self::$execution_result  = [];
		self::$permission_result = true;
	}

	public function execute_callback( mixed $input ): mixed {
		self::$executions[] = $input;

		return self::$execution_result;
	}

	public function permission_callback( mixed $input = null ): bool {
		self::$permission_calls[] = $input;

		return self::$permission_result;
	}
}
