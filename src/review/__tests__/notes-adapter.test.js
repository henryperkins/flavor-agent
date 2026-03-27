import {
	createNotesReviewProjection,
	normalizeNotesReviewEvidence,
	serializeNotesReviewProjection,
} from '../notes-adapter';

describe( 'notes adapter', () => {
	test( 'normalizes shared review evidence into a stable adapter shape', () => {
		expect(
			normalizeNotesReviewEvidence( {
				surface: 'template-part',
				interactionState: 'preview-ready',
				title: 'Refresh header structure',
				summary: 'Review the deterministic operations before apply.',
				advisoryOnly: false,
				previewRequired: true,
				operations: [
					{
						key: 'replace-header',
						type: 'replace_block_with_pattern',
						patternTitle: 'Hero',
					},
				],
				references: [
					{
						slug: 'header',
						type: 'template-part',
					},
				],
			} )
		).toEqual( {
			surface: 'template-part',
			interactionState: 'preview-ready',
			title: 'Refresh header structure',
			summary: 'Review the deterministic operations before apply.',
			advisoryOnly: false,
			previewRequired: true,
			operations: [
				{
					id: 'replace-header',
					type: 'replace_block_with_pattern',
					label: 'Hero',
					summary: '',
				},
			],
			references: [
				{
					id: 'header',
					label: 'header',
					type: 'template-part',
				},
			],
		} );
	} );

	test( 'creates and serializes an optional notes projection without runtime editor dependencies', () => {
		const projection = createNotesReviewProjection( {
			surface: 'navigation',
			interactionState: 'advisory-ready',
			title: 'Navigation review',
			summary: 'Manual changes only.',
			advisoryOnly: true,
		} );

		expect( projection ).toEqual( {
			kind: 'flavor-agent/review-note',
			title: 'Navigation review',
			surface: 'navigation',
			status: 'advisory-ready',
			body: 'Manual changes only.',
			meta: {
				advisoryOnly: true,
				previewRequired: false,
				operationCount: 0,
				referenceCount: 0,
			},
			operations: [],
			references: [],
		} );
		expect(
			JSON.parse(
				serializeNotesReviewProjection( {
					surface: 'navigation',
					interactionState: 'advisory-ready',
					title: 'Navigation review',
					summary: 'Manual changes only.',
					advisoryOnly: true,
				} )
			)
		).toEqual( projection );
	} );
} );
