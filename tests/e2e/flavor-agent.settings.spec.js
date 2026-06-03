const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const wp70Harness = getWp70HarnessConfig();

async function setSettingsFieldValue( page, selector, value ) {
	await page.locator( selector ).evaluate( ( element, nextValue ) => {
		element.value = nextValue;
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		element.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}, value );
}

async function getSettingsFieldValue( page, selector ) {
	return page.locator( selector ).evaluate( ( element ) => element.value );
}

test( '@wp70-site-editor settings page keeps compact help-first IA without changing accordion behavior', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/options-general.php?page=flavor-agent', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );

	await expect(
		page.getByRole( 'heading', { name: 'Flavor Agent Settings' } )
	).toBeVisible();
	await expect( page.locator( '.flavor-agent-admin-hero__copy' ) ).toHaveText(
		'Configure setup, storage, docs, and guidance.'
	);
	await expect(
		page.locator( '.flavor-agent-settings__glance-item' )
	).toHaveCount( 6 );
	await expect( page.locator( '.flavor-agent-settings' ) ).not.toContainText(
		'Recent Activity'
	);
	await expect( page.locator( '.flavor-agent-settings' ) ).not.toContainText(
		'Optional second step for vector-based pattern recommendations.'
	);

	const chatSection = page.locator( '[data-flavor-agent-section="chat"]' );
	const embeddingSection = page.locator(
		'[data-flavor-agent-section="embeddings"]'
	);
	const patternSection = page.locator(
		'[data-flavor-agent-section="patterns"]'
	);
	const docsSection = page.locator( '[data-flavor-agent-section="docs"]' );
	const guidelinesSection = page.locator(
		'[data-flavor-agent-section="guidelines"]'
	);
	const experimentsSection = page.locator(
		'[data-flavor-agent-section="experiments"]'
	);
	const sectionSummarySelector =
		':scope > .flavor-agent-settings-section__summary';

	await expect( chatSection.locator( sectionSummarySelector ) ).toContainText(
		'Text-generation provider status.'
	);
	await expect(
		embeddingSection.locator( sectionSummarySelector )
	).toContainText( 'Embedding credentials for semantic features.' );
	await expect(
		patternSection.locator( sectionSummarySelector )
	).toContainText( 'Storage and sync for pattern recommendations.' );
	await expect( docsSection.locator( sectionSummarySelector ) ).toContainText(
		'Built-in developer.wordpress.org grounding.'
	);
	await expect(
		guidelinesSection.locator( sectionSummarySelector )
	).toContainText( 'Site and block guidance.' );
	await expect(
		experimentsSection.locator( sectionSummarySelector )
	).toContainText( 'AI Activity logging controls.' );

	const chatInitiallyOpen = await chatSection.evaluate(
		( section ) => section.open
	);

	if ( chatInitiallyOpen ) {
		await docsSection.locator( sectionSummarySelector ).click();
		await expect( docsSection ).toHaveJSProperty( 'open', true );
		await expect( chatSection ).toHaveJSProperty( 'open', false );

		await chatSection.locator( sectionSummarySelector ).click();
		await expect( chatSection ).toHaveJSProperty( 'open', true );
		await expect( patternSection ).toHaveJSProperty( 'open', false );
	} else {
		await chatSection.locator( sectionSummarySelector ).click();
		await expect( chatSection ).toHaveJSProperty( 'open', true );
		await expect( patternSection ).toHaveJSProperty( 'open', false );

		await docsSection.locator( sectionSummarySelector ).click();
		await expect( docsSection ).toHaveJSProperty( 'open', true );
		await expect( chatSection ).toHaveJSProperty( 'open', false );
	}

	await expect( page.locator( '.flavor-agent-settings' ) ).not.toContainText(
		'Cloudflare Override'
	);
	await expect( docsSection ).toContainText(
		'Built-in developer.wordpress.org grounding is active.'
	);
	await expect( docsSection ).not.toContainText( 'Developer Docs Source' );
	await expect( docsSection ).not.toContainText(
		'Built-in public Cloudflare AI Search endpoint'
	);
	await expect( docsSection ).not.toContainText( 'Runtime Grounding' );
	await expect( docsSection ).not.toContainText( 'Developer Docs Prewarm' );
	await expect(
		page.locator( '.flavor-agent-guidelines__actions-panel' )
	).toContainText( 'Import fills the form. Save Changes to persist.' );

	const helpButton = page.locator( '#contextual-help-link' );

	await expect( helpButton ).toHaveText( 'Help' );
	await helpButton.click();
	await expect( page.locator( '#contextual-help-wrap' ) ).toBeVisible();

	const overviewPanel = page.locator( '#tab-panel-flavor-agent-overview' );

	await expect( overviewPanel ).toBeVisible();
	await expect( overviewPanel ).toContainText(
		'Use Connectors for text generation. Flavor Agent shows the active chat path here.'
	);

	await page
		.locator(
			'#contextual-help-wrap a[href="#tab-panel-flavor-agent-configuration"]'
		)
		.click();
	await expect(
		page.locator( '#tab-panel-flavor-agent-configuration' )
	).toBeVisible();
	await expect(
		page.locator( '#tab-panel-flavor-agent-configuration' )
	).toContainText(
		'Pattern Storage chooses where the pattern catalog is indexed.'
	);

	await page
		.locator(
			'#contextual-help-wrap a[href="#tab-panel-flavor-agent-troubleshooting"]'
		)
		.click();
	await expect(
		page.locator( '#tab-panel-flavor-agent-troubleshooting' )
	).toBeVisible();
	await expect(
		page.locator( '#tab-panel-flavor-agent-troubleshooting' )
	).toContainText(
		'Developer Docs use the built-in developer.wordpress.org grounding path.'
	);
	await expect(
		page.locator( '#tab-panel-flavor-agent-troubleshooting' )
	).toContainText(
		'When core Guidelines are available, Flavor Agent reads them first.'
	);
	await expect(
		page.locator( '#tab-panel-flavor-agent-troubleshooting' )
	).toContainText( 'AI Activity Dual Logging' );

	await expect(
		page.locator( '#contextual-help-wrap .contextual-help-sidebar' )
	).toContainText( 'Quick Links' );
	await expect(
		page.locator( '#contextual-help-wrap .contextual-help-sidebar' )
	).toContainText( 'Open Connectors' );
	await expect(
		page.locator( '#contextual-help-wrap .contextual-help-sidebar' )
	).toContainText( 'Open Activity Log' );

	await page.setViewportSize( { width: 390, height: 900 } );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await waitForWordPressReady( page );

	await expect(
		page.locator( '.flavor-agent-settings-section__summary' ).first()
	).toBeVisible();
	await expect
		.poll( async () =>
			page
				.locator( '.flavor-agent-settings-section__summary' )
				.evaluateAll( ( summaries ) =>
					summaries.map( ( summary ) => {
						const summaryBox = summary.getBoundingClientRect();
						const side = summary.querySelector(
							'.flavor-agent-settings-section__summary-side'
						);
						const sideBox = side?.getBoundingClientRect();
						const overflowingBadges = Array.from(
							summary.querySelectorAll(
								'.flavor-agent-settings-section__badge'
							)
						).filter( ( badge ) => {
							const box = badge.getBoundingClientRect();

							return (
								box.left < summaryBox.left - 1 ||
								box.right > summaryBox.right + 1 ||
								badge.scrollWidth > badge.clientWidth + 1
							);
						} );

						return {
							summaryText:
								summary
									.querySelector(
										'.flavor-agent-settings-section__title'
									)
									?.textContent?.trim() || '',
							sideFits:
								! sideBox ||
								( sideBox.left >= summaryBox.left - 1 &&
									sideBox.right <= summaryBox.right + 1 ),
							sideNoOverflow:
								! side ||
								side.scrollWidth <= side.clientWidth + 1,
							overflowingBadgeCount: overflowingBadges.length,
						};
					} )
				)
		)
		.toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					summaryText: '2. Embedding Model',
					sideFits: true,
					sideNoOverflow: true,
					overflowingBadgeCount: 0,
				} ),
			] )
		);
} );

