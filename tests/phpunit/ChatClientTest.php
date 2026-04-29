<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\ChatClient;
use FlavorAgent\LLM\ResponseSchema;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ChatClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_is_supported_when_wordpress_ai_client_has_a_text_generation_provider(): void {
		WordPressTestState::$ai_client_supported = true;

		$this->assertTrue( ChatClient::is_supported() );
	}

	public function test_is_not_supported_when_wordpress_ai_client_has_no_text_generation_provider(): void {
		WordPressTestState::$ai_client_supported = false;

		$this->assertFalse( ChatClient::is_supported() );
	}

	public function test_chat_routes_through_the_wordpress_ai_client(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		$result = ChatClient::chat( 'system prompt', 'user prompt' );

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);
		$this->assertSame( 'core_function', WordPressTestState::$last_ai_client_prompt['transport'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_chat_returns_unified_setup_message_when_no_backend_is_available(): void {
		$result = ChatClient::chat( 'system prompt', 'user prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text_generation_provider', $result->get_error_code() );
		$this->assertSame( ChatClient::get_setup_message(), $result->get_error_message() );
	}

	public function test_selected_connector_provider_pins_chat_to_that_connector(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider' => 'anthropic',
		];
		WordPressTestState::$connectors                     = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'description'    => 'Anthropic connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support     = [
			'anthropic' => true,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		$result = ChatClient::chat( 'system prompt', 'user prompt' );

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_selected_connector_provider_skips_unsupported_optional_prompt_features(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider' => 'anthropic',
		];
		WordPressTestState::$connectors                     = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'description'    => 'Anthropic connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support     = [
			'anthropic' => true,
		];
		WordPressTestState::$ai_client_feature_support      = [
			'reasoning'   => false,
			'json_schema' => false,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		$result = ChatClient::chat(
			'system prompt',
			'user prompt',
			[
				'type'       => 'object',
				'properties' => [
					'explanation' => [ 'type' => 'string' ],
				],
			]
		);

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertSame( 'system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertArrayNotHasKey( 'reasoning', WordPressTestState::$last_ai_client_prompt );
		$this->assertArrayNotHasKey( 'json_schema', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_connector_json_schema_normalizes_nullable_enums_for_schema_compatible_connector(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider' => 'openai',
		];
		WordPressTestState::$connectors                     = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support     = [
			'openai' => true,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		$result = ChatClient::chat(
			'system prompt',
			'user prompt',
			ResponseSchema::get( 'block' )
		);

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);

		$schema      = WordPressTestState::$last_ai_client_prompt['json_schema'] ?? [];
		$type_schema = $schema['properties']['settings']['items']['properties']['type'] ?? [];

		$this->assertArrayNotHasKey( 'type', $type_schema );
		$this->assertSame(
			[
				[
					'type' => 'string',
					'enum' => [ 'attribute_change', 'style_variation' ],
				],
				[
					'type' => 'null',
					'enum' => [ null ],
				],
			],
			$type_schema['anyOf'] ?? null
		);
	}

	public function test_connector_json_schema_closes_object_nodes_for_schema_compatible_connector(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider' => 'openai',
		];
		WordPressTestState::$connectors                     = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support     = [
			'openai' => true,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		ChatClient::chat(
			'system prompt',
			'user prompt',
			ResponseSchema::get( 'block' )
		);

		$schema = WordPressTestState::$last_ai_client_prompt['json_schema'] ?? [];

		$this->assertObjectSchemasDisallowAdditionalProperties( $schema );
	}

	public function test_anthropic_connector_skips_block_schema_that_exceeds_union_limit(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider' => 'anthropic',
		];
		WordPressTestState::$connectors                     = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'description'    => 'Anthropic connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support     = [
			'anthropic' => true,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		ChatClient::chat(
			'system prompt',
			'user prompt',
			ResponseSchema::get( 'block' )
		);

		$schema = WordPressTestState::$last_ai_client_prompt['json_schema'] ?? [];

		$this->assertArrayNotHasKey( 'json_schema', WordPressTestState::$last_ai_client_prompt );
		$this->assertLessThanOrEqual( 16, self::count_schema_unions( $schema ) );
	}

	private function assertObjectSchemasDisallowAdditionalProperties( array $schema, string $path = '$' ): void {
		if ( self::schema_includes_type( $schema, 'object' ) ) {
			$this->assertArrayHasKey( 'additionalProperties', $schema, $path );
			$this->assertFalse( $schema['additionalProperties'], $path );
		}

		foreach ( [ 'properties', 'patternProperties', 'definitions', '$defs' ] as $collection_key ) {
			if ( ! isset( $schema[ $collection_key ] ) || ! is_array( $schema[ $collection_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $collection_key ] as $key => $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$this->assertObjectSchemasDisallowAdditionalProperties(
						$child_schema,
						$path . '.' . $collection_key . '.' . $key
					);
				}
			}
		}

		foreach ( [ 'items', 'contains', 'additionalProperties', 'propertyNames', 'not' ] as $schema_key ) {
			if ( isset( $schema[ $schema_key ] ) && is_array( $schema[ $schema_key ] ) ) {
				$this->assertObjectSchemasDisallowAdditionalProperties(
					$schema[ $schema_key ],
					$path . '.' . $schema_key
				);
			}
		}

		foreach ( [ 'anyOf', 'oneOf', 'allOf', 'prefixItems' ] as $schema_list_key ) {
			if ( ! isset( $schema[ $schema_list_key ] ) || ! is_array( $schema[ $schema_list_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $schema_list_key ] as $key => $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$this->assertObjectSchemasDisallowAdditionalProperties(
						$child_schema,
						$path . '.' . $schema_list_key . '.' . $key
					);
				}
			}
		}
	}

	private static function schema_includes_type( array $schema, string $type ): bool {
		$schema_type = $schema['type'] ?? null;

		if ( is_string( $schema_type ) ) {
			return $type === $schema_type;
		}

		return is_array( $schema_type ) && in_array( $type, $schema_type, true );
	}

	private static function count_schema_unions( array $schema ): int {
		$count = 0;

		if ( isset( $schema['type'] ) && is_array( $schema['type'] ) ) {
			++$count;
		}

		if ( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
			++$count;
		}

		foreach ( [ 'properties', 'patternProperties', 'definitions', '$defs' ] as $collection_key ) {
			if ( ! isset( $schema[ $collection_key ] ) || ! is_array( $schema[ $collection_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $collection_key ] as $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$count += self::count_schema_unions( $child_schema );
				}
			}
		}

		foreach ( [ 'items', 'contains', 'additionalProperties', 'propertyNames', 'not' ] as $schema_key ) {
			if ( isset( $schema[ $schema_key ] ) && is_array( $schema[ $schema_key ] ) ) {
				$count += self::count_schema_unions( $schema[ $schema_key ] );
			}
		}

		foreach ( [ 'anyOf', 'oneOf', 'allOf', 'prefixItems' ] as $schema_list_key ) {
			if ( ! isset( $schema[ $schema_list_key ] ) || ! is_array( $schema[ $schema_list_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $schema_list_key ] as $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$count += self::count_schema_unions( $child_schema );
				}
			}
		}

		return $count;
	}
}
