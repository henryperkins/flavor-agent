<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use FlavorAgent\Context\PatternCandidateSelector;
use FlavorAgent\Context\PatternCatalog;
use FlavorAgent\Context\PatternOverrideAnalyzer;
use FlavorAgent\Context\TemplateStructureAnalyzer;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PatternCandidateSelectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();

		if ( class_exists( '\WP_Block_Patterns_Registry' ) ) {
			\WP_Block_Patterns_Registry::get_instance()->reset();
		}
	}

	private function build_selector(): PatternCandidateSelector {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$this->markTestSkipped( 'WP_Block_Patterns_Registry is not available.' );
		}

		$catalog = new PatternCatalog(
			new PatternOverrideAnalyzer(
				new BlockTypeIntrospector(),
				new TemplateStructureAnalyzer()
			)
		);

		return new PatternCandidateSelector( $catalog );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function register_pattern( string $name, array $extra = [] ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			array_merge(
				[
					'title'   => $name,
					'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
				],
				$extra
			)
		);
	}

	public function test_collect_template_candidate_patterns_orders_typed_before_generic(): void {
		$selector = $this->build_selector();

		$this->register_pattern( 'plugin/typed-singular', [ 'templateTypes' => [ 'page' ] ] );
		$this->register_pattern( 'plugin/typed-other', [ 'templateTypes' => [ 'archive' ] ] );
		$this->register_pattern( 'plugin/generic', [ 'templateTypes' => [] ] );

		$candidates = $selector->collect_template_candidate_patterns( 'page' );
		$names      = array_map( static fn( array $p ): string => $p['name'], $candidates );

		$this->assertSame( [ 'plugin/typed-singular', 'plugin/generic' ], $names );
		$this->assertSame( 'typed', $candidates[0]['matchType'] );
		$this->assertSame( 'generic', $candidates[1]['matchType'] );
	}

	public function test_collect_template_candidate_patterns_returns_unfiltered_set_when_no_template_type(): void {
		$selector = $this->build_selector();

		$this->register_pattern( 'plugin/a', [ 'templateTypes' => [ 'page' ] ] );
		$this->register_pattern( 'plugin/b', [ 'templateTypes' => [] ] );

		$candidates = $selector->collect_template_candidate_patterns( null );

		$this->assertCount( 2, $candidates );
		// No matchType is annotated when the template type filter is null.
		$this->assertArrayNotHasKey( 'matchType', $candidates[0] );
		// Content is stripped to keep the candidate payload small.
		$this->assertArrayNotHasKey( 'content', $candidates[0] );
	}

	public function test_collect_template_candidate_patterns_filters_to_visible_pattern_names(): void {
		$selector = $this->build_selector();

		$this->register_pattern( 'plugin/visible', [ 'templateTypes' => [ 'page' ] ] );
		$this->register_pattern( 'plugin/hidden', [ 'templateTypes' => [ 'page' ] ] );

		$candidates = $selector->collect_template_candidate_patterns(
			'page',
			[ 'plugin/visible' ]
		);

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'plugin/visible', $candidates[0]['name'] );
	}

	public function test_collect_template_candidate_patterns_caps_results_at_documented_limit(): void {
		$selector = $this->build_selector();

		for ( $i = 0; $i < PatternCandidateSelector::TEMPLATE_PATTERN_CANDIDATE_CAP + 5; $i++ ) {
			$this->register_pattern( "plugin/p-$i", [ 'templateTypes' => [ 'page' ] ] );
		}

		$candidates = $selector->collect_template_candidate_patterns( 'page' );

		$this->assertCount(
			PatternCandidateSelector::TEMPLATE_PATTERN_CANDIDATE_CAP,
			$candidates
		);
	}

	public function test_collect_template_part_candidate_patterns_scores_area_specific_block_type_first(): void {
		$selector = $this->build_selector();

		// Header-block-typed pattern: + 8 (specific area) + 2 (template-part) + 4 (term match).
		$this->register_pattern(
			'plugin/header-explicit',
			[
				'title'      => 'Site Header',
				'blockTypes' => [ 'core/template-part/header' ],
			]
		);

		// Generic template-part pattern that mentions header in title: +2 + 4.
		$this->register_pattern(
			'plugin/header-loose',
			[
				'title'      => 'Header Banner',
				'blockTypes' => [ 'core/template-part' ],
			]
		);

		// No match: random pattern with no header signal.
		$this->register_pattern(
			'plugin/sidebar',
			[
				'title'      => 'Sidebar Widgets',
				'blockTypes' => [ 'core/template-part/sidebar' ],
			]
		);

		// Pure generic fallback.
		$this->register_pattern( 'plugin/free-form' );

		$candidates = $selector->collect_template_part_candidate_patterns( 'header' );
		$names      = array_map( static fn( array $p ): string => $p['name'], $candidates );

		$this->assertSame(
			[ 'plugin/header-explicit', 'plugin/header-loose', 'plugin/free-form' ],
			$names
		);
		$this->assertSame( 'area', $candidates[0]['matchType'] );
		$this->assertSame( 'area', $candidates[1]['matchType'] );
		$this->assertSame( 'generic', $candidates[2]['matchType'] );
		// Sort metadata is stripped from the returned payload.
		$this->assertArrayNotHasKey( '_sortIndex', $candidates[0] );
		$this->assertArrayNotHasKey( '_sortScore', $candidates[0] );
	}

	public function test_collect_template_part_candidate_patterns_returns_unfiltered_when_area_is_empty(): void {
		$selector = $this->build_selector();

		$this->register_pattern( 'plugin/a', [ 'blockTypes' => [ 'core/template-part/header' ] ] );
		$this->register_pattern( 'plugin/b', [ 'blockTypes' => [] ] );

		$candidates = $selector->collect_template_part_candidate_patterns( null );

		$this->assertCount( 2, $candidates );
		$this->assertArrayNotHasKey( 'matchType', $candidates[0] );
	}

	public function test_collect_template_part_candidate_patterns_recognizes_navigation_overlay_double_term(): void {
		$selector = $this->build_selector();

		// Pattern title contains both 'navigation' and 'overlay': triggers
		// the +2 bonus on top of the multi-term scoring.
		$this->register_pattern(
			'plugin/nav-overlay',
			[
				'title'       => 'Navigation Overlay Layout',
				'description' => 'Mobile overlay navigation menu',
				'blockTypes'  => [ 'core/template-part' ],
			]
		);
		$this->register_pattern(
			'plugin/just-nav',
			[
				'title'      => 'Navigation Only',
				'blockTypes' => [ 'core/template-part' ],
			]
		);

		$candidates = $selector->collect_template_part_candidate_patterns(
			'navigation-overlay'
		);

		$this->assertSame( 'plugin/nav-overlay', $candidates[0]['name'] );
		$this->assertSame( 'plugin/just-nav', $candidates[1]['name'] );
	}

	public function test_collect_template_part_candidate_patterns_filters_to_visible_pattern_names(): void {
		$selector = $this->build_selector();

		$this->register_pattern(
			'plugin/visible',
			[ 'blockTypes' => [ 'core/template-part/header' ] ]
		);
		$this->register_pattern(
			'plugin/hidden',
			[ 'blockTypes' => [ 'core/template-part/header' ] ]
		);

		$candidates = $selector->collect_template_part_candidate_patterns(
			'header',
			[ 'plugin/visible' ]
		);

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'plugin/visible', $candidates[0]['name'] );
	}

	public function test_collect_template_part_candidate_patterns_falls_back_to_kebab_term_for_unknown_areas(): void {
		$selector = $this->build_selector();

		// 'custom-region' is not in the documented area map; the score loop
		// should still match patterns whose haystack contains either
		// 'custom-region' or its space-normalized form 'custom region'.
		$this->register_pattern(
			'plugin/custom-region-pattern',
			[ 'title' => 'Custom Region Banner' ]
		);
		$this->register_pattern( 'plugin/unrelated', [ 'title' => 'Footer Block' ] );

		$candidates = $selector->collect_template_part_candidate_patterns(
			'custom-region'
		);
		$names      = array_map( static fn( array $p ): string => $p['name'], $candidates );

		// The custom-region pattern matches via the spaced fallback term;
		// the unrelated one falls into the generic bucket because it has no
		// templateTypes/blockTypes.
		$this->assertSame(
			[ 'plugin/custom-region-pattern', 'plugin/unrelated' ],
			$names
		);
		$this->assertSame( 'area', $candidates[0]['matchType'] );
		$this->assertSame( 'generic', $candidates[1]['matchType'] );
	}
}
