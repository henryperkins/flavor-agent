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
} );
