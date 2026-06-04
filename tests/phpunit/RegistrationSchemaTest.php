<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the `validationReasons` field is present on every executable
 * recommendation surface's suggestion *item* schema, and is typed so the
 * versioned reason vocabulary can grow without ever failing the whole
 * recommendation payload under the Gutenberg ajv-draft-04 strict validator
 * (design decision OD-1).
 *
 * `code` is a BOUNDED STRING (maxLength + pattern), NOT an enum, so a
 * newly-minted reason code is never an out-of-enum value. `severity` may be a
 * fixed 3-value enum.
 *
 * Every assertion runs against the genuinely-registered output schema
 * (the public builder the abilities expose), not a test-only construct.
 */
final class RegistrationSchemaTest extends TestCase {

	/**
	 * The suggestion *item* schema for each executable surface, keyed by a
	 * human-readable surface label, resolved from the genuinely-registered
	 * ability output schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function executableSuggestionItemSchemas(): array {
		$block         = Registration::recommendation_output_schema( 'flavor-agent/recommend-block' );
		$style         = Registration::recommendation_output_schema( 'flavor-agent/recommend-style' );
		$template      = Registration::recommendation_output_schema( 'flavor-agent/recommend-template' );
		$template_part = Registration::recommendation_output_schema( 'flavor-agent/recommend-template-part' );

		return [
			'block'         => $block['properties']['block']['items'],
			'style'         => $style['properties']['suggestions']['items'],
			'template'      => $template['properties']['suggestions']['items'],
			'template-part' => $template_part['properties']['suggestions']['items'],
		];
	}

	public function test_validation_reasons_present_on_every_executable_suggestion_schema(): void {
		foreach ( $this->executableSuggestionItemSchemas() as $surface => $item_schema ) {
			$this->assertArrayHasKey(
				'validationReasons',
				$item_schema['properties'],
				"validationReasons must be on the {$surface} suggestion item schema"
			);
			$this->assertSame(
				'array',
				$item_schema['properties']['validationReasons']['type'],
				"validationReasons must be an array on the {$surface} surface"
			);
		}
	}

	/**
	 * The strongest assertions, required for at least the style and block
	 * suggestion schemas: `code` is a bounded string (no enum), `severity`
	 * is a fixed enum.
	 */
	public function test_validation_reasons_code_is_a_bounded_string_not_an_enum(): void {
		$schemas = $this->executableSuggestionItemSchemas();

		foreach ( [ 'block', 'style', 'template', 'template-part' ] as $surface ) {
			$item = $schemas[ $surface ]['properties']['validationReasons']['items'];

			// code: bounded string, NOT enum.
			$this->assertSame(
				'string',
				$item['properties']['code']['type'],
				"validationReasons.code must be a string on the {$surface} surface"
			);
			$this->assertSame(
				64,
				$item['properties']['code']['maxLength'],
				"validationReasons.code must cap at 64 chars on the {$surface} surface"
			);
			$this->assertSame(
				'^[a-z0-9_-]+$',
				$item['properties']['code']['pattern'],
				"validationReasons.code must be bounded by the vocabulary pattern on the {$surface} surface"
			);
			$this->assertArrayNotHasKey(
				'enum',
				$item['properties']['code'],
				"validationReasons.code must be a bounded string, NOT an enum, on the {$surface} surface"
			);

			// severity: fixed 3-value enum.
			$this->assertSame(
				'string',
				$item['properties']['severity']['type'],
				"validationReasons.severity must be a string on the {$surface} surface"
			);
			$this->assertSame(
				[ 'rejected', 'downgraded', 'no_op' ],
				$item['properties']['severity']['enum'],
				"validationReasons.severity must be the fixed 3-value enum on the {$surface} surface"
			);

			// message: free-form string.
			$this->assertSame(
				'string',
				$item['properties']['message']['type'],
				"validationReasons.message must be a string on the {$surface} surface"
			);
		}
	}
}
