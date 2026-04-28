<?php

declare(strict_types=1);

namespace FlavorAgent\Guidelines;

final class LegacyGuidelinesRepository implements GuidelinesRepository {

	public function source(): string {
		return 'legacy_options';
	}

	/**
	 * @return array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>}
	 */
	public function get_all(): array {
		return [
			'site'       => $this->get_text_option( \FlavorAgent\Guidelines::OPTION_SITE ),
			'copy'       => $this->get_text_option( \FlavorAgent\Guidelines::OPTION_COPY ),
			'images'     => $this->get_text_option( \FlavorAgent\Guidelines::OPTION_IMAGES ),
			'additional' => $this->get_text_option( \FlavorAgent\Guidelines::OPTION_ADDITIONAL ),
			'blocks'     => \FlavorAgent\Guidelines::sanitize_block_guidelines(
				get_option( \FlavorAgent\Guidelines::OPTION_BLOCKS, [] )
			),
		];
	}

	private function get_text_option( string $option_name ): string {
		return \FlavorAgent\Guidelines::sanitize_guideline_text( get_option( $option_name, '' ) );
	}
}
