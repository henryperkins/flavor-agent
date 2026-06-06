<?php

declare(strict_types=1);

return [
	'pattern_top_three_relevance'       => [
		'surface'                => 'pattern',
		'expectedRelevantKeys'   => [ 'theme/hero-contrast', 'theme/hero-centered' ],
		'shouldRemainApplicable' => true,
		'suggestions'            => [
			[
				'name'       => 'theme/hero-contrast',
				'operations' => [
					[
						'type' => 'insert_pattern',
						'name' => 'theme/hero-contrast',
					],
					[
						'type' => 'track_pattern_context',
						'name' => 'theme/hero-contrast',
					],
				],
				'ranking'    => [ 'score' => 0.91 ],
			],
			[
				'name'    => 'theme/pricing-columns',
				'ranking' => [ 'score' => 0.72 ],
			],
			[
				'name'    => 'theme/testimonial-strip',
				'ranking' => [ 'score' => 0.65 ],
			],
		],
	],
	'style_contrast_and_preset_quality' => [
		'surface'                => 'style',
		'expectedRelevantKeys'   => [ 'Use readable accent contrast' ],
		'shouldRemainApplicable' => true,
		'suggestions'            => [
			[
				'label'          => 'Use readable accent contrast',
				'operations'     => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|contrast',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'contrast',
					],
				],
				'qualitySignals' => [
					'contrastPreserved' => true,
				],
			],
			[
				'label'             => 'Use faint raw text',
				'operations'        => [
					[
						'type'  => 'set_styles',
						'path'  => [ 'color', 'text' ],
						'value' => '#eeeeee',
					],
				],
				'validationReasons' => [
					[
						'code'     => 'failed_contrast',
						'severity' => 'error',
					],
				],
				'qualitySignals'    => [
					'contrastPreserved' => false,
				],
			],
		],
	],
	'stale_false_positive_probe'        => [
		'surface'                     => 'block',
		'expectedRelevantKeys'        => [ 'Apply a different useful style' ],
		'shouldRemainApplicable'      => true,
		'wasMarkedStale'              => true,
		'promptTokenBaselineEstimate' => 120,
		'promptTokenEstimate'         => 192,
		'suggestions'                 => [
			[
				'label'            => 'Keep current button style',
				'attributeUpdates' => [ 'className' => 'is-style-fill' ],
				'operations'       => [
					[
						'type'       => 'set_attribute',
						'path'       => [ 'style', 'spacing', 'padding' ],
						'value'      => 'var:preset|spacing|medium',
						'valueType'  => 'preset',
						'presetType' => 'spacing',
						'presetSlug' => 'medium',
					],
				],
				'qualitySignals'   => [
					'contrastPreserved' => true,
				],
			],
			[
				'label'      => 'Use body font size',
				'operations' => [
					[
						'type'       => 'set_attribute',
						'path'       => [ 'style', 'typography', 'fontSize' ],
						'value'      => 'var:preset|font-size|body',
						'valueType'  => 'preset',
						'presetType' => 'font-size',
						'presetSlug' => 'body',
					],
				],
			],
		],
		'currentState'                => [
			'attributes' => [ 'className' => 'is-style-fill' ],
		],
	],
	'invalid_operation_probe'           => [
		'surface'                => 'template',
		'expectedRelevantKeys'   => [ 'Insert valid footer CTA' ],
		'shouldRemainApplicable' => true,
		'suggestions'            => [
			[
				'label'              => 'Insert valid footer CTA',
				'operations'         => [
					[
						'type' => 'insert_pattern',
						'name' => 'theme/footer-cta',
					],
				],
				'rejectedOperations' => [
					[ 'reason' => 'unsupported path' ],
				],
				'qualitySignals'     => [
					'contrastPreserved' => true,
				],
			],
		],
	],
];
