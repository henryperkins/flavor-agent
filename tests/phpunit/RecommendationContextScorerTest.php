<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\RecommendationContextScorer;
use PHPUnit\Framework\TestCase;

final class RecommendationContextScorerTest extends TestCase {

	public function test_scores_dot_string_style_support_paths_and_penalizes_absent_concrete_paths(): void {
		$supported   = RecommendationContextScorer::score(
			[
				'surface'           => 'block',
				'group'             => 'styles',
				'suggestion'        => [
					'label'            => 'Use accent background',
					'description'      => 'Use the supported background color control.',
					'attributeUpdates' => [
						'style' => [
							'color' => [
								'background' => 'var(--wp--preset--color--accent)',
							],
						],
					],
				],
				'executionContract' => [
					'allowedPanels'     => [ 'color' ],
					'styleSupportPaths' => [ 'color.background' ],
				],
			]
		);
		$unsupported = RecommendationContextScorer::score(
			[
				'surface'           => 'block',
				'group'             => 'styles',
				'suggestion'        => [
					'label'            => 'Use accent text',
					'description'      => 'Use the text color control.',
					'attributeUpdates' => [
						'style' => [
							'color' => [
								'text' => 'var(--wp--preset--color--accent)',
							],
						],
					],
				],
				'executionContract' => [
					'allowedPanels'     => [ 'color' ],
					'styleSupportPaths' => [ 'color.background' ],
				],
			]
		);

		$this->assertGreaterThan( 0.7, $supported['evidence']['supports_fit'] );
		$this->assertArrayNotHasKey( 'unsupported_control', $supported['penalties'] );
		$this->assertSame( 0.45, $unsupported['evidence']['supports_fit'] );
		$this->assertSame( 0.20, $unsupported['penalties']['unsupported_control'] );
	}

	public function test_style_surface_support_fit_reads_style_context_supported_paths(): void {
		$style_context = [
			'styleContext' => [
				'supportedStylePaths' => [
					[
						'path' => [ 'color', 'background' ],
					],
				],
			],
		];
		$supported     = RecommendationContextScorer::score(
			[
				'surface'    => 'global-styles',
				'group'      => 'suggestions',
				'suggestion' => [
					'label'      => 'Use accent background',
					'operations' => [
						[
							'type'  => 'set_styles',
							'path'  => [ 'color', 'background' ],
							'value' => '#123456',
						],
					],
				],
				'context'    => $style_context,
			]
		);
		$unsupported   = RecommendationContextScorer::score(
			[
				'surface'    => 'global-styles',
				'group'      => 'suggestions',
				'suggestion' => [
					'label'      => 'Use accent text',
					'operations' => [
						[
							'type'  => 'set_styles',
							'path'  => [ 'color', 'text' ],
							'value' => '#123456',
						],
					],
				],
				'context'    => $style_context,
			]
		);

		$this->assertGreaterThan( 0.7, $supported['evidence']['supports_fit'] );
		$this->assertArrayNotHasKey( 'unsupported_control', $supported['penalties'] );
		$this->assertSame( 0.45, $unsupported['evidence']['supports_fit'] );
		$this->assertSame( 0.20, $unsupported['penalties']['unsupported_control'] );
	}

	public function test_style_surface_no_op_detection_reads_current_global_styles_config(): void {
		$context = [
			'styleContext' => [
				'currentConfig' => [
					'styles' => [
						'color' => [
							'background' => '#123456',
						],
					],
				],
			],
		];

		$exact           = RecommendationContextScorer::score(
			[
				'surface'    => 'global-styles',
				'suggestion' => [
					'operations' => [
						[
							'type'  => 'set_styles',
							'path'  => [ 'color', 'background' ],
							'value' => '#123456',
						],
					],
				],
				'context'    => $context,
			]
		);
		$different       = RecommendationContextScorer::score(
			[
				'surface'    => 'global-styles',
				'suggestion' => [
					'operations' => [
						[
							'type'  => 'set_styles',
							'path'  => [ 'color', 'background' ],
							'value' => '#654321',
						],
					],
				],
				'context'    => $context,
			]
		);
		$missing         = RecommendationContextScorer::score(
			[
				'surface'    => 'global-styles',
				'suggestion' => [
					'operations' => [
						[
							'type'  => 'set_styles',
							'path'  => [ 'color', 'text' ],
							'value' => '#123456',
						],
					],
				],
				'context'    => $context,
			]
		);
		$preset_mismatch = RecommendationContextScorer::score(
			[
				'surface'    => 'global-styles',
				'suggestion' => [
					'operations' => [
						[
							'type'  => 'set_styles',
							'path'  => [ 'color', 'background' ],
							'value' => '#123456',
						],
					],
				],
				'context'    => [
					'styleContext' => [
						'currentConfig' => [
							'styles' => [
								'color' => [
									'background' => 'var:preset|color|accent',
								],
							],
						],
					],
				],
			]
		);

		$this->assertSame( 0.25, $exact['penalties']['possible_no_op'] );
		$this->assertArrayNotHasKey( 'possible_no_op', $different['penalties'] );
		$this->assertArrayNotHasKey( 'possible_no_op', $missing['penalties'] );
		$this->assertArrayNotHasKey( 'possible_no_op', $preset_mismatch['penalties'] );
	}

