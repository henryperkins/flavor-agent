<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns\Retrieval;

interface PatternRetrievalBackend {

	/**
	 * @param string[]             $visible_pattern_names
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $context
	 * @return array<int, array{payload: array<string, mixed>, score: float}>|\WP_Error
	 */
	public function search( string $query, array $visible_pattern_names, array $state, array $context ): array|\WP_Error;
}
