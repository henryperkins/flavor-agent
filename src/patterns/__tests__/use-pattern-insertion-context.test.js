// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createElement } = require( '@wordpress/element' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import { usePatternInsertionContext } from '../use-pattern-insertion-context';

const { getRoot } = setupReactTest();

let captures = [];

function createEditor( state ) {
	return {
		getBlockName: jest.fn(
			( clientId ) => state.blockNames?.[ clientId ] || ''
		),
		getBlockAttributes: jest.fn(
			( clientId ) => state.blockAttributes?.[ clientId ] || {}
		),
		getBlockRootClientId: jest.fn(
			( clientId ) => state.blockRoots?.[ clientId ] ?? null
		),
		getBlockOrder: jest.fn(
			( rootClientId ) => state.blockOrder?.[ rootClientId ] || []
		),
	};
}

function Probe( props ) {
	captures.push( usePatternInsertionContext( props ) );
	return null;
}

function render( props ) {
	act( () => {
		getRoot().render( createElement( Probe, props ) );
	} );
}

beforeEach( () => {
	captures = [];
	window.flavorAgentData = {
		templatePartAreas: {
			'site-header': 'header',
		},
	};
} );

afterEach( () => {
	delete window.flavorAgentData;
} );

describe( 'usePatternInsertionContext', () => {
	test( 'returns the same context reference when insertion inputs are unchanged', () => {
		const state = {
			blockNames: { 'root-a': 'core/group' },
			blockRoots: { 'root-a': null },
			blockOrder: { 'root-a': [] },
			blockAttributes: {},
		};
		const editor = createEditor( state );
		const blockTree = [];
		const siblingOrder = state.blockOrder[ 'root-a' ];
		const props = {
			editor,
			inserterRootClientId: 'root-a',
			insertionIndex: 0,
			blockTree,
			siblingOrder,
		};

		render( props );
		render( props );

		expect( captures[ 1 ] ).toBe( captures[ 0 ] );
		expect( captures[ 0 ] ).toEqual( {
			rootBlock: 'core/group',
			ancestors: [ 'core/group' ],
			nearbySiblings: [],
		} );
	} );

	test( 'derives template-part metadata and nearby siblings from the insertion target', () => {
		const state = {
			blockNames: {
				'tpl-a': 'core/template-part',
				'group-a': 'core/group',
				'sibling-a': 'core/paragraph',
				'sibling-b': 'core/image',
				'sibling-c': 'core/buttons',
			},
			blockRoots: {
				'group-a': 'tpl-a',
				'tpl-a': null,
			},
			blockOrder: {
				'group-a': [ 'sibling-a', 'sibling-b', 'sibling-c' ],
			},
			blockAttributes: {
				'tpl-a': {
					slug: 'site-header',
				},
				'group-a': {
					layout: {
						type: 'flex',
					},
				},
			},
		};
		const editor = createEditor( state );

		render( {
			editor,
			inserterRootClientId: 'group-a',
			insertionIndex: 1,
			blockTree: [],
			siblingOrder: state.blockOrder[ 'group-a' ],
		} );

		expect( captures[ 0 ] ).toEqual( {
			rootBlock: 'core/group',
			ancestors: [ 'core/template-part', 'core/group' ],
			nearbySiblings: [ 'core/paragraph', 'core/image', 'core/buttons' ],
			templatePartArea: 'header',
			templatePartSlug: 'site-header',
			containerLayout: 'flex',
		} );
		expect( editor.getBlockAttributes ).toHaveBeenCalledWith( 'group-a' );
		expect( editor.getBlockAttributes ).toHaveBeenCalledWith( 'tpl-a' );
	} );

	test( 'recomputes when the sibling order changes around the same insertion point', () => {
		const state = {
			blockNames: {
				'root-a': 'core/group',
				'sibling-a': 'core/paragraph',
				'sibling-b': 'core/image',
			},
			blockRoots: { 'root-a': null },
			blockOrder: {
				'root-a': [ 'sibling-a' ],
			},
			blockAttributes: {},
		};
		const editor = createEditor( state );

		render( {
			editor,
			inserterRootClientId: 'root-a',
			insertionIndex: 1,
			blockTree: [],
			siblingOrder: state.blockOrder[ 'root-a' ],
		} );

		state.blockOrder = {
			'root-a': [ 'sibling-a', 'sibling-b' ],
		};

		render( {
			editor,
			inserterRootClientId: 'root-a',
			insertionIndex: 1,
			blockTree: [],
			siblingOrder: state.blockOrder[ 'root-a' ],
		} );

		expect( captures[ 1 ] ).not.toBe( captures[ 0 ] );
		expect( captures[ 0 ].nearbySiblings ).toEqual( [ 'core/paragraph' ] );
		expect( captures[ 1 ].nearbySiblings ).toEqual( [
			'core/paragraph',
			'core/image',
		] );
	} );

	test( 'keeps root-level insertion context targetable without a root block', () => {
		const state = {
			blockNames: {},
			blockRoots: {},
			blockOrder: {
				'': [],
			},
			blockAttributes: {},
		};

		render( {
			editor: createEditor( state ),
			inserterRootClientId: null,
			insertionIndex: 0,
			blockTree: [],
			siblingOrder: state.blockOrder[ '' ],
		} );

		expect( captures[ 0 ] ).toEqual( {
			ancestors: [],
			nearbySiblings: [],
		} );
	} );
} );
