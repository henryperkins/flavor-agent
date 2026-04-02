const mockUseDispatch = jest.fn();

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		Button: ( { children, ...props } ) =>
			createElement(
				'button',
				{
					type: 'button',
					...props,
				},
				children
			),
	};
} );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import SuggestionChips from '../SuggestionChips';

let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	mockUseDispatch.mockImplementation( () => ( {
		applySuggestion: jest.fn().mockResolvedValue( true ),
	} ) );

	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => {
		root.unmount();
	} );
	container.remove();
} );

describe( 'SuggestionChips', () => {
	test( 'renders named chip controls as an ARIA group', () => {
		act( () => {
			root.render(
				<SuggestionChips
					clientId="block-1"
					label="AI color suggestions"
					suggestions={ [
						{
							label: 'Use accent color',
							panel: 'color',
						},
					] }
				/>
			);
		} );

		expect(
			container.querySelector(
				'[role="group"][aria-label="AI color suggestions"]'
			)
		).not.toBeNull();
	} );
} );
