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
}
