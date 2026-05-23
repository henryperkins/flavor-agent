<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\ResponseSchema;
use PHPUnit\Framework\TestCase;

final class ResponseSchemaTest extends TestCase {

	/**
	 * The LLM strict schemas must accept a nullable `confidence` so the model can express
	 * per-suggestion ranking signals. Without this the parser's RankingContract path would
	 * be unreachable in production (text.format=json_schema rejects undeclared fields).
	 */
	public function test_strict_llm_schemas_accept_nullable_confidence_on_suggestion_items(): void {
		$cases = [
			'template'      => 'suggestions',
			'template_part' => 'suggestions',
			'style'         => 'suggestions',
			'navigation'    => 'suggestions',
		];

		foreach ( $cases as $surface => $list_key ) {
			$schema = ResponseSchema::get( $surface );

			$this->assertIsArray( $schema, "Schema for {$surface} should exist." );

			$confidence = $schema['properties'][ $list_key ]['items']['properties']['confidence'] ?? null;

			$this->assertIsArray( $confidence, "{$surface} suggestion items should declare confidence." );
			$this->assertSame( [ 'number', 'null' ], $confidence['type'] ?? null, "{$surface} confidence must be nullable so the model can opt out." );
			$this->assertSame( 0, $confidence['minimum'] ?? null );
			$this->assertSame( 1, $confidence['maximum'] ?? null );
		}
	}

	public function test_block_schema_already_carries_confidence_via_display_metadata(): void {
		$schema = ResponseSchema::get( 'block' );

		$this->assertIsArray( $schema );

		$settings_confidence = $schema['properties']['settings']['items']['properties']['confidence'] ?? null;
		$styles_confidence   = $schema['properties']['styles']['items']['properties']['confidence'] ?? null;
		$block_confidence    = $schema['properties']['block']['items']['properties']['confidence'] ?? null;

		$this->assertSame( 'number', $settings_confidence['type'] ?? null );
		$this->assertSame( 'number', $styles_confidence['type'] ?? null );
		$this->assertSame( 'number', $block_confidence['type'] ?? null );
	}

	public function test_strict_llm_schemas_accept_nullable_ranking_objects(): void {
		$cases = [
			'template'      => [ 'suggestions' ],
			'template_part' => [ 'suggestions' ],
			'style'         => [ 'suggestions' ],
			'navigation'    => [ 'suggestions' ],
		];

		foreach ( $cases as $surface => [ $list_key ] ) {
			$schema  = ResponseSchema::get( $surface );
			$ranking = $schema['properties'][ $list_key ]['items']['properties']['ranking'] ?? null;

			$this->assertIsArray( $ranking, "{$surface} suggestion items should declare ranking." );
			$this->assertSame( [ 'object', 'null' ], $ranking['type'] ?? null );
			$this->assertFalse( (bool) ( $ranking['additionalProperties'] ?? true ) );
			$this->assertSame( [ 'number', 'null' ], $ranking['properties']['score']['type'] ?? null );
			$this->assertSame( [ 'string', 'null' ], $ranking['properties']['reason']['type'] ?? null );
			$this->assertSame( [ 'array', 'null' ], $ranking['properties']['sourceSignals']['type'] ?? null );
			$this->assertSame( [ 'string', 'null' ], $ranking['properties']['designPrinciple']['type'] ?? null );
			$this->assertSame( [ 'string', 'null' ], $ranking['properties']['risk']['type'] ?? null );
			$this->assertSame(
				[ 'score', 'reason', 'sourceSignals', 'designPrinciple', 'risk' ],
				$ranking['required'] ?? null
			);
			$this->assert_no_contextual_component_fields_in_llm_ranking_schema( $ranking, $surface );
		}
	}

	public function test_block_schema_accepts_nullable_ranking_objects_on_all_lanes(): void {
		$schema = ResponseSchema::get( 'block' );

		foreach ( [ 'settings', 'styles', 'block' ] as $list_key ) {
			$ranking = $schema['properties'][ $list_key ]['items']['properties']['ranking'] ?? null;

			$this->assertIsArray( $ranking, "Block {$list_key} items should declare ranking." );
			$this->assertSame( [ 'object', 'null' ], $ranking['type'] ?? null );
			$this->assertSame( [ 'number', 'null' ], $ranking['properties']['score']['type'] ?? null );
			$this->assertSame(
				[ 'score', 'reason', 'sourceSignals', 'designPrinciple', 'risk' ],
				$ranking['required'] ?? null
			);
			$this->assert_no_contextual_component_fields_in_llm_ranking_schema( $ranking, "block {$list_key}" );
		}
	}

	private function assert_no_contextual_component_fields_in_llm_ranking_schema( array $ranking, string $label ): void {
		foreach ( [ 'modelScore', 'deterministicScore', 'contextScore', 'blendedScore', 'contextEvidence', 'contextPenalties', 'rankingVersion' ] as $field ) {
			$this->assertArrayNotHasKey(
				$field,
				$ranking['properties'] ?? [],
				"{$label} LLM ranking schema must not expose plugin-generated {$field}."
			);
		}
	}
}
