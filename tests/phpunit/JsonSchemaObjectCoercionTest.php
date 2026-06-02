<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\JsonSchemaObjectCoercion;
use PHPUnit\Framework\TestCase;

final class JsonSchemaObjectCoercionTest extends TestCase {

	public function test_empty_object_typed_value_encodes_as_json_object(): void {
		$coerced = JsonSchemaObjectCoercion::coerce(
			[],
			[
				'type'                 => 'object',
				'additionalProperties' => true,
			]
		);

		$this->assertSame( '{}', wp_json_encode( $coerced ) );
	}

	public function test_empty_array_typed_value_stays_a_json_array(): void {
		$coerced = JsonSchemaObjectCoercion::coerce(
			[],
			[
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
			]
		);

		$this->assertSame( '[]', wp_json_encode( $coerced ) );
	}

	public function test_empty_array_stays_array_when_schema_allows_array_and_object(): void {
		$coerced = JsonSchemaObjectCoercion::coerce(
			[],
			[
				'type' => [ 'string', 'number', 'boolean', 'object', 'array', 'null' ],
			]
		);

		$this->assertSame( '[]', wp_json_encode( $coerced ) );
	}

	public function test_empty_object_nullable_value_encodes_as_json_object(): void {
		$coerced = JsonSchemaObjectCoercion::coerce(
			[],
			[
				'type' => [ 'object', 'null' ],
			]
		);

		$this->assertSame( '{}', wp_json_encode( $coerced ) );
	}

	public function test_non_empty_object_map_is_left_as_a_json_object(): void {
		$coerced = JsonSchemaObjectCoercion::coerce(
			[ 'below_threshold' => 2 ],
			[
				'type'                 => 'object',
				'additionalProperties' => true,
			]
		);

		$this->assertSame( '{"below_threshold":2}', wp_json_encode( $coerced ) );
	}

	public function test_coerces_empty_object_fields_nested_in_array_items(): void {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'recommendations' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'             => [ 'type' => 'string' ],
							'patternOverrides' => [ 'type' => 'object' ],
						],
					],
				],
				'diagnostics'     => [
					'type'       => 'object',
					'properties' => [
						'dropReasons' => [
							'type'                 => 'object',
							'additionalProperties' => true,
						],
					],
				],
			],
		];

		$value = [
			'recommendations' => [
				[
					'name'             => 'hero',
					'patternOverrides' => [],
				],
			],
			'diagnostics'     => [
				'dropReasons' => [],
			],
		];

		$json = wp_json_encode( JsonSchemaObjectCoercion::coerce( $value, $schema ) );

		$this->assertStringContainsString( '"patternOverrides":{}', $json );
		$this->assertStringContainsString( '"dropReasons":{}', $json );
		// The list itself must stay an array, and scalar data is preserved.
		$this->assertStringContainsString( '"recommendations":[{', $json );
		$this->assertStringContainsString( '"name":"hero"', $json );
	}

	public function test_recurses_into_nested_object_properties(): void {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'ranking' => [
					'type'       => 'object',
					'properties' => [
						'rankingHint' => [ 'type' => 'object' ],
					],
				],
			],
		];

		$json = wp_json_encode(
			JsonSchemaObjectCoercion::coerce(
				[
					'ranking' => [
						'score'       => 0.5,
						'rankingHint' => [],
					],
				],
				$schema
			)
		);

		$this->assertStringContainsString( '"ranking":{', $json );
		$this->assertStringContainsString( '"rankingHint":{}', $json );
		$this->assertStringContainsString( '"score":0.5', $json );
	}

	public function test_coerces_empty_object_entries_under_additional_properties_schema(): void {
		$json = wp_json_encode(
			JsonSchemaObjectCoercion::coerce(
				[ 'header' => [] ],
				[
					'type'                 => 'object',
					'additionalProperties' => [ 'type' => 'object' ],
				]
			)
		);

		$this->assertSame( '{"header":{}}', $json );
	}

	public function test_leaves_scalars_and_null_untouched(): void {
		$this->assertSame( 'draft', JsonSchemaObjectCoercion::coerce( 'draft', [ 'type' => 'string' ] ) );
		$this->assertSame( 5, JsonSchemaObjectCoercion::coerce( 5, [ 'type' => 'integer' ] ) );
		// A null where the schema expects an object is not our concern here; it is
		// passed through unchanged rather than turned into {}.
		$this->assertNull( JsonSchemaObjectCoercion::coerce( null, [ 'type' => 'object' ] ) );
	}
}
