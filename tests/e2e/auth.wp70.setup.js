const { test, expect } = require( '@playwright/test' );
const { getWp70HarnessConfig } = require( '../../scripts/wp70-e2e' );

test( 'authenticate the WP 7.0 Site Editor harness', async ( { page } ) => {
	const harness = getWp70HarnessConfig();

	await page.goto( '/wp-login.php', {
		waitUntil: 'domcontentloaded',
	} );

	await page.locator( '#user_login' ).fill( harness.adminUser );
	await page.locator( '#user_pass' ).fill( harness.adminPassword );
	await page.locator( '#wp-submit' ).click();

	await page.waitForURL( /\/wp-admin(?:\/|\/index\.php)?$/ );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	await page.context().storageState( {
		path: harness.storageStatePath,
	} );
} );
