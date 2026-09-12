const fs = require( 'fs' );
const { test, expect } = require( './test-fixtures' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const harness = getWp70HarnessConfig();
const BEFORE = 'Original persistence test paragraph';
const AFTER = 'Changed by the persistence test recommendation';
const SUGGESTION = 'Update persistence test paragraph';
const SAVE_HEADER = 'x-flavor-agent-save-occurrence';
const TARGET_CLASS = 'persistence-target';

function postRoute( postId ) {
	return new RegExp( `/wp/v2/posts/${ postId }(?:\\?|$)` );
}

async function readApply( page, activityId ) {
	return page.evaluate( async ( id ) => {
		const response = await window.wp.apiFetch( {
			path: `/flavor-agent/v1/activity?applyIds=${ encodeURIComponent(
				id
			) }`,
		} );
		return response.entries.find( ( entry ) => entry.id === id ) || null;
	}, activityId );
}

async function readSavedContent( page, postId ) {
	return page.evaluate( async ( id ) => {
		const post = await window.wp.apiFetch( {
			path: `/wp/v2/posts/${ id }?context=edit`,
		} );
		return post.content.raw;
	}, postId );
}

function runVerificationWorker() {
	// Exercise the registered production worker hook against the real captured
	// save. Advance cron explicitly so the test does not depend on site traffic.
	runWpCli( harness, [
		'eval',
		"do_action( 'flavor_agent_verify_saved_applies' );",
	] );
}

async function waitForVerdict( page, activityId, state ) {
	runVerificationWorker();
	await expect
		.poll(
			async () =>
				( await readApply( page, activityId ) )?.persistenceVerdict
					?.state
		)
		.toBe( state );
	return readApply( page, activityId );
}

async function dismissWelcomeGuide( page ) {
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		preferences.set( 'core/edit-post', 'welcomeGuide', false );
		preferences.set( 'core/edit-post', 'welcomeGuideTemplate', false );
	} );
	const welcome = page
		.getByRole( 'dialog' )
		.filter( { hasText: /Welcome to the editor/ } );
	if ( await welcome.isVisible() ) {
		await welcome
			.getByRole( 'button', { name: 'Close', exact: true } )
			.click();
	}
	await expect( welcome ).toBeHidden();
}

