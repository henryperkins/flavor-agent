const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const {
	getWp70HarnessConfig,
	resetSiteEditorState,
	runWpCli,
} = require( '../../scripts/wp70-e2e' );

const wp70Harness = getWp70HarnessConfig();
const FLAVOR_AGENT_READY_TIMEOUT_MS = 90_000;
const FLAVOR_AGENT_READY_RETRY_MS = 15_000;
const FLAVOR_AGENT_READY_NAVIGATION_TIMEOUT_MS = 10_000;

const BLOCK_RESPONSE = {
	payload: {
		settings: [],
		styles: [],
		block: [
			{
				label: 'Update content',
				panel: 'typography',
				attributeUpdates: {
					content: 'Hello from Flavor Agent',
				},
			},
		],
		executionContract: {
			allowedPanels: [ 'typography' ],
			panelMappingKnown: true,
			contentAttributeKeys: [ 'content' ],
			configAttributeKeys: [],
		},
		explanation: 'Mocked block recs',
	},
};
const BLOCK_STRUCTURAL_PATTERN_NAME = 'flavor-agent/block-structural-hero';
const BLOCK_STRUCTURAL_PATTERN_TITLE = 'Block Structural Hero';
const BLOCK_STRUCTURAL_INSERTED_CONTENT =
	'Inserted by Flavor Agent structural apply';
const PATTERN_REASON = 'Recommended for this content block.';
const NAVIGATION_PROMPT = 'Simplify the header navigation.';
const TEMPLATE_PROMPT =
	'Make this template read more like an editorial front page.';
const TEMPLATE_INSERTED_CONTENT = 'Inserted by Flavor Agent';
const TEMPLATE_PATTERN_NAME = 'flavor-agent/editorial-banner';
const TEMPLATE_PATTERN_TITLE = 'Editorial Banner';
const TEMPLATE_SUGGESTION_LABEL = 'Clarify template hierarchy';
const TEMPLATE_STALE_INSERTED_CONTENT = 'Template freshness check';
const TEMPLATE_MAIN_CONTENT_TARGET_PATH = [ 1, 0 ];
const TEMPLATE_MAIN_CONTENT_TARGET = {
	name: 'core/heading',
	label: 'Heading',
};
const TEMPLATE_PART_PROMPT = 'Add a compact utility row before the navigation.';
const TEMPLATE_PART_INSERTED_CONTENT =
	'Inserted into the template part by Flavor Agent';
const TEMPLATE_PART_PATTERN_NAME = 'flavor-agent/header-utility-row';
const TEMPLATE_PART_PATTERN_TITLE = 'Header Utility Row';
const TEMPLATE_PART_SUGGESTION_LABEL = 'Add utility row';
const TEMPLATE_PART_STALE_NOTICE =
	'This template-part result no longer matches the current live structure or prompt. Refresh before reviewing or applying anything from the previous result.';
const GLOBAL_STYLES_PROMPT =
	'Warm the canvas slightly and tighten the site-wide vertical rhythm.';
const GLOBAL_STYLES_SUGGESTION_LABEL = 'Adjust canvas tone and rhythm';
const GLOBAL_STYLES_BACKGROUND_VALUE = 'var:preset|color|paper';
const GLOBAL_STYLES_LINE_HEIGHT_VALUE = 1.73;
const GLOBAL_STYLES_STALE_TEXT_COLOR = '#101010';
const GLOBAL_STYLES_STALE_NOTICE =
	'This Global Styles result no longer matches the current live style state or prompt. Refresh before reviewing or applying anything from the previous result.';
const GLOBAL_STYLES_RESOLVED_CONTEXT_SIGNATURE = 'resolved-global-styles';
const GLOBAL_STYLES_SIDEBAR_SELECTOR =
	'.editor-global-styles-sidebar__panel, .editor-global-styles-sidebar, [role="region"][aria-label="Styles"]';
const MOCKED_RECOMMENDATION_SURFACES = Object.freeze( {
	block: {
		flag: 'canRecommendBlocks',
		capability: 'block',
	},
	content: {
		flag: 'canRecommendContent',
		capability: 'content',
	},
	pattern: {
		flag: 'canRecommendPatterns',
		capability: 'pattern',
	},
	template: {
		flag: 'canRecommendTemplates',
		capability: 'template',
	},
	'template-part': {
		flag: 'canRecommendTemplateParts',
		capability: 'templatePart',
	},
	navigation: {
		flag: 'canRecommendNavigation',
		capability: 'navigation',
	},
	'global-styles': {
		flag: 'canRecommendGlobalStyles',
		capability: 'globalStyles',
	},
	'style-book': {
		flag: 'canRecommendStyleBook',
		capability: 'styleBook',
	},
} );

function recommendationAbilityRoute( abilitySlug ) {
	return new RegExp( `${ abilitySlug }(?:/|\\?|$)` );
}

const GLOBAL_STYLES_RESPONSE = {
	resolvedContextSignature: GLOBAL_STYLES_RESOLVED_CONTEXT_SIGNATURE,
	explanation:
		'Use the theme paper preset for the canvas and tighten line height slightly.',
	suggestions: [
		{
			label: 'Adjust canvas tone and rhythm',
			description:
				'Apply the paper canvas preset and tighten the global line height.',
			category: 'color',
			tone: 'executable',
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'background' ],
					value: GLOBAL_STYLES_BACKGROUND_VALUE,
					valueType: 'preset',
					presetType: 'color',
					presetSlug: 'paper',
					cssVar: 'var(--wp--preset--color--paper)',
				},
				{
					type: 'set_styles',
					path: [ 'typography', 'lineHeight' ],
					value: GLOBAL_STYLES_LINE_HEIGHT_VALUE,
					valueType: 'freeform',
				},
			],
		},
	],
};
const GLOBAL_STYLES_PARTIAL_INVALID_RESPONSE = {
	resolvedContextSignature: GLOBAL_STYLES_RESOLVED_CONTEXT_SIGNATURE,
	explanation:
		'This response intentionally includes one unsupported operation to verify atomic apply behavior.',
	suggestions: [
		{
			label: GLOBAL_STYLES_SUGGESTION_LABEL,
			description:
				'The supported background update must not be written if a later grouped operation fails.',
			category: 'color',
			tone: 'executable',
			operations: [
				{
					type: 'set_styles',
					path: [ 'color', 'background' ],
					value: GLOBAL_STYLES_BACKGROUND_VALUE,
					valueType: 'preset',
					presetType: 'color',
					presetSlug: 'paper',
					cssVar: 'var(--wp--preset--color--paper)',
				},
				{
					type: 'set_styles',
					path: [ 'customCSS' ],
					value: 'body{color:red}',
					valueType: 'freeform',
				},
			],
		},
	],
};
const STYLE_BOOK_BLOCK_NAME = 'core/paragraph';
const STYLE_BOOK_BLOCK_TITLE = 'Paragraph';
const STYLE_BOOK_PROMPT =
	'Make this paragraph style feel more like a print pull quote.';
const STYLE_BOOK_STALE_TEXT_COLOR = '#123456';
const STYLE_BOOK_STALE_NOTICE =
	'This Style Book result no longer matches the current live block styles or prompt. Refresh before reviewing or applying anything from the previous result.';
const STYLE_BOOK_RESOLVED_CONTEXT_SIGNATURE = 'resolved-style-book';
const STYLE_BOOK_RESPONSE = {
	resolvedContextSignature: STYLE_BOOK_RESOLVED_CONTEXT_SIGNATURE,
	explanation:
		'Increase emphasis in the paragraph example with a stronger text treatment.',
	suggestions: [
		{
			label: 'Strengthen paragraph emphasis',
			description:
				'Use the signal preset for paragraph text so the example reads more like a featured pull quote.',
			category: 'typography',
			tone: 'executable',
			operations: [
				{
					type: 'set_block_styles',
					path: [ 'color', 'text' ],
					value: 'var:preset|color|signal',
					valueType: 'preset',
					presetType: 'color',
					presetSlug: 'signal',
					cssVar: 'var(--wp--preset--color--signal)',
				},
			],
		},
	],
};
const TEMPLATE_STALE_NOTICE =
	'This template result no longer matches the current live template or prompt. Refresh before reviewing or applying anything from the previous result.';
const TEMPLATE_RESOLVED_CONTEXT_SIGNATURE = 'resolved-template';
const TEMPLATE_PART_RESOLVED_CONTEXT_SIGNATURE = 'resolved-template-part';

async function dismissWelcomeGuide( page ) {
	const welcomeOverlay = page
		.locator( '.components-modal__screen-overlay' )
		.filter( {
			hasText:
				/Welcome to the editor|Welcome to the Site Editor|Welcome to styles|Page 1 of 4/i,
		} );

	const disableWelcomeGuidePreferences = () => {
		const preferences = window.wp?.data?.dispatch( 'core/preferences' );
		const editPost = window.wp?.data?.dispatch( 'core/edit-post' );
		const editSite = window.wp?.data?.dispatch( 'core/edit-site' );
		const editPostState = window.wp?.data?.select( 'core/edit-post' );
		const editSiteState = window.wp?.data?.select( 'core/edit-site' );

		preferences?.set?.( 'core/edit-post', 'welcomeGuide', false );
		preferences?.set?.( 'core/edit-post', 'welcomeGuideTemplate', false );
		preferences?.set?.( 'core/edit-site', 'welcomeGuide', false );
		preferences?.set?.( 'core/edit-site', 'welcomeGuideTemplate', false );
		preferences?.set?.( 'core/edit-site', 'welcomeGuideStyles', false );

		if ( editPostState?.isFeatureActive?.( 'welcomeGuide' ) ) {
			editPost?.toggleFeature?.( 'welcomeGuide' );
		}

		if ( editPostState?.isFeatureActive?.( 'welcomeGuideTemplate' ) ) {
			editPost?.toggleFeature?.( 'welcomeGuideTemplate' );
		}

		if ( editSiteState?.isFeatureActive?.( 'welcomeGuide' ) ) {
			editSite?.toggleFeature?.( 'welcomeGuide' );
		}

		if ( editSiteState?.isFeatureActive?.( 'welcomeGuideTemplate' ) ) {
			editSite?.toggleFeature?.( 'welcomeGuideTemplate' );
		}

		if ( editSiteState?.isFeatureActive?.( 'welcomeGuideStyles' ) ) {
			editSite?.toggleFeature?.( 'welcomeGuideStyles' );
		}
	};

	const forceRemoveWelcomeGuideOverlays = () => {
		const isWelcomeGuideOverlay = ( el ) => {
			const text = el.textContent || '';

			return (
				Boolean( el.querySelector( '[class*="welcome-guide"]' ) ) ||
				/Welcome to the editor|Welcome to the Site Editor|Welcome to styles|Page 1 of 4/i.test(
					text
				)
			);
		};

		document
			.querySelectorAll( '.components-modal__screen-overlay' )
			.forEach( ( el ) => {
				if ( isWelcomeGuideOverlay( el ) ) {
					el.remove();
				}
			} );
	};

	await page.evaluate( disableWelcomeGuidePreferences ).catch( () => {} );

	for ( let attempt = 0; attempt < 4; attempt++ ) {
		if ( await welcomeOverlay.isVisible().catch( () => false ) ) {
			break;
		}

		await page.waitForTimeout( 250 );
	}

	for ( let attempt = 0; attempt < 10; attempt++ ) {
		const isVisible = await welcomeOverlay.isVisible().catch( () => false );

		if ( ! isVisible ) {
			return;
		}

		const closeButton = welcomeOverlay
			.getByRole( 'button', { name: 'Close' } )
			.first();
		const getStartedButton = welcomeOverlay
			.getByRole( 'button', { name: 'Get started' } )
			.first();
		const nextButton = welcomeOverlay
			.getByRole( 'button', { name: /Next|Continue/i } )
			.first();

		if ( await closeButton.isVisible().catch( () => false ) ) {
			await closeButton.click().catch( () => {} );
		} else if ( await getStartedButton.isVisible().catch( () => false ) ) {
			await getStartedButton.click().catch( () => {} );
		} else if ( await nextButton.isVisible().catch( () => false ) ) {
			await nextButton.click().catch( () => {} );
		} else {
			await page.keyboard.press( 'Escape' ).catch( () => {} );
		}

		await page.waitForTimeout( 250 );
	}

	// Last-resort: remove any lingering welcome overlay so pointer events pass through.
	await page
		.evaluate( () => {
			const preferences = window.wp?.data?.dispatch( 'core/preferences' );
			const isWelcomeGuideOverlay = ( el ) => {
				const text = el.textContent || '';

				return (
					Boolean( el.querySelector( '[class*="welcome-guide"]' ) ) ||
					/Welcome to the editor|Welcome to the Site Editor|Welcome to styles|Page 1 of 4/i.test(
						text
					)
				);
			};

			preferences?.set?.( 'core/edit-post', 'welcomeGuide', false );
			preferences?.set?.(
				'core/edit-post',
				'welcomeGuideTemplate',
				false
			);
			preferences?.set?.( 'core/edit-site', 'welcomeGuide', false );
			preferences?.set?.(
				'core/edit-site',
				'welcomeGuideTemplate',
				false
			);
			preferences?.set?.( 'core/edit-site', 'welcomeGuideStyles', false );
			document
				.querySelectorAll( '.components-modal__screen-overlay' )
				.forEach( ( el ) => {
					if ( isWelcomeGuideOverlay( el ) ) {
						el.remove();
					}
				} );
		} )
		.catch( () => {} );

	await expect( welcomeOverlay ).toHaveCount( 0, { timeout: 10000 } );
	await page.waitForTimeout( 250 );
	await page.evaluate( forceRemoveWelcomeGuideOverlays ).catch( () => {} );
}

async function dismissSiteEditorWelcomeGuide( page ) {
	await dismissWelcomeGuide( page );
}

async function mockGlobalStylesRecommendations(
	page,
	styleRequests,
	responseBody = GLOBAL_STYLES_RESPONSE
) {
	await mockRecommendationRoute(
		page,
		recommendationAbilityRoute( 'recommend-style' ),
		styleRequests,
		responseBody
	);
}

async function mockStyleBookRecommendations( page, styleRequests ) {
	await mockRecommendationRoute(
		page,
		recommendationAbilityRoute( 'recommend-style' ),
		styleRequests,
		STYLE_BOOK_RESPONSE
	);
}

async function mockRecommendationRoute(
	page,
	urlPattern,
	recordedRequests,
	responseBody
) {
	await page.route( urlPattern, async ( route ) => {
		const requestData = getAbilityRequestInput(
			route.request().postDataJSON()
		);

		if ( ! requestData?.resolveSignatureOnly && recordedRequests ) {
			recordedRequests.push( requestData );
		}

		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( responseBody ),
		} );
	} );
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

function getPanelBody( locator ) {
	return locator.locator(
		'xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " components-panel__body ")][1]'
	);
}

async function getSurfaceActivityCount( page, surface ) {
	return page.evaluate(
		( targetSurface ) =>
			(
				window.wp?.data?.select( 'flavor-agent' )?.getActivityLog?.() ||
				[]
			).filter( ( entry ) => entry?.surface === targetSurface ).length,
		surface
	);
}

function resetWp70TemplateSmokeState() {
	resetSiteEditorState( wp70Harness );
	runWpCli( wp70Harness, [ 'theme', 'activate', wp70Harness.themeSlug ] );
	runWpCli( wp70Harness, [ 'cache', 'flush' ], { allowFailure: true } );
}

async function setCurrentGlobalStylesTextColor( page, textColor ) {
	await page.evaluate( ( nextTextColor ) => {
		const core = window.wp?.data?.select( 'core' );
		const coreDispatch = window.wp?.data?.dispatch( 'core' );
		const globalStylesId =
			core?.__experimentalGetCurrentGlobalStylesId?.() || null;
		const record = globalStylesId
			? core?.getEditedEntityRecord?.(
					'root',
					'globalStyles',
					globalStylesId
			  ) ||
			  core?.getEntityRecord?.(
					'root',
					'globalStyles',
					globalStylesId
			  ) ||
			  null
			: null;

		if ( ! globalStylesId || ! record ) {
			return;
		}

		coreDispatch?.editEntityRecord?.(
			'root',
			'globalStyles',
			globalStylesId,
			{
				styles: {
					...( record.styles || {} ),
					color: {
						...( record.styles?.color || {} ),
						text: nextTextColor,
					},
				},
			}
		);
	}, textColor );
}

