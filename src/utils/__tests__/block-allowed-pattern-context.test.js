import {
	BLOCK_OPERATION_ACTION_INSERT_AFTER,
	BLOCK_OPERATION_ACTION_INSERT_BEFORE,
	BLOCK_OPERATION_ACTION_REPLACE,
} from '../block-operation-catalog';
import {
	buildAllowedPatternContext,
	buildBlockOperationTargetSignature,
} from '../block-allowed-pattern-context';

describe( 'block allowed pattern context', () => {
	test( 'normalizes visible renderable patterns with selected-target actions', () => {
		const targetSignature = buildBlockOperationTargetSignature( {
			clientId: 'block-1',
			name: 'core/group',
			structuralIdentity: {
				role: 'hero-slot',
			},
			editingMode: 'default',
		} );

		expect(
			buildAllowedPatternContext(
				[
					{
						name: 'theme/hero',
						title: 'Hero',
						source: 'theme',
						categories: [ 'featured', '' ],
						blockTypes: [ 'core/group' ],
						content: '<!-- wp:group /-->',
					},
					{
						name: 'theme/text-band',
						title: 'Text band',
						source: 'theme',
						blockTypes: [ 'core/paragraph' ],
						content: '<!-- wp:paragraph /-->',
					},
					{
						name: 'theme/hidden',
						title: 'Hidden',
						source: 'theme',
						content: '<!-- wp:group /-->',
						inserter: false,
					},
					{
						name: 'theme/empty',
						title: 'Empty',
						source: 'theme',
						content: '',
					},
				],
				{
					targetClientId: 'block-1',
					targetBlockName: 'core/group',
					targetSignature,
				}
			)
		).toEqual( {
			targetClientId: 'block-1',
			targetBlockName: 'core/group',
			targetSignature,
			allowedPatterns: [
				{
					name: 'theme/hero',
					title: 'Hero',
					source: 'theme',
					categories: [ 'featured' ],
					blockTypes: [ 'core/group' ],
					allowedActions: [
						BLOCK_OPERATION_ACTION_INSERT_BEFORE,
						BLOCK_OPERATION_ACTION_INSERT_AFTER,
						BLOCK_OPERATION_ACTION_REPLACE,
					],
				},
				{
					name: 'theme/text-band',
					title: 'Text band',
					source: 'theme',
					categories: [],
					blockTypes: [ 'core/paragraph' ],
					allowedActions: [
						BLOCK_OPERATION_ACTION_INSERT_BEFORE,
						BLOCK_OPERATION_ACTION_INSERT_AFTER,
					],
				},
			],
		} );
	} );

	test( 'omits patterns when the selected target cannot be structurally changed', () => {
		expect(
			buildAllowedPatternContext(
				[
					{
						name: 'theme/hero',
						title: 'Hero',
						source: 'theme',
						content: '<!-- wp:group /-->',
					},
				],
				{
					targetClientId: 'block-1',
					targetBlockName: 'core/group',
					targetSignature: 'target-sig',
					editingMode: 'contentOnly',
				}
			)
		).toEqual( {
			targetClientId: 'block-1',
			targetBlockName: 'core/group',
			targetSignature: 'target-sig',
			allowedPatterns: [],
		} );
	} );
} );
