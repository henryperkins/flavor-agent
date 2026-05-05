<?php

declare(strict_types=1);

namespace FlavorAgent\Guidelines;

final class PromptGuidelinesFormatter {

	/**
	 * @param array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>} $guidelines
	 * @param array<int, string>|null $categories
	 */
	public static function format( array $guidelines, string $block_name = '', ?array $categories = null ): string {
		$lines              = [];
		$allowed_categories = null === $categories
			? null
			: array_flip(
				array_values(
					array_filter(
						$categories,
						static fn ( string $category ): bool => '' !== $category
					)
				)
			);

		foreach (
			[
				'site'       => 'Site',
				'copy'       => 'Copy',
				'images'     => 'Images',
				'additional' => 'Additional',
			] as $category => $label
		) {
			if ( null !== $allowed_categories && ! isset( $allowed_categories[ $category ] ) ) {
				continue;
			}

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