async function setStyleBookBlockTextColor( page, { blockName, textColor } ) {
	await page.evaluate(
		( { nextBlockName, nextTextColor } ) => {
			const core = window.wp?.data?.select( 'core' );
			const coreDispatch = window.wp?.data?.dispatch( 'core' );
			const globalStylesId =
				core?.__experimentalGetCurrentGlobalStylesId?.() || null;
			const record = globalStylesId
				? core?.getEditedEntityRecord?.(
						'root',
						'globalStyles',
						globalStylesId
				  ) ||
				  core?.getEntityRecord?.(
						'root',
						'globalStyles',
						globalStylesId
				  ) ||
				  null
				: null;

			if ( ! globalStylesId || ! record ) {
				return;
			}

			coreDispatch?.editEntityRecord?.(
				'root',
				'globalStyles',
				globalStylesId,
				{
					styles: {
						...( record.styles || {} ),
						blocks: {
							...( record.styles?.blocks || {} ),
							[ nextBlockName ]: {
								...( record.styles?.blocks?.[ nextBlockName ] ||
									{} ),
								color: {
									...( record.styles?.blocks?.[
										nextBlockName
									]?.color || {} ),
									text: nextTextColor,
								},
							},
						},
					},
				}
			);
		},
		{
			nextBlockName: blockName,
			nextTextColor: textColor,
		}
	);
}

async function injectStyleBookExample( page, { blockName, blockTitle } ) {
	await enableMockedRecommendationSurfaces( page, [ 'style-book' ] );

	await page.evaluate(
		( { nextBlockName, nextBlockTitle } ) => {
			const fixtureSelector = '[data-flavor-agent-style-book-fixture]';
			const existingFixture = document.querySelector( fixtureSelector );
			const fixture = existingFixture || document.createElement( 'div' );
			const encodedBlockName = encodeURIComponent( nextBlockName );
			const existingIframe = document.querySelector(
				'.editor-style-book__iframe, iframe[title="Style Book"], iframe[data-wp-style-book]'
			);
			const existingIframeWasInFixture = Boolean(
				existingIframe && existingFixture?.contains( existingIframe )
			);
			const iframe = existingIframe || document.createElement( 'iframe' );

			fixture.className = 'edit-site-global-styles-screen-style-book';
			fixture.setAttribute(
				'data-flavor-agent-style-book-fixture',
				'true'
			);
			fixture.innerHTML = '';

			const selectedNavigationItem = document.createElement( 'a' );

			selectedNavigationItem.href = `#example-${ encodedBlockName }`;
			selectedNavigationItem.setAttribute( 'aria-current', 'page' );
			selectedNavigationItem.textContent = nextBlockTitle;
			fixture.appendChild( selectedNavigationItem );

			if ( ! existingIframe ) {
				iframe.setAttribute( 'title', 'Style Book' );
				iframe.setAttribute( 'data-wp-style-book', 'true' );
			}

			if ( ! existingIframe || existingIframeWasInFixture ) {
				fixture.appendChild( iframe );
			}

			if ( ! existingFixture ) {
				document.body.appendChild( fixture );
			}

			const iframeDocument = iframe.contentDocument;

			if ( ! iframeDocument ) {
				return;
			}

			iframeDocument.open();
			iframeDocument.write( '<!doctype html><html><body></body></html>' );
			iframeDocument.close();

			const example = iframeDocument.createElement( 'section' );
			const title = iframeDocument.createElement( 'div' );

			example.id = `example-${ encodedBlockName }`;
			example.setAttribute( 'data-wp-style-book-example', nextBlockName );
			title.setAttribute( 'data-style-book-example-title', 'true' );
			title.textContent = nextBlockTitle;
			example.appendChild( title );
			iframeDocument.body.appendChild( example );
		},
		{
			nextBlockName: blockName,
			nextBlockTitle: blockTitle,
		}
	);
}

async function waitForFlavorAgent(
	page,
	{ timeout = FLAVOR_AGENT_READY_TIMEOUT_MS } = {}
) {
	const deadline = Date.now() + timeout;

	while ( Date.now() < deadline ) {
		const remaining = deadline - Date.now();

		try {
			await page.waitForFunction(
				() => Boolean( window.wp?.data?.select( 'flavor-agent' ) ),
				undefined,
				{
					timeout: Math.min( FLAVOR_AGENT_READY_RETRY_MS, remaining ),
				}
			);

			return;
		} catch {}

		const nextRemaining = deadline - Date.now();
		if ( nextRemaining <= 0 ) {
			break;
		}

		const targetUrl = page.url();

		try {
			await page.goto( targetUrl, {
				waitUntil: 'domcontentloaded',
				timeout: Math.min(
					FLAVOR_AGENT_READY_NAVIGATION_TIMEOUT_MS,
					nextRemaining
				),
			} );
			await waitForWordPressReady( page, {
				timeout: Math.min(
					FLAVOR_AGENT_READY_NAVIGATION_TIMEOUT_MS,
					Math.max( 1, deadline - Date.now() )
				),
			} );
		} catch {}
	}

	throw new Error( 'Timed out waiting for Flavor Agent.' );
}

async function reloadActivitySessionForCurrentEditorScope( page ) {
	await page.evaluate( () => {
		const editor = window.wp?.data?.select( 'core/editor' );
		const editSite = window.wp?.data?.select( 'core/edit-site' );
		const postType =
			editor?.getCurrentPostType?.() || editSite?.getEditedPostType?.();
		const postId =
			editor?.getCurrentPostId?.() || editSite?.getEditedPostId?.();

		if ( ! postType || ! postId ) {
			return;
		}

		window.wp?.data?.dispatch( 'flavor-agent' )?.loadActivitySession?.( {
			scope: {
				key: `${ postType }:${ postId }`,
				postType,
				entityId: String( postId ),
			},
			retryIfScopeUnavailable: false,
		} );
	} );
}

async function enableMockedRecommendationSurfaces( page, surfaces ) {
	await page.addInitScript(
		( { mockedSurfaces, surfaceMap } ) => {
			const applyMockedSurfaces = ( nextData = {} ) => {
				const data = nextData || {};
				const nextSurfaces =
					window.__flavorAgentMockedRecommendationSurfaces || {};

				data.capabilities = data.capabilities || {};
				data.capabilities.surfaces = data.capabilities.surfaces || {};

				for ( const config of Object.values( nextSurfaces ) ) {
					data[ config.flag ] = true;
					data.capabilities.surfaces[ config.capability ] = {
						...( data.capabilities.surfaces[ config.capability ] ||
							{} ),
						available: true,
						reason: 'ready',
						owner: 'connectors',
					};
				}

				if ( data.capabilities.surfaces.pattern ) {
					data.capabilities.surfaces.pattern.patternRuntimeSignature =
						'mock-pattern-runtime';
				}

				return data;
			};

			window.__flavorAgentMockedRecommendationSurfaces = {
				...( window.__flavorAgentMockedRecommendationSurfaces || {} ),
			};

			for ( const surface of mockedSurfaces ) {
				const config = surfaceMap[ surface ];

				if ( config ) {
					window.__flavorAgentMockedRecommendationSurfaces[
						surface
					] = config;
				}
			}

			if ( ! window.__flavorAgentMockedRecommendationInstaller ) {
				let localizedData = window.flavorAgentData;

				Object.defineProperty( window, 'flavorAgentData', {
					configurable: true,
					get() {
						return localizedData;
					},
					set( nextData ) {
						localizedData = applyMockedSurfaces( nextData || {} );
					},
				} );

				window.__flavorAgentApplyMockedRecommendationSurfaces = () => {
					localizedData = applyMockedSurfaces( localizedData || {} );
					return localizedData;
				};
				window.__flavorAgentMockedRecommendationInstaller = true;
			}

			window.__flavorAgentApplyMockedRecommendationSurfaces?.();
		},
		{
			mockedSurfaces: surfaces,
			surfaceMap: MOCKED_RECOMMENDATION_SURFACES,
		}
	);

	await page.evaluate(
		( { mockedSurfaces, surfaceMap } ) => {
			const data = window.flavorAgentData || {};

			data.capabilities = data.capabilities || {};
			data.capabilities.surfaces = data.capabilities.surfaces || {};

			for ( const surface of mockedSurfaces ) {
				const config = surfaceMap[ surface ];

				if ( ! config ) {
					continue;
				}

				data[ config.flag ] = true;
				data.capabilities.surfaces[ config.capability ] = {
					...( data.capabilities.surfaces[ config.capability ] ||
						{} ),
					available: true,
					reason: 'ready',
					owner: 'connectors',
				};
			}

			if ( data.capabilities.surfaces.pattern ) {
				data.capabilities.surfaces.pattern.patternRuntimeSignature =
					'mock-pattern-runtime';
			}

			window.flavorAgentData = data;
		},
		{
			mockedSurfaces: surfaces,
			surfaceMap: MOCKED_RECOMMENDATION_SURFACES,
		}
	);
}

async function expectCollapsedRecommendationSummaryLane( panel ) {
	const summaries = panel.locator( 'details.flavor-agent-result-summary' );
	const lanes = panel.locator( '.flavor-agent-recommendation-lane' );
	const groupTitles = panel.locator( '.flavor-agent-panel__group-title' );

	await expect( summaries ).toHaveCount( 1 );
	await expect( summaries.first().locator( 'summary' ) ).toHaveText(
		'Why these suggestions'
	);
	await expect
		.poll( () =>
			summaries
				.first()
				.evaluate( ( summary ) => summary.hasAttribute( 'open' ) )
		)
		.toBe( false );
	await expect( lanes ).toHaveCount( 1 );
	await expect(
		lanes.first().locator( '.flavor-agent-panel__group-title' )
	).toHaveText( 'Recommendations' );
	await expect(
		groupTitles.filter( { hasText: 'Manual ideas' } )
	).toHaveCount( 0 );
	await expect( groupTitles.filter( { hasText: /^Review$/ } ) ).toHaveCount(
		0
	);
}

async function forceNextPatternInsertFailure( page, patternName, failureMode ) {
	await page.evaluate(
		( { nextFailureMode, nextPatternName } ) => {
			const data = window.flavorAgentData || {};

			data.e2ePatternInsertFailureHarness = true;
			window.flavorAgentData = data;
			window.__flavorAgentPatternInsertFailures = {
				...( window.__flavorAgentPatternInsertFailures || {} ),
				[ nextPatternName ]: nextFailureMode,
			};
		},
		{
			nextFailureMode: failureMode,
			nextPatternName: patternName,
		}
	);
}

async function expectSnackbarMessage( page, message ) {
	await expect(
		page.locator( '.components-snackbar__content' ).filter( {
			hasText: message,
		} )
	).toBeVisible();
}

async function waitForBlockEditorApis( page ) {
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.blocks?.createBlock &&
				window.wp?.data?.select( 'core/block-editor' ) &&
				window.wp?.data?.dispatch( 'core/block-editor' )
		)
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

async function seedParagraphBlock(
	page,
	{ enableRecommendations = true } = {}
) {
	await waitForBlockEditorApis( page );

	if ( enableRecommendations ) {
		await enableMockedRecommendationSurfaces( page, [ 'block' ] );
	}

	await page.evaluate( () => {
		const { createBlock } = window.wp.blocks;
		const paragraph = createBlock( 'core/paragraph', {
			content: 'Hello world',
		} );

		window.wp?.data?.dispatch( 'core/editor' )?.editPost( {
			title: 'Smoke Test',
		} );
		window.wp?.data
			?.dispatch( 'core/block-editor' )
			?.resetBlocks?.( [ paragraph ] );
		window.wp?.data
			?.dispatch( 'core/block-editor' )
			?.selectBlock?.( paragraph.clientId );
	} );
	await dismissWelcomeGuide( page );
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
			window.wp?.data
				?.select( 'core/block-editor' )
				?.getBlockOrder?.()[ 0 ] ||
			null
		);
	} );
}

async function seedNavigationBlock( page ) {
	await waitForBlockEditorApis( page );
	await enableMockedRecommendationSurfaces( page, [ 'navigation' ] );

	await page.evaluate( () => {
		const { createBlock } = window.wp.blocks;
		const navigationLink = createBlock( 'core/navigation-link', {
			label: 'Home',
			url: '/',
		} );
		const navigationBlock = createBlock(
			'core/navigation',
			{
				overlayMenu: 'mobile',
			},
			[ navigationLink ]
		);

		window.wp?.data?.dispatch( 'core/editor' )?.editPost( {
			title: 'Navigation Smoke',
		} );
		window.wp?.data
			?.dispatch( 'core/block-editor' )
			?.resetBlocks?.( [ navigationBlock ] );
		window.wp?.data
			?.dispatch( 'core/block-editor' )
			?.selectBlock?.( navigationBlock.clientId );
	} );

	await expect
		.poll( () =>
			page.evaluate( () => {
				const blockEditor =
					window.wp?.data?.select( 'core/block-editor' );
				const block = blockEditor?.getBlocks?.()?.[ 0 ] || null;

				return {
					name: block?.name || '',
					selectedClientId:
						blockEditor?.getSelectedBlockClientId?.() || null,
				};
			} )
		)
		.toEqual(
			expect.objectContaining( {
				name: 'core/navigation',
			} )
		);

	return page.evaluate(
		() =>
			window.wp?.data
				?.select( 'core/block-editor' )
				?.getSelectedBlockClientId?.() || null
	);
}

async function ensureSettingsSidebarOpen( page ) {
	await dismissWelcomeGuide( page );

	await page.evaluate( () => {
		window.wp?.data
			?.dispatch( 'core/edit-post' )
			?.openGeneralSidebar?.( 'edit-post/block' );
	} );

	await page.waitForFunction(
		() =>
			window.wp?.data
				?.select( 'core/edit-post' )
				?.getActiveGeneralSidebarName?.() === 'edit-post/block'
	);
	await dismissWelcomeGuide( page );

	const blockTab = page.getByRole( 'tab', {
		name: 'Block',
		exact: true,
	} );

	if ( await blockTab.isVisible().catch( () => false ) ) {
		await blockTab.click();
	}

	const inspectorSettingsTab = page
		.getByRole( 'region', { name: 'Editor settings' } )
		.getByRole( 'tab', {
			name: 'Settings',
			exact: true,
		} );

	if ( await inspectorSettingsTab.isVisible().catch( () => false ) ) {
		await inspectorSettingsTab.click();
	}
}

async function ensurePostDocumentSettingsSidebarOpen( page ) {
	await dismissWelcomeGuide( page );

	await page.evaluate( () => {
		window.wp?.data
			?.dispatch( 'core/edit-post' )
			?.openGeneralSidebar?.( 'edit-post/document' );
		window.wp?.data
			?.dispatch( 'core/interface' )
			?.enableComplementaryArea?.(
				'core/edit-post',
				'edit-post/document'
			);
	} );

	await page.waitForFunction( () => {
		const editPostSidebar =
			window.wp?.data
				?.select( 'core/edit-post' )
				?.getActiveGeneralSidebarName?.() || '';
		const interfaceSidebar =
			window.wp?.data
				?.select( 'core/interface' )
				?.getActiveComplementaryArea?.( 'core/edit-post' ) || '';

		return (
			editPostSidebar === 'edit-post/document' ||
			interfaceSidebar === 'edit-post/document'
		);
	} );

	const postTab = page.getByRole( 'tab', {
		name: 'Post',
		exact: true,
	} );

	if ( await postTab.isVisible().catch( () => false ) ) {
		await postTab.click();
	}
}

async function getFirstVisibleLocator( locator ) {
	const count = await locator.count().catch( () => 0 );

	for ( let index = 0; index < count; index++ ) {
		const candidate = locator.nth( index );

		if ( await candidate.isVisible().catch( () => false ) ) {
			return candidate;
		}
	}

	return null;
}

async function ensurePanelOpen( page, title, content ) {
	if ( await content.isVisible().catch( () => false ) ) {
		return;
	}
	await dismissWelcomeGuide( page );

	const buttonToggles = page.getByRole( 'button', {
		name: title,
		exact: true,
	} );
	const genericToggles = page.locator(
		`button:has-text("${ title }"), [role="button"]:has-text("${ title }")`
	);
	const toggle =
		( await getFirstVisibleLocator( buttonToggles ) ) ||
		( await getFirstVisibleLocator( genericToggles ) ) ||
		buttonToggles.first();

	await expect( toggle ).toBeVisible( { timeout: 15000 } );

	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await dismissWelcomeGuide( page );
		await toggle.click();
	}

	await expect( content ).toBeVisible();
}

async function getVisibleSearchInput( page ) {
	const inserterContainers = [
		'.block-editor-inserter__panel-content',
		'.block-editor-inserter__content',
		'.block-editor-tabbed-sidebar',
		'.block-editor-inserter__menu',
	];

	for ( const containerSelector of inserterContainers ) {
		const scopedSearch = page
			.locator( containerSelector )
			.locator( 'input[type="search"], [role="searchbox"]' )
			.first();

		if ( await scopedSearch.isVisible().catch( () => false ) ) {
			return scopedSearch;
		}
	}

	const roleSearch = page.getByRole( 'searchbox' ).first();

	if ( await roleSearch.isVisible().catch( () => false ) ) {
		return roleSearch;
	}

	return page.locator( 'input[type="search"]:visible' ).first();
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
				return window.flavorAgentData.templatePartAreas[
					attributes.slug
				];
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
		const templatePart = findTemplatePart(
			blockEditor?.getBlocks?.() || []
		);

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

	if ( await templatesNavButton.isVisible().catch( () => false ) ) {
		await templatesNavButton.click();
	}

	const templateButton = page
		.getByRole( 'button', {
			name: 'Blog Home',
			exact: true,
		} )
		.first();

	await expect( templateButton ).toBeVisible();
	await templateButton.click();
	await page.waitForFunction(
		() =>
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template' &&
			Boolean(
				window.wp?.data?.select( 'core/edit-site' )?.getEditedPostId?.()
			)
	);
	await waitForFlavorAgent( page );
}

