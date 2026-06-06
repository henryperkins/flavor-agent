<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns;

use FlavorAgent\Support\StringArray;

final class PatternDesignMetadata {

	/**
	 * @param array<string, mixed> $pattern
	 * @return array<string, mixed>
	 */
	public static function extract( array $pattern ): array {
		$content    = is_string( $pattern['content'] ?? null ) ? $pattern['content'] : '';
		$categories = StringArray::sanitize( $pattern['categories'] ?? [] );
		$traits     = PatternIndex::infer_layout_traits( $pattern );

		return [
			'sectionRole'          => self::section_role( $content, $categories, $traits ),
			'layoutShape'          => self::layout_shape( $content ),
			'visualDensity'        => self::visual_density( $content ),
			'colorMood'            => self::color_mood( $content ),
			'typographyRole'       => self::typography_role( $content, $categories ),
			'interactionRole'      => self::interaction_role( $content ),
			'templateAreaAffinity' => self::template_area_affinity( $categories, $traits ),
			'styleTokenUsage'      => self::style_token_usage( $content ),
			'blockComplexity'      => self::block_complexity( $content ),
			'contentSpecificity'   => self::content_specificity( $content ),
		];
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public static function summarize( array $metadata ): string {
		$parts = [];

		foreach ( $metadata as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( [] !== $value ) {
					$parts[] = $key . '=' . implode( '|', array_map( 'strval', $value ) );
				}
				continue;
			}

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$parts[] = $key . '=' . (string) $value;
			}
		}

		return implode( '; ', $parts );
	}

	/**
	 * @param string[] $categories
	 * @param string[] $traits
	 */
	private static function section_role( string $content, array $categories, array $traits ): string {
		$category_set = array_fill_keys( $categories, true );
		$trait_set    = array_fill_keys( $traits, true );

		if ( isset( $category_set['header'] ) || str_contains( $content, 'wp:navigation' ) || str_contains( $content, 'wp:site-title' ) ) {
			return 'header';
		}
		if ( isset( $category_set['footer'] ) ) {
			return 'footer';
		}
		if ( isset( $trait_set['pricing'] ) ) {
			return 'pricing';
		}
		if ( isset( $trait_set['testimonial'] ) ) {
			return 'testimonial';
		}
		if ( isset( $trait_set['query-loop'] ) ) {
			return 'query-listing';
		}
		if ( isset( $trait_set['hero-banner'] ) ) {
			return 'hero';
		}
		if ( str_contains( $content, 'wp:buttons' ) ) {
			return 'cta';
		}

		return 'unknown';
	}

	private static function layout_shape( string $content ): string {
		if ( str_contains( $content, 'wp:cover' ) ) {
			return 'cover';
		}
		if ( str_contains( $content, 'wp:media-text' ) ) {
			return 'media-left';
		}
		if ( str_contains( $content, 'wp:columns' ) ) {
			$column_count = (int) preg_match_all( '/<!--\s+wp:column\b/', $content );

			return $column_count > 2 ? 'grid' : 'two-column';
		}
		if ( str_contains( $content, 'wp:group' ) ) {
			return 'single-column';
		}

		return 'unknown';
	}

	private static function visual_density( string $content ): string {
		$count = substr_count( $content, '<!-- wp:' );

		if ( $count <= 3 ) {
			return 'sparse';
		}
		if ( $count <= 10 ) {
			return 'balanced';
		}

		return 'dense';
	}

	private static function color_mood( string $content ): string {
		$lower = strtolower( $content );

		if ( str_contains( $lower, 'backgroundcolor":"contrast' ) || str_contains( $lower, 'dimratio":70' ) || str_contains( $lower, 'dimratio":80' ) || str_contains( $lower, 'dimratio":90' ) ) {
			return 'dark';
		}
		if ( str_contains( $lower, 'backgroundcolor":"base' ) || str_contains( $lower, 'backgroundcolor":"white' ) ) {
			return 'light';
		}
		if ( str_contains( $lower, 'backgroundcolor":"accent' ) ) {
			return 'accent';
		}
		if ( str_contains( $lower, 'wp:image' ) || str_contains( $lower, 'wp:cover' ) || str_contains( $lower, 'wp:gallery' ) ) {
			return 'image-heavy';
		}

		return 'unknown';
	}

	/**
	 * @param string[] $categories
	 */
	private static function typography_role( string $content, array $categories ): string {
		if ( str_contains( $content, 'wp:post-title' ) || str_contains( $content, 'wp:post-date' ) || in_array( 'query', $categories, true ) ) {
			return 'metadata-heavy';
		}
		if ( str_contains( $content, 'wp:heading' ) && str_contains( $content, 'wp:buttons' ) ) {
			return 'marketing';
		}
		if ( str_contains( $content, 'wp:navigation' ) ) {
			return 'navigation';
		}
		if ( str_contains( $content, 'wp:paragraph' ) ) {
			return 'editorial';
		}

		return 'unknown';
	}

	private static function interaction_role( string $content ): string {
		if ( str_contains( $content, 'wp:navigation' ) ) {
			return 'navigation';
		}
		if ( str_contains( $content, 'wp:search' ) || str_contains( $content, 'wp:form' ) ) {
			return 'form';
		}
		if ( str_contains( $content, 'wp:social-links' ) || str_contains( $content, 'wp:buttons' ) ) {
			return 'link-collection';
		}

		return 'static-content';
	}

	/**
	 * @param string[] $categories
	 * @param string[] $traits
	 */
	private static function template_area_affinity( array $categories, array $traits ): string {
		if ( in_array( 'header', $categories, true ) ) {
			return 'header';
		}
		if ( in_array( 'footer', $categories, true ) ) {
			return 'footer';
		}
		if ( in_array( 'site-chrome', $traits, true ) ) {
			return 'root';
		}

		return 'content';
	}

	/**
	 * @return string[]
	 */
	private static function style_token_usage( string $content ): array {
		preg_match_all( '/var:preset\|(?:color|spacing|font-size|font-family|shadow|duotone)\|([a-z0-9_-]+)/i', $content, $matches );
		preg_match_all( '/wp--preset--(?:color|spacing|font-size|font-family|shadow|duotone)--([a-z0-9_-]+)/i', $content, $css_matches );
		preg_match_all( '/"(?:backgroundColor|textColor|gradient|fontSize|fontFamily|style|duotone)"\s*:\s*"([a-z0-9_-]+)"/i', $content, $attribute_matches );

		return array_values(
			array_unique(
				array_filter(
					array_merge( $matches[1] ?? [], $css_matches[1] ?? [], $attribute_matches[1] ?? [] ),
					static fn( string $token ): bool => '' !== $token
				)
			)
		);
	}

	private static function block_complexity( string $content ): string {
		$count = substr_count( $content, '<!-- wp:' );

		if ( $count <= 3 ) {
			return 'low';
		}
		if ( $count <= 10 ) {
			return 'medium';
		}

		return 'high';
	}

	private static function content_specificity( string $content ): string {
		$text = function_exists( 'strip_shortcodes' ) ? \strip_shortcodes( $content ) : $content;
		$text = function_exists( 'wp_strip_all_tags' ) ? \wp_strip_all_tags( $text ) : strip_tags( $text );

		return preg_match( '/\b(pricing|testimonial|portfolio|contact|team|event|menu|product)\b/i', $text ) ? 'topic-specific' : 'generic';
	}
}
