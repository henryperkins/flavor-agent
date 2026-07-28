<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Contract for a governed external-apply surface executor. The decision
 * service and the undo ability dispatch to an implementation by surface.
 */
interface ExternalApplyExecutor {

	/**
	 * Authorize the current user against the executor's ACTUAL write target.
	 *
	 * Row-level permission checks authorize the `document` scope, but every
	 * executor writes what `target` names, and the two are independent
	 * caller-supplied values. Where the surface's capability is per-object --
	 * post-blocks maps to `edit_post:N` -- a row whose document names a post
	 * the caller may edit and whose target names one they may not is a
	 * privilege escalation. Callers must run this immediately before dispatch;
	 * it is the only check that sees the target the write will actually use.
	 *
	 * @param array<string, mixed> $entry Hydrated activity entry.
	 */
	public static function authorize_target( array $entry ): true|\WP_Error;

	/** Re-resolve the live subject and return the drift baseline string for gate 2. */
	public static function resolve_baseline( array $entry ): string|\WP_Error;

	/** @return array{target: array<string,mixed>, before: array<string,mixed>, after: array<string,mixed>}|\WP_Error */
	public static function execute( array $entry ): array|\WP_Error;

	/** @return array{result: string, after?: array<string, mixed>}|\WP_Error */
	public static function undo( array $entry ): array|\WP_Error;
}
