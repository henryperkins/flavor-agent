<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use FlavorAgent\Context\PatternCandidateSelector;
use FlavorAgent\Context\PatternCatalog;
use FlavorAgent\Context\PatternOverrideAnalyzer;
use FlavorAgent\Context\SyncedPatternRepository;
use FlavorAgent\Context\TemplatePartContextCollector;
use FlavorAgent\Context\TemplateRepository;
use FlavorAgent\Context\TemplateStructureAnalyzer;
use FlavorAgent\Context\ThemeTokenCollector;
use FlavorAgent\Context\ViewportVisibilityAnalyzer;
use FlavorAgent\Support\TemplatePartCompositionProfile;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class TemplatePartContextCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	private function build_collector(): TemplatePartContextCollector {
		$structure_analyzer = new TemplateStructureAnalyzer();

		return new TemplatePartContextCollector(
			new TemplateRepository(),
			$structure_analyzer,
			new PatternOverrideAnalyzer( new BlockTypeIntrospector(), $structure_analyzer ),
			new PatternCandidateSelector(
				new PatternCatalog(
					new PatternOverrideAnalyzer( new BlockTypeIntrospector(), $structure_analyzer )
				)
			),
			new ThemeTokenCollector(),
			new ViewportVisibilityAnalyzer( $structure_analyzer ),
			new SyncedPatternRepository()
		);
	}

	public function test_expands_readable_synced_pattern_for_role_analysis(): void {
		WordPressTestState::$posts[55]                 = (object) [
			'ID'           => 55,
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => 'Branded header',
			'post_content' => '<!-- wp:site-logo /--><!-- wp:navigation /-->',
		];
		WordPressTestState::$capabilities['read_post'] = true;

		$collector = $this->build_collector();
		$expanded  = $collector->expand_synced_patterns_for_tests(
			parse_blocks( '<!-- wp:block {"ref":55} /-->' )
		);

		$names = array_map(
			static fn( array $block ): string => (string) ( $block['blockName'] ?? '' ),
			$expanded
		);
		$this->assertContains( 'core/site-logo', $names );
		$this->assertContains( 'core/navigation', $names );
		$this->assertNotContains( 'core/block', $names );

		// The header built entirely from a synced pattern must not read as empty:
		// both expected roles should now be present, with no gaps.
		$stats   = ( new TemplateStructureAnalyzer() )->collect_template_part_block_stats( $expanded );
		$profile = TemplatePartCompositionProfile::analyze(
			'header',
			$stats['blockCounts'],
			$stats['blockCount']
		);
		$this->assertSame( [], $profile['missingRoles'] );
		$this->assertContains( 'branding', $profile['presentRoles'] );
		$this->assertContains( 'primary-navigation', $profile['presentRoles'] );
		$this->assertFalse( $profile['isEmpty'] );
	}

	public function test_keeps_unresolvable_synced_reference_literal(): void {
		// No matching post registered, so the reference cannot be resolved and the
		// literal node is preserved rather than dropped.
		$collector = $this->build_collector();
		$expanded  = $collector->expand_synced_patterns_for_tests(
			parse_blocks( '<!-- wp:block {"ref":999} /-->' )
		);

		$names = array_map(
			static fn( array $block ): string => (string) ( $block['blockName'] ?? '' ),
			$expanded
		);
		$this->assertContains( 'core/block', $names );
	}
}
