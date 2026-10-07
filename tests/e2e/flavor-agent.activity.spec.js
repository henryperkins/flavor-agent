const { test, expect } = require( './test-fixtures' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const wp70Harness = getWp70HarnessConfig();

test( 'AI Activity exports an explicit local candidate with separate restricted custody', async ( { page } ) => {
	let exportRequests = 0;
	const candidate = {
		schemaVersion: 'recommendation-fixture-export-v1',
		provenance: { kind: 'synthetic_fixture', implementationSha: 'a'.repeat( 40 ), publicVersions: { plugin: '0.1.1', wordpress: '7.1.3', ranking: 'contextual-ranking-v1', validationVocabulary: 'validation-reasons-v1', report: 'governance-learning-report-v1' }, config: { surfaces: [ 'block' ], rankingMode: 'static' } },
		sample: { rowLimit: 500, sampleSize: 0, exportedRowCount: 0, excludedRowCount: 0, truncated: false, shownSetCount: 0, shownSuggestionCount: 0, unlinkedApplyCount: 0, missingIdentityCount: 0 },
		rows: [], coverage: { surfaces: [ 'block' ], missingShownLinks: 0, missingApplyLinks: 0, unverifiedCoverageCount: 0 },
		metrics: [ { name: 'reviewSelectionRate', numerator: 0, denominator: 0, coverage: 'no_denominator' } ],
	};
	await page.route( '**/wp-json/flavor-agent/v1/activity**', async ( route ) => {
		if ( route.request().url().includes( '/fixture-export' ) ) {
			++exportRequests;
			expect( route.request().method() ).toBe( 'POST' );
			expect( route.request().postDataJSON() ).toEqual( { selection: { dateFrom: '2026-10-01', dateTo: '2026-10-07', surfaces: [ 'block' ], rowLimit: 500 } } );
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { candidate, rawActivity: { prompt: 'Private raw text must never download' } } ) } );
		} else {
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( buildActivityResponse( route.request().url() ) ) } );
		}
	} );
	await page.goto( '/wp-admin/options-general.php?page=flavor-agent-activity', { waitUntil: 'domcontentloaded' } );
	await waitForWordPressReady( page );
	const disclosure = page.locator( '.flavor-agent-activity-log__fixture-export' );
	await disclosure.locator( 'summary' ).click();
	await disclosure.locator( 'summary' ).click();
	expect( exportRequests ).toBe( 0 );
	await disclosure.locator( 'summary' ).click();
	await page.getByLabel( 'From (UTC)', { exact: true } ).fill( '2026-10-01' );
	await page.getByLabel( 'Through (UTC)', { exact: true } ).fill( '2026-10-07' );
	await page.getByLabel( 'Source custodian (local record)', { exact: true } ).fill( 'Fixture reviewer' );
	await page.getByLabel( 'Source site (local record)', { exact: true } ).fill( 'private.fixture.test' );
	await page.getByLabel( 'Collection permission and scope (local record)', { exact: true } ).fill( 'Approved local fixture sample' );
	await page.getByLabel( 'Block', { exact: true } ).check();
	const downloading = page.waitForEvent( 'download' );
	await page.getByRole( 'button', { name: 'Download local review bundle', exact: true } ).click();
	const stream = await ( await downloading ).createReadStream();
	const chunks = [];
	for await ( const chunk of stream ) { chunks.push( chunk ); }
	const bundle = JSON.parse( Buffer.concat( chunks ).toString( 'utf8' ) );
	expect( bundle.candidate ).toEqual( candidate );
	expect( bundle.reviewRecord ).toMatchObject( { site: 'private.fixture.test', custodian: 'Fixture reviewer', reviewerDecision: 'pending' } );
	expect( JSON.stringify( bundle.candidate ) ).not.toContain( 'private.fixture.test' );
	expect( JSON.stringify( bundle ) ).not.toContain( 'Private raw text' );
	expect( exportRequests ).toBe( 1 );
} );

