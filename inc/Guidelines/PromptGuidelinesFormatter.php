<?php

declare(strict_types=1);

namespace FlavorAgent\Guidelines;

final class PromptGuidelinesFormatter {

	/**
	 * @param array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>} $guidelines
	 */
	public static function format( array $guidelines, string $block_name = '' ): string {
		$lines = [];

		foreach (
			[
				'site'       => 'Site',
				'copy'       => 'Copy',
				'images'     => 'Images',
				'additional' => 'Additional',
			] as $category => $label
		) {
			$value = trim( (string) ( $guidelines[ $category ] ?? '' ) );

			if ( '' !== $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}

		$blocks = is_array( $guidelines['blocks'] ?? null ) ? $guidelines['blocks'] : [];

		if ( '' !== $block_name && isset( $blocks[ $block_name ] ) ) {
			$lines[] = 'Block ' . $block_name . ': ' . trim( (string) $blocks[ $block_name ] );
		} elseif ( '' === $block_name && [] !== $blocks ) {
			foreach ( array_slice( $blocks, 0, 12, true ) as $name => $value ) {
				$guideline = trim( (string) $value );

				if ( '' !== $guideline ) {
					$lines[] = 'Block ' . (string) $name . ': ' . $guideline;
				}
			}
		}

		if ( [] === $lines ) {
			return '';
		}

		return "## Site Guidelines\n" . implode( "\n", $lines );
	}
}
