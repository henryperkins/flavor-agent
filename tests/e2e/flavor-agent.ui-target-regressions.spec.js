const { test, expect } = require( './test-fixtures' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig } = require( '../../scripts/wp70-e2e' );

const themeSlug = getWp70HarnessConfig().themeSlug;
const patternName = 'flavor-agent/target-regression';
const resolvedContextSignature = 'target-regression-resolved';
const surfaces = {
	template: {
		postType: 'wp_template',
		slug: 'home',
		capability: 'template',
		flag: 'canRecommendTemplates',
		panel: 'AI Template Recommendations',
		selector: '.flavor-agent-template-panel',
	},
	'template-part': {
		postType: 'wp_template_part',
		slug: 'header',
		capability: 'templatePart',
		flag: 'canRecommendTemplateParts',
		panel: 'AI Template Part Recommendations',
		selector: '.flavor-agent-template-part-panel',
	},
};

function requestInput( body ) {
	return body?.input && typeof body.input === 'object' ? body.input : body;
}

async function openEditor( page, surface ) {
	const config = surfaces[ surface ];
	await page.addInitScript( ( { flag, capability } ) => {
		let data;
		Object.defineProperty( window, 'flavorAgentData', {
			configurable: true,
			get: () => data,
			set( value ) {
				data = value || {};
				data[ flag ] = true;
				data.capabilities = data.capabilities || {};
				data.capabilities.surfaces = data.capabilities.surfaces || {};
				data.capabilities.surfaces[ capability ] = {
					available: true,
					reason: 'ready',
					owner: 'connectors',
				};
			},
		} );
	}, config );
	const entityId = `${ themeSlug }//${ config.slug }`;
	await page.goto(
		`/wp-admin/site-editor.php?postType=${
			config.postType
		}&postId=${ encodeURIComponent( entityId ) }`,
		{ waitUntil: 'domcontentloaded' }
	);
	await waitForWordPressReady( page );
	const editorIsReady = ( { postType, id } ) => {
		const editor = window.wp?.data?.select( 'core/edit-site' );
		return (
			editor?.getEditedPostType?.() === postType &&
			editor?.getEditedPostId?.() === id &&
			Boolean( window.wp?.data?.select( 'flavor-agent' ) ) &&
			window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.()
				.length > 0
		);
	};
	const editorTarget = { postType: config.postType, id: entityId };
	const openedDirectly = await page
		.waitForFunction( editorIsReady, editorTarget, { timeout: 10_000 } )
		.then( () => true )
		.catch( () => false );
	if ( ! openedDirectly ) {
		// New Site Editor routers can resolve the legacy URL to a DataViews grid.
		const entityCard = page
			.getByRole( 'button', {
				name: surface === 'template' ? 'Blog Home' : 'Header',
				exact: true,
			} )
			.first();
		await expect( entityCard ).toBeVisible( { timeout: 60_000 } );
		await entityCard.click();
		await page.waitForFunction( editorIsReady, editorTarget, {
			timeout: 90_000,
		} );
	}
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		for ( const scope of [ 'core/edit-site', 'core/edit-post' ] ) {
			for ( const feature of [
				'welcomeGuide',
				'welcomeGuideTemplate',
				'welcomeGuideStyles',
			] ) {
				preferences.set( scope, feature, false );
			}
		}
		window.wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea( 'core/edit-site', 'edit-post/document' );
		const editor = window.wp.data.select( 'core/block-editor' );
		window.wp.data.dispatch( 'core/block-editor' ).updateSettings( {
			...editor.getSettings(),
			__experimentalBlockPatterns: [
				...( editor.getSettings().__experimentalBlockPatterns || [] ),
				{
					name: 'flavor-agent/target-regression',
					title: 'Target Regression',
					content:
						'<!-- wp:paragraph --><p>AI inserted content</p><!-- /wp:paragraph -->',
				},
			],
		} );
		window.wp.data.dispatch( 'core/block-editor' ).resetBlocks( [
			window.wp.blocks.createBlock( 'core/paragraph', {
				content: 'Obsolete note',
			} ),
			window.wp.blocks.createBlock( 'core/paragraph', {
				content: 'Legal notice',
			} ),
		] );
	} );
	const closeGuide = page
		.getByRole( 'dialog' )
		.getByRole( 'button', { name: 'Close', exact: true } );
	if ( await closeGuide.isVisible().catch( () => false ) ) {
		await closeGuide.click();
	}
	const documentTab = page.getByRole( 'tab', {
		name: surface === 'template' ? 'Template' : 'Template Part',
		exact: true,
	} );
	if ( await documentTab.count() ) {
		await documentTab.click();
	}
	const prompt = page.getByPlaceholder(
		'Describe the structure or layout you want.'
	);
	if ( ! ( await prompt.isVisible() ) ) {
		await page
			.getByRole( 'button', { name: config.panel, exact: true } )
			.click();
	}
	await expect( prompt ).toBeVisible();
	return page.locator( config.selector );
}

async function reviewSuggestion( panel, suggestionLabel ) {
	await panel
		.getByPlaceholder( 'Describe the structure or layout you want.' )
		.fill( 'Remove the obsolete note and preserve the legal notice.' );
	await panel
		.getByRole( 'button', { name: 'Get Suggestions', exact: true } )
		.click();
	await panel
		.getByRole( 'button', {
			name: `Review ${ suggestionLabel }`,
			exact: true,
		} )
		.click();
	await expect(
		panel.getByRole( 'button', { name: 'Confirm Apply', exact: true } )
	).toBeEnabled();
}

