jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);
jest.mock( '../fixture-export', () => ( {
	...jest.requireActual( '../fixture-export' ),
	buildFixtureReviewBundle: jest.fn( async ( candidate, reviewRecord ) => ( {
		candidate,
		reviewRecord,
	} ) ),
	downloadFixtureReviewBundle: jest.fn(),
} ) );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );
const apiFetch = require( '@wordpress/api-fetch' );
const {
	downloadFixtureReviewBundle,
	buildFixtureReviewBundle,
} = require( '../fixture-export' );
import * as component from '../FixtureExport';

const { getContainer, getRoot } = setupReactTest();

describe( 'FixtureExport', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		downloadFixtureReviewBundle.mockClear();
		buildFixtureReviewBundle.mockClear();
	} );
	async function render( canExportFixtures = true ) {
		await act( async () =>
			getRoot().render(
				<component.default
					bootData={ { canExportFixtures, nonce: 'nonce' } }
				/>
			)
		);
	}
	async function change( label, value ) {
		await act( async () => {
			const input = getContainer().querySelector(
				`input[aria-label="${ label }"]`
			);
			input.value = value;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	}
	async function fill() {
		await change( 'From (UTC)', '2026-10-01' );
		await change( 'Through (UTC)', '2026-10-07' );
		await change( 'Source custodian (local record)', 'Local Reviewer' );
		await change( 'Source site (local record)', 'private.example' );
		await change(
			'Collection permission and scope (local record)',
			'Approved local sample'
		);
		await act( async () =>
			getContainer().querySelector( 'input[aria-label="Block"]' ).click()
		);
	}
	test.each( [ true, 1, '1' ] )(
		'renders export for the explicit localized administrator flag %p without fetching',
		async ( flag ) => {
			await render( flag );
			expect(
				getContainer().querySelector(
					'.flavor-agent-activity-log__fixture-export'
				)
			).not.toBeNull();
			expect( apiFetch ).not.toHaveBeenCalled();
		}
	);
	test.each( [ false, 0, '0', '', 'false', 'true', 'yes', 2, null, [], {} ] )(
		'rejects nonadministrator or arbitrary localized flags %p',
		async ( flag ) => {
			await render( flag );
			expect( getContainer().querySelector( 'button' ) ).toBeNull();
			expect( apiFetch ).not.toHaveBeenCalled();
			expect( downloadFixtureReviewBundle ).not.toHaveBeenCalled();
		}
	);
	test( 'a missing administrator flag does not render export', async () => {
		await act( async () =>
			getRoot().render(
				<component.default bootData={ { nonce: 'nonce' } } />
			)
		);
		expect( getContainer().querySelector( 'button' ) ).toBeNull();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
	test( 'permission-gates the action and never exports on passive render or close', async () => {
		await render( false );
		expect( getContainer().querySelector( 'button' ) ).toBeNull();
		await render();
		await act( async () => getRoot().render( null ) );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( downloadFixtureReviewBundle ).not.toHaveBeenCalled();
	} );
	test( 'downloads only on explicit click with reviewed date/surface/limit selection', async () => {
		apiFetch.mockResolvedValue( {
			candidate: { schemaVersion: 'recommendation-fixture-export-v1' },
			rawActivity: { prompt: 'must not download' },
		} );
		await render( '1' );
		await fill();
		await act( async () =>
			getContainer().querySelector( 'button' ).click()
		);
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/flavor-agent/v1/activity/fixture-export',
				method: 'POST',
				data: {
					selection: {
						dateFrom: '2026-10-01',
						dateTo: '2026-10-07',
						surfaces: [ 'block' ],
						rowLimit: 500,
					},
				},
			} )
		);
		expect( buildFixtureReviewBundle ).toHaveBeenCalledWith(
			{ schemaVersion: 'recommendation-fixture-export-v1' },
			expect.objectContaining( {
				site: 'private.example',
				custodian: 'Local Reviewer',
			} )
		);
		expect( downloadFixtureReviewBundle ).toHaveBeenCalledTimes( 1 );
		expect(
			JSON.stringify( downloadFixtureReviewBundle.mock.calls )
		).not.toContain( 'rawActivity' );
		expect( getContainer().textContent ).toContain( 'restricted' );
	} );
	test( 'stale permission or unverified provenance cannot trigger a download', async () => {
		apiFetch.mockRejectedValue( {
			code: 'flavor_agent_fixture_provenance_unverified',
			message: 'Runtime provenance is unverified.',
		} );
		await render();
		await fill();
		await act( async () =>
			getContainer().querySelector( 'button' ).click()
		);
		expect( getContainer().textContent ).toContain(
			'Runtime provenance is unverified.'
		);
		expect( downloadFixtureReviewBundle ).not.toHaveBeenCalled();
		await render( false );
		expect( getContainer().querySelector( 'button' ) ).toBeNull();
	} );
} );
