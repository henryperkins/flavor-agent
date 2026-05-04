<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\WordPressAIPolicy;
use PHPUnit\Framework\TestCase;

final class WordPressAIPolicyTest extends TestCase {

	public function test_sanitize_text_generation_options_drops_unknown_keys(): void {
		$result = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'unsupported' => 'value',
				'max_tokens'  => 256,
			]
		);

		$this->assertArrayNotHasKey( 'unsupported', $result );
		$this->assertSame( 256, $result['max_tokens'] );
	}

	public function test_sanitize_text_generation_options_coerces_non_negative_ints(): void {
		$result = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'candidate_count' => '4',
				'max_tokens'      => 1024.7,
				'top_k'           => -10,
				'top_logprobs'    => '0',
			]
		);

		$this->assertSame( 4, $result['candidate_count'] );
		$this->assertSame( 1024, $result['max_tokens'] );
		$this->assertSame( 0, $result['top_k'] );
		$this->assertSame( 0, $result['top_logprobs'] );
	}

	public function test_sanitize_text_generation_options_drops_non_numeric_ints(): void {
		$result = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'candidate_count' => 'not-a-number',
				'max_tokens'      => null,
			]
		);

		$this->assertSame( [], $result );
	}

	public function test_sanitize_text_generation_options_coerces_floats(): void {
		$result = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'temperature'       => '0.7',
				'top_p'             => 0.9,
				'presence_penalty'  => -0.5,
				'frequency_penalty' => 1,
			]
		);

		$this->assertSame( 0.7, $result['temperature'] );
		$this->assertSame( 0.9, $result['top_p'] );
		$this->assertSame( -0.5, $result['presence_penalty'] );
		$this->assertSame( 1.0, $result['frequency_penalty'] );
	}

	public function test_sanitize_text_generation_options_drops_non_numeric_floats(): void {
		$result = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'temperature' => 'hot',
				'top_p'       => null,
			]
		);

		$this->assertSame( [], $result );
	}

	public function test_sanitize_text_generation_options_handles_logprobs_boolean(): void {
		$truthy  = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'logprobs' => 'true',
			]
		);
		$falsy   = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'logprobs' => '0',
			]
		);
		$invalid = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'logprobs' => 'maybe',
			]
		);

		$this->assertTrue( $truthy['logprobs'] );
		$this->assertFalse( $falsy['logprobs'] );
		$this->assertArrayNotHasKey( 'logprobs', $invalid );
	}

	public function test_sanitize_text_generation_options_normalizes_stop_sequences(): void {
		$result = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'stop_sequences' => [
					'STOP',
					'STOP',
					'',
					'  end  ',
					[ 'nested' ],
					(object) [ 'foo' => 'bar' ],
					42,
				],
			]
		);

		// Duplicates removed, empty strings removed, arrays/objects skipped,
		// scalars cast to string and trimmed via sanitize_text_field.
		$this->assertSame( [ 'STOP', 'end', '42' ], array_values( $result['stop_sequences'] ) );
	}

	public function test_sanitize_text_generation_options_drops_empty_or_invalid_stop_sequences(): void {
		$non_array          = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'stop_sequences' => 'STOP',
			]
		);
		$empty_after_filter = WordPressAIPolicy::sanitize_text_generation_options(
			[
				'stop_sequences' => [ '', [ 'nested' ] ],
			]
		);

		$this->assertArrayNotHasKey( 'stop_sequences', $non_array );
		$this->assertArrayNotHasKey( 'stop_sequences', $empty_after_filter );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function ability_name_provider(): array {
		return [
			'block'         => [ 'flavor_agent_block', 'flavor-agent/recommend-block' ],
			'content'       => [ 'flavor_agent_content', 'flavor-agent/recommend-content' ],
			'pattern'       => [ 'flavor_agent_pattern', 'flavor-agent/recommend-patterns' ],
			'template'      => [ 'flavor_agent_template', 'flavor-agent/recommend-template' ],
			'template_part' => [ 'flavor_agent_template_part', 'flavor-agent/recommend-template-part' ],
			'navigation'    => [ 'flavor_agent_navigation', 'flavor-agent/recommend-navigation' ],
			'style'         => [ 'flavor_agent_style', 'flavor-agent/recommend-style' ],
		];
	}

	/**
	 * @dataProvider ability_name_provider
	 */
	public function test_ability_name_for_schema_name_maps_known_schemas( string $schema_name, string $expected ): void {
		$this->assertSame(
			$expected,
			WordPressAIPolicy::ability_name_for_schema_name( $schema_name )
		);
	}

	public function test_ability_name_for_schema_name_returns_empty_for_unknown_schema(): void {
		$this->assertSame(
			'',
			WordPressAIPolicy::ability_name_for_schema_name( 'flavor_agent_unknown' )
		);
	}

	public function test_ability_name_for_schema_name_returns_empty_for_null(): void {
		$this->assertSame(
			'',
			WordPressAIPolicy::ability_name_for_schema_name( null )
		);
	}

	public function test_ability_name_for_schema_name_normalizes_via_sanitize_key(): void {
		// sanitize_key lowercases and strips invalid characters; a noisy
		// schema string should still map cleanly when its sanitized form is
		// recognized.
		$this->assertSame(
			'flavor-agent/recommend-block',
			WordPressAIPolicy::ability_name_for_schema_name( 'Flavor_Agent_Block' )
		);
	}
}