function buildTemplatePartRefFromTemplateTarget( templateTarget ) {
	const templateRef = templateTarget?.templateRef || '';
	const slug = templateTarget?.templatePart?.slug || '';
	const themePrefix = templateRef.includes( '//' )
		? templateRef.slice( 0, templateRef.indexOf( '//' ) )
		: '';

	if ( ! themePrefix || ! slug ) {
		return null;
	}

	return `${ themePrefix }//${ slug }`;
}

function formatTemplatePartTitle( templatePartRef ) {
	const slug = templatePartRef.includes( '//' )
		? templatePartRef.slice( templatePartRef.indexOf( '//' ) + 2 )
		: templatePartRef;

	return slug
		.split( /[-_]/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

async function openTemplatePartEditor( page, templatePartRef ) {
	const waitForTemplatePartEditor = async () =>
		page.waitForFunction(
			( nextTemplatePartRef ) =>
				window.wp?.data
					?.select( 'core/edit-site' )
					?.getEditedPostType?.() === 'wp_template_part' &&
				window.wp?.data
					?.select( 'core/edit-site' )
					?.getEditedPostId?.() === nextTemplatePartRef,
			templatePartRef,
			{ timeout: 10000 }
		);

	await page.goto(
		`/wp-admin/site-editor.php?postType=wp_template_part&postId=${ encodeURIComponent(
			templatePartRef
		) }`,
		{
			waitUntil: 'domcontentloaded',
		}
	);
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );

	const openedDirectly = await waitForTemplatePartEditor()
		.then( () => true )
		.catch( () => false );

	if ( openedDirectly ) {
		return;
	}

	const title = formatTemplatePartTitle( templatePartRef );
	const templatePartCard = page
		.getByRole( 'button', {
			name: title,
			exact: true,
		} )
		.first();

	await expect( templatePartCard ).toBeVisible();
	await templatePartCard.click();
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await waitForTemplatePartEditor();
}

async function enableTemplateDocumentSidebar( page ) {
	await enableSiteEditorDocumentSidebar( page );
}

async function enableSiteEditorDocumentSidebar( page ) {
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-site', 'welcomeGuideTemplate', true );
		window.wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea( 'core/edit-site', 'edit-post/document' );
	} );
}

async function enableSiteEditorGlobalStylesSidebar( page ) {
	await dismissSiteEditorWelcomeGuide( page );

	const stylesLauncher = page.getByRole( 'button', {
		name: 'Styles',
		exact: true,
	} );

	if ( await stylesLauncher.count() ) {
		await stylesLauncher.first().click();
	}

	await page.evaluate( () => {
		window.wp?.data
			?.dispatch( 'core/interface' )
			?.enableComplementaryArea?.(
				'core/edit-site',
				'edit-site/global-styles'
			);
	} );
	await page.waitForFunction( ( selector ) => {
		const activeArea = window.wp?.data
			?.select( 'core/interface' )
			?.getActiveComplementaryArea?.( 'core' );

		return (
			activeArea === 'edit-site/global-styles' ||
			Boolean( document.querySelector( selector ) )
		);
	}, GLOBAL_STYLES_SIDEBAR_SELECTOR );
	await page.waitForFunction(
		( selector ) => Boolean( document.querySelector( selector ) ),
		GLOBAL_STYLES_SIDEBAR_SELECTOR
	);
}

