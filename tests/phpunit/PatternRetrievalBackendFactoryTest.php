<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Patterns\Retrieval\CloudflareAISearchPatternRetrievalBackend;
use FlavorAgent\Patterns\Retrieval\PatternRetrievalBackendFactory;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternRetrievalBackendFactoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_defaults_to_cloudflare_when_no_setting_exists(): void {
		$this->assertSame(
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			PatternRetrievalBackendFactory::selected_backend()
		);
		$this->assertInstanceOf(
			CloudflareAISearchPatternRetrievalBackend::class,
			PatternRetrievalBackendFactory::for_runtime_state( [] )
		);
	}

	/** @dataProvider compatible_settings */
	public function test_compatible_settings_resolve_cloudflare_without_rewriting_settings( string $setting ): void {
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = $setting;

		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, PatternRetrievalBackendFactory::selected_backend() );
		$this->assertInstanceOf( CloudflareAISearchPatternRetrievalBackend::class, PatternRetrievalBackendFactory::for_runtime_state( [] ) );
		$this->assertSame( $setting, WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public static function compatible_settings(): array {
		return [
			'empty'   => [ '' ],
			'legacy'  => [ 'qdrant' ],
			'current' => [ Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH ],
		];
	}

	public function test_selects_cloudflare_ai_search_backend_from_setting(): void {
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] =
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH;

		$this->assertSame(
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			PatternRetrievalBackendFactory::selected_backend()
		);
		$this->assertInstanceOf(
			CloudflareAISearchPatternRetrievalBackend::class,
			PatternRetrievalBackendFactory::for_runtime_state( [] )
		);
	}

	/** @dataProvider invalid_settings */
	public function test_invalid_setting_blocks_capabilities_and_runtime_search( mixed $setting ): void {
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = $setting;

		$backend = PatternRetrievalBackendFactory::for_runtime_state( [] );

		$this->assertNotSame(
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			PatternRetrievalBackendFactory::selected_backend()
		);
		$this->assertInstanceOf( \WP_Error::class, $backend );
		$this->assertSame( 'unsupported_pattern_retrieval_backend', $backend->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $backend->get_error_data() );
	}

	public static function invalid_settings(): array {
		return [
			'unknown'     => [ 'unsupported_backend' ],
			'punctuation' => [ '<>' ],
			'whitespace'  => [ ' ' ],
			'corrupt'     => [ [ 'qdrant' ] ],
		];
	}
}
