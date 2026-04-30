<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\Registration;
use FlavorAgent\Context\BlockOperationValidator;
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

		foreach ( [ 'editingMode', 'childCount', 'supportsContentRole', 'structuralIdentity', 'structuralAncestors', 'structuralBranch', 'parentContext', 'siblingSummariesBefore', 'siblingSummariesAfter' ] as $field ) {
			$this->assertArrayHasKey( $field, $selected_block['properties'] );
		}

		$this->assertSame(
			'boolean',
			$ability['input_schema']['properties']['resolveSignatureOnly']['type'] ?? null
		);

		$block_ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'] ?? [];
		$suggestion    = $block_ability['output_schema']['properties']['block']['items'] ?? [];
		$operation     = $suggestion['properties']['operations']['items'] ?? [];
		$proposal      = $suggestion['properties']['proposedOperations']['items'] ?? [];
		$rejection     = $suggestion['properties']['rejectedOperations']['items'] ?? [];

		$this->assertSame( 'array', $suggestion['properties']['operations']['type'] ?? null );
		$this->assertSame( 'array', $suggestion['properties']['proposedOperations']['type'] ?? null );
		$this->assertSame( 'array', $suggestion['properties']['rejectedOperations']['type'] ?? null );
		$this->assertSame( $operation['properties'], $proposal['properties'] ?? null );
		$this->assertSame(
			[
				BlockOperationValidator::INSERT_PATTERN,
				BlockOperationValidator::REPLACE_BLOCK_WITH_PATTERN,
				null,
			],
			$operation['properties']['type']['enum'] ?? null
		);
		$this->assertSame( [ 'integer', 'null' ], $operation['properties']['catalogVersion']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $operation['properties']['patternName']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetClientId']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $operation['properties']['position']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $operation['properties']['action']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetSignature']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetSurface']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $operation['properties']['targetType']['type'] ?? null );
		$this->assertSame( [ 'object', 'null' ], $operation['properties']['expectedTarget']['type'] ?? null );
		$this->assertSame(
			[ 'string', 'null' ],
			$operation['properties']['expectedTarget']['properties']['clientId']['type'] ?? null
		);
		$this->assertSame(
			[ 'string', 'null' ],
			$operation['properties']['expectedTarget']['properties']['name']['type'] ?? null
		);
		$this->assertSame(
			[ 'object', 'null' ],
			$rejection['properties']['operation']['type'] ?? null
		);
		$this->assertContains(
			BlockOperationValidator::ERROR_CLIENT_SERVER_OPERATION_MISMATCH,
			$suggestion['properties']['rejectedOperations']['items']['properties']['code']['enum'] ?? []
		);

		$this->assertTrue( (bool) ( $selected_block['properties']['attributes']['additionalProperties'] ?? false ) );
		$this->assertTrue( (bool) ( $selected_block['properties']['blockVisibility']['additionalProperties'] ?? false ) );
		$this->assertTrue( (bool) ( $selected_block['properties']['structuralIdentity']['additionalProperties'] ?? false ) );
		$this->assertTrue(
			(bool) (
				$selected_block['properties']['structuralIdentity']['properties']['position']['additionalProperties']
				?? false
			)
		);
		$this->assertFalse(
			(bool) (
				$selected_block['properties']['structuralAncestors']['items']['additionalProperties']
				?? false
			)
		);
		$this->assertFalse(
			(bool) (
				$selected_block['properties']['structuralBranch']['items']['additionalProperties']
				?? false
			)
		);
		$this->assertFalse(
			(bool) (
				$selected_block['properties']['structuralBranch']['items']['properties']['children']['items']['additionalProperties']
				?? false
			)
		);
		$this->assertSame( 6, $selected_block['properties']['structuralAncestors']['maxItems'] ?? null );
		$this->assertSame( 6, $selected_block['properties']['structuralBranch']['maxItems'] ?? null );
		$this->assertSame( 3, $selected_block['properties']['siblingSummariesBefore']['maxItems'] ?? null );
		$this->assertSame( 3, $selected_block['properties']['siblingSummariesAfter']['maxItems'] ?? null );
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

	public function test_register_abilities_emits_annotations_for_recommend_abilities(): void {
		Registration::register_category();
		Registration::register_abilities();

		$expected = [
			'readOnlyHint' => true,
			'destructive'  => false,
			'idempotent'   => false,
		];

		foreach ( [
			'flavor-agent/recommend-block',
			'flavor-agent/recommend-content',
			'flavor-agent/recommend-patterns',
			'flavor-agent/recommend-template',
			'flavor-agent/recommend-template-part',
			'flavor-agent/recommend-navigation',
			'flavor-agent/recommend-style',
		] as $ability_id ) {
			$ability     = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$annotations = $ability['meta']['annotations'] ?? null;

			$this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
			$this->assertArrayNotHasKey( 'readonly', $annotations, "{$ability_id} must keep WP-format readonly unset so core executes it with POST." );
			$this->assertSame( $expected, $annotations, "{$ability_id} should declare LLM-invoking annotations." );
		}
	}

	public function test_register_abilities_emits_annotations_for_read_abilities(): void {
		Registration::register_category();
		Registration::register_abilities();

		$expected = [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];

		foreach ( [
			'flavor-agent/introspect-block',
			'flavor-agent/list-allowed-blocks',
			'flavor-agent/list-patterns',
			'flavor-agent/get-pattern',
			'flavor-agent/list-synced-patterns',
			'flavor-agent/get-synced-pattern',
			'flavor-agent/list-template-parts',
			'flavor-agent/get-active-theme',
			'flavor-agent/get-theme-presets',
			'flavor-agent/get-theme-styles',
			'flavor-agent/get-theme-tokens',
			'flavor-agent/check-status',
			'flavor-agent/search-wordpress-docs',
		] as $ability_id ) {
			$ability     = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$annotations = $ability['meta']['annotations'] ?? null;

			$this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
			$this->assertSame( $expected, $annotations, "{$ability_id} should declare read-only annotations." );
		}
	}

	public function test_register_abilities_annotation_expectations_cover_every_registered_ability(): void {
		Registration::register_category();
		Registration::register_abilities();

		$expected = [
			'flavor-agent/recommend-block',
			'flavor-agent/recommend-content',
			'flavor-agent/recommend-patterns',
			'flavor-agent/recommend-template',
			'flavor-agent/recommend-template-part',
			'flavor-agent/recommend-navigation',
			'flavor-agent/recommend-style',
			'flavor-agent/introspect-block',
			'flavor-agent/list-allowed-blocks',
			'flavor-agent/list-patterns',
			'flavor-agent/get-pattern',
			'flavor-agent/list-synced-patterns',
			'flavor-agent/get-synced-pattern',
			'flavor-agent/list-template-parts',
			'flavor-agent/get-active-theme',
			'flavor-agent/get-theme-presets',
			'flavor-agent/get-theme-styles',
			'flavor-agent/get-theme-tokens',
			'flavor-agent/check-status',
			'flavor-agent/search-wordpress-docs',
		];

		$actual = array_keys( WordPressTestState::$registered_abilities );
		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'Every registered ability should be assigned to an annotation group.' );

		foreach ( $actual as $ability_id ) {
			$annotations = WordPressTestState::$registered_abilities[ $ability_id ]['meta']['annotations'] ?? null;

			$this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
		}
	}

	public function test_selected_block_schema_bounds_parent_and_sibling_summaries(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability        = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'] ?? [];
		$selected_block = $ability['input_schema']['properties']['selectedBlock'] ?? [];
		$parent_schema  = $selected_block['properties']['parentContext'] ?? [];
		$sibling_schema = $selected_block['properties']['siblingSummariesBefore']['items'] ?? [];

		$this->assertFalse( (bool) ( $parent_schema['additionalProperties'] ?? true ) );
		$this->assertFalse( (bool) ( $parent_schema['properties']['visualHints']['additionalProperties'] ?? true ) );
		$this->assertFalse( (bool) ( $sibling_schema['additionalProperties'] ?? true ) );
		$this->assertFalse( (bool) ( $sibling_schema['properties']['visualHints']['additionalProperties'] ?? true ) );
	}

	public function test_structural_branch_schema_allows_selected_leaf_markers_at_max_depth(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability          = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'] ?? [];
		$selected_block   = $ability['input_schema']['properties']['selectedBlock'] ?? [];
		$branch_item      = $selected_block['properties']['structuralBranch']['items'] ?? [];
		$child_item       = $branch_item['properties']['children']['items'] ?? [];
		$grandchild_item  = $child_item['properties']['children']['items'] ?? [];
		$grandchild_props = $grandchild_item['properties'] ?? [];

		$this->assertArrayHasKey( 'children', $branch_item['properties'] ?? [] );
		$this->assertArrayHasKey( 'children', $child_item['properties'] ?? [] );
		$this->assertArrayHasKey( 'isSelected', $grandchild_props );
		$this->assertArrayNotHasKey( 'children', $grandchild_props );
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

	public function test_register_abilities_describes_resolve_signature_only_contracts_accurately(): void {
		Registration::register_category();
		Registration::register_abilities();

		$block_ability    = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'] ?? null;
		$template_ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-template'] ?? null;

		$this->assertIsArray( $block_ability );
		$this->assertIsArray( $template_ability );
		$this->assertStringContainsString(
			'apply-context signature',
			(string) ( $block_ability['input_schema']['properties']['resolveSignatureOnly']['description'] ?? '' )
		);
		$this->assertStringNotContainsString(
			'review/apply context signatures',
			(string) ( $block_ability['input_schema']['properties']['resolveSignatureOnly']['description'] ?? '' )
		);
		$this->assertStringContainsString(
			'review/apply context signatures',
			(string) ( $template_ability['input_schema']['properties']['resolveSignatureOnly']['description'] ?? '' )
		);
	}

	public function test_register_abilities_exposes_pattern_override_metadata_in_pattern_schemas(): void {
		Registration::register_category();
		Registration::register_abilities();

		$recommend_ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-patterns'] ?? null;
		$list_ability      = WordPressTestState::$registered_abilities['flavor-agent/list-patterns'] ?? null;
		$get_ability       = WordPressTestState::$registered_abilities['flavor-agent/get-pattern'] ?? null;
		$list_synced       = WordPressTestState::$registered_abilities['flavor-agent/list-synced-patterns'] ?? null;
		$get_synced        = WordPressTestState::$registered_abilities['flavor-agent/get-synced-pattern'] ?? null;

		$this->assertIsArray( $recommend_ability );
		$this->assertIsArray( $list_ability );
		$this->assertIsArray( $get_ability );
		$this->assertIsArray( $list_synced );
		$this->assertIsArray( $get_synced );
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
		$this->assertSame(
			'string',
			$list_ability['input_schema']['properties']['search']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$list_ability['input_schema']['properties']['includeContent']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$list_ability['input_schema']['properties']['limit']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$list_ability['input_schema']['properties']['offset']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$list_ability['output_schema']['properties']['total']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$list_ability['output_schema']['properties']['patterns']['items']['properties']['id']['type'] ?? null
		);
		$this->assertSame(
			[ 'patternId' ],
			$get_ability['input_schema']['required'] ?? null
		);
		$this->assertSame(
			'string',
			$get_ability['output_schema']['properties']['id']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$list_synced['input_schema']['properties']['syncStatus']['type'] ?? null
		);
		$this->assertStringContainsString(
			'partial',
			(string) ( $list_synced['input_schema']['properties']['syncStatus']['description'] ?? '' )
		);
		$this->assertSame(
			'string',
			$list_synced['input_schema']['properties']['search']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$list_synced['input_schema']['properties']['includeContent']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$list_synced['input_schema']['properties']['limit']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$list_synced['input_schema']['properties']['offset']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$list_synced['output_schema']['properties']['total']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$get_synced['input_schema']['properties']['patternId']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$list_synced['output_schema']['properties']['patterns']['items']['properties']['syncStatus']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$get_synced['output_schema']['properties']['wpPatternSyncStatus']['type'] ?? null
		);
		$this->assertArrayNotHasKey(
			'resolveSignatureOnly',
			$recommend_ability['input_schema']['properties'] ?? []
		);
		$this->assertArrayNotHasKey(
			'editorStructure',
			$recommend_ability['input_schema']['properties'] ?? []
		);
	}

	public function test_register_abilities_exposes_design_helper_schemas(): void {
		Registration::register_category();
		Registration::register_abilities();

		$blocks_ability       = WordPressTestState::$registered_abilities['flavor-agent/list-allowed-blocks'] ?? null;
		$active_theme_ability = WordPressTestState::$registered_abilities['flavor-agent/get-active-theme'] ?? null;
		$presets_ability      = WordPressTestState::$registered_abilities['flavor-agent/get-theme-presets'] ?? null;
		$styles_ability       = WordPressTestState::$registered_abilities['flavor-agent/get-theme-styles'] ?? null;

		$this->assertIsArray( $blocks_ability );
		$this->assertSame(
			'string',
			$blocks_ability['input_schema']['properties']['search']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$blocks_ability['input_schema']['properties']['category']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$blocks_ability['input_schema']['properties']['limit']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$blocks_ability['input_schema']['properties']['offset']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$blocks_ability['input_schema']['properties']['includeVariations']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$blocks_ability['input_schema']['properties']['maxVariations']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$blocks_ability['output_schema']['properties']['blocks']['items']['properties']['apiVersion']['type'] ?? null
		);
		$this->assertSame(
			[ 'array', 'null' ],
			$blocks_ability['output_schema']['properties']['blocks']['items']['properties']['allowedBlocks']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$blocks_ability['output_schema']['properties']['total']['type'] ?? null
		);

		$this->assertIsArray( $active_theme_ability );
		$this->assertSame(
			'string',
			$active_theme_ability['output_schema']['properties']['stylesheet']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$active_theme_ability['output_schema']['properties']['template']['type'] ?? null
		);

		$this->assertIsArray( $presets_ability );
		$this->assertSame(
			'array',
			$presets_ability['output_schema']['properties']['colorPresets']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$presets_ability['output_schema']['properties']['duotonePresets']['type'] ?? null
		);

		$this->assertIsArray( $styles_ability );
		$this->assertSame(
			'object',
			$styles_ability['output_schema']['properties']['styles']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$styles_ability['output_schema']['properties']['blockPseudoStyles']['type'] ?? null
		);

		$template_parts_ability = WordPressTestState::$registered_abilities['flavor-agent/list-template-parts'] ?? null;

		$this->assertIsArray( $template_parts_ability );
		$this->assertSame(
			'boolean',
			$template_parts_ability['input_schema']['properties']['includeContent']['type'] ?? null
		);
	}

	public function test_list_template_parts_permission_callback_is_not_request_sensitive(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability             = WordPressTestState::$registered_abilities['flavor-agent/list-template-parts'] ?? null;
		$permission_callback = $ability['permission_callback'] ?? null;

		$this->assertIsCallable( $permission_callback );

		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$this->assertTrue( $permission_callback( [] ) );
		$this->assertTrue( $permission_callback( [ 'includeContent' => false ] ) );
		$this->assertTrue( $permission_callback( [ 'includeContent' => true ] ) );

		WordPressTestState::$capabilities = [
			'edit_posts' => false,
		];

		$this->assertFalse( $permission_callback( [ 'includeContent' => true ] ) );

		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];

		$this->assertTrue( $permission_callback( [] ) );
		$this->assertTrue( $permission_callback( [ 'includeContent' => true ] ) );
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
			'boolean',
			$ability['input_schema']['properties']['resolveSignatureOnly']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['reviewContextSignature']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['resolvedContextSignature']['type'] ?? null
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
			'boolean',
			$ability['input_schema']['properties']['resolveSignatureOnly']['type'] ?? null
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
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['reviewContextSignature']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['resolvedContextSignature']['type'] ?? null
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
			'boolean',
			$ability['input_schema']['properties']['resolveSignatureOnly']['type'] ?? null
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
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['reviewContextSignature']['type'] ?? null
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
			$style_ability['input_schema']['properties']['styleContext']['properties']['templateStructure']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$style_ability['input_schema']['properties']['styleContext']['properties']['templateStructure']['items']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$style_ability['input_schema']['properties']['styleContext']['properties']['templateStructure']['items']['properties']['name']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$style_ability['input_schema']['properties']['styleContext']['properties']['templateStructure']['items']['properties']['innerBlocks']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$style_ability['input_schema']['properties']['styleContext']['properties']['templateVisibility']['type'] ?? null
		);
		$this->assertSame(
			'boolean',
			$style_ability['input_schema']['properties']['resolveSignatureOnly']['type'] ?? null
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
		$this->assertSame(
			'string',
			$style_ability['output_schema']['properties']['reviewContextSignature']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$style_ability['output_schema']['properties']['resolvedContextSignature']['type'] ?? null
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
