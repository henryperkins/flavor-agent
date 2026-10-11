const { test, expect } = require( './test-fixtures' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );

async function openBlockEditor( page ) {
	await page.addLocatorHandler(
		page.getByRole( 'dialog', { name: /Welcome to the editor/i } ).first(),
		async ( dialog ) => {
			await dialog
				.getByRole( 'button', { name: 'Close', exact: true } )
				.click();
		}
	);
	await page.addInitScript( () => {
		let data;
		Object.defineProperty( window, 'flavorAgentData', {
			configurable: true,
			get: () => data,
			set( value ) {
				data = value || {};
				data.canRecommendBlocks = true;
				data.capabilities = data.capabilities || {};
				data.capabilities.surfaces = data.capabilities.surfaces || {};
				data.capabilities.surfaces.block = {
					available: true,
					reason: 'ready',
					owner: 'connectors',
				};
			},
		} );
	} );
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.data?.select( 'flavor-agent' ) &&
				window.wp?.blocks?.createBlock
		)
	);
	return page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		preferences.set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/editor' ).editPost( {
			title: 'UI review regression',
		} );
		const block = window.wp.blocks.createBlock( 'core/paragraph', {
			content: 'Original content',
		} );
		const editor = window.wp.data.dispatch( 'core/block-editor' );
		editor.resetBlocks( [ block ] );
		editor.selectBlock( block.clientId );
		window.wp.data
			.dispatch( 'core/edit-post' )
			.openGeneralSidebar( 'edit-post/block' );
		return block.clientId;
	} );
}

