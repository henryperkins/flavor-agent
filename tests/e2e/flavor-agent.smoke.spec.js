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
const TEMPLATE_INSERTED_CONTENT = 'Inserted by Flavor Agent';
const TEMPLATE_PATTERN_NAME = 'flavor-agent/editorial-banner';
const TEMPLATE_PATTERN_TITLE = 'Editorial Banner';

async function dismissWelcomeGuide( page ) {
	const welcomeOverlay = page.locator( '.components-modal__screen-overlay' );
	const closeButton = welcomeOverlay
		.getByRole( 'button', { name: 'Close' } )
		.first();

	const didAppear = await closeButton
		.waitFor( { state: 'visible', timeout: 10000 } )
		.then( () => true )
		.catch( () => false );

	if ( didAppear ) {
		await closeButton.click();
		await expect( welcomeOverlay ).toBeHidden();
	}
}

async function dismissSiteEditorWelcomeGuide( page ) {
	const welcomeOverlay = page.locator( '.components-modal__screen-overlay' );
	const getStartedButton = welcomeOverlay
		.getByRole( 'button', { name: 'Get started' } )
		.first();

	const didAppear = await getStartedButton
		.waitFor( { state: 'visible', timeout: 10000 } )
		.then( () => true )
		.catch( () => false );

	if ( didAppear ) {
		await getStartedButton.click();
		await expect( welcomeOverlay ).toBeHidden();
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

async function getCurrentPostEditUrl( page ) {
	return page.evaluate( () => {
		const editor = window.wp?.data?.select( 'core/editor' );
		const postId = editor?.getCurrentPostId?.();

		if ( ! postId ) {
			return window.location.pathname + window.location.search;
		}

		return `/wp-admin/post.php?post=${ postId }&action=edit`;
	} );
}

async function saveCurrentPost( page ) {
	await page.evaluate( () => {
		return window.wp?.data?.dispatch( 'core/editor' )?.savePost?.();
	} );

	await expect
		.poll( () =>
			page.evaluate( () => ( {
				isAutosaving:
					window.wp?.data
						?.select( 'core/editor' )
						?.isAutosavingPost?.() || false,
				isSaving:
					window.wp?.data
						?.select( 'core/editor' )
						?.isSavingPost?.() || false,
			} ) )
		)
		.toEqual( {
			isAutosaving: false,
			isSaving: false,
		} );
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
	await dismissWelcomeGuide( page );

	if ( await defaultBlockButton.count() ) {
		await defaultBlockButton.click();
	} else {
		await canvas.locator( 'body' ).click();
	}

	await page.keyboard.type( 'Hello world' );
	await expect
		.poll( () =>
			page.evaluate( () => {
				const blocks =
					window.wp?.data
						?.select( 'core/block-editor' )
						?.getBlocks?.() || [];
				const paragraph = blocks.find(
					( block ) => block?.name === 'core/paragraph'
				);

				return paragraph?.attributes?.content || '';
			} )
		)
		.toContain( 'Hello world' );

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
		function inferArea( attributes ) {
			if ( typeof attributes?.area === 'string' && attributes.area ) {
				return attributes.area;
			}

			if (
				typeof attributes?.slug === 'string' &&
				window.flavorAgentData?.templatePartAreas?.[ attributes.slug ]
			) {
				return window.flavorAgentData.templatePartAreas[ attributes.slug ];
			}

			if (
				attributes?.slug === 'header' ||
				attributes?.slug === 'footer' ||
				attributes?.slug === 'sidebar'
			) {
				return attributes.slug;
			}

			return '';
		}

		function findTemplatePart( blocks ) {
			let fallback = null;

			for ( const block of blocks ) {
				if ( block?.name === 'core/template-part' ) {
					const candidate = {
						clientId: block.clientId,
						slug: block.attributes?.slug || '',
						area: inferArea( block.attributes ),
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
	await page.waitForFunction(
		() =>
			window.wp?.data?.select( 'core/edit-site' )?.getEditedPostType?.() ===
				'wp_template' &&
			Boolean(
				window.wp?.data?.select( 'core/edit-site' )?.getEditedPostId?.()
			)
	);
	await waitForFlavorAgent( page );
}

async function enableTemplateDocumentSidebar( page ) {
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-site', 'welcomeGuideTemplate', true );
		window.wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea( 'core/edit-site', 'edit-post/document' );
	} );
	await expect(
		page.getByRole( 'tab', { name: 'Template', exact: true } )
	).toBeVisible();
}

async function registerTemplatePattern(
	page,
	{ insertedContent, patternName, patternTitle }
) {
	await page.evaluate(
		( {
			insertedContent: nextInsertedContent,
			patternName: nextPatternName,
			patternTitle: nextPatternTitle,
		} ) => {
			const blockEditorDispatch =
				window.wp.data.dispatch( 'core/block-editor' );
			const blockEditorSelect =
				window.wp.data.select( 'core/block-editor' );
			const settings = blockEditorSelect.getSettings?.() || {};
			const existingPatterns = Array.isArray(
				settings.__experimentalBlockPatterns
			)
				? settings.__experimentalBlockPatterns.filter(
						( pattern ) => pattern?.name !== nextPatternName
				  )
				: [];

			blockEditorDispatch.updateSettings( {
				__experimentalBlockPatterns: [
					...existingPatterns,
					{
						name: nextPatternName,
						title: nextPatternTitle,
						content: `<!-- wp:paragraph --><p>${ nextInsertedContent }</p><!-- /wp:paragraph -->`,
					},
				],
			} );
		},
		{
			insertedContent,
			patternName,
			patternTitle,
		}
	);
}

async function openTemplateRecommendationsPanel( page ) {
	const promptInput = page.getByPlaceholder(
		'Describe the structure or layout you want.'
	);

	await ensurePanelOpen(
		page,
		'AI Template Recommendations',
		promptInput
	);

	return promptInput;
}

async function getTemplateInsertState( page, insertedContent ) {
	return page.evaluate( ( { nextInsertedContent } ) => {
		function normalizeValue( value ) {
			if ( Array.isArray( value ) ) {
				return value.map( ( item ) =>
					normalizeValue( item === undefined ? null : item )
				);
			}

			if ( value && typeof value === 'object' ) {
				return Object.fromEntries(
					Object.entries( value )
						.filter( ( [ , entryValue ] ) => entryValue !== undefined )
						.sort( ( [ leftKey ], [ rightKey ] ) =>
							leftKey.localeCompare( rightKey )
						)
						.map( ( [ key, entryValue ] ) => [
							key,
							normalizeValue( entryValue ),
						] )
				);
			}

			return value;
		}

		function normalizeBlockSnapshot( block ) {
			return {
				name: block?.name || '',
				attributes: normalizeValue( block?.attributes || {} ),
				innerBlocks: Array.isArray( block?.innerBlocks )
					? block.innerBlocks.map( normalizeBlockSnapshot )
					: [],
			};
		}

		function getBlockByPath( blocks, path = [] ) {
			let currentBlocks = blocks;
			let block = null;

			for ( const index of path ) {
				if ( ! Array.isArray( currentBlocks ) ) {
					return null;
				}

				block = currentBlocks[ index ] || null;

				if ( ! block ) {
					return null;
				}

				currentBlocks = block.innerBlocks || [];
			}

			return block;
		}

		function resolveRootBlocks( blocks, rootLocator ) {
			if (
				! rootLocator ||
				rootLocator.type === 'root' ||
				( Array.isArray( rootLocator.path ) && rootLocator.path.length === 0 )
			) {
				return blocks;
			}

			const rootBlock = getBlockByPath( blocks, rootLocator.path || [] );

			return Array.isArray( rootBlock?.innerBlocks )
				? rootBlock.innerBlocks
				: [];
		}

		function hasInsertedParagraph( blocks ) {
			const flavorAgent = window.wp.data.select( 'flavor-agent' );
			const activityLog = flavorAgent.getActivityLog?.() || [];
			const lastActivity = activityLog[ activityLog.length - 1 ] || null;
			const insertOperation =
				( lastActivity?.after?.operations || [] ).find(
					( operation ) => operation?.type === 'insert_pattern'
				) || null;

			if (
				insertOperation?.rootLocator &&
				Number.isInteger( insertOperation?.index ) &&
				Array.isArray( insertOperation?.insertedBlocksSnapshot )
			) {
				const rootBlocks = resolveRootBlocks(
					blocks,
					insertOperation.rootLocator
				);
				const slice = rootBlocks.slice(
					insertOperation.index,
					insertOperation.index +
						insertOperation.insertedBlocksSnapshot.length
				);

				return (
					JSON.stringify( slice.map( normalizeBlockSnapshot ) ) ===
					JSON.stringify( insertOperation.insertedBlocksSnapshot )
				);
			}

			for ( const block of blocks ) {
				const content = String( block?.attributes?.content || '' );

				if (
					block?.name === 'core/paragraph' &&
					content.includes( nextInsertedContent )
				) {
					return true;
				}

				if (
					Array.isArray( block?.innerBlocks ) &&
					hasInsertedParagraph( block.innerBlocks )
				) {
					return true;
				}
			}

			return false;
		}

		const flavorAgent = window.wp.data.select( 'flavor-agent' );
		const activityLog = flavorAgent.getActivityLog?.() || [];
		const lastActivity = activityLog[ activityLog.length - 1 ] || null;
		const blocks =
			window.wp.data.select( 'core/block-editor' ).getBlocks?.() || [];

		return {
			hasInsertedContent: hasInsertedParagraph( blocks ),
			undoStatus: lastActivity?.undo?.status || '',
		};
	}, { nextInsertedContent: insertedContent } );
}

test( 'block inspector smoke applies, persists, and undoes AI recommendations', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
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
	await suggestionButton.click();

	await expect
		.poll( () =>
			page.evaluate( ( { selectedClientId } ) => {
				return (
					window.wp.data
						.select( 'core/block-editor' )
						.getBlockAttributes?.( selectedClientId )?.content || ''
				);
			}, { selectedClientId: clientId } )
		)
		.toBe( 'Hello from Flavor Agent' );

	await expect( page.getByText( 'Recent AI Actions' ) ).toBeVisible();
	await expect
		.poll( () =>
			page.evaluate( () =>
				window.wp?.data?.select( 'core/editor' )?.getCurrentPostId?.() ||
				null
			)
		)
		.toBeTruthy();
	await saveCurrentPost( page );

	const editUrl = await getCurrentPostEditUrl( page );

	await page.goto( editUrl, {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await ensureSettingsSidebarOpen( page );
	await page.waitForFunction(
		() =>
			( window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() || [] )
				.length > 0
	);
	await page.evaluate( () => {
		const blockEditor = window.wp.data.select( 'core/block-editor' );
		const paragraph = ( blockEditor.getBlocks?.() || [] ).find(
			( block ) => block?.name === 'core/paragraph'
		);

		if ( paragraph?.clientId ) {
			window.wp.data
				.dispatch( 'core/block-editor' )
				.selectBlock( paragraph.clientId );
		}
	} );

	const refreshedPromptInput = page.getByPlaceholder(
		'What are you trying to achieve?'
	);

	await ensurePanelOpen( page, 'AI Recommendations', refreshedPromptInput );
	await page
		.locator( '.flavor-agent-activity-row' )
		.getByRole( 'button', { name: 'Undo', exact: true } )
		.click();

	await expect
		.poll( () =>
			page.evaluate( () => {
				const flavorAgent = window.wp.data.select( 'flavor-agent' );
				const blockEditor = window.wp.data.select( 'core/block-editor' );
				const paragraph = ( blockEditor.getBlocks?.() || [] ).find(
					( block ) => block?.name === 'core/paragraph'
				);
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity =
					activityLog[ activityLog.length - 1 ] || null;

				return {
					content: paragraph?.attributes?.content || '',
					undoStatus: lastActivity?.undo?.status || '',
				};
			} )
		)
		.toEqual( {
			content: 'Hello world',
			undoStatus: 'undone',
		} );
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

test( 'template surface smoke previews and applies executable template recommendations', async ( {
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
					explanation: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PATTERN_NAME,
								},
							],
							templateParts: [],
							patternSuggestions: [ TEMPLATE_PATTERN_NAME ],
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

	await enableTemplateDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	} );

	const promptInput = await openTemplateRecommendationsPanel(
		page
	);
	await promptInput.fill( TEMPLATE_PROMPT );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect.poll( () => templateRequests.length ).toBe( 1 );
	expect( templateRequests[ 0 ].templateRef ).toBe(
		templateTarget.templateRef
	);
	expect( templateRequests[ 0 ].prompt ).toBe( TEMPLATE_PROMPT );
	expect( templateRequests[ 0 ] ).toHaveProperty(
		'visiblePatternNames'
	);
	expect( templateRequests[ 0 ].visiblePatternNames ).toContain(
		TEMPLATE_PATTERN_NAME
	);

	await expect( page.getByText( 'Suggested Composition' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Preview Apply' } ).click();
	await expect( page.getByText( 'Review Before Apply' ) ).toBeVisible();
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/block-editor' )
			.clearSelectedBlock();
	} );
	await page.getByRole( 'button', { name: 'Confirm Apply' } ).click();

	await expect
		.poll( () =>
			page.evaluate(
				( { patternName } ) => {
					const flavorAgent =
						window.wp.data.select( 'flavor-agent' );
					const operations =
						flavorAgent.getTemplateLastAppliedOperations?.() || [];
					const activityLog =
						flavorAgent.getActivityLog?.() || [];
					const lastActivity =
						activityLog[ activityLog.length - 1 ] || null;

					return {
						applyStatus:
							flavorAgent.getTemplateApplyStatus?.() || '',
						hasInsertOperation: operations.some(
							( operation ) =>
								operation?.type === 'insert_pattern' &&
								operation?.patternName === patternName
						),
						lastActivityType: lastActivity?.type || '',
					};
				},
				{
					patternName: TEMPLATE_PATTERN_NAME,
				}
			)
		)
		.toEqual( {
			applyStatus: 'success',
			hasInsertOperation: true,
			lastActivityType: 'apply_template_suggestion',
	} );

	await page.getByRole( 'tab', { name: 'Template', exact: true } ).click();
	await openTemplateRecommendationsPanel( page );
	await expect( page.getByText( 'Recent AI Actions' ) ).toBeVisible();
	await expect( page.locator( '.flavor-agent-activity-row' ) ).toContainText(
		'Clarify template hierarchy'
	);
} );

test( '@wp70-site-editor template undo survives a Site Editor refresh when the template has not drifted', async ( {
	page,
} ) => {
	await page.route(
		'**/*recommend-template*',
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					explanation: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PATTERN_NAME,
								},
							],
							templateParts: [],
							patternSuggestions: [ TEMPLATE_PATTERN_NAME ],
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
	await page.waitForFunction(
		() =>
			(
				window.wp?.data
					?.select( 'core/block-editor' )
					?.getBlocks?.() || []
			).length > 0
	);

	await enableTemplateDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	} );

	const promptInput = await openTemplateRecommendationsPanel( page );
	await promptInput.fill( TEMPLATE_PROMPT );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();
	await expect( page.getByText( 'Suggested Composition' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Preview Apply' } ).click();
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/block-editor' )
			.clearSelectedBlock();
	} );
	await page.getByRole( 'button', { name: 'Confirm Apply' } ).click();
	const templateEditorUrl = page.url();

	await expect
		.poll( async () => ( await getTemplateInsertState(
			page,
			TEMPLATE_INSERTED_CONTENT
		) ).undoStatus )
		.toBe( 'available' );

	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await page.goto( templateEditorUrl, {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await page.waitForFunction(
		() =>
			window.wp?.data?.select( 'core/edit-site' )?.getEditedPostType?.() ===
			'wp_template'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data
					?.select( 'core/block-editor' )
					?.getBlocks?.() || []
			).length > 0
	);

	await enableTemplateDocumentSidebar( page );
	await page.getByRole( 'tab', { name: 'Template', exact: true } ).click();
	await openTemplateRecommendationsPanel( page );
	await expect( page.getByText( 'Recent AI Actions' ) ).toBeVisible();
	await page
		.locator( '.flavor-agent-activity-row' )
		.getByRole( 'button', { name: 'Undo', exact: true } )
		.click();

	await expect
		.poll( () => getTemplateInsertState( page, TEMPLATE_INSERTED_CONTENT ) )
		.toEqual( {
			hasInsertedContent: false,
			undoStatus: 'undone',
		} );
} );

test( '@wp70-site-editor template undo is disabled after inserted pattern content changes', async ( {
	page,
} ) => {
	const editedInsertedContent = 'Inserted content edited after apply';

	await page.route(
		'**/*recommend-template*',
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					explanation: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PATTERN_NAME,
								},
							],
							templateParts: [],
							patternSuggestions: [ TEMPLATE_PATTERN_NAME ],
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
	await page.waitForFunction(
		() =>
			(
				window.wp?.data
					?.select( 'core/block-editor' )
					?.getBlocks?.() || []
			).length > 0
	);

	await enableTemplateDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	} );

	const promptInput = await openTemplateRecommendationsPanel( page );
	await promptInput.fill( TEMPLATE_PROMPT );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();
	await expect( page.getByText( 'Suggested Composition' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Preview Apply' } ).click();
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/block-editor' )
			.clearSelectedBlock();
	} );
	await page.getByRole( 'button', { name: 'Confirm Apply' } ).click();

	await expect
		.poll( async () => ( await getTemplateInsertState(
			page,
			TEMPLATE_INSERTED_CONTENT
		) ).undoStatus )
		.toBe( 'available' );

	await page.evaluate(
		( { nextContent } ) => {
			function normalizeValue( value ) {
				if ( Array.isArray( value ) ) {
					return value.map( ( item ) =>
						normalizeValue( item === undefined ? null : item )
					);
				}

				if ( value && typeof value === 'object' ) {
					return Object.fromEntries(
						Object.entries( value )
							.filter( ( [ , entryValue ] ) => entryValue !== undefined )
							.sort( ( [ leftKey ], [ rightKey ] ) =>
								leftKey.localeCompare( rightKey )
							)
							.map( ( [ key, entryValue ] ) => [
								key,
								normalizeValue( entryValue ),
							] )
					);
				}

				return value;
			}

			function normalizeBlockSnapshot( block ) {
				return {
					name: block?.name || '',
					attributes: normalizeValue( block?.attributes || {} ),
					innerBlocks: Array.isArray( block?.innerBlocks )
						? block.innerBlocks.map( normalizeBlockSnapshot )
						: [],
				};
			}

			function findParagraphBlock( blocks ) {
				for ( const block of blocks ) {
					if ( block?.name === 'core/paragraph' ) {
						return block;
					}

					if ( Array.isArray( block?.innerBlocks ) ) {
						const nested = findParagraphBlock( block.innerBlocks );

						if ( nested ) {
							return nested;
						}
					}
				}

				return null;
			}

			function findMatchingInsertedSlice( blocks, snapshot ) {
				const sliceLength = Array.isArray( snapshot ) ? snapshot.length : 0;

				if ( sliceLength > 0 ) {
					for ( let index = 0; index <= blocks.length - sliceLength; index++ ) {
						const candidate = blocks.slice( index, index + sliceLength );

						if (
							JSON.stringify( candidate.map( normalizeBlockSnapshot ) ) ===
							JSON.stringify( snapshot )
						) {
							return candidate;
						}
					}
				}

				for ( const block of blocks ) {
					if ( Array.isArray( block?.innerBlocks ) ) {
						const nested = findMatchingInsertedSlice(
							block.innerBlocks,
							snapshot
						);

						if ( nested ) {
							return nested;
						}
					}
				}

				return null;
			}

			const flavorAgent = window.wp.data.select( 'flavor-agent' );
			const activityLog = flavorAgent.getActivityLog?.() || [];
			const lastActivity = activityLog[ activityLog.length - 1 ] || null;
			const insertOperation =
				( lastActivity?.after?.operations || [] ).find(
					( operation ) => operation?.type === 'insert_pattern'
				) || null;
			const blockEditor = window.wp.data.select( 'core/block-editor' );
			const insertedBlockSlice = findMatchingInsertedSlice(
				blockEditor.getBlocks?.() || [],
				insertOperation?.insertedBlocksSnapshot || []
			);
			const insertedBlock = Array.isArray( insertedBlockSlice )
				? findParagraphBlock( insertedBlockSlice )
				: null;

			if ( insertedBlock?.clientId ) {
				window.wp.data
					.dispatch( 'core/block-editor' )
					.updateBlockAttributes( insertedBlock.clientId, {
						content: nextContent,
					} );
			}
		},
		{ nextContent: editedInsertedContent }
	);
	await expect
		.poll( () =>
			page.evaluate( ( { nextContent } ) => {
				function hasEditedParagraph( blocks ) {
					for ( const block of blocks ) {
						const content = String( block?.attributes?.content || '' );

						if (
							block?.name === 'core/paragraph' &&
							content.includes( nextContent )
						) {
							return true;
						}

						if (
							Array.isArray( block?.innerBlocks ) &&
							hasEditedParagraph( block.innerBlocks )
						) {
							return true;
						}
					}

					return false;
				}

				return hasEditedParagraph(
					window.wp.data
						.select( 'core/block-editor' )
						.getBlocks?.() || []
				);
			}, { nextContent: editedInsertedContent } )
		)
		.toBe( true );

	await page.getByRole( 'tab', { name: 'Template', exact: true } ).click();
	await openTemplateRecommendationsPanel( page );

	await page.evaluate( async () => {
		const flavorAgent = window.wp.data.select( 'flavor-agent' );
		const activityLog = flavorAgent.getActivityLog?.() || [];
		const lastActivity = activityLog[ activityLog.length - 1 ] || null;

		if ( lastActivity?.id ) {
			await window.wp.data
				.dispatch( 'flavor-agent' )
				.undoActivity( lastActivity.id );
		}
	} );

	await expect(
		page.getByText(
			'Inserted pattern content changed after apply and cannot be undone automatically.'
		)
	).toBeVisible();
	await expect(
		page
			.locator( '.flavor-agent-activity-row' )
			.getByRole( 'button', { name: 'Undo', exact: true } )
	).toHaveCount( 0 );
	await expect
		.poll( () =>
			page.evaluate( () => {
				const flavorAgent = window.wp.data.select( 'flavor-agent' );
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity =
					activityLog[ activityLog.length - 1 ] || null;

				return {
					undoStatus: lastActivity?.undo?.status || '',
					undoError: flavorAgent.getUndoError?.() || '',
				};
			} )
		)
		.toEqual( {
			undoStatus: 'failed',
			undoError:
				'Inserted pattern content changed after apply and cannot be undone automatically.',
		} );
	await expect
		.poll( () =>
			page.evaluate( ( { nextContent } ) => {
				function hasEditedParagraph( blocks ) {
					for ( const block of blocks ) {
						const content = String( block?.attributes?.content || '' );

						if (
							block?.name === 'core/paragraph' &&
							content.includes( nextContent )
						) {
							return true;
						}

						if (
							Array.isArray( block?.innerBlocks ) &&
							hasEditedParagraph( block.innerBlocks )
						) {
							return true;
						}
					}

					return false;
				}

				return hasEditedParagraph(
					window.wp.data
						.select( 'core/block-editor' )
						.getBlocks?.() || []
				);
			}, { nextContent: editedInsertedContent } )
		)
		.toBe( true );
} );
