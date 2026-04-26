const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );

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
		'Chat uses Settings > Connectors when available; direct Azure and OpenAI Native settings below remain as legacy fallback.'
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

	await chatSection.locator( sectionSummarySelector ).click();
	await expect( chatSection ).toHaveJSProperty( 'open', true );
	await expect( patternSection ).toHaveJSProperty( 'open', false );

	await docsSection.locator( sectionSummarySelector ).click();
	await expect( docsSection ).toHaveJSProperty( 'open', true );
	await expect( chatSection ).toHaveJSProperty( 'open', false );

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
	).toContainText( 'Guidelines import fills the form first.' );

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
