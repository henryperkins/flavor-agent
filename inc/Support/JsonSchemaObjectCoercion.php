<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coerce empty associative maps to JSON objects according to a JSON Schema.
 *
 * PHP cannot distinguish an empty map from an empty list — both are `[]`, which
 * `wp_json_encode()` serializes as a JSON array. When a schema declares a field
 * `type: object`, an empty array therefore serializes as `[]` and fails
 * validation under the Gutenberg `@wordpress/abilities` client, which validates
 * ability output with ajv-draft-04 in strict mode. That breaks every
 * bridge-transport execution path — the pattern recommender's insert-time
 * revalidation, the Abilities Explorer, and external MCP clients.
 *
 * This walks a value alongside its schema and replaces empty arrays at
 * `type: object` positions with an empty object, leaving lists, scalars, and
 * non-empty maps untouched.
 */
final class JsonSchemaObjectCoercion {

	/**
	 * @param mixed $value  Value to coerce (typically a decoded response payload).
	 * @param mixed $schema JSON Schema (array) describing $value.
	 * @return mixed Coerced value: empty object-typed arrays become \stdClass.
	 */
	public static function coerce( mixed $value, mixed $schema ): mixed {
		if ( ! is_array( $schema ) ) {
			return $value;
		}

		if ( self::expects_object( $schema ) && is_array( $value ) && [] === $value ) {
			return new \stdClass();
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $property_schema ) {
				if ( array_key_exists( $name, $value ) ) {
					$value[ $name ] = self::coerce( $value[ $name ], $property_schema );
				}
			}
		}

		if ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) ) {
			$declared = isset( $schema['properties'] ) && is_array( $schema['properties'] )
				? $schema['properties']
				: [];

			foreach ( $value as $key => $entry ) {
				if ( is_string( $key ) && ! array_key_exists( $key, $declared ) ) {
					$value[ $key ] = self::coerce( $entry, $schema['additionalProperties'] );
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $value as $index => $entry ) {
				if ( is_int( $index ) ) {
					$value[ $index ] = self::coerce( $entry, $schema['items'] );
				}
			}
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private static function expects_object( array $schema ): bool {
		$type = $schema['type'] ?? null;

		if ( is_string( $type ) ) {
			return 'object' === $type;
		}

		if ( is_array( $type ) ) {
			return in_array( 'object', $type, true ) && ! in_array( 'array', $type, true );
		}

		return false;
	}
}