async function editorBlocks( page ) {
	return page.evaluate( () =>
		window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.map( ( block ) => ( {
				clientId: block.clientId,
				content:
					typeof block.attributes.content === 'string'
						? block.attributes.content
						: block.attributes.content?.toHTMLString?.(),
			} ) )
	);
}

for ( const surface of [ 'template', 'template-part' ] ) {
	test( `@wp70-site-editor ${ surface } invalidates reviewed targets when same-shaped siblings exchange paths`, async ( {
		page,
	} ) => {
		let generationInput;
		await page.route(
			new RegExp( `recommend-${ surface }(?:/|\\?|$)` ),
			async ( route ) => {
				const input = requestInput( route.request().postDataJSON() );
				if ( ! input.resolveSignatureOnly ) {
					generationInput = input;
				}
				const node =
					surface === 'template'
						? input.editorStructure.topLevelBlockTree[ 0 ]
						: input.editorStructure.allBlockPaths[ 0 ];
				const operation =
					surface === 'template'
						? {
								type: 'insert_pattern',
								patternName,
								placement: 'before_block_path',
								targetPath: [ 0 ],
								expectedTarget: node,
						  }
						: {
								type: 'remove_block',
								expectedBlockName: 'core/paragraph',
								targetPath: [ 0 ],
								expectedTarget: node,
						  };
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						resolvedContextSignature,
						suggestions: [
							{
								label: 'Reviewed target operation',
								description:
									'Operate on the reviewed first paragraph.',
								operations: [ operation ],
							},
						],
					} ),
				} );
			}
		);
		const panel = await openEditor( page, surface );
		await reviewSuggestion( panel, 'Reviewed target operation' );
		const original = await editorBlocks( page );
		const reviewedNode =
			surface === 'template'
				? generationInput.editorStructure.topLevelBlockTree[ 0 ]
				: generationInput.editorStructure.allBlockPaths[ 0 ];
		expect( reviewedNode.editorIdentity.clientId ).toBe(
			original[ 0 ].clientId
		);
		expect( reviewedNode.editorIdentity.subtreeSignature ).toContain(
			'Obsolete note'
		);

		await page.evaluate( () => {
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			window.wp.data
				.dispatch( 'core/block-editor' )
				.resetBlocks( [ ...blocks ].reverse() );
		} );

		await expect(
			panel.locator( '.flavor-agent-scope-bar__stale-message' )
		).toBeVisible();
		await expect(
			panel.getByRole( 'button', { name: 'Confirm Apply', exact: true } )
		).toBeDisabled();
		expect( await editorBlocks( page ) ).toEqual( [
			original[ 1 ],
			original[ 0 ],
		] );
	} );
}

test( '@wp70-site-editor shared Apply rejects a manual edit made while server preflight is pending', async ( {
	page,
} ) => {
	let heldPreflight;
	let releasePreflight;
	const preflightGate = new Promise( ( resolve ) => {
		releasePreflight = resolve;
	} );
	await page.route( /recommend-template-part(?:\/|\?|$)/, async ( route ) => {
		const input = requestInput( route.request().postDataJSON() );
		if ( input.resolveSignatureOnly ) {
			heldPreflight = input;
			await preflightGate;
		}
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				resolvedContextSignature,
				suggestions: [
					{
						label: 'Insert reviewed introduction',
						description: 'Insert the introduction at the start.',
						operations: [
							{
								type: 'insert_pattern',
								patternName,
								placement: 'start',
							},
						],
					},
				],
			} ),
		} );
	} );
	const panel = await openEditor( page, 'template-part' );
	await reviewSuggestion( panel, 'Insert reviewed introduction' );
	const original = await editorBlocks( page );
	const beforeApplyCount = await page.evaluate(
		() =>
			window.wp.data
				.select( 'flavor-agent' )
				.getActivityLog()
				.filter(
					( entry ) => entry.type === 'apply_template_part_suggestion'
				).length
	);
	await panel
		.getByRole( 'button', { name: 'Confirm Apply', exact: true } )
		.click();
	await expect.poll( () => Boolean( heldPreflight ) ).toBe( true );
	await page.evaluate( ( id ) => {
		window.wp.data
			.dispatch( 'core/block-editor' )
			.updateBlockAttributes( id, {
				content: 'Manual legal correction',
			} );
	}, original[ 1 ].clientId );
	releasePreflight();

	await expect
		.poll( () =>
			page.evaluate( () =>
				window.wp.data
					.select( 'flavor-agent' )
					.getTemplatePartApplyStatus()
			)
		)
		.toBe( 'error' );
	expect( await editorBlocks( page ) ).toEqual( [
		original[ 0 ],
		{ ...original[ 1 ], content: 'Manual legal correction' },
	] );
	expect(
		await page.evaluate(
			() =>
				window.wp.data
					.select( 'flavor-agent' )
					.getActivityLog()
					.filter(
						( entry ) =>
							entry.type === 'apply_template_part_suggestion'
					).length
		)
	).toBe( beforeApplyCount );
	await expect(
		panel.getByText(
			'This editor context changed while the suggestion was being checked. Refresh the recommendations before applying.',
			{ exact: true }
		)
	).toBeVisible();
} );