async function openRecommendationEditor( page, title ) {
	// Same-type neighbors require the apply's unchanged attributes to identify
	// its target; a single paragraph would hide a missing identity snapshot.
	const created = runWpCli( harness, [
		'post',
		'create',
		'--post_status=publish',
		`--post_title=${ title }`,
		`--post_content=<!-- wp:paragraph {"className":"${ TARGET_CLASS }"} --><p class="${ TARGET_CLASS }">${ BEFORE }</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"persistence-neighbor"} --><p class="persistence-neighbor">Unchanged neighboring paragraph</p><!-- /wp:paragraph -->`,
		'--porcelain',
	] );
	const postId = Number( created.stdout.trim() );
	expect( postId ).toBeGreaterThan( 0 );

	// Only recommendation availability/provider output are fixture data. Apply,
	// activity storage, WordPress save, undo, comparison, and assurance are real.
	await page.addInitScript( () => {
		let localizedData;
		Object.defineProperty( window, 'flavorAgentData', {
			configurable: true,
			get: () => localizedData,
			set: ( data ) => {
				localizedData = data;
				if ( ! data ) {
					return;
				}
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
	await page.route( /recommend-block(?:\/|\?|$)/, async ( route ) => {
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				resolvedContextSignature: 'persistence-test-block-signature',
				payload: {
					resolvedContextSignature:
						'persistence-test-block-signature',
					settings: [],
					styles: [],
					block: [
						{
							label: SUGGESTION,
							panel: 'typography',
							attributeUpdates: { content: AFTER },
						},
					],
					executionContract: {
						allowedPanels: [ 'typography' ],
						panelMappingKnown: true,
						contentAttributeKeys: [ 'content' ],
						configAttributeKeys: [],
					},
					explanation: 'Persistence test recommendation',
				},
			} ),
		} );
	} );
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit`, {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await page.waitForFunction(
		() =>
			window.wp?.data?.select( 'flavor-agent' ) &&
			window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0
	);
	await dismissWelcomeGuide( page );
	const clientId = await page.evaluate( () => {
		const paragraph = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ];
		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( paragraph.clientId );
		window.wp.data
			.dispatch( 'core/edit-post' )
			.openGeneralSidebar( 'edit-post/block' );
		return paragraph.clientId;
	} );
	const blockTab = page.getByRole( 'tab', { name: 'Block', exact: true } );
	if ( await blockTab.isVisible() ) {
		await blockTab.click();
	}
	const prompt = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);
	if ( ! ( await prompt.isVisible() ) ) {
		await page
			.getByRole( 'button', { name: 'AI Recommendations', exact: true } )
			.click();
	}
	await expect( prompt ).toBeVisible();
	await page
		.getByRole( 'button', { name: 'Get Suggestions', exact: true } )
		.click();
	await page.getByRole( 'button', { name: SUGGESTION, exact: true } ).click();
	await expect( page.locator( '.flavor-agent-toast' ).first() ).toContainText(
		'Block updated in editor (not saved)'
	);
	await expect
		.poll( () =>
			page.evaluate(
				( id ) =>
					window.wp.data
						.select( 'core/block-editor' )
						.getBlockAttributes( id ).content,
				clientId
			)
		)
		.toBe( AFTER );
	await expect
		.poll( () =>
			page.evaluate(
				() =>
					window.wp.data
						.select( 'flavor-agent' )
						.getActivityLog()
						.find( ( entry ) => entry.type === 'apply_suggestion' )
						?.persistence?.status
			)
		)
		.toBe( 'server' );
	const activityId = await page.evaluate(
		() =>
			window.wp.data
				.select( 'flavor-agent' )
				.getActivityLog()
				.find( ( entry ) => entry.type === 'apply_suggestion' ).id
	);
	return { postId, clientId, activityId };
}

async function saveThroughWordPress(
	page,
	postId,
	{ responseLost = false } = {}
) {
	const request = page.waitForRequest(
		( next ) =>
			postRoute( postId ).test( next.url() ) && next.method() === 'POST'
	);
	const button = page.locator( 'button.editor-post-publish-button' );
	await expect( button ).toBeEnabled();
	await button.click();
	const saveRequest = await request;
	await expect
		.poll( () =>
			page.evaluate( () =>
				window.wp.data.select( 'core/editor' ).isSavingPost()
			)
		)
		.toBe( false );
	await expect
		.poll( () =>
			page.evaluate( () =>
				window.wp.data
					.select( 'core/editor' )
					.didPostSaveRequestSucceed()
			)
		)
		.toBe( ! responseLost );
	const occurrenceId = ( await saveRequest.allHeaders() )[ SAVE_HEADER ];
	expect( occurrenceId ).toMatch( /^[A-Za-z0-9_-]{1,64}$/ );
	return occurrenceId;
}

async function openBlockRecommendations( page ) {
	const prompt = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);
	// Gutenberg closes the settings sidebar when entering the mobile layout.
	// Reopen it as a user would before inspecting its recommendation UI.
	if ( ! ( await prompt.isVisible() ) ) {
		await page
			.getByRole( 'button', { name: 'Settings', exact: true } )
			.click();
		const blockTab = page.getByRole( 'tab', {
			name: 'Block',
			exact: true,
		} );
		if ( await blockTab.isVisible() ) {
			await blockTab.click();
		}
		const recommendations = page.getByRole( 'button', {
			name: 'AI Recommendations',
			exact: true,
		} );
		await expect( recommendations ).toBeVisible();
		if (
			( await recommendations.getAttribute( 'aria-expanded' ) ) !== 'true'
		) {
			await recommendations.click();
		}
	}
	await expect( prompt ).toBeVisible();
	const recentActions = page.getByRole( 'button', {
		name: /^Recent AI Actions/,
	} );
	await expect( recentActions ).toBeVisible();
	if ( ( await recentActions.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await recentActions.click();
	}
}

async function attachJSON( testInfo, name, value ) {
	const path = testInfo.outputPath( name );
	fs.writeFileSync( path, JSON.stringify( value, null, 2 ) );
	await testInfo.attach( name, { path, contentType: 'application/json' } );
}

async function captureView( page, testInfo, name, size, subject, prepareView ) {
	await page.setViewportSize( size );
	if ( prepareView ) {
		await prepareView( page );
	}
	await expect( subject ).toBeVisible();
	await subject.scrollIntoViewIfNeeded();
	const width = await page.evaluate( () => ( {
		viewport: window.innerWidth,
		document: document.documentElement.scrollWidth,
	} ) );
	expect( width.document ).toBeLessThanOrEqual( width.viewport + 1 );
	const path = testInfo.outputPath( `${ name }.png` );
	await page.screenshot( { path } );
	await testInfo.attach( name, { path, contentType: 'image/png' } );
}

test( '@wp70-site-editor persistence keeps editor undo independent until the next manual save', async ( {
	page,
	context,
}, testInfo ) => {
	const { postId, clientId, activityId } = await openRecommendationEditor(
		page,
		'Persistence save and undo'
	);
	const unsaved = await readApply( page, activityId );
	expect( unsaved.applyLane ).toBe( 'editor-state' );
	expect( unsaved.target.persistenceIdentity ).toMatchObject( {
		name: 'core/paragraph',
		attributes: { content: BEFORE, className: TARGET_CLASS },
	} );
	expect( unsaved.persistenceVerdict.state ).toBe( 'unknown' );
	expect( await readSavedContent( page, postId ) ).toContain( BEFORE );

	const activityRow = page
		.locator( '.flavor-agent-activity-row' )
		.filter( { hasText: SUGGESTION } );
	await captureView(
		page,
		testInfo,
		'editor-desktop-unsaved',
		{ width: 1440, height: 1000 },
		activityRow,
		openBlockRecommendations
	);
	await captureView(
		page,
		testInfo,
		'editor-mobile-unsaved',
		{ width: 390, height: 844 },
		activityRow,
		openBlockRecommendations
	);
	await page.setViewportSize( { width: 1440, height: 1000 } );
	await openBlockRecommendations( page );

	const firstOccurrence = await saveThroughWordPress( page, postId );
	const confirmed = await waitForVerdict(
		page,
		activityId,
		'save_confirmed'
	);
	expect( confirmed.persistenceVerdict.saveOccurrenceId ).toBe(
		firstOccurrence
	);
	expect( confirmed.persistenceVerdict.saveSequence ).toBeGreaterThan( 0 );
	expect( confirmed.verificationCoverage ).toMatchObject( {
		state: 'compared',
		conclusive: true,
	} );
	expect( await readSavedContent( page, postId ) ).toContain( AFTER );

	await activityRow
		.getByRole( 'button', { name: 'Undo', exact: true } )
		.click();
	await expect
		.poll( () =>
			page.evaluate(
				( id ) =>
					window.wp.data
						.select( 'core/block-editor' )
						.getBlockAttributes( id ).content,
				clientId
			)
		)
		.toBe( BEFORE );
	await expect
		.poll(
			async () =>
				( await readApply( page, activityId ) )?.undoState?.state
		)
		.toBe( 'undone' );
	const undoneBeforeSave = await readApply( page, activityId );
	expect( undoneBeforeSave.persistenceVerdict ).toEqual(
		confirmed.persistenceVerdict
	);
	expect( await readSavedContent( page, postId ) ).toContain( AFTER );

	const admin = await context.newPage();
	await admin.goto(
		`/wp-admin/options-general.php?page=flavor-agent-activity&activity=${ activityId }`,
		{ waitUntil: 'domcontentloaded' }
	);
	const assurance = admin
		.locator( '.flavor-agent-activity-log__assurance' )
		.first();
	await expect( assurance ).toContainText( 'Persisted' );
	await expect( assurance ).toContainText( 'Undone in editor' );
	await expect( assurance ).toContainText( 'Compared with saved content' );
	await captureView(
		admin,
		testInfo,
		'admin-desktop-persisted-and-undone',
		{ width: 1440, height: 1000 },
		assurance
	);
	await captureView(
		admin,
		testInfo,
		'admin-mobile-persisted-and-undone',
		{ width: 390, height: 844 },
		assurance
	);
	await admin.close();

	const secondOccurrence = await saveThroughWordPress( page, postId );
	expect( secondOccurrence ).not.toBe( firstOccurrence );
	const discarded = await waitForVerdict(
		page,
		activityId,
		'save_discarded'
	);
	expect( discarded.persistenceVerdict.saveOccurrenceId ).toBe(
		secondOccurrence
	);
	expect( discarded.persistenceVerdict.saveSequence ).toBeGreaterThan(
		confirmed.persistenceVerdict.saveSequence
	);
	expect( discarded.undoState.state ).toBe( 'undone' );
	expect( await readSavedContent( page, postId ) ).toContain( BEFORE );
	await attachJSON( testInfo, 'save-undo-assurance.json', {
		unsaved,
		confirmed,
		undoneBeforeSave,
		discarded,
	} );
} );

test( '@wp70-site-editor persistence verifies a successful server write after the browser loses its response', async ( {
	page,
}, testInfo ) => {
	const { postId, activityId } = await openRecommendationEditor(
		page,
		'Persistence lost response'
	);
	const forbidden = await page.evaluate( async ( id ) => {
		try {
			await window.wp.apiFetch( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
				data: {
					entry: {
						type: 'recommendation_outcome',
						linkedApplyActivityId: id,
						after: {
							outcome: {
								event: 'save_confirmed',
								serverAuthored: true,
							},
						},
					},
				},
			} );
			return null;
		} catch ( error ) {
			return { code: error.code, status: error.data?.status };
		}
	}, activityId );
	expect( forbidden ).toEqual( {
		code: 'flavor_agent_persistence_server_authorship_required',
		status: 403,
	} );
	let serverWriteStatus;
	await page.route( postRoute( postId ), async ( route ) => {
		if ( route.request().method() !== 'POST' ) {
			await route.continue();
			return;
		}
		const response = await route.fetch();
		serverWriteStatus = response.status();
		await route.abort( 'connectionreset' );
	} );
	const occurrenceId = await saveThroughWordPress( page, postId, {
		responseLost: true,
	} );
	expect( serverWriteStatus ).toBe( 200 );
	expect( await readSavedContent( page, postId ) ).toContain( AFTER );
	const confirmed = await waitForVerdict(
		page,
		activityId,
		'save_confirmed'
	);
	await expect
		.poll(
			async () =>
				( await readApply( page, activityId ) )?.requestStatus?.state
		)
		.toBe( 'save_failed' );
	const final = await readApply( page, activityId );
	expect( final.persistenceVerdict.saveOccurrenceId ).toBe( occurrenceId );
	expect( final.persistenceVerdict ).toEqual( confirmed.persistenceVerdict );
	expect( final.verificationCoverage.conclusive ).toBe( true );
	await attachJSON( testInfo, 'lost-response-assurance.json', {
		forbidden,
		serverWriteStatus,
		final,
	} );
} );