test( '@wp70-site-editor settings page shows Developer Docs runtime diagnostics', async ( {
	page,
} ) => {
	runWpCli( wp70Harness, [
		'eval',
		`
update_option(
	'flavor_agent_docs_runtime_state',
	array(
		'status' => 'degraded',
		'lastSearchAt' => '2026-05-11 00:00:00',
		'lastSearchMode' => 'async',
		'lastResultCount' => 0,
		'lastTrustedSuccessAt' => '2026-04-08 09:45:00',
		'lastTrustedSuccessMode' => 'foreground',
		'lastServedAt' => '2026-04-08 09:50:00',
		'lastServedMode' => 'cache',
		'lastFallbackType' => 'generic',
		'lastSourceTypes' => array( 'developer-docs' ),
		'lastFreshness' => array( 'stale' ),
		'lastGroundingFingerprint' => 'abc123',
		'lastErrorAt' => '2026-05-11 00:00:00',
		'lastErrorMode' => 'async',
		'lastErrorCode' => 'http_request_failed',
		'lastErrorMessage' => 'Developer Docs grounding is missing current WordPress release-cycle sources.',
	),
	false
);
`,
	] );

	await page.goto( '/wp-admin/options-general.php?page=flavor-agent', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );

	const docsSection = page.locator( '[data-flavor-agent-section="docs"]' );
	const summary = docsSection.locator(
		':scope > .flavor-agent-settings-section__summary'
	);

	if ( ! ( await docsSection.evaluate( ( section ) => section.open ) ) ) {
		await summary.click();
	}

	await expect( docsSection ).toContainText(
		'Built-in developer.wordpress.org grounding is active.'
	);
	await expect( docsSection ).toContainText(
		'Developer Docs grounding is degraded.'
	);
	await expect( docsSection ).toContainText( 'developer-docs' );
	await expect( docsSection ).toContainText( 'stale' );
	await expect( docsSection ).toContainText(
		'Developer Docs grounding is missing current WordPress release-cycle sources.'
	);
	await expect( docsSection ).not.toContainText( 'Fingerprint:' );
	await expect( docsSection ).not.toContainText( 'abc123' );

	runWpCli(
		wp70Harness,
		[ 'option', 'delete', 'flavor_agent_docs_runtime_state' ],
		{ allowFailure: true }
	);
} );