	public function test_style_book_no_op_detection_reads_block_scoped_current_styles_with_camel_case_paths(): void {
		$context = [
			'styleContext' => [
				'currentConfig' => [
					'styles' => [
						'blocks' => [
							'core/paragraph' => [
								'typography' => [
									'fontSize' => 'var:preset|font-size|large',
								],
							],
						],
					],
				],
			],
		];

		$exact         = RecommendationContextScorer::score(
			[
				'surface'    => 'style-book',
				'suggestion' => [
					'operations' => [
						[
							'type'      => 'set_block_styles',
							'blockName' => 'core/paragraph',
							'path'      => [ 'typography', 'fontSize' ],
							'value'     => 'var:preset|font-size|large',
						],
					],
				],
				'context'    => $context,
			]
		);
		$different     = RecommendationContextScorer::score(
			[
				'surface'    => 'style-book',
				'suggestion' => [
					'operations' => [
						[
							'type'      => 'set_block_styles',
							'blockName' => 'core/paragraph',
							'path'      => [ 'typography', 'fontSize' ],
							'value'     => 'var:preset|font-size|small',
						],
					],
				],
				'context'    => $context,
			]
		);
		$missing_block = RecommendationContextScorer::score(
			[
				'surface'    => 'style-book',
				'suggestion' => [
					'operations' => [
						[
							'type'      => 'set_block_styles',
							'blockName' => 'core/heading',
							'path'      => [ 'typography', 'fontSize' ],
							'value'     => 'var:preset|font-size|large',
						],
					],
				],
				'context'    => $context,
			]
		);

		$this->assertSame( 0.25, $exact['penalties']['possible_no_op'] );
		$this->assertArrayNotHasKey( 'possible_no_op', $different['penalties'] );
		$this->assertArrayNotHasKey( 'possible_no_op', $missing_block['penalties'] );
	}

	public function test_applies_fixed_penalty_values_and_caps_the_aggregate_penalty(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'           => 'block',
				'group'             => 'styles',
				'prompt'            => 'Create a vivid hero call to action',
				'suggestion'        => [
					'label'              => 'Keep current text color',
					'description'        => 'Rejected contrast validation hint.',
					'attributeUpdates'   => [
						'style' => [
							'color' => [
								'text' => 'var(--wp--preset--color--contrast)',
							],
						],
					],
					'rejectedOperations' => [
						[ 'code' => 'contrast_failed' ],
					],
				],
				'context'           => [
					'block' => [
						'currentAttributes' => [
							'style' => [
								'color' => [
									'text' => 'var(--wp--preset--color--contrast)',
								],
							],
						],
					],
				],
				'docsGrounding'     => [
					'status' => 'stale',
				],
				'executionContract' => [
					'styleSupportPaths' => [ 'color.background' ],
				],
			]
		);

		$this->assertSame( 0.12, $result['penalties']['weak_prompt_match'] );
		$this->assertSame( 0.25, $result['penalties']['possible_no_op'] );
		$this->assertSame( 0.20, $result['penalties']['unsupported_control'] );
		$this->assertSame( 0.15, $result['penalties']['stale_docs'] );
		$this->assertSame( 0.15, $result['penalties']['validation_risk'] );
		$this->assertGreaterThanOrEqual( 0.0, $result['score'] );
		$this->assertGreaterThan( 0.35, array_sum( $result['penalties'] ) );
	}

	public function test_accessibility_fit_requires_the_suggestion_to_address_accessibility(): void {
		$context = [
			'designSemantics' => [
				'mainDesignIssue' => 'contrast',
			],
		];

		$generic  = RecommendationContextScorer::score(
			[
				'surface'    => 'template',
				'suggestion' => [
					'label'       => 'Improve the section',
					'description' => 'Make this area feel more polished.',
				],
				'context'    => $context,
			]
		);
		$explicit = RecommendationContextScorer::score(
			[
				'surface'    => 'template',
				'suggestion' => [
					'label'       => 'Improve contrast and readability',
					'description' => 'Use stronger contrast for readable text.',
				],
				'context'    => $context,
			]
		);

		$this->assertSame( 0.55, $generic['evidence']['accessibility_fit'] );
		$this->assertGreaterThan( 0.55, $explicit['evidence']['accessibility_fit'] );
	}

	public function test_no_op_penalty_requires_all_shallow_scalar_updates_to_match(): void {
		$context = [
			'block' => [
				'currentAttributes' => [
					'level' => 2,
					'style' => [
						'color' => [
							'text' => 'var(--wp--preset--color--contrast)',
						],
					],
				],
			],
		];

		$exact   = RecommendationContextScorer::score(
			[
				'suggestion' => [
					'attributeUpdates' => [
						'level' => 2,
					],
				],
				'context'    => $context,
			]
		);
		$partial = RecommendationContextScorer::score(
			[
				'suggestion' => [
					'attributeUpdates' => [
						'level' => 3,
					],
				],
				'context'    => $context,
			]
		);

		$this->assertSame( 0.25, $exact['penalties']['possible_no_op'] );
		$this->assertArrayNotHasKey( 'possible_no_op', $partial['penalties'] );
	}
}