const ACTIVITY_ENTRIES = [
	{
		id: 'activity-block-1',
		type: 'apply_block_suggestion',
		surface: 'block',
		target: {
			clientId: 'block-1',
			blockName: 'core/paragraph',
			blockPath: [ 0 ],
		},
		suggestion: 'Rewrite hero heading',
		before: {
			attributes: {
				content: 'Before',
			},
		},
		after: {
			attributes: {
				content: 'After',
			},
		},
		request: {
			prompt: 'Make the hero heading clearer.',
			reference: 'post:42:0',
		},
		document: {
			scopeKey: 'post:42',
			postType: 'post',
			entityId: '42',
		},
		timestamp: '2026-03-24T10:00:00Z',
	},
	{
		id: 'activity-template-1',
		type: 'apply_template_suggestion',
		surface: 'template',
		target: {
			templateRef: 'theme//home',
		},
		suggestion: 'Refresh template hierarchy',
		before: {
			operations: [],
		},
		after: {
			operations: [
				{
					type: 'insert_pattern',
					patternName: 'theme/hero',
				},
			],
		},
		request: {
			prompt: 'Make the home template feel more editorial.',
			reference: 'template:theme//home:3',
		},
		document: {
			scopeKey: 'wp_template:theme//home',
			postType: 'wp_template',
			entityId: 'theme//home',
		},
		timestamp: '2026-03-24T10:05:00Z',
	},
];

function buildActivityResponse(
	requestUrl,
	entries = ACTIVITY_ENTRIES,
	overrides = {}
) {
	const url = new URL( requestUrl );
	const search = ( url.searchParams.get( 'search' ) || '' )
		.trim()
		.toLowerCase();
	const filteredEntries = search
		? entries.filter( ( entry ) =>
				[
					entry.suggestion,
					entry.surface,
					entry.document?.postType,
					entry.request?.prompt,
				]
					.filter( Boolean )
					.some( ( value ) =>
						String( value ).toLowerCase().includes( search )
					)
		  )
		: entries;

	return {
		entries: filteredEntries,
		filterOptions: overrides.filterOptions || {
			surface: [
				{ value: 'block', label: 'Block' },
				{ value: 'template', label: 'Template' },
			],
			operationType: [
				{ value: 'modify-attributes', label: 'Modify attributes' },
				{ value: 'insert', label: 'Insert pattern' },
			],
			postType: [
				{ value: 'post', label: 'post' },
				{ value: 'wp_template', label: 'wp_template' },
			],
		},
		paginationInfo: {
			page: 1,
			perPage: Number( url.searchParams.get( 'perPage' ) || 20 ),
			totalItems: filteredEntries.length,
			totalPages: filteredEntries.length > 0 ? 1 : 0,
		},
		summary: {
			total: filteredEntries.length,
			applied: filteredEntries.length,
			undone: 0,
			review: 0,
			blocked: 0,
			failed: 0,
			...( overrides.summary || {} ),
		},
	};
}

test( 'AI Activity page loads entries, updates selection, and exposes the filters UI', async ( {
	page,
} ) => {
	await page.route(
		'**/wp-json/flavor-agent/v1/activity**',
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(
					buildActivityResponse( route.request().url() )
				),
			} );
		}
	);

	await page.goto(
		'/wp-admin/options-general.php?page=flavor-agent-activity',
		{
			waitUntil: 'domcontentloaded',
		}
	);
	await waitForWordPressReady( page );

	await expect(
		page.locator( '#flavor-agent-activity-log-root' )
	).toBeVisible( {
		timeout: 30_000,
	} );
	await expect(
		page.getByRole( 'heading', { name: 'AI Activity Log' } )
	).toBeVisible( { timeout: 30_000 } );
	await expect(
		page.locator( '.flavor-agent-activity-log__summary-item' )
	).toHaveCount( 9, { timeout: 30_000 } );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Refresh template hierarchy' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Rewrite hero heading' );
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'Rewrite hero heading' );

	await page
		.locator( '.flavor-agent-activity-log__feed' )
		.getByText( 'Refresh template hierarchy', { exact: true } )
		.click();
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'Refresh template hierarchy' );

	await page.getByLabel( 'Search AI activity' ).fill( 'template' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Refresh template hierarchy' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).not.toContainText( 'Rewrite hero heading' );

	await expect(
		page.getByRole( 'button', { name: 'Add filter' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'View options' } )
	).toBeVisible();
} );

