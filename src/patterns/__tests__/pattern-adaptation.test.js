const mockCloneBlock = jest.fn();

jest.mock( '@wordpress/blocks', () => ( {
	cloneBlock: ( ...args ) => mockCloneBlock( ...args ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

import { buildPatternAdaptationPreview } from '../pattern-adaptation';

function deepClone( block ) {
	return JSON.parse( JSON.stringify( block ) );
}

const THEME_TOKENS = {
	color: {
		palette: [
			{ slug: 'base' },
			{ slug: 'contrast' },
			{ slug: 'primary' },
		],
		backgroundEnabled: true,
		textEnabled: true,
	},
	spacing: {
		spacingSizes: [
			{ slug: '20' },
			{ slug: '40' },
			{ slug: '60' },
		],
	},
};

const EMPTY_THEME_TOKENS = {
	color: { palette: [], backgroundEnabled: true, textEnabled: true },
	spacing: { spacingSizes: [] },
};

const REGISTRY = {
	getBlockType: jest.fn( () => ( { supports: {} } ) ),
	getBlockStyles: jest.fn( () => [] ),
};

const BASE_CTX = {
	precedingHeadingLevel: null,
	nearbyHeadingLevels: [],
	rootAlign: '',
	siblingAligns: [],
};

beforeEach( () => {
	mockCloneBlock.mockReset();
	mockCloneBlock.mockImplementation( deepClone );
	REGISTRY.getBlockType.mockReset();
	REGISTRY.getBlockType.mockReturnValue( { supports: {} } );
	REGISTRY.getBlockStyles.mockReset();
	REGISTRY.getBlockStyles.mockReturnValue( [] );
} );

function run( overrides = {} ) {
	return buildPatternAdaptationPreview( {
		pattern: { name: 'theme/hero' },
		sourceBlocks: [ { name: 'core/paragraph', attributes: {} } ],
		adaptationContext: BASE_CTX,
		insertionTargetSignature: 'target-sig',
		resolvedContextSignature: 'resolved-sig',
		themeTokens: THEME_TOKENS,
		blockRegistry: REGISTRY,
		...overrides,
	} );
}

describe( 'buildPatternAdaptationPreview scaffold', () => {
	test( 'refuses synced/user pattern references', () => {
		const result = run( {
			pattern: { name: 'core/block/12', type: 'user', id: 12 },
			sourceBlocks: [
				{ name: 'core/block', attributes: { ref: 12 } },
			],
		} );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unsupported_synced_reference' );
		expect( result.blocks ).toEqual( [] );
		expect( result.plan ).toBeNull();
		expect( result.adaptationSignature ).toBe( '' );
	} );

	test( 'blocks when no rule applies (no theme presets needed)', () => {
		const result = run();

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'unsupported_block_support' );
	} );

	test( 'reports missing_theme_tokens when the theme exposes no presets', () => {
		const result = run( {
			sourceBlocks: [
				{
					name: 'core/group',
					attributes: { backgroundColor: 'off-theme' },
				},
			],
			themeTokens: EMPTY_THEME_TOKENS,
		} );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'missing_theme_tokens' );
	} );

	test( 'clones source blocks exactly once and never mutates the source', () => {
		const sourceBlocks = [ { name: 'core/paragraph', attributes: {} } ];
		run( { sourceBlocks } );

		expect( mockCloneBlock ).toHaveBeenCalledTimes( sourceBlocks.length );
		expect( sourceBlocks[ 0 ].attributes ).toEqual( {} );
	} );

	test( 'blocks an empty source block array', () => {
		const result = run( { sourceBlocks: [] } );

		expect( result.status ).toBe( 'blocked' );
		expect( result.reason ).toBe( 'adapted_blocks_not_insertable' );
	} );
} );

describe( 'heading level + alignment rules', () => {
	test( 'sets heading level to nearest preceding heading + 1', () => {
		const result = run( {
			sourceBlocks: [ { name: 'core/heading', attributes: { level: 4 } } ],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 },
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.level ).toBe( 3 );
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'level',
				from: 4,
				to: 3,
				reason: 'nearby_heading_hierarchy',
			} )
		);
	} );

	test( 'clamps heading level to 6 and skips when already aligned', () => {
		const aligned = run( {
			sourceBlocks: [ { name: 'core/heading', attributes: { level: 3 } } ],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 2 },
		} );
		expect( aligned.status ).toBe( 'blocked' );

		const clamped = run( {
			sourceBlocks: [ { name: 'core/heading', attributes: { level: 2 } } ],
			adaptationContext: { ...BASE_CTX, precedingHeadingLevel: 6 },
		} );
		expect( clamped.blocks[ 0 ].attributes.level ).toBe( 6 );
	} );

	test( 'matches container alignment when the block supports it', () => {
		REGISTRY.getBlockType.mockImplementation( ( name ) =>
			name === 'core/image'
				? { supports: { align: [ 'wide', 'full' ] } }
				: { supports: {} }
		);

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: { ...BASE_CTX, rootAlign: 'full' },
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.align ).toBe( 'full' );
		expect( result.plan.changes ).toContainEqual(
			expect.objectContaining( {
				attribute: 'align',
				to: 'full',
				reason: 'match_container_alignment',
			} )
		);
	} );

	test( 'does not apply an alignment the block does not support', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { align: [ 'wide' ] },
		} );

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: { ...BASE_CTX, rootAlign: 'full' },
		} );

		expect( result.status ).toBe( 'blocked' );
	} );

	test( 'falls back to the most frequent sibling align with no root align', () => {
		REGISTRY.getBlockType.mockReturnValue( {
			supports: { align: [ 'wide', 'full' ] },
		} );

		const result = run( {
			sourceBlocks: [ { name: 'core/image', attributes: {} } ],
			adaptationContext: {
				...BASE_CTX,
				rootAlign: '',
				siblingAligns: [ 'wide', 'full', 'wide' ],
			},
		} );

		expect( result.status ).toBe( 'ready' );
		expect( result.blocks[ 0 ].attributes.align ).toBe( 'wide' );
	} );
} );
