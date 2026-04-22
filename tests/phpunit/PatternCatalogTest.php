<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use FlavorAgent\Context\PatternCatalog;
use FlavorAgent\Context\PatternOverrideAnalyzer;
use FlavorAgent\Context\TemplateStructureAnalyzer;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_for_patterns_caches_override_metadata_by_pattern_content_hash(): void {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$this->markTestSkipped( 'WP_Block_Patterns_Registry is not available.' );
		}

		$content = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/pattern-overrides"}}}} --><p>Bound</p><!-- /wp:paragraph -->';

		\WP_Block_Patterns_Registry::get_instance()->register(
			'plugin/pattern-one',
			[
				'title'   => 'Pattern One',
				'content' => $content,
			]
		);
		\WP_Block_Patterns_Registry::get_instance()->register(
			'plugin/pattern-two',
			[
				'title'   => 'Pattern Two',
				'content' => $content,
			]
		);

		$catalog = new PatternCatalog(
			new PatternOverrideAnalyzer(
				new BlockTypeIntrospector(),
				new TemplateStructureAnalyzer()
			)
		);

		$catalog->for_patterns();
		$catalog->for_patterns();

		$override_cache = new \ReflectionProperty( $catalog, 'pattern_override_cache' );
		$override_cache->setAccessible( true );
		$query_cache = new \ReflectionProperty( $catalog, 'pattern_query_cache' );
		$query_cache->setAccessible( true );

		$this->assertCount( 1, $override_cache->getValue( $catalog ) );
		$this->assertCount( 1, $query_cache->getValue( $catalog ) );
	}
}