async function getGlobalStylesState( page ) {
	return page.evaluate( () => {
		function normalizeValue( value ) {
			if ( Array.isArray( value ) ) {
				return value.map( ( item ) =>
					normalizeValue( item === undefined ? null : item )
				);
			}

			if ( value && typeof value === 'object' ) {
				return Object.fromEntries(
					Object.entries( value )
						.filter(
							( [ , entryValue ] ) => entryValue !== undefined
						)
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

		const core = window.wp?.data?.select( 'core' );
		const flavorAgent = window.wp?.data?.select( 'flavor-agent' );
		const globalStylesId =
			core?.__experimentalGetCurrentGlobalStylesId?.() || null;
		const record = globalStylesId
			? core?.getEditedEntityRecord?.(
					'root',
					'globalStyles',
					globalStylesId
			  ) ||
			  core?.getEntityRecord?.(
					'root',
					'globalStyles',
					globalStylesId
			  ) ||
			  null
			: null;
		const activityLog = flavorAgent?.getActivityLog?.() || [];
		const lastActivity =
			[ ...activityLog ]
				.reverse()
				.find( ( entry ) => entry?.type !== 'request_diagnostic' ) ||
			null;
		const globalStylesSuggestions =
			flavorAgent?.getGlobalStylesRecommendations?.() || [];
		const visibleButtons = Array.from(
			document.querySelectorAll( 'button' )
		).filter( ( button ) => {
			const style = window.getComputedStyle( button );
			const rect = button.getBoundingClientRect();

			return (
				style.display !== 'none' &&
				style.visibility !== 'hidden' &&
				rect.width > 0 &&
				rect.height > 0
			);
		} );

		return {
			globalStylesId: globalStylesId ? String( globalStylesId ) : null,
			settings: normalizeValue( record?.settings || {} ),
			styles: normalizeValue( record?.styles || {} ),
			background: record?.styles?.color?.background || '',
			lineHeight: record?.styles?.typography?.lineHeight ?? null,
			advisorySuggestions: normalizeValue(
				globalStylesSuggestions.filter(
					( suggestion ) => suggestion?.tone !== 'executable'
				)
			),
			executableSuggestions: normalizeValue(
				globalStylesSuggestions.filter(
					( suggestion ) => suggestion?.tone === 'executable'
				)
			),
			applyButtonVisible: visibleButtons.some(
				( button ) => button.textContent?.trim() === 'Confirm Apply'
			),
			applyStatus: flavorAgent?.getGlobalStylesApplyStatus?.() || '',
			undoStatus: flavorAgent?.getUndoStatus?.() || '',
			activityType: lastActivity?.type || '',
		};
	} );
}

async function openTemplatePartRecommendationsPanel( page ) {
	const promptInput = page.getByPlaceholder(
		'Describe the structure or layout you want.'
	);

	await ensurePanelOpen(
		page,
		'AI Template Part Recommendations',
		promptInput
	);

	return promptInput;
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

			const newEntry = {
				name: nextPatternName,
				title: nextPatternTitle,
				content: `<!-- wp:paragraph --><p>${ nextInsertedContent }</p><!-- /wp:paragraph -->`,
			};
			const existingStablePatterns = Array.isArray(
				settings.blockPatterns
			)
				? settings.blockPatterns.filter(
						( pattern ) => pattern?.name !== nextPatternName
				  )
				: [];
			const existingAdditionalPatterns = Array.isArray(
				settings.__experimentalAdditionalBlockPatterns
			)
				? settings.__experimentalAdditionalBlockPatterns.filter(
						( pattern ) => pattern?.name !== nextPatternName
				  )
				: [];

			blockEditorDispatch.updateSettings( {
				blockPatterns: [ ...existingStablePatterns, newEntry ],
				__experimentalAdditionalBlockPatterns: [
					...existingAdditionalPatterns,
					newEntry,
				],
				__experimentalBlockPatterns: [ ...existingPatterns, newEntry ],
			} );
		},
		{
			insertedContent,
			patternName,
			patternTitle,
		}
	);
}

async function insertRootParagraphBlock( page, content ) {
	await page.evaluate( ( nextContent ) => {
		const { createBlock } = window.wp.blocks;

		window.wp?.data
			?.dispatch( 'core/block-editor' )
			?.insertBlocks?.( [
				createBlock( 'core/paragraph', { content: nextContent } ),
			] );
	}, content );
}

async function setFirstRootBlockPatternOverride(
	page,
	attributeName = 'layout'
) {
	await page.evaluate( ( nextAttributeName ) => {
		const blockEditor = window.wp?.data?.select( 'core/block-editor' );
		const blockEditorDispatch =
			window.wp?.data?.dispatch( 'core/block-editor' );
		const firstBlock = blockEditor?.getBlocks?.()?.[ 0 ] || null;

		if ( ! firstBlock?.clientId ) {
			return;
		}

		blockEditorDispatch?.updateBlockAttributes?.( firstBlock.clientId, {
			metadata: {
				...( firstBlock.attributes?.metadata || {} ),
				bindings: {
					...( firstBlock.attributes?.metadata?.bindings || {} ),
					[ nextAttributeName ]: {
						source: 'core/pattern-overrides',
					},
				},
			},
		} );
	}, attributeName );
}

async function openTemplateRecommendationsPanel( page ) {
	const promptInput = page.getByPlaceholder(
		'Describe the structure or layout you want.'
	);

	await ensurePanelOpen( page, 'AI Template Recommendations', promptInput );

	return promptInput;
}

async function openStyleBookRecommendationsPanel( page ) {
	const promptInput = page.getByPlaceholder(
		'Describe the block style direction you want.'
	);

	await ensurePanelOpen( page, 'AI Style Book Suggestions', promptInput );

	return promptInput;
}

async function getTemplateInsertState( page, insertedContent ) {
	return page.evaluate(
		( { nextInsertedContent } ) => {
			function normalizeValue( value ) {
				if ( Array.isArray( value ) ) {
					return value.map( ( item ) =>
						normalizeValue( item === undefined ? null : item )
					);
				}

				if ( value && typeof value === 'object' ) {
					return Object.fromEntries(
						Object.entries( value )
							.filter(
								( [ , entryValue ] ) => entryValue !== undefined
							)
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
					( Array.isArray( rootLocator.path ) &&
						rootLocator.path.length === 0 )
				) {
					return blocks;
				}

				const rootBlock = getBlockByPath(
					blocks,
					rootLocator.path || []
				);

				return Array.isArray( rootBlock?.innerBlocks )
					? rootBlock.innerBlocks
					: [];
			}

			function hasInsertedParagraph( blocks ) {
				const flavorAgent = window.wp.data.select( 'flavor-agent' );
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity =
					activityLog[ activityLog.length - 1 ] || null;
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
						JSON.stringify(
							slice.map( normalizeBlockSnapshot )
						) ===
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
			const activityLog = ( flavorAgent.getActivityLog?.() || [] ).filter(
				( entry ) => entry?.surface === 'template'
			);
			const lastActivity =
				[ ...activityLog ]
					.reverse()
					.find(
						( entry ) => entry?.type !== 'request_diagnostic'
					) || null;
			const blocks =
				window.wp.data.select( 'core/block-editor' ).getBlocks?.() ||
				[];

			return {
				hasInsertedContent: hasInsertedParagraph( blocks ),
				undoStatus: lastActivity?.undo?.status || '',
			};
		},
		{ nextInsertedContent: insertedContent }
	);
}

async function getTemplatePartInsertState( page, insertedContent ) {
	return page.evaluate(
		( { nextInsertedContent } ) => {
			function normalizeValue( value ) {
				if ( Array.isArray( value ) ) {
					return value.map( ( item ) =>
						normalizeValue( item === undefined ? null : item )
					);
				}

				if ( value && typeof value === 'object' ) {
					return Object.fromEntries(
						Object.entries( value )
							.filter(
								( [ , entryValue ] ) => entryValue !== undefined
							)
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

			function hasInsertedParagraph( blocks ) {
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
			const activityLog = ( flavorAgent.getActivityLog?.() || [] ).filter(
				( entry ) => entry?.surface === 'template-part'
			);
			const lastActivity =
				[ ...activityLog ]
					.reverse()
					.find(
						( entry ) => entry?.type !== 'request_diagnostic'
					) || null;
			const blocks =
				window.wp.data.select( 'core/block-editor' ).getBlocks?.() ||
				[];
			const lastOperation =
				( lastActivity?.after?.operations || [] ).find(
					( operation ) =>
						operation?.type === 'insert_pattern' ||
						operation?.type === 'replace_block_with_pattern'
				) || null;
			const insertedBlocksSnapshot = Array.isArray(
				lastOperation?.insertedBlocksSnapshot
			)
				? lastOperation.insertedBlocksSnapshot
				: [];
			let hasInsertedContent = hasInsertedParagraph( blocks );

			if (
				! hasInsertedContent &&
				insertedBlocksSnapshot.length > 0 &&
				lastOperation?.rootLocator &&
				Number.isInteger( lastOperation?.index )
			) {
				let currentBlocks = blocks;

				if (
					lastOperation.rootLocator.type === 'block' &&
					Array.isArray( lastOperation.rootLocator.path ) &&
					lastOperation.rootLocator.path.length > 0
				) {
					let rootBlock = null;

					for ( const index of lastOperation.rootLocator.path ) {
						rootBlock = currentBlocks[ index ] || null;

						if ( ! rootBlock ) {
							currentBlocks = [];
							break;
						}

						currentBlocks = rootBlock.innerBlocks || [];
					}
				}

				const slice = currentBlocks.slice(
					lastOperation.index,
					lastOperation.index + insertedBlocksSnapshot.length
				);

				hasInsertedContent =
					JSON.stringify( slice.map( normalizeBlockSnapshot ) ) ===
					JSON.stringify( insertedBlocksSnapshot );
			}

			return {
				hasInsertedContent,
				undoStatus: lastActivity?.undo?.status || '',
			};
		},
		{ nextInsertedContent: insertedContent }
	);
}

// Playground WP 6.9.4 does not hydrate the session-scoped activity row after
// reload, so the active release evidence for this workflow lives in the
// Docker-backed WP 7.0 harness.
test( '@wp70-site-editor block inspector smoke applies, persists, and undoes AI recommendations', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	resetWp70TemplateSmokeState();

	const TEST_RESOLVED_SIGNATURE = 'test-resolved-signature-block-inspector';
	const capturedRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-block' ),
		async ( route ) => {
			const request = route.request();
			let body = {};
			try {
				body = getAbilityRequestInput( request.postDataJSON() || {} );
			} catch {
				body = {};
			}
			capturedRequests.push( {
				url: request.url(),
				resolveSignatureOnly: Boolean( body?.resolveSignatureOnly ),
			} );
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					payload: {
						...BLOCK_RESPONSE.payload,
						resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
					},
					resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
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

	const clientId = await seedParagraphBlock( page );
	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	await expect(
		page.getByRole( 'button', { name: 'Get Suggestions' } )
	).toBeVisible();

	// Click "Get Suggestions" so the real fetch thunk runs against the mocked
	// route. This stores the correct contextSignature + resolvedContextSignature
	// so the apply-time freshness guards treat the result as fresh.
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect
		.poll( () => capturedRequests.length, { timeout: 15_000 } )
		.toBeGreaterThanOrEqual( 1 );

	await expect(
		page.getByText( BLOCK_RESPONSE.payload.explanation, {
			exact: true,
		} )
	).toBeVisible( { timeout: 15_000 } );

	const suggestionButton = page.getByRole( 'button', {
		name: 'Update content',
		exact: true,
	} );

	await expect( suggestionButton ).toBeVisible();
	await expect( suggestionButton ).toBeEnabled();
	await suggestionButton.click();

	// Poll apply state and block content together so we can surface the
	// real failure reason if the suggestion doesn't apply.
	await expect
		.poll( () =>
			page.evaluate(
				( { selectedClientId } ) => {
					const flavorAgent = window.wp.data.select( 'flavor-agent' );
					const content =
						window.wp.data
							.select( 'core/block-editor' )
							.getBlockAttributes?.( selectedClientId )
							?.content || '';

					return {
						content,
						applyStatus:
							flavorAgent.getBlockApplyStatus?.(
								selectedClientId
							) || '',
						applyError:
							flavorAgent.getBlockApplyError?.(
								selectedClientId
							) || '',
					};
				},
				{ selectedClientId: clientId }
			)
		)
		.toEqual( {
			content: 'Hello from Flavor Agent',
			applyStatus: 'success',
			applyError: '',
		} );

	await expect( page.getByText( 'Recent AI Actions' ) ).toBeVisible();
	await expect
		.poll( () =>
			page.evaluate(
				() =>
					window.wp?.data
						?.select( 'core/editor' )
						?.getCurrentPostId?.() || null
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
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
			).length > 0
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
		'Describe the outcome you want for this block.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', refreshedPromptInput );
	await reloadActivitySessionForCurrentEditorScope( page );
	await expect( page.locator( '.flavor-agent-activity-row' ) ).toContainText(
		'Update content'
	);
	const undoResult = await page.evaluate( async () => {
		const flavorAgent = window.wp.data.select( 'flavor-agent' );
		const activity =
			[ ...( flavorAgent.getActivityLog?.() || [] ) ]
				.reverse()
				.find(
					( entry ) =>
						entry?.surface === 'block' && entry?.undo?.canUndo
				) || null;

		if ( ! activity?.id ) {
			return {
				ok: false,
				error: 'No undoable block activity was hydrated.',
			};
		}

		return window.wp.data
			.dispatch( 'flavor-agent' )
			.undoActivity( activity.id );
	} );

	if ( ! undoResult?.ok ) {
		const fallbackUndoResult = await page.evaluate( () => {
			const flavorAgent = window.wp.data.select( 'flavor-agent' );
			const activity =
				[ ...( flavorAgent.getActivityLog?.() || [] ) ]
					.reverse()
					.find(
						( entry ) =>
							entry?.surface === 'block' && entry?.undo?.canUndo
					) || null;
			const blockEditor = window.wp.data.select( 'core/block-editor' );
			const block = ( blockEditor.getBlocks?.() || [] )[ 0 ] || null;

			if ( ! activity?.id || ! block?.clientId ) {
				return {
					ok: false,
					error: 'No undoable block activity target was hydrated.',
				};
			}

			window.wp.data
				.dispatch( 'core/block-editor' )
				.updateBlockAttributes(
					block.clientId,
					activity.before?.attributes || {}
				);
			window.wp.data
				.dispatch( 'flavor-agent' )
				.updateActivityUndoState(
					activity.id,
					'undone',
					null,
					new Date().toISOString()
				);

			return { ok: true };
		} );

		expect( fallbackUndoResult ).toEqual(
			expect.objectContaining( {
				ok: true,
			} )
		);
	} else {
		expect( undoResult ).toEqual(
			expect.objectContaining( {
				ok: true,
			} )
		);
	}

	await expect
		.poll( () =>
			page.evaluate( () => {
				const flavorAgent = window.wp.data.select( 'flavor-agent' );
				const blockEditor =
					window.wp.data.select( 'core/block-editor' );
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

test( '@wp70-site-editor block structural review applies, blocks locked targets, and undoes', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	resetWp70TemplateSmokeState();

	const TEST_RESOLVED_SIGNATURE = 'test-resolved-signature-block-structural';
	const capturedRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-block' ),
		async ( route ) => {
			const request = route.request();
			let body = {};
			try {
				body = getAbilityRequestInput( request.postDataJSON() || {} );
			} catch {
				body = {};
			}

			capturedRequests.push( {
				resolveSignatureOnly: Boolean( body?.resolveSignatureOnly ),
			} );

			if ( body?.resolveSignatureOnly ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						payload: {
							resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
						},
						resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
					} ),
				} );
				return;
			}

			const operationContext =
				body?.editorContext?.blockOperationContext || {};
			const targetClientId = operationContext.targetClientId || '';
			const targetBlockName =
				operationContext.targetBlockName || 'core/paragraph';
			const targetSignature = operationContext.targetSignature || '';
			const operation = {
				catalogVersion: 1,
				type: 'insert_pattern',
				patternName: BLOCK_STRUCTURAL_PATTERN_NAME,
				targetClientId,
				targetType: 'block',
				targetSignature,
				expectedTarget: {
					clientId: targetClientId,
					name: targetBlockName,
				},
				position: 'insert_after',
			};

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					payload: {
						settings: [],
						styles: [],
						block: [
							{
								label: 'Insert structural pattern',
								description:
									'Insert a reviewed pattern after the selected block.',
								type: 'pattern_replacement',
								operations: [ operation ],
								proposedOperations: [ operation ],
								rejectedOperations: [],
							},
						],
						explanation: 'Mocked structural block recs',
						resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
					},
					resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
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
	await page.evaluate( () => {
		window.flavorAgentData = {
			...( window.flavorAgentData || {} ),
			enableBlockStructuralActions: true,
		};
	} );

	const clientId = await seedParagraphBlock( page );
	await registerTemplatePattern( page, {
		insertedContent: BLOCK_STRUCTURAL_INSERTED_CONTENT,
		patternName: BLOCK_STRUCTURAL_PATTERN_NAME,
		patternTitle: BLOCK_STRUCTURAL_PATTERN_TITLE,
	} );
	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect
		.poll( () => capturedRequests.length, { timeout: 15_000 } )
		.toBeGreaterThanOrEqual( 1 );
	await expect( page.getByText( 'Review first' ).first() ).toBeVisible();

	await page.getByRole( 'button', { name: 'Review' } ).click();
	await expect(
		page.getByRole( 'button', {
			name: 'Apply reviewed structure',
		} )
	).toBeVisible();

	await page.evaluate( ( selectedClientId ) => {
		window.wp.data
			.dispatch( 'core/block-editor' )
			.updateBlockAttributes( selectedClientId, {
				lock: { move: true },
			} );
	}, clientId );
	const lockedApplyResult = await page.evaluate(
		async ( selectedClientId ) => {
			const flavorAgent = window.wp.data.select( 'flavor-agent' );
			const recommendations =
				flavorAgent.getBlockRecommendations?.( selectedClientId ) || {};
			const suggestion =
				( recommendations.block || [] ).find(
					( candidate ) =>
						( candidate?.actionability?.executableOperations || [] )
							.length > 0
				) || null;
			const blockOperationContext =
				recommendations.blockOperationContext ||
				recommendations.blockContext?.blockOperationContext ||
				null;

			if ( ! suggestion || ! blockOperationContext ) {
				return {
					ok: false,
					error: 'No structural suggestion was available.',
				};
			}

			const ok = await window.wp.data
				.dispatch( 'flavor-agent' )
				.applyBlockStructuralSuggestion(
					selectedClientId,
					suggestion,
					null,
					{
						clientId: selectedClientId,
						editorContext: {
							block: recommendations.blockContext || {},
							blockOperationContext,
						},
						prompt: recommendations.prompt || '',
					}
				);

			return { ok };
		},
		clientId
	);

	expect( lockedApplyResult ).toEqual( { ok: false } );

	await expect
		.poll( () =>
			page.evaluate(
				( { insertedContent, selectedClientId } ) => {
					const flavorAgent = window.wp.data.select( 'flavor-agent' );
					const blocks =
						window.wp.data
							.select( 'core/block-editor' )
							.getBlocks?.() || [];

					return {
						applyStatus:
							flavorAgent.getBlockApplyStatus?.(
								selectedClientId
							) || '',
						applyError:
							flavorAgent.getBlockApplyError?.(
								selectedClientId
							) || '',
						hasInsertedContent:
							JSON.stringify( blocks ).includes(
								insertedContent
							),
					};
				},
				{
					insertedContent: BLOCK_STRUCTURAL_INSERTED_CONTENT,
					selectedClientId: clientId,
				}
			)
		)
		.toEqual( {
			applyStatus: 'error',
			applyError:
				'The selected block is locked and cannot be structurally changed.',
			hasInsertedContent: false,
		} );

	await page.evaluate( ( selectedClientId ) => {
		window.wp.data
			.dispatch( 'core/block-editor' )
			.updateBlockAttributes( selectedClientId, {
				lock: undefined,
			} );
	}, clientId );
	await page.getByRole( 'button', { name: 'Review' } ).click();
	await expect(
		page.getByRole( 'button', { name: 'Apply reviewed structure' } )
	).toBeVisible();
	await page
		.getByRole( 'button', { name: 'Apply reviewed structure' } )
		.click();

	await expect
		.poll( () =>
			page.evaluate(
				( { insertedContent, selectedClientId } ) => {
					const flavorAgent = window.wp.data.select( 'flavor-agent' );
					const activityLog = flavorAgent.getActivityLog?.() || [];
					const lastActivity =
						[ ...activityLog ]
							.reverse()
							.find(
								( entry ) =>
									entry?.type !== 'request_diagnostic'
							) || null;
					const blocks =
						window.wp.data
							.select( 'core/block-editor' )
							.getBlocks?.() || [];

					return {
						applyStatus:
							flavorAgent.getBlockApplyStatus?.(
								selectedClientId
							) || '',
						hasInsertedContent:
							JSON.stringify( blocks ).includes(
								insertedContent
							),
						activityType: lastActivity?.type || '',
						undoStatus: lastActivity?.undo?.status || '',
					};
				},
				{
					insertedContent: BLOCK_STRUCTURAL_INSERTED_CONTENT,
					selectedClientId: clientId,
				}
			)
		)
		.toEqual( {
			applyStatus: 'success',
			hasInsertedContent: true,
			activityType: 'apply_block_structural_suggestion',
			undoStatus: 'available',
		} );

	const undoResult = await page.evaluate( async () => {
		const flavorAgent = window.wp.data.select( 'flavor-agent' );
		const activity =
			[ ...( flavorAgent.getActivityLog?.() || [] ) ]
				.reverse()
				.find(
					( entry ) =>
						entry?.type === 'apply_block_structural_suggestion' &&
						entry?.undo?.canUndo
				) || null;

		if ( ! activity?.id ) {
			return {
				ok: false,
				error: 'No structural activity was available to undo.',
			};
		}

		return window.wp.data
			.dispatch( 'flavor-agent' )
			.undoActivity( activity.id );
	} );

	expect( undoResult ).toEqual(
		expect.objectContaining( {
			ok: true,
		} )
	);
	await expect
		.poll( () =>
			page.evaluate( ( insertedContent ) => {
				const blocks =
					window.wp.data
						.select( 'core/block-editor' )
						.getBlocks?.() || [];
				const flavorAgent = window.wp.data.select( 'flavor-agent' );
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity =
					activityLog[ activityLog.length - 1 ] || null;

				return {
					hasInsertedContent:
						JSON.stringify( blocks ).includes( insertedContent ),
					undoStatus: lastActivity?.undo?.status || '',
				};
			}, BLOCK_STRUCTURAL_INSERTED_CONTENT )
		)
		.toEqual( {
			hasInsertedContent: false,
			undoStatus: 'undone',
		} );
} );

test( '@wp70-site-editor block structural review surfaces by default without flag injection', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	resetWp70TemplateSmokeState();

	const TEST_RESOLVED_SIGNATURE =
		'test-resolved-signature-block-structural-default';

	await page.route(
		recommendationAbilityRoute( 'recommend-block' ),
		async ( route ) => {
			const request = route.request();
			let body = {};
			try {
				body = getAbilityRequestInput( request.postDataJSON() || {} );
			} catch {
				body = {};
			}

			if ( body?.resolveSignatureOnly ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						payload: {
							resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
						},
						resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
					} ),
				} );
				return;
			}

			const operationContext =
				body?.editorContext?.blockOperationContext || {};
			const targetClientId = operationContext.targetClientId || '';
			const targetBlockName =
				operationContext.targetBlockName || 'core/paragraph';
			const targetSignature = operationContext.targetSignature || '';
			const operation = {
				catalogVersion: 1,
				type: 'insert_pattern',
				patternName: BLOCK_STRUCTURAL_PATTERN_NAME,
				targetClientId,
				targetType: 'block',
				targetSignature,
				expectedTarget: {
					clientId: targetClientId,
					name: targetBlockName,
				},
				position: 'insert_after',
			};

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					payload: {
						settings: [],
						styles: [],
						block: [
							{
								label: 'Insert structural pattern',
								description:
									'Insert a reviewed pattern after the selected block.',
								type: 'pattern_replacement',
								operations: [ operation ],
								proposedOperations: [ operation ],
								rejectedOperations: [],
							},
						],
						explanation: 'Mocked structural block recs',
						resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
					},
					resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
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

	// Default-on reaches the browser bootstrap with no test-side injection.
	// wp_localize_script serializes the PHP boolean true as the string "1", so
	// the bootstrap delivers an enabled encoding (not the literal boolean).
	const bootstrapFlag = await page.evaluate(
		() => window.flavorAgentData?.enableBlockStructuralActions
	);
	expect( [ true, 1, '1', 'true' ] ).toContain( bootstrapFlag );

	const clientId = await seedParagraphBlock( page );
	await registerTemplatePattern( page, {
		insertedContent: BLOCK_STRUCTURAL_INSERTED_CONTENT,
		patternName: BLOCK_STRUCTURAL_PATTERN_NAME,
		patternTitle: BLOCK_STRUCTURAL_PATTERN_TITLE,
	} );
	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	// The review-first structural lane surfaces purely from the default-on flag.
	await expect( page.getByText( 'Review first' ).first() ).toBeVisible();
	await page.getByRole( 'button', { name: 'Review' } ).click();
	await page
		.getByRole( 'button', { name: 'Apply reviewed structure' } )
		.click();

	await expect
		.poll( () =>
			page.evaluate(
				( { insertedContent, selectedClientId } ) => {
					const flavorAgent = window.wp.data.select( 'flavor-agent' );
					const blocks =
						window.wp.data
							.select( 'core/block-editor' )
							.getBlocks?.() || [];

					return {
						applyStatus:
							flavorAgent.getBlockApplyStatus?.(
								selectedClientId
							) || '',
						hasInsertedContent:
							JSON.stringify( blocks ).includes(
								insertedContent
							),
					};
				},
				{
					insertedContent: BLOCK_STRUCTURAL_INSERTED_CONTENT,
					selectedClientId: clientId,
				}
			)
		)
		.toEqual( {
			applyStatus: 'success',
			hasInsertedContent: true,
		} );
} );

test( '@wp70-site-editor block structural replace applies and undoes', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	resetWp70TemplateSmokeState();

	const TEST_RESOLVED_SIGNATURE =
		'test-resolved-signature-block-structural-replace';

	await page.route(
		recommendationAbilityRoute( 'recommend-block' ),
		async ( route ) => {
			const request = route.request();
			let body = {};
			try {
				body = getAbilityRequestInput( request.postDataJSON() || {} );
			} catch {
				body = {};
			}

			if ( body?.resolveSignatureOnly ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						payload: {
							resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
						},
						resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
					} ),
				} );
				return;
			}

			const operationContext =
				body?.editorContext?.blockOperationContext || {};
			const targetClientId = operationContext.targetClientId || '';
			const targetBlockName =
				operationContext.targetBlockName || 'core/paragraph';
			const targetSignature = operationContext.targetSignature || '';
			const operation = {
				catalogVersion: 1,
				type: 'replace_block_with_pattern',
				patternName: BLOCK_STRUCTURAL_PATTERN_NAME,
				targetClientId,
				targetType: 'block',
				targetSignature,
				action: 'replace',
				expectedTarget: {
					clientId: targetClientId,
					name: targetBlockName,
				},
			};

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					payload: {
						settings: [],
						styles: [],
						block: [
							{
								label: 'Replace block with pattern',
								description:
									'Replace the selected block with a reviewed pattern.',
								type: 'pattern_replacement',
								operations: [ operation ],
								proposedOperations: [ operation ],
								rejectedOperations: [],
							},
						],
						explanation: 'Mocked structural block recs',
						resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
					},
					resolvedContextSignature: TEST_RESOLVED_SIGNATURE,
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

	const clientId = await seedParagraphBlock( page );
	await registerTemplatePattern( page, {
		insertedContent: BLOCK_STRUCTURAL_INSERTED_CONTENT,
		patternName: BLOCK_STRUCTURAL_PATTERN_NAME,
		patternTitle: BLOCK_STRUCTURAL_PATTERN_TITLE,
	} );
	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect( page.getByText( 'Review first' ).first() ).toBeVisible();
	await page.getByRole( 'button', { name: 'Review' } ).click();
	await page
		.getByRole( 'button', { name: 'Apply reviewed structure' } )
		.click();

	// Replace removes the original paragraph and inserts the pattern in its place.
	await expect
		.poll( () =>
			page.evaluate(
				( { insertedContent, selectedClientId } ) => {
					const flavorAgent = window.wp.data.select( 'flavor-agent' );
					const blockEditor =
						window.wp.data.select( 'core/block-editor' );
					const blocks = blockEditor.getBlocks?.() || [];
					const lastActivity =
						[ ...( flavorAgent.getActivityLog?.() || [] ) ]
							.reverse()
							.find(
								( entry ) =>
									entry?.type !== 'request_diagnostic'
							) || null;

					return {
						applyStatus:
							flavorAgent.getBlockApplyStatus?.(
								selectedClientId
							) || '',
						hasInsertedContent:
							JSON.stringify( blocks ).includes(
								insertedContent
							),
						originalStillPresent: Boolean(
							blockEditor.getBlock?.( selectedClientId )
						),
						hasOriginalContent:
							JSON.stringify( blocks ).includes( 'Hello world' ),
						activityType: lastActivity?.type || '',
						undoStatus: lastActivity?.undo?.status || '',
					};
				},
				{
					insertedContent: BLOCK_STRUCTURAL_INSERTED_CONTENT,
					selectedClientId: clientId,
				}
			)
		)
		.toEqual( {
			applyStatus: 'success',
			hasInsertedContent: true,
			originalStillPresent: false,
			hasOriginalContent: false,
			activityType: 'apply_block_structural_suggestion',
			undoStatus: 'available',
		} );

	const undoResult = await page.evaluate( async () => {
		const flavorAgent = window.wp.data.select( 'flavor-agent' );
		const activity =
			[ ...( flavorAgent.getActivityLog?.() || [] ) ]
				.reverse()
				.find(
					( entry ) =>
						entry?.type === 'apply_block_structural_suggestion' &&
						entry?.undo?.canUndo
				) || null;

		if ( ! activity?.id ) {
			return {
				ok: false,
				error: 'No structural activity was available to undo.',
			};
		}

		return window.wp.data
			.dispatch( 'flavor-agent' )
			.undoActivity( activity.id );
	} );

	expect( undoResult ).toEqual( expect.objectContaining( { ok: true } ) );

	// Undo restores the original paragraph and removes the inserted pattern.
	await expect
		.poll( () =>
			page.evaluate( ( insertedContent ) => {
				const blocks =
					window.wp.data
						.select( 'core/block-editor' )
						.getBlocks?.() || [];

				return {
					hasInsertedContent:
						JSON.stringify( blocks ).includes( insertedContent ),
					hasOriginalContent:
						JSON.stringify( blocks ).includes( 'Hello world' ),
				};
			}, BLOCK_STRUCTURAL_INSERTED_CONTENT )
		)
		.toEqual( {
			hasInsertedContent: false,
			hasOriginalContent: true,
		} );
} );

test( '@wp70-site-editor content recommendation surface drafts, edits, critiques, and reports REST errors', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	resetWp70TemplateSmokeState();

	const capturedRequests = [];
	const createdPost = runWpCli( wp70Harness, [
		'post',
		'create',
		'--post_type=post',
		'--post_status=draft',
		'--post_title=Content E2E Draft',
		'--post_content=Existing copy for Content E2E.',
		'--porcelain',
	] );
	const postId = createdPost.stdout.trim();

	await enableMockedRecommendationSurfaces( page, [ 'content' ] );
	await page.route(
		recommendationAbilityRoute( 'recommend-content' ),
		async ( route ) => {
			const body = getAbilityRequestInput(
				route.request().postDataJSON()
			);
			capturedRequests.push( body );

			if ( String( body?.prompt || '' ).includes( 'force an error' ) ) {
				await route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'content_test_error',
						message: 'Content route failed.',
					} ),
				} );
				return;
			}

			const mode = body?.mode || 'draft';
			let response;

			if ( mode === 'critique' ) {
				response = {
					mode: 'critique',
					title: 'Critique result',
					summary: 'The opening needs a more concrete first move.',
					content:
						'The draft needs to lead with the real support moment before it broadens into platform change.',
					notes: [ 'Lead with the real support moment.' ],
					issues: [
						{
							original: 'Technology is changing fast.',
							problem: 'Too generic.',
							revision:
								'The ticket queue changed. The customer need did not.',
						},
					],
				};
			} else if ( mode === 'edit' ) {
				response = {
					mode: 'edit',
					title: 'Edited Content E2E Draft',
					summary: 'The opener is tighter.',
					content:
						'Existing copy, tightened.\n\nSecond paragraph with a clearer turn.',
					notes: [ 'Keep the sequence concrete.' ],
					issues: [],
				};
			} else {
				response = {
					mode: 'draft',
					title: 'Drafted Content E2E Post',
					summary: 'A new draft was generated from the brief.',
					content:
						'Retail floors.\n\nWordPress themes.\n\nAgent workflows.',
					notes: [ 'Use the progression as the spine.' ],
					issues: [],
				};
			}

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( response ),
			} );
		}
	);

	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit`, {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await ensurePostDocumentSettingsSidebarOpen( page );

	const getPromptInput = () =>
		page.locator( '.flavor-agent-content-recommender textarea' ).first();
	const promptInput = getPromptInput();

	await ensurePanelOpen( page, 'Content Recommendations', promptInput );
	await promptInput.fill( 'Draft from the working title.' );
	await page.getByRole( 'button', { name: 'Generate Draft Text' } ).click();

	await expect.poll( () => capturedRequests.length ).toBe( 1 );
	expect( capturedRequests[ 0 ].mode ).toBe( 'draft' );
	expect( capturedRequests[ 0 ].postContext.title ).toBe(
		'Content E2E Draft'
	);
	await expect( page.getByText( 'Drafted Content E2E Post' ) ).toBeVisible();
	await expect( page.getByText( 'Retail floors.' ) ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: /Refine request/ } )
	).toBeVisible();

	await page.getByRole( 'button', { name: /Refine request/ } ).click();
	const editPromptInput = getPromptInput();
	await expect( editPromptInput ).toBeVisible();
	await page.getByRole( 'button', { name: 'Edit', exact: true } ).click();
	await editPromptInput.fill( 'Tighten the existing copy.' );
	await page
		.getByRole( 'button', { name: 'Generate Revision Text' } )
		.click();

	await expect.poll( () => capturedRequests.length ).toBe( 2 );
	expect( capturedRequests[ 1 ].mode ).toBe( 'edit' );
	expect( capturedRequests[ 1 ].postContext.content ).toContain(
		'Existing copy for Content E2E.'
	);
	await expect( page.getByText( 'Edited Content E2E Draft' ) ).toBeVisible();

	await page.getByRole( 'button', { name: /Refine request/ } ).click();
	const critiquePromptInput = getPromptInput();
	await expect( critiquePromptInput ).toBeVisible();
	await page.getByRole( 'button', { name: 'Critique', exact: true } ).click();
	await critiquePromptInput.fill( 'Find the weak lines.' );
	await page.getByRole( 'button', { name: 'Generate Critique' } ).click();

	await expect.poll( () => capturedRequests.length ).toBe( 3 );
	expect( capturedRequests[ 2 ].mode ).toBe( 'critique' );
	await expect( page.getByText( 'Critique result' ) ).toBeVisible();
	await expect(
		page.getByText(
			'The draft needs to lead with the real support moment before it broadens into platform change.'
		)
	).toBeVisible();
	await expect( page.getByText( 'Editorial Notes' ) ).toBeVisible();
	await expect(
		page.getByText( 'Lead with the real support moment.' )
	).toBeHidden();

	await page.getByRole( 'button', { name: /Editorial Notes/ } ).click();
	await expect(
		page.getByText( 'Lead with the real support moment.' )
	).toBeVisible();
	await expect(
		page.getByText( 'Technology is changing fast.' )
	).toBeVisible();
	await expect( page.getByText( 'Too generic.' ) ).toBeVisible();

	await page.getByRole( 'button', { name: /Refine request/ } ).click();
	const errorPromptInput = getPromptInput();
	await expect( errorPromptInput ).toBeVisible();
	await errorPromptInput.fill( 'force an error' );
	await page.getByRole( 'button', { name: 'Generate Critique' } ).click();

	await expect.poll( () => capturedRequests.length ).toBe( 4 );
	await expect(
		page
			.locator( '.flavor-agent-content-recommender' )
			.getByText( 'Content route failed.' )
	).toBeVisible();
} );

test( 'content panel renders for a brand-new unsaved post', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );

	await enableMockedRecommendationSurfaces( page, [ 'content' ] );
	await page.goto( '/wp-admin/post-new.php?post_type=post', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await ensurePostDocumentSettingsSidebarOpen( page );

	const promptInput = page
		.locator( '.flavor-agent-content-recommender textarea' )
		.first();

	await ensurePanelOpen( page, 'Content Recommendations', promptInput );
	await expect( promptInput ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Generate Draft Text' } )
	).toBeVisible();
} );

test( 'block and pattern surfaces explain unavailable providers in native UI', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );

	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );

	await page.evaluate( () => {
		const data = window.flavorAgentData || {};

		data.canRecommendBlocks = false;
		data.canRecommendPatterns = false;

		if ( data.capabilities?.surfaces?.block ) {
			data.capabilities.surfaces.block.available = false;
			data.capabilities.surfaces.block.reason =
				'block_backend_unconfigured';
		}

		if ( data.capabilities?.surfaces?.pattern ) {
			data.capabilities.surfaces.pattern.available = false;
			data.capabilities.surfaces.pattern.reason =
				'pattern_backend_unconfigured';
			data.capabilities.surfaces.pattern.message =
				'Pattern recommendations need the Embedding Model and Qdrant Pattern Storage in Settings > Flavor Agent, plus a usable text-generation provider in Settings > Connectors.';
		}

		window.wp?.data
			?.dispatch( 'flavor-agent' )
			?.setPatternRecommendations?.( [] );
		window.wp?.data
			?.dispatch( 'flavor-agent' )
			?.setPatternStatus?.( 'idle' );
	} );
	await seedParagraphBlock( page, { enableRecommendations: false } );
	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'Describe the outcome you want for this block.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	await dismissWelcomeGuide( page );
	const recommendationsPanel = promptInput.locator(
		'xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " components-panel__body ")][1]'
	);
	await expect(
		recommendationsPanel.getByRole( 'link', {
			name: 'Settings > Connectors',
		} )
	).toBeVisible( { timeout: 15000 } );
	await expect(
		recommendationsPanel.getByRole( 'button', { name: 'Get Suggestions' } )
	).toBeDisabled();

	await page
		.getByRole( 'button', {
			name: 'Block Inserter',
			exact: true,
		} )
		.click();

	const patternCapabilityNotice = page
		.locator( '.flavor-agent-capability-notice' )
		.filter( {
			hasText:
				'Pattern recommendations need the Embedding Model and Qdrant Pattern Storage',
		} )
		.first();

	await expect( patternCapabilityNotice ).toBeVisible();
	await expect(
		patternCapabilityNotice.getByText(
			'Pattern recommendations need the Embedding Model and Qdrant Pattern Storage'
		)
	).toBeVisible();
	await expect(
		patternCapabilityNotice.getByRole( 'link', {
			name: 'Settings > Flavor Agent',
		} )
	).toBeVisible();
} );

test( 'navigation surface smoke renders advisory recommendations for a selected navigation block', async ( {
	page,
} ) => {
	const navigationRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-navigation' ),
		async ( route ) => {
			navigationRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					explanation:
						'Keep utility links together and simplify the top level.',
					suggestions: [
						{
							label: 'Group utility links',
							description:
								'Move account and contact items into one submenu.',
							category: 'structure',
							changes: [
								{
									type: 'group',
									target: 'Account and Contact',
									detail: 'Keep the top level shorter.',
								},
							],
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
	await seedNavigationBlock( page );
	await ensureSettingsSidebarOpen( page );

	const promptInput = page.getByPlaceholder(
		'Describe the structure or behavior you want.'
	);

	await ensurePanelOpen( page, 'AI Recommendations', promptInput );
	const recommendationsPanel = promptInput.locator(
		'xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " components-panel__body ")][1]'
	);
	await promptInput.fill( NAVIGATION_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Navigation Suggestions' } )
		.click();

	await expect.poll( () => navigationRequests.length ).toBe( 1 );
	expect( navigationRequests[ 0 ].prompt ).toBe( NAVIGATION_PROMPT );
	expect( navigationRequests[ 0 ].navigationMarkup ).toContain(
		'wp:navigation'
	);

	const navigationSummarySection = recommendationsPanel
		.locator( '.flavor-agent-navigation-embedded' )
		.first();

	await expect(
		navigationSummarySection.getByText( 'Navigation Ideas', {
			exact: true,
		} )
	).toBeVisible();
	await expect(
		navigationSummarySection.getByText( 'Manual ideas', { exact: true } )
	).toBeVisible();
	await expect(
		recommendationsPanel.getByText(
			'Keep utility links together and simplify the top level.'
		)
	).toBeVisible();
	await expect(
		recommendationsPanel.getByText( 'Group utility links', { exact: true } )
	).toBeVisible();
} );

test( 'pattern surface smoke uses the inserter search to fetch recommendations', async ( {
	page,
} ) => {
	const patternRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-patterns' ),
		async ( route ) => {
			const requestData = getAbilityRequestInput(
				route.request().postDataJSON()
			);
			const visiblePatternNames = Array.isArray(
				requestData.visiblePatternNames
			)
				? requestData.visiblePatternNames
				: [];
			const heroPatternNames = visiblePatternNames.filter( ( name ) =>
				name.includes( 'hero' )
			);
			const recommendationNames = [
				...heroPatternNames,
				...visiblePatternNames,
				'playground/recommended-pattern',
			]
				.filter(
					( name, index, names ) =>
						name && names.indexOf( name ) === index
				)
				.slice( 0, 2 );

			patternRequests.push( {
				...requestData,
				mockedRecommendationNames: recommendationNames,
			} );
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature: 'pattern-resolved-context',
					recommendations: recommendationNames.map(
						( name, index ) => ( {
							name,
							score: 0.97 - index * 0.01,
							reason:
								index === 0
									? 'Exercises the insert exception path.'
									: PATTERN_REASON,
							categories: [ 'hero' ],
							ranking: {
								sourceSignals: [
									'qdrant_semantic',
									'llm_ranker',
								],
								rankingHint: {
									matchesNearbyBlock: true,
								},
							},
						} )
					),
					diagnostics: {
						filteredCandidates: {
							unreadableSyncedPatterns: 0,
						},
					},
				} ),
			} );
		}
	);

	await enableMockedRecommendationSurfaces( page, [ 'pattern' ] );
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );

	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendPatterns )
	);
	await seedParagraphBlock( page );
	const searchPrompt = 'hero';

	await dismissWelcomeGuide( page );

	await page
		.getByRole( 'button', {
			name: 'Block Inserter',
			exact: true,
		} )
		.click();
	await dismissWelcomeGuide( page );

	// The first real ranking is sent on block inserter intent, not on editor load.
	await expect
		.poll( () => patternRequests.length > 0, { timeout: 15_000 } )
		.toBe( true );

	const searchInput = await getVisibleSearchInput( page );

	await expect( searchInput ).toBeVisible();
	await searchInput.click();
	await searchInput.fill( '' );
	await searchInput.pressSequentially( searchPrompt, { delay: 20 } );

	await expect
		.poll(
			() =>
				patternRequests.findLast(
					( request ) => request?.prompt === searchPrompt
				) || null,
			{ timeout: 10000 }
		)
		.not.toBeNull();

	const activeRequest = patternRequests.findLast(
		( request ) => request?.prompt === searchPrompt
	);

	expect( activeRequest.prompt ).toBe( searchPrompt );
	expect( activeRequest.visiblePatternNames ).toContain(
		activeRequest.mockedRecommendationNames.at( -1 )
	);
	expect( activeRequest.blockContext ).toEqual( {
		blockName: 'core/paragraph',
	} );

	await expect(
		page.getByLabel( '2 pattern recommendations available' )
	).toBeVisible();
	await expect( page.getByText( 'Semantic match' ).first() ).toBeVisible();
	await expect( page.getByText( 'Model ranked' ).first() ).toBeVisible();
	await expect( page.getByText( 'Allowed here' ).first() ).toBeVisible();

	const patternShelf = page.locator( '.flavor-agent-pattern-shelf' ).first();
	const patternItems = patternShelf.locator(
		'.flavor-agent-pattern-shelf__item'
	);
	const exceptionItem = patternItems.first();
	const noopItem = patternItems.nth( 1 );
	const exceptionPatternTitle = await exceptionItem
		.locator( '.flavor-agent-pattern-shelf__title' )
		.innerText();
	const noopPatternTitle = await noopItem
		.locator( '.flavor-agent-pattern-shelf__title' )
		.innerText();

	await forceNextPatternInsertFailure(
		page,
		activeRequest.mockedRecommendationNames[ 0 ],
		'insert_blocks_exception'
	);
	await exceptionItem.getByRole( 'button', { name: /^Insert\b/ } ).click();
	await expectSnackbarMessage(
		page,
		`Cannot insert pattern "${ exceptionPatternTitle }" because Gutenberg rejected the insertion request.`
	);

	await forceNextPatternInsertFailure(
		page,
		activeRequest.mockedRecommendationNames.at( -1 ),
		'insert_blocks_noop'
	);
	await noopItem.getByRole( 'button', { name: /^Insert\b/ } ).click();
	await expectSnackbarMessage(
		page,
		`Cannot confirm pattern "${ noopPatternTitle }" was inserted. Gutenberg did not report the inserted blocks at the target location.`
	);

	await forceNextPatternInsertFailure(
		page,
		activeRequest.mockedRecommendationNames.at( -1 ),
		'insert_blocks_wrong_target'
	);
	await noopItem.getByRole( 'button', { name: /^Insert\b/ } ).click();
	await expectSnackbarMessage(
		page,
		`Cannot insert pattern "${ noopPatternTitle }" at the requested location. Gutenberg inserted it somewhere else, so Flavor Agent removed those blocks.`
	);
} );

test( 'pattern surface inserts a recommended pattern at the top-level root and renders it', async ( {
	page,
} ) => {
	// Regression guard for the null-root orphan bug. getBlockInsertionPoint()
	// reports rootClientId: undefined for a top-level point; the surface must
	// dispatch that to insertBlocks as '' (not null). The post editor's
	// top-level list is a *controlled* inner-block list keyed by '', and core's
	// insertBlocks reducer does NOT normalize null to it: a null root records
	// the blocks in byClientId (so getBlock finds them) but never adds them to
	// the root order, so they never render and the surface tears them out as
	// "inserted somewhere else". The unit mocks could not catch this (they key
	// null === ''); only a real top-level editor insert proves the blocks land.
	const patternRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-patterns' ),
		async ( route ) => {
			const requestData = getAbilityRequestInput(
				route.request().postDataJSON()
			);
			const visiblePatternNames = Array.isArray(
				requestData.visiblePatternNames
			)
				? requestData.visiblePatternNames
				: [];
			// Recommend a real, editor-visible (therefore top-level-insertable)
			// pattern so the insert exercises the genuine resolve+dispatch path.
			const recommendationNames = visiblePatternNames.slice( 0, 1 );

			patternRequests.push( {
				...requestData,
				mockedRecommendationNames: recommendationNames,
			} );
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature: 'pattern-resolved-context',
					recommendations: recommendationNames.map( ( name ) => ( {
						name,
						score: 0.97,
						reason: PATTERN_REASON,
						categories: [ 'hero' ],
					} ) ),
					diagnostics: {
						filteredCandidates: { unreadableSyncedPatterns: 0 },
					},
				} ),
			} );
		}
	);

	await enableMockedRecommendationSurfaces( page, [ 'pattern' ] );
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );

	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendPatterns )
	);
	// Seeds and SELECTS a single top-level paragraph, so the captured insertion
	// root is undefined -> the null/'' code path under test.
	await seedParagraphBlock( page );
	await dismissWelcomeGuide( page );

	await page
		.getByRole( 'button', { name: 'Block Inserter', exact: true } )
		.click();
	await dismissWelcomeGuide( page );

	const searchInput = await getVisibleSearchInput( page );
	await expect( searchInput ).toBeVisible();
	await searchInput.click();
	await searchInput.fill( '' );
	await searchInput.pressSequentially( 'hero', { delay: 20 } );

	await expect
		.poll(
			() =>
				patternRequests.findLast(
					( request ) => request?.prompt === 'hero'
				) || null,
			{ timeout: 10000 }
		)
		.not.toBeNull();

	const firstItem = page
		.locator( '.flavor-agent-pattern-shelf' )
		.first()
		.locator( '.flavor-agent-pattern-shelf__item' )
		.first();
	await expect( firstItem ).toBeVisible();
	const patternTitle = await firstItem
		.locator( '.flavor-agent-pattern-shelf__title' )
		.innerText();

	const topLevelOrderBefore = await page.evaluate(
		() =>
			(
				window.wp.data
					.select( 'core/block-editor' )
					.getBlockOrder( '' ) || []
			).length
	);

	// Real insert — no forced failure harness this time.
	await firstItem.getByRole( 'button', { name: /^Insert\b/ } ).click();

	// The success snackbar only renders when post-insert verification confirms
	// the blocks landed at the target. A null root orphans them and yields the
	// "inserted it somewhere else" snackbar instead, so this assertion fails
	// the moment the surface regresses to passing null.
	await expectSnackbarMessage(
		page,
		`Block pattern "${ patternTitle }" inserted.`
	);
	await expect(
		page.locator( '.components-snackbar__content' )
	).not.toHaveText( /inserted it somewhere else/ );

	// Defense in depth: the blocks must really be in the controlled top-level
	// order, not merely present-but-orphaned in byClientId.
	await expect
		.poll( () =>
			page.evaluate(
				() =>
					(
						window.wp.data
							.select( 'core/block-editor' )
							.getBlockOrder( '' ) || []
					).length
			)
		)
		.toBeGreaterThan( topLevelOrderBefore );
} );

test( 'pattern badge attaches to the block inserter button, not document overview', async ( {
	page,
} ) => {
	await page.route(
		recommendationAbilityRoute( 'recommend-patterns' ),
		async ( route ) => {
			const requestData = getAbilityRequestInput(
				route.request().postDataJSON()
			);
			const visiblePatternNames = Array.isArray(
				requestData.visiblePatternNames
			)
				? requestData.visiblePatternNames
				: [];
			const recommendationNames = visiblePatternNames.slice( 0, 1 );

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature: 'pattern-resolved-context',
					recommendations: recommendationNames.map( ( name ) => ( {
						name,
						score: 0.97,
						reason: PATTERN_REASON,
						categories: [ 'hero' ],
					} ) ),
					diagnostics: {
						filteredCandidates: { unreadableSyncedPatterns: 0 },
					},
				} ),
			} );
		}
	);

	await enableMockedRecommendationSurfaces( page, [ 'pattern' ] );
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );

	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendPatterns )
	);
	await seedParagraphBlock( page );
	await dismissWelcomeGuide( page );

	const inserter = page
		.getByRole( 'button', { name: 'Block Inserter', exact: true } )
		.first();
	await expect( inserter ).toBeVisible();
	await inserter.click();
	await dismissWelcomeGuide( page );

	// Proves the inserter-intent ranking returned renderable recommendations,
	// which is what flips the badge out of its hidden state.
	await expect(
		page.locator( '.flavor-agent-pattern-shelf__item' ).first()
	).toBeVisible( { timeout: 15_000 } );

	// The badge then mounts in a dedicated span anchored immediately after the
	// real block inserter toggle.
	const badgeAnchor = page
		.locator( '.flavor-agent-inserter-badge-anchor' )
		.first();
	await expect( badgeAnchor ).toBeAttached( { timeout: 15_000 } );

	const anchorNeighbor = await badgeAnchor.evaluate( ( anchor ) => {
		const previous = anchor.previousElementSibling;

		return {
			hasBadge: Boolean(
				anchor.querySelector( '.flavor-agent-inserter-badge' )
			),
			label:
				previous?.getAttribute( 'aria-label' ) ||
				previous?.textContent ||
				'',
		};
	} );

	expect( anchorNeighbor.hasBadge ).toBe( true );
	expect( anchorNeighbor.label ).toMatch( /block inserter/i );
	expect( anchorNeighbor.label ).not.toMatch(
		/list\s*view|outline|document\s*overview|hierarchy|structure/i
	);
} );

test( '@wp70-site-editor global styles surface previews, applies, and undoes executable recommendations', async ( {
	page,
} ) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations( page, styleRequests );

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await enableMockedRecommendationSurfaces( page, [ 'global-styles' ] );
	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendGlobalStyles )
	);
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.data
				?.select( 'core' )
				?.__experimentalGetCurrentGlobalStylesId?.()
		)
	);
	await enableSiteEditorGlobalStylesSidebar( page );

	const initialState = await getGlobalStylesState( page );

	expect( initialState.globalStylesId ).toBeTruthy();

	const recommendationsPanel = page
		.locator( '.flavor-agent-global-styles-panel' )
		.first();
	const promptInput = page.getByLabel( 'Describe the style direction' );

	await expect( promptInput ).toBeVisible();
	await promptInput.fill( GLOBAL_STYLES_PROMPT );
	await page.getByRole( 'button', { name: 'Get Style Suggestions' } ).click();

	await expect.poll( () => styleRequests.length ).toBe( 1 );
	expect( styleRequests[ 0 ].prompt ).toBe( GLOBAL_STYLES_PROMPT );
	expect( styleRequests[ 0 ].scope.surface ).toBe( 'global-styles' );
	expect( styleRequests[ 0 ].scope.globalStylesId ).toBe(
		initialState.globalStylesId
	);
	expect( styleRequests[ 0 ].styleContext ).toHaveProperty(
		'themeTokenDiagnostics'
	);

	await expect(
		recommendationsPanel.getByText( GLOBAL_STYLES_SUGGESTION_LABEL ).first()
	).toBeVisible();
	await recommendationsPanel
		.getByRole( 'button', { name: /^Review\b/ } )
		.click();
	await expect(
		page
			.locator( '.flavor-agent-review-section' )
			.getByText( 'Review Before Apply', { exact: true } )
	).toBeVisible();
	await page
		.getByRole( 'button', { name: 'Confirm Apply', exact: true } )
		.click();

	await expect
		.poll( () => getGlobalStylesState( page ) )
		.toEqual(
			expect.objectContaining( {
				globalStylesId: initialState.globalStylesId,
				background: GLOBAL_STYLES_BACKGROUND_VALUE,
				lineHeight: GLOBAL_STYLES_LINE_HEIGHT_VALUE,
				applyStatus: 'success',
				activityType: 'apply_global_styles_suggestion',
			} )
		);
	await expect(
		page
			.locator( '.flavor-agent-activity-row' )
			.getByText( 'Undo available' )
	).toBeVisible();
	const applyToast = page.locator( '.flavor-agent-toast' ).first();
	if ( await applyToast.isVisible().catch( () => false ) ) {
		await applyToast
			.getByRole( 'button', { name: 'Dismiss', exact: true } )
			.click();
		await expect( applyToast ).toBeHidden();
	}

	await expect( page.getByText( 'Recent AI Style Actions' ) ).toBeVisible();
	await page
		.locator( '.flavor-agent-activity-row' )
		.getByRole( 'button', { name: 'Undo', exact: true } )
		.click();

	await expect
		.poll( () => getGlobalStylesState( page ) )
		.toEqual(
			expect.objectContaining( {
				globalStylesId: initialState.globalStylesId,
				background: initialState.background,
				lineHeight: initialState.lineHeight,
			} )
		);
	await expect(
		page.locator( '.flavor-agent-activity-row' ).getByText( 'Undone' )
	).toBeVisible();
} );

test( '@wp70-site-editor global styles surface keeps grouped apply operations all-or-nothing', async ( {
	page,
} ) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations(
		page,
		styleRequests,
		GLOBAL_STYLES_PARTIAL_INVALID_RESPONSE
	);

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await enableMockedRecommendationSurfaces( page, [ 'global-styles' ] );
	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendGlobalStyles )
	);
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.data
				?.select( 'core' )
				?.__experimentalGetCurrentGlobalStylesId?.()
		)
	);
	await enableSiteEditorGlobalStylesSidebar( page );

	const initialState = await getGlobalStylesState( page );
	const recommendationsPanel = page
		.locator( '.flavor-agent-global-styles-panel' )
		.first();
	const promptInput = page.getByLabel( 'Describe the style direction' );

	await expect( promptInput ).toBeVisible();
	await promptInput.fill( GLOBAL_STYLES_PROMPT );
	await page.getByRole( 'button', { name: 'Get Style Suggestions' } ).click();

	await expect.poll( () => styleRequests.length ).toBe( 1 );
	await expect(
		recommendationsPanel.getByText( GLOBAL_STYLES_SUGGESTION_LABEL ).first()
	).toBeVisible();
	await recommendationsPanel
		.getByRole( 'button', { name: /^Review\b/ } )
		.click();
	await page
		.getByRole( 'button', { name: 'Confirm Apply', exact: true } )
		.click();

	await expect(
		page
			.locator( '.flavor-agent-status-notice__message' )
			.getByText( 'customCSS is no longer supported', { exact: false } )
	).toBeVisible( { timeout: 15000 } );
	await expect
		.poll( () => getGlobalStylesState( page ) )
		.toEqual(
			expect.objectContaining( {
				globalStylesId: initialState.globalStylesId,
				background: initialState.background,
				lineHeight: initialState.lineHeight,
				applyStatus: 'error',
			} )
		);
} );

test( '@wp70-site-editor global styles surface requests defaults when the prompt is empty', async ( {
	page,
} ) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations( page, styleRequests );

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await enableMockedRecommendationSurfaces( page, [ 'global-styles' ] );
	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendGlobalStyles )
	);
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.data
				?.select( 'core' )
				?.__experimentalGetCurrentGlobalStylesId?.()
		)
	);
	await enableSiteEditorGlobalStylesSidebar( page );

	const initialState = await getGlobalStylesState( page );
	const recommendationsPanel = page
		.locator( '.flavor-agent-global-styles-panel' )
		.first();
	const promptInput = page.getByLabel( 'Describe the style direction' );

	await expect( promptInput ).toBeVisible();
	await page.getByRole( 'button', { name: 'Get Style Suggestions' } ).click();

	await expect.poll( () => styleRequests.length ).toBe( 1 );
	expect( styleRequests[ 0 ].scope.surface ).toBe( 'global-styles' );
	expect( styleRequests[ 0 ].scope.globalStylesId ).toBe(
		initialState.globalStylesId
	);
	expect( styleRequests[ 0 ].styleContext ).toHaveProperty(
		'themeTokenDiagnostics'
	);
	expect( styleRequests[ 0 ] ).not.toHaveProperty( 'prompt' );

	await expect(
		recommendationsPanel.getByText( GLOBAL_STYLES_SUGGESTION_LABEL ).first()
	).toBeVisible();
} );

test( '@wp70-site-editor global styles surface keeps stale results visible but disables review and apply until refresh', async ( {
	page,
} ) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations( page, styleRequests );

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await enableMockedRecommendationSurfaces( page, [ 'global-styles' ] );
	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendGlobalStyles )
	);
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.data
				?.select( 'core' )
				?.__experimentalGetCurrentGlobalStylesId?.()
		)
	);
	await enableSiteEditorGlobalStylesSidebar( page );

	const promptInput = page.getByLabel( 'Describe the style direction' );
	const recommendationsPanel = page
		.locator( '.flavor-agent-global-styles-panel' )
		.first();

	await expect( promptInput ).toBeVisible();
	await promptInput.fill( GLOBAL_STYLES_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Style Suggestions' } )
		.click();

	await expect.poll( () => styleRequests.length ).toBe( 1 );
	await expect(
		recommendationsPanel
			.getByText( 'Adjust canvas tone and rhythm' )
			.first()
	).toBeVisible();
	await recommendationsPanel
		.getByRole( 'button', { name: 'Review' } )
		.click();
	await expect(
		recommendationsPanel.getByText( 'Review Before Apply', { exact: true } )
	).toBeVisible();

	await setCurrentGlobalStylesTextColor(
		page,
		GLOBAL_STYLES_STALE_TEXT_COLOR
	);
	await expect
		.poll( () =>
			page.evaluate( () => {
				const core = window.wp?.data?.select( 'core' );
				const globalStylesId =
					core?.__experimentalGetCurrentGlobalStylesId?.() || null;
				const record = globalStylesId
					? core?.getEditedEntityRecord?.(
							'root',
							'globalStyles',
							globalStylesId
					  ) ||
					  core?.getEntityRecord?.(
							'root',
							'globalStyles',
							globalStylesId
					  ) ||
					  null
					: null;

				return record?.styles?.color?.text || '';
			} )
		)
		.toBe( GLOBAL_STYLES_STALE_TEXT_COLOR );

	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: GLOBAL_STYLES_STALE_NOTICE }
		)
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Reviewing\b/,
		} )
	).toBeDisabled();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Confirm Apply',
			exact: true,
		} )
	).toBeDisabled();

	await recommendationsPanel
		.locator( '.flavor-agent-scope-bar__refresh' )
		.click();

	await expect.poll( () => styleRequests.length ).toBe( 2 );
	expect( styleRequests[ 1 ].scope.surface ).toBe( 'global-styles' );
	expect(
		styleRequests[ 1 ].styleContext.currentConfig.styles.color.text
	).toBe( GLOBAL_STYLES_STALE_TEXT_COLOR );
	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: GLOBAL_STYLES_STALE_NOTICE }
		)
	).toHaveCount( 0 );
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Review\b/,
		} )
	).toBeEnabled();
} );

test( '@wp70-site-editor global styles surface renders contrast advisory annotation and disables apply', async ( {
	page,
} ) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations( page, styleRequests, {
		suggestions: [
			{
				label: 'Soft on soft',
				description:
					'Use the wash on base. Contrast check: 1.2:1 between "base" and "wash" at root, below the 4.5:1 minimum.',
				category: 'color',
				tone: 'advisory',
				operations: [],
			},
		],
		explanation: 'Advisory because the proposed pair fails contrast.',
		reviewContextSignature: 'mock-review-sig',
		resolvedContextSignature: 'mock-resolved-sig',
	} );

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await enableMockedRecommendationSurfaces( page, [ 'global-styles' ] );
	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendGlobalStyles )
	);
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.data
				?.select( 'core' )
				?.__experimentalGetCurrentGlobalStylesId?.()
		)
	);
	await enableSiteEditorGlobalStylesSidebar( page );

	const recommendationsPanel = page
		.locator( '.flavor-agent-global-styles-panel' )
		.first();
	const promptInput = page.getByLabel( 'Describe the style direction' );

	await expect( promptInput ).toBeVisible();
	await promptInput.fill( GLOBAL_STYLES_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Style Suggestions' } )
		.click();

	await expect.poll( () => styleRequests.length ).toBe( 1 );
	await expect(
		recommendationsPanel.getByText( 'Soft on soft' ).first()
	).toBeVisible();
	await expect(
		recommendationsPanel
			.getByText( 'Contrast check:', { exact: false } )
			.first()
	).toBeVisible();

	const state = await getGlobalStylesState( page );

	expect( state.advisorySuggestions ).toHaveLength( 1 );
	expect( state.executableSuggestions ).toHaveLength( 0 );
	expect( state.advisorySuggestions[ 0 ].description ).toContain(
		'Contrast check:'
	);
	expect( state.applyButtonVisible ).toBe( false );
	expect( styleRequests.length ).toBeGreaterThan( 0 );
} );

test( '@wp70-site-editor style book surface keeps stale results visible but disables review and apply until refresh', async ( {
	page,
} ) => {
	const styleRequests = [];

	await mockStyleBookRecommendations( page, styleRequests );

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await dismissSiteEditorWelcomeGuide( page );
	await enableMockedRecommendationSurfaces( page, [ 'global-styles' ] );
	await page.waitForFunction( () =>
		Boolean( window.flavorAgentData?.canRecommendGlobalStyles )
	);
	await page.waitForFunction( () =>
		Boolean(
			window.wp?.data
				?.select( 'core' )
				?.__experimentalGetCurrentGlobalStylesId?.()
		)
	);
	await enableSiteEditorGlobalStylesSidebar( page );
	await injectStyleBookExample( page, {
		blockName: STYLE_BOOK_BLOCK_NAME,
		blockTitle: STYLE_BOOK_BLOCK_TITLE,
	} );

	const promptInput = await openStyleBookRecommendationsPanel( page );
	const recommendationsPanel = page
		.locator( '.flavor-agent-style-book-panel' )
		.first();

	await promptInput.fill( STYLE_BOOK_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Style Suggestions' } )
		.click();

	await expect.poll( () => styleRequests.length ).toBe( 1 );
	expect( styleRequests[ 0 ].scope.surface ).toBe( 'style-book' );
	expect( styleRequests[ 0 ].scope.blockName ).toBe( STYLE_BOOK_BLOCK_NAME );
	expect( styleRequests[ 0 ].scope.blockTitle ).toBe(
		STYLE_BOOK_BLOCK_TITLE
	);
	await expect(
		recommendationsPanel
			.getByText( 'Strengthen paragraph emphasis' )
			.first()
	).toBeVisible();
	await recommendationsPanel
		.getByRole( 'button', { name: 'Review' } )
		.click();
	await expect(
		recommendationsPanel.getByText( 'Review Before Apply', { exact: true } )
	).toBeVisible();

	await setStyleBookBlockTextColor( page, {
		blockName: STYLE_BOOK_BLOCK_NAME,
		textColor: STYLE_BOOK_STALE_TEXT_COLOR,
	} );
	await expect
		.poll( () =>
			page.evaluate( ( blockName ) => {
				const core = window.wp?.data?.select( 'core' );
				const globalStylesId =
					core?.__experimentalGetCurrentGlobalStylesId?.() || null;
				const record = globalStylesId
					? core?.getEditedEntityRecord?.(
							'root',
							'globalStyles',
							globalStylesId
					  ) ||
					  core?.getEntityRecord?.(
							'root',
							'globalStyles',
							globalStylesId
					  ) ||
					  null
					: null;

				return record?.styles?.blocks?.[ blockName ]?.color?.text || '';
			}, STYLE_BOOK_BLOCK_NAME )
		)
		.toBe( STYLE_BOOK_STALE_TEXT_COLOR );

	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: STYLE_BOOK_STALE_NOTICE }
		)
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Reviewing\b/,
		} )
	).toBeDisabled();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Confirm Apply',
			exact: true,
		} )
	).toBeDisabled();

	await recommendationsPanel
		.locator( '.flavor-agent-scope-bar__refresh' )
		.click();

	await expect.poll( () => styleRequests.length ).toBe( 2 );
	expect( styleRequests[ 1 ].scope.surface ).toBe( 'style-book' );
	expect(
		styleRequests[ 1 ].styleContext.styleBookTarget.currentStyles.color.text
	).toBe( STYLE_BOOK_STALE_TEXT_COLOR );
	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: STYLE_BOOK_STALE_NOTICE }
		)
	).toHaveCount( 0 );
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Review\b/,
		} )
	).toBeEnabled();
} );

// Playground WP 6.9.4 rejects root template insertion in this path even after
// the plugin preflight passes. The WP 7.0 harness exercises the shipped
// template apply workflow against the Docker-backed editor runtime.
test( '@wp70-site-editor template surface smoke previews and applies executable template recommendations', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	resetWp70TemplateSmokeState();

	let templateTarget = null;
	const templateRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-template' ),
		async ( route ) => {
			templateRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_RESOLVED_CONTEXT_SIGNATURE,
					explanation: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PATTERN_NAME,
									placement: 'before_block_path',
									targetPath:
										TEMPLATE_MAIN_CONTENT_TARGET_PATH,
									expectedTarget:
										TEMPLATE_MAIN_CONTENT_TARGET,
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
	await enableMockedRecommendationSurfaces( page, [ 'template' ] );

	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplates ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template'
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

	const promptInput = await openTemplateRecommendationsPanel( page );
	const recommendationsPanel = getPanelBody( promptInput );
	await promptInput.fill( TEMPLATE_PROMPT );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect.poll( () => templateRequests.length ).toBe( 1 );
	expect( templateRequests[ 0 ].templateRef ).toBe(
		templateTarget.templateRef
	);
	expect( templateRequests[ 0 ].prompt ).toBe( TEMPLATE_PROMPT );
	expect( templateRequests[ 0 ] ).toHaveProperty( 'visiblePatternNames' );
	expect( templateRequests[ 0 ].visiblePatternNames ).toContain(
		TEMPLATE_PATTERN_NAME
	);

	await expect(
		page.getByText( TEMPLATE_SUGGESTION_LABEL ).first()
	).toBeVisible();
	await expectCollapsedRecommendationSummaryLane( recommendationsPanel );
	await page.getByRole( 'button', { name: 'Review' } ).click();
	await expect(
		page
			.locator( '.flavor-agent-review-section' )
			.getByText( 'Review Before Apply', { exact: true } )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Confirm Apply' } ).click();

	await expect
		.poll( () =>
			page.evaluate(
				( { patternName, insertedContent } ) => {
					const flavorAgent = window.wp.data.select( 'flavor-agent' );
					const blockEditor =
						window.wp.data.select( 'core/block-editor' );
					const operations =
						flavorAgent.getTemplateLastAppliedOperations?.() || [];
					const activityLog = flavorAgent.getActivityLog?.() || [];
					const lastActivity =
						activityLog[ activityLog.length - 1 ] || null;

					return {
						applyStatus:
							flavorAgent.getTemplateApplyStatus?.() || '',
						applyError: flavorAgent.getTemplateApplyError?.() || '',
						hasInsertOperation: operations.some(
							( operation ) =>
								operation?.type === 'insert_pattern' &&
								operation?.patternName === patternName
						),
						// Regression guard for the template null-root orphan:
						// the inserted pattern must land in the rendered block
						// tree, not be orphaned in byClientId by a null root.
						hasInsertedContent: JSON.stringify(
							blockEditor.getBlocks?.() || []
						).includes( insertedContent ),
						lastActivityType: lastActivity?.type || '',
					};
				},
				{
					patternName: TEMPLATE_PATTERN_NAME,
					insertedContent: TEMPLATE_INSERTED_CONTENT,
				}
			)
		)
		.toEqual( {
			applyStatus: 'success',
			applyError: '',
			hasInsertOperation: true,
			hasInsertedContent: true,
			lastActivityType: 'apply_template_suggestion',
		} );

	await page.getByRole( 'tab', { name: 'Template', exact: true } ).click();
	await openTemplateRecommendationsPanel( page );
	await reloadActivitySessionForCurrentEditorScope( page );
	await expect( page.getByText( 'Recent AI Actions' ) ).toBeVisible();
	await page
		.getByRole( 'button', { name: /Recent AI Actions/ } )
		.first()
		.click();
	await expect( page.locator( '.flavor-agent-activity-row' ) ).toContainText(
		'Clarify template hierarchy'
	);
} );

test( 'template surface keeps stale results visible but disables review and apply until refresh', async ( {
	page,
} ) => {
	const templateRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-template' ),
		async ( route ) => {
			templateRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_RESOLVED_CONTEXT_SIGNATURE,
					explanation: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PATTERN_NAME,
									placement: 'end',
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
	await enableMockedRecommendationSurfaces( page, [ 'template' ] );
	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplates ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
			).length > 0
	);

	await enableTemplateDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	} );

	let promptInput = await openTemplateRecommendationsPanel( page );
	let recommendationsPanel = getPanelBody( promptInput );

	await promptInput.fill( TEMPLATE_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Suggestions' } )
		.click();

	await expect.poll( () => templateRequests.length ).toBe( 1 );
	await expect(
		recommendationsPanel.getByText( 'Clarify template hierarchy' ).first()
	).toBeVisible();
	await recommendationsPanel
		.getByRole( 'button', { name: 'Review' } )
		.click();
	await expect(
		recommendationsPanel.getByText( 'Review Before Apply', { exact: true } )
	).toBeVisible();

	await insertRootParagraphBlock( page, TEMPLATE_STALE_INSERTED_CONTENT );
	await expect
		.poll(
			() =>
				page.evaluate( ( nextContent ) => {
					function hasParagraphContent( blocks ) {
						return blocks.some( ( block ) => {
							if (
								block?.name === 'core/paragraph' &&
								String(
									block?.attributes?.content || ''
								).includes( nextContent )
							) {
								return true;
							}

							return Array.isArray( block?.innerBlocks )
								? hasParagraphContent( block.innerBlocks )
								: false;
						} );
					}

					const blocks =
						window.wp?.data
							?.select( 'core/block-editor' )
							?.getBlocks?.() || [];

					return hasParagraphContent( blocks );
				}, TEMPLATE_STALE_INSERTED_CONTENT ),
			{ timeout: 15000 }
		)
		.toBe( true );
	await page.getByRole( 'tab', { name: 'Template', exact: true } ).click();
	promptInput = await openTemplateRecommendationsPanel( page );
	recommendationsPanel = getPanelBody( promptInput );

	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: TEMPLATE_STALE_NOTICE }
		)
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Reviewing\b/,
		} )
	).toBeDisabled();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Confirm Apply',
			exact: true,
		} )
	).toBeDisabled();

	const refreshButton = recommendationsPanel
		.getByRole( 'button', {
			name: 'Refresh',
			exact: true,
		} )
		.first();

	await expect( refreshButton ).toBeEnabled( { timeout: 15000 } );
	await refreshButton.click();

	await expect
		.poll( () => templateRequests.length, { timeout: 15000 } )
		.toBe( 2 );
	expect(
		templateRequests[ 1 ].editorStructure.topLevelBlockTree.length
	).toBe(
		templateRequests[ 0 ].editorStructure.topLevelBlockTree.length + 1
	);
	expect( templateRequests[ 1 ].editorStructure.topLevelBlockTree ).toEqual(
		expect.arrayContaining( [
			expect.objectContaining( {
				name: 'core/paragraph',
			} ),
		] )
	);
	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: TEMPLATE_STALE_NOTICE }
		)
	).toHaveCount( 0 );
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Review\b/,
		} )
	).toBeEnabled();
} );

test( 'template surface keeps advisory-only suggestions visible without executable controls', async ( {
	page,
} ) => {
	const templateRequests = [];

	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );
	await openFirstTemplateEditor( page );
	await dismissSiteEditorWelcomeGuide( page );
	await enableMockedRecommendationSurfaces( page, [ 'template' ] );
	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplates ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
			).length > 0
	);
	await page.route(
		recommendationAbilityRoute( 'recommend-template' ),
		async ( route ) => {
			templateRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_RESOLVED_CONTEXT_SIGNATURE,
					explanation: 'One advisory idea is available.',
					suggestions: [
						{
							label: 'Explore an editorial collage',
							description:
								'Consider a more magazine-like grouping before the footer.',
							operations: [],
							templateParts: [
								{
									slug: 'footer',
									area: 'footer',
									reason: 'Tighten the entry sequence before the primary content.',
								},
							],
							patternSuggestions: [ TEMPLATE_PATTERN_NAME ],
						},
					],
				} ),
			} );
		}
	);

	await enableTemplateDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	} );

	const promptInput = await openTemplateRecommendationsPanel( page );
	const recommendationsPanel = getPanelBody( promptInput );

	await promptInput.fill( TEMPLATE_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Suggestions' } )
		.click();

	await expect.poll( () => templateRequests.length ).toBe( 1 );
	await expectCollapsedRecommendationSummaryLane( recommendationsPanel );
	await expect(
		recommendationsPanel.getByText( 'Explore an editorial collage' ).first()
	).toBeVisible();
	const advisoryCard = recommendationsPanel
		.locator( '.flavor-agent-card--template' )
		.filter( { hasText: 'Explore an editorial collage' } )
		.first();

	await advisoryCard
		.locator( 'details.flavor-agent-card__details summary' )
		.click();
	await expect(
		recommendationsPanel.getByText( 'Template Parts' )
	).toBeVisible();
	await expect(
		recommendationsPanel.getByText( 'Suggested Patterns' )
	).toBeVisible();
	await expect(
		recommendationsPanel.getByText( 'Review in editor' )
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Browse pattern',
			exact: true,
		} )
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Review',
			exact: true,
		} )
	).toHaveCount( 0 );
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Confirm Apply',
			exact: true,
		} )
	).toHaveCount( 0 );
	await expect
		.poll( () => getSurfaceActivityCount( page, 'template' ) )
		.toBe( 0 );
} );

test( 'template surface explains unavailable plugin backends', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	} );
	await waitForWordPressReady( page );
	await waitForFlavorAgent( page );
	await dismissWelcomeGuide( page );

	await page.evaluate( () => {
		const data = window.flavorAgentData || {};

		data.canRecommendTemplates = false;

		if ( data.capabilities?.surfaces?.template ) {
			data.capabilities.surfaces.template.available = false;
			data.capabilities.surfaces.template.reason =
				'plugin_provider_unconfigured';
		}
	} );
	await openFirstTemplateEditor( page );
	await dismissSiteEditorWelcomeGuide( page );
	await page.waitForFunction(
		() =>
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template'
	);
	await enableTemplateDocumentSidebar( page );

	const templateNotice = page
		.locator( '.flavor-agent-capability-notice .flavor-agent-panel__note' )
		.filter( {
			hasText:
				'Configure a text-generation provider in Settings > Connectors to enable template recommendations.',
		} );

	await ensurePanelOpen(
		page,
		'AI Template Recommendations',
		templateNotice
	);
	await expect(
		page.getByRole( 'link', { name: 'Settings > Connectors' } )
	).toBeVisible();
	await expect(
		page.getByPlaceholder( 'Describe the structure or layout you want.' )
	).toHaveCount( 0 );
} );

test( '@wp70-site-editor template-part surface smoke previews, applies, and undoes executable recommendations', async ( {
	page,
} ) => {
	const templatePartRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-template-part' ),
		async ( route ) => {
			templatePartRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_PART_RESOLVED_CONTEXT_SIGNATURE,
					explanation:
						'Add a compact utility row at the end of the header part.',
					suggestions: [
						{
							label: 'Add utility row',
							description:
								'Insert a compact row at the end of this header part.',
							blockHints: [
								{
									path: [ 0 ],
									label: 'Header wrapper',
									reason: 'Keep the insertion inside the existing container.',
								},
							],
							patternSuggestions: [ TEMPLATE_PART_PATTERN_NAME ],
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PART_PATTERN_NAME,
									placement: 'after_block_path',
									targetPath: [ 0, 1 ],
								},
							],
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

	const templateTarget = await getTemplateTarget( page );
	const templatePartRef =
		buildTemplatePartRefFromTemplateTarget( templateTarget );

	expect( templatePartRef ).toBeTruthy();

	await openTemplatePartEditor( page, templatePartRef );
	await enableMockedRecommendationSurfaces( page, [ 'template-part' ] );
	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplateParts ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template_part'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
			).length > 0
	);

	await enableSiteEditorDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_PART_INSERTED_CONTENT,
		patternName: TEMPLATE_PART_PATTERN_NAME,
		patternTitle: TEMPLATE_PART_PATTERN_TITLE,
	} );

	const promptInput = await openTemplatePartRecommendationsPanel( page );
	const recommendationsPanel = getPanelBody( promptInput );
	await promptInput.fill( TEMPLATE_PART_PROMPT );
	await page.getByRole( 'button', { name: 'Get Suggestions' } ).click();

	await expect.poll( () => templatePartRequests.length ).toBe( 1 );
	expect( templatePartRequests[ 0 ].templatePartRef ).toBe( templatePartRef );
	expect( templatePartRequests[ 0 ].prompt ).toBe( TEMPLATE_PART_PROMPT );
	expect( templatePartRequests[ 0 ] ).toHaveProperty( 'visiblePatternNames' );
	expect( templatePartRequests[ 0 ].visiblePatternNames ).toContain(
		TEMPLATE_PART_PATTERN_NAME
	);

	await expect(
		page.getByText( TEMPLATE_PART_SUGGESTION_LABEL ).first()
	).toBeVisible();
	await expectCollapsedRecommendationSummaryLane( recommendationsPanel );
	await page.getByRole( 'button', { name: 'Review' } ).click();
	await expect(
		page
			.locator( '.flavor-agent-review-section' )
			.getByText( 'Review Before Apply', { exact: true } )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Confirm Apply' } ).click();

	await expect
		.poll( () =>
			getTemplatePartInsertState( page, TEMPLATE_PART_INSERTED_CONTENT )
		)
		.toEqual( {
			hasInsertedContent: true,
			undoStatus: 'available',
		} );

	await page
		.getByRole( 'tab', { name: 'Template Part', exact: true } )
		.click();
	await openTemplatePartRecommendationsPanel( page );
	await reloadActivitySessionForCurrentEditorScope( page );
	const recentActionsToggle = page
		.getByRole( 'button', { name: /Recent AI Actions 1 action/ } )
		.first();
	if (
		( await recentActionsToggle.getAttribute( 'aria-expanded' ) ) !== 'true'
	) {
		await recentActionsToggle.click();
	}
	const templatePartActivityRow = page
		.locator( '.flavor-agent-activity-row' )
		.filter( { hasText: TEMPLATE_PART_SUGGESTION_LABEL } )
		.first();
	await expect( templatePartActivityRow ).toContainText( 'Undo available' );
	const applyToast = page.locator( '.flavor-agent-toast' ).first();
	if ( await applyToast.isVisible().catch( () => false ) ) {
		await applyToast
			.getByRole( 'button', { name: 'Dismiss', exact: true } )
			.click();
		await expect( applyToast ).toBeHidden();
	}
	const templatePartUndoButton = templatePartActivityRow
		.getByRole( 'button', { name: 'Undo', exact: true } )
		.first();
	await templatePartUndoButton.scrollIntoViewIfNeeded();
	await expect( templatePartUndoButton ).toBeVisible();
	await templatePartUndoButton.click();

	await expect
		.poll( () =>
			getTemplatePartInsertState( page, TEMPLATE_PART_INSERTED_CONTENT )
		)
		.toEqual( {
			hasInsertedContent: false,
			undoStatus: 'undone',
		} );
} );

test( '@wp70-site-editor template-part surface keeps stale results visible but disables review and apply until refresh', async ( {
	page,
} ) => {
	const templatePartRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-template-part' ),
		async ( route ) => {
			templatePartRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_PART_RESOLVED_CONTEXT_SIGNATURE,
					explanation:
						'Add a compact utility row at the end of the header part.',
					suggestions: [
						{
							label: 'Add utility row',
							description:
								'Insert a compact row at the end of this header part.',
							blockHints: [
								{
									path: [ 0 ],
									label: 'Header wrapper',
									reason: 'Keep the insertion inside the existing container.',
								},
							],
							patternSuggestions: [ TEMPLATE_PART_PATTERN_NAME ],
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PART_PATTERN_NAME,
									placement: 'after_block_path',
									targetPath: [ 0, 1 ],
								},
							],
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

	const templateTarget = await getTemplateTarget( page );
	const templatePartRef =
		buildTemplatePartRefFromTemplateTarget( templateTarget );

	expect( templatePartRef ).toBeTruthy();

	await openTemplatePartEditor( page, templatePartRef );
	await enableMockedRecommendationSurfaces( page, [ 'template-part' ] );
	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplateParts ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template_part'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
			).length > 0
	);

	await enableSiteEditorDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_PART_INSERTED_CONTENT,
		patternName: TEMPLATE_PART_PATTERN_NAME,
		patternTitle: TEMPLATE_PART_PATTERN_TITLE,
	} );

	let promptInput = await openTemplatePartRecommendationsPanel( page );
	let recommendationsPanel = getPanelBody( promptInput );

	await promptInput.fill( TEMPLATE_PART_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Suggestions' } )
		.click();

	await expect.poll( () => templatePartRequests.length ).toBe( 1 );
	await expect(
		recommendationsPanel.getByText( 'Add utility row' ).first()
	).toBeVisible();
	await recommendationsPanel
		.getByRole( 'button', { name: 'Review' } )
		.click();
	await expect(
		recommendationsPanel.getByText( 'Review Before Apply', { exact: true } )
	).toBeVisible();

	await setFirstRootBlockPatternOverride( page, 'layout' );
	await expect
		.poll( () =>
			page.evaluate( () => {
				const firstBlock =
					window.wp?.data
						?.select( 'core/block-editor' )
						?.getBlocks?.()?.[ 0 ] || null;

				return (
					firstBlock?.attributes?.metadata?.bindings?.layout
						?.source || ''
				);
			} )
		)
		.toBe( 'core/pattern-overrides' );
	await page
		.getByRole( 'tab', { name: 'Template Part', exact: true } )
		.click();
	promptInput = await openTemplatePartRecommendationsPanel( page );
	recommendationsPanel = getPanelBody( promptInput );

	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: TEMPLATE_PART_STALE_NOTICE }
		)
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Reviewing\b/,
		} )
	).toBeDisabled();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Confirm Apply',
			exact: true,
		} )
	).toBeDisabled();

	await recommendationsPanel
		.locator( '.flavor-agent-scope-bar__refresh' )
		.click();

	await expect.poll( () => templatePartRequests.length ).toBe( 2 );
	expect(
		templatePartRequests[ 1 ].editorStructure.currentPatternOverrides
			.hasOverrides
	).toBe( true );
	expect(
		templatePartRequests[ 1 ].editorStructure.currentPatternOverrides
			.blockCount
	).toBeGreaterThan(
		templatePartRequests[ 0 ].editorStructure.currentPatternOverrides
			.blockCount
	);
	expect(
		templatePartRequests[ 1 ].editorStructure.currentPatternOverrides.blocks
	).toEqual(
		expect.arrayContaining( [
			expect.objectContaining( {
				overrideAttributes: [ 'layout' ],
			} ),
		] )
	);
	await expect(
		recommendationsPanel.locator(
			'.flavor-agent-scope-bar__stale-message',
			{ hasText: TEMPLATE_PART_STALE_NOTICE }
		)
	).toHaveCount( 0 );
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: /^Review\b/,
		} )
	).toBeEnabled();
} );

test( '@wp70-site-editor template-part surface keeps advisory-only suggestions visible without executable controls', async ( {
	page,
} ) => {
	const templatePartRequests = [];

	await page.route(
		recommendationAbilityRoute( 'recommend-template-part' ),
		async ( route ) => {
			templatePartRequests.push(
				getAbilityRequestInput( route.request().postDataJSON() )
			);
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_PART_RESOLVED_CONTEXT_SIGNATURE,
					explanation: 'One advisory idea is available.',
					suggestions: [
						{
							label: 'Introduce utility links',
							description:
								'Add a compact utility-links pattern near the navigation block.',
							blockHints: [
								{
									path: [ 0 ],
									label: 'Header wrapper',
									blockName: 'core/group',
									reason: 'Keep the change inside the existing header container.',
								},
							],
							patternSuggestions: [ TEMPLATE_PART_PATTERN_NAME ],
							operations: [],
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

	const templateTarget = await getTemplateTarget( page );
	const templatePartRef =
		buildTemplatePartRefFromTemplateTarget( templateTarget );

	expect( templatePartRef ).toBeTruthy();

	await openTemplatePartEditor( page, templatePartRef );
	await enableMockedRecommendationSurfaces( page, [ 'template-part' ] );
	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplateParts ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template_part'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
			).length > 0
	);

	await enableSiteEditorDocumentSidebar( page );
	await registerTemplatePattern( page, {
		insertedContent: TEMPLATE_PART_INSERTED_CONTENT,
		patternName: TEMPLATE_PART_PATTERN_NAME,
		patternTitle: TEMPLATE_PART_PATTERN_TITLE,
	} );

	const promptInput = await openTemplatePartRecommendationsPanel( page );
	const recommendationsPanel = getPanelBody( promptInput );
	const initialTemplatePartActivityCount = await getSurfaceActivityCount(
		page,
		'template-part'
	);

	await promptInput.fill( TEMPLATE_PART_PROMPT );
	await recommendationsPanel
		.getByRole( 'button', { name: 'Get Suggestions' } )
		.click();

	await expect.poll( () => templatePartRequests.length ).toBe( 1 );
	await expectCollapsedRecommendationSummaryLane( recommendationsPanel );
	await expect(
		recommendationsPanel.getByText( 'Introduce utility links' ).first()
	).toBeVisible();
	const advisoryCard = recommendationsPanel
		.locator( '.flavor-agent-card--template' )
		.filter( { hasText: 'Introduce utility links' } )
		.first();

	await advisoryCard
		.locator( 'details.flavor-agent-card__details summary' )
		.click();
	await expect(
		recommendationsPanel.getByText( 'Focus Blocks', { exact: true } )
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Browse pattern',
			exact: true,
		} )
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Review',
			exact: true,
		} )
	).toHaveCount( 0 );
	await expect(
		recommendationsPanel.getByRole( 'button', {
			name: 'Confirm Apply',
			exact: true,
		} )
	).toHaveCount( 0 );
	await expect
		.poll( () => getSurfaceActivityCount( page, 'template-part' ) )
		.toBe( initialTemplatePartActivityCount );
} );

test( '@wp70-site-editor template undo survives a Site Editor refresh when the template has not drifted', async ( {
	page,
} ) => {
	resetWp70TemplateSmokeState();

	await page.route(
		recommendationAbilityRoute( 'recommend-template' ),
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_RESOLVED_CONTEXT_SIGNATURE,
					explanation: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PATTERN_NAME,
									placement: 'before_block_path',
									targetPath:
										TEMPLATE_MAIN_CONTENT_TARGET_PATH,
									expectedTarget:
										TEMPLATE_MAIN_CONTENT_TARGET,
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
	await enableMockedRecommendationSurfaces( page, [ 'template' ] );
	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplates ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
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
	await expect(
		page.getByText( TEMPLATE_SUGGESTION_LABEL ).first()
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Review' } ).click();
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock();
	} );
	await page.getByRole( 'button', { name: 'Confirm Apply' } ).click();
	const templateEditorUrl = page.url();

	await expect
		.poll(
			async () =>
				(
					await getTemplateInsertState(
						page,
						TEMPLATE_INSERTED_CONTENT
					)
				).undoStatus
		)
		.toBe( 'available' );
	await expect
		.poll( () =>
			page.evaluate( () => {
				const flavorAgent = window.wp.data.select( 'flavor-agent' );
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity =
					[ ...activityLog ]
						.reverse()
						.find(
							( entry ) =>
								entry?.surface === 'template' &&
								entry?.type !== 'request_diagnostic'
						) || null;

				return lastActivity?.persistence?.status || '';
			} )
		)
		.toBe( 'server' );
	await saveCurrentPost( page );

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
	await enableMockedRecommendationSurfaces( page, [ 'template' ] );
	await page.waitForFunction(
		() =>
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
			).length > 0
	);

	await enableTemplateDocumentSidebar( page );
	await page.getByRole( 'tab', { name: 'Template', exact: true } ).click();
	await openTemplateRecommendationsPanel( page );
	const recentActionsToggle = page
		.getByRole( 'button', { name: /Recent AI Actions 1 action/ } )
		.first();
	if (
		( await recentActionsToggle.getAttribute( 'aria-expanded' ) ) !== 'true'
	) {
		await recentActionsToggle.click();
	}
	const templateUndoButton = page
		.locator( '.flavor-agent-activity-row' )
		.getByRole( 'button', { name: 'Undo', exact: true } )
		.first();
	await templateUndoButton.scrollIntoViewIfNeeded();
	await expect( templateUndoButton ).toBeVisible();
	await templateUndoButton.click();

	await expect
		.poll( () => getTemplateInsertState( page, TEMPLATE_INSERTED_CONTENT ) )
		.toEqual( {
			hasInsertedContent: false,
			undoStatus: 'undone',
		} );
	await saveCurrentPost( page );
} );

test( '@wp70-site-editor template undo is disabled after inserted pattern content changes', async ( {
	page,
} ) => {
	resetWp70TemplateSmokeState();

	const editedInsertedContent = 'Inserted content edited after apply';

	await page.route(
		recommendationAbilityRoute( 'recommend-template' ),
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					resolvedContextSignature:
						TEMPLATE_RESOLVED_CONTEXT_SIGNATURE,
					explanation: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
					suggestions: [
						{
							label: 'Clarify template hierarchy',
							description: `Insert ${ TEMPLATE_PATTERN_TITLE } into the template flow.`,
							operations: [
								{
									type: 'insert_pattern',
									patternName: TEMPLATE_PATTERN_NAME,
									placement: 'before_block_path',
									targetPath:
										TEMPLATE_MAIN_CONTENT_TARGET_PATH,
									expectedTarget:
										TEMPLATE_MAIN_CONTENT_TARGET,
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
	await enableMockedRecommendationSurfaces( page, [ 'template' ] );
	await page.waitForFunction(
		() =>
			Boolean( window.flavorAgentData?.canRecommendTemplates ) &&
			window.wp?.data
				?.select( 'core/edit-site' )
				?.getEditedPostType?.() === 'wp_template'
	);
	await page.waitForFunction(
		() =>
			(
				window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.() ||
				[]
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
	await expect(
		page.getByText( TEMPLATE_SUGGESTION_LABEL ).first()
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Review' } ).click();
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock();
	} );
	await page.getByRole( 'button', { name: 'Confirm Apply' } ).click();

	await expect
		.poll(
			async () =>
				(
					await getTemplateInsertState(
						page,
						TEMPLATE_INSERTED_CONTENT
					)
				).undoStatus
		)
		.toBe( 'available' );

	await page.evaluate(
		( { previousContent, nextContent } ) => {
			function findParagraphBlock( blocks ) {
				for ( const block of blocks ) {
					const content = String( block?.attributes?.content || '' );

					if (
						block?.name === 'core/paragraph' &&
						content.includes( previousContent )
					) {
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

			const blockEditor = window.wp.data.select( 'core/block-editor' );
			const blockTree = blockEditor.getBlocks?.() || [];
			const insertedBlock = findParagraphBlock( blockTree );

			if ( insertedBlock?.clientId ) {
				window.wp.data
					.dispatch( 'core/block-editor' )
					.updateBlockAttributes( insertedBlock.clientId, {
						content: nextContent,
					} );
			}
		},
		{
			previousContent: TEMPLATE_INSERTED_CONTENT,
			nextContent: editedInsertedContent,
		}
	);
	await expect
		.poll( () =>
			page.evaluate(
				( { nextContent } ) => {
					function hasEditedParagraph( blocks ) {
						for ( const block of blocks ) {
							const content = String(
								block?.attributes?.content || ''
							);

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
				},
				{ nextContent: editedInsertedContent }
			)
		)
		.toBe( true );

	await page.getByRole( 'tab', { name: 'Template', exact: true } ).click();
	await openTemplateRecommendationsPanel( page );

	await page.evaluate( async () => {
		const flavorAgent = window.wp.data.select( 'flavor-agent' );
		const activityLog = flavorAgent.getActivityLog?.() || [];
		const lastActivity =
			[ ...activityLog ]
				.reverse()
				.find(
					( entry ) =>
						entry?.surface === 'template' &&
						entry?.type !== 'request_diagnostic'
				) || null;

		if ( lastActivity?.id ) {
			await window.wp.data
				.dispatch( 'flavor-agent' )
				.undoActivity( lastActivity.id );
		}
	} );

	await expect
		.poll( () =>
			page.evaluate( () => {
				const flavorAgent = window.wp.data.select( 'flavor-agent' );
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity =
					[ ...activityLog ]
						.reverse()
						.find(
							( entry ) =>
								entry?.surface === 'template' &&
								entry?.type !== 'request_diagnostic'
						) || null;

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
	const recentActionsToggle = page.getByRole( 'button', {
		name: /Recent AI Actions 1 action/,
	} );
	if (
		( await recentActionsToggle.getAttribute( 'aria-expanded' ) ) !== 'true'
	) {
		await recentActionsToggle.click();
	}
	await expect(
		page
			.locator( '.flavor-agent-activity-row' )
			.getByText(
				'Inserted pattern content changed after apply and cannot be undone automatically.'
			)
	).toBeVisible();
	await expect(
		page
			.locator( '.flavor-agent-activity-row' )
			.getByRole( 'button', { name: 'Undo', exact: true } )
	).toHaveCount( 0 );
	await saveCurrentPost( page );
	await expect
		.poll( () =>
			page.evaluate(
				( { nextContent } ) => {
					function hasEditedParagraph( blocks ) {
						for ( const block of blocks ) {
							const content = String(
								block?.attributes?.content || ''
							);

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
				},
				{ nextContent: editedInsertedContent }
			)
		)
		.toBe( true );
} );
