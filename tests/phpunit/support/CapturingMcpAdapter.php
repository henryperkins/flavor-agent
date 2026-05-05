<?php

declare(strict_types=1);

namespace FlavorAgent\Tests\Support;

final class CapturingMcpAdapter {
	/** @var array<int, array<int, mixed>> */
	public array $calls = [];

	public function create_server( mixed ...$args ): self {
		$this->calls[] = $args;

		return $this;
	}
}
