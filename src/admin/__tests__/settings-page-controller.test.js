jest.mock( '@wordpress/i18n', () =>
	require( '../../test-utils/i18n-mock' ).createI18nMock()
);

const fs = require( 'fs' );
const path = require( 'path' );

import * as i18n from '@wordpress/i18n';
import { initializeSettingsPage } from '../settings-page-controller';

const SETTINGS_CSS = fs.readFileSync(
	path.join( __dirname, '../settings.css' ),
	'utf8'
);
const TOKENS_CSS = fs.readFileSync(
	path.join( __dirname, '../../tokens.css' ),
	'utf8'
);

const CONFIRMED_CATALOG = {
	pattern_backend: 'cloudflare_ai_search',
	cloudflare_ai_search_namespace: 'mock-namespace',
	cloudflare_ai_search_instance: 'mock-index',
	cloudflare_ai_search_signature: 'mock-signature',
	last_synced_at: '2026-04-07T12:00:00Z',
};

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
	attentionSection = '',
	defaultSection = 'chat',
	forceSection = '',
	prerequisiteMessage = '',
	prerequisitesReady = '1',
	syncStatus = 'uninitialized',
	validationErrorSections = [],
} = {} ) {
	const sectionAttributes = ( section ) => {
		if ( ! validationErrorSections.includes( section ) ) {
			return '';
		}

		return ' open data-flavor-agent-validation-error="true"';
	};

	document.body.innerHTML = `
		<div
			class="flavor-agent-settings"
			data-attention-section="${ attentionSection }"
			data-default-section="${ defaultSection }"
			data-force-section="${ forceSection }"
			data-open-section-storage-key="flavor-agent-settings-open-section"
		>
			<section id="flavor-agent-section-chat">
				<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="chat"${ sectionAttributes(
					'chat'
				) }>
					<summary>Chat</summary>
				</details>
			</section>
			<section id="flavor-agent-section-patterns">
				<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="patterns"${ sectionAttributes(
					'patterns'
				) }>
					<summary>Patterns</summary>
				</details>
			</section>
			<section id="flavor-agent-section-docs">
				<details class="flavor-agent-settings-section__panel" data-flavor-agent-section="docs"${ sectionAttributes(
					'docs'
				) }>
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
						data-pattern-sync-status="${ syncStatus }"

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

function renderGuidelinesSettingsPage( {
	categories = {},
	blockGuidelines = {},
	fallbackInactive = false,
	blockOptions = [
		{
			value: 'core/image',
			label: 'Image',
		},
		{
			value: 'core/paragraph',
			label: 'Paragraph',
		},
	],
} = {} ) {
	window.flavorAgentAdmin = {
		...( window.flavorAgentAdmin || {} ),
		guidelinesBlockOptions: blockOptions,
	};

	document.body.innerHTML = `
		<div
			class="flavor-agent-settings"
			data-default-section="guidelines"
			data-force-section=""
			data-open-section-storage-key="flavor-agent-settings-open-section"
		>
			${ fallbackInactive ? '<details data-guidelines-fallback>' : '' }
			<div class="flavor-agent-guidelines" data-flavor-agent-guidelines-root>
				<div class="flavor-agent-guidelines__notice" data-guidelines-notice aria-live="polite"></div>
				<textarea id="flavor_agent_guideline_site">${ categories.site || '' }</textarea>
				<textarea id="flavor_agent_guideline_copy">${ categories.copy || '' }</textarea>
				<textarea id="flavor_agent_guideline_images">${
					categories.images || ''
				}</textarea>
				<textarea id="flavor_agent_guideline_additional">${
					categories.additional || ''
				}</textarea>
				<details class="flavor-agent-guidelines__blocks-panel">
					<div data-guidelines-block-list></div>
					<textarea data-guidelines-block-input hidden>${ JSON.stringify(
						blockGuidelines
					) }</textarea>
					<select data-guidelines-block-select></select>
					<textarea data-guidelines-block-text></textarea>
					<button type="button" data-guidelines-block-cancel hidden>Cancel</button>
					<button type="button" data-guidelines-block-save>Add Block Guideline</button>
				</details>
				<button type="button" data-guidelines-import-button>Import JSON</button>
				<button type="button" data-guidelines-export-button>Export JSON</button>
				<input type="file" data-guidelines-file-input hidden />
			</div>
			${ fallbackInactive ? '</details>' : '' }
		</div>
	`;

	return document.querySelector( '.flavor-agent-settings' );
}

describe( 'settings page controller', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		window.history.replaceState( null, '', '/' );
		document.body.innerHTML = '';
		window.flavorAgentAdmin = {
			nonce: 'test-nonce',
			restUrl: 'https://example.test/wp-json/',
		};
	} );

	afterEach( () => {
		delete window.flavorAgentAdmin;
		document.body.innerHTML = '';
		window.history.replaceState( null, '', '/' );
	} );

	test( 'declares tone styles for server-rendered settings status blocks', () => {
		expect( SETTINGS_CSS ).toContain( '.flavor-agent-settings-status' );
		expect( SETTINGS_CSS ).toContain(
			'.flavor-agent-settings-status--warning'
		);
		expect( SETTINGS_CSS ).toContain(
			'.flavor-agent-settings-status--error'
		);
		expect( SETTINGS_CSS ).toContain(
			'.flavor-agent-settings-status--success'
		);
		expect( SETTINGS_CSS ).toContain(
			'.flavor-agent-settings-status--accent'
		);
	} );

	test( 'settings status borders use shared admin status tokens', () => {
		expect( SETTINGS_CSS ).toContain(
			'--flavor-agent-settings-status-border-accent'
		);
		expect( SETTINGS_CSS ).toContain(
			'--flavor-agent-settings-status-border-success'
		);
		expect( SETTINGS_CSS ).toContain(
			'--flavor-agent-settings-status-border-warning'
		);
		expect( SETTINGS_CSS ).toContain(
			'--flavor-agent-settings-status-border-error'
		);
		const toneBorderRules =
			SETTINGS_CSS.match(
				/\.flavor-agent-settings(?:-status|__glance-item)--(?:accent|success|warning|error)\s*\{[^}]*\}/g
			) || [];
		expect( toneBorderRules.join( '\n' ) ).not.toContain(
			'border-color: rgba('
		);
	} );

	test( 'settings accent borders use shared brand tokens instead of raw rgba literals', () => {
		expect( SETTINGS_CSS ).not.toMatch( /rgba\(11,\s*123,\s*128/i );
		expect( TOKENS_CSS ).toContain(
			'--flavor-agent-color-accent-line-strong'
		);
	} );

	test( 'does not expose the dormant low-contrast subtle text token', () => {
		expect( TOKENS_CSS ).not.toContain( '--flavor-agent-color-subtle' );
	} );

	test( 'shared reduced-motion reset covers wp-admin roots', () => {
		expect( TOKENS_CSS ).toMatch(
			/@media\s*\(prefers-reduced-motion:\s*reduce\)[\s\S]*\.flavor-agent-settings \*/s
		);
		expect( TOKENS_CSS ).toMatch(
			/@media\s*\(prefers-reduced-motion:\s*reduce\)[\s\S]*\.flavor-agent-settings \*::before/s
		);
		expect( TOKENS_CSS ).toMatch(
			/@media\s*\(prefers-reduced-motion:\s*reduce\)[\s\S]*\.flavor-agent-activity-log \*/s
		);
		expect( TOKENS_CSS ).toMatch(
			/@media\s*\(prefers-reduced-motion:\s*reduce\)[\s\S]*\.flavor-agent-activity-log \*::after/s
		);
	} );

	test( 'runtime attention section overrides stored state and becomes the active accordion section', () => {
		const storage = createStorage( {
			'flavor-agent-settings-open-section': 'docs',
		} );
		const root = renderSettingsPage( {
			attentionSection: 'patterns',
			defaultSection: 'patterns',
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

	test( 'preserves every server-opened validation error section on load', () => {
		const storage = createStorage( {
			'flavor-agent-settings-open-section': 'docs',
		} );
		const root = renderSettingsPage( {
			defaultSection: 'chat',
			forceSection: 'chat',
			validationErrorSections: [ 'chat', 'patterns' ],
		} );

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage,
		} );

		expect(
			root.querySelector( '[data-flavor-agent-section="chat"]' ).open
		).toBe( true );
		expect(
			root.querySelector( '[data-flavor-agent-section="patterns"]' ).open
		).toBe( true );
		expect(
			root.querySelector( '[data-flavor-agent-section="docs"]' ).open
		).toBe( false );
		expect( storage.getItem( 'flavor-agent-settings-open-section' ) ).toBe(
			'chat'
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

	test( 'a section hash opens and focuses its summary while preserving validation errors', () => {
		window.history.replaceState( null, '', '#flavor-agent-section-docs' );
		const root = renderSettingsPage( {
			forceSection: 'patterns',
			validationErrorSections: [ 'patterns' ],
		} );
		const storage = createStorage();

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage,
		} );

		const docs = root.querySelector( '[data-flavor-agent-section="docs"]' );
		expect( docs.open ).toBe( true );
		expect( document.activeElement ).toBe(
			docs.querySelector( 'summary' )
		);
		expect(
			root.querySelector( '[data-flavor-agent-section="patterns"]' ).open
		).toBe( true );
		expect( storage.getItem( 'flavor-agent-settings-open-section' ) ).toBe(
			'docs'
		);
	} );

	test( 'status link activation opens and focuses the destination even when its hash is unchanged', () => {
		const root = renderSettingsPage();
		const link = document.createElement( 'a' );
		link.href = '#flavor-agent-section-docs';
		link.textContent = 'Docs status';
		root.prepend( link );
		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );

		const docs = root.querySelector( '[data-flavor-agent-section="docs"]' );
		link.focus();
		link.click();
		expect( docs.open ).toBe( true );
		expect( window.location.hash ).toBe( '#flavor-agent-section-docs' );
		expect( document.activeElement ).toBe(
			docs.querySelector( 'summary' )
		);

		docs.open = false;
		link.focus();
		link.click();
		expect( docs.open ).toBe( true );
		expect( document.activeElement ).toBe(
			docs.querySelector( 'summary' )
		);
	} );

	test( 'hash navigation expands the matching section without intercepting unrelated links', () => {
		const root = renderSettingsPage();
		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );
		window.history.replaceState(
			null,
			'',
			'#flavor-agent-section-patterns'
		);
		window.dispatchEvent( new Event( 'hashchange' ) );
		const patterns = root.querySelector(
			'[data-flavor-agent-section="patterns"]'
		);
		expect( patterns.open ).toBe( true );
		expect( document.activeElement ).toBe(
			patterns.querySelector( 'summary' )
		);

		const otherScreen = document.createElement( 'a' );
		otherScreen.href = '/other-screen#flavor-agent-section-docs';
		root.appendChild( otherScreen );
		const click = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
			button: 0,
		} );
		let intercepted;
		root.addEventListener(
			'click',
			( event ) => {
				intercepted = event.defaultPrevented;
				event.preventDefault();
			},
			{ once: true }
		);
		otherScreen.dispatchEvent( click );
		expect( intercepted ).toBe( false );
		expect( patterns.open ).toBe( true );
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
						...CONFIRMED_CATALOG,
						status: 'ready',
						indexed_count: 12,
						last_synced_at: '2026-04-07T12:00:00Z',
						stale_reason: '',
						last_error: '',
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
			root.querySelector( '#flavor-agent-sync-notice' ).textContent
		).toContain( 'Synced 3 patterns, removed 1. Status: Ready.' );
		expect( i18n.sprintf ).toHaveBeenCalled();
	} );

	test( 'queued sync polls until the live panel leaves indexing', async () => {
		const root = renderSettingsPage();
		const fetchImpl = jest
			.fn()
			.mockResolvedValueOnce( {
				ok: true,
				text: async () =>
					JSON.stringify( {
						queued: true,
						scheduled: true,
						runtimeState: {
							status: 'indexing',
							indexed_count: 0,
							last_synced_at: null,
						},
						status: 'indexing',
					} ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				text: async () =>
					JSON.stringify( {
						runtimeState: {
							...CONFIRMED_CATALOG,
							status: 'ready',
							indexed_count: 12,
							last_synced_at: '2026-04-07T12:00:00Z',
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

		expect( fetchImpl ).toHaveBeenNthCalledWith(
			1,
			'https://example.test/wp-json/flavor-agent/v1/sync-patterns',
			expect.objectContaining( {
				method: 'POST',
			} )
		);
		expect( fetchImpl ).toHaveBeenNthCalledWith(
			2,
			'https://example.test/wp-json/flavor-agent/v1/sync-patterns',
			expect.objectContaining( {
				method: 'GET',
			} )
		);
		expect(
			root.querySelector( '[data-pattern-status-badge="panel"]' )
				.textContent
		).toBe( 'Ready' );
		expect(
			root.querySelector( '#flavor-agent-sync-notice' ).textContent
		).toContain( 'Pattern catalog status updated: Ready.' );
		expect(
			root.querySelector( '#flavor-agent-sync-button' ).disabled
		).toBe( false );
		expect(
			root.querySelector( '.flavor-agent-sync-panel' ).dataset
				.patternSyncStatus
		).toBe( 'ready' );
	} );

	test( 'queued sync keeps the button disabled and ignores another click while polling', async () => {
		const root = renderSettingsPage();
		const fetchImpl = jest
			.fn()
			.mockResolvedValueOnce( {
				ok: true,
				text: async () =>
					JSON.stringify( {
						queued: true,
						scheduled: true,
						runtimeState: {
							status: 'indexing',
							indexed_count: 0,
							last_synced_at: null,
						},
						status: 'indexing',
					} ),
			} )
			.mockImplementationOnce(
				() =>
					new Promise( () => {
						// Keep the GET poll open so the control stays in the polling state.
					} )
			);

		initializeSettingsPage( {
			root,
			fetchImpl,
			storage: createStorage(),
		} );

		const button = root.querySelector( '#flavor-agent-sync-button' );

		button.click();
		await flushPromises();
		await flushPromises();

		expect( button.disabled ).toBe( true );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );

		button.click();

		expect(
			fetchImpl.mock.calls.filter(
				( [ , options ] ) => options?.method === 'POST'
			)
		).toHaveLength( 1 );
	} );

	test( 'sync failure keeps the panel open and surfaces the server error', async () => {
		const root = renderSettingsPage();
		const fetchImpl = jest.fn().mockResolvedValue( {
			ok: false,
			status: 500,
			text: async () =>
				JSON.stringify( {
					code: 'pattern_sync_schedule_failed',
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
		expect(
			root.querySelector( '#flavor-agent-sync-button' ).disabled
		).toBe( false );
		expect(
			root.querySelector( '.flavor-agent-sync-panel' ).dataset
				.patternSyncStatus
		).toBe( 'rejected' );
	} );

	test( 'disabled sync button references the prerequisite guidance', () => {
		const root = renderSettingsPage( {
			prerequisitesReady: '0',
			prerequisiteMessage: 'Add Pattern Storage before syncing.',
		} );

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );

		const button = root.querySelector( '#flavor-agent-sync-button' );
		const describedBy = button.getAttribute( 'aria-describedby' );

		expect( button.disabled ).toBe( true );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( describedBy ).toBeTruthy();
		expect( root.querySelector( `#${ describedBy }` ).textContent ).toBe(
			'Add Pattern Storage before syncing.'
		);
	} );

	test( 'disabled sync button can reference missing embedding model guidance', () => {
		const root = renderSettingsPage( {
			prerequisitesReady: '0',
			prerequisiteMessage: 'Complete Embedding Model before syncing.',
		} );

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );

		const button = root.querySelector( '#flavor-agent-sync-button' );
		const describedBy = button.getAttribute( 'aria-describedby' );

		expect( button.disabled ).toBe( true );
		expect( root.querySelector( `#${ describedBy }` ).textContent ).toBe(
			'Complete Embedding Model before syncing.'
		);
	} );

	test( 'sync button remains enabled when pattern prerequisites are ready', () => {
		const root = renderSettingsPage( {
			prerequisitesReady: '1',
			prerequisiteMessage: '',
		} );

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );

		const button = root.querySelector( '#flavor-agent-sync-button' );

		expect( button.disabled ).toBe( false );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'false' );
		expect( button.hasAttribute( 'aria-describedby' ) ).toBe( false );
	} );

	test( 'resumes an existing sync on page load and unlocks the completed catalog', async () => {
		const root = renderSettingsPage( { syncStatus: 'indexing' } );
		const fetchImpl = jest.fn().mockResolvedValue( {
			ok: true,
			text: async () =>
				JSON.stringify( {
					runtimeState: {
						...CONFIRMED_CATALOG,
						status: 'ready',
						indexed_count: 12,
					},
				} ),
		} );

		initializeSettingsPage( { root, fetchImpl, storage: createStorage() } );
		await flushPromises();

		expect(
			root.querySelector( '.flavor-agent-sync-panel' ).dataset
				.patternSyncStatus
		).toBe( 'ready' );
		expect(
			root.querySelector( '#flavor-agent-sync-button' ).disabled
		).toBe( false );
		expect( fetchImpl ).toHaveBeenCalledWith(
			'https://example.test/wp-json/flavor-agent/v1/sync-patterns',
			expect.objectContaining( { method: 'GET' } )
		);
	} );

	test( 'sync button remains disabled when the initial runtime status is indexing', () => {
		const root = renderSettingsPage( {
			prerequisitesReady: '1',
			prerequisiteMessage: '',
			syncStatus: 'indexing',
		} );

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn( () => new Promise( () => {} ) ),
			storage: createStorage(),
		} );

		const button = root.querySelector( '#flavor-agent-sync-button' );

		expect( button.disabled ).toBe( true );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( button.getAttribute( 'aria-describedby' ) ).toBe(
			'flavor-agent-sync-summary'
		);
	} );

	test( 'block guideline validation identifies and focuses the invalid field, preserving helper associations', () => {
		const root = renderGuidelinesSettingsPage();
		const blockSelect = root.querySelector(
			'[data-guidelines-block-select]'
		);
		const blockText = root.querySelector( '[data-guidelines-block-text]' );
		const helper = document.createElement( 'p' );
		helper.id = 'block-select-helper';
		helper.textContent = 'Select the block to guide.';
		blockSelect.insertAdjacentElement( 'beforebegin', helper );
		blockSelect.setAttribute( 'aria-describedby', helper.id );
		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );

		const save = root.querySelector( '[data-guidelines-block-save]' );
		save.focus();
		save.click();
		const selectError = root.querySelector(
			'#flavor-agent-block-select-error'
		);
		expect( document.activeElement ).toBe( blockSelect );
		expect( blockSelect.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( blockSelect.getAttribute( 'aria-describedby' ) ).toBe(
			'block-select-helper flavor-agent-block-select-error'
		);
		expect( selectError.hidden ).toBe( false );
		expect( selectError.textContent ).toBe(
			'Choose a block before saving a block guideline.'
		);
		expect(
			root.querySelector( '.flavor-agent-guidelines__blocks-panel' ).open
		).toBe( true );
		expect(
			root.querySelector( '[data-guidelines-notice]' ).textContent
		).toBe( selectError.textContent );

		blockSelect.value = 'core/paragraph';
		blockSelect.dispatchEvent( new Event( 'change' ) );
		expect( blockSelect.hasAttribute( 'aria-invalid' ) ).toBe( false );
		expect( blockSelect.getAttribute( 'aria-describedby' ) ).toBe(
			helper.id
		);
		expect( selectError.hidden ).toBe( true );
		expect(
			root.querySelector( '[data-guidelines-notice]' ).textContent
		).toBe( '' );
		save.click();
		const textError = root.querySelector(
			'#flavor-agent-block-text-error'
		);
		expect( document.activeElement ).toBe( blockText );
		expect( blockText.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( blockText.getAttribute( 'aria-describedby' ) ).toBe(
			textError.id
		);
		expect( textError.hidden ).toBe( false );

		blockText.value = 'Use short paragraphs.';
		blockText.dispatchEvent( new Event( 'input' ) );
		expect( blockText.hasAttribute( 'aria-invalid' ) ).toBe( false );
		expect( blockText.hasAttribute( 'aria-describedby' ) ).toBe( false );
		expect( textError.hidden ).toBe( true );
		expect(
			root.querySelector( '[data-guidelines-notice]' ).textContent
		).toBe( '' );
		save.click();
		expect( document.activeElement ).toBe( blockSelect );
	} );

	test( 'hidden Cancel remains absent under wp-admin button styles and focus returns to Block after cancellation', () => {
		const root = renderGuidelinesSettingsPage( {
			blockGuidelines: { 'core/image': 'Include alternative text.' },
		} );
		root.classList.add( 'wp-core-ui' );
		const style = document.createElement( 'style' );
		style.textContent = `.wp-core-ui .button { display: inline-block; }\n${ SETTINGS_CSS }`;
		root.prepend( style );
		const cancel = root.querySelector( '[data-guidelines-block-cancel]' );
		cancel.classList.add( 'button' );
		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );
		expect( cancel.hidden ).toBe( true );
		expect( window.getComputedStyle( cancel ).display ).toBe( 'none' );
		root.querySelector(
			'.flavor-agent-guidelines__item-actions .button-secondary'
		).click();
		expect( cancel.hidden ).toBe( false );
		expect( window.getComputedStyle( cancel ).display ).toBe(
			'inline-block'
		);
		cancel.focus();
		cancel.click();
		expect( window.getComputedStyle( cancel ).display ).toBe( 'none' );
		expect( document.activeElement ).toBe(
			root.querySelector( '[data-guidelines-block-select]' )
		);
	} );

	test( 'block actions identify their target and removal moves focus to the next, previous, or add control', () => {
		const root = renderGuidelinesSettingsPage( {
			blockGuidelines: {
				'core/image': 'Include alternative text.',
				'core/paragraph': 'Keep paragraphs concise.',
				'core/quote': 'Attribute quoted speech.',
			},
			blockOptions: [
				{ value: 'core/image', label: 'Image' },
				{ value: 'core/paragraph', label: 'Paragraph' },
				{ value: 'core/quote', label: 'Quote' },
			],
		} );
		const confirm = jest.spyOn( window, 'confirm' ).mockReturnValue( true );
		try {
			initializeSettingsPage( {
				root,
				fetchImpl: jest.fn(),
				storage: createStorage(),
			} );
			const remove = ( name ) =>
				Array.from(
					root.querySelectorAll( '[data-guidelines-block-remove]' )
				).find(
					( button ) => button.dataset.guidelinesBlockRemove === name
				);
			expect(
				root
					.querySelector(
						'.flavor-agent-guidelines__item-actions .button-secondary'
					)
					.getAttribute( 'aria-label' )
			).toBe( 'Edit guideline for Image' );
			expect( remove( 'core/image' ).getAttribute( 'aria-label' ) ).toBe(
				'Remove guideline for Image'
			);
			remove( 'core/paragraph' ).focus();
			remove( 'core/paragraph' ).click();
			expect( document.activeElement ).toBe( remove( 'core/quote' ) );
			remove( 'core/quote' ).click();
			expect( document.activeElement ).toBe( remove( 'core/image' ) );
			remove( 'core/image' ).click();
			expect( document.activeElement ).toBe(
				root.querySelector( '[data-guidelines-block-select]' )
			);
		} finally {
			confirm.mockRestore();
		}
	} );

	test( 'inactive fallback validation opens its ancestors and successful mutations name the inactive destination', () => {
		const root = renderGuidelinesSettingsPage( { fallbackInactive: true } );
		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );
		const fallback = root.querySelector( '[data-guidelines-fallback]' );
		const select = root.querySelector( '[data-guidelines-block-select]' );
		const text = root.querySelector( '[data-guidelines-block-text]' );
		const save = root.querySelector( '[data-guidelines-block-save]' );
		expect( fallback.open ).toBe( false );
		save.click();
		expect( fallback.open ).toBe( true );
		expect( document.activeElement ).toBe( select );
		select.value = 'core/image';
		text.value = 'Provide alternative text.';
		save.click();
		expect(
			root.querySelector( '[data-guidelines-notice]' ).textContent
		).toBe(
			'Fallback block guideline ready. Save Changes to persist the inactive fallback.'
		);
		const confirm = jest.spyOn( window, 'confirm' ).mockReturnValue( true );
		try {
			root.querySelector( '[data-guidelines-block-remove]' ).click();
			expect(
				root.querySelector( '[data-guidelines-notice]' ).textContent
			).toBe(
				'Fallback block guideline removed. Save Changes to persist the inactive fallback.'
			);
		} finally {
			confirm.mockRestore();
		}
	} );

	test( 'guidelines manager adds, edits, and removes block guidelines in the hidden field', () => {
		const root = renderGuidelinesSettingsPage();
		const originalConfirm = window.confirm;

		window.confirm = jest.fn( () => true );

		try {
			initializeSettingsPage( {
				root,
				fetchImpl: jest.fn(),
				storage: createStorage(),
			} );

			const blockSelect = root.querySelector(
				'[data-guidelines-block-select]'
			);
			const blockText = root.querySelector(
				'[data-guidelines-block-text]'
			);
			const hiddenInput = root.querySelector(
				'[data-guidelines-block-input]'
			);

			blockSelect.value = 'core/paragraph';
			blockText.value = 'Keep paragraphs under three sentences.';
			root.querySelector( '[data-guidelines-block-save]' ).click();

			expect( JSON.parse( hiddenInput.value ) ).toEqual( {
				'core/paragraph': 'Keep paragraphs under three sentences.',
			} );
			expect(
				root.querySelector( '.flavor-agent-guidelines__item-title' )
					.textContent
			).toBe( 'Paragraph' );

			root.querySelector(
				'.flavor-agent-guidelines__item-actions .button.button-secondary'
			).click();
			expect( blockSelect.disabled ).toBe( true );
			blockText.value = 'Keep paragraphs under two sentences.';
			root.querySelector( '[data-guidelines-block-save]' ).click();

			expect( JSON.parse( hiddenInput.value ) ).toEqual( {
				'core/paragraph': 'Keep paragraphs under two sentences.',
			} );

			root.querySelector( '.button.button-link-delete' ).click();

			expect( window.confirm ).toHaveBeenCalledWith(
				'Remove the block guideline for Paragraph?'
			);
			expect( JSON.parse( hiddenInput.value ) ).toEqual( {} );
			expect(
				root.querySelector( '[data-guidelines-block-list]' ).textContent
			).toContain( 'No block guidelines yet.' );
			expect( i18n.__ ).toHaveBeenCalledWith(
				'No block guidelines yet.',
				'flavor-agent'
			);
			expect( i18n.sprintf ).toHaveBeenCalled();
		} finally {
			window.confirm = originalConfirm;
		}
	} );

	test.each( [ false, true ] )(
		'guidelines manager imports JSON and identifies whether the fallback is inactive (%s)',
		async ( fallbackInactive ) => {
			const root = renderGuidelinesSettingsPage( { fallbackInactive } );

			initializeSettingsPage( {
				root,
				fetchImpl: jest.fn(),
				storage: createStorage(),
			} );

			const fileInput = root.querySelector(
				'[data-guidelines-file-input]'
			);
			const file = {
				text: jest.fn().mockResolvedValue(
					JSON.stringify( {
						guideline_categories: {
							site: {
								guidelines:
									'Studio website for enterprise buyers.',
							},
							copy: {
								guidelines: 'Use direct, plain language.',
							},
							images: {
								guidelines: 'Prefer documentary photography.',
							},
							additional: {
								guidelines: 'Avoid mentioning discounts.',
							},
							blocks: {
								'Core/Image': {
									guidelines:
										'All images need meaningful alt text.',
								},
								'invalid block': {
									guidelines: 'Ignore this entry.',
								},
							},
						},
					} )
				),
			};

			Object.defineProperty( fileInput, 'files', {
				value: [ file ],
				configurable: true,
			} );
			fileInput.dispatchEvent( new Event( 'change' ) );

			await flushPromises();
			await flushPromises();

			expect(
				root.querySelector( '#flavor_agent_guideline_site' ).value
			).toBe( 'Studio website for enterprise buyers.' );
			expect(
				root.querySelector( '#flavor_agent_guideline_copy' ).value
			).toBe( 'Use direct, plain language.' );
			expect(
				root.querySelector( '#flavor_agent_guideline_images' ).value
			).toBe( 'Prefer documentary photography.' );
			expect(
				root.querySelector( '#flavor_agent_guideline_additional' ).value
			).toBe( 'Avoid mentioning discounts.' );
			expect(
				JSON.parse(
					root.querySelector( '[data-guidelines-block-input]' ).value
				)
			).toEqual( {
				'core/image': 'All images need meaningful alt text.',
			} );
			expect(
				root.querySelector( '.flavor-agent-guidelines__blocks-panel' )
					.open
			).toBe( true );
			expect(
				root.querySelector( '[data-guidelines-notice]' ).textContent
			).toContain(
				fallbackInactive
					? 'Fallback guidelines imported into the form. Save Changes to persist the inactive fallback.'
					: 'Guidelines imported into the form. Save Changes to persist.'
			);
			expect( document.activeElement ).toBe(
				root.querySelector( '[data-guidelines-import-button]' )
			);
		}
	);

	test.each( [ false, true ] )(
		'guidelines manager exports the current form and identifies whether the fallback is inactive (%s)',
		async ( fallbackInactive ) => {
			const root = renderGuidelinesSettingsPage( {
				fallbackInactive,
				categories: {
					site: 'Publisher site',
					copy: 'Use confident editorial language.',
					images: 'Favor authentic reporting images.',
					additional: 'Do not mention subscriber counts.',
				},
				blockGuidelines: {
					'core/paragraph': 'Lead paragraphs should stay concise.',
				},
			} );
			const clickSpy = jest
				.spyOn( window.HTMLAnchorElement.prototype, 'click' )
				.mockImplementation( () => {} );
			const originalBlob = global.Blob;
			const originalCreateObjectURL = URL.createObjectURL;
			const originalRevokeObjectURL = URL.revokeObjectURL;

			global.Blob = jest
				.fn()
				.mockImplementation( ( parts, options = {} ) => ( {
					parts,
					type: options.type,
				} ) );
			URL.createObjectURL = jest.fn(
				() => 'blob:flavor-agent-guidelines'
			);
			URL.revokeObjectURL = jest.fn();

			try {
				initializeSettingsPage( {
					root,
					fetchImpl: jest.fn(),
					storage: createStorage(),
				} );

				root.querySelector( '[data-guidelines-export-button]' ).click();

				expect( URL.createObjectURL ).toHaveBeenCalledTimes( 1 );

				const [ blob ] = URL.createObjectURL.mock.calls[ 0 ];
				const payload = JSON.parse( blob.parts.join( '' ) );

				expect( payload ).toEqual( {
					guideline_categories: {
						site: {
							guidelines: 'Publisher site',
						},
						copy: {
							guidelines: 'Use confident editorial language.',
						},
						images: {
							guidelines: 'Favor authentic reporting images.',
						},
						additional: {
							guidelines: 'Do not mention subscriber counts.',
						},
						blocks: {
							'core/paragraph': {
								guidelines:
									'Lead paragraphs should stay concise.',
							},
						},
					},
				} );
				expect( URL.revokeObjectURL ).toHaveBeenCalledWith(
					'blob:flavor-agent-guidelines'
				);
				expect(
					root.querySelector( '[data-guidelines-notice]' ).textContent
				).toContain(
					fallbackInactive
						? 'Fallback guidelines exported.'
						: 'Guidelines exported.'
				);
			} finally {
				clickSpy.mockRestore();
				global.Blob = originalBlob;
				URL.createObjectURL = originalCreateObjectURL;
				URL.revokeObjectURL = originalRevokeObjectURL;
			}
		}
	);
} );
