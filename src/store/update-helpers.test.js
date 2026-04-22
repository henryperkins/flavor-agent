import {
	attributeSnapshotsMatch,
	buildBlockRecommendationDiagnostics,
	buildSafeAttributeUpdates,
	buildUndoAttributeUpdates,
	getBlockSuggestionExecutionInfo,
	getSuggestionAttributeUpdates,
	sanitizeRecommendationsForContext,
} from './update-helpers';

describe( 'update helpers', () => {
	test( 'buildSafeAttributeUpdates preserves unrelated metadata and style values', () => {
		const currentAttributes = {
			metadata: {
				name: 'Hero',
				blockVisibility: {
					viewport: {
						mobile: true,
					},
				},
			},
			style: {
				color: {
					text: 'var(--wp--preset--color--contrast)',
					background: 'var(--wp--preset--color--base)',
				},
				spacing: {
					padding: 'var(--wp--preset--spacing--40)',
				},
			},
		};
		const suggestedUpdates = {
			metadata: {
				blockVisibility: {
					viewport: {
						mobile: false,
					},
				},
			},
			style: {
				color: {
					background: 'var(--wp--preset--color--accent)',
				},
			},
		};

		expect(
			buildSafeAttributeUpdates( currentAttributes, suggestedUpdates )
		).toEqual( {
			metadata: {
				name: 'Hero',
				blockVisibility: {
					viewport: {
						mobile: false,
					},
				},
			},
			style: {
				color: {
					text: 'var(--wp--preset--color--contrast)',
					background: 'var(--wp--preset--color--accent)',
				},
				spacing: {
					padding: 'var(--wp--preset--spacing--40)',
				},
			},
		} );
	} );

	test( 'buildSafeAttributeUpdates strips custom CSS channels before merging', () => {
		const currentAttributes = {
			style: {
				color: {
					text: 'var(--wp--preset--color--contrast)',
				},
			},
		};
		const suggestedUpdates = {
			customCSS: '.wp-block-paragraph { color: red; }',
			style: {
				css: '.wp-block-paragraph { color: red; }',
				color: {
					background: 'var(--wp--preset--color--accent)',
				},
			},
		};

		expect(
			buildSafeAttributeUpdates( currentAttributes, suggestedUpdates )
		).toEqual( {
			style: {
				color: {
					text: 'var(--wp--preset--color--contrast)',
					background: 'var(--wp--preset--color--accent)',
				},
			},
		} );
	} );

	test( 'sanitizeRecommendationsForContext drops locked settings and non-content updates', () => {
		const recommendations = {
			settings: [
				{
					label: 'Hide on mobile',
					attributeUpdates: {
						metadata: {
							blockVisibility: false,
						},
					},
				},
			],
			styles: [
				{
					label: 'Use accent background',
					attributeUpdates: {
						style: {
							color: {
								background: 'var(--wp--preset--color--accent)',
							},
						},
					},
				},
			],
			block: [
				{
					label: 'Tighten copy',
					attributeUpdates: {
						content: 'Shorter text',
						metadata: {
							blockVisibility: false,
						},
					},
				},
			],
			explanation: 'Only content changes are allowed here.',
		};
		const blockContext = {
			isInsideContentOnly: true,
			contentAttributes: {
				content: {
					role: 'content',
				},
			},
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, blockContext )
		).toEqual( {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Tighten copy',
					attributeUpdates: {
						content: 'Shorter text',
					},
				},
			],
			explanation: 'Only content changes are allowed here.',
		} );
	} );

	test( 'sanitizeRecommendationsForContext drops suggestions that only contain CSS channels', () => {
		const recommendations = {
			styles: [
				{
					label: 'Inject CSS',
					attributeUpdates: {
						customCSS: '.wp-block-group { color: red; }',
					},
				},
			],
			block: [
				{
					label: 'Add inline CSS',
					attributeUpdates: {
						style: {
							css: '.wp-block-group { color: red; }',
						},
					},
				},
			],
			explanation: 'Unsafe CSS should be removed before the UI renders.',
		};

		expect( sanitizeRecommendationsForContext( recommendations ) ).toEqual(
			{
				settings: [],
				styles: [],
				block: [],
				explanation:
					'Unsafe CSS should be removed before the UI renders.',
			}
		);
	} );

	test( 'sanitizeRecommendationsForContext strips nested style.css and raw CSS strings while keeping safe style updates', () => {
		const recommendations = {
			styles: [
				{
					label: 'Use accent background',
					attributeUpdates: {
						style: {
							css: '.wp-block-group { color: red; }',
							color: {
								background: 'var(--wp--preset--color--accent)',
								text: 'color: red;',
							},
						},
					},
				},
			],
			explanation: 'Only theme-backed updates should remain.',
		};

		expect( sanitizeRecommendationsForContext( recommendations ) ).toEqual(
			{
				settings: [],
				styles: [
					{
						label: 'Use accent background',
						attributeUpdates: {
							style: {
								color: {
									background:
										'var(--wp--preset--color--accent)',
								},
							},
						},
					},
				],
				block: [],
				explanation: 'Only theme-backed updates should remain.',
			}
		);
	} );

	test( 'getSuggestionAttributeUpdates blocks non-content updates in contentOnly mode', () => {
		const blockContext = {
			isInsideContentOnly: true,
			contentAttributes: {
				content: {
					role: 'content',
				},
			},
		};

		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						metadata: {
							blockVisibility: {
								viewport: {
									mobile: false,
								},
							},
						},
					},
				},
				blockContext
			)
		).toEqual( {} );
	} );

	test( 'getSuggestionAttributeUpdates strips unsafe CSS channels before apply', () => {
		expect(
			getSuggestionAttributeUpdates( {
				attributeUpdates: {
					customCSS: '.wp-block-paragraph { color: red; }',
					style: {
						css: '.wp-block-paragraph { color: red; }',
						color: {
							background: 'var(--wp--preset--color--accent)',
							text: 'color: red;',
						},
					},
				},
			} )
		).toEqual( {
			style: {
				color: {
					background: 'var(--wp--preset--color--accent)',
				},
			},
		} );
	} );

	test( 'sanitizeRecommendationsForContext restricts when block itself is contentOnly', () => {
		const recommendations = {
			settings: [
				{ label: 'Toggle', attributeUpdates: { isToggled: true } },
			],
			styles: [
				{
					label: 'Bold bg',
					attributeUpdates: {
						style: { color: { background: '#000' } },
					},
				},
			],
			block: [
				{
					label: 'Update content',
					attributeUpdates: {
						content: 'New text',
						metadata: { foo: 1 },
					},
				},
			],
			explanation: 'Test',
		};
		const blockContext = {
			editingMode: 'contentOnly',
			isInsideContentOnly: false,
			contentAttributes: { content: { role: 'content' } },
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, blockContext )
		).toEqual( {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Update content',
					attributeUpdates: { content: 'New text' },
				},
			],
			explanation: 'Test',
		} );
	} );

	test( 'sanitizeRecommendationsForContext drops wrapper suggestions for supportsContentRole blocks without direct content attributes', () => {
		const recommendations = {
			settings: [
				{ label: 'Toggle', attributeUpdates: { isToggled: true } },
			],
			styles: [
				{
					label: 'Outline',
					attributeUpdates: {
						className: 'is-style-outline',
					},
				},
			],
			block: [
				{
					label: 'Change wrapper spacing',
					attributeUpdates: {
						style: {
							spacing: {
								padding: 'var(--wp--preset--spacing--40)',
							},
						},
					},
				},
			],
			explanation: 'Wrapper updates are locked here.',
		};
		const blockContext = {
			editingMode: 'contentOnly',
			supportsContentRole: true,
			contentAttributes: {},
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, blockContext )
		).toEqual( {
			settings: [],
			styles: [],
			block: [],
			explanation: 'Wrapper updates are locked here.',
		} );
	} );

	test( 'sanitizeRecommendationsForContext returns empty for disabled blocks', () => {
		const recommendations = {
			settings: [
				{ label: 'Toggle', attributeUpdates: { isToggled: true } },
			],
			styles: [
				{
					label: 'Bold bg',
					attributeUpdates: {
						style: { color: { background: '#000' } },
					},
				},
			],
			block: [
				{
					label: 'Update content',
					attributeUpdates: { content: 'New text' },
				},
			],
			explanation: 'Disabled block test',
		};
		const blockContext = {
			editingMode: 'disabled',
			isInsideContentOnly: false,
			contentAttributes: { content: { role: 'content' } },
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, blockContext )
		).toEqual( {
			settings: [],
			styles: [],
			block: [],
			explanation: 'Disabled block test',
		} );
	} );

	test( 'getSuggestionAttributeUpdates restricts when block itself is contentOnly', () => {
		const blockContext = {
			editingMode: 'contentOnly',
			isInsideContentOnly: false,
			contentAttributes: { content: { role: 'content' } },
		};

		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						content: 'Hi',
						backgroundColor: 'accent',
					},
				},
				blockContext
			)
		).toEqual( { content: 'Hi' } );
	} );

	test( 'getSuggestionAttributeUpdates blocks wrapper updates for supportsContentRole blocks without direct content attributes', () => {
		const blockContext = {
			editingMode: 'contentOnly',
			supportsContentRole: true,
			contentAttributes: {},
		};

		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						className: 'is-style-outline',
					},
				},
				blockContext
			)
		).toEqual( {} );
	} );

	test( 'getSuggestionAttributeUpdates returns empty for disabled blocks', () => {
		const blockContext = {
			editingMode: 'disabled',
			isInsideContentOnly: false,
			contentAttributes: { content: { role: 'content' } },
		};

		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						content: 'Hi',
					},
				},
				blockContext
			)
		).toEqual( {} );
	} );

	test( 'sanitizeRecommendationsForContext: disabled takes precedence over isInsideContentOnly', () => {
		const recommendations = {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Update content',
					attributeUpdates: { content: 'New text' },
				},
			],
			explanation: 'Precedence test',
		};
		const blockContext = {
			editingMode: 'disabled',
			isInsideContentOnly: true,
			contentAttributes: { content: { role: 'content' } },
		};

		const result = sanitizeRecommendationsForContext(
			recommendations,
			blockContext
		);

		expect( result.block ).toEqual( [] );
		expect( result.explanation ).toBe( 'Precedence test' );
	} );

	test( 'getSuggestionAttributeUpdates preserves nested metadata and style updates when unlocked', () => {
		const suggestion = {
			attributeUpdates: {
				metadata: {
					blockVisibility: {
						viewport: {
							mobile: false,
						},
					},
				},
				style: {
					color: {
						background: 'var(--wp--preset--color--accent)',
					},
				},
			},
		};

		expect(
			getSuggestionAttributeUpdates( suggestion, {
				isInsideContentOnly: false,
			} )
		).toEqual( suggestion.attributeUpdates );
	} );

	test( 'getSuggestionAttributeUpdates rejects unsupported metadata.bindings updates when bindable attributes are known', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						metadata: {
							name: 'Hero CTA',
							bindings: {
								url: {
									source: 'core/post-meta',
									args: { key: 'cta_url' },
								},
								text: {
									source: 'core/post-meta',
									args: { key: 'cta_label' },
								},
							},
						},
					},
				},
				{
					bindableAttributes: [ 'url' ],
					isInsideContentOnly: false,
				}
			)
		).toEqual( {
			metadata: {
				name: 'Hero CTA',
				bindings: {
					url: {
						source: 'core/post-meta',
						args: { key: 'cta_url' },
					},
				},
			},
		} );
	} );

	test( 'getSuggestionAttributeUpdates preserves unrelated metadata when bindings are disallowed', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						metadata: {
							name: 'Hero CTA',
							bindings: {
								url: {
									source: 'core/post-meta',
									args: { key: 'cta_url' },
								},
							},
						},
					},
				},
				{
					bindableAttributes: [],
					isInsideContentOnly: false,
				}
			)
		).toEqual( {
			metadata: {
				name: 'Hero CTA',
			},
		} );
	} );

	test( 'getSuggestionAttributeUpdates drops binding-only suggestions when no bindable attributes are allowed', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						metadata: {
							bindings: {
								url: {
									source: 'core/post-meta',
									args: { key: 'cta_url' },
								},
							},
						},
					},
				},
				{
					bindableAttributes: [],
					isInsideContentOnly: false,
				}
			)
		).toEqual( {} );
	} );

	test( 'getBlockSuggestionExecutionInfo keeps structural recommendations advisory even with safe local updates', () => {
		expect(
			getBlockSuggestionExecutionInfo(
				{
					type: 'structural_recommendation',
					attributeUpdates: {
						metadata: {
							blockVisibility: {
								viewport: {
									mobile: false,
								},
							},
						},
					},
				},
				{
					isInsideContentOnly: false,
				}
			)
		).toEqual( {
			allowedUpdates: {},
			isAdvisory: true,
			isAdvisoryOnly: true,
			isExecutable: false,
		} );
	} );

	test( 'getBlockSuggestionExecutionInfo allows freeform block gap updates when the block supports them', () => {
		expect(
			getBlockSuggestionExecutionInfo(
				{
					attributeUpdates: {
						style: {
							spacing: {
								blockGap: '1rem',
							},
						},
					},
				},
				{
					inspectorPanels: {
						dimensions: [ 'spacing.blockGap' ],
					},
					isInsideContentOnly: false,
				},
				{
					allowedPanels: [ 'dimensions' ],
					panelMappingKnown: true,
					presetSlugs: {
						spacing: [],
					},
					styleSupportPaths: [ 'spacing.blockGap' ],
				}
			)
		).toEqual( {
			allowedUpdates: {
				style: {
					spacing: {
						blockGap: '1rem',
					},
				},
			},
			isAdvisory: false,
			isAdvisoryOnly: false,
			isExecutable: true,
		} );
	} );

	test( 'sanitizeRecommendationsForContext preserves optional UI metadata for unlocked blocks', () => {
		const recommendations = {
			settings: [
				{
					label: 'Use accent background',
					type: 'attribute_change',
					currentValue: 'base',
					suggestedValue: 'accent',
					attributeUpdates: {
						backgroundColor: 'accent',
					},
				},
			],
			styles: [
				{
					label: 'Outline',
					type: 'style_variation',
					isCurrentStyle: false,
					isRecommended: true,
					attributeUpdates: {
						className: 'is-style-outline',
					},
				},
			],
			block: [],
			explanation: 'Use the theme accent and outline style.',
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, {
				isInsideContentOnly: false,
			} )
		).toEqual( recommendations );
	} );

	test( 'sanitizeRecommendationsForContext drops settings and styles when the execution contract declares explicit empty panels', () => {
		const recommendations = {
			settings: [
				{
					label: 'Use accent background',
					panel: 'color',
					attributeUpdates: {
						backgroundColor: 'accent',
					},
				},
			],
			styles: [
				{
					label: 'Outline spacing',
					panel: 'dimensions',
					attributeUpdates: {
						style: {
							spacing: {
								padding: 'var:preset|spacing|40',
							},
						},
					},
				},
			],
			block: [
				{
					label: 'Use outline style',
					type: 'style_variation',
					attributeUpdates: {
						className: 'is-style-outline',
					},
				},
			],
			explanation: 'Only the registered style variation is executable.',
		};
		const executionContract = {
			hasExplicitlyEmptyPanels: true,
			allowedPanels: [],
			styleSupportPaths: [],
			registeredStyles: [ 'outline' ],
			presetSlugs: {
				color: [ 'accent' ],
				spacing: [ '40' ],
			},
		};

		expect(
			sanitizeRecommendationsForContext(
				recommendations,
				{},
				executionContract
			)
		).toEqual( {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Use outline style',
					type: 'style_variation',
					attributeUpdates: {
						className: 'is-style-outline',
					},
				},
			],
			explanation: 'Only the registered style variation is executable.',
		} );
	} );

	test( 'sanitizeRecommendationsForContext preserves best-effort settings and styles when panel mapping is unknown', () => {
		const recommendations = {
			settings: [
				{
					label: 'Turn on drop cap',
					panel: 'general',
					attributeUpdates: {
						dropCap: true,
					},
				},
			],
			styles: [
				{
					label: 'Use accent background',
					panel: 'color',
					attributeUpdates: {
						backgroundColor: 'accent',
					},
				},
			],
			block: [],
			explanation: 'Unknown panel mappings should stay best-effort.',
		};
		const executionContract = {
			panelMappingKnown: false,
			allowedPanels: [],
			styleSupportPaths: [],
			presetSlugs: {
				color: [ 'accent' ],
			},
		};

		expect(
			sanitizeRecommendationsForContext(
				recommendations,
				{},
				executionContract
			)
		).toEqual( recommendations );
	} );

	test( 'getSuggestionAttributeUpdates preserves style updates when panel mapping is unknown', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						backgroundColor: 'accent',
					},
				},
				{},
				{
					panelMappingKnown: false,
					styleSupportPaths: [],
					presetSlugs: {
						color: [ 'accent' ],
					},
				}
			)
		).toEqual( {
			backgroundColor: 'accent',
		} );
	} );

	test( 'sanitizeRecommendationsForContext strips unsupported metadata.bindings updates for unlocked blocks', () => {
		const recommendations = {
			settings: [
				{
					label: 'Connect button text and URL',
					attributeUpdates: {
						metadata: {
							name: 'Hero CTA',
							bindings: {
								url: {
									source: 'core/post-meta',
									args: { key: 'cta_url' },
								},
								text: {
									source: 'core/post-meta',
									args: { key: 'cta_label' },
								},
							},
						},
					},
				},
			],
			styles: [],
			block: [],
			explanation: 'Only supported bindings should survive.',
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, {
				bindableAttributes: [ 'url' ],
				isInsideContentOnly: false,
			} )
		).toEqual( {
			settings: [
				{
					label: 'Connect button text and URL',
					attributeUpdates: {
						metadata: {
							name: 'Hero CTA',
							bindings: {
								url: {
									source: 'core/post-meta',
									args: { key: 'cta_url' },
								},
							},
						},
					},
				},
			],
			styles: [],
			block: [],
			explanation: 'Only supported bindings should survive.',
		} );
	} );

	test( 'buildBlockRecommendationDiagnostics explains when suggestions were routed to non-block lanes', () => {
		const rawRecommendations = {
			settings: [],
			styles: [
				{
					label: 'Use accent text',
					attributeUpdates: {
						textColor: 'accent',
					},
				},
			],
			block: [],
			explanation: 'Use the accent preset for stronger emphasis.',
		};
		const sanitizedRecommendations = sanitizeRecommendationsForContext(
			rawRecommendations,
			{
				editingMode: 'default',
			}
		);

		expect(
			buildBlockRecommendationDiagnostics(
				rawRecommendations,
				sanitizedRecommendations,
				{
					editingMode: 'default',
				}
			)
		).toEqual(
			expect.objectContaining( {
				hasEmptyBlockResult: true,
				title: 'No block-lane suggestions returned',
				rawCounts: {
					settings: 0,
					styles: 1,
					block: 0,
				},
				finalCounts: {
					settings: 0,
					styles: 1,
					block: 0,
				},
				reasonCodes: expect.arrayContaining( [
					'model_returned_no_block_items',
					'suggestions_routed_to_other_lanes',
				] ),
				detailLines: expect.arrayContaining( [
					'Flavor Agent returned 1 style, but none in the block lane.',
				] ),
			} )
		);
	} );

	test( 'sanitizeRecommendationsForContext preserves non-binding metadata when bindings are disallowed', () => {
		const recommendations = {
			settings: [
				{
					label: 'Connect CTA fields',
					attributeUpdates: {
						metadata: {
							name: 'Hero CTA',
							bindings: {
								url: {
									source: 'core/post-meta',
									args: { key: 'cta_url' },
								},
							},
						},
					},
				},
			],
			styles: [],
			block: [],
			explanation:
				'Unsupported bindings should not remove unrelated metadata.',
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, {
				bindableAttributes: [],
				isInsideContentOnly: false,
			} )
		).toEqual( {
			settings: [
				{
					label: 'Connect CTA fields',
					attributeUpdates: {
						metadata: {
							name: 'Hero CTA',
						},
					},
				},
			],
			styles: [],
			block: [],
			explanation:
				'Unsupported bindings should not remove unrelated metadata.',
		} );
	} );

	test( 'sanitizeRecommendationsForContext preserves advisory structural suggestions in contentOnly mode', () => {
		const recommendations = {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Wrap this block in a Group',
					type: 'structural_recommendation',
					attributeUpdates: {
						metadata: {
							blockVisibility: {
								viewport: {
									mobile: false,
								},
							},
						},
					},
				},
				{
					label: 'Use accent background',
					type: 'attribute_change',
					attributeUpdates: {
						backgroundColor: 'accent',
					},
				},
			],
			explanation:
				'Preserve structural advice, drop locked wrapper edits.',
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, {
				editingMode: 'contentOnly',
				contentAttributes: {
					content: {
						role: 'content',
					},
				},
			} )
		).toEqual( {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Wrap this block in a Group',
					type: 'structural_recommendation',
					attributeUpdates: [],
				},
			],
			explanation:
				'Preserve structural advice, drop locked wrapper edits.',
		} );
	} );

	test( 'sanitizeRecommendationsForContext preserves advisory structural suggestions for supportsContentRole blocks without direct content attributes', () => {
		const recommendations = {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Wrap this block in a Group',
					type: 'structural_recommendation',
					attributeUpdates: {
						className: 'is-style-outline',
					},
				},
				{
					label: 'Change wrapper spacing',
					type: 'attribute_change',
					attributeUpdates: {
						style: {
							spacing: {
								padding: 'var(--wp--preset--spacing--40)',
							},
						},
					},
				},
			],
			explanation: 'Only advisory structure guidance should survive.',
		};

		expect(
			sanitizeRecommendationsForContext( recommendations, {
				editingMode: 'contentOnly',
				supportsContentRole: true,
				contentAttributes: {},
			} )
		).toEqual( {
			settings: [],
			styles: [],
			block: [
				{
					label: 'Wrap this block in a Group',
					type: 'structural_recommendation',
					attributeUpdates: [],
				},
			],
			explanation: 'Only advisory structure guidance should survive.',
		} );
	} );

	test( 'getSuggestionAttributeUpdates rejects unsupported preset slugs under an authoritative execution contract', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						backgroundColor: 'accent',
					},
				},
				{},
				{
					allowedPanels: [ 'color' ],
					styleSupportPaths: [ 'color.background' ],
					presetSlugs: {
						color: [ 'base' ],
					},
				}
			)
		).toEqual( {} );
	} );

	test( 'getSuggestionAttributeUpdates preserves safe custom property style values under an authoritative execution contract', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						style: {
							color: {
								text: 'var(--wp--custom--brand-accent)',
							},
						},
					},
				},
				{},
				{
					allowedPanels: [ 'color' ],
					styleSupportPaths: [ 'color.text' ],
					presetSlugs: {
						color: [ 'base' ],
					},
					enabledFeatures: {
						textColor: true,
					},
				}
			)
		).toEqual( {
			style: {
				color: {
					text: 'var(--wp--custom--brand-accent)',
				},
			},
		} );
	} );

	test( 'getSuggestionAttributeUpdates allows raw fallback values when the relevant preset families are explicitly empty', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						style: {
							color: {
								background: '#222',
							},
							typography: {
								fontSize: '18px',
								fontFamily: '"Literata", serif',
							},
							shadow: '0 12px 32px rgba(0, 0, 0, 0.18)',
						},
					},
				},
				{},
				{
					allowedPanels: [ 'color', 'shadow', 'typography' ],
					styleSupportPaths: [
						'color.background',
						'typography.fontSize',
						'typography.__experimentalFontFamily',
						'shadow',
					],
					presetSlugs: {
						color: [],
						fontsize: [],
						fontfamily: [],
						shadow: [],
					},
					enabledFeatures: {
						backgroundColor: true,
					},
				}
			)
		).toEqual( {
			style: {
				color: {
					background: '#222',
				},
				typography: {
					fontSize: '18px',
					fontFamily: '"Literata", serif',
				},
				shadow: '0 12px 32px rgba(0, 0, 0, 0.18)',
			},
		} );
	} );

	test( 'getSuggestionAttributeUpdates preserves supported typography controls under an authoritative execution contract', () => {
		expect(
			getSuggestionAttributeUpdates(
				{
					attributeUpdates: {
						style: {
							typography: {
								fontWeight: '700',
								fontStyle: 'italic',
								letterSpacing: '-0.02em',
								textDecoration: 'underline',
								textTransform: 'uppercase',
							},
						},
					},
				},
				{},
				{
					allowedPanels: [ 'typography' ],
					styleSupportPaths: [
						'typography.fontWeight',
						'typography.fontStyle',
						'typography.letterSpacing',
						'typography.textDecoration',
						'typography.textTransform',
					],
					enabledFeatures: {
						fontWeight: true,
						fontStyle: true,
						letterSpacing: true,
						textDecoration: true,
						textTransform: true,
					},
				}
			)
		).toEqual( {
			style: {
				typography: {
					fontWeight: '700',
					fontStyle: 'italic',
					letterSpacing: '-0.02em',
					textDecoration: 'underline',
					textTransform: 'uppercase',
				},
			},
		} );
	} );

	test( 'getBlockSuggestionExecutionInfo treats unregistered style variations as non-executable under an authoritative execution contract', () => {
		expect(
			getBlockSuggestionExecutionInfo(
				{
					type: 'style_variation',
					attributeUpdates: {
						className: 'is-style-fancy',
					},
				},
				{},
				{
					registeredStyles: [ 'outline' ],
				}
			)
		).toEqual( {
			allowedUpdates: {},
			isAdvisory: true,
			isAdvisoryOnly: false,
			isExecutable: false,
		} );
	} );

	test( 'buildUndoAttributeUpdates restores removed keys and previous nested objects', () => {
		expect(
			buildUndoAttributeUpdates(
				{
					content: 'Old copy',
					style: {
						color: {
							background: '#fff',
						},
					},
				},
				{
					content: 'New copy',
					style: {
						color: {
							background: '#000',
						},
					},
					className: 'is-style-contrast',
				}
			)
		).toEqual( {
			content: 'Old copy',
			style: {
				color: {
					background: '#fff',
				},
			},
			className: undefined,
		} );
	} );

	test( 'attributeSnapshotsMatch compares structural snapshots for undo safety checks', () => {
		expect(
			attributeSnapshotsMatch(
				{ content: 'Same', className: 'alpha' },
				{ content: 'Same', className: 'alpha' }
			)
		).toBe( true );
		expect(
			attributeSnapshotsMatch(
				{
					metadata: {
						name: 'Hero',
						bindings: {
							url: {
								source: 'core/post-meta',
								args: { key: 'cta_url' },
							},
						},
					},
					style: {
						color: {
							text: 'var(--wp--preset--color--contrast)',
							background: 'var(--wp--preset--color--base)',
						},
					},
				},
				{
					style: {
						color: {
							background: 'var(--wp--preset--color--base)',
							text: 'var(--wp--preset--color--contrast)',
						},
					},
					metadata: {
						bindings: {
							url: {
								args: { key: 'cta_url' },
								source: 'core/post-meta',
							},
						},
						name: 'Hero',
					},
				}
			)
		).toBe( true );
		expect(
			attributeSnapshotsMatch(
				{ content: 'Same', className: 'alpha' },
				{ content: 'Changed', className: 'alpha' }
			)
		).toBe( false );
	} );
} );
