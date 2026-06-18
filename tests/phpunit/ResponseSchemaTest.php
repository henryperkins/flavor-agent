<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\ResponseSchema;
use PHPUnit\Framework\TestCase;

final class ResponseSchemaTest extends TestCase {

	public function test_pattern_schema_constrains_ranking_recommendations(): void {
		$schema = ResponseSchema::get( 'pattern' );

		$this->assertIsArray( $schema, 'Pattern ranking schema should exist so the ranking call gets the same structured-output guard as the other surfaces.' );
		$this->assertSame( false, $schema['additionalProperties'] ?? null );
		$this->assertContains( 'recommendations', $schema['required'] ?? [] );

		$item = $schema['properties']['recommendations']['items'] ?? null;
		$this->assertIsArray( $item, 'recommendations items should be an object schema.' );
		$this->assertSame(
			[ 'name', 'score', 'reason' ],
			$item['required'] ?? null,
			'Each recommendation must require exactly the fields the parser reads (name, score, reason).'
		);
		$this->assertSame( false, $item['additionalProperties'] ?? null );
		$this->assertSame( 'string', $item['properties']['name']['type'] ?? null );
		$this->assertSame( 'number', $item['properties']['score']['type'] ?? null );
		$this->assertSame( 'string', $item['properties']['reason']['type'] ?? null );
	}

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

	public function test_block_schema_declares_group_ids_and_recommended_sets(): void {
		$schema = ResponseSchema::get( 'block' );

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'recommendedSets', $schema['properties'] );
		$this->assertContains( 'recommendedSets', $schema['required'] );
		$this->assertSame( 'array', $schema['properties']['recommendedSets']['type'] );

		$set = $schema['properties']['recommendedSets']['items'];
		$this->assertSame( 'object', $set['type'] );
		$this->assertFalse( (bool) $set['additionalProperties'] );
		$this->assertSame( [ 'id', 'label', 'reason' ], $set['required'] );
		$this->assertSame( 'string', $set['properties']['id']['type'] );
		$this->assertSame( 'string', $set['properties']['label']['type'] );
		$this->assertSame( 'string', $set['properties']['reason']['type'] );

		foreach ( [ 'settings', 'styles', 'block' ] as $lane ) {
			$item = $schema['properties'][ $lane ]['items'];
			$this->assertSame( 'string', $item['properties']['groupId']['type'] ?? null, "Block {$lane} items must carry groupId." );
			$this->assertContains( 'groupId', $item['required'], "Block {$lane} items must require groupId under strict schema." );
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
