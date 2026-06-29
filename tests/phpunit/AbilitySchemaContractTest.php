<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration;
use PHPUnit\Framework\TestCase;

/**
 * Guards the recommendation ability schemas against keywords that the
 * Gutenberg-provided `@wordpress/abilities` client validator rejects.
 *
 * That client compiles every ability's input_schema and output_schema with
 * ajv-draft-04 in strict mode (`ajv.compile()` throws on the first unknown
 * keyword). When the no-signal bridge transport runs an ability — e.g. the
 * pattern recommender's insert-time `resolveSignatureOnly` revalidation, the
 * Abilities Explorer, or an external MCP client — a non-draft-04 keyword such
 * as the OpenAPI-only `example` makes the schema uncompilable, surfacing to the
 * user as "could not revalidate the current server apply context."
 *
 * This test mirrors that strict compilation by asserting every keyword position
 * uses only JSON Schema draft-04 vocabulary.
 */
final class AbilitySchemaContractTest extends TestCase {

	private const RECOMMENDATION_ABILITIES = [
		'flavor-agent/recommend-block',
		'flavor-agent/recommend-content',
		'flavor-agent/recommend-patterns',
		'flavor-agent/recommend-navigation',
		'flavor-agent/recommend-style',
		'flavor-agent/recommend-template',
		'flavor-agent/recommend-template-part',
	];

	private const EXTERNAL_APPLY_ABILITIES = [
		'flavor-agent/request-style-apply',
		'flavor-agent/get-activity',
		'flavor-agent/list-activity',
		'flavor-agent/undo-activity',
	];

	/**
	 * JSON Schema draft-04 vocabulary — the keyword set ajv-draft-04 recognizes.
	 * Deliberately excludes draft-06+ and OpenAPI keywords (`example`, `examples`,
	 * `const`, `if`/`then`/`else`, `readOnly`, ...) that strict ajv-draft-04 would
	 * reject at compile time.
	 *
	 * @var string[]
	 */
	private const DRAFT04_KEYWORDS = [
		'$ref',
		'$schema',
		'id',
		'title',
		'description',
		'default',
		'multipleOf',
		'maximum',
		'exclusiveMaximum',
		'minimum',
		'exclusiveMinimum',
		'maxLength',
		'minLength',
		'pattern',
		'additionalItems',
		'items',
		'maxItems',
		'minItems',
		'uniqueItems',
		'maxProperties',
		'minProperties',
		'required',
		'additionalProperties',
		'definitions',
		'properties',
		'patternProperties',
		'dependencies',
		'enum',
		'type',
		'format',
		'allOf',
		'anyOf',
		'oneOf',
		'not',
	];

	public function test_recommendation_input_and_output_schemas_use_only_draft04_keywords(): void {
		$violations = [];

		foreach ( self::RECOMMENDATION_ABILITIES as $ability ) {
			$this->collect_unknown_keywords(
				Registration::recommendation_input_schema( $ability ),
				false,
				"{$ability}.input_schema",
				$violations
			);
			$this->collect_unknown_keywords(
				Registration::recommendation_output_schema( $ability ),
				false,
				"{$ability}.output_schema",
				$violations
			);
		}

		$this->assertSame(
			[],
			$violations,
			"Ability schemas must only use JSON Schema draft-04 keywords so the\n"
				. "Gutenberg ajv-draft-04 strict validator can compile them. Offending\n"
				. "keyword positions:\n  " . implode( "\n  ", $violations )
		);
	}

	public function test_external_apply_input_and_output_schemas_use_only_draft04_keywords(): void {
		$violations = [];

		foreach ( self::EXTERNAL_APPLY_ABILITIES as $ability ) {
			$this->collect_unknown_keywords(
				Registration::external_apply_input_schema( $ability ),
				false,
				"{$ability}.input_schema",
				$violations
			);
			$this->collect_unknown_keywords(
				Registration::external_apply_output_schema( $ability ),
				false,
				"{$ability}.output_schema",
				$violations
			);
		}

		$this->assertSame(
			[],
			$violations,
			"External-apply ability schemas must only use JSON Schema draft-04 keywords. Offending\n"
				. "keyword positions:\n  " . implode( "\n  ", $violations )
		);
	}