test( 'AI Activity identifies a model response that exhausted its token budget', async ( {
	page,
} ) => {
	const error =
		'The AI model reached a token limit before completing the response.';
	const entry = {
		...ACTIVITY_ENTRIES[ 0 ],
		id: 'activity-output-limit',
		type: 'request_diagnostic',
		suggestion: 'Block request failed: ' + error,
		undo: { status: 'failed', canUndo: false, error },
		request: {
			ai: {
				providerLabel: 'Anthropic',
				model: 'claude-sonnet-5',
				requestSummary: { maxOutputTokens: 16384 },
				responseSummary: {
					bodyBytes: 0,
					processingMs: 55720,
					finishReason: 'length',
				},
				errorSummary: {
					code: 'incomplete_response',
					wrappedMessage: error,
				},
			},
		},
	};
	await page.route(
		'**/wp-json/flavor-agent/v1/activity**',
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(
					buildActivityResponse( route.request().url(), [ entry ], {
						summary: { applied: 0, failed: 1 },
					} )
				),
			} );
		}
	);
	await page.goto(
		'/wp-admin/options-general.php?page=flavor-agent-activity',
		{
			waitUntil: 'domcontentloaded',
		}
	);
	await waitForWordPressReady( page );
	const sidebar = page.locator( '.flavor-agent-activity-log__sidebar' );
	await expect( sidebar ).toContainText( 'Request failed' );
	await sidebar.getByText( 'Provider diagnostics', { exact: true } ).click();
	await expect(
		sidebar.getByText( 'claude-sonnet-5', { exact: true } )
	).toBeVisible();
	await expect(
		sidebar.getByText( '16384 max output tokens', { exact: true } )
	).toBeVisible();
	await expect(
		sidebar.getByText(
			'0 bytes · 55720 ms processing · finish reason length',
			{
				exact: true,
			}
		)
	).toBeVisible();
	await expect(
		sidebar.getByText( error, { exact: true } ).last()
	).toBeVisible();
} );

test( 'AI Activity linked-row mode clears the URL and keeps discovery badges visible', async ( {
	page,
} ) => {
	const discoveryEntries = [
		{
			...ACTIVITY_ENTRIES[ 0 ],
			request: {
				...ACTIVITY_ENTRIES[ 0 ].request,
				ai: {
					requestLogId: 'request-log-1',
					requestToken: 'request-token-1',
				},
			},
			attestation: {
				id: 'att_abc123',
			},
		},
		ACTIVITY_ENTRIES[ 1 ],
	];

	await page.route(
		'**/wp-json/flavor-agent/v1/activity**',
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(
					buildActivityResponse(
						route.request().url(),
						discoveryEntries
					)
				),
			} );
		}
	);

	await page.goto(
		'/wp-admin/options-general.php?page=flavor-agent-activity&activity=activity-block-1',
		{
			waitUntil: 'domcontentloaded',
		}
	);
	await waitForWordPressReady( page );

	await expect(
		page.locator( '.flavor-agent-activity-log__linked-row-banner' )
	).toContainText( 'Focused row' );
	await expect(
		page.locator( '.flavor-agent-activity-log__linked-row-banner' )
	).toContainText( 'Rewrite hero heading' );
	await expect(
		page.locator( '.flavor-agent-activity-log__entry-badge', {
			hasText: 'AI request',
		} )
	).toContainText( 'AI request' );
	await expect(
		page.locator( '.flavor-agent-activity-log__entry-badge', {
			hasText: 'Attestation',
		} )
	).toContainText( 'Attestation' );
	await expect(
		page.getByRole( 'link', { name: /Open target/i } )
	).toHaveAttribute(
		'href',
		/http:\/\/127\.0\.0\.1:\d+\/wp-admin\/post\.php\?post=42&action=edit$/
	);

	await page.getByRole( 'button', { name: 'Clear focused row' } ).click();

	await expect( page ).toHaveURL(
		/\/wp-admin\/options-general\.php\?page=flavor-agent-activity$/
	);
	await expect(
		page.locator( '.flavor-agent-activity-log__linked-row-banner' )
	).toHaveCount( 0 );
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'Rewrite hero heading' );
} );

