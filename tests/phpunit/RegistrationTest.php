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

	public function test_register_abilities_marks_ai_recommendations_public_for_mcp(): void {
		Registration::register_category();
		Registration::register_abilities();

		foreach ( [
			'flavor-agent/recommend-block',
			'flavor-agent/recommend-content',
			'flavor-agent/recommend-patterns',
			'flavor-agent/recommend-template',
			'flavor-agent/recommend-template-part',
			'flavor-agent/recommend-navigation',
			'flavor-agent/recommend-style',
		] as $ability_id ) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $ability, "Expected registered ability {$ability_id}." );
			$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ), "{$ability_id} should remain REST-visible." );
			$this->assertTrue( (bool) ( $ability['meta']['mcp']['public'] ?? false ), "{$ability_id} should opt into the default MCP server." );
		}

		$this->assertFalse(
			(bool) ( WordPressTestState::$registered_abilities['flavor-agent/introspect-block']['meta']['mcp']['public'] ?? false )
		);
		$this->assertFalse(
			(bool) ( WordPressTestState::$registered_abilities['flavor-agent/check-status']['meta']['mcp']['public'] ?? false )
		);
	}

	public function test_register_abilities_exposes_content_recommendation_schema(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-content'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );
		$this->assertTrue( (bool) ( $ability['meta']['mcp']['public'] ?? false ) );
		$this->assertSame(
			'Recommend editorial content',
			$ability['label'] ?? null
		);
		$this->assertStringContainsString(
			'blog posts, essays, and site copy',
			(string) ( $ability['description'] ?? '' )
		);
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['mode']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['voiceProfile']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$ability['input_schema']['properties']['postContext']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['postContext']['properties']['categories']['items']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['issues']['items']['properties']['revision']['type'] ?? null
		);
	}

	public function test_register_abilities_exposes_pattern_override_metadata_in_pattern_schemas(): void {
		Registration::register_category();
		Registration::register_abilities();

		$recommend_ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-patterns'] ?? null;
		$list_ability      = WordPressTestState::$registered_abilities['flavor-agent/list-patterns'] ?? null;

		$this->assertIsArray( $recommend_ability );
		$this->assertIsArray( $list_ability );
		$this->assertSame(
			'object',
			$recommend_ability['output_schema']['properties']['recommendations']['items']['properties']['patternOverrides']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$recommend_ability['input_schema']['properties']['insertionContext']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$recommend_ability['input_schema']['properties']['insertionContext']['properties']['ancestors']['items']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$recommend_ability['input_schema']['properties']['insertionContext']['properties']['templatePartArea']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$recommend_ability['output_schema']['properties']['recommendations']['items']['properties']['overrideCapabilities']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$recommend_ability['output_schema']['properties']['recommendations']['items']['properties']['overrideCapabilities']['properties']['matchesNearbyBlock']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$recommend_ability['output_schema']['properties']['recommendations']['items']['properties']['overrideCapabilities']['properties']['nearbyBlockOverlapAttrs']['items']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$recommend_ability['output_schema']['properties']['recommendations']['items']['properties']['overrideCapabilities']['properties']['siblingOverrideCount']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$list_ability['output_schema']['properties']['patterns']['items']['properties']['patternOverrides']['type'] ?? null
		);
		$this->assertArrayNotHasKey(
			'editorStructure',
			$recommend_ability['input_schema']['properties'] ?? []
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
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['placement']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$suggestion['properties']['operations']['items']['properties']['targetPath']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$suggestion['properties']['operations']['items']['properties']['targetPath']['items']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$ability['input_schema']['properties']['visiblePatternNames']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['visiblePatternNames']['items']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$ability['input_schema']['properties']['editorSlots']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['editorSlots']['properties']['assignedParts']['items']['properties']['slug']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$ability['input_schema']['properties']['editorStructure']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['input_schema']['properties']['editorStructure']['properties']['topLevelBlockTree']['items']['properties']['path']['items']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['input_schema']['properties']['editorStructure']['properties']['structureStats']['properties']['blockCount']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['expectedTarget']['properties']['name']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$suggestion['properties']['operations']['items']['properties']['expectedTarget']['properties']['slot']['properties']['isEmpty']['type'] ?? null
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
		$this->assertSame(
			'array',
			$ability['input_schema']['properties']['visiblePatternNames']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['input_schema']['properties']['visiblePatternNames']['items']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$ability['input_schema']['properties']['editorStructure']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['input_schema']['properties']['editorStructure']['properties']['blockTree']['items']['properties']['path']['items']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['input_schema']['properties']['editorStructure']['properties']['allBlockPaths']['items']['properties']['childCount']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$ability['input_schema']['properties']['editorStructure']['properties']['structureStats']['properties']['hasNavigation']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$ability['input_schema']['properties']['editorStructure']['properties']['operationTargets']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['input_schema']['properties']['editorStructure']['properties']['operationTargets']['items']['properties']['path']['items']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['input_schema']['properties']['editorStructure']['properties']['insertionAnchors']['items']['properties']['targetPath']['items']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['input_schema']['properties']['editorStructure']['properties']['structuralConstraints']['properties']['contentOnlyPaths']['items']['items']['type'] ?? null
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
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['patternName']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['placement']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$suggestion['properties']['operations']['items']['properties']['targetPath']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$suggestion['properties']['operations']['items']['properties']['targetPath']['items']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$suggestion['properties']['operations']['items']['properties']['expectedBlockName']['type'] ?? null
		);
	}

	public function test_register_abilities_exposes_navigation_change_target_paths(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-navigation'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertSame(
			'object',
			$ability['input_schema']['properties']['editorContext']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$ability['output_schema']['properties']['suggestions']['items']['properties']['changes']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$ability['output_schema']['properties']['suggestions']['items']['properties']['changes']['items']['properties']['targetPath']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$ability['output_schema']['properties']['suggestions']['items']['properties']['changes']['items']['properties']['targetPath']['items']['type'] ?? null
		);
	}

	public function test_register_abilities_exposes_style_and_surface_status_schemas(): void {
		Registration::register_category();
		Registration::register_abilities();

		$style_ability  = WordPressTestState::$registered_abilities['flavor-agent/recommend-style'] ?? null;
		$status_ability = WordPressTestState::$registered_abilities['flavor-agent/check-status'] ?? null;
		$tokens_ability = WordPressTestState::$registered_abilities['flavor-agent/get-theme-tokens'] ?? null;

		$this->assertIsArray( $style_ability );
		$this->assertSame( [ 'scope', 'styleContext' ], $style_ability['input_schema']['required'] ?? null );
		$this->assertSame(
			'object',
			$style_ability['input_schema']['properties']['scope']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$style_ability['input_schema']['properties']['scope']['properties']['blockName']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$style_ability['input_schema']['properties']['styleContext']['properties']['styleBookTarget']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$style_ability['output_schema']['properties']['suggestions']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$style_ability['output_schema']['properties']['suggestions']['items']['properties']['operations']['items']['properties']['blockName']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$style_ability['output_schema']['properties']['suggestions']['items']['properties']['operations']['items']['properties']['variationTitle']['type'] ?? null
		);

		$this->assertIsArray( $status_ability );
		$this->assertSame(
			'object',
			$status_ability['output_schema']['properties']['surfaces']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$status_ability['output_schema']['properties']['surfaces']['properties']['content']['properties']['available']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$status_ability['output_schema']['properties']['surfaces']['properties']['globalStyles']['properties']['available']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$status_ability['output_schema']['properties']['surfaces']['properties']['styleBook']['properties']['available']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$status_ability['output_schema']['properties']['surfaces']['properties']['template']['properties']['message']['type'] ?? null
		);

		$this->assertIsArray( $tokens_ability );
		$this->assertSame(
			'object',
			$tokens_ability['output_schema']['properties']['diagnostics']['type'] ?? null
		);
	}
}
