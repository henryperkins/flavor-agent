<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/bootstrap.php';

use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_choices_fall_back_to_direct_providers_when_connector_registry_throws(): void {
		WordPressTestState::set_connector_api_errors( [
			'wp_get_connectors' => 'Connector registry exploded.',
		] );

		$this->assertSame(
			[
				Provider::AZURE  => 'Azure OpenAI',
				Provider::NATIVE => 'OpenAI Native',
			],
			Provider::choices( 'anthropic' )
		);
	}

	public function test_openai_connector_status_falls_back_when_connector_api_throws(): void {
		WordPressTestState::set_connector_api_errors( [
			'wp_get_connector'           => 'Connector lookup exploded.',
			'wp_is_connector_registered' => 'Connector registration check exploded.',
		] );

		$status = Provider::openai_connector_status();

		$this->assertFalse( $status['registered'] );
		$this->assertFalse( $status['configured'] );
		$this->assertSame( 'OpenAI', $status['label'] );
		$this->assertSame( 'connectors_ai_openai_api_key', $status['settingName'] );
		$this->assertSame( 'none', $status['keySource'] );
		$this->assertNull( $status['credentialsUrl'] );
		$this->assertNull( $status['pluginSlug'] );
	}

	public function test_chat_configuration_falls_back_to_wordpress_ai_client_when_saved_connector_is_unreadable(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::set_connector_api_errors( [
			'wp_get_connectors' => 'Connector registry exploded.',
		] );
		WordPressTestState::$ai_client_supported = true;

		$config = Provider::chat_configuration();

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}
}