for ( const harnessTag of [ '', '@wp70-site-editor ' ] ) {
	for ( const [ label, surface, signingAvailable, warn ] of [
		[ 'unavailable global styles signing', 'global-styles', false, true ],
		[ 'unavailable style book signing', 'style-book', false, true ],
		[ 'unavailable template signing', 'template', false, true ],
		[ 'unavailable template part signing', 'template-part', false, true ],
		[ 'available signing', 'global-styles', true, false ],
		[ 'post blocks approval', 'post-blocks', false, false ],
	] ) {
		test( `${ harnessTag }AI Activity attestation advisory keeps approvals enabled with ${ label }`, async ( {
			page,
		}, testInfo ) => {
			const entry = {
				id: 'activity-attestation-advisory',
				type: `apply_${ surface.replaceAll( '-', '_' ) }_suggestion`,
				surface,
				applyLane: 'server-executed',
				status: 'pending',
				suggestion: 'Review this proposed change',
				target: {
					globalStylesId: '17',
					templateRef: 'theme//home',
					postId: 42,
				},
				document: {
					scopeKey: 'global_styles:17',
					postType: 'global_styles',
					entityId: '17',
				},
				before: {},
				after: {},
				request: {},
				apply: {
					status: 'pending',
					requestedBy: 7,
					expiresAt: '2030-06-10T00:00:00Z',
					operations: [],
				},
				timestamp: '2026-06-10T00:00:00Z',
			};
			// Configuration is a page-load fixture; no site keys or provider calls are needed.
			await page.addInitScript( ( available ) => {
				let boot;
				Object.defineProperty( window, 'flavorAgentActivityLog', {
					configurable: true,
					get: () => boot,
					set: ( value ) => {
						boot = {
							...value,
							attestation: {
								signingAvailable: available,
								eligibleSurfaces: [
									'global-styles',
									'style-book',
									'template',
									'template-part',
								],
							},
						};
					},
				} );
			}, signingAvailable );
			await page.route(
				'**/wp-json/flavor-agent/v1/activity**',
				async ( route ) => {
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify(
							route.request().url().includes( '/claim' )
								? { claim: null, entry }
								: buildActivityResponse(
										route.request().url(),
										[ entry ]
								  )
						),
					} );
				}
			);
			await page.goto(
				'/wp-admin/options-general.php?page=flavor-agent-activity'
			);
			await waitForWordPressReady( page );
			const decision = page.locator(
				'.flavor-agent-activity-log__decision'
			);
			const warning = decision.locator( '.components-notice.is-warning' );
			const approve = decision.getByRole( 'button', {
				name: 'Approve and apply',
			} );
			await expect( approve ).toBeEnabled();
			await expect(
				decision.getByRole( 'button', { name: 'Reject', exact: true } )
			).toBeEnabled();
			if ( warn ) {
				await expect( warning ).toBeVisible();
				await expect(
					warning.locator( '.components-notice__content' )
				).toHaveText(
					'Attestation signing is unavailable. Approving this change will apply it without a signed attestation.'
				);
				await expect( warning.getByRole( 'button' ) ).toHaveCount( 0 );
				expect(
					await decision.evaluate( ( element ) => {
						const notice = element.querySelector(
							'.components-notice.is-warning'
						);
						return (
							notice.compareDocumentPosition(
								element.querySelector( 'textarea' )
							) === window.Node.DOCUMENT_POSITION_FOLLOWING
						);
					} )
				).toBe( true );
				if ( surface === 'global-styles' ) {
					await warning.scrollIntoViewIfNeeded();
					await testInfo.attach( 'Attestation advisory', {
						body: await page.screenshot( {
							path: testInfo.outputPath(
								'attestation-advisory.png'
							),
						} ),
						contentType: 'image/png',
					} );
				}
			} else {
				await expect( warning ).toHaveCount( 0 );
			}
		} );
	}
}

