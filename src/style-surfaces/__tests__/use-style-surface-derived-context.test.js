// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createElement } = require( '@wordpress/element' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import { useStyleSurfaceDerivedContext } from '../use-style-surface-derived-context';

const { getRoot } = setupReactTest();

let captures = [];

function Probe( props ) {
	captures.push( useStyleSurfaceDerivedContext( props ) );
	return null;
}

function render( props ) {
	act( () => {
		getRoot().render( createElement( Probe, props ) );
	} );
}

// Stable design-semantics builder; identity is owned by the caller (useCallback).
const buildDesignSemantics = ( blocks ) => ( { count: blocks.length } );

beforeEach( () => {
	captures = [];
} );

describe( 'useStyleSurfaceDerivedContext', () => {
	test( 'returns referentially stable derivations across re-renders with unchanged inputs', () => {
		const editedBlocks = [];
		const rawRecommendations = [];
		const props = {
			editedBlocks,
			rawRecommendations,
			buildDesignSemantics,
		};

		render( props );
		render( props );

		expect( captures[ 1 ].templateStructure ).toBe(
			captures[ 0 ].templateStructure
		);
		expect( captures[ 1 ].templateVisibility ).toBe(
			captures[ 0 ].templateVisibility
		);
		expect( captures[ 1 ].designSemantics ).toBe(
			captures[ 0 ].designSemantics
		);
		expect( captures[ 1 ].rawSuggestions ).toBe(
			captures[ 0 ].rawSuggestions
		);
	} );

	test( 'recomputes block-derived values only when the blocks reference changes', () => {
		const editedBlocks = [];
		const rawRecommendations = [];

		render( { editedBlocks, rawRecommendations, buildDesignSemantics } );
		// rawRecommendations identity changes, blocks identity stable.
		render( {
			editedBlocks,
			rawRecommendations: [],
			buildDesignSemantics,
		} );

		expect( captures[ 1 ].templateStructure ).toBe(
			captures[ 0 ].templateStructure
		);
		expect( captures[ 1 ].designSemantics ).toBe(
			captures[ 0 ].designSemantics
		);
		// rawSuggestions follows rawRecommendations identity.
		expect( captures[ 1 ].rawSuggestions ).not.toBe(
			captures[ 0 ].rawSuggestions
		);

		// Now change the blocks reference: block-derived values recompute.
		render( {
			editedBlocks: [],
			rawRecommendations,
			buildDesignSemantics,
		} );
		expect( captures[ 2 ].templateStructure ).not.toBe(
			captures[ 0 ].templateStructure
		);
		expect( captures[ 2 ].designSemantics ).not.toBe(
			captures[ 0 ].designSemantics
		);
	} );

	test( 'tags each suggestion with a stable suggestionKey', () => {
		render( {
			editedBlocks: [],
			rawRecommendations: [ { id: 'x' }, { id: 'y' } ],
			buildDesignSemantics,
		} );

		expect( captures[ 0 ].rawSuggestions ).toHaveLength( 2 );
		captures[ 0 ].rawSuggestions.forEach( ( suggestion ) => {
			expect( suggestion.suggestionKey ).toBeTruthy();
		} );
	} );

	test( 'recomputes designSemantics when the builder identity changes', () => {
		const editedBlocks = [];
		const rawRecommendations = [];

		render( { editedBlocks, rawRecommendations, buildDesignSemantics } );
		render( {
			editedBlocks,
			rawRecommendations,
			buildDesignSemantics: ( blocks ) => ( { other: blocks.length } ),
		} );

		expect( captures[ 1 ].designSemantics ).not.toBe(
			captures[ 0 ].designSemantics
		);
	} );

	test( 'tolerates null inputs without throwing', () => {
		render( {
			editedBlocks: null,
			rawRecommendations: null,
			buildDesignSemantics,
		} );

		expect( captures[ 0 ].rawSuggestions ).toEqual( [] );
	} );
} );
