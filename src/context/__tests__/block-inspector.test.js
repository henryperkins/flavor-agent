jest.mock( '@wordpress/data', () => ( {
	select: jest.fn(),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	store: {},
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: {},
} ) );

const { resolveInspectorPanels } = require( '../block-inspector' );

describe( 'resolveInspectorPanels', () => {
	test( 'maps WP 7.0 support keys to the same panels as the server collector', () => {
		expect(
			resolveInspectorPanels( {
				customCSS: true,
				listView: true,
			} )
		).toEqual( {
			advanced: [ 'customCSS' ],
			settings: [ 'listView' ],
		} );
	} );
} );
