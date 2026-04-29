const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const {
	getWp70HarnessConfig,
	runWpCli,
} = require( '../../scripts/wp70-e2e' );

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

test( 'settings page keeps compact help-first IA without changing accordion behavior', async ( {
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
		'Configure site-specific settings here. Use Help for setup reference and troubleshooting.'
	);
	await expect(
		page.locator( '.flavor-agent-settings__glance-item' )
	).toHaveCount( 4 );
	await expect( page.locator( '.flavor-agent-settings' ) ).not.toContainText(
		'Recent Activity'
	);
	await expect( page.locator( '.flavor-agent-settings' ) ).not.toContainText(
		'Optional second step for vector-based pattern recommendations.'
	);

	const chatSection = page.locator( '[data-flavor-agent-section="chat"]' );
	const patternSection = page.locator(
		'[data-flavor-agent-section="patterns"]'
	);
	const docsSection = page.locator( '[data-flavor-agent-section="docs"]' );
	const guidelinesSection = page.locator(
		'[data-flavor-agent-section="guidelines"]'
	);
	const sectionSummarySelector =
		':scope > .flavor-agent-settings-section__summary';

	await expect( chatSection.locator( sectionSummarySelector ) ).toContainText(
		'Required'
	);
	await expect( chatSection.locator( sectionSummarySelector ) ).toContainText(
		'Chat is handled by Settings > Connectors; this screen configures embeddings and supporting services.'
	);
	await expect(
		patternSection.locator( sectionSummarySelector )
	).toContainText( 'Optional' );
	await expect(
		patternSection.locator( sectionSummarySelector )
	).toContainText( 'Add vector search for pattern recommendations.' );
	await expect( docsSection.locator( sectionSummarySelector ) ).toContainText(
		'Optional'
	);
	await expect( docsSection.locator( sectionSummarySelector ) ).toContainText(
		'Ground responses with developer.wordpress.org docs.'
	);
	await expect(
		guidelinesSection.locator( sectionSummarySelector )
	).toContainText( 'Optional' );
	await expect(
		guidelinesSection.locator( sectionSummarySelector )
	).toContainText(
		'Store plugin-owned site, writing, image, and block guidance.'
	);

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

	const legacyOverridePanel = page
		.locator( '.flavor-agent-settings-subpanel' )
		.filter( {
			has: page.locator( 'summary', {
				hasText: 'Cloudflare Override',
			} ),
		} );

	await expect( legacyOverridePanel ).toContainText(
		'Older installs or explicit custom-endpoint overrides only. Leave these blank to use the built-in public docs endpoint.'
	);
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
		'Configure chat first. Settings > Connectors is the primary path and the only required section.'
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
		'Cloudflare override fields are only for older installs or explicit custom-endpoint use.'
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
	).toContainText( 'Guidelines import fills the legacy form first.' );

	await expect(
		page.locator( '#contextual-help-wrap .contextual-help-sidebar' )
	).toContainText( 'Quick Links' );
	await expect(
		page.locator( '#contextual-help-wrap .contextual-help-sidebar' )
	).toContainText( 'Open Connectors' );
	await expect(
		page.locator( '#contextual-help-wrap .contextual-help-sidebar' )
	).toContainText( 'Open Activity Log' );
} );

test( '@wp70-site-editor settings page saves, validates, and persists safe fields', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );

	runWpCli( wp70Harness, [
		'option',
		'delete',
		'flavor_agent_pattern_recommendation_threshold',
	], { allowFailure: true } );
	runWpCli( wp70Harness, [
		'option',
		'delete',
		'flavor_agent_pattern_max_recommendations',
	], { allowFailure: true } );
	runWpCli( wp70Harness, [
		'option',
		'delete',
		'flavor_agent_cloudflare_ai_search_max_results',
	], { allowFailure: true } );
	runWpCli( wp70Harness, [
		'option',
		'delete',
		'flavor_agent_guideline_site',
	], { allowFailure: true } );
	runWpCli( wp70Harness, [
		'option',
		'delete',
		'flavor_agent_guideline_copy',
	], { allowFailure: true } );

	await page.goto( '/wp-admin/options-general.php?page=flavor-agent', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );

	await page.locator( '.flavor-agent-settings-section__panel' ).evaluateAll(
		( sections ) => {
			sections.forEach( ( section ) => {
				section.open = true;
			} );
		}
	);

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

	await page.locator( 'form.flavor-agent-settings__form' ).evaluate(
		( form ) => {
			form.noValidate = true;
		}
	);
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await page.waitForLoadState( 'domcontentloaded' );
	await waitForWordPressReady( page );

	await expect(
		page.locator( '.flavor-agent-settings-save-summary' )
	).toContainText( 'Pattern settings saved.' );
	await expect(
		page.locator( '.flavor-agent-settings-save-summary' )
	).toContainText( 'Docs grounding settings saved.' );
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
	await page.locator( '.flavor-agent-settings-section__panel' ).evaluateAll(
		( sections ) => {
			sections.forEach( ( section ) => {
				section.open = true;
			} );
		}
	);

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
