<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Maps an activity surface to its governed external-apply executor. The
 * decision service and the undo ability dispatch through this seam so new
 * external-apply surfaces only register an arm here.
 *
 * Kept in its own file (one class per file) so the PSR-4 autoloader resolves
 * it on a cold reference; both call sites look it up before any executor
 * class has been loaded.
 */
final class ExternalApplyExecutorRegistry {

	/** @return class-string<ExternalApplyExecutor>|null */
	public static function for_surface( string $surface ): ?string {
		return match ( $surface ) {
			'global-styles', 'style-book' => StyleApplyExecutor::class,
			'template-part'               => TemplatePartApplyExecutor::class,
			'template'                    => TemplateApplyExecutor::class,
			'post-blocks'                 => PostBlocksApplyExecutor::class,
			default                       => null,
		};
	}

	private function __construct() {}
}
