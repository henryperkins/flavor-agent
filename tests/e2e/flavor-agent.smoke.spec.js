const { test, expect } = require( '@playwright/test' );

const BLOCK_RESPONSE = {
	payload: {
		settings: [],
		styles: [],
		block: [
			{
				label: 'Update content',
				attributeUpdates: {
					content: 'Hello from Flavor Agent',
				},
			},
		],
		explanation: 'Mocked block recs',
	},
};
const PATTERN_REASON = 'Recommended for this content block.';
const TEMPLATE_PROMPT = 'Make this template read more like an editorial front page.';
const TEMPLATE_PATTERN_TITLE = 'Editorial Banner';

async function dismissWelcomeGuide( page ) {
	const closeButton = page.getByRole( 'button', { name: 'Close' } );

	if ( await closeButton.count() ) {
		await closeButton.click();
		await page.waitForTimeout( 500 );
	}
}

async function dismissSiteEditorWelcomeGuide( page ) {
	const getStartedButton = page.getByRole( 'button', {
		name: 'Get started',
	} );

	if ( await getStartedButton.count() ) {
		await getStartedButton.click();
		await page.waitForTimeout( 500 );
	}
}

async function waitForWordPressReady( page ) {
	for ( let attempt = 0; attempt < 12; attempt++ ) {
		const loadingText = page.getByText( 'WordPress is not ready yet' );

		if ( ! ( await loadingText.count() ) ) {
			return;
		}

		await page.waitForTimeout( 1000 );
		await page.reload( { waitUntil: 'domcontentloaded' } );
	}

	await expect(
		page.getByText( 'WordPress is not ready yet' )
	).toHaveCount( 0 );
}

async function waitForFlavorAgent( page ) {
	await page.waitForFunction(
		() => Boolean( window.wp?.data?.select( 'flavor-agent' ) )
	);
}

async function seedParagraphBlock( page ) {
	const canvas = page.frameLocator( 'iframe' ).first();
	const defaultBlockButton = canvas.getByRole( 'button', {
		name: /Add default block|Type \/ to choose a block/i,
	} );

	await page.evaluate( () => {
		window.flavorAgentData.canRecommendBlocks = true;
		window.wp?.data?.dispatch( 'core/editor' )?.editPost( {
			title: 'Smoke Test',
		} );
	} );
	await expect(
		canvas.getByRole( 'textbox', { name: 'Add title' } )
	).toBeVisible();

	if ( await defaultBlockButton.count() ) {
		await defaultBlockButton.click();
	} else {
		await canvas.locator( 'body' ).click();
	}

	await page.keyboard.type( 'Hello world' );
	await page.waitForTimeout( 500 );

	return page.evaluate( () => {
		return (
			window.wp?.data
				?.select( 'core/block-editor' )
				?.getSelectedBlockClientId?.() ||
			window.wp?.data?.select( 'core/block-editor' )?.getBlockOrder?.()[ 0 ] ||
			null
		);
	} );
}

async function ensureSettingsSidebarOpen( page ) {
	const settingsButton = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );

	if ( ( await settingsButton.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await settingsButton.click();
	}
}

async function ensurePanelOpen( page, title, content ) {
	if ( await content.isVisible().catch( () => false ) ) {
		return;
	}

	const toggle = page
		.locator(
			`button:has-text("${ title }"), [role="button"]:has-text("${ title }")`
		)
		.first();

	await expect( toggle ).toBeVisible();

	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await toggle.click();
	}

	await expect( content ).toBeVisible();
}

function getVisibleSearchInput( page ) {
	return page.locator( '[role="searchbox"]:visible, input[type="search"]:visible' ).first();
}

async function getTemplateTarget( page ) {
	return page.evaluate( () => {
		function findTemplatePart( blocks ) {
			let fallback = null;

			for ( const block of blocks ) {
				if ( block?.name === 'core/template-part' ) {
					const candidate = {
						clientId: block.clientId,
						slug: block.attributes?.slug || '',
						area: block.attributes?.area || '',
					};

					if ( candidate.slug && candidate.area ) {
						return candidate;
					}

					if ( ! fallback && candidate.slug ) {
						fallback = candidate;
					}
				}

				if ( block?.innerBlocks?.length ) {
					const nested = findTemplatePart( block.innerBlocks );

					if ( nested ) {
						return nested;
					}
				}
			}

			return fallback;
		}

		const blockEditor = window.wp?.data?.select( 'core/block-editor' );
		const editSite = window.wp?.data?.select( 'core/edit-site' );
		const templatePart = findTemplatePart( blockEditor?.getBlocks?.() || [] );

		if ( ! templatePart?.slug ) {
			return null;
		}

		return {
			templateRef: editSite?.getEditedPostId?.() || null,
			templatePart,
		};
	} );
}

async function openFirstTemplateEditor( page ) {
	const templatesNavButton = page.getByRole( 'button', {
		name: 'Templates',
		exact: true,
	} );

	if ( await templatesNavButton.count() ) {
		await templatesNavButton.click();
	}

	await expect(
		page.getByRole( 'region', { name: 'Templates' } )
	).toBeVisible();

	const templateButton = page.getByRole( 'button', {
		name: 'Blog Home',
		exact: true,
	} ).first();

	await expect( templateButton ).toBeVisible();
	await templateButton.click();

	await page.waitForTimeout( 1000 );
	await waitForFlavorAgent( page );
	await page.waitForFunction(
		() =>
			window.wp?.data?.select( 'core/edit-site' )?.getEditedPostType?.() ===
			'wp_template'
	);
}

