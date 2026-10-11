jest.mock( '@wordpress/i18n', () =>
	require( '../../test-utils/i18n-mock' ).createI18nMock()
);

import { initializeSettingsPage } from '../settings-page-controller';

const SAVED_STATE = {
	pattern_backend: 'cloudflare_ai_search',
	cloudflare_ai_search_namespace: 'mock-namespace',
	cloudflare_ai_search_instance: 'mock-index',
	cloudflare_ai_search_signature: 'mock-signature',
	status: 'ready',
	indexed_count: 12,
	last_synced_at: '2026-10-11T10:00:00Z',
};

function response( payload, { ok = true, status = 200 } = {} ) {
	return { ok, status, text: async () => JSON.stringify( payload ) };
}

async function settleRequests() {
	for ( let count = 0; count < 12; count++ ) {
		await Promise.resolve();
	}
}

function renderSyncSettings( state = SAVED_STATE ) {
	const initialLabel =
		state.recommendations_ready === false ? 'Needs sync' : 'Ready';
	document.body.innerHTML = `
		<div class="flavor-agent-settings">
			<input name="flavor_agent_cloudflare_workers_ai_account_id" value="saved-account" />
			<input name="flavor_agent_cloudflare_workers_ai_api_token" type="password" value="" data-saved-secret="true" />
			<input name="flavor_agent_cloudflare_workers_ai_embedding_model" value="saved-model" />
			<input name="flavor_agent_cloudflare_pattern_ai_search_instance_id" value="saved-index" />
			<a data-pattern-overview-status="true"><p class="flavor-agent-settings__glance-value">${ initialLabel }</p></a>
			<span data-flavor-agent-status-badge="patterns">${ initialLabel }</span>
			<details data-flavor-agent-sync-panel>
				<summary>Sync <span data-pattern-status-badge="panel">${ initialLabel }</span></summary>
				<div class="flavor-agent-sync-panel"
					data-pattern-prerequisites-ready="1"
					data-pattern-sync-status="${ state.status }">
					<p id="flavor-agent-sync-summary">Pattern recommendations are ready.</p>
					<p id="flavor-agent-sync-config-hint" data-pattern-sync-config-hint hidden>Save Changes before syncing changed Cloudflare credentials or storage settings.</p>
					${ [ 'status', 'indexed_count', 'last_synced_at', 'stale_reason', 'last_error' ]
						.map(
							( metric ) =>
								`<div data-pattern-metric="${ metric }"><p data-pattern-metric-value="${ metric }">${
									metric === 'status'
										? initialLabel
										: state[ metric ] || ''
								}</p></div>`
						)
						.join( '' ) }
					<button type="button" id="flavor-agent-sync-button">Sync Pattern Catalog</button>
					<button type="button" id="flavor-agent-sync-check-button">Check sync status</button>
					<span id="flavor-agent-sync-spinner" class="spinner"></span>
					<span id="flavor-agent-sync-status" aria-hidden="true"></span>
					<span id="flavor-agent-sync-live-region" aria-live="polite"></span>
					<div id="flavor-agent-sync-notice" aria-live="polite"></div>
				</div>
			</details>
		</div>`;
	const root = document.querySelector( '.flavor-agent-settings' );
	root.querySelector(
		'.flavor-agent-sync-panel'
	).dataset.patternRuntimeState = JSON.stringify( state );
	return root;
}

function statusLabels( root ) {
	return [
		'[data-pattern-status-badge="panel"]',
		'[data-flavor-agent-status-badge="patterns"]',
		'.flavor-agent-settings__glance-value',
		'[data-pattern-metric-value="status"]',
	].map( ( selector ) => root.querySelector( selector ).textContent );
}

function start( root, fetchImpl ) {
	initializeSettingsPage( { root, fetchImpl, storage: null } );
	return {
		sync: root.querySelector( '#flavor-agent-sync-button' ),
		check: root.querySelector( '#flavor-agent-sync-check-button' ),
	};
}

