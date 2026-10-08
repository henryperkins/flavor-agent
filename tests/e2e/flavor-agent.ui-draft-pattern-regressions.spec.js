const { test, expect } = require( './test-fixtures' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );

const patternName = 'flavor-agent/draft-pattern-regression';
const patternTitle = 'Draft Pattern Regression';
const insertedContent = 'Inserted draft regression heading';
const resolvedContextSignature = 'draft-pattern-regression-resolved';
const welcomeHandledPages = new WeakSet();

function requestInput( body ) {
	return body?.input && typeof body.input === 'object' ? body.input : body;
}

async function openPostEditor( page ) {
	if ( ! welcomeHandledPages.has( page ) ) {
		const guide = page
			.getByRole( 'dialog', {
				name: /Welcome to the editor/i,
			} )
			.first();
		await page.addLocatorHandler( guide, async () => {
			await guide
				.getByRole( 'button', { name: 'Close', exact: true } )
				.click();
		} );
		welcomeHandledPages.add( page );
	}
	await page.addInitScript( () => {
		let data;
		Object.defineProperty( window, 'flavorAgentData', {
			configurable: true,
			get: () => data,
			set( value ) {
				data = value || {};
				data.canRecommendContent = true;
				data.canRecommendPatterns = true;
				data.capabilities = data.capabilities || {};
				data.capabilities.surfaces = data.capabilities.surfaces || {};
				for ( const surface of [ 'content', 'pattern' ] ) {
					data.capabilities.surfaces[ surface ] = {
						available: true,
						reason: 'ready',
						owner: 'connectors',
						patternRuntimeSignature: 'draft-pattern-runtime',
					};
				}
			},
		} );
	} );
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await page.waitForFunction( () =>
		Boolean(
			window.flavorAgentData?.canRecommendContent &&
				window.wp?.data?.select( 'flavor-agent' ) &&
				window.wp?.blocks?.createBlock
		)
	);
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		for ( const feature of [ 'welcomeGuide', 'welcomeGuideTemplate' ] ) {
			preferences.set( 'core/edit-post', feature, false );
		}
		window.wp.data.dispatch( 'core/editor' ).editPost( {
			title: 'Draft and pattern regression',
		} );
	} );
}

async function openPatternShelf( page ) {
	await openPostEditor( page );
	await expect
		.poll(
			() =>
				page.evaluate( () => {
					const editor = window.wp.data.select( 'core/block-editor' );
					return (
						editor.getAllowedPatterns?.( '' ) ||
						editor.__experimentalGetAllowedPatterns?.( '' ) ||
						[]
					).length;
				} ),
			{ timeout: 30_000 }
		)
		.toBeGreaterThan( 0 );
	await page.evaluate(
		( { name, title, content } ) => {
			const editor = window.wp.data.select( 'core/block-editor' );
			const dispatch = window.wp.data.dispatch( 'core/block-editor' );
			const settings = editor.getSettings();
			const pattern = {
				name,
				title,
				content: `<!-- wp:heading {"level":5} --><h5>${ content }</h5><!-- /wp:heading -->`,
			};
			dispatch.updateSettings( {
				blockPatterns: [ ...( settings.blockPatterns || [] ), pattern ],
				__experimentalBlockPatterns: [
					...( settings.__experimentalBlockPatterns || [] ),
					pattern,
				],
				__experimentalAdditionalBlockPatterns: [
					...( settings.__experimentalAdditionalBlockPatterns || [] ),
					pattern,
				],
			} );
			const heading = window.wp.blocks.createBlock( 'core/heading', {
				content: 'Existing section',
				level: 2,
			} );
			dispatch.resetBlocks( [ heading ] );
			dispatch.selectBlock( heading.clientId );
			dispatch.showInsertionPoint( '', 1 );
		},
		{ name: patternName, title: patternTitle, content: insertedContent }
	);
	await page
		.getByRole( 'button', { name: 'Block Inserter', exact: true } )
		.first()
		.click();
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/block-editor' )
			.showInsertionPoint( '', 1 )
	);
	const item = page
		.locator( '.flavor-agent-pattern-shelf__item' )
		.filter( { hasText: patternTitle } )
		.first();
	await expect( item ).toBeVisible( { timeout: 30_000 } );
	return item;
}

async function interceptPatterns( page ) {
	let releaseValidation;
	let validations = 0;
	let completedValidations = 0;
	await page.route( /recommend-patterns(?:\/|\?|$)/, async ( route ) => {
		const input = requestInput( route.request().postDataJSON() );
		if ( input.resolveSignatureOnly ) {
			validations++;
			await new Promise( ( resolve ) => {
				releaseValidation = resolve;
			} );
		}
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				resolvedContextSignature,
				recommendations: [
					{
						name: patternName,
						score: 0.98,
						reason: 'Use a section heading.',
					},
				],
			} ),
		} );
		if ( input.resolveSignatureOnly ) {
			completedValidations++;
		}
	} );
	return {
		get validations() {
			return validations;
		},
		get completedValidations() {
			return completedValidations;
		},
		release: () => releaseValidation(),
	};
}

