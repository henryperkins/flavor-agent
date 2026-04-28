const { expect } = require( '@playwright/test' );

const READY_TEXT = 'WordPress is not ready yet';
const INTERNAL_SERVER_ERROR_TEXT = 'Internal Server Error';
const DEFAULT_TIMEOUT_MS = 90_000;
const RETRY_DELAY_MS = 2_000;
const NAVIGATION_TIMEOUT_MS = 10_000;

async function isTransientReadinessPage( page, readinessNotice ) {
	if ( await readinessNotice.count() ) {
		return true;
	}

	const bodyText = await page
		.locator( 'body' )
		.innerText( { timeout: 1_000 } )
		.catch( () => '' );

	return bodyText.trim() === INTERNAL_SERVER_ERROR_TEXT;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ timeout?: number }}            [options]
 * @return {Promise<void>}
 */
async function waitForWordPressReady(
	page,
	{ timeout = DEFAULT_TIMEOUT_MS } = {}
) {
	const readinessNotice = page.getByText( READY_TEXT );
	const targetUrl = page.url();
	const deadline = Date.now() + timeout;

	while ( Date.now() < deadline ) {
		if ( ! ( await isTransientReadinessPage( page, readinessNotice ) ) ) {
			return;
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			break;
		}

		await page.waitForTimeout( Math.min( RETRY_DELAY_MS, remaining ) );

		try {
			// Playground cold starts can abort same-document reloads while the proxy
			// is still serving temporary 502 readiness responses.
			await page.goto( targetUrl, {
				waitUntil: 'domcontentloaded',
				timeout: Math.min( NAVIGATION_TIMEOUT_MS, remaining ),
			} );
		} catch ( error ) {
			if ( Date.now() >= deadline ) {
				break;
			}
		}
	}

	await expect( readinessNotice ).toHaveCount( 0 );
	await expect( page.locator( 'body' ) ).not.toHaveText(
		INTERNAL_SERVER_ERROR_TEXT
	);
}

module.exports = {
	waitForWordPressReady,
};
