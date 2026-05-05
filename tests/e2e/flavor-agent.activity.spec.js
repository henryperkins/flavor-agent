const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const wp70Harness = getWp70HarnessConfig();

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

function buildActivityResponse( requestUrl ) {
	const url = new URL( requestUrl );
	const search = ( url.searchParams.get( 'search' ) || '' )
		.trim()
		.toLowerCase();
	const filteredEntries = search
		? ACTIVITY_ENTRIES.filter( ( entry ) =>
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
		: ACTIVITY_ENTRIES;

	return {
		entries: filteredEntries,
		filterOptions: {
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
	).toBeVisible( { timeout: 30_000 } );
	await expect(
		page.getByRole( 'heading', { name: 'AI Activity Log' } )
	).toBeVisible( { timeout: 30_000 } );
	await expect(
		page.locator( '.flavor-agent-activity-log__summary-item' )
	).toHaveCount( 6, { timeout: 30_000 } );
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
	).toBeVisible( { timeout: 30_000 } );
	await expect(
		page.locator( '.flavor-agent-activity-log__summary-item' )
	).toHaveCount( 6, { timeout: 30_000 } );
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
