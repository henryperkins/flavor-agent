<?php

declare(strict_types=1);

return [
	'already_good_block_with_noise'           => [
		'surface'      => 'block',
		'alreadyGood'  => true,
		'currentState' => [
			'attributes' => [
				'style' => [
					'color' => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
				],
			],
		],
		'suggestions'  => [
			[
				'label'            => 'Keep contrast text',
				'attributeUpdates' => [
					'style' => [
						'color' => [
							'text' => 'var(--wp--preset--color--contrast)',
						],
					],
				],
				'operations'       => [],
				'ranking'          => [
					'score' => 0.68,
				],
			],
		],
	],
	'block_with_invalid_and_valid_operations' => [
		'surface'     => 'block',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'              => 'Replace with hero pattern',
				'operations'         => [
					[
						'type'        => 'replace_block_with_pattern',
						'patternName' => 'theme/hero',
					],
				],
				'rejectedOperations' => [
					[
						'type'   => 'replace_block_with_pattern',
						'reason' => 'target path no longer matches',
					],
				],
			],
			[
				'label'              => 'Insert CTA nearby',
				'operations'         => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'theme/cta',
					],
				],
				'rejectedOperations' => [
					[
						'type'   => 'insert_pattern',
						'reason' => 'insertion target is locked',
					],
				],
			],
		],
	],
	'style_with_theme_preset'                 => [
		'surface'     => 'style',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'      => 'Use accent preset',
				'operations' => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|accent',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'accent',
					],
				],
			],
		],
	],
	'style_with_raw_value'                    => [
		'surface'     => 'style',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'      => 'Use raw brand color',
				'operations' => [
					[
						'type'      => 'set_styles',
						'path'      => [ 'color', 'background' ],
						'value'     => '#123456',
						'valueType' => 'raw',
					],
				],
			],
		],
	],
	'template_with_pattern_suggestion'        => [
		'surface'     => 'template',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'              => 'Add footer utility pattern',
				'patternSuggestions' => [ 'theme/footer-utility' ],
				'operations'         => [],
			],
		],
	],
];