test( 'AI Activity renders the rich visual diff viewer for pending governance rows without implying the site changed', async ( {
	page,
} ) => {
	const governanceEntries = [
		{
			id: 'activity-style-apply',
			type: 'apply_global_styles_suggestion',
			surface: 'global-styles',
			applyLane: 'server-executed',
			status: 'pending',
			target: {
				globalStylesId: '17',
			},
			suggestion: 'External: use the accent text preset',
			before: {
				userConfig: {
					styles: {
						color: {
							text: 'var:preset|color|contrast',
						},
					},
				},
			},
			after: {
				operations: [],
			},
			request: {
				prompt: 'Use the accent text preset for stronger emphasis.',
				reference: 'external-apply:activity-spec',
			},
			apply: {
				status: 'pending',
				requestedBy: 7,
				requestedAt: '2026-06-10T00:00:00Z',
				expiresAt: '2030-06-10T00:00:00Z',
				operations: [
					{
						type: 'set_styles',
						path: [ 'color', 'text' ],
						value: 'var:preset|color|accent',
						presetSlug: 'accent',
					},
				],
				signatures: {
					resolvedContextSignature: 'r'.repeat( 64 ),
					reviewContextSignature: 'v'.repeat( 64 ),
					baselineConfigHash: 'b'.repeat( 64 ),
				},
				requestReference: 'activity-spec-req-1',
			},
			document: {
				scopeKey: 'global_styles:17',
				postType: 'global_styles',
				entityId: '17',
			},
			timestamp: '2026-03-24T10:10:00Z',
		},
	];

	await page.route(
		'**/wp-json/flavor-agent/v1/activity**',
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(
					buildActivityResponse(
						route.request().url(),
						governanceEntries,
						{
							summary: {
								total: 1,
								applied: 0,
								pending: 1,
							},
						}
					)
				),
			} );
		}
	);

	await page.goto(
		'/wp-admin/options-general.php?page=flavor-agent-activity',
		{
			waitUntil: 'domcontentloaded',
		}
	);
	await waitForWordPressReady( page );

	const viewer = page.locator( '.flavor-agent-activity-log__visual-diff' );
	const diffRow = viewer.locator(
		'.flavor-agent-activity-log__visual-diff-row',
		{
			has: page.locator(
				'.flavor-agent-activity-log__visual-diff-row-title',
				{
					hasText: 'color.text',
				}
			),
		}
	);

	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'Governance evidence' );
	await expect( viewer ).toBeVisible( { timeout: 30_000 } );
	await expect( diffRow ).toContainText( 'Proposed only' );
	await expect( diffRow ).toContainText( 'Not applied' );
	await expect(
		diffRow
			.locator( '.flavor-agent-activity-log__visual-diff-chip' )
			.first()
	).toBeVisible();
	await expect(
		page.locator( '.flavor-agent-activity-log__detail-section' ).filter( {
			hasText: 'State snapshots',
		} )
	).toBeVisible();
} );

for ( const [ label, identity ] of [
	[ 'editor lane', { applyLane: 'editor-state' } ],
	[ 'missing lane', {} ],
	[
		'non-apply type',
		{ applyLane: 'server-executed', type: 'request_diagnostic' },
	],
] ) {
	test( `AI Activity rejects governance claims from a row with ${ label }`, async ( {
		page,
	} ) => {
		const entry = {
			...ACTIVITY_ENTRIES[ 0 ],
			type: 'apply_global_styles_suggestion',
			surface: 'global-styles',
			status: 'pending',
			apply: {
				status: 'pending',
				requestedBy: 7,
				decidedBy: 1,
				decidedByName: 'Site Admin',
				executedAt: '2026-06-10T00:00:00Z',
				attestationStatus: 'recorded',
			},
			undo: {
				status: 'undone',
				verification: 'client-reported',
				attestationStatus: 'recorded',
			},
			...identity,
		};
		await page.route(
			'**/wp-json/flavor-agent/v1/activity**',
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						buildActivityResponse( route.request().url(), [
							entry,
						] )
					),
				} );
			}
		);
		await page.goto(
			'/wp-admin/options-general.php?page=flavor-agent-activity'
		);
		await waitForWordPressReady( page );
		const sidebar = page.locator( '.flavor-agent-activity-log__sidebar' );
		await expect( sidebar ).toContainText( entry.suggestion );
		await expect( sidebar ).not.toContainText( 'Governance evidence' );
		await expect( sidebar ).not.toContainText( 'Site Admin' );
		await expect( sidebar ).not.toContainText(
			'Undo attestation recorded'
		);
		await expect(
			page.getByRole( 'button', { name: 'Approve', exact: true } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Reject', exact: true } )
		).toHaveCount( 0 );
	} );
}

