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
		Registration::register_recommendation_abilities();

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

		$this->assertArrayHasKey( 'name', $selected_block['properties'] );
		$this->assertSame(
			[
				[ 'required' => [ 'blockName' ] ],
				[ 'required' => [ 'name' ] ],
			],
			$selected_block['anyOf'] ?? null
		);
		$this->assertArrayNotHasKey( 'required', $selected_block );

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

		$pre_filtering_counts = $block_ability['output_schema']['properties']['preFilteringCounts'] ?? null;
		$this->assertIsArray( $pre_filtering_counts );
		$this->assertSame( 'object', $pre_filtering_counts['type'] ?? null );
		$this->assertSame(
			'integer',
			$pre_filtering_counts['properties']['settings']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$pre_filtering_counts['properties']['styles']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$pre_filtering_counts['properties']['block']['type'] ?? null
		);
	}

	public function test_register_abilities_curates_ai_recommendations_off_universal_mcp(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

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
			// Write-side recommend-* are curated onto the dedicated flavor-agent
			// MCP server + the Abilities API, never the universal default server
			// (whose recommend surface is the read-only preview siblings). Mirrors
			// the external-apply abilities: no mcp key at all.
			$this->assertArrayNotHasKey(
				'mcp',
				$ability['meta'],
				"{$ability_id} must stay off the universal MCP server."
			);
		}

		$this->assertFalse(
			(bool) ( WordPressTestState::$registered_abilities['flavor-agent/check-status']['meta']['mcp']['public'] ?? false ),
			'check-status exposes backend-config inventory and must stay Abilities-API-only.'
		);
		$this->assertFalse(
			(bool) ( WordPressTestState::$registered_abilities['flavor-agent/list-synced-patterns']['meta']['mcp']['public'] ?? false ),
			'list-synced-patterns can return draft wp_block content and must stay Abilities-API-only.'
		);
	}

	public function test_registered_ability_schemas_do_not_emit_empty_properties_arrays(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		foreach ( WordPressTestState::$registered_abilities as $ability_id => $ability ) {
			foreach ( [ 'input_schema', 'output_schema' ] as $schema_key ) {
				$schema = $ability[ $schema_key ] ?? null;

				if ( ! is_array( $schema ) ) {
					continue;
				}

				$this->assertSchemaHasNoEmptyPropertiesArrays(
					$schema,
					"{$ability_id}.{$schema_key}"
				);
			}
		}
	}

	public function test_recommendation_ability_registration_delegates_contracts_to_ability_classes(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		foreach ( Registration::recommendation_ability_classes() as $ability_id => $definition ) {
			$raw = WordPressTestState::$raw_registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $raw, "Expected raw registration for {$ability_id}." );
			$this->assertSame( $definition['ability_class'], $raw['ability_class'] ?? null );
			$this->assertArrayNotHasKey( 'input_schema', $raw );
			$this->assertArrayNotHasKey( 'output_schema', $raw );
			$this->assertArrayNotHasKey( 'meta', $raw );
		}
	}

	public function test_register_abilities_emits_annotations_for_recommend_abilities(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$expected = [
			'destructive' => false,
			'idempotent'  => false,
			'openWorld'   => true,
		];

		foreach ( [
			'flavor-agent/recommend-block',
			'flavor-agent/recommend-content',
			'flavor-agent/recommend-patterns',
			'flavor-agent/recommend-template',
			'flavor-agent/recommend-template-part',
			'flavor-agent/recommend-post-blocks',
			'flavor-agent/recommend-navigation',
			'flavor-agent/recommend-style',
		] as $ability_id ) {
			$ability     = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$annotations = $ability['meta']['annotations'] ?? null;

			$this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
			$this->assertArrayNotHasKey( 'readonly', $annotations, "{$ability_id} must keep WP-format readonly unset so core executes it with POST." );
			$this->assertArrayNotHasKey( 'readOnlyHint', $annotations, "{$ability_id} must not claim direct MCP readOnlyHint because execution persists diagnostics and freshness tokens." );
			$this->assertSame( $expected, $annotations, "{$ability_id} should declare LLM-invoking annotations." );
		}
	}

	public function test_register_abilities_emits_annotations_for_read_abilities(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

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
			'flavor-agent/list-templates',
			'flavor-agent/get-active-theme',
			'flavor-agent/get-theme-presets',
			'flavor-agent/get-theme-styles',
			'flavor-agent/get-theme-tokens',
			'flavor-agent/check-status',
		] as $ability_id ) {
			$ability     = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$annotations = $ability['meta']['annotations'] ?? null;

			$this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
			$this->assertSame( $expected, $annotations, "{$ability_id} should declare read-only annotations." );
		}
	}

	public function test_external_agent_read_abilities_are_mcp_public(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$expected_mcp = [
			'public' => true,
			'type'   => 'tool',
		];

		foreach ( [
			'flavor-agent/introspect-block',
			'flavor-agent/list-allowed-blocks',
			'flavor-agent/list-patterns',
			'flavor-agent/get-pattern',
			'flavor-agent/list-template-parts',
			'flavor-agent/list-templates',
			'flavor-agent/get-active-theme',
			'flavor-agent/get-theme-presets',
			'flavor-agent/get-theme-styles',
			'flavor-agent/get-theme-tokens',
		] as $ability_id ) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$mcp     = $ability['meta']['mcp'] ?? null;

			$this->assertIsArray( $mcp, "{$ability_id} must declare meta.mcp for the universal MCP default server to discover it." );
			$this->assertSame( $expected_mcp, $mcp, "{$ability_id} meta.mcp must expose public:true and type:'tool'." );
		}
	}

	public function test_recommendation_abilities_fold_example_guidance_into_description_for_ability_explorer(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$expected_example_paths = [
			'flavor-agent/recommend-block'         => [ 'prompt' ],
			'flavor-agent/recommend-content'       => [ 'prompt', 'voiceProfile' ],
			'flavor-agent/recommend-patterns'      => [ 'postType', 'templateType', 'prompt' ],
			'flavor-agent/recommend-navigation'    => [ 'prompt' ],
			'flavor-agent/recommend-style'         => [ 'prompt' ],
			'flavor-agent/recommend-template'      => [ 'templateType', 'prompt' ],
			'flavor-agent/recommend-template-part' => [ 'prompt' ],
		];

		foreach ( $expected_example_paths as $ability_id => $properties ) {
			$schema = WordPressTestState::$registered_abilities[ $ability_id ]['input_schema']['properties'] ?? [];

			foreach ( $properties as $property ) {
				// The non-standard `example` keyword makes the Gutenberg
				// ajv-draft-04 validator (which the Abilities Explorer uses to
				// RUN abilities) throw at compile time, so example guidance is
				// folded into `description` instead.
				$this->assertArrayNotHasKey(
					'example',
					$schema[ $property ] ?? [],
					"{$ability_id}.input_schema.properties.{$property} must not use the non-draft-04 `example` keyword."
				);
				$this->assertStringContainsString(
					'For example:',
					(string) ( $schema[ $property ]['description'] ?? '' ),
					"{$ability_id}.input_schema.properties.{$property}.description should carry example guidance for the Abilities Explorer."
				);
			}
		}
	}

	public function test_preview_recommendation_abilities_inherit_example_guidance_for_ability_explorer(): void {
		Registration::register_category();
		Registration::register_abilities();

		$expected_example_paths = [
			'flavor-agent/preview-recommend-block'         => [ 'prompt' ],
			'flavor-agent/preview-recommend-navigation'    => [ 'prompt' ],
			'flavor-agent/preview-recommend-style'         => [ 'prompt' ],
			'flavor-agent/preview-recommend-template'      => [ 'templateType', 'prompt' ],
			'flavor-agent/preview-recommend-template-part' => [ 'prompt' ],
		];

		foreach ( $expected_example_paths as $ability_id => $properties ) {
			$schema = WordPressTestState::$registered_abilities[ $ability_id ]['input_schema']['properties'] ?? [];

			foreach ( $properties as $property ) {
				$this->assertArrayNotHasKey(
					'example',
					$schema[ $property ] ?? [],
					"{$ability_id} must not inherit the non-draft-04 `example` keyword from its parent recommend-* ability."
				);
				$this->assertStringContainsString(
					'For example:',
					(string) ( $schema[ $property ]['description'] ?? '' ),
					"{$ability_id} should inherit the {$property} example guidance (in description) from its parent recommend-* ability."
				);
			}
		}
	}

	public function test_register_preview_recommendation_abilities(): void {
		Registration::register_category();
		Registration::register_abilities();

		foreach (
			[
				'flavor-agent/preview-recommend-block',
				'flavor-agent/preview-recommend-navigation',
				'flavor-agent/preview-recommend-style',
				'flavor-agent/preview-recommend-template',
				'flavor-agent/preview-recommend-template-part',
			] as $ability_id
		) {
			$raw = WordPressTestState::$raw_registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $raw, "Expected raw registration for {$ability_id}." );
			$this->assertSame( 'flavor-agent', $raw['category'] ?? null, "{$ability_id} must register under the flavor-agent category." );
			$this->assertArrayHasKey( 'ability_class', $raw, "{$ability_id} must use the ability_class registration shape." );
		}
	}

	public function test_preview_recommendation_abilities_are_readonly_and_mcp_public(): void {
		Registration::register_category();
		Registration::register_abilities();

		$expected_meta_annotations = [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];

		foreach (
			[
				'flavor-agent/preview-recommend-block',
				'flavor-agent/preview-recommend-navigation',
				'flavor-agent/preview-recommend-style',
				'flavor-agent/preview-recommend-template',
				'flavor-agent/preview-recommend-template-part',
			] as $ability_id
		) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $ability, "{$ability_id} should be registered." );
			$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );
			$this->assertTrue( (bool) ( $ability['meta']['readonly'] ?? false ) );
			$this->assertSame(
				[
					'public' => true,
					'type'   => 'tool',
				],
				$ability['meta']['mcp'] ?? null
			);
			$this->assertSame( $expected_meta_annotations, $ability['meta']['annotations'] ?? null );
		}
	}

	public function test_preview_recommendation_abilities_are_available_without_feature_gate(): void {
		WordPressTestState::$options['wpai_features_enabled']             = false;
		WordPressTestState::$options['wpai_feature_flavor-agent_enabled'] = false;

		Registration::register_category();
		Registration::register_abilities();

		foreach (
			[
				'flavor-agent/preview-recommend-block',
				'flavor-agent/preview-recommend-navigation',
				'flavor-agent/preview-recommend-style',
				'flavor-agent/preview-recommend-template',
				'flavor-agent/preview-recommend-template-part',
			] as $ability_id
		) {
			$this->assertArrayHasKey(
				$ability_id,
				WordPressTestState::$registered_abilities,
				"{$ability_id} should register on the always-on helper path regardless of the Flavor Agent feature gate."
			);
		}
	}

	public function test_preview_recommendation_abilities_strip_resolve_signature_only_and_client_request_from_schema(): void {
		Registration::register_category();
		Registration::register_abilities();

		foreach (
			[
				'flavor-agent/preview-recommend-block',
				'flavor-agent/preview-recommend-navigation',
				'flavor-agent/preview-recommend-style',
				'flavor-agent/preview-recommend-template',
				'flavor-agent/preview-recommend-template-part',
			] as $ability_id
		) {
			$properties = WordPressTestState::$registered_abilities[ $ability_id ]['input_schema']['properties'] ?? [];

			$this->assertArrayNotHasKey( 'resolveSignatureOnly', $properties, "{$ability_id} must strip resolveSignatureOnly from its public input schema." );
			$this->assertArrayNotHasKey( 'clientRequest', $properties, "{$ability_id} must strip clientRequest from its public input schema." );
		}
	}

	public function test_internal_read_abilities_are_not_mcp_public(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		foreach ( [
			'flavor-agent/list-synced-patterns',
			'flavor-agent/get-synced-pattern',
			'flavor-agent/check-status',
		] as $ability_id ) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $ability, "{$ability_id} should be registered." );
			$this->assertArrayNotHasKey( 'mcp', $ability['meta'] ?? [], "{$ability_id} must remain Abilities-API-only (synced-pattern entities and backend inventory stay editor-internal)." );
		}
	}

	public function test_search_wordpress_docs_does_not_claim_readonly_annotations(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$ability     = WordPressTestState::$registered_abilities['flavor-agent/search-wordpress-docs'] ?? null;
		$annotations = $ability['meta']['annotations'] ?? null;

		$this->assertIsArray( $annotations );
		$this->assertArrayNotHasKey( 'readonly', $annotations );
		$this->assertArrayNotHasKey( 'readOnlyHint', $annotations );
		$this->assertSame(
			[
				'destructive' => false,
				'idempotent'  => false,
				'openWorld'   => true,
			],
			$annotations
		);
	}

	public function test_read_only_optional_object_inputs_default_to_empty_object(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		foreach ( [
			'flavor-agent/list-allowed-blocks',
			'flavor-agent/list-patterns',
			'flavor-agent/list-synced-patterns',
			'flavor-agent/list-template-parts',
			'flavor-agent/list-templates',
			'flavor-agent/get-active-theme',
			'flavor-agent/get-theme-presets',
			'flavor-agent/get-theme-styles',
			'flavor-agent/get-theme-tokens',
			'flavor-agent/check-status',
		] as $ability_id ) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $ability, "{$ability_id} should be registered." );
			$this->assertSame( [], $ability['input_schema']['default'] ?? null, "{$ability_id} should support omitted GET input." );
		}
	}

	public function test_register_abilities_annotation_expectations_cover_every_registered_ability(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();
		Registration::register_external_apply_abilities();

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
			'flavor-agent/list-templates',
			'flavor-agent/get-active-theme',
			'flavor-agent/get-theme-presets',
			'flavor-agent/get-theme-styles',
			'flavor-agent/get-theme-tokens',
			'flavor-agent/check-status',
			'flavor-agent/search-wordpress-docs',
			'flavor-agent/preview-recommend-block',
			'flavor-agent/preview-recommend-navigation',
			'flavor-agent/preview-recommend-style',
			'flavor-agent/preview-recommend-template',
			'flavor-agent/preview-recommend-template-part',
			'flavor-agent/preview-recommend-post-blocks',
			'flavor-agent/recommend-post-blocks',
			'flavor-agent/request-style-apply',
			'flavor-agent/request-template-part-apply',
			'flavor-agent/request-template-apply',
			'flavor-agent/request-post-blocks-apply',
			'flavor-agent/get-activity',
			'flavor-agent/list-activity',
			'flavor-agent/undo-activity',
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

	public function test_external_apply_abilities_register_behind_the_feature_gate(): void {
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		\FlavorAgent\AI\FeatureBootstrap::register_global_helper_abilities();

		foreach ( [
			'flavor-agent/request-style-apply',
			'flavor-agent/request-template-part-apply',
			'flavor-agent/request-template-apply',
			'flavor-agent/request-post-blocks-apply',
			'flavor-agent/get-activity',
			'flavor-agent/list-activity',
			'flavor-agent/undo-activity',
		] as $ability_id ) {
			$this->assertArrayHasKey(
				$ability_id,
				WordPressTestState::$registered_abilities,
				"{$ability_id} should register when the Flavor Agent feature is enabled."
			);
		}
	}

	public function test_external_apply_abilities_do_not_register_without_the_feature_gate(): void {
		WordPressTestState::$options = [];

		\FlavorAgent\AI\FeatureBootstrap::register_global_helper_abilities();

		$this->assertArrayNotHasKey(
			'flavor-agent/request-style-apply',
			WordPressTestState::$registered_abilities
		);
		$this->assertArrayNotHasKey(
			'flavor-agent/request-template-part-apply',
			WordPressTestState::$registered_abilities
		);
		$this->assertArrayNotHasKey(
			'flavor-agent/request-template-apply',
			WordPressTestState::$registered_abilities
		);
		$this->assertArrayNotHasKey(
			'flavor-agent/request-post-blocks-apply',
			WordPressTestState::$registered_abilities
		);
		$this->assertArrayNotHasKey(
			'flavor-agent/get-activity',
			WordPressTestState::$registered_abilities
		);
		$this->assertArrayNotHasKey(
			'flavor-agent/list-activity',
			WordPressTestState::$registered_abilities
		);
		$this->assertArrayNotHasKey(
			'flavor-agent/undo-activity',
			WordPressTestState::$registered_abilities
		);
	}

	public function test_external_apply_abilities_are_never_mcp_public_and_declare_expected_annotations(): void {
		$expectations = [
			'flavor-agent/request-style-apply'         => [
				'destructive' => false,
				'idempotent'  => false,
			],
			'flavor-agent/request-template-part-apply' => [
				'destructive' => false,
				'idempotent'  => false,
			],
			'flavor-agent/request-template-apply'      => [
				'destructive' => false,
				'idempotent'  => false,
			],
			'flavor-agent/request-post-blocks-apply'   => [
				'destructive' => false,
				'idempotent'  => false,
			],
			'flavor-agent/get-activity'                => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'flavor-agent/list-activity'               => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'flavor-agent/undo-activity'               => [
				'destructive' => true,
				'idempotent'  => false,
			],
		];

		foreach ( $expectations as $ability_id => $annotations ) {
			$meta = Registration::external_apply_meta( $ability_id );

			$this->assertTrue( $meta['show_in_rest'] );
			$this->assertArrayNotHasKey(
				'mcp',
				$meta,
				"{$ability_id} must stay off the universal MCP server — activity rows can carry prompts."
			);

			foreach ( $annotations as $key => $value ) {
				$this->assertSame( $value, $meta['annotations'][ $key ], "{$ability_id} annotation {$key}" );
			}
		}
	}

	public function test_selected_block_schema_bounds_parent_and_sibling_summaries(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

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
		Registration::register_recommendation_abilities();

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
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-content'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );
		$this->assertArrayNotHasKey( 'mcp', $ability['meta'] );
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
			'integer',
			$ability['input_schema']['properties']['postContext']['properties']['postId']['type'] ?? null
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
		Registration::register_recommendation_abilities();

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
		Registration::register_recommendation_abilities();

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
			'boolean',
			$recommend_ability['input_schema']['properties']['resolveSignatureOnly']['type'] ?? null
		);
		$this->assertStringContainsString(
			'server-issued apply-context signature',
			(string) ( $recommend_ability['input_schema']['properties']['resolveSignatureOnly']['description'] ?? '' )
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
			'integer',
			$recommend_ability['output_schema']['properties']['diagnostics']['properties']['filteredCandidates']['properties']['unreadableSyncedPatterns']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$recommend_ability['output_schema']['properties']['reviewContextSignature']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$recommend_ability['output_schema']['properties']['resolvedContextSignature']['type'] ?? null
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
			'editorStructure',
			$recommend_ability['input_schema']['properties'] ?? []
		);
	}

	public function test_register_abilities_exposes_ranking_contract_in_all_recommendation_surfaces(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$cases = [
			'flavor-agent/recommend-template'      => 'suggestions',
			'flavor-agent/recommend-template-part' => 'suggestions',
			'flavor-agent/recommend-navigation'    => 'suggestions',
			'flavor-agent/recommend-style'         => 'suggestions',
		];

		foreach ( $cases as $ability_id => $list_key ) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$this->assertIsArray( $ability, "Expected registered ability {$ability_id}." );

			$ranking = $ability['output_schema']['properties'][ $list_key ]['items']['properties']['ranking'] ?? null;

			$this->assertIsArray( $ranking, "{$ability_id} should declare ranking schema on its suggestion items." );
			$this->assertSame( 'object', $ranking['type'] ?? null, "{$ability_id} ranking should be an object." );
			$this->assertSame( 'number', $ranking['properties']['score']['type'] ?? null );
			$this->assertSame( 'string', $ranking['properties']['safetyMode']['type'] ?? null );
			$this->assertSame( 'array', $ranking['properties']['sourceSignals']['type'] ?? null );
			$this->assertSame( 'object', $ranking['properties']['freshnessMeta']['type'] ?? null );
			$this->assertSame( 'string', $ranking['properties']['designPrinciple']['type'] ?? null );
			$this->assertSame( 'string', $ranking['properties']['risk']['type'] ?? null );
			$this->assertSame( 'number', $ranking['properties']['modelScore']['type'] ?? null );
			$this->assertSame( 'number', $ranking['properties']['deterministicScore']['type'] ?? null );
			$this->assertSame( 'number', $ranking['properties']['contextScore']['type'] ?? null );
			$this->assertSame( 'number', $ranking['properties']['blendedScore']['type'] ?? null );
			$this->assertSame( 'object', $ranking['properties']['contextEvidence']['type'] ?? null );
			$this->assertSame( 'object', $ranking['properties']['contextPenalties']['type'] ?? null );
			$this->assertSame( 'string', $ranking['properties']['rankingVersion']['type'] ?? null );
		}

		$block_ability  = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'] ?? null;
		$block_ranking  = $block_ability['output_schema']['properties']['settings']['items']['properties']['ranking'] ?? null;
		$styles_ranking = $block_ability['output_schema']['properties']['styles']['items']['properties']['ranking'] ?? null;
		$blocks_ranking = $block_ability['output_schema']['properties']['block']['items']['properties']['ranking'] ?? null;

		$this->assertIsArray( $block_ranking, 'recommend-block settings items should declare ranking.' );
		$this->assertIsArray( $styles_ranking, 'recommend-block styles items should declare ranking.' );
		$this->assertIsArray( $blocks_ranking, 'recommend-block block items should declare ranking.' );
		$this->assertSame( 'number', $block_ranking['properties']['score']['type'] ?? null );
		$this->assertSame( 'string', $block_ranking['properties']['designPrinciple']['type'] ?? null );
		$this->assertSame( 'string', $block_ranking['properties']['risk']['type'] ?? null );
		$this->assertSame( 'string', $styles_ranking['properties']['designPrinciple']['type'] ?? null );
		$this->assertSame( 'string', $styles_ranking['properties']['risk']['type'] ?? null );
		$this->assertSame( 'string', $blocks_ranking['properties']['designPrinciple']['type'] ?? null );
		$this->assertSame( 'string', $blocks_ranking['properties']['risk']['type'] ?? null );
		$this->assertSame( 'number', $block_ranking['properties']['contextScore']['type'] ?? null );
		$this->assertSame( 'object', $styles_ranking['properties']['contextEvidence']['type'] ?? null );
		$this->assertSame( 'string', $blocks_ranking['properties']['rankingVersion']['type'] ?? null );
	}

	public function test_register_abilities_declares_template_advisory_fields_in_output_schema(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-template'] ?? null;
		$item    = $ability['output_schema']['properties']['suggestions']['items'] ?? null;

		$this->assertIsArray( $item );
		$this->assertSame( 'array', $item['properties']['templateParts']['type'] ?? null, 'Template suggestions emit templateParts; the schema should advertise it.' );
		$this->assertSame( 'string', $item['properties']['templateParts']['items']['properties']['slug']['type'] ?? null );
		$this->assertSame( 'string', $item['properties']['templateParts']['items']['properties']['area']['type'] ?? null );
		$this->assertSame( 'array', $item['properties']['patternSuggestions']['type'] ?? null );
		$this->assertSame( 'string', $item['properties']['patternSuggestions']['items']['type'] ?? null );
	}

	public function test_register_abilities_exposes_ranking_contract_in_pattern_recommendations(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-patterns'] ?? null;

		$this->assertIsArray( $ability );

		$ranking = $ability['output_schema']['properties']['recommendations']['items']['properties']['ranking'] ?? null;

		$this->assertIsArray( $ranking, 'Pattern recommendation items should declare a ranking schema for MCP/Ability consumers.' );
		$this->assertSame( 'object', $ranking['type'] ?? null );
		$this->assertTrue(
			(bool) ( $ranking['additionalProperties'] ?? false ),
			'ranking stays open-ended so surface-specific freshness metadata round-trips.'
		);
		$this->assertSame( 'number', $ranking['properties']['score']['type'] ?? null );
		$this->assertSame( 0, $ranking['properties']['score']['minimum'] ?? null );
		$this->assertSame( 1, $ranking['properties']['score']['maximum'] ?? null );
		$this->assertSame( 'string', $ranking['properties']['reason']['type'] ?? null );
		$this->assertSame( 'array', $ranking['properties']['sourceSignals']['type'] ?? null );
		$this->assertSame( 'string', $ranking['properties']['sourceSignals']['items']['type'] ?? null );
		$this->assertSame( 'string', $ranking['properties']['safetyMode']['type'] ?? null );
		$this->assertSame( 'object', $ranking['properties']['freshnessMeta']['type'] ?? null );
		$this->assertSame( 'object', $ranking['properties']['rankingHint']['type'] ?? null );
		$this->assertSame( 'string', $ranking['properties']['advisoryType']['type'] ?? null );
		$this->assertSame( 'string', $ranking['properties']['designPrinciple']['type'] ?? null );
		$this->assertSame( 'string', $ranking['properties']['risk']['type'] ?? null );
		$this->assertSame( 'array', $ranking['properties']['operations']['type'] ?? null );
		$this->assertSame( 'object', $ranking['properties']['operations']['items']['type'] ?? null );
		$this->assertSame( 'number', $ranking['properties']['contextScore']['type'] ?? null );
		$this->assertSame( 'object', $ranking['properties']['contextEvidence']['type'] ?? null );
		$this->assertSame( 'string', $ranking['properties']['rankingVersion']['type'] ?? null );
	}

	public function test_recommendation_abilities_include_image_guidelines_in_declared_categories(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$cases = [
			'flavor-agent/recommend-block'         => [
				'blockName'  => 'core/image',
				'categories' => [ 'site', 'copy', 'images', 'additional' ],
			],
			'flavor-agent/recommend-content'       => [
				'blockName'  => null,
				'categories' => [ 'site', 'copy', 'images', 'additional' ],
			],
			'flavor-agent/recommend-patterns'      => [
				'blockName'  => null,
				'categories' => [ 'site', 'images', 'additional' ],
			],
			'flavor-agent/recommend-navigation'    => [
				'blockName'  => null,
				'categories' => [ 'site', 'copy', 'images', 'additional' ],
			],
			'flavor-agent/recommend-style'         => [
				'blockName'  => null,
				'categories' => [ 'site', 'images', 'additional' ],
			],
			'flavor-agent/recommend-template'      => [
				'blockName'  => null,
				'categories' => [ 'site', 'copy', 'images', 'additional' ],
			],
			'flavor-agent/recommend-template-part' => [
				'blockName'  => null,
				'categories' => [ 'site', 'copy', 'images', 'additional' ],
			],
		];

		foreach ( $cases as $ability_id => $case ) {
			WordPressTestState::$wpai_formatted_guidelines = '<guidelines>Respect imagery.</guidelines>';
			WordPressTestState::$wpai_guideline_calls      = [];

			$ability = WordPressTestState::$registered_abilities[ $ability_id ]['execute_callback'][0] ?? null;

			$this->assertInstanceOf( \FlavorAgent\AI\Abilities\RecommendationAbility::class, $ability, "Expected ability object for {$ability_id}." );

			$instruction = $ability->get_system_instruction(
				null,
				[
					'ability'    => $ability_id,
					'block_name' => $case['blockName'],
				]
			);

			$this->assertSame( '<guidelines>Respect imagery.</guidelines>', $instruction, "Expected {$ability_id} to return formatted guidelines." );
			$this->assertSame(
				[
					[
						'categories' => $case['categories'],
						'blockName'  => $case['blockName'],
					],
				],
				WordPressTestState::$wpai_guideline_calls,
				"Expected {$ability_id} to request the complete guideline category set."
			);
		}
	}

	public function test_register_abilities_exposes_design_helper_schemas(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

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
			$styles_ability['output_schema']['properties']['scope']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$styles_ability['output_schema']['properties']['scope']['properties']['globalStylesId']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$styles_ability['output_schema']['properties']['styleContext']['type'] ?? null
		);
		$this->assertSame(
			'object',
			$styles_ability['output_schema']['properties']['styleContext']['properties']['currentConfig']['type'] ?? null
		);
		$this->assertSame(
			'array',
			$styles_ability['output_schema']['properties']['styleContext']['properties']['availableVariations']['type'] ?? null
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
		$this->assertSame(
			'string',
			$template_parts_ability['output_schema']['properties']['templateParts']['items']['properties']['id']['type'] ?? null
		);
	}

	public function test_list_template_parts_permission_callback_is_not_request_sensitive(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

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

	public function test_recommendation_permission_callback_checks_specific_post_when_input_has_post_id(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability             = WordPressTestState::$registered_abilities['flavor-agent/recommend-content'] ?? null;
		$permission_callback = $ability['permission_callback'] ?? null;

		$this->assertIsCallable( $permission_callback );

		WordPressTestState::$capabilities = [
			'edit_posts'    => true,
			'edit_post:42'  => false,
			'edit_post:101' => true,
		];

		$this->assertFalse(
			$permission_callback(
				[
					'postContext' => [
						'postId' => 42,
					],
				]
			)
		);
		$this->assertTrue(
			$permission_callback(
				[
					'document' => [
						'scopeKey' => 'post:101',
					],
				]
			)
		);
		$this->assertTrue( $permission_callback( [] ) );
	}

	public function test_block_recommendation_permission_callback_checks_explicit_post_scope(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability             = WordPressTestState::$registered_abilities['flavor-agent/recommend-block'] ?? null;
		$permission_callback = $ability['permission_callback'] ?? null;

		$this->assertIsCallable( $permission_callback );

		WordPressTestState::$capabilities = [
			'edit_posts'    => true,
			'edit_post:42'  => false,
			'edit_post:101' => true,
		];

		$this->assertFalse(
			$permission_callback(
				[
					'editorContext' => [
						'postId' => 42,
					],
				]
			)
		);
		$this->assertTrue(
			$permission_callback(
				[
					'document' => [
						'scopeKey' => 'post:101',
					],
				]
			)
		);
		$this->assertTrue( $permission_callback( [] ) );
	}

	public function test_recommend_patterns_schema_exposes_request_purpose_model_request_and_runtime_signature(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-patterns'] ?? null;
		$this->assertIsArray( $ability );

		$input_properties = $ability['input_schema']['properties'] ?? [];
		$this->assertArrayHasKey( 'requestPurpose', $input_properties );
		$this->assertSame( 'string', $input_properties['requestPurpose']['type'] ?? null );
		$this->assertTrue( $ability['input_schema']['additionalProperties'] ?? false );

		$output_properties = $ability['output_schema']['properties'] ?? [];
		$this->assertArrayHasKey( 'patternRuntimeSignature', $output_properties );
		$this->assertSame( 'string', $output_properties['patternRuntimeSignature']['type'] ?? null );
		$this->assertArrayHasKey( 'modelRequest', $output_properties['diagnostics']['properties'] ?? [] );
	}

	public function test_pattern_recommendation_permission_callback_checks_document_entity_scope(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability             = WordPressTestState::$registered_abilities['flavor-agent/recommend-patterns'] ?? null;
		$permission_callback = $ability['permission_callback'] ?? null;

		$this->assertIsCallable( $permission_callback );

		WordPressTestState::$capabilities = [
			'edit_posts'    => true,
			'edit_post:42'  => false,
			'edit_post:101' => true,
		];

		$this->assertFalse(
			$permission_callback(
				[
					'document' => [
						'scopeKey' => 'page:42',
						'postType' => 'page',
						'entityId' => '42',
					],
				]
			)
		);
		$this->assertTrue(
			$permission_callback(
				[
					'document' => [
						'scopeKey' => 'case_study:101',
						'postType' => 'case_study',
						'entityId' => '101',
					],
				]
			)
		);
		$this->assertTrue(
			$permission_callback(
				[
					'document' => [
						'scopeKey' => 'post:101',
					],
				]
			)
		);
		$this->assertTrue( $permission_callback( [] ) );
	}

	public function test_theme_recommendation_permission_callback_requires_theme_capability_without_post_checks(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability             = WordPressTestState::$registered_abilities['flavor-agent/recommend-template'] ?? null;
		$permission_callback = $ability['permission_callback'] ?? null;

		$this->assertIsCallable( $permission_callback );

		WordPressTestState::$capabilities = [
			'edit_theme_options' => false,
			'edit_post:77'       => true,
		];

		$this->assertFalse(
			$permission_callback(
				[
					'editorContext' => [
						'postId' => 77,
					],
				]
			)
		);

		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'edit_post:77'       => false,
		];

		$this->assertTrue(
			$permission_callback(
				[
					'editorContext' => [
						'postId' => 77,
					],
				]
			)
		);
		$this->assertTrue( $permission_callback( [] ) );
	}

	public function test_theme_scoped_recommendation_permissions_ignore_numeric_entity_ids(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$edit_post_calls                  = 0;
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'edit_post'          => static function () use ( &$edit_post_calls ): bool {
				++$edit_post_calls;

				return false;
			},
		];

		$cases = [
			'flavor-agent/recommend-style'         => [
				'scope'    => [
					'entityId'       => 77,
					'globalStylesId' => 101,
				],
				'document' => [
					'entityId' => 88,
				],
			],
			'flavor-agent/recommend-template'      => [
				'document' => [
					'entityId' => 77,
				],
			],
			'flavor-agent/recommend-template-part' => [
				'document' => [
					'entityId' => 77,
				],
			],
			'flavor-agent/recommend-navigation'    => [
				'document' => [
					'entityId' => 77,
				],
			],
		];

		foreach ( $cases as $ability_id => $input ) {
			$permission_callback = WordPressTestState::$registered_abilities[ $ability_id ]['permission_callback'] ?? null;

			$this->assertIsCallable( $permission_callback, "Expected permission callback for {$ability_id}." );
			$this->assertTrue( $permission_callback( $input ), "Expected {$ability_id} to use theme permissions only." );
		}

		$this->assertSame( 0, $edit_post_calls );
	}

	public function test_recommendation_ability_system_instruction_uses_declared_guideline_categories(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-block']['execute_callback'][0] ?? null;

		$this->assertInstanceOf( \FlavorAgent\AI\Abilities\RecommendBlockAbility::class, $ability );

		WordPressTestState::$wpai_formatted_guidelines = '<guidelines>Respect site voice.</guidelines>';
		$seen_ability                                  = null;

		add_filter(
			'wpai_system_instruction',
			static function ( string $instruction, string $ability_name ) use ( &$seen_ability ): string {
				$seen_ability = $ability_name;

				return $instruction;
			},
			10,
			3
		);

		$instruction = $ability->get_system_instruction(
			null,
			[
				'ability'    => 'flavor-agent/recommend-block',
				'block_name' => 'core/paragraph',
			]
		);

		$this->assertSame( '<guidelines>Respect site voice.</guidelines>', $instruction );
		$this->assertSame( 'flavor-agent/recommend-block', $seen_ability );
		$this->assertSame(
			[
				[
					'categories' => [ 'site', 'copy', 'images', 'additional' ],
					'blockName'  => 'core/paragraph',
				],
			],
			WordPressTestState::$wpai_guideline_calls
		);
	}

	public function test_recommendation_ability_guideline_context_accepts_selected_block_alias(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-block']['execute_callback'][0] ?? null;

		$this->assertInstanceOf( \FlavorAgent\AI\Abilities\RecommendBlockAbility::class, $ability );

		WordPressTestState::$wpai_formatted_guidelines = '<guidelines>Respect paragraph voice.</guidelines>';

		$result = $ability->execute_callback(
			[
				'selectedBlock'        => [
					'blockName'  => 'core/paragraph',
					'attributes' => [],
				],
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				[
					'categories' => [ 'site', 'copy', 'images', 'additional' ],
					'blockName'  => 'core/paragraph',
				],
			],
			WordPressTestState::$wpai_guideline_calls
		);
	}

	public function test_recommend_block_accepts_selected_block_name_alias(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-block']['execute_callback'][0] ?? null;

		$this->assertInstanceOf( \FlavorAgent\AI\Abilities\RecommendBlockAbility::class, $ability );

		$result = $ability->execute_callback(
			[
				'selectedBlock'        => [
					'name'       => 'core/paragraph',
					'attributes' => [],
				],
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['resolvedContextSignature'] ?? '' );
	}

	public function test_recommendation_input_schemas_include_client_request_identity(): void {
		foreach ( Registration::recommendation_ability_classes() as $ability_id => $_definition ) {
			$schema         = Registration::recommendation_input_schema( $ability_id );
			$client_request = $schema['properties']['clientRequest'] ?? null;

			$this->assertIsArray( $client_request, "Expected clientRequest schema for {$ability_id}." );
			$this->assertSame( 'object', $client_request['type'] ?? null );
			$this->assertSame( 'string', $client_request['properties']['sessionId']['type'] ?? null );
			$this->assertSame( 'integer', $client_request['properties']['requestToken']['type'] ?? null );
			$this->assertSame( 'string', $client_request['properties']['abortId']['type'] ?? null );
			$this->assertSame( 'boolean', $client_request['properties']['aborted']['type'] ?? null );
			$this->assertSame( 'string', $client_request['properties']['scopeKey']['type'] ?? null );
		}
	}

	public function test_register_abilities_wordpress_docs_schema_drops_entity_key(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/search-wordpress-docs'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertSame( [ 'query' ], $ability['input_schema']['required'] ?? null );
		$this->assertArrayNotHasKey(
			'entityKey',
			$ability['input_schema']['properties'] ?? []
		);
		$this->assertSame(
			'object',
			$ability['output_schema']['properties']['docsGrounding']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['docsGroundingFingerprint']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$ability['output_schema']['properties']['guidance']['items']['properties']['contentHash']['type'] ?? null
		);
		$this->assertArrayNotHasKey(
			'retrievedAt',
			$ability['output_schema']['properties']['guidance']['items']['properties'] ?? []
		);
	}

	public function test_recommendation_output_schemas_expose_docs_grounding_contract(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		foreach (
			[
				'flavor-agent/recommend-block',
				'flavor-agent/recommend-patterns',
				'flavor-agent/recommend-navigation',
				'flavor-agent/recommend-style',
				'flavor-agent/recommend-template',
				'flavor-agent/recommend-template-part',
			] as $ability_id
		) {
			$ability    = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
			$properties = is_array( $ability['output_schema']['properties'] ?? null )
				? $ability['output_schema']['properties']
				: [];

			$docs_grounding_properties = $properties['docsGrounding']['properties'] ?? [];

			$this->assertSame( 'object', $properties['docsGrounding']['type'] ?? null, $ability_id );
			$this->assertSame(
				[ 'available', 'sourceTypes', 'count', 'contentFingerprint', 'runtimeFingerprint', 'reason', 'source', 'errorCode' ],
				array_keys( $docs_grounding_properties ),
				$ability_id
			);
			$this->assertSame( 'boolean', $docs_grounding_properties['available']['type'] ?? null, $ability_id );
			$this->assertSame( 'array', $docs_grounding_properties['sourceTypes']['type'] ?? null, $ability_id );
			$this->assertSame( 'integer', $docs_grounding_properties['count']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $docs_grounding_properties['contentFingerprint']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $docs_grounding_properties['runtimeFingerprint']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $docs_grounding_properties['reason']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $docs_grounding_properties['source']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $docs_grounding_properties['errorCode']['type'] ?? null, $ability_id );
			$this->assertSame( 'string', $properties['docsGroundingFingerprint']['type'] ?? null, $ability_id );
		}
	}

	public function test_register_abilities_exposes_structured_template_operation_schema(): void {
		Registration::register_category();
		Registration::register_abilities();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-template'] ?? null;

		$this->assertIsArray( $ability );
		$this->assertTrue( (bool) ( $ability['meta']['show_in_rest'] ?? false ) );
		$this->assertSame(
			'object',
			$ability['input_schema']['properties']['designSemantics']['type'] ?? null
		);

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
		Registration::register_recommendation_abilities();

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
			'object',
			$ability['input_schema']['properties']['designSemantics']['type'] ?? null
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
		Registration::register_recommendation_abilities();

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
		Registration::register_recommendation_abilities();

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
		// The docs runtime signal returned by InfraAbilities::check_status() must
		// stay declared so schema-driven clients see the full payload contract.
		$this->assertSame(
			'object',
			$status_ability['output_schema']['properties']['backends']['properties']['cloudflare_ai_search']['properties']['runtime']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$status_ability['output_schema']['properties']['backends']['properties']['cloudflare_ai_search']['properties']['runtime']['properties']['status']['type'] ?? null
		);
		$this->assertSame(
			'integer',
			$status_ability['output_schema']['properties']['backends']['properties']['cloudflare_ai_search']['properties']['runtime']['properties']['lastResultCount']['type'] ?? null
		);
		$this->assertSame(
			'string',
			$status_ability['output_schema']['properties']['backends']['properties']['cloudflare_ai_search']['properties']['runtime']['properties']['lastReason']['type'] ?? null
		);

		$this->assertIsArray( $tokens_ability );
		$this->assertSame(
			'object',
			$tokens_ability['output_schema']['properties']['diagnostics']['type'] ?? null
		);
	}

	public function test_register_recommendation_abilities_use_flavor_agent_category(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		foreach ( Registration::recommendation_ability_classes() as $ability_id => $definition ) {
			$ability = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;

			$this->assertIsArray( $ability, "Expected registered ability {$ability_id}." );
			$this->assertSame(
				'flavor-agent',
				$ability['category'] ?? null,
				"{$ability_id} must report category 'flavor-agent' so the Ability Explorer groups it under the plugin."
			);
		}
	}

	public function test_recommend_content_mode_advertises_enum_and_default(): void {
		Registration::register_category();
		Registration::register_recommendation_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/recommend-content'] ?? null;
		$mode    = $ability['input_schema']['properties']['mode'] ?? null;

		$this->assertIsArray( $mode );
		$this->assertSame( 'string', $mode['type'] ?? null );
		$this->assertSame( [ 'draft', 'edit', 'critique' ], $mode['enum'] ?? null );
		$this->assertSame( 'draft', $mode['default'] ?? null );
	}

	public function test_list_synced_patterns_advertises_sync_status_enum_and_default(): void {
		Registration::register_category();
		Registration::register_abilities();

		$ability = WordPressTestState::$registered_abilities['flavor-agent/list-synced-patterns'] ?? null;
		$sync    = $ability['input_schema']['properties']['syncStatus'] ?? null;

		$this->assertIsArray( $sync );
		$this->assertSame( 'string', $sync['type'] ?? null );
		$this->assertSame( [ 'synced', 'partial', 'unsynced', 'all' ], $sync['enum'] ?? null );
		$this->assertSame( 'synced', $sync['default'] ?? null );
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function assertSchemaHasNoEmptyPropertiesArrays( array $schema, string $path ): void {
		if ( array_key_exists( 'properties', $schema ) ) {
			$this->assertNotSame(
				[],
				$schema['properties'],
				"{$path}.properties must be omitted for open object schemas without declared child properties."
			);
		}

		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->assertSchemaHasNoEmptyPropertiesArrays(
					$value,
					"{$path}.{$key}"
				);
			}
		}
	}
}