for ( const harnessTag of [ '', '@wp70-site-editor ' ] ) {
	test( `${ harnessTag }block apply preserves content when the prompt changes during preflight`, async ( {
		page,
	} ) => {
		let release;
		let applyRequests = 0;
		let backgroundRequests = 0;
		const gate = new Promise( ( resolve ) => {
			release = resolve;
		} );
		const label = 'Apply delayed prompt update';
		const signature = 'ui-review-prompt-race';
		await page.route( /recommend-block(?:\/|\?|$)/, async ( route ) => {
			const body = route.request().postDataJSON();
			const input = body?.input || body;
			const isApply =
				input.resolveSignatureOnly &&
				! Object.hasOwn( input, 'contextSignature' );
			if ( isApply ) {
				applyRequests++;
				await gate;
			}
			const payload = input.resolveSignatureOnly
				? { resolvedContextSignature: signature }
				: {
						settings: [],
						styles: [],
						block: [
							{
								label,
								panel: 'typography',
								attributeUpdates: { content: 'AI content' },
							},
						],
						executionContract: {
							allowedPanels: [ 'typography' ],
							panelMappingKnown: true,
							contentAttributeKeys: [ 'content' ],
							configAttributeKeys: [],
						},
						resolvedContextSignature: signature,
						explanation: 'Change the paragraph content.',
				  };
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					payload,
					resolvedContextSignature: signature,
				} ),
			} );
			if ( input.resolveSignatureOnly && ! isApply ) {
				backgroundRequests++;
			}
		} );
		try {
			const clientId = await openBlockEditor( page );
			const prompt = page.getByPlaceholder(
				'Describe the outcome you want for this block.'
			);
			if ( ! ( await prompt.isVisible() ) ) {
				await page
					.getByRole( 'button', {
						name: 'AI Recommendations',
						exact: true,
					} )
					.click();
			}
			await prompt.fill( 'Make the paragraph clearer.' );
			await page
				.getByRole( 'button', { name: 'Get Suggestions', exact: true } )
				.click();
			const suggestion = page.getByRole( 'button', {
				name: label,
				exact: true,
			} );
			await expect( suggestion ).toBeEnabled();
			await expect.poll( () => backgroundRequests ).toBeGreaterThan( 0 );
			await suggestion.click();
			await expect.poll( () => applyRequests ).toBe( 1 );
			await prompt.fill( 'Keep my original paragraph.' );
			release();
			await expect
				.poll( () =>
					page.evaluate( ( id ) => {
						const agent = window.wp.data.select( 'flavor-agent' );
						return {
							content: window.wp.data
								.select( 'core/block-editor' )
								.getBlockAttributes( id ).content,
							status: agent.getBlockApplyStatus( id ),
							staleReason: agent.getBlockStaleReason( id ),
							activities: agent
								.getActivityLog()
								.filter(
									( entry ) =>
										entry.type === 'apply_suggestion'
								).length,
							toasts: agent.getToasts().length,
						};
					}, clientId )
				)
				.toEqual( {
					content: 'Original content',
					status: 'error',
					staleReason: 'client',
					activities: 0,
					toasts: 0,
				} );
			await expect( prompt ).toHaveValue( 'Keep my original paragraph.' );
			await expect(
				page.getByText( label, { exact: true } )
			).toBeVisible();
		} finally {
			release();
			await page.unrouteAll( { behavior: 'wait' } );
		}
	} );

	test( `${ harnessTag }toast dismissal returns shortcut focus to the editor iframe`, async ( {
		page,
	} ) => {
		await openBlockEditor( page );
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await expect( canvas.locator( 'body' ) ).toBeVisible();
		await canvas.locator( 'body' ).evaluate( ( body ) => {
			const button = body.ownerDocument.createElement( 'button' );
			button.id = 'ui-review-focus-origin';
			button.textContent = 'Return to editor';
			body.appendChild( button );
		} );
		const origin = canvas.locator( '#ui-review-focus-origin' );
		await origin.focus();
		await page.evaluate( () => {
			window.wp.data.dispatch( 'flavor-agent' ).enqueueToast( {
				id: 'ui-review-focus-toast',
				title: 'Focus restoration regression',
				variant: 'success',
				autoDismissMs: 60_000,
			} );
		} );
		await expect( page.locator( '.flavor-agent-toast' ) ).toBeVisible();
		await origin.press( 'Control+Alt+Shift+U' );
		await expect(
			page.locator( '.flavor-agent-toast__action' )
		).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '.flavor-agent-toast' ) ).toHaveCount( 0 );
		await expect( origin ).toBeFocused();
	} );

	test( `${ harnessTag }Activity keeps an explicit release after a late focus claim`, async ( {
		page,
	} ) => {
		const claim = {
			userId: 1,
			displayName: 'Test reviewer',
			expiresAt: '2030-06-10T00:00:00Z',
		};
		let serverClaim = claim;
		let posts = 0;
		let deletes = 0;
		let holdRefresh = false;
		let release;
		const gate = new Promise( ( resolve ) => {
			release = resolve;
		} );
		const entry = () => ( {
			id: 'ui-review-claim-race',
			type: 'apply_post_blocks_suggestion',
			surface: 'post-blocks',
			applyLane: 'server-executed',
			canDecide: true,
			status: 'pending',
			suggestion: 'Review the claim race',
			target: { postId: 42, postType: 'post' },
			document: { scopeKey: 'post:42', postType: 'post', entityId: '42' },
			before: {},
			after: {},
			apply: {
				status: 'pending',
				requestedBy: 7,
				expiresAt: '2030-06-10T00:00:00Z',
				operations: [],
				claim: serverClaim,
			},
			timestamp: '2026-06-10T00:00:00Z',
		} );
		await page.addInitScript( () => {
			let boot;
			Object.defineProperty( window, 'flavorAgentActivityLog', {
				configurable: true,
				get: () => boot,
				set: ( value ) => {
					boot = { ...value, currentUserId: 1 };
				},
			} );
		} );
		await page.route(
			'**/wp-json/flavor-agent/v1/activity**',
			async ( route ) => {
				const request = route.request();
				const isClaim = request.url().includes( '/claim' );
				// WordPress apiFetch can tunnel DELETE through a POST request.
				const method = (
					request.headers()[ 'x-http-method-override' ] ||
					new URL( request.url() ).searchParams.get( '_method' ) ||
					request.method()
				).toUpperCase();
				if ( isClaim && method === 'POST' ) {
					posts++;
					if ( holdRefresh ) {
						await gate;
					}
					serverClaim = claim;
				} else if ( isClaim && method === 'DELETE' ) {
					deletes++;
					serverClaim = null;
				}
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						isClaim
							? { claim: serverClaim, entry: entry() }
							: {
									entries: [ entry() ],
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
		try {
			await page.goto(
				'/wp-admin/options-general.php?page=flavor-agent-activity&activity=ui-review-claim-race',
				{
					waitUntil: 'domcontentloaded',
				}
			);
			await waitForWordPressReady( page );
			const releaseButton = page.getByRole( 'button', {
				name: 'Release review claim',
				exact: true,
			} );
			await expect( releaseButton ).toBeVisible();
			await expect.poll( () => posts ).toBe( 1 );
			holdRefresh = true;
			await page.evaluate( () =>
				window.dispatchEvent( new Event( 'focus' ) )
			);
			await expect.poll( () => posts ).toBe( 2 );
			await releaseButton.click();
			await expect.poll( () => deletes ).toBe( 1 );
			await expect( releaseButton ).toHaveCount( 0 );
			release();
			await expect.poll( () => deletes ).toBe( 2 );
			await expect( releaseButton ).toHaveCount( 0 );
			await expect(
				page.locator( '#flavor-agent-activity-log-details' )
			).not.toContainText( 'You’re reviewing this' );
			await expect(
				page.getByRole( 'button', {
					name: 'Approve and apply',
					exact: true,
				} )
			).toBeEnabled();
		} finally {
			release();
			await page.unrouteAll( { behavior: 'wait' } );
		}
	} );
}
