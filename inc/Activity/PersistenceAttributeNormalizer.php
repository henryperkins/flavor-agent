<?php
declare(strict_types=1);

namespace FlavorAgent\Activity;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API properties have standardized names.

/** Reads saved attributes using the complete schema frozen with a save. */
final class PersistenceAttributeNormalizer {

	/**
	 * A missing attribute is omitted from attributes; an unsupported extraction is
	 * an error, never a missing value. The live block registry is intentionally unused.
	 *
	 * @param array<string,mixed> $block Parsed saved block.
	 * @param array<string,array> $schemas Pinned registered attribute schemas.
	 * @param string[]            $keys Attribute names needed by the comparison.
	 * @return array{ok:bool,reason:string,attributes:array}
	 */
	public static function read( array $block, array $schemas, array $keys ): array {
		$name = $block['blockName'] ?? '';
		if ( ! is_string( $name ) || ! isset( $schemas[ $name ] ) || ! is_array( $schemas[ $name ] ) ) {
			return self::failure( 'schema_drift' );
		}

		$attributes = [];
		$root       = null;
		foreach ( array_unique( $keys ) as $key ) {
			$schema = $schemas[ $name ][ $key ] ?? null;
			if ( ! is_array( $schema ) || ( ! isset( $schema['type'] ) && ! isset( $schema['enum'] ) ) ) {
				return self::failure( 'schema_drift' );
			}
			if ( ! self::supported( $schema ) ) {
				return self::failure( 'unsupported_extraction' );
			}
			$source = $schema['source'] ?? null;
			if ( null === $source ) {
				$found = array_key_exists( $key, $block['attrs'] ?? [] );
				$value = $found ? $block['attrs'][ $key ] : null;
			} elseif ( 'raw' === $source ) {
				$found = true;
				$value = (string) ( $block['innerHTML'] ?? '' );
			} else {
				if ( null === $root ) {
					$root = self::html_root( (string) ( $block['innerHTML'] ?? '' ) );
				}
				if ( ! $root ) {
					return self::failure( 'unsupported_extraction' );
				}
				$extracted = self::extract( $root, $schema );
				if ( ! $extracted['ok'] ) {
					return self::failure( 'unsupported_extraction' );
				}
				$found = $extracted['found'];
				$value = $extracted['value'];
			}

			if ( $found && ! self::valid_value( $value, $schema ) ) {
				$found = false;
			}
			if ( ! $found && array_key_exists( 'default', $schema ) ) {
				$found = true;
				$value = $schema['default'];
			}
			if ( ! $found && 'rich-text' === ( $schema['type'] ?? '' ) ) {
				$found = true;
				$value = '';
			}
			if ( $found ) {
				$attributes[ $key ] = $value;
			}
		}
		return [
			'ok'         => true,
			'reason'     => '',
			'attributes' => $attributes,
		];
	}

	/** Normalize an editor's serializable HTML string with the same DOM rules. */
	public static function comparable_value( $value, array $schema ) {
		if ( is_string( $value ) && in_array( $schema['source'] ?? '', [ 'html', 'rich-text' ], true ) ) {
			$root = self::html_root( $value );
			return $root ? self::inner_html( $root ) : $value;
		}
		return $value;
	}

	private static function failure( string $reason ): array {
		return [
			'ok'         => false,
			'reason'     => $reason,
			'attributes' => [],
		];
	}

