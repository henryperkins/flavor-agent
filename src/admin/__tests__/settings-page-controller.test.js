import { initializeSettingsPage } from '../settings-page-controller';

function createStorage( initialValues = {} ) {
	const values = new Map( Object.entries( initialValues ) );

	return {
		getItem( key ) {
			return values.has( key ) ? values.get( key ) : null;
		},
		removeItem( key ) {
			values.delete( key );
		},
		setItem( key, value ) {
			values.set( key, value );
		},
	};
}

function flushPromises() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

function renderSettingsPage( {
	defaultSection = 'chat',
	forceSection = '',
	prerequisiteMessage = '',
	prerequisitesReady = '1',
} = {} ) {
	document.body.innerHTML = `
		<div
			class="flavor-agent-settings"
			data-default-section="${ defaultSection }"
			data-force-section="${ forceSection }"
			data-open-section-storage-key="flavor-agent-settings-open-section"
		>
			<section id="flavor-agent-section-chat">
				<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="chat">
					<summary>Chat</summary>
				</details>
			</section>
			<section id="flavor-agent-section-patterns">
				<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="patterns">
					<summary>Patterns</summary>
				</details>
			</section>
			<section id="flavor-agent-section-docs">
				<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="docs">
					<summary>Docs</summary>
				</details>
			</section>

			<a
				data-pattern-overview-status="true"
				class="flavor-agent-settings__glance-item flavor-agent-settings__glance-item--warning"
			>
				<p class="flavor-agent-settings__glance-value">Needs sync</p>
			</a>
			<span
				class="flavor-agent-settings-section__badge flavor-agent-settings-section__badge--warning"
				data-flavor-agent-status-badge="patterns"
			>
				Needs sync
			</span>

			<details class="flavor-agent-settings-subpanel flavor-agent-settings-subpanel--sync" data-flavor-agent-sync-panel>
				<summary class="flavor-agent-settings-subpanel__summary">
					<span>Sync Pattern Catalog</span>
					<span
						class="flavor-agent-settings-section__badge flavor-agent-settings-section__badge--warning"
						data-pattern-status-badge="panel"
					>
						Needs sync
					</span>
				</summary>
				<div
					class="flavor-agent-settings-subpanel__body flavor-agent-sync-panel"
					data-pattern-prerequisites-ready="${ prerequisitesReady }"
					data-pattern-prerequisite-message="${ prerequisiteMessage }"
				>
					<p id="flavor-agent-sync-summary" class="flavor-agent-sync-panel__summary">
						Pattern recommendations are not available until you sync the catalog.
					</p>
					${
						prerequisiteMessage
							? `<p class="flavor-agent-sync-panel__prerequisites" data-pattern-prerequisite-copy>${ prerequisiteMessage }</p>`
							: ''
					}
					<div class="flavor-agent-sync-panel__metrics">
						<div class="flavor-agent-sync-panel__metric" data-pattern-metric="status">
							<p class="flavor-agent-sync-panel__metric-value" data-pattern-metric-value="status">Not synced</p>
						</div>
						<div class="flavor-agent-sync-panel__metric" data-pattern-metric="indexed_count">
							<p class="flavor-agent-sync-panel__metric-value" data-pattern-metric-value="indexed_count">0</p>
						</div>
						<div class="flavor-agent-sync-panel__metric" data-pattern-metric="last_synced_at">
							<p class="flavor-agent-sync-panel__metric-value" data-pattern-metric-value="last_synced_at">Not synced yet</p>
						</div>
						<div class="flavor-agent-sync-panel__metric is-hidden" data-pattern-metric="stale_reason" hidden>
							<p class="flavor-agent-sync-panel__metric-value" data-pattern-metric-value="stale_reason"></p>
						</div>
						<div class="flavor-agent-sync-panel__metric is-hidden" data-pattern-metric="last_error" hidden>
							<p class="flavor-agent-sync-panel__metric-value" data-pattern-metric-value="last_error"></p>
						</div>
						<div class="flavor-agent-sync-panel__metric" data-pattern-metric="qdrant_collection">
							<p class="flavor-agent-sync-panel__metric-value" data-pattern-metric-value="qdrant_collection">initial-collection</p>
						</div>
						<div class="flavor-agent-sync-panel__metric" data-pattern-metric="embedding_dimension">
							<p class="flavor-agent-sync-panel__metric-value" data-pattern-metric-value="embedding_dimension">0</p>
						</div>
					</div>
					<div class="flavor-agent-sync-panel__actions">
						<button type="button" id="flavor-agent-sync-button">Sync Pattern Catalog</button>
						<span id="flavor-agent-sync-spinner" class="spinner" aria-hidden="true"></span>
						<span id="flavor-agent-sync-status" class="flavor-agent-sync-panel__status" aria-hidden="true"></span>
						<span id="flavor-agent-sync-live-region" class="screen-reader-text" aria-live="polite"></span>
					</div>
					<div id="flavor-agent-sync-notice" class="flavor-agent-sync-panel__notice" aria-live="polite"></div>
				</div>
			</details>
		</div>
	`;

	return document.querySelector( '.flavor-agent-settings' );
}

