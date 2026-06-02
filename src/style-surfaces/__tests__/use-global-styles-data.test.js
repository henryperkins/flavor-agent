// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createElement } = require( '@wordpress/element' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

const mockGetGlobalStylesUserConfig = jest.fn();

jest.mock( '../../utils/style-operations', () => ( {
	getGlobalStylesUserConfig: ( ...args ) =>
		mockGetGlobalStylesUserConfig( ...args ),
} ) );

import {
	EMPTY_STYLE_CONFIG,
	EMPTY_STYLE_VARIATIONS,
	selectGlobalStylesDataDependencies,
	useGlobalStylesData,
} from '../use-global-styles-data';

const { getRoot } = setupReactTest();

let captures = [];

function Probe( props ) {
	captures.push( useGlobalStylesData( props.registry, props.dependencies ) );
	return null;
}

function render( props ) {
	act( () => {
		getRoot().render( createElement( Probe, props ) );
	} );
}

beforeEach( () => {
	captures = [];
	mockGetGlobalStylesUserConfig.mockReset();
} );

describe( 'selectGlobalStylesDataDependencies', () => {
	test( 'selects only raw Global Styles records and references', () => {
		const userConfigRecord = { styles: { color: {} } };
		const baseConfigRecord = { styles: { spacing: {} } };
		const variationRecords = [ { title: 'Default' } ];
		const core = {
			getCurrentGlobalStylesId: jest.fn( () => '17' ),
			getEditedEntityRecord: jest.fn( () => userConfigRecord ),
			getEntityRecord: jest.fn(),
			getCurrentThemeBaseGlobalStyles: jest.fn( () => baseConfigRecord ),
			getCurrentThemeGlobalStylesVariations: jest.fn(
				() => variationRecords
			),
		};

		expect( selectGlobalStylesDataDependencies( () => core ) ).toEqual( {
			globalStylesId: '17',
			userConfigRecord,
			baseConfigRecord,
			variationRecords,
		} );
		expect( core.getEntityRecord ).not.toHaveBeenCalled();
	} );

	test( 'returns null variation records instead of allocating an empty fallback', () => {
		const core = {
			getCurrentGlobalStylesId: jest.fn( () => '17' ),
			getEditedEntityRecord: jest.fn( () => null ),
			getEntityRecord: jest.fn( () => null ),
			getCurrentThemeBaseGlobalStyles: jest.fn( () => null ),
			getCurrentThemeGlobalStylesVariations: jest.fn( () => undefined ),
			__experimentalGetCurrentThemeGlobalStylesVariations: jest.fn(
				() => undefined
			),
		};

		expect(
			selectGlobalStylesDataDependencies( () => core ).variationRecords
		).toBeNull();
	} );
} );

describe( 'useGlobalStylesData', () => {
	test( 'memoizes normalized data while raw selector dependencies are unchanged', () => {
		const registry = { select: jest.fn() };
		const dependencies = {
			globalStylesId: '17',
			userConfigRecord: { styles: {} },
			baseConfigRecord: { styles: {} },
			variationRecords: [],
		};
		const currentConfig = { settings: {}, styles: {}, _links: {} };
		const mergedConfig = { settings: {}, styles: {}, _links: {} };
		const availableVariations = [];

		mockGetGlobalStylesUserConfig.mockReturnValue( {
			globalStylesId: '17',
			userConfig: currentConfig,
			mergedConfig,
			variations: availableVariations,
		} );

		render( { registry, dependencies } );
		render( { registry, dependencies } );

		expect( mockGetGlobalStylesUserConfig ).toHaveBeenCalledTimes( 1 );
		expect( captures[ 1 ] ).toBe( captures[ 0 ] );
		expect( captures[ 0 ].currentConfig ).toBe( currentConfig );
		expect( captures[ 0 ].mergedConfig ).toBe( mergedConfig );
		expect( captures[ 0 ].availableVariations ).toBe( availableVariations );
	} );

	test( 'uses stable empty fallbacks when Global Styles data is unavailable', () => {
		mockGetGlobalStylesUserConfig.mockReturnValue( null );

		render( {
			registry: { select: jest.fn() },
			dependencies: {
				globalStylesId: null,
				userConfigRecord: null,
				baseConfigRecord: null,
				variationRecords: null,
			},
		} );

		expect( captures[ 0 ] ).toEqual( {
			globalStylesId: '',
			currentConfig: EMPTY_STYLE_CONFIG,
			mergedConfig: EMPTY_STYLE_CONFIG,
			availableVariations: EMPTY_STYLE_VARIATIONS,
		} );
	} );
} );