async function editorHeadings( page ) {
	return page.evaluate( () =>
		window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.map( ( block ) => ( {
				clientId: block.clientId,
				level: block.attributes.level,
				content:
					typeof block.attributes.content === 'string'
						? block.attributes.content
						: block.attributes.content?.toHTMLString?.(),
			} ) )
	);
}

for ( const harnessTag of [ '', '@wp70-site-editor ' ] ) {
	for ( const variant of [ 'original', 'adapted' ] ) {
		test( `${ harnessTag }${ variant } pattern insertion rejects a target changed during validation`, async ( {
			page,
		} ) => {
			const validation = await interceptPatterns( page );
			const item = await openPatternShelf( page );
			if ( variant === 'adapted' ) {
				await item
					.getByRole( 'button', {
						name: 'Preview adapted',
						exact: true,
					} )
					.click();
			}
			const before = await editorHeadings( page );
			const insert =
				variant === 'adapted'
					? page
							.locator( '.flavor-agent-pattern-adaptation' )
							.getByRole( 'button', {
								name: /^Insert adapted\b/,
							} )
					: item.getByRole( 'button', { name: /^Insert original/ } );
			await insert.click();
			await expect.poll( () => validation.validations ).toBe( 1 );
			await expect( insert ).toBeDisabled();
			await page.evaluate( () =>
				window.wp.data
					.dispatch( 'core/block-editor' )
					.showInsertionPoint( '', 0 )
			);
			validation.release();
			await expect
				.poll( () => validation.completedValidations )
				.toBe( 1 );
			await expect.poll( () => editorHeadings( page ) ).toEqual( before );
			await expect(
				page.getByText( `Pattern "${ patternTitle }" inserted.`, {
					exact: true,
				} )
			).toHaveCount( 0 );
		} );

		test( `${ harnessTag }${ variant } pattern insertion ignores duplicate activation while validation is pending`, async ( {
			page,
		} ) => {
			const validation = await interceptPatterns( page );
			const item = await openPatternShelf( page );
			if ( variant === 'adapted' ) {
				await item
					.getByRole( 'button', {
						name: 'Preview adapted',
						exact: true,
					} )
					.click();
			}
			const insert =
				variant === 'adapted'
					? page
							.locator( '.flavor-agent-pattern-adaptation' )
							.getByRole( 'button', {
								name: /^Insert adapted\b/,
							} )
					: item.getByRole( 'button', { name: /^Insert original/ } );
			await insert.evaluate( ( button ) => {
				button.click();
				button.click();
			} );
			await expect.poll( () => validation.validations ).toBe( 1 );
			await expect( insert ).toBeDisabled();
			validation.release();
			await expect
				.poll( async () => ( await editorHeadings( page ) ).length )
				.toBe( 2 );
			const headings = await editorHeadings( page );
			expect( headings[ 1 ].content ).toContain( insertedContent );
			expect( headings[ 1 ].level ).toBe( variant === 'adapted' ? 3 : 5 );
			expect( validation.validations ).toBe( 1 );
			await expect(
				page
					.getByText( `Pattern "${ patternTitle }" inserted.`, {
						exact: true,
					} )
					.first()
			).toBeVisible();
		} );
	}

	test( `${ harnessTag }Content retains the next draft typed during generation and marks the old result stale`, async ( {
		page,
	} ) => {
		let releaseResponse;
		let received = false;
		await page.route( /recommend-content(?:\/|\?|$)/, async ( route ) => {
			received = true;
			await new Promise( ( resolve ) => {
				releaseResponse = resolve;
			} );
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					mode: 'draft',
					title: 'Generated old request',
					content: 'Completed text for the old request.',
					notes: [],
					issues: [],
				} ),
			} );
		} );
		await openPostEditor( page );
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/edit-post' )
				.openGeneralSidebar?.( 'edit-post/document' );
			window.wp.data
				.dispatch( 'core/interface' )
				.enableComplementaryArea(
					'core/edit-post',
					'edit-post/document'
				);
		} );
		const postTab = page.getByRole( 'tab', { name: 'Post', exact: true } );
		if ( await postTab.isVisible().catch( () => false ) ) {
			await postTab.click();
		}
		const panel = page.locator( '.flavor-agent-content-recommender' );
		const prompt = panel.locator( 'textarea' );
		if ( ! ( await prompt.isVisible() ) ) {
			await page
				.getByRole( 'button', {
					name: 'Content Recommendations',
					exact: true,
				} )
				.click();
		}
		await prompt.fill( 'Generate the first request.' );
		await panel
			.getByRole( 'button', { name: 'Generate Draft Text', exact: true } )
			.click();
		await expect.poll( () => received ).toBe( true );
		await expect( prompt ).toBeEditable();
		await prompt.fill( 'Keep this next draft while waiting.' );
		releaseResponse();
		await expect(
			panel.getByText( 'Generated old request', { exact: true } )
		).toBeVisible();
		if ( ! ( await prompt.isVisible() ) ) {
			await panel
				.getByRole( 'button', { name: /Refine request/ } )
				.click();
		}
		await expect( prompt ).toHaveValue(
			'Keep this next draft while waiting.'
		);
		await expect( panel ).toContainText(
			/refresh before relying on the previous text/i
		);
		await expect(
			panel.getByRole( 'button', { name: /^Copy/ } ).first()
		).toBeDisabled();
	} );
}