test( 'AI Activity page renders an inline load error instead of the empty activity copy', async ( {
	page,
} ) => {
	await page.route(
		'**/wp-json/flavor-agent/v1/activity**',
		async ( route ) => {
			await route.fulfill( {
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'rest_internal_error',
					message: 'Activity endpoint exploded.',
				} ),
			} );
		}
	);

	await page.goto(
		'/wp-admin/options-general.php?page=flavor-agent-activity',
		{
			waitUntil: 'domcontentloaded',
		}
	);
	await waitForWordPressReady( page );

	await expect( page.getByText( 'Activity log unavailable' ) ).toBeVisible();
	await expect(
		page.getByText( 'Activity endpoint exploded.' )
	).toBeVisible();
	await expect(
		page.getByText( 'No AI activity has been recorded yet.' )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'button', { name: 'Retry loading activity' } )
	).toBeVisible();
} );

test( '@wp70-site-editor AI Activity page loads real repository data in wp-admin', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );

	runWpCli( wp70Harness, [
		'eval',
		`
\\FlavorAgent\\Activity\\Repository::install();
$table_name = $GLOBALS['wpdb']->prefix . 'flavor_agent_activity';
$GLOBALS['wpdb']->query( "TRUNCATE TABLE {$table_name}" );
$entries = array(
	array(
		'id' => 'activity-full-stack-block',
		'type' => 'apply_suggestion',
		'surface' => 'block',
		'target' => array(
			'clientId' => 'block-1',
			'blockName' => 'core/paragraph',
			'blockPath' => array( 0 ),
		),
		'suggestion' => 'Full-stack block rewrite',
		'before' => array(
			'attributes' => array(
				'content' => 'Before block copy',
			),
		),
		'after' => array(
			'attributes' => array(
				'content' => 'After block copy',
			),
		),
		'request' => array(
			'prompt' => 'Tighten the block copy.',
			'reference' => 'block:block-1:1',
			'ai' => array(
				'backendLabel' => 'WordPress AI Client',
				'model' => 'provider-managed',
				'pathLabel' => 'WordPress AI Client via Settings > Connectors',
				'ownerLabel' => 'Settings > Connectors',
				'credentialSourceLabel' => 'Provider-managed',
				'selectedProviderLabel' => 'Azure OpenAI',
				'ability' => 'flavor-agent/recommend-block',
				'route' => 'wp-abilities:flavor-agent/recommend-block',
			),
		),
		'document' => array(
			'scopeKey' => 'post:42',
			'postType' => 'post',
			'entityId' => '42',
		),
		'timestamp' => '2026-03-24T10:00:00Z',
	),
	array(
		'id' => 'activity-full-stack-template',
		'type' => 'apply_template_suggestion',
		'surface' => 'template',
		'target' => array(
			'templateRef' => 'theme//home',
		),
		'suggestion' => 'Full-stack template insert',
		'before' => array(
			'operations' => array(),
		),
		'after' => array(
			'operations' => array(
				array(
					'type' => 'insert_pattern',
					'patternName' => 'theme/hero',
				),
			),
		),
		'request' => array(
			'prompt' => 'Make the home template more editorial.',
			'reference' => 'template:theme//home:3',
			'ai' => array(
				'backendLabel' => 'WordPress AI Client',
				'model' => 'provider-managed',
				'pathLabel' => 'WordPress AI Client via Settings > Connectors',
				'ownerLabel' => 'Settings > Connectors',
				'credentialSourceLabel' => 'Provider-managed',
				'selectedProviderLabel' => 'Azure OpenAI',
				'ability' => 'flavor-agent/recommend-template',
				'route' => 'wp-abilities:flavor-agent/recommend-template',
			),
		),
		'document' => array(
			'scopeKey' => 'wp_template:theme//home',
			'postType' => 'wp_template',
			'entityId' => 'theme//home',
		),
		'timestamp' => '2026-03-24T10:05:00Z',
	),
	array(
		'id' => 'activity-full-stack-failed',
		'type' => 'request_diagnostic',
		'surface' => 'content',
		'target' => array(
			'requestRef' => 'content:post:42',
		),
		'suggestion' => 'Full-stack failed content request',
		'before' => array(),
		'after' => array(),
		'request' => array(
			'prompt' => 'Draft the launch note.',
			'reference' => 'content:post:42',
			'ai' => array(
				'backendLabel' => 'WordPress AI Client',
				'model' => 'provider-managed',
				'pathLabel' => 'WordPress AI Client via Settings > Connectors',
				'ownerLabel' => 'Settings > Connectors',
				'credentialSourceLabel' => 'Provider-managed',
				'selectedProviderLabel' => 'Azure OpenAI',
				'ability' => 'flavor-agent/recommend-content',
				'route' => 'wp-abilities:flavor-agent/recommend-content',
				'errorSummary' => array(
					'wrappedMessage' => 'Provider timeout.',
				),
			),
		),
		'document' => array(
			'scopeKey' => 'post:42',
			'postType' => 'post',
			'entityId' => '42',
		),
		'executionResult' => 'review',
		'undo' => array(
			'status' => 'failed',
			'error' => 'Provider timeout.',
		),
		'timestamp' => '2026-03-24T10:10:00Z',
	),
	array(
		'id' => 'activity-full-stack-undone',
		'type' => 'apply_suggestion',
		'surface' => 'block',
		'target' => array(
			'clientId' => 'block-2',
			'blockName' => 'core/paragraph',
			'blockPath' => array( 1 ),
		),
		'suggestion' => 'Full-stack undone block rewrite',
		'before' => array(
			'attributes' => array(
				'content' => 'Undo before copy',
			),
		),
		'after' => array(
			'attributes' => array(
				'content' => 'Undo after copy',
			),
		),
		'request' => array(
			'prompt' => 'Rewrite then undo.',
			'reference' => 'block:block-2:1',
		),
		'document' => array(
			'scopeKey' => 'post:42',
			'postType' => 'post',
			'entityId' => '42',
		),
		'timestamp' => '2026-03-24T10:15:00Z',
	),
);
foreach ( $entries as $entry ) {
	\\FlavorAgent\\Activity\\Repository::create( $entry );
}
\\FlavorAgent\\Activity\\Repository::update_undo_status( 'activity-full-stack-undone', 'undone' );
`,
	] );

	await page.goto(
		'/wp-admin/options-general.php?page=flavor-agent-activity',
		{
			waitUntil: 'domcontentloaded',
		}
	);
	await waitForWordPressReady( page );

	await expect(
		page.locator( '#flavor-agent-activity-log-root' )
	).toBeVisible( {
		timeout: 30_000,
	} );
	await expect(
		page.locator( '.flavor-agent-activity-log__summary-item' )
	).toHaveCount( 9, { timeout: 30_000 } );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Full-stack template insert' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Full-stack block rewrite' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Full-stack failed content request' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Full-stack undone block rewrite' );

	await page
		.locator( '.flavor-agent-activity-log__feed' )
		.getByText( 'Full-stack template insert', { exact: true } )
		.click();
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'WordPress AI Client via Settings > Connectors' );
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'wp_template' );
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'theme//home' );
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'Insert pattern' );

	await page.getByLabel( 'Search AI activity' ).fill( 'failed content' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).toContainText( 'Full-stack failed content request' );
	await expect(
		page.locator( '.flavor-agent-activity-log__feed' )
	).not.toContainText( 'Full-stack template insert' );
	await page
		.locator( '.flavor-agent-activity-log__feed' )
		.getByText( 'Full-stack failed content request', { exact: true } )
		.click();
	await expect(
		page.locator( '.flavor-agent-activity-log__sidebar' )
	).toContainText( 'Provider timeout.' );
} );
