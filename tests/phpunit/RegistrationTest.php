<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class RegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_register_abilities_exposes_current_recommend_block_structural_schema(): void {
		Registration::register_category();
		Registration::register_abilities();

		$this->assertArrayHasKey( 'flavor-agent', WordPressTestState::$registered_ability_categories );
		$this->assertArrayHasKey( 'flavor-agent/recommend-block', WordPressTestState::$registered_abilities );

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'];

		$this->assertIsArray( $ability );
		$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );

		$selected_block = $ability['input_schema']['properties']['selectedBlock'] ?? null;

		$this->assertIsArray( $selected_block );
		$this->assertFalse( (bool) ( $selected_block['additionalProperties'] ?? true ) );

		foreach ( [ 'editingMode', 'childCount', 'supportsContentRole', 'structuralIdentity', 'structuralAncestors', 'structuralBranch' ] as $field ) {
			$this->assertArrayHasKey( $field, $selected_block['properties'] );
		}

		$this->assertTrue( (bool) ( $selected_block['properties']['attributes']['additionalProperties'] ?? false ) );
		$this->assertTrue( (bool) ( $selected_block['properties']['blockVisibility']['additionalProperties'] ?? false ) );
		$this->assertTrue( (bool) ( $selected_block['properties']['structuralIdentity']['additionalProperties'] ?? false ) );
		$this->assertTrue(
			(bool) (
				$selected_block['properties']['structuralIdentity']['properties']['position']['additionalProperties']
				?? false
			)
		);
		$this->assertTrue(
			(bool) (
				$selected_block['properties']['structuralAncestors']['items']['additionalProperties']
				?? false
			)
		);
		$this->assertTrue(
			(bool) (
				$selected_block['properties']['structuralBranch']['items']['additionalProperties']
				?? false
			)
		);
		$this->assertTrue(
			(bool) (
				$selected_block['properties']['structuralBranch']['items']['properties']['children']['items']['additionalProperties']
				?? false
			)
		);
	}

	public function test_register_abilities_exposes_wordpress_docs_entity_key_schema(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/search-wordpress-docs'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertSame( [ 'query' ], $ability['input_schema']['required'] ?? null );
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['entityKey']['type'] ?? null
		);
		$this->assertStringContainsString(
			'namespace/block-name',
			(string) ( $ability['input_schema']['properties']['entityKey']['description'] ?? '' )
		);
		$this->assertStringContainsString(
			'template:404',
			(string) ( $ability['input_schema']['properties']['entityKey']['description'] ?? '' )
		);
	}

	public function test_register_abilities_exposes_structured_template_operation_schema(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-template'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );

		$suggestion = $ability['output_schema']['properties']['suggestions']['items'] ?? null;

		$this->assertIsArray( $suggestion );
		$this->assertSame(
			'array',
			$suggestion['properties']['operations']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['type']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['currentSlug']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['patternName']['type'] ?? null
		);
		$this->assertArrayNotHasKey(
			'visiblePatternNames',
			$ability['input_schema']['properties'] ?? []
		);
		$this->assertSame(
			'object',
			$ability['input_schema']['properties']['editorSlots']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['editorSlots']['properties']['assignedParts']['items']['properties']['slug']['type'] ?? null
		);
	}

	public function test_register_abilities_exposes_template_part_recommendation_schema(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-template-part'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );
		$this->assertSame( [ 'templatePartRef' ], $ability['input_schema']['required'] ?? null );
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['templatePartRef']['type'] ?? null
		);

		$suggestion = $ability['output_schema']['properties']['suggestions']['items'] ?? null;

		$this->assertIsArray( $suggestion );
		$this->assertSame(
			'array',
			$suggestion['properties']['blockHints']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$suggestion['properties']['blockHints']['items']['properties']['path']['items']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['blockHints']['items']['properties']['blockName']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['patternSuggestions']['items']['type'] ?? null
		);
	}
}
