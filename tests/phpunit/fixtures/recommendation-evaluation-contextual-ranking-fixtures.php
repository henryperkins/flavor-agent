<?php

declare(strict_types=1);

return [
	'block_structural_contextual_rerank' => [
		'surface'                      => 'block',
		'parser'                       => 'block',
		'lane'                         => 'block',
		'rankedMetricProbe'            => true,
		'enableBlockStructuralActions' => true,
		'context'                      => [
			'block'                 => [
				'name'              => 'core/paragraph',
				'currentAttributes' => [],
			],
			'visiblePatternNames'   => [ 'theme/hero' ],
			'blockOperationContext' => [
				'targetClientId'  => 'block-1',
				'targetBlockName' => 'core/paragraph',
				'targetSignature' => 'sig-1',
				'allowedPatterns' => [
					[
						'name'           => 'theme/hero',
						'allowedActions' => [ 'insert_after' ],
					],
				],
			],
		],
		'rankingContext'               => [
			'surface'           => 'block',
			'prompt'            => 'Add a hero section after this paragraph.',
			'executionContract' => [
				'allowedPanels' => [ 'general' ],
			],
			'docsGrounding'     => [
				'status' => 'grounded',
			],
		],
		'enforceBlockContext'          => true,
		'response'                     => [
			'block' => [
				[
					'label'            => 'Insert footer pattern',
					'description'      => 'Add a footer pattern near this block.',
					'type'             => 'structural_recommendation',
					'panel'            => '',
					'attributeUpdates' => '{}',
					'operations'       => [
						[
							'type'            => 'insert_pattern',
							'patternName'     => 'theme/footer',
							'targetClientId'  => 'block-1',
							'targetSignature' => 'sig-1',
							'position'        => 'insert_after',
						],
					],
					'confidence'       => 0.70,
				],
				[
					'label'            => 'Insert hero pattern',
					'description'      => 'Add a hero section after the paragraph.',
					'type'             => 'structural_recommendation',
					'panel'            => '',
					'attributeUpdates' => '{}',
					'operations'       => [
						[
							'type'            => 'insert_pattern',
							'patternName'     => 'theme/hero',
							'targetClientId'  => 'block-1',
							'targetSignature' => 'sig-1',
							'position'        => 'insert_after',
						],
					],
					'confidence'       => 0.55,
				],
			],
		],
		'expectedTopLabel'             => 'Insert hero pattern',
		'expectedMetrics'              => [
			'fixtures'             => 1,
			'suggestions'          => 2,
			'invalidOperationRate' => 0.5,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
		'expectedTopRankedMetrics'     => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
];
