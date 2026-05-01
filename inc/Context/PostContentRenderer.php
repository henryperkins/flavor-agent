<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PostContentRenderer {

	private const MAX_ATTR_LENGTH = 500;

	private const MAX_ATTR_COUNT = 100;

	private const ALLOWED_HREF_SCHEMES = [ 'http://', 'https://', 'mailto:', 'tel:' ];

	/**
	 * @param array<string, mixed> $context
	 */
	public function extract( string $post_content, array $context = [] ): string {
		$post_content = str_replace( "\r", '', $post_content );
		$post_id      = (int) ( $context['postId'] ?? 0 );

		if ( $post_id <= 0 ) {
			return self::fallback( $post_content );
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return self::fallback( $post_content );
		}

		$blocks = parse_blocks( $post_content );
		if ( [] === $blocks ) {
			return self::fallback( $post_content );
		}

		[ $stripped_chunks, $rendered_html ] = $this->render_with_globals( $blocks, $post, $context );

		$visible    = trim( implode( "\n\n", array_filter( array_map( 'trim', $stripped_chunks ) ) ) );
		$attributes = $this->extract_html_attributes( $rendered_html );
		$attributes = $this->dedupe_against( $attributes, $visible );

		if ( '' === $visible && [] === $attributes ) {
			return self::fallback( $post_content );
		}

		return $this->assemble_output( $visible, $attributes );
	}

	/**
	 * @param array<int, mixed>    $blocks
	 * @param array<string, mixed> $context
	 * @return array{0: array<int, string>, 1: string}
	 */
	private function render_with_globals( array $blocks, \WP_Post $post, array $context ): array {
		$had_global    = array_key_exists( 'post', $GLOBALS );
		$original_post = $had_global ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$stripped_chunks = [];
		$rendered_html   = '';

		try {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				$block_name = (string) ( $block['blockName'] ?? '' );

				if ( 'core/post-content' === $block_name ) {
					continue;
				}

				if ( 'core/post-title' === $block_name ) {
					$staged = (string) ( $context['stagedTitle'] ?? '' );
					if ( '' !== $staged ) {
						$stripped_chunks[] = $staged;
					}
					continue;
				}

				if ( 'core/post-excerpt' === $block_name ) {
					$staged = (string) ( $context['stagedExcerpt'] ?? '' );
					if ( '' !== $staged ) {
						$stripped_chunks[] = $staged;
					}
					continue;
				}

				try {
					$rendered = (string) render_block( $block );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surface third-party render failures without aborting recommendations.
					error_log(
						sprintf(
							'[flavor-agent] PostContentRenderer: render_block failed for %s - %s',
							'' !== $block_name ? $block_name : 'freeform',
							$e->getMessage()
						)
					);
					$stripped_chunks[] = sprintf(
						'[block render failed: %s]',
						'' !== $block_name ? $block_name : 'freeform'
					);
					continue;
				}

				$rendered_html    .= $rendered;
				$stripped_chunks[] = $this->strip_block_html( $rendered );
			}
		} finally {
			if ( $had_global ) {
				$GLOBALS['post'] = $original_post;
				if ( $original_post instanceof \WP_Post ) {
					setup_postdata( $original_post );
				} else {
					wp_reset_postdata();
				}
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			}
		}

		return [ $stripped_chunks, $rendered_html ];
	}

	private function strip_block_html( string $html ): string {
		$with_breaks = preg_replace(
			'#</(p|div|h[1-6]|li|tr|td|th|blockquote|article|section|aside|header|footer|main|figure|figcaption|nav|ul|ol|table)\b[^>]*>#i',
			"\n$0",
			$html
		);

		if ( null === $with_breaks ) {
			$with_breaks = $html;
		}

		$with_breaks = preg_replace(
			'#<(br|hr)\b[^>]*/?>#i',
			"\n$0\n",
			$with_breaks
		) ?? $with_breaks;

		return trim( wp_strip_all_tags( $with_breaks ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function extract_html_attributes( string $rendered_html ): array {
		if ( '' === $rendered_html ) {
			return [];
		}

		if ( ! class_exists( \DOMDocument::class ) || ! class_exists( \DOMXPath::class ) ) {
			return [];
		}

		$doc             = new \DOMDocument();
		$previous_libxml = libxml_use_internal_errors( true );

		try {
			$loaded = $doc->loadHTML(
				'<?xml encoding="UTF-8"?><div>' . $rendered_html . '</div>',
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);

			if ( ! $loaded ) {
				return [];
			}

			$xpath   = new \DOMXPath( $doc );
			$strings = [];

			$append = function ( string $value ) use ( &$strings ): bool {
				if ( count( $strings ) >= self::MAX_ATTR_COUNT ) {
					return false;
				}

				$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) ?? $value;
				$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;
				$value = trim( $value );

				if ( '' === $value ) {
					return true;
				}

				$value = self::truncate_attribute_value( $value );

				$strings[] = $value;

				return true;
			};

			foreach ( [ 'alt', 'title', 'aria-label' ] as $attr ) {
				if ( count( $strings ) >= self::MAX_ATTR_COUNT ) {
					break;
				}

				$nodes = $xpath->query( '//*[@' . $attr . ']' );
				if ( false === $nodes ) {
					continue;
				}

				foreach ( $nodes as $node ) {
					if ( ! ( $node instanceof \DOMElement ) ) {
						continue;
					}

					if ( ! $append( $node->getAttribute( $attr ) ) ) {
						break 2;
					}
				}
			}

			if ( count( $strings ) < self::MAX_ATTR_COUNT ) {
				$href_nodes = $xpath->query( '//a[@href]' );
				if ( false !== $href_nodes ) {
					foreach ( $href_nodes as $node ) {
						if ( ! ( $node instanceof \DOMElement ) ) {
							continue;
						}

						$href = trim( $node->getAttribute( 'href' ) );
						if ( '' === $href || '#' === ( $href[0] ?? '' ) ) {
							continue;
						}

						if ( ! $this->is_allowed_href_scheme( $href ) ) {
							continue;
						}

						if ( ! $append( $href ) ) {
							break;
						}
					}
				}
			}

			return array_values( array_unique( $strings ) );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml );
		}
	}

	private function is_allowed_href_scheme( string $href ): bool {
		foreach ( self::ALLOWED_HREF_SCHEMES as $scheme ) {
			if ( 0 === stripos( $href, $scheme ) ) {
				return true;
			}
		}

		return ! preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $href );
	}

	private static function truncate_attribute_value( string $value ): string {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value, 'UTF-8' ) > self::MAX_ATTR_LENGTH
				? mb_substr( $value, 0, self::MAX_ATTR_LENGTH, 'UTF-8' ) . '…'
				: $value;
		}

		return strlen( $value ) > self::MAX_ATTR_LENGTH
			? substr( $value, 0, self::MAX_ATTR_LENGTH ) . '…'
			: $value;
	}

	/**
	 * @param array<int, string> $attributes
	 * @return array<int, string>
	 */
	private function dedupe_against( array $attributes, string $visible ): array {
		if ( '' === $visible || [] === $attributes ) {
			return $attributes;
		}

		$lower_visible = strtolower( $visible );

		return array_values(
			array_filter(
				$attributes,
				static fn ( string $attr ): bool => '' !== $attr
					&& false === strpos( $lower_visible, strtolower( $attr ) )
			)
		);
	}

	/**
	 * @param array<int, string> $attributes
	 */
	private function assemble_output( string $visible, array $attributes ): string {
		if ( '' !== $visible && [] !== $attributes ) {
			return $visible
				. "\n\n[Attribute references]\n- "
				. implode( "\n- ", $attributes );
		}

		if ( '' !== $visible ) {
			return $visible;
		}

		if ( [] !== $attributes ) {
			return "[Attribute references]\n- " . implode( "\n- ", $attributes );
		}

		return '';
	}

	private static function fallback( string $post_content ): string {
		return sanitize_textarea_field( $post_content );
	}
}
