<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\FlavorAgentRequestTag;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class FlavorAgentRequestTagTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	protected function tearDown(): void {
		if ( \class_exists( FlavorAgentRequestTag::class ) ) {
			FlavorAgentRequestTag::finish();
		}

		parent::tearDown();
	}

	public function test_start_current_and_finish_manage_the_active_tag(): void {
		$this->assertTrue( \class_exists( FlavorAgentRequestTag::class ) );

		$tag = new FlavorAgentRequestTag(
			'template',
			'flavor-agent/recommend-template',
			'wp_template:theme//home',
			[
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
			],
			'request-token-1'
		);

		FlavorAgentRequestTag::start( $tag );

		$this->assertSame( $tag, FlavorAgentRequestTag::current() );
		$this->assertSame( 'template', $tag->surface() );
		$this->assertSame( 'flavor-agent/recommend-template', $tag->ability_name() );
		$this->assertSame( 'wp_template:theme//home', $tag->scope_key() );
		$this->assertSame(
			[
				'scopeKey' => 'wp_template:theme//home',
				'postType' => 'wp_template',
			],
			$tag->document_ref()
		);
		$this->assertSame( 'request-token-1', $tag->request_token() );

		FlavorAgentRequestTag::finish();

		$this->assertNull( FlavorAgentRequestTag::current() );
	}

	public function test_nested_start_reflects_the_latest_tag_until_finish(): void {
		$this->assertTrue( \class_exists( FlavorAgentRequestTag::class ) );

		$outer = new FlavorAgentRequestTag(
			'block',
			'flavor-agent/recommend-block',
			'post:42',
			[ 'scopeKey' => 'post:42' ],
			'outer-token'
		);
		$inner = new FlavorAgentRequestTag(
			'content',
			'flavor-agent/recommend-content',
			'post:43',
			[ 'scopeKey' => 'post:43' ],
			'inner-token'
		);

		FlavorAgentRequestTag::start( $outer );
		FlavorAgentRequestTag::start( $inner );

		$this->assertSame( $inner, FlavorAgentRequestTag::current() );

		FlavorAgentRequestTag::finish();

		$this->assertNull( FlavorAgentRequestTag::current() );
	}
}