test( 'block inspector smoke renders AI recommendations', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await page.waitForTimeout( 5000 );
	await dismissWelcomeGuide( page );

	const clientId = await seedParagraphBlock( page );
	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'What are you trying to achieve?'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	await expect(
		page.getByRole( 'button', { name: 'Get Suggestions' } )
	).toBeVisible();

	await page.evaluate(
		( { selectedClientId, payload } ) => {
			window.wp.data.dispatch( 'flavor-agent' ).setBlockRecommendations(
				selectedClientId,
				{
					blockName: 'core/paragraph',
					blockContext: { name: 'core/paragraph' },
					...payload,
				}
			);
		},
		{
			selectedClientId: clientId,
			payload: BLOCK_RESPONSE.payload,
		}
	);

	await expect(
		page.getByText( BLOCK_RESPONSE.payload.explanation, {
			exact: true,
		} )
	).toBeVisible();

	const suggestionButton = page.getByRole( 'button', {
		name: 'Update content',
		exact: true,
	} );

	await expect( suggestionButton ).toBeVisible();
	await expect( suggestionButton ).toBeEnabled();
} );

test( 'pattern surface smoke uses the inserter search to fetch recommendations', async ( {
	page,
} ) => {
	const patternRequests = [];

	await page.route(
		'**/*recommend-patterns*',
		async ( route ) => {
			patternRequests.push( route.request().postDataJSON() );
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					recommendations: [
						{
							name: 'playground/recommended-pattern',
							score: 0.97,
							reason: PATTERN_REASON,
						},
					],
				} ),
			} );
		}
	);

	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await page.waitForTimeout( 5000 );
	await dismissWelcomeGuide( page );

	await page.waitForFunction(
		() => Boolean( window.flavorAgentData?.canRecommendPatterns )
	);
	await seedParagraphBlock( page );
	const searchPrompt = 'hero';

	await expect.poll( () => patternRequests.length > 0 ).toBe( true );

	await page.getByRole( 'button', {
		name: 'Block Inserter',
		exact: true,
	} ).click();

	const searchInput = getVisibleSearchInput( page );

	await expect( searchInput ).toBeVisible();
	await page.waitForTimeout( 500 );
	await searchInput.fill( searchPrompt );

	await expect.poll( () => patternRequests.length >= 2 ).toBe( true );

	const activeRequest = patternRequests.at( -1 );

	expect( activeRequest.prompt ).toBe( searchPrompt );
	expect( activeRequest.blockContext ).toEqual( {
		blockName: 'core/paragraph',
	} );

	await expect(
		page.getByLabel( '1 pattern recommendation available' )
	).toBeVisible();
} );

test( 'template surface smoke fetches template recommendations and links to editor actions', async ( {
	page,
} ) => {
	let templateTarget = null;
	const templateRequests = [];

	await page.route(
		'**/*recommend-template*',
		async ( route ) => {
			templateRequests.push( route.request().postDataJSON() );

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					explanation: `Review ${ templateTarget.templatePart.slug } before reusing ${ TEMPLATE_PATTERN_TITLE }.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Review ${ templateTarget.templatePart.slug } in the ${ templateTarget.templatePart.area } area and reuse ${ TEMPLATE_PATTERN_TITLE }.`,
							templateParts: [
								{
									slug: templateTarget.templatePart.slug,
									area: templateTarget.templatePart.area,
									reason: `Review ${ templateTarget.templatePart.slug } first.`,
								},
							],
							patternSuggestions: [ TEMPLATE_PATTERN_TITLE ],
						},
					],
				} ),
			} );
		}
	);

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await openFirstTemplateEditor( page );
	await dismissSiteEditorWelcomeGuide( page );

	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplates ) &&
			window.wp?.data?.select( 'core/edit-site' )?.getEditedPostType?.() ===
				'wp_template'
	);
	await page.waitForFunction( () => {
		return (
			window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.()
				.length > 0
		);
	} );

	templateTarget = await getTemplateTarget( page );
	expect( templateTarget ).toBeTruthy();

	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-site', 'welcomeGuideTemplate', true );
		window.wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea( 'core/edit-site', 'edit-post/document' );
	} );
	await page.waitForTimeout( 500 );

	const promptInput = page.getByPlaceholder(
		'Describe the structure or layout you want.'
	);

	await ensurePanelOpen(
		page,
		'AI Template Recommendations',
		promptInput
	);
	await promptInput.fill( TEMPLATE_PROMPT );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect.poll( () => templateRequests.length ).toBe( 1 );
	expect( templateRequests[ 0 ].templateRef ).toBe(
		templateTarget.templateRef
	);
	expect( templateRequests[ 0 ].prompt ).toBe( TEMPLATE_PROMPT );

	await expect( page.getByText( 'Suggested Composition' ) ).toBeVisible();

	const templatePartButton = page.getByRole( 'button', {
		name: templateTarget.templatePart.slug,
		exact: true,
	} ).first();

	await expect( templatePartButton ).toBeVisible();
	await page.getByRole( 'button', {
		name: 'Browse pattern',
		exact: true,
	} ).click();

	await expect
		.poll( async () => {
			return page.evaluate( () =>
				window.wp.data.select( 'core/editor' ).isInserterOpened()
			);
		} )
		.toBe( true );

	const patternsTab = page.getByRole( 'tab', {
		name: /Patterns/i,
	} );

	if ( await patternsTab.count() ) {
		await expect( patternsTab ).toHaveAttribute( 'aria-selected', 'true' );
	}

	await expect( getVisibleSearchInput( page ) ).toBeVisible();
} );