test( '@wp70-site-editor settings page saves, validates, and persists safe fields', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );

	runWpCli(
		wp70Harness,
		[ 'option', 'delete', 'flavor_agent_pattern_recommendation_threshold' ],
		{ allowFailure: true }
	);
	runWpCli(
		wp70Harness,
		[ 'option', 'delete', 'flavor_agent_pattern_max_recommendations' ],
		{ allowFailure: true }
	);
	runWpCli(
		wp70Harness,
		[ 'option', 'delete', 'flavor_agent_cloudflare_ai_search_max_results' ],
		{ allowFailure: true }
	);
	runWpCli(
		wp70Harness,
		[ 'option', 'delete', 'flavor_agent_guideline_site' ],
		{ allowFailure: true }
	);
	runWpCli(
		wp70Harness,
		[ 'option', 'delete', 'flavor_agent_guideline_copy' ],
		{ allowFailure: true }
	);

	await page.goto( '/wp-admin/options-general.php?page=flavor-agent', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );

	await page
		.locator( '.flavor-agent-settings-section__panel' )
		.evaluateAll( ( sections ) => {
			sections.forEach( ( section ) => {
				section.open = true;
			} );
		} );

	await setSettingsFieldValue(
		page,
		'#flavor_agent_pattern_recommendation_threshold',
		'0.72'
	);
	await setSettingsFieldValue(
		page,
		'#flavor_agent_pattern_max_recommendations',
		'20'
	);
	await setSettingsFieldValue(
		page,
		'#flavor_agent_cloudflare_ai_search_max_results',
		'99'
	);
	await setSettingsFieldValue(
		page,
		'#flavor_agent_guideline_site',
		'A practical WordPress implementation site.'
	);
	await setSettingsFieldValue(
		page,
		'#flavor_agent_guideline_copy',
		'Use direct copy. Avoid inflated consultant language.'
	);

	await page
		.locator( 'form.flavor-agent-settings__form' )
		.evaluate( ( form ) => {
			form.noValidate = true;
		} );
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await page.waitForLoadState( 'domcontentloaded' );
	await waitForWordPressReady( page );

	await expect(
		page.locator( '.flavor-agent-settings-save-summary' )
	).toContainText( 'Pattern settings saved.' );
	await expect(
		page.locator( '.flavor-agent-settings-save-summary' )
	).toContainText( 'Developer docs settings saved.' );
	await expect(
		page.locator( '.flavor-agent-settings-save-summary' )
	).toContainText( 'Guidelines saved.' );

	const savedValues = runWpCli( wp70Harness, [
		'eval',
		`
echo wp_json_encode(
	array(
		'threshold' => get_option( 'flavor_agent_pattern_recommendation_threshold' ),
		'maxRecommendations' => get_option( 'flavor_agent_pattern_max_recommendations' ),
		'maxDocs' => get_option( 'flavor_agent_cloudflare_ai_search_max_results' ),
		'siteGuidelines' => get_option( 'flavor_agent_guideline_site' ),
		'copyGuidelines' => get_option( 'flavor_agent_guideline_copy' ),
	)
);
`,
	] );
	const values = JSON.parse( savedValues.stdout.trim() );

	expect( String( values.threshold ) ).toBe( '0.72' );
	expect( Number( values.maxRecommendations ) ).toBe( 12 );
	expect( Number( values.maxDocs ) ).toBe( 8 );
	expect( values.siteGuidelines ).toBe(
		'A practical WordPress implementation site.'
	);
	expect( values.copyGuidelines ).toBe(
		'Use direct copy. Avoid inflated consultant language.'
	);

	await page.reload( { waitUntil: 'domcontentloaded' } );
	await waitForWordPressReady( page );
	await page
		.locator( '.flavor-agent-settings-section__panel' )
		.evaluateAll( ( sections ) => {
			sections.forEach( ( section ) => {
				section.open = true;
			} );
		} );

	expect(
		await getSettingsFieldValue(
			page,
			'#flavor_agent_pattern_recommendation_threshold'
		)
	).toBe( '0.72' );
	expect(
		await getSettingsFieldValue(
			page,
			'#flavor_agent_pattern_max_recommendations'
		)
	).toBe( '12' );
	expect(
		await getSettingsFieldValue(
			page,
			'#flavor_agent_cloudflare_ai_search_max_results'
		)
	).toBe( '8' );
	expect(
		await getSettingsFieldValue( page, '#flavor_agent_guideline_site' )
	).toBe( 'A practical WordPress implementation site.' );
	expect(
		await getSettingsFieldValue( page, '#flavor_agent_guideline_copy' )
	).toBe( 'Use direct copy. Avoid inflated consultant language.' );
} );
