import { buildPatternAdaptationContext } from '../pattern-adaptation-context';

function makeEditor( blocks, order, roots = {} ) {
	return {
		getBlockName: jest.fn( ( id ) => blocks[ id ]?.name || '' ),
		getBlockAttributes: jest.fn( ( id ) => blocks[ id ]?.attributes || {} ),
		getBlockOrder: jest.fn( ( root ) => order[ root ?? '' ] || [] ),
		getBlockRootClientId: jest.fn( ( id ) => roots[ id ] ?? null ),
	};
}

describe( 'buildPatternAdaptationContext', () => {
	test( 'reads preceding heading level, nearby levels, and aligns', () => {
		const blocks = {
			h1: { name: 'core/heading', attributes: { level: 2 } },
			p1: { name: 'core/paragraph', attributes: {} },
			img: { name: 'core/image', attributes: { align: 'wide' } },
			root: { name: 'core/group', attributes: { align: 'full' } },
		};
		const order = { '': [ 'h1', 'p1', 'img' ] };

		const ctx = buildPatternAdaptationContext(
			makeEditor( blocks, order ),
			{
				inserterRootClientId: null,
				insertionIndex: 3,
				siblingOrder: order[ '' ],
			}
		);

		expect( ctx.precedingHeadingLevel ).toBe( 2 );
		expect( ctx.nearbyHeadingLevels ).toEqual( [ 2 ] );
		expect( ctx.siblingAligns ).toEqual( [ 'wide' ] );
	} );

	test( 'reads root align from the inserter root block', () => {
		const blocks = {
			root: { name: 'core/group', attributes: { align: 'full' } },
		};
		const ctx = buildPatternAdaptationContext(
			makeEditor( blocks, { root: [] } ),
			{
				inserterRootClientId: 'root',
				insertionIndex: 0,
				siblingOrder: [],
			}
		);

		expect( ctx.rootAlign ).toBe( 'full' );
		expect( ctx.precedingHeadingLevel ).toBeNull();
	} );

	test( 'returns empty signal for a missing editor', () => {
		expect( buildPatternAdaptationContext( null, {} ) ).toEqual( {
			precedingHeadingLevel: null,
			nearbyHeadingLevels: [],
			rootAlign: '',
			siblingAligns: [],
		} );
	} );
} );
