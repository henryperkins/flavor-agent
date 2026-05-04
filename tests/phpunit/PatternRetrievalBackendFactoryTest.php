<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Patterns\Retrieval\CloudflareAISearchPatternRetrievalBackend;
use FlavorAgent\Patterns\Retrieval\PatternRetrievalBackendFactory;
use FlavorAgent\Patterns\Retrieval\QdrantPatternRetrievalBackend;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternRetrievalBackendFactoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_defaults_to_qdrant_backend_when_no_setting_exists(): void {
		$this->assertSame(
			Config::PATTERN_BACKEND_QDRANT,
			PatternRetrievalBackendFactory::selected_backend()
		);
		$this->assertInstanceOf(
			QdrantPatternRetrievalBackend::class,
			PatternRetrievalBackendFactory::for_runtime_state( [] )
		);
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

	public function test_invalid_setting_falls_back_for_capabilities_but_errors_for_runtime_search(): void {
		WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = 'unsupported_backend';

		$backend = PatternRetrievalBackendFactory::for_runtime_state( [] );

		$this->assertSame(
			Config::PATTERN_BACKEND_QDRANT,
			PatternRetrievalBackendFactory::selected_backend()
		);
		$this->assertInstanceOf( \WP_Error::class, $backend );
		$this->assertSame( 'unsupported_pattern_retrieval_backend', $backend->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $backend->get_error_data() );
	}
}
