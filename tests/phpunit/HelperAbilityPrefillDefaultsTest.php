<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * The AI plugin's Abilities Explorer prefills its "Input Data" textarea from each
 * input-schema property's `default` (falling back to `example`, then a type-based
 * empty value such as ""). For helper abilities whose required field is a
 * free-form id/name, that empty fallback makes the very first "Invoke Ability"
 * click fail validation (e.g. "patternId is required."). Declaring a `default`
 * gives testers a working example out of the box.
 *
 * `default` is the only safe keyword for this:
 *  - it is JSON Schema draft-04 vocabulary, so Gutenberg's strict ajv-draft-04
 *    `@wordpress/abilities` client compiles the schema (see
 *    {@see AbilitySchemaContractTest}); the OpenAPI-only `example` keyword is
 *    rejected at compile time and must never be used here, and
 *  - core's WP 7.1 ability-schema REST reshaping keeps `default`
 *    (`rest_get_allowed_schema_keywords()`), so it survives to the client.
 *
 * A per-property `default` is a pure tooling hint: core's
 * `WP_Ability::normalize_input()` only applies a *top-level* schema default, so
 * this changes no runtime behavior — the field stays required and an explicitly
 * empty submit still errors.
 */
final class HelperAbilityPrefillDefaultsTest extends TestCase {

	/**
	 * Each helper ability's required free-form field mapped to the working
	 * example the Abilities Explorer should prefill.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EXPECTED_DEFAULTS = [
		'flavor-agent/introspect-block'      => [ 'blockName' => 'core/paragraph' ],
		'flavor-agent/get-pattern'           => [ 'patternId' => 'core/query-standard-posts' ],
		'flavor-agent/search-wordpress-docs' => [ 'query' => 'block supports' ],
	];

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		Registration::register_abilities();
	}

	public function test_helper_required_fields_prefill_a_working_default(): void {
		foreach ( self::EXPECTED_DEFAULTS as $ability => $fields ) {
			$schema = WordPressTestState::$raw_registered_abilities[ $ability ]['input_schema'] ?? null;
			$this->assertIsArray( $schema, "{$ability} must be registered with an input schema." );

			foreach ( $fields as $property => $expected_default ) {
				$prop = $schema['properties'][ $property ] ?? null;
				$this->assertIsArray( $prop, "{$ability}.{$property} property must exist." );

				$this->assertArrayHasKey(
					'default',
					$prop,
					"{$ability}.{$property} must declare a `default` so the Abilities Explorer prefills a working example instead of an empty required field."
				);
				$this->assertSame(
					$expected_default,
					$prop['default'],
					"{$ability}.{$property} default must be the documented working example."
				);

				// The default must satisfy the property's own declared type so the
				// prefilled value passes input validation on the first invoke.
				$this->assertSame( 'string', $prop['type'] ?? null, "{$ability}.{$property} is a string field." );
				$this->assertIsString( $prop['default'] );
				$this->assertNotSame(
					'',
					$prop['default'],
					'A blank default would reproduce the original empty-required error.'
				);

				// Guard the landmine: never the OpenAPI-only `example` keyword,
				// which strict ajv-draft-04 rejects at compile time.
				$this->assertArrayNotHasKey(
					'example',
					$prop,
					"{$ability}.{$property} must prefill via `default`, not the ajv-incompatible `example` keyword."
				);
			}

			// The default is a tooling hint, not a server-side fallback: the field
			// must stay required so an explicitly empty submit still errors.
			$required = $schema['required'] ?? [];
			foreach ( array_keys( $fields ) as $property ) {
				$this->assertContains(
					$property,
					$required,
					"{$ability}.{$property} must remain required even with a prefill default."
				);
			}
		}
	}
}
