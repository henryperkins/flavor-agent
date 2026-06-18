<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\NavigationPrompt;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\LLM\StylePrompt;
use FlavorAgent\LLM\TemplatePartPrompt;
use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

/**
 * The strict response schemas force every declared property to be required and
 * forbid additional properties (ResponseSchema::strict_object() +
 * WordPressAIClient::normalize_output_schema()). Any JSON example a prompt
 * teaches the model — both the system-prompt shape block and the appended
 * few-shot examples — must therefore satisfy that schema, or the prompt is
 * teaching a shape the structured-output contract rejects/coerces. This guard
 * decodes every example payload and validates it recursively against the
 * surface schema's required keys.
 */
final class PromptExampleSchemaConsistencyTest extends TestCase {

	/** @var array<int, string> */
	private array $violations = [];

	public function test_template_examples_match_strict_schema(): void {
		$this->assert_examples_match_schema(
			'template',
			TemplatePrompt::build_system(),
			TemplatePrompt::get_few_shot_examples()
		);
	}

	public function test_template_part_examples_match_strict_schema(): void {
		$this->assert_examples_match_schema(
			'template_part',
			TemplatePartPrompt::build_system(),
			TemplatePartPrompt::get_few_shot_examples()
		);
	}

	public function test_style_examples_match_strict_schema(): void {
		$this->assert_examples_match_schema(
			'style',
			StylePrompt::build_system(),
			StylePrompt::get_few_shot_examples()
		);
	}

	public function test_navigation_examples_match_strict_schema(): void {
		$this->assert_examples_match_schema(
			'navigation',
			NavigationPrompt::build_system(),
			[]
		);
	}

	/**
	 * @param array<int, string> $few_shot_examples
	 */
	private function assert_examples_match_schema( string $surface, string $system_prompt, array $few_shot_examples ): void {
		$schema = ResponseSchema::get( $surface );
		$this->assertIsArray( $schema, "Schema for {$surface} should exist." );

		$texts    = array_merge( [ $system_prompt ], $few_shot_examples );
		$payloads = [];

		foreach ( $texts as $text ) {
			foreach ( $this->extract_suggestion_payloads( (string) $text ) as $payload ) {
				$payloads[] = $payload;
			}
		}

		$this->assertNotEmpty(
			$payloads,
			"Expected at least one decodable suggestions payload in the {$surface} prompt examples."
		);

		$this->violations = [];

		foreach ( $payloads as $index => $payload ) {
			$this->collect_schema_violations( $payload, $schema, "{$surface}#{$index}" );
		}

		$this->assertSame(
			[],
			$this->violations,
			"{$surface} prompt examples teach a shape the strict schema rejects:\n  - " . implode( "\n  - ", $this->violations )
		);
	}

	/**
	 * Find every top-level balanced `{...}` object in the text that decodes to a
	 * suggestions payload (string-aware so braces inside JSON strings are ignored).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_suggestion_payloads( string $text ): array {
		$payloads = [];
		$length   = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( '{' !== $text[ $i ] ) {
				continue;
			}

			$depth     = 0;
			$in_string = false;
			$escaped   = false;

			for ( $j = $i; $j < $length; $j++ ) {
				$char = $text[ $j ];

				if ( $in_string ) {
					if ( $escaped ) {
						$escaped = false;
					} elseif ( '\\' === $char ) {
						$escaped = true;
					} elseif ( '"' === $char ) {
						$in_string = false;
					}

					continue;
				}

				if ( '"' === $char ) {
					$in_string = true;
					continue;
				}

				if ( '{' === $char ) {
					++$depth;
				} elseif ( '}' === $char ) {
					--$depth;

					if ( 0 === $depth ) {
						$decoded = json_decode( substr( $text, $i, $j - $i + 1 ), true );

						if ( is_array( $decoded ) && array_key_exists( 'suggestions', $decoded ) ) {
							$payloads[] = $decoded;
						}

						$i = $j;
						break;
					}
				}
			}
		}

		return $payloads;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function collect_schema_violations( mixed $value, array $schema, string $path ): void {
		$types = $schema['type'] ?? null;
		$types = is_array( $types ) ? $types : [ $types ];

		if ( null === $value ) {
			if ( ! in_array( 'null', $types, true ) ) {
				$this->violations[] = "{$path}: null is not permitted by the schema.";
			}

			return;
		}

		if ( in_array( 'object', $types, true ) && is_array( $value ) ) {
			$required = is_array( $schema['required'] ?? null ) ? $schema['required'] : [];

			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $value ) ) {
					$this->violations[] = "{$path}: omits required key '{$key}'";
				}
			}

			$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];

			foreach ( $value as $key => $child ) {
				if ( isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ) {
					$this->collect_schema_violations( $child, $properties[ $key ], "{$path}.{$key}" );
				}
			}

			return;
		}

		if ( in_array( 'array', $types, true ) && is_array( $value ) ) {
			$items = is_array( $schema['items'] ?? null ) ? $schema['items'] : [];

			if ( [] !== $items ) {
				foreach ( $value as $element_index => $child ) {
					$this->collect_schema_violations( $child, $items, "{$path}[{$element_index}]" );
				}
			}
		}
	}
}
