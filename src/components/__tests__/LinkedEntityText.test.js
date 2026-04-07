jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import LinkedEntityText from '../LinkedEntityText';

const { getContainer, getRoot } = setupReactTest();

describe( 'LinkedEntityText', () => {
	test( 'renders non-interactive entity text when no click handler is provided', () => {
		act( () => {
			getRoot().render(
				<LinkedEntityText
					text="Use the header area for spacing."
					entities={ [
						{
							text: 'header area',
							type: 'area',
							tooltip: 'Theme header area',
						},
					] }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain(
			'Use the header area for spacing.'
		);
		expect( getContainer().querySelector( 'button' ) ).toBeNull();

		const entity = getContainer().querySelector(
			'.flavor-agent-inline-link'
		);
		expect( entity ).toBeTruthy();
		expect( entity.tagName ).toBe( 'SPAN' );
		expect( entity.getAttribute( 'title' ) ).toBe( 'Theme header area' );
	} );

	test( 'prefers the longest entity when overlapping matches start at the same index', () => {
		const onEntityClick = jest.fn();
		const shorterEntity = {
			text: 'header',
			type: 'area',
		};
		const longerEntity = {
			text: 'header area',
			type: 'part',
		};

		act( () => {
			getRoot().render(
				<LinkedEntityText
					text="Use the header area for spacing."
					entities={ [ shorterEntity, longerEntity ] }
					onEntityClick={ onEntityClick }
				/>
			);
		} );

		const entityButton = getContainer().querySelector( 'button' );
		expect( entityButton ).toBeTruthy();
		expect( entityButton.textContent ).toBe( 'header area' );

		act( () => {
			entityButton.click();
		} );

		expect( onEntityClick ).toHaveBeenCalledWith( longerEntity );
	} );
} );
