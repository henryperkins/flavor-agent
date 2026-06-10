const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const wp70Harness = getWp70HarnessConfig();

const ACTIVITY_PAGE =
	'/wp-admin/options-general.php?page=flavor-agent-activity';

// End-to-end coverage of the human approval gate. Unlike the route-mocked
// Playground activity specs, this one runs against the real repository and
// decision route on the Docker-backed WP 7.0 (MySQL) harness: the governance
// property under test (approval re-checks freshness and fails closed against
// live server state) only exists when the real decision route and
// StyleApplyExecutor run. Playground's SQLite + load-without-activation harness
// cannot serve the admin activity query, which is why the other activity specs
// mock it — so these tests are tagged @wp70-site-editor and excluded there.
function seedPendingExternalApply( id ) {
	runWpCli( wp70Harness, [
		'eval',
		`
\\FlavorAgent\\Activity\\Repository::install();
$table_name = $GLOBALS['wpdb']->prefix . 'flavor_agent_activity';
$GLOBALS['wpdb']->query( "TRUNCATE TABLE {$table_name}" );
\\FlavorAgent\\Activity\\Repository::create( array(
	'id' => '${ id }',
	'type' => 'apply_global_styles_suggestion',
	'surface' => 'global-styles',
	'target' => array( 'globalStylesId' => '999999' ),
	'suggestion' => 'External: use the accent text preset',
	'before' => array(),
	'after' => array(),
	'executionResult' => 'pending',
	'undo' => array( 'status' => 'not_applicable' ),
	'request' => array(
		'prompt' => 'darker',
		'reference' => 'external-apply:e2e',
		'apply' => array(
			'status' => 'pending',
			'requestedBy' => 1,
			'requestedAt' => '2026-06-10T00:00:00Z',
			'expiresAt' => '2030-06-10T00:00:00Z',
			'operations' => array(
				array(
					'type' => 'set_styles',
					'path' => array( 'color', 'text' ),
					'value' => 'var:preset|color|accent',
					'valueType' => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'accent',
				),
			),
			'signatures' => array(
				'resolvedContextSignature' => str_repeat( 'e', 64 ),
				'reviewContextSignature' => str_repeat( 'e', 64 ),
				'baselineConfigHash' => str_repeat( 'e', 64 ),
			),
			'requestReference' => 'e2e-req-1',
		),
	),
	'document' => array(
		'scopeKey' => 'global_styles:999999',
		'postType' => 'global_styles',
		'entityId' => '999999',
	),
	'timestamp' => '2026-06-10T00:00:00Z',
) );
`,
	] );
}

async function openSeededExternalApply( page ) {
	await page
		.locator( '.flavor-agent-activity-log__feed' )
		.getByText( 'External: use the accent text preset' )
		.first()
		.click();

	return page.locator( '.flavor-agent-activity-log__sidebar' );
}

test.describe( 'external apply approvals', () => {
	test( '@wp70-site-editor pending external applies appear and can be rejected with a note', async ( {
		page,
	} ) => {
		test.setTimeout( 120_000 );
		seedPendingExternalApply( 'activity-ext-apply-reject' );

		await page.goto( ACTIVITY_PAGE, { waitUntil: 'domcontentloaded' } );
		await waitForWordPressReady( page );

		await expect(
			page.locator( '#flavor-agent-activity-log-root' )
		).toBeVisible( { timeout: 30_000 } );

		let sidebar = await openSeededExternalApply( page );

		await expect( sidebar.getByText( 'Governance evidence' ) ).toBeVisible();
		await expect( sidebar.getByText( 'Approval required' ) ).toBeVisible();
		await expect( sidebar.getByText( 'Requested operations' ) ).toBeVisible();
		await expect(
			sidebar.getByRole( 'cell', { name: 'color.text' } )
		).toBeVisible();
		await expect(
			sidebar.getByRole( 'cell', { name: 'Baseline unavailable' } )
		).toBeVisible();
		await expect(
			sidebar.getByRole( 'cell', { name: 'Not applied' } )
		).toBeVisible();
		await expect( sidebar.getByText( 'Target and provenance' ) ).toBeVisible();
		await expect( sidebar.getByText( 'User #1' ) ).toBeVisible();
		await expect(
			sidebar.getByText( 'e2e-req-1', { exact: true } ).first()
		).toBeVisible();
		await page
			.getByLabel( 'Decision note (optional)' )
			.fill( 'Rejected from the browser spec' );
		await page.getByRole( 'button', { name: 'Reject' } ).click();

		await expect(
			page
				.locator( '.flavor-agent-activity-log__sidebar' )
				.getByText( 'Rejected' )
				.first()
		).toBeVisible( { timeout: 30_000 } );
		await expect(
			sidebar.getByText( 'Rejected from the browser spec' )
		).toBeVisible();

		await page.reload( { waitUntil: 'domcontentloaded' } );
		await waitForWordPressReady( page );
		sidebar = await openSeededExternalApply( page );

		await expect( sidebar.getByText( 'Rejected' ).first() ).toBeVisible();
		await expect(
			sidebar.getByText( 'Rejected from the browser spec' )
		).toBeVisible();
	} );

	test( '@wp70-site-editor approving a drifted request fails closed instead of mutating', async ( {
		page,
	} ) => {
		test.setTimeout( 120_000 );
		// globalStylesId 999999 does not exist, so the approval-time freshness
		// check must fail closed and transition the row to failed rather than
		// mutating any live entity.
		seedPendingExternalApply( 'activity-ext-apply-approve' );

		await page.goto( ACTIVITY_PAGE, { waitUntil: 'domcontentloaded' } );
		await waitForWordPressReady( page );

		await expect(
			page.locator( '#flavor-agent-activity-log-root' )
		).toBeVisible( { timeout: 30_000 } );

		const sidebar = await openSeededExternalApply( page );
		await page.getByRole( 'button', { name: 'Approve and apply' } ).click();

		// The summary card label "Failed or unavailable" is always present, so
		// this asserts the row/sidebar failed external-apply status specifically.
		await expect(
			page
				.locator( '.flavor-agent-activity-log__sidebar' )
				.getByText( 'Apply failed' )
				.first()
		).toBeVisible( { timeout: 30_000 } );
		await expect(
			sidebar.getByText( 'flavor_agent_apply_resolve_failed' )
		).toBeVisible();
		await expect(
			sidebar
				.getByText(
					'The requested Global Styles entity is not available on this site.'
				)
				.first()
		).toBeVisible();
	} );
} );
