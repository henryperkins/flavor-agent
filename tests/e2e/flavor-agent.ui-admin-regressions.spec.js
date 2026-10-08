const { test, expect } = require( './test-fixtures' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );

test( '@wp70-site-editor Settings resumes a sync already indexing when the page opens', async ( {
	page,
} ) => {
	let statusRequests = 0;
	await page.route(
		'**/wp-admin/options-general.php?page=flavor-agent',
		async ( route ) => {
			const response = await route.fetch();
			const body = ( await response.text() )
				.replace(
					/data-pattern-sync-status="[^"]*"/g,
					'data-pattern-sync-status="indexing"'
				)
				.replace(
					/data-pattern-prerequisites-ready="[^"]*"/g,
					'data-pattern-prerequisites-ready="1"'
				);
			await route.fulfill( { response, body } );
		}
	);
	await page.route(
		'**/wp-json/flavor-agent/v1/sync-patterns',
		async ( route ) => {
			expect( route.request().method() ).toBe( 'GET' );
			statusRequests += 1;
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					runtimeState: { status: 'ready', indexed_count: 12 },
				} ),
			} );
		}
	);

	await page.goto( '/wp-admin/options-general.php?page=flavor-agent', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	const patterns = page.locator( '[data-flavor-agent-section="patterns"]' );
	if ( ! ( await patterns.evaluate( ( element ) => element.open ) ) ) {
		await patterns.locator( ':scope > summary' ).click();
	}
	await expect( page.locator( '.flavor-agent-sync-panel' ) ).toHaveAttribute(
		'data-pattern-sync-status',
		'ready'
	);
	await expect( page.locator( '#flavor-agent-sync-button' ) ).toBeEnabled();
	expect( statusRequests ).toBe( 1 );
} );

function pendingPostEntry( canDecide ) {
	return {
		id: 'post-decision-permission',
		type: 'apply_post_blocks_suggestion',
		surface: 'post-blocks',
		applyLane: 'server-executed',
		canDecide,
		status: 'pending',
		suggestion: 'Review this post change',
		target: { postId: 42, postType: 'post' },
		document: { scopeKey: 'post:42', postType: 'post', entityId: '42' },
		before: {},
		after: {},
		apply: {
			status: 'pending',
			requestedBy: 7,
			expiresAt: '2030-06-10T00:00:00Z',
			operations: [],
		},
		timestamp: '2026-06-10T00:00:00Z',
	};
}

for ( const [ label, canDecide ] of [
	[ 'granted', true ],
	[ 'denied', false ],
	[ 'missing', undefined ],
] ) {
	test( `@wp70-site-editor Activity uses the ${ label } row decision permission`, async ( {
		page,
	} ) => {
		const entry = pendingPostEntry( canDecide );
		let claimRequests = 0;
		await page.addInitScript( () => {
			let boot;
			Object.defineProperty( window, 'flavorAgentActivityLog', {
				configurable: true,
				get: () => boot,
				set: ( value ) => {
					boot = { ...value, canApproveStyleApplies: false };
				},
			} );
		} );
		await page.route(
			'**/wp-json/flavor-agent/v1/activity**',
			async ( route ) => {
				const isClaim = route.request().url().includes( '/claim' );
				if ( isClaim && route.request().method() === 'POST' ) {
					claimRequests += 1;
				}
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						isClaim
							? { claim: null, entry }
							: {
									entries: [ entry ],
									paginationInfo: {
										page: 1,
										perPage: 20,
										totalItems: 1,
										totalPages: 1,
									},
									summary: { total: 1, pending: 1 },
							  }
					),
				} );
			}
		);

		await page.goto(
			'/wp-admin/options-general.php?page=flavor-agent-activity&activity=post-decision-permission',
			{ waitUntil: 'domcontentloaded' }
		);
		await waitForWordPressReady( page );
		await expect(
			page.locator( '#flavor-agent-activity-log-details' )
		).toContainText( 'Review this post change' );
		const approve = page.getByRole( 'button', {
			name: 'Approve and apply',
			exact: true,
		} );
		if ( canDecide === true ) {
			await expect( approve ).toBeEnabled();
			await expect(
				page.getByRole( 'button', { name: 'Reject', exact: true } )
			).toBeEnabled();
			await expect.poll( () => claimRequests ).toBe( 1 );
		} else {
			await expect( approve ).toHaveCount( 0 );
			await page.evaluate( () =>
				window.dispatchEvent( new Event( 'focus' ) )
			);
			expect( claimRequests ).toBe( 0 );
		}
	} );
}