describe( 'settings page controller', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		window.flavorAgentAdmin = {
			nonce: 'test-nonce',
			restUrl: 'https://example.test/wp-json/',
		};
	} );

	afterEach( () => {
		delete window.flavorAgentAdmin;
		document.body.innerHTML = '';
	} );

	test( 'forced section overrides stored state and becomes the active accordion section', () => {
		const storage = createStorage( {
			'flavor-agent-settings-open-section': 'docs',
		} );
		const root = renderSettingsPage( {
			defaultSection: 'chat',
			forceSection: 'patterns',
		} );

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage,
		} );

		expect(
			root.querySelector( '[data-flavor-agent-section="patterns"]' ).open
		).toBe( true );
		expect(
			root.querySelector( '[data-flavor-agent-section="chat"]' ).open
		).toBe( false );
		expect(
			root.querySelector( '[data-flavor-agent-section="docs"]' ).open
		).toBe( false );
		expect( storage.getItem( 'flavor-agent-settings-open-section' ) ).toBe(
			'patterns'
		);
	} );

	test( 'opening another section closes the previous one and updates storage', () => {
		const storage = createStorage();
		const root = renderSettingsPage();

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage,
		} );

		const chatSection = root.querySelector(
			'[data-flavor-agent-section="chat"]'
		);
		const docsSection = root.querySelector(
			'[data-flavor-agent-section="docs"]'
		);

		expect( chatSection.open ).toBe( true );

		docsSection.open = true;
		docsSection.dispatchEvent( new Event( 'toggle' ) );

		expect( docsSection.open ).toBe( true );
		expect( chatSection.open ).toBe( false );
		expect( storage.getItem( 'flavor-agent-settings-open-section' ) ).toBe(
			'docs'
		);
	} );

	test( 'sync success updates the live panel state from runtimeState', async () => {
		const root = renderSettingsPage();
		const fetchImpl = jest.fn().mockResolvedValue( {
			ok: true,
			text: async () =>
				JSON.stringify( {
					indexed: 3,
					removed: 1,
					runtimeState: {
						status: 'ready',
						indexed_count: 12,
						last_synced_at: '2026-04-07T12:00:00Z',
						stale_reason: '',
						last_error: '',
						qdrant_collection: 'flavor-agent-patterns-test',
						embedding_dimension: 1536,
					},
					status: 'ready',
				} ),
		} );

		initializeSettingsPage( {
			root,
			fetchImpl,
			storage: createStorage(),
		} );

		root.querySelector( '#flavor-agent-sync-button' ).click();
		await flushPromises();
		await flushPromises();

		expect( fetchImpl ).toHaveBeenCalledWith(
			'https://example.test/wp-json/flavor-agent/v1/sync-patterns',
			expect.objectContaining( {
				method: 'POST',
			} )
		);
		expect(
			root.querySelector( '[data-pattern-status-badge="panel"]' )
				.textContent
		).toBe( 'Ready' );
		expect(
			root.querySelector( '[data-flavor-agent-status-badge="patterns"]' )
				.textContent
		).toBe( 'Ready' );
		expect(
			root.querySelector(
				'[data-pattern-overview-status="true"] .flavor-agent-settings__glance-value'
			).textContent
		).toBe( 'Ready' );
		expect(
			root.querySelector( '[data-pattern-metric-value="indexed_count"]' )
				.textContent
		).toBe( '12' );
		expect(
			root.querySelector( '[data-pattern-metric-value="last_synced_at"]' )
				.textContent
		).toBe( '2026-04-07T12:00:00Z' );
		expect(
			root.querySelector(
				'[data-pattern-metric-value="qdrant_collection"]'
			).textContent
		).toBe( 'flavor-agent-patterns-test' );
		expect(
			root.querySelector(
				'[data-pattern-metric-value="embedding_dimension"]'
			).textContent
		).toBe( '1536' );
		expect(
			root.querySelector( '#flavor-agent-sync-notice' ).textContent
		).toContain( 'Synced 3 patterns, removed 1. Status: Ready.' );
	} );

	test( 'sync failure keeps the panel open and surfaces the server error', async () => {
		const root = renderSettingsPage();
		const fetchImpl = jest.fn().mockResolvedValue( {
			ok: false,
			text: async () =>
				JSON.stringify( {
					message: 'Sync failed hard.',
				} ),
		} );

		initializeSettingsPage( {
			root,
			fetchImpl,
			storage: createStorage(),
		} );

		const syncPanel = root.querySelector(
			'[data-flavor-agent-sync-panel]'
		);

		syncPanel.open = false;
		root.querySelector( '#flavor-agent-sync-button' ).click();
		await flushPromises();
		await flushPromises();

		expect( syncPanel.open ).toBe( true );
		expect(
			root.querySelector( '#flavor-agent-sync-notice' ).textContent
		).toContain( 'Sync failed hard.' );
		expect(
			root.querySelector( '#flavor-agent-sync-live-region' ).textContent
		).toBe( 'Sync failed hard.' );
		expect(
			root.querySelector( '#flavor-agent-sync-status' ).textContent
		).toBe( '' );
	} );
} );
