const { test, expect } = require( '@playwright/test' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const wp70Harness = getWp70HarnessConfig();

const ACTIVITY_PAGE =
	'/wp-admin/options-general.php?page=flavor-agent-activity';
const TEMPLATE_APPLY_TITLE = 'External: append the query pattern to Home';
const TEMPLATE_PATTERN_NAME = 'core/query-standard-posts';
let originalAttestationKey = '';
let hadOriginalAttestationKey = false;
let attestationKeyConfigured = false;

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

function configureAttestationKey() {
	if ( ! attestationKeyConfigured ) {
		const current = runWpCli(
			wp70Harness,
			[
				'config',
				'get',
				'FLAVOR_AGENT_ATTEST_PRIVATE_KEY',
				'--type=constant',
			],
			{ allowFailure: true }
		);

		hadOriginalAttestationKey = current.status === 0;
		originalAttestationKey = hadOriginalAttestationKey
			? current.stdout.trim()
			: '';
	}

	const generated = runWpCli( wp70Harness, [
		'eval',
		"echo base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( 'a', SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) ) );",
	] ).stdout.trim();

	if ( ! generated ) {
		throw new Error(
			'Could not generate the WP70 attestation signing key.'
		);
	}

	runWpCli( wp70Harness, [
		'config',
		'set',
		'FLAVOR_AGENT_ATTEST_PRIVATE_KEY',
		generated,
		'--type=constant',
	] );
	attestationKeyConfigured = true;
}

function restoreAttestationKey() {
	if ( ! attestationKeyConfigured ) {
		return;
	}

	if ( hadOriginalAttestationKey ) {
		runWpCli( wp70Harness, [
			'config',
			'set',
			'FLAVOR_AGENT_ATTEST_PRIVATE_KEY',
			originalAttestationKey,
			'--type=constant',
		] );
	} else {
		runWpCli(
			wp70Harness,
			[
				'config',
				'delete',
				'FLAVOR_AGENT_ATTEST_PRIVATE_KEY',
				'--type=constant',
			],
			{ allowFailure: true }
		);
	}

	attestationKeyConfigured = false;
}

function seedPendingTemplateApply( id ) {
	configureAttestationKey();
	runWpCli( wp70Harness, [
		'eval',
		`
\\FlavorAgent\\Activity\\Repository::install();
\\FlavorAgent\\Attestation\\Repository::install();
$activity_table = $GLOBALS['wpdb']->prefix . 'flavor_agent_activity';
$attestation_table = \\FlavorAgent\\Attestation\\Repository::table_name();
$GLOBALS['wpdb']->query( "TRUNCATE TABLE {$activity_table}" );
$GLOBALS['wpdb']->query( "TRUNCATE TABLE {$attestation_table}" );
$template_posts = get_posts( array(
	'post_type' => 'wp_template',
	'post_status' => 'any',
	'numberposts' => -1,
	'fields' => 'ids',
) );
foreach ( $template_posts as $template_post_id ) {
	wp_delete_post( $template_post_id, true );
}
$template_ref = '${ wp70Harness.themeSlug }//home';
$template = \\FlavorAgent\\Context\\ServerCollector::resolve_template_for_apply( $template_ref );
if ( ! is_object( $template ) ) {
	throw new \\RuntimeException( 'Could not resolve the Home template fixture.' );
}
$pattern = \\WP_Block_Patterns_Registry::get_instance()->get_registered( '${ TEMPLATE_PATTERN_NAME }' );
if ( ! is_array( $pattern ) ) {
	throw new \\RuntimeException( 'Could not resolve the template pattern fixture.' );
}
$baseline = \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest( (string) $template->content );
\\FlavorAgent\\Activity\\Repository::create( array(
	'id' => '${ id }',
	'type' => 'apply_template_suggestion',
	'surface' => 'template',
	'target' => array(
		'templateRef' => $template_ref,
		'templateType' => 'home',
		'slug' => 'home',
		'title' => 'Home',
	),
	'suggestion' => '${ TEMPLATE_APPLY_TITLE }',
	'before' => array(),
	'after' => array(),
	'executionResult' => 'pending',
	'undo' => array( 'status' => 'not_applicable' ),
	'request' => array(
		'prompt' => 'Add the standard query pattern.',
		'reference' => 'external-template-apply:e2e',
		'apply' => array(
			'status' => 'pending',
			'requestedBy' => 1,
			'requestedAt' => '2026-07-17T00:00:00Z',
			'expiresAt' => '2030-07-17T00:00:00Z',
			'operations' => array(
				array(
					'type' => 'insert_pattern',
					'patternName' => '${ TEMPLATE_PATTERN_NAME }',
					'placement' => 'end',
				),
			),
			'signatures' => array(
				'resolvedContextSignature' => str_repeat( 't', 64 ),
				'reviewContextSignature' => str_repeat( 't', 64 ),
				'baselineContentHash' => $baseline,
			),
			'requestReference' => 'e2e-template-req-1',
		),
	),
	'document' => array(
		'scopeKey' => 'wp_template:' . $template_ref,
		'postType' => 'wp_template',
		'entityId' => $template_ref,
		'entityKind' => 'template',
		'entityName' => 'template',
	),
	'timestamp' => '2026-07-17T00:00:00Z',
) );
`,
	] );
}

async function openSeededExternalApply(
	page,
	title = 'External: use the accent text preset'
) {
	await page
		.locator( '.flavor-agent-activity-log__feed' )
		.getByText( title )
		.first()
		.click();

	return page.locator( '.flavor-agent-activity-log__sidebar' );
}

test.describe( 'external apply approvals', () => {
	test.afterAll( () => {
		restoreAttestationKey();
	} );

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
		const diffRow = sidebar
			.locator( '.flavor-agent-activity-log__visual-diff-row' )
			.filter( {
				hasText: 'color.text',
			} );

		await expect(
			sidebar.getByText( 'Governance evidence' )
		).toBeVisible();
		await expect( sidebar.getByText( 'Approval required' ) ).toBeVisible();
		await expect(
			sidebar.locator( '.flavor-agent-activity-log__visual-diff' )
		).toBeVisible();
		await expect(
			sidebar.getByText( 'Requested operations' )
		).toBeVisible();
		await expect( diffRow ).toBeVisible();
		await expect( diffRow ).toContainText( 'Proposed only' );
		await expect( diffRow ).toContainText( 'Baseline unavailable' );
		await expect( diffRow ).toContainText( 'Not applied' );
		await expect(
			diffRow
				.locator( '.flavor-agent-activity-log__visual-diff-chip' )
				.first()
		).toBeVisible();
		await expect( sidebar.getByText( 'Full provenance' ) ).toBeVisible();
		await expect(
			sidebar.getByText( 'User #1', { exact: true } ).first()
		).toBeVisible();
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

	test( '@wp70-site-editor approving a template apply exposes its attestation badge', async ( {
		page,
	} ) => {
		test.setTimeout( 120_000 );
		seedPendingTemplateApply( 'activity-template-attestation' );

		await page.goto( ACTIVITY_PAGE, { waitUntil: 'domcontentloaded' } );
		await waitForWordPressReady( page );

		await expect(
			page.locator( '#flavor-agent-activity-log-root' )
		).toBeVisible( { timeout: 30_000 } );

		const sidebar = await openSeededExternalApply(
			page,
			TEMPLATE_APPLY_TITLE
		);
		await page.getByRole( 'button', { name: 'Approve and apply' } ).click();

		await expect( sidebar.getByText( 'Applied' ).first() ).toBeVisible( {
			timeout: 30_000,
		} );
		await expect(
			page.locator( '.flavor-agent-activity-log__entry-badge', {
				hasText: 'Attestation',
			} )
		).toBeVisible();
		await expect( sidebar ).toContainText(
			'External template apply (external-template-apply-v1)'
		);
		await expect( sidebar ).toContainText(
			`wp_template:${ wp70Harness.themeSlug }//home`
		);
	} );
} );
