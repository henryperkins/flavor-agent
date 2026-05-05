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
		'Review model readiness, embeddings, patterns, developer docs, and guidance without leaving the current setup flow.'
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
		'Required'
	);
	await expect( chatSection.locator( sectionSummarySelector ) ).toContainText(
		'Text generation is configured in Settings > Connectors.'
	);
	await expect(
		embeddingSection.locator( sectionSummarySelector )
	).toContainText( 'Required' );
	await expect(
		embeddingSection.locator( sectionSummarySelector )
	).toContainText(
		'Configure one embedding model for Flavor Agent semantic features.'
	);
	await expect(
		patternSection.locator( sectionSummarySelector )
	).toContainText( 'Optional' );
	await expect(
		patternSection.locator( sectionSummarySelector )
	).toContainText(
		'Pattern recommendations use Pattern Storage plus the configured embedding model when needed.'
	);
	await expect( docsSection.locator( sectionSummarySelector ) ).toContainText(
		'Ready'
	);
	await expect( docsSection.locator( sectionSummarySelector ) ).toContainText(
		'Internal developer.wordpress.org grounding is already configured.'
	);
	await expect(
		guidelinesSection.locator( sectionSummarySelector )
	).toContainText( 'Optional' );
	await expect(
		guidelinesSection.locator( sectionSummarySelector )
	).toContainText(
		'Store plugin-owned site, writing, image, and block guidance.'
	);
	await expect(
		experimentsSection.locator( sectionSummarySelector )
	).toContainText( 'Optional' );

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
		"Developer docs use Flavor Agent's built-in public endpoint. No Cloudflare credentials are required."
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
		'AI Model shows the text-generation provider configured in Settings > Connectors.'
	);
	await expect( overviewPanel ).toContainText(
		'Embedding Model is configured once for Flavor Agent semantic features.'
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
		"Developer Docs uses Flavor Agent's built-in public developer.wordpress.org endpoint."
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