describe( 'pattern sync monitoring and saved configuration', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		window.flavorAgentAdmin = {
			nonce: 'test-nonce',
			restUrl: 'https://example.test/wp-json/',
		};
	} );

	afterEach( () => {
		jest.clearAllTimers();
		jest.useRealTimers();
		delete window.flavorAgentAdmin;
		document.body.innerHTML = '';
	} );

	test( 'failed polling qualifies every status surface and retains confirmed index metadata', async () => {
		const root = renderSyncSettings();
		const indexing = { ...SAVED_STATE, status: 'indexing' };
		const fetchImpl = jest
			.fn()
			.mockResolvedValueOnce(
				response( { queued: true, runtimeState: indexing } )
			)
			.mockResolvedValueOnce(
				response(
					{ message: 'Gateway unavailable.' },
					{ ok: false, status: 503 }
				)
			);
		const { sync, check } = start( root, fetchImpl );
		sync.click();
		await settleRequests();

		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Status unavailable' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain( 'Last confirmed status: Syncing.' );
		expect(
			root.querySelector( '#flavor-agent-sync-live-region' ).textContent
		).toContain( 'The server may still be working.' );
		expect(
			root.querySelector( '[data-pattern-metric-value="indexed_count"]' )
				.textContent
		).toBe( '12' );
		expect(
			root.querySelector( '[data-pattern-metric-value="last_synced_at"]' )
				.textContent
		).toBe( SAVED_STATE.last_synced_at );
		expect( sync.disabled ).toBe( true );
		expect( sync.getAttribute( 'aria-describedby' ) ).toBe(
			'flavor-agent-sync-summary'
		);
		expect( check.disabled ).toBe( false );
		expect(
			root.querySelector( '#flavor-agent-sync-status' ).textContent
		).toBe( '' );
		sync.click();
		await jest.advanceTimersByTimeAsync( 10000 );
		expect( fetchImpl ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'successful status check clears uncertainty before permitting another sync', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockRejectedValueOnce( new Error( 'Connection lost.' ) )
			.mockResolvedValueOnce( response( { runtimeState: SAVED_STATE } ) );
		const { sync, check } = start( root, fetchImpl );
		sync.click();
		await settleRequests();
		expect( sync.disabled ).toBe( true );
		check.click();
		await settleRequests();

		expect(
			fetchImpl.mock.calls.map( ( [ , options ] ) => options.method )
		).toEqual( [ 'POST', 'GET' ] );
		expect( statusLabels( root ) ).toEqual( Array( 4 ).fill( 'Ready' ) );
		expect(
			root.querySelector( '#flavor-agent-sync-notice' ).textContent
		).toContain( 'Pattern catalog status updated: Ready.' );
		expect( sync.disabled ).toBe( false );
	} );

	test( 'a status check that confirms indexing resumes monitoring without another POST', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockRejectedValueOnce( new Error( 'Connection lost.' ) )
			.mockResolvedValueOnce(
				response( {
					runtimeState: { ...SAVED_STATE, status: 'indexing' },
				} )
			)
			.mockResolvedValueOnce( response( { runtimeState: SAVED_STATE } ) );
		const { sync, check } = start( root, fetchImpl );
		sync.click();
		await settleRequests();
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual( Array( 4 ).fill( 'Syncing' ) );
		expect( sync.disabled ).toBe( true );
		await jest.advanceTimersByTimeAsync( 5000 );
		expect( statusLabels( root ) ).toEqual( Array( 4 ).fill( 'Ready' ) );
		expect(
			fetchImpl.mock.calls.map( ( [ , options ] ) => options.method )
		).toEqual( [ 'POST', 'GET', 'GET' ] );
	} );

	test( 'a repeated failed status check preserves uncertainty and does not submit another job', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockRejectedValue( new Error( 'Offline.' ) );
		const { sync, check } = start( root, fetchImpl );
		sync.click();
		await settleRequests();
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Status unavailable' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain( 'Last confirmed status: Ready.' );
		expect( sync.disabled ).toBe( true );
		expect(
			fetchImpl.mock.calls.map( ( [ , options ] ) => options.method )
		).toEqual( [ 'POST', 'GET' ] );
	} );

	test( 'a lost POST response does not claim the server job failed', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockRejectedValue( new Error( 'Network interrupted.' ) );
		const { sync } = start( root, fetchImpl );
		sync.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Status unavailable' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-notice' ).textContent
		).toContain( 'may have been accepted' );
		expect( sync.disabled ).toBe( true );
	} );

	test.each( [
		[ 'pattern_sync_unconfigured', 400 ],
		[ 'pattern_sync_schedule_failed', 500 ],
		[ 'rest_cookie_invalid_nonce', 403 ],
	] )(
		'an explicit %s rejection permits recovery without pretending catalog progress',
		async ( code, status ) => {
			const root = renderSyncSettings();
			const fetchImpl = jest
				.fn()
				.mockResolvedValue(
					response(
						{ code, message: 'The request was rejected.' },
						{ ok: false, status }
					)
				);
			const { sync } = start( root, fetchImpl );
			sync.click();
			await settleRequests();
			expect( statusLabels( root ) ).toEqual(
				Array( 4 ).fill( 'Sync not started' )
			);
			expect(
				root.querySelector( '#flavor-agent-sync-summary' ).textContent
			).toContain( 'Last confirmed catalog status: Ready.' );
			expect(
				root.querySelector( '#flavor-agent-sync-notice' ).textContent
			).toContain( 'The request was rejected.' );
			expect( sync.disabled ).toBe( false );
		}
	);

	test.each( [ {}, { runtimeState: { status: 'unknown' } } ] )(
		'unusable successful status data does not imply completed sync: %j',
		async ( payload ) => {
			const root = renderSyncSettings( {
				...SAVED_STATE,
				status: 'indexing',
			} );
			const fetchImpl = jest
				.fn()
				.mockResolvedValue( response( payload ) );
			const { sync, check } = start( root, fetchImpl );
			await settleRequests();
			expect( statusLabels( root ) ).toEqual(
				Array( 4 ).fill( 'Status unavailable' )
			);
			expect(
				root.querySelector( '#flavor-agent-sync-notice' ).textContent
			).toContain( 'did not return a usable sync status' );
			expect( sync.disabled ).toBe( true );
			expect( check.disabled ).toBe( false );
		}
	);

	test( 'an accepted response without runtime state remains uncertain', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockResolvedValue( response( { queued: true } ) );
		const { sync } = start( root, fetchImpl );
		sync.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Status unavailable' )
		);
		expect( sync.disabled ).toBe( true );
	} );

	test.each( [
		'flavor_agent_cloudflare_workers_ai_account_id',
		'flavor_agent_cloudflare_workers_ai_api_token',
		'flavor_agent_cloudflare_workers_ai_embedding_model',
		'flavor_agent_cloudflare_pattern_ai_search_instance_id',
	] )(
		'unsaved %s blocks sync with visible associated instructions',
		( name ) => {
			const root = renderSyncSettings();
			const fetchImpl = jest.fn();
			const { sync, check } = start( root, fetchImpl );
			const field = root.querySelector( `[name="${ name }"]` );
			field.value = 'changed';
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			expect( sync.disabled ).toBe( true );
			expect( sync.getAttribute( 'aria-describedby' ) ).toBe(
				'flavor-agent-sync-config-hint'
			);
			expect(
				root.querySelector( '[data-pattern-sync-config-hint]' ).hidden
			).toBe( false );
			expect( check.disabled ).toBe( false );
			sync.click();
			expect( fetchImpl ).not.toHaveBeenCalled();
			field.value = field.defaultValue;
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			expect( sync.disabled ).toBe( false );
			expect(
				root.querySelector( '[data-pattern-sync-config-hint]' ).hidden
			).toBe( true );
		}
	);

	test( 'browser restored edits are compared with rendered saved values on initialization', () => {
		const root = renderSyncSettings();
		root.querySelector(
			'[name="flavor_agent_cloudflare_workers_ai_account_id"]'
		).value = 'restored-account';
		const { sync } = start( root, jest.fn() );
		expect( sync.disabled ).toBe( true );
		expect(
			root.querySelector( '[data-pattern-sync-config-hint]' ).hidden
		).toBe( false );
	} );

	test( 'blank saved secret values permit sync until the credentials are edited', () => {
		const root = renderSyncSettings();
		const { sync } = start( root, jest.fn() );
		const token = root.querySelector(
			'[name="flavor_agent_cloudflare_workers_ai_api_token"]'
		);
		expect( token.value ).toBe( '' );
		expect( sync.disabled ).toBe( false );
		token.value = 'new-token';
		token.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		expect( sync.disabled ).toBe( true );
		token.value = token.defaultValue;
		token.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( sync.disabled ).toBe( false );
	} );

	test( 'Cloudflare storage edits block sync without any backend picker state', () => {
		const root = renderSyncSettings();
		const { sync } = start( root, jest.fn() );
		expect( sync.disabled ).toBe( false );
		const cloudflare = root.querySelector(
			'[name="flavor_agent_cloudflare_pattern_ai_search_instance_id"]'
		);
		cloudflare.value = 'changed-index';
		cloudflare.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( sync.disabled ).toBe( true );
		expect( sync.getAttribute( 'aria-describedby' ) ).toBe(
			'flavor-agent-sync-config-hint'
		);
	} );

	test( 'checking status with an unsaved configuration does not unlock a sync using different saved settings', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockResolvedValue( response( { runtimeState: SAVED_STATE } ) );
		const { sync, check } = start( root, fetchImpl );
		const field = root.querySelector(
			'[name="flavor_agent_cloudflare_workers_ai_account_id"]'
		);
		field.value = 'changed';
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual( Array( 4 ).fill( 'Ready' ) );
		expect( sync.disabled ).toBe( true );
		expect(
			root.querySelector( '[data-pattern-sync-config-hint]' ).hidden
		).toBe( false );
		expect(
			fetchImpl.mock.calls.map( ( [ , options ] ) => options.method )
		).toEqual( [ 'GET' ] );
	} );

	test( 'a legacy catalog migration stays unavailable until a Cloudflare rebuild', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest.fn().mockResolvedValue(
			response( {
				runtimeState: {
					...SAVED_STATE,
					status: 'stale',
					indexed_count: 0,
					last_synced_at: null,
					stale_reason: 'pattern_store_migration_required',
				},
			} )
		);
		const { check } = start( root, fetchImpl );
		check.click();
		await settleRequests();
		expect(
			root.querySelector( '[data-flavor-agent-status-badge="patterns"]' )
				.textContent
		).toBe( 'Needs sync' );
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain(
			'unavailable until the catalog is rebuilt in Cloudflare AI Search'
		);
		expect(
			root.querySelector( '[data-pattern-metric-value="stale_reason"]' )
				.textContent
		).toContain( 'existing vectors are not copied' );
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).not.toContain( 'usable but out of date' );
	} );

	test.each( [
		'pattern_backend_changed',
		'cloudflare_ai_search_namespace_changed',
		'cloudflare_ai_search_instance_changed',
		'cloudflare_ai_search_signature_changed',
	] )(
		'compatibility drift %s requires sync rather than claiming a usable catalog',
		async ( reason ) => {
			const root = renderSyncSettings();
			const fetchImpl = jest.fn().mockResolvedValue(
				response( {
					runtimeState: {
						...SAVED_STATE,
						status: 'stale',
						stale_reason: reason,
						stale_reasons: [ reason ],
					},
				} )
			);
			const { sync, check } = start( root, fetchImpl );
			check.click();
			await settleRequests();
			expect( statusLabels( root ) ).toEqual(
				Array( 4 ).fill( 'Needs sync' )
			);
			expect(
				root.querySelector( '#flavor-agent-sync-summary' ).textContent
			).toContain(
				'unavailable until the catalog is rebuilt or revalidated'
			);
			expect(
				root.querySelector( '#flavor-agent-sync-summary' ).textContent
			).toContain( 'Review and save Cloudflare settings, then sync' );
			expect(
				root.querySelector(
					'[data-pattern-metric-value="stale_reason"]'
				).textContent
			).not.toBe( reason );
			expect(
				root.querySelector( '#flavor-agent-sync-live-region' )
					.textContent
			).toContain( 'Needs sync' );
			expect( sync.disabled ).toBe( false );
		}
	);

	test( 'secondary compatibility drift blocks a catalog whose primary reason is registry changes', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest.fn().mockResolvedValue(
			response( {
				runtimeState: {
					...SAVED_STATE,
					status: 'stale',
					stale_reason: 'pattern_registry_changed',
					stale_reasons: [
						'pattern_registry_changed',
						'cloudflare_ai_search_signature_changed',
					],
				},
			} )
		);
		start( root, fetchImpl ).check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Needs sync' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).not.toContain( 'usable but out of date' );
	} );

	test( 'failed monitoring retains the confirmed Needs sync label and catalog metadata', async () => {
		const incompatible = {
			...SAVED_STATE,
			status: 'stale',
			stale_reason: 'cloudflare_ai_search_signature_changed',
			stale_reasons: [ 'cloudflare_ai_search_signature_changed' ],
		};
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockResolvedValueOnce( response( { runtimeState: incompatible } ) )
			.mockResolvedValueOnce(
				response(
					{ message: 'Gateway unavailable.' },
					{ ok: false, status: 503 }
				)
			);
		const { sync, check } = start( root, fetchImpl );
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Needs sync' )
		);
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Status unavailable' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain( 'Last confirmed status: Needs sync.' );
		expect(
			root.querySelector( '[data-pattern-metric-value="indexed_count"]' )
				.textContent
		).toBe( '12' );
		expect(
			root.querySelector( '[data-pattern-metric-value="last_synced_at"]' )
				.textContent
		).toBe( SAVED_STATE.last_synced_at );
		expect( sync.disabled ).toBe( true );
		expect( check.disabled ).toBe( false );
	} );

	test( 'a rejected sync retains the confirmed Needs sync label and permits recovery', async () => {
		const incompatible = {
			...SAVED_STATE,
			status: 'stale',
			stale_reason: 'cloudflare_ai_search_instance_changed',
			stale_reasons: [ 'cloudflare_ai_search_instance_changed' ],
		};
		const root = renderSyncSettings();
		const fetchImpl = jest
			.fn()
			.mockResolvedValueOnce( response( { runtimeState: incompatible } ) )
			.mockResolvedValueOnce(
				response(
					{
						code: 'pattern_sync_schedule_failed',
						message: 'Unable to schedule sync.',
					},
					{ ok: false, status: 500 }
				)
			);
		const { sync, check } = start( root, fetchImpl );
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Needs sync' )
		);
		sync.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Sync not started' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain( 'Last confirmed catalog status: Needs sync.' );
		expect(
			root.querySelector( '[data-pattern-metric-value="indexed_count"]' )
				.textContent
		).toBe( '12' );
		expect(
			root.querySelector( '[data-pattern-metric-value="last_synced_at"]' )
				.textContent
		).toBe( SAVED_STATE.last_synced_at );
		expect( sync.disabled ).toBe( false );
		expect( check.disabled ).toBe( false );
		expect(
			fetchImpl.mock.calls.map( ( [ , options ] ) => options.method )
		).toEqual( [ 'GET', 'POST' ] );
	} );

	test( 'a confirmed registry-only stale catalog remains usable and clears initial blocked readiness', async () => {
		const root = renderSyncSettings( {
			status: 'stale',
			last_synced_at: SAVED_STATE.last_synced_at,
			recommendations_ready: false,
			stale_reason: 'pattern_registry_changed',
		} );
		const fetchImpl = jest.fn().mockResolvedValue(
			response( {
				runtimeState: {
					...SAVED_STATE,
					status: 'stale',
					stale_reason: 'pattern_registry_changed',
					stale_reasons: [ 'pattern_registry_changed' ],
				},
			} )
		);
		const { check } = start( root, fetchImpl );
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Needs sync' )
		);
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Refresh needed' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain( 'usable but out of date' );
	} );

	test( 'a partial ready response cannot inherit earlier catalog confirmation', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest.fn().mockResolvedValue(
			response( {
				runtimeState: {
					status: 'ready',
					indexed_count: 12,
					last_synced_at: SAVED_STATE.last_synced_at,
				},
			} )
		);
		const { sync, check } = start( root, fetchImpl );
		check.click();
		await settleRequests();
		expect( statusLabels( root ) ).toEqual(
			Array( 4 ).fill( 'Status unavailable' )
		);
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain( 'Last confirmed status: Ready' );
		expect(
			root.querySelector( '#flavor-agent-sync-live-region' ).textContent
		).toContain( 'Check sync status' );
		expect( sync.disabled ).toBe( true );
		expect( check.disabled ).toBe( false );
	} );

	test( 'actual indexing retains syncing even when previous catalog drift is recorded', async () => {
		const state = {
			...SAVED_STATE,
			status: 'indexing',
			stale_reason: 'cloudflare_ai_search_signature_changed',
			stale_reasons: [ 'cloudflare_ai_search_signature_changed' ],
		};
		const root = renderSyncSettings( state );
		const { sync } = start(
			root,
			jest.fn().mockResolvedValue( response( { runtimeState: state } ) )
		);
		await settleRequests();
		expect( statusLabels( root ) ).toEqual( Array( 4 ).fill( 'Syncing' ) );
		expect(
			root.querySelector( '#flavor-agent-sync-summary' ).textContent
		).toContain( 'syncing now' );
		expect( sync.disabled ).toBe( true );
	} );

	test( 'a confirmed server job error updates the catalog and gives error feedback instead of monitoring uncertainty', async () => {
		const root = renderSyncSettings();
		const fetchImpl = jest.fn().mockResolvedValue(
			response( {
				runtimeState: {
					...SAVED_STATE,
					status: 'error',
					last_error: 'Storage rejected the saved key.',
				},
			} )
		);
		const { sync, check } = start( root, fetchImpl );
		check.click();
		await settleRequests();
		expect(
			root.querySelector( '[data-pattern-status-badge="panel"]' )
				.textContent
		).toBe( 'Error' );
		expect(
			root.querySelector( '#flavor-agent-sync-notice .notice-error' )
				.textContent
		).toContain( 'Storage rejected the saved key.' );
		expect(
			root.querySelector( '[data-pattern-metric-value="last_error"]' )
				.textContent
		).toBe( 'Storage rejected the saved key.' );
		expect( sync.disabled ).toBe( false );
	} );

	test( 'both actions prevent overlapping requests while a status check is pending', async () => {
		const root = renderSyncSettings();
		let resolveRequest;
		const fetchImpl = jest.fn().mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolveRequest = resolve;
				} )
		);
		const { sync, check } = start( root, fetchImpl );
		check.click();
		expect( check.disabled ).toBe( true );
		expect( sync.disabled ).toBe( true );
		check.click();
		sync.click();
		expect( fetchImpl ).toHaveBeenCalledTimes( 1 );
		resolveRequest( response( { runtimeState: SAVED_STATE } ) );
		await settleRequests();
		expect( check.disabled ).toBe( false );
		expect( sync.disabled ).toBe( false );
	} );
} );