	/**
	 * The template-part external-apply input schema is sourced from its own
	 * builder (`template_part_apply_input_schema`), not `external_apply_input_schema`,
	 * so it is guarded explicitly. Its output schema keys on `$ability_id` in the
	 * shared `external_apply_output_schema` match and is covered here too.
	 */
	public function test_request_template_part_apply_schemas_use_only_draft04_keywords(): void {
		$violations = [];

		$this->collect_unknown_keywords(
			Registration::template_part_apply_input_schema( 'flavor-agent/request-template-part-apply' ),
			false,
			'flavor-agent/request-template-part-apply.input_schema',
			$violations
		);
		$this->collect_unknown_keywords(
			Registration::external_apply_output_schema( 'flavor-agent/request-template-part-apply' ),
			false,
			'flavor-agent/request-template-part-apply.output_schema',
			$violations
		);

		$this->assertSame(
			[],
			$violations,
			"The request-template-part-apply schemas must only use JSON Schema draft-04 keywords. Offending\n"
				. "keyword positions:\n  " . implode( "\n  ", $violations )
		);
	}

	/**
	 * The page-level template external-apply input schema is also sourced from a
	 * dedicated builder (`template_apply_input_schema`), not the shared match.
	 */
	public function test_request_template_apply_schemas_use_only_draft04_keywords(): void {
		$violations = [];

		$this->collect_unknown_keywords(
			Registration::template_apply_input_schema( 'flavor-agent/request-template-apply' ),
			false,
			'flavor-agent/request-template-apply.input_schema',
			$violations
		);
		$this->collect_unknown_keywords(
			Registration::external_apply_output_schema( 'flavor-agent/request-template-apply' ),
			false,
			'flavor-agent/request-template-apply.output_schema',
			$violations
		);

		$this->assertSame(
			[],
			$violations,
			"The request-template-apply schemas must only use JSON Schema draft-04 keywords. Offending\n"
				. "keyword positions:\n  " . implode( "\n  ", $violations )
		);
	}

	/**
	 * Recursively walk a schema, recording keyword positions outside the
	 * draft-04 vocabulary. Mirrors how ajv descends a schema: keys directly
	 * under properties/patternProperties/definitions are property names (not
	 * keywords) and are skipped; everything else is a keyword position.
	 *
	 * @param mixed     $node            Schema node (array) or scalar leaf.
	 * @param bool      $is_property_map  True when $node maps names to subschemas.
	 * @param string    $path            Human-readable position for diagnostics.
	 * @param string[]  $violations      Accumulator (by reference).
	 */
	private function collect_unknown_keywords( mixed $node, bool $is_property_map, string $path, array &$violations ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $key => $value ) {
			if ( $is_property_map ) {
				// $key is a property name; its value is a subschema.
				$this->collect_unknown_keywords( $value, false, "{$path}.{$key}", $violations );
				continue;
			}

			if ( is_string( $key ) && ! in_array( $key, self::DRAFT04_KEYWORDS, true ) ) {
				$violations[] = "{$path}.{$key}";
			}

			if ( in_array( $key, [ 'properties', 'patternProperties', 'definitions' ], true ) ) {
				$this->collect_unknown_keywords( $value, true, "{$path}.{$key}", $violations );
			} elseif ( in_array( $key, [ 'items', 'additionalProperties', 'additionalItems', 'not' ], true ) ) {
				$this->collect_unknown_keywords( $value, false, "{$path}.{$key}", $violations );
			} elseif ( in_array( $key, [ 'allOf', 'anyOf', 'oneOf' ], true ) && is_array( $value ) ) {
				foreach ( $value as $index => $subschema ) {
					$this->collect_unknown_keywords( $subschema, false, "{$path}.{$key}[{$index}]", $violations );
				}
			} elseif ( 'dependencies' === $key && is_array( $value ) ) {
				foreach ( $value as $dependency => $subschema ) {
					if ( is_array( $subschema ) ) {
						$this->collect_unknown_keywords( $subschema, false, "{$path}.{$key}.{$dependency}", $violations );
					}
				}
			}
		}
	}
}