	private static function supported( array $schema, int $depth = 0 ): bool {
		if ( $depth > 8 || 'local' === ( $schema['role'] ?? '' ) || ! empty( $schema['multiline'] ) ) {
			return false;
		}
		$source = $schema['source'] ?? null;
		if ( ! in_array( $source, [ null, 'raw', 'attribute', 'text', 'html', 'rich-text', 'tag', 'query' ], true ) ) {
			return false;
		}
		if ( isset( $schema['selector'] ) && ( ! is_string( $schema['selector'] ) || null === self::selector_xpath( $schema['selector'] ) ) ) {
			return false;
		}
		if ( 'attribute' === $source && ( ! is_string( $schema['attribute'] ?? null ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_:-]*$/D', $schema['attribute'] ) ) ) {
			return false;
		}
		if ( 'query' === $source ) {
			if ( ! is_array( $schema['query'] ?? null ) || [] === $schema['query'] ) {
				return false;
			}
			foreach ( $schema['query'] as $nested ) {
				if ( ! is_array( $nested ) || ! isset( $nested['source'] ) || ! self::supported( $nested, $depth + 1 ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Support a deliberately bounded CSS subset. Unknown selectors are never
	 * approximated: pseudo classes, sibling combinators and escapes fail closed.
	 */
	private static function selector_xpath( string $selector ): ?string {
		if ( '' === trim( $selector ) ) {
			return '.';
		}
		$paths = [];
		foreach ( explode( ',', $selector ) as $group ) {
			$group = trim( $group );
			if ( '' === $group ) {
				return null;
			}
			$parts      = preg_split( '/\s*(>)\s*|\s+/', $group, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			$path       = '.';
			$axis       = '//';
			$needs_term = true;
			foreach ( $parts as $part ) {
				if ( '>' === $part ) {
					if ( $needs_term ) {
						return null;
					}
					$axis       = '/';
					$needs_term = true;
					continue;
				}
				if ( ! preg_match( '/^(\*|[a-zA-Z][a-zA-Z0-9-]*)?((?:[.#][a-zA-Z_][a-zA-Z0-9_-]*)*)$/D', $part, $match ) || '' === $match[0] ) {
					return null;
				}
				$term = ! empty( $match[1] ) ? strtolower( $match[1] ) : '*';
				preg_match_all( '/([.#])([a-zA-Z_][a-zA-Z0-9_-]*)/', $match[2] ?? '', $qualifiers, PREG_SET_ORDER );
				foreach ( $qualifiers as $qualifier ) {
					$term .= '#' === $qualifier[1]
						? '[@id="' . $qualifier[2] . '"]'
						: '[contains(concat(" ",normalize-space(@class)," ")," ' . $qualifier[2] . ' ")]';
				}
				$path      .= $axis . $term;
				$axis       = '//';
				$needs_term = false;
			}
			if ( $needs_term ) {
				return null;
			}
			$paths[] = $path;
		}
		return implode( ' | ', $paths );
	}

	private static function html_root( string $html ): ?\DOMElement {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		try {
			$loaded = $document->loadHTML( '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
			return $loaded ? $document->getElementsByTagName( 'body' )->item( 0 ) : null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	private static function extract( \DOMElement $root, array $schema ): array {
		$xpath    = new \DOMXPath( $root->ownerDocument );
		$selector = self::selector_xpath( $schema['selector'] ?? '' );
		$nodes    = null !== $selector ? $xpath->query( $selector, $root ) : false;
		if ( false === $nodes ) {
			return [
				'ok'    => false,
				'found' => false,
				'value' => null,
			];
		}
		$source = $schema['source'];
		if ( 'query' === $source ) {
			$values = [];
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}
				$item = [];
				foreach ( $schema['query'] as $key => $nested ) {
					$extracted = self::extract( $node, $nested );
					if ( ! $extracted['ok'] ) {
						return $extracted;
					}
					if ( $extracted['found'] ) {
						$item[ $key ] = $extracted['value'];
					}
				}
				$values[] = $item;
			}
			return [
				'ok'    => true,
				'found' => true,
				'value' => $values,
			];
		}
		$node = $nodes->item( 0 );
		if ( ! $node instanceof \DOMElement ) {
			if ( 'attribute' === $source && 'boolean' === ( $schema['type'] ?? '' ) ) {
				return [
					'ok'    => true,
					'found' => true,
					'value' => false,
				];
			}
			return [
				'ok'    => true,
				'found' => false,
				'value' => null,
			];
		}
		if ( 'attribute' === $source ) {
			$has_attribute = $node->hasAttribute( $schema['attribute'] );
			return [
				'ok'    => true,
				'found' => 'boolean' === ( $schema['type'] ?? '' ) || $has_attribute,
				'value' => 'boolean' === ( $schema['type'] ?? '' ) ? $has_attribute : ( $has_attribute ? $node->getAttribute( $schema['attribute'] ) : null ),
			];
		}
		$value = match ( $source ) {
			'text' => $node->textContent,
			'tag' => strtolower( $node->tagName ),
			'html', 'rich-text' => self::inner_html( $node ),
			default => null,
		};
		return [
			'ok'    => null !== $value,
			'found' => null !== $value,
			'value' => $value,
		];
	}

	private static function inner_html( \DOMElement $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	private static function valid_value( $value, array $schema ): bool {
		$types = (array) ( $schema['type'] ?? [] );
		$valid = [] === $types;
		foreach ( $types as $type ) {
			$valid = $valid || match ( $type ) {
				'string', 'rich-text' => is_string( $value ),
				'number', 'integer' => is_int( $value ) || is_float( $value ),
				'boolean' => is_bool( $value ),
				'null' => null === $value,
				'array' => is_array( $value ) && array_is_list( $value ),
				'object' => is_array( $value ) && ( [] === $value || ! array_is_list( $value ) ),
				default => false,
			};
		}
		return $valid && ( ! isset( $schema['enum'] ) || in_array( $value, $schema['enum'], true ) );
	}
}
