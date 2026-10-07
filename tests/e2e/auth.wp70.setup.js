const { test, expect } = require( '@playwright/test' );
const { getWp70HarnessConfig } = require( '../../scripts/wp70-e2e' );

test( 'authenticate the WP 7.1 Site Editor harness', async ( { page } ) => {
	const harness = getWp70HarnessConfig();

	await page.goto( '/wp-login.php', {
		// Load the login page before waiting for its deferred username autofocus.
		waitUntil: 'load',
	} );

	// WordPress schedules this focus after load; let it finish before either
	// fill so it cannot interrupt password entry and prevent form submission.
	await expect( page.locator( '#user_login' ) ).toBeFocused();
	await page.locator( '#user_login' ).fill( harness.adminUser );
	await page.locator( '#user_pass' ).fill( harness.adminPassword );
	await page.locator( '#wp-submit' ).click();

	await page.waitForURL( /\/wp-admin(?:\/|\/index\.php)?$/ );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	await page.context().storageState( {
		path: harness.storageStatePath,
	} );
} );
