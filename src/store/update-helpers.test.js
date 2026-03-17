import {
	buildSafeAttributeUpdates,
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
} );
