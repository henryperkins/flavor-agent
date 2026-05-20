<?php

declare(strict_types=1);

return [
	'block_semantics_parent_contrast_constraint'    => [
		'surface'         => 'block',
		'alreadyGood'     => true,
		'parser'          => 'block',
		'lane'            => 'block',
		'context'         => [
			'block'           => [
				'name' => 'core/paragraph',
			],
			'designSemantics' => [
				'surface'         => 'block',
				'sectionRole'     => 'footer',
				'contrastContext' => 'dark-parent',
				'mainDesignIssue' => 'none',
				'negativeSignals' => [ 'parent-already-supplies-contrast' ],
			],
		],
		'response'        => [
			'block' => [],
		],
		'expectedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 0,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
	'template_semantics_no_invalid_operations'      => [
		'surface'                  => 'template',
		'alreadyGood'              => false,
		'parser'                   => 'template',
		'rankedMetricProbe'        => true,
		'context'                  => [
			'templateRef'     => 'twentytwentyfive//archive',
			'templateType'    => 'archive',
			'patterns'        => [
				[
					'name'  => 'twentytwentyfive/query-card',
					'title' => 'Query Card',
				],
			],
			'designSemantics' => [
				'surface'         => 'template',
				'sectionRole'     => 'archive-list',
				'layoutRhythm'    => 'grid',
				'mainDesignIssue' => 'rhythm',
			],
		],
		'response'                 => [
			'suggestions' => [
				[
					'label'              => 'Use query-card rhythm',
					'description'        => 'Preserve the archive grid rhythm with existing query-card patterns.',
					'operations'         => [],
					'patternSuggestions' => [ 'twentytwentyfive/query-card' ],
					'ranking'            => null,
				],
			],
		],
		'expectedMetrics'          => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
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
	'template_part_semantics_no_invalid_operations' => [
		'surface'                  => 'template-part',
		'alreadyGood'              => false,
		'parser'                   => 'template_part',
		'rankedMetricProbe'        => true,
		'context'                  => [
			'templatePartRef' => 'twentytwentyfive//footer',
			'slug'            => 'footer',
			'area'            => 'footer',
			'patterns'        => [
				[
					'name'  => 'twentytwentyfive/footer',
					'title' => 'Footer',
				],
			],
			'designSemantics' => [
				'surface'         => 'template-part',
				'sectionRole'     => 'footer',
				'contrastContext' => 'dark-parent',
				'mainDesignIssue' => 'contrast',
				'templatePart'    => [
					'ref'  => 'twentytwentyfive//footer',
					'slug' => 'footer',
					'area' => 'footer',
				],
			],
		],
		'response'                 => [
			'suggestions' => [
				[
					'label'              => 'Preserve footer contrast',
					'description'        => 'Use footer-safe pattern guidance without emitting invalid operations.',
					'operations'         => [],
					'patternSuggestions' => [ 'twentytwentyfive/footer' ],
					'ranking'            => null,
				],
			],
		],
		'expectedMetrics'          => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
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
