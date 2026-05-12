const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );

const DOCS_WARNING_TEXT =
	'Developer Docs grounding is trusted, but current release-cycle sources have not been confirmed. Review current WordPress docs before applying.';

function recommendationAbilityRoute( abilitySlug ) {
	return new RegExp( `${ abilitySlug }(?:/|\\?|$)` );
}

function getAbilityRequestInput( body = {} ) {
	return body &&
		typeof body === 'object' &&
		! Array.isArray( body ) &&
		body.input &&
		typeof body.input === 'object' &&
		! Array.isArray( body.input )
		? body.input
		: body;
}

async function waitForFlavorAgent( page ) {
	await page.waitForFunction(
		() => Boolean( window.wp?.data?.select( 'flavor-agent' ) ),
		undefined,
		{ timeout: 90_000 }
	);
}

async function dismissWelcomeGuide( page ) {
	await page.evaluate( () => {
		window.wp?.data
			?.dispatch( 'core/preferences' )
			?.set?.( 'core/edit-post', 'welcomeGuide', false );
		window.wp?.data
			?.dispatch( 'core/preferences' )
			?.set?.( 'core/edit-post', 'welcomeGuideTemplate', false );
	} );

	const welcomeOverlay = page
		.locator( '.components-modal__screen-overlay' )
		.filter( {
			hasText:
				/Welcome to the editor|Welcome to the Site Editor|Page 1 of 4/i,
		} );

	for ( let attempt = 0; attempt < 4; attempt++ ) {
		if ( ! ( await welcomeOverlay.isVisible().catch( () => false ) ) ) {
			return;
		}

		const closeButton = welcomeOverlay
			.getByRole( 'button', { name: 'Close' } )
			.first();
		const getStartedButton = welcomeOverlay
			.getByRole( 'button', { name: 'Get started' } )
			.first();

		if ( await closeButton.isVisible().catch( () => false ) ) {
			await closeButton.click().catch( () => {} );
		} else if ( await getStartedButton.isVisible().catch( () => false ) ) {
			await getStartedButton.click().catch( () => {} );
		} else {
			await page.keyboard.press( 'Escape' ).catch( () => {} );
		}

		await page.waitForTimeout( 250 );
	}
}

async function enableMockedPatternRecommendations( page ) {
	await page.addInitScript( () => {
		const applyPatternCapability = ( nextData = {} ) => {
			const data = nextData || {};

			data.canRecommendPatterns = true;
			data.capabilities = data.capabilities || {};
			data.capabilities.surfaces = data.capabilities.surfaces || {};
			data.capabilities.surfaces.pattern = {
				...( data.capabilities.surfaces.pattern || {} ),
				available: true,
				reason: 'ready',
				owner: 'connectors',
			};

			return data;
		};

		let localizedData = applyPatternCapability(
			window.flavorAgentData || {}
		);

		Object.defineProperty( window, 'flavorAgentData', {
			configurable: true,
			get() {
				return localizedData;
			},
			set( nextData ) {
				localizedData = applyPatternCapability( nextData || {} );
			},
		} );
	} );
}

async function seedParagraphBlock( page ) {
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.blocks?.createBlock &&
				window.wp?.data?.select( 'core/block-editor' ) &&
				window.wp?.data?.dispatch( 'core/block-editor' )
		)
	);

	await page.evaluate( () => {
		const { createBlock } = window.wp.blocks;
		const paragraph = createBlock( 'core/paragraph', {
			content: 'Docs grounding warning browser test.',
		} );

		window.wp?.data?.dispatch( 'core/editor' )?.editPost( {
			title: 'Docs Grounding Warning',
		} );
		window.wp?.data
			?.dispatch( 'core/block-editor' )
			?.resetBlocks?.( [ paragraph ] );
		window.wp?.data
			?.dispatch( 'core/block-editor' )
			?.selectBlock?.( paragraph.clientId );
	} );
}

test( 'pattern inserter shows docs grounding warning for stale currentness coverage', async ( {
	page,
} ) => {
	await page.route(
		recommendationAbilityRoute( 'recommend-patterns' ),
		async ( route ) => {
			getAbilityRequestInput( route.request().postDataJSON() );

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					recommendations: [],
					diagnostics: {},
					docsGrounding: {
						status: 'grounded',
						coverage: {
							status: 'missing-current-release-cycle',
							message:
								'Current release-cycle sources are missing.',
						},
					},
				} ),
			} );
		}
	);

	await enableMockedPatternRecommendations( page );
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await seedParagraphBlock( page );

	await page
		.getByRole( 'button', {
			name: 'Block Inserter',
			exact: true,
		} )
		.click();

	await expect(
		page
			.locator( '.components-notice__content' )
			.filter( { hasText: DOCS_WARNING_TEXT } )
	).toBeVisible( { timeout: 15_000 } );
} );
