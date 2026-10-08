// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import useRecommendationDraft from '../use-recommendation-draft';

const { getContainer, getRoot } = setupReactTest();
let draft;

function Draft() {
	draft = useRecommendationDraft();
	return <span>{ draft.prompt }</span>;
}

test( 'exposes prompt edits synchronously before React renders', () => {
	act( () => getRoot().render( <Draft /> ) );
	act( () => {
		draft.setPrompt( 'Changed before render' );
		expect( draft.getPrompt() ).toBe( 'Changed before render' );
		draft.resetPrompt();
		expect( draft.getPrompt() ).toBe( '' );
		draft.hydratePrompt( 'Hydrated before render' );
		expect( draft.getPrompt() ).toBe( 'Hydrated before render' );
	} );
} );

test( 'hydrates untouched stored requests on mount and on a later successful result', () => {
	act( () => getRoot().render( <Draft /> ) );
	act( () => draft.hydratePrompt( 'Stored request' ) );
	expect( getContainer().textContent ).toBe( 'Stored request' );
	act( () => draft.hydratePrompt( 'Completed new request' ) );
	expect( getContainer().textContent ).toBe( 'Completed new request' );
	act( () => getRoot().render( null ) );
	act( () => getRoot().render( <Draft /> ) );
	act( () => draft.hydratePrompt( 'Completed new request' ) );
	expect( getContainer().textContent ).toBe( 'Completed new request' );
} );

test( 'retains an explicitly cleared draft until the scope resets', () => {
	act( () => getRoot().render( <Draft /> ) );
	act( () => draft.setPrompt( '' ) );
	act( () => draft.hydratePrompt( 'Old request' ) );
	expect( getContainer().textContent ).toBe( '' );
	act( () => draft.resetPrompt() );
	act( () => draft.hydratePrompt( 'New scope stored request' ) );
	expect( getContainer().textContent ).toBe( 'New scope stored request' );
} );
