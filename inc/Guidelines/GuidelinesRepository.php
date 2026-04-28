<?php

declare(strict_types=1);

namespace FlavorAgent\Guidelines;

interface GuidelinesRepository {

	/**
	 * @return array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>}
	 */
	public function get_all(): array;

	public function source(): string;
}
