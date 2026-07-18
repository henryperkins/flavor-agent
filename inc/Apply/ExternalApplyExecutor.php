<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Contract for a governed external-apply surface executor. The decision
 * service and the undo ability dispatch to an implementation by surface.
 */
interface ExternalApplyExecutor {

	/** Re-resolve the live subject and return the drift baseline string for gate 2. */
	public static function resolve_baseline( array $entry ): string|\WP_Error;

	/** @return array{target: array<string,mixed>, before: array<string,mixed>, after: array<string,mixed>}|\WP_Error */
	public static function execute( array $entry ): array|\WP_Error;

	/** @return array{result: string, after?: array<string, mixed>}|\WP_Error */
	public static function undo( array $entry ): array|\WP_Error;
}
