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

function renderGuidelinesSettingsPage( {
	categories = {},
	blockGuidelines = {},
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
	document.body.innerHTML = `
		<div
			class="flavor-agent-settings"
			data-default-section="guidelines"
			data-force-section=""
			data-open-section-storage-key="flavor-agent-settings-open-section"
		>
			<div class="flavor-agent-guidelines" data-flavor-agent-guidelines-root>
				<div class="flavor-agent-guidelines__notice" data-guidelines-notice aria-live="polite"></div>
				<textarea id="flavor_agent_guideline_site">${ categories.site || '' }</textarea>
				<textarea id="flavor_agent_guideline_copy">${ categories.copy || '' }</textarea>
				<textarea id="flavor_agent_guideline_images">${ categories.images || '' }</textarea>
				<textarea id="flavor_agent_guideline_additional">${ categories.additional || '' }</textarea>
				<details class="flavor-agent-guidelines__blocks-panel">
					<div data-guidelines-block-list></div>
					<textarea data-guidelines-block-input hidden>${ JSON.stringify(
						blockGuidelines
					) }</textarea>
					<script type="application/json" data-guidelines-block-options>${ JSON.stringify(
						blockOptions
					) }</script>
					<select data-guidelines-block-select></select>
					<textarea data-guidelines-block-text></textarea>
					<button type="button" data-guidelines-block-cancel hidden>Cancel</button>
					<button type="button" data-guidelines-block-save>Add Block Guideline</button>
				</details>
				<button type="button" data-guidelines-import-button>Import JSON</button>
				<button type="button" data-guidelines-export-button>Export JSON</button>
				<input type="file" data-guidelines-file-input hidden />
			</div>
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
			const blockText = root.querySelector( '[data-guidelines-block-text]' );
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
			).toContain(
				'No block guidelines yet. Add one when a specific block needs extra rules.'
			);
		} finally {
			window.confirm = originalConfirm;
		}
	} );

	test( 'guidelines manager imports Gutenberg-compatible JSON into the form', async () => {
		const root = renderGuidelinesSettingsPage();

		initializeSettingsPage( {
			root,
			fetchImpl: jest.fn(),
			storage: createStorage(),
		} );

		const fileInput = root.querySelector( '[data-guidelines-file-input]' );
		const file = {
			text: jest.fn().mockResolvedValue(
				JSON.stringify( {
					guideline_categories: {
						site: {
							guidelines: 'Studio website for enterprise buyers.',
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
							'core/image': {
								guidelines: 'All images need meaningful alt text.',
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
			root.querySelector( '.flavor-agent-guidelines__blocks-panel' ).open
		).toBe( true );
		expect(
			root.querySelector( '[data-guidelines-notice]' ).textContent
		).toContain( 'Guidelines imported into the form. Save Changes to persist.' );
	} );

	test( 'guidelines manager exports the current form as Gutenberg-compatible JSON', async () => {
		const root = renderGuidelinesSettingsPage( {
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
			.spyOn( HTMLAnchorElement.prototype, 'click' )
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
		URL.createObjectURL = jest.fn( () => 'blob:flavor-agent-guidelines' );
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
							guidelines: 'Lead paragraphs should stay concise.',
						},
					},
				},
			} );
			expect( URL.revokeObjectURL ).toHaveBeenCalledWith(
				'blob:flavor-agent-guidelines'
			);
			expect(
				root.querySelector( '[data-guidelines-notice]' ).textContent
			).toContain( 'Guidelines exported.' );
		} finally {
			clickSpy.mockRestore();
			global.Blob = originalBlob;
			URL.createObjectURL = originalCreateObjectURL;
			URL.revokeObjectURL = originalRevokeObjectURL;
		}
	} );
} );
