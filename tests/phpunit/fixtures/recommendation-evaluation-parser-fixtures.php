<?php

declare(strict_types=1);

$style_context = [
	'scope'        => [
		'surface' => 'global-styles',
	],
	'styleContext' => [
		'currentConfig'       => [
			'styles' => [],
		],
		'mergedConfig'        => [
			'styles' => [
				'color' => [
					'background' => '#ffffff',
					'text'       => '#111111',
				],
			],
		],
		'themeTokens'         => [
			'colors'       => [ 'accent: #f5f5f5' ],
			'colorPresets' => [
				[
					'slug'  => 'accent',
					'color' => '#f5f5f5',
				],
			],
		],
		'supportedStylePaths' => [
			[
				'path'        => [ 'color', 'background' ],
				'valueSource' => 'color',
			],
			[
				'path'        => [ 'border', 'width' ],
				'valueSource' => 'freeform',
			],
		],
	],
];

return [
	'block_parser_executable_update'       => [
		'surface'         => 'block',
		'alreadyGood'     => false,
		'parser'          => 'block',
		'response'        => [
			'block' => [
				[
					'label'            => 'Use correct heading level',
					'description'      => 'Use a heading level that fits this section.',
					'type'             => 'attribute_change',
					'panel'            => 'general',
					'attributeUpdates' => '{"level":2}',
					'operations'       => [],
					'confidence'       => 0.55,
				],
			],
		],
		'expectedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
	'block_parser_no_op_ranked_probe'      => [
		'surface'                  => 'block',
		'alreadyGood'              => false,
		'parser'                   => 'block',
		'rankedMetricProbe'        => true,
		'currentState'             => [
			'attributes' => [
				'level' => 2,
			],
		],
		'response'                 => [
			'block' => [
				[
					'label'            => 'Keep existing heading level',
					'description'      => '',
					'type'             => 'attribute_change',
					'panel'            => 'general',
					'attributeUpdates' => '{"level":2}',
					'operations'       => [],
					'confidence'       => 0.7,
				],
				[
					'label'            => 'Raise heading level',
					'description'      => 'Use a stronger heading hierarchy for the selected section.',
					'type'             => 'attribute_change',
					'panel'            => 'general',
					'attributeUpdates' => '{"level":3}',
					'operations'       => [],
					'confidence'       => 0.55,
				],
			],
		],
		'expectedMetrics'          => [
			'fixtures'             => 1,
			'suggestions'          => 2,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.5,
			'noiseRate'            => 0.0,
		],
		'expectedTopRankedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
	'style_parser_preset_ranked_probe'     => [
		'surface'                  => 'style',
		'alreadyGood'              => false,
		'parser'                   => 'style',
		'rankedMetricProbe'        => true,
		'context'                  => $style_context,
		'response'                 => [
			'suggestions' => [
				[
					'label'       => 'Use custom border width',
					'description' => 'Use a raw border value.',
					'category'    => 'border',
					'tone'        => 'executable',
					'operations'  => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'border', 'width' ],
							'value'     => '2px',
							'valueType' => 'freeform',
						],
					],
					'confidence'  => 0.7,
				],
				[
					'label'       => 'Use accent background preset',
					'description' => 'Use the theme accent preset for a token-backed background.',
					'category'    => 'color',
					'tone'        => 'executable',
					'operations'  => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'background' ],
							'value'      => 'var:preset|color|accent',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'accent',
						],
					],
					'confidence'  => 0.55,
				],
			],
		],
		'expectedMetrics'          => [
			'fixtures'             => 1,
			'suggestions'          => 2,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.5,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
		'expectedTopRankedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 1.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
	'style_parser_downgraded_ranked_probe' => [
		'surface'                  => 'style',
		'alreadyGood'              => false,
		'parser'                   => 'style',
		'rankedMetricProbe'        => true,
		'context'                  => $style_context,
		'response'                 => [
			'suggestions' => [
				[
					'label'       => 'Use unsupported text color',
					'description' => 'This unsupported operation should be downgraded.',
					'category'    => 'color',
					'tone'        => 'executable',
					'operations'  => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'text' ],
							'value'      => 'var:preset|color|accent',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'accent',
						],
					],
					'confidence'  => 0.7,
				],
				[
					'label'       => 'Use supported border width',
					'description' => 'Use a supported border width value.',
					'category'    => 'border',
					'tone'        => 'executable',
					'operations'  => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'border', 'width' ],
							'value'     => '2px',
							'valueType' => 'freeform',
						],
					],
					'confidence'  => 0.55,
				],
			],
		],
		'expectedMetrics'          => [
			'fixtures'             => 1,
			'suggestions'          => 2,
			'invalidOperationRate' => 0.5,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
		'expectedTopRankedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
];
