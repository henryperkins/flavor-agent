// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createElement } = require( '@wordpress/element' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import { useContentDerivedContext } from '../use-content-derived-context';

const { getRoot } = setupReactTest();

let captures = [];

function Probe( props ) {
	captures.push( useContentDerivedContext( props ) );
	return null;
}

function render( props ) {
	act( () => {
		getRoot().render( createElement( Probe, props ) );
	} );
}

function baseProps( overrides = {} ) {
	return {
		activityLog: [],
		postId: 42,
		postType: 'post',
		title: 'Working draft',
		excerpt: '',
		content: 'Retail floors.',
		slug: 'working-draft',
		status: 'draft',
		...overrides,
	};
}

beforeEach( () => {
	captures = [];
} );

describe( 'useContentDerivedContext', () => {
	test( 'returns referentially stable derived values across re-renders with unchanged inputs', () => {
		const activityLog = [];
		const props = baseProps( { activityLog } );

		render( props );
		render( props );

		expect( captures ).toHaveLength( 2 );
		// The whole derived bundle is stable...
		expect( captures[ 1 ].postContext ).toBe( captures[ 0 ].postContext );
		expect( captures[ 1 ].activityEntries ).toBe(
			captures[ 0 ].activityEntries
		);
	} );

	test( 'keeps postContext stable when an unrelated input object identity changes but values do not', () => {
		// activityLog identity changes (new array) but post primitives are equal.
		render( baseProps( { activityLog: [] } ) );
		render( baseProps( { activityLog: [] } ) );

		expect( captures[ 1 ].postContext ).toBe( captures[ 0 ].postContext );
	} );

	test( 'produces a new postContext when a post primitive changes', () => {
		render( baseProps() );
		render( baseProps( { title: 'Edited title' } ) );

		expect( captures[ 1 ].postContext ).not.toBe(
			captures[ 0 ].postContext
		);
		expect( captures[ 1 ].postContext.title ).toBe( 'Edited title' );
	} );

	test( 'filters activity entries to the content surface in reverse order', () => {
		const activityLog = [
			{ id: 'a', surface: 'content' },
			{ id: 'b', surface: 'block' },
			{ id: 'c', surface: 'content' },
		];

		render( baseProps( { activityLog } ) );

		expect( captures[ 0 ].activityEntries.map( ( e ) => e.id ) ).toEqual( [
			'c',
			'a',
		] );
	} );

	test( 'recomputes activityEntries only when the activity log reference changes', () => {
		const activityLog = [ { id: 'a', surface: 'content' } ];

		render( baseProps( { activityLog } ) );
		render( baseProps( { activityLog } ) );
		const stableRef = captures[ 1 ].activityEntries;
		expect( stableRef ).toBe( captures[ 0 ].activityEntries );

		render(
			baseProps( { activityLog: [ { id: 'a', surface: 'content' } ] } )
		);
		expect( captures[ 2 ].activityEntries ).not.toBe( stableRef );
	} );
} );
