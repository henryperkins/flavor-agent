const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( './test-fixtures' );
const { waitForWordPressReady } = require( './wait-for-wordpress-ready' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const harness = getWp70HarnessConfig();
const settingsURL = '/wp-admin/options-general.php?page=flavor-agent';
const syncURL = '**/wp-json/flavor-agent/v1/sync-patterns';
const artifacts = path.resolve( __dirname, '../../output/qdrant-removal/screenshots', harness.gutenbergVersion ? 'gutenberg' : 'bundled' );
let mockedCatalogState = {};

function evalFixture( php ) {
	return runWpCli( harness, [ 'eval', php ] ).stdout.trim();
}

async function openSettings( page, hash = '' ) {
	await page.goto( settingsURL + hash, { waitUntil: 'domcontentloaded' } );
	await waitForWordPressReady( page );
}

async function openSection( page, key ) {
	const section = page.locator( `[data-flavor-agent-section="${ key }"]` );
	if ( ! ( await section.evaluate( ( element ) => element.open ) ) ) {
		await section.locator( ':scope > summary' ).click();
	}
	return section;
}

async function openSyncPanel( page ) {
	await openSection( page, 'patterns' );
	const panel = page.locator( '[data-flavor-agent-sync-panel]' );
	if ( ! ( await panel.evaluate( ( element ) => element.open ) ) ) {
		await panel.locator( ':scope > summary' ).click();
	}
	return panel;
}

async function assertNoOverflow( page ) {
	expect( await page.evaluate( () =>
		document.documentElement.scrollWidth - document.documentElement.clientWidth
	) ).toBeLessThanOrEqual( 1 );
}

test.beforeEach( async ( { page } ) => {
	fs.mkdirSync( artifacts, { recursive: true } );
	// Disposable local options only. Every sync request is intercepted below,
	// including GET, which can run a due WP-Cron sync on the server.
	mockedCatalogState = JSON.parse( evalFixture( `
update_option( 'gutenberg-experiments', [] );
delete_option( 'flavor_agent_guideline_site' );
delete_option( 'flavor_agent_guideline_copy' );
delete_option( 'flavor_agent_guideline_images' );
delete_option( 'flavor_agent_guideline_additional' );
delete_option( 'flavor_agent_guideline_blocks' );
update_option( 'flavor_agent_cloudflare_workers_ai_account_id', 'playground-account' );
update_option( 'flavor_agent_cloudflare_workers_ai_api_token', 'playground-workers-token' );
update_option( 'flavor_agent_cloudflare_workers_ai_embedding_model', '@cf/qwen/qwen3-embedding-0.6b' );
update_option( 'flavor_agent_pattern_retrieval_backend', 'cloudflare_ai_search' );
$instance = FlavorAgent\\Cloudflare\\PatternSearchInstanceManager::managed_instance_id();
$signature = FlavorAgent\\Cloudflare\\PatternSearchInstanceManager::credential_signature( 'playground-account', 'playground-workers-token', '@cf/qwen/qwen3-embedding-0.6b' );
update_option( 'flavor_agent_cloudflare_pattern_ai_search_instance_id', $instance );
update_option( 'flavor_agent_cloudflare_pattern_ai_search_validated_signature', $signature );
delete_option( 'flavor_agent_cloudflare_pattern_ai_search_provisioning_state' );
// Mocked ready catalog metadata; no provider connection or upload is made.
update_option( FlavorAgent\\Patterns\\PatternIndex::STATE_OPTION, [
 'status' => 'ready', 'pattern_backend' => 'cloudflare_ai_search',
 'cloudflare_ai_search_namespace' => 'default',
 'cloudflare_ai_search_instance' => $instance,
 'cloudflare_ai_search_signature' => $signature,
 'last_synced_at' => gmdate( 'c' ), 'last_attempt_at' => gmdate( 'c' ),
 'indexed_count' => 12,
] );
wp_clear_scheduled_hook( FlavorAgent\\Patterns\\PatternIndex::CRON_HOOK );
delete_transient( 'flavor_agent_sync_lock' );
echo wp_json_encode( FlavorAgent\\Patterns\\PatternIndex::get_state() );
` ) );
	await page.route( syncURL, ( route ) => route.fulfill( {
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify( { runtimeState: { status: 'uninitialized', indexed_count: 0 } } ),
	} ) );
} );

test( '@wp70-site-editor dashboard distinguishes configuration from health and explains its editor handoff', async ( { page } ) => {
	await page.setViewportSize( { width: 1440, height: 1000 } );
	await openSettings( page );
	await expect( page.locator( '[data-flavor-agent-status-badge="embeddings"]' ) ).toHaveText( 'Configured' );
	const embeddings = await openSection( page, 'embeddings' );
	await expect( embeddings ).toContainText( 'Configured does not confirm current connectivity' );
	const patterns = await openSection( page, 'patterns' );
	await expect( patterns ).toContainText( 'Managed pattern index configured. Check Pattern Sync for the latest catalog result.' );
	await expect( page.locator( '.flavor-agent-settings' ) ).not.toContainText( /Qdrant/i );
	await expect( page.locator( '[name="flavor_agent_pattern_retrieval_backend"], [name="flavor_agent_qdrant_url"], [name="flavor_agent_qdrant_key"]' ) ).toHaveCount( 0 );
	const editorLink = page.locator( '.flavor-agent-admin-hero__actions' ).getByRole( 'link', { name: 'Open Post Editor' } );
	await expect( editorLink ).toHaveAttribute( 'href', /\/wp-admin\/post-new\.php$/ );
	await expect( editorLink ).toHaveAttribute( 'aria-describedby', 'flavor-agent-editor-handoff' );
	await expect( page.locator( '#flavor-agent-editor-handoff' ) ).toContainText( 'requires a configured provider in Connectors' );
	await expect( page.locator( '#flavor-agent-editor-handoff' ) ).toContainText( 'does not request recommendations, apply changes, or publish content' );
	await page.screenshot( { path: path.join( artifacts, 'desktop-configured.png' ), fullPage: true } );
	await openSyncPanel( page );
	for ( const width of [ 390, 320 ] ) {
		await page.setViewportSize( { width, height: 1000 } );
		await assertNoOverflow( page );
		await expect( page.locator( '#flavor-agent-sync-button' ) ).toBeVisible();
		await page.screenshot( { path: path.join( artifacts, `patterns-${ width }.png` ), fullPage: true } );
	}
	await page.setViewportSize( { width: 1440, height: 1000 } );
	let recommendationRequests = 0;
	await page.route( '**/wp-json/flavor-agent/v1/recommend**', async ( route ) => {
		recommendationRequests += 1;
		await route.abort();
	} );
	await editorLink.click();
	await expect( page ).toHaveURL( /\/wp-admin\/post-new\.php$/ );
	await expect( page.locator( '.edit-post-layout' ) ).toBeVisible();
	expect( recommendationRequests ).toBe( 0 );
} );

test( '@wp70-site-editor dashboard status navigation and guideline editing retain keyboard context at desktop and narrow sizes', async ( { page } ) => {
	await openSettings( page );
	const card = page.locator( 'a[href="#flavor-agent-section-guidelines"]' );
	const guidelines = page.locator( '[data-flavor-agent-section="guidelines"]' );
	const summary = guidelines.locator( ':scope > summary' );
	await card.focus();
	await page.keyboard.press( 'Enter' );
	await expect( guidelines ).toHaveJSProperty( 'open', true );
	await expect( summary ).toBeFocused();
	expect( await summary.evaluate( ( element ) => {
		const style = getComputedStyle( element );
		return element.matches( ':focus-visible' ) && style.outlineStyle !== 'none' && parseFloat( style.outlineWidth ) > 0;
	} ) ).toBe( true );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await waitForWordPressReady( page );
	await expect( guidelines ).toHaveJSProperty( 'open', true );
	await expect( summary ).toBeFocused();
	const blocks = page.locator( '[data-flavor-agent-nested-panel="block-guidelines"]' );
	await blocks.locator( ':scope > summary' ).click();
	const select = page.getByLabel( 'Block', { exact: true } );
	const text = page.getByLabel( 'Guideline text', { exact: true } );
	const add = page.getByRole( 'button', { name: 'Add Block Guideline', exact: true } );
	const cancel = page.locator( '[data-guidelines-block-cancel]' );
	await expect( cancel ).toBeHidden();
	await text.focus();
	await page.keyboard.press( 'Tab' );
	await expect( add ).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await expect( select ).toBeFocused();
	await expect( select ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( select ).toHaveAttribute( 'aria-describedby', /flavor-agent-block-select-error/ );
	await expect( page.locator( '#flavor-agent-block-select-error' ) ).toBeInViewport();
	await select.selectOption( 'core/paragraph' );
	await add.focus();
	await page.keyboard.press( 'Enter' );
	await expect( text ).toBeFocused();
	await expect( text ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( text ).toHaveAttribute( 'aria-describedby', /flavor-agent-block-text-error/ );
	await expect( page.locator( '#flavor-agent-block-text-error' ) ).toBeInViewport();
	await text.fill( 'Use concise paragraphs.' );
	await add.click();
	await expect( text ).not.toHaveAttribute( 'aria-invalid', 'true' );
	const edit = page.getByRole( 'button', { name: /Edit .*Paragraph/ } );
	await edit.click();
	await expect( text ).toBeFocused();
	await expect( cancel ).toBeVisible();
	await cancel.focus();
	await page.keyboard.press( 'Enter' );
	await expect( cancel ).toBeHidden();
	await expect( select ).toBeFocused();
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: /Remove .*Paragraph/ } ).click();
	await expect( select ).toBeFocused();
	await expect( edit ).toHaveCount( 0 );
	for ( const width of [ 1440, 390, 320 ] ) {
		await page.setViewportSize( { width, height: 1000 } );
		await assertNoOverflow( page );
		await expect( cancel ).toBeHidden();
		await text.focus();
		await page.keyboard.press( 'Tab' );
		await expect( add ).toBeFocused();
		await page.screenshot( { path: path.join( artifacts, `guidelines-${ width }.png` ), fullPage: true } );
	}
	await page.setViewportSize( { width: 1440, height: 1000 } );
	await page.locator( '#collapse-button' ).click();
	await expect( page.locator( 'body' ) ).toHaveClass( /folded/ );
	await assertNoOverflow( page );
	await page.screenshot( { path: path.join( artifacts, 'desktop-collapsed-navigation.png' ), fullPage: true } );
} );

test( '@wp70-site-editor dashboard reconciles unavailable sync monitoring before allowing another job', async ( { page } ) => {
	let posts = 0;
	let gets = 0;
	await page.route( syncURL, async ( route ) => {
		if ( route.request().method() === 'POST' ) {
			posts += 1;
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
				queued: true, runtimeState: { ...mockedCatalogState, status: 'indexing', indexed_count: 6, last_error: '' },
			} ) } );
			return;
		}
		gets += 1;
		await route.fulfill( { status: gets === 1 ? 503 : 200, contentType: 'application/json', body: JSON.stringify(
			gets === 1 ? { message: 'Mocked status connection failure.' } : { runtimeState: { ...mockedCatalogState, status: 'ready', indexed_count: 12, last_error: '' } }
		) } );
	} );
	await openSettings( page, '#flavor-agent-section-patterns' );
	await openSyncPanel( page );
	const start = page.locator( '#flavor-agent-sync-button' );
	await start.click();
	await expect( page.locator( '.flavor-agent-sync-panel' ) ).toHaveAttribute( 'data-pattern-sync-status', 'unavailable' );
	for ( const selector of [ '[data-pattern-overview-status] .flavor-agent-settings__glance-value', '[data-flavor-agent-status-badge="patterns"]', '[data-pattern-status-badge="panel"]', '[data-pattern-metric-value="status"]' ] ) {
		await expect( page.locator( selector ) ).toHaveText( 'Status unavailable' );
	}
	await expect( page.locator( '#flavor-agent-sync-summary' ) ).toContainText( 'Last confirmed status: Syncing' );
	await expect( page.locator( '#flavor-agent-sync-notice' ) ).toContainText( 'server may still be working' );
	await expect( page.locator( '#flavor-agent-sync-live-region' ) ).toContainText( 'Check sync status' );
	await expect( start ).toBeDisabled();
	await expect( page.locator( '[data-pattern-metric-value="indexed_count"]' ) ).toHaveText( '6' );
	await page.screenshot( { path: path.join( artifacts, 'sync-status-unavailable-mocked.png' ), fullPage: true } );
	await page.getByRole( 'button', { name: 'Check sync status', exact: true } ).click();
	await expect( page.locator( '.flavor-agent-sync-panel' ) ).toHaveAttribute( 'data-pattern-sync-status', 'ready' );
	for ( const selector of [ '[data-pattern-overview-status] .flavor-agent-settings__glance-value', '[data-flavor-agent-status-badge="patterns"]', '[data-pattern-status-badge="panel"]', '[data-pattern-metric-value="status"]' ] ) {
		await expect( page.locator( selector ) ).toHaveText( 'Ready' );
	}
	await expect( start ).toBeEnabled();
	expect( posts ).toBe( 1 );
	expect( gets ).toBe( 2 );
	await openSection( page, 'embeddings' );
	const endpoint = page.locator( '#flavor_agent_cloudflare_workers_ai_account_id' );
	const saved = await endpoint.inputValue();
	await endpoint.fill( 'changed-playground-account' );
	await openSyncPanel( page );
	await expect( start ).toBeDisabled();
	await expect( page.locator( '[data-pattern-sync-config-hint]' ) ).toBeVisible();
	await expect( start ).toHaveAttribute( 'aria-describedby', /flavor-agent-sync-config-hint/ );
	await openSection( page, 'embeddings' );
	await endpoint.fill( saved );
	await openSyncPanel( page );
	await expect( start ).toBeEnabled();
} );

test( '@wp70-site-editor dashboard requires an explicit Cloudflare rebuild for a legacy Qdrant catalog', async ( { page } ) => {
	evalFixture( `
update_option( 'flavor_agent_pattern_retrieval_backend', 'qdrant' );
update_option( 'flavor_agent_qdrant_url', 'https://example.test/qdrant' );
update_option( 'flavor_agent_qdrant_key', 'retired-test-key' );
update_option( FlavorAgent\\Patterns\\PatternIndex::STATE_OPTION, [
 'status' => 'ready', 'pattern_backend' => 'qdrant',
 'qdrant_collection' => 'retired-collection', 'indexed_count' => 999,
 'last_synced_at' => gmdate( 'c' ),
] );
` );
	await openSettings( page, '#flavor-agent-section-patterns' );
	await expect( page.locator( '.flavor-agent-settings' ) ).not.toContainText( /Qdrant/i );
	await expect( page.locator( '[data-flavor-agent-status-badge="patterns"]' ) ).toHaveText( 'Needs sync' );
	await expect( page.locator( '#flavor-agent-sync-summary' ) ).toContainText( 'unavailable until the catalog is rebuilt in Cloudflare AI Search' );
	await expect( page.locator( '[data-pattern-metric-value="indexed_count"]' ) ).toHaveText( '0' );
	await expect( page.locator( '#flavor-agent-sync-button' ) ).toBeEnabled();
	await expect( page.locator( '#flavor-agent-sync-button' ) ).toHaveAccessibleName( 'Sync Pattern Catalog' );
	await page.screenshot( { path: path.join( artifacts, 'legacy-store-needs-sync.png' ), fullPage: true } );
	await page.route( syncURL, ( route ) => route.fulfill( {
		status: 503, contentType: 'application/json',
		body: JSON.stringify( { message: 'Mocked legacy catalog status connection failure.' } ),
	} ) );
	await page.getByRole( 'button', { name: 'Check sync status', exact: true } ).click();
	await expect( page.locator( '[data-flavor-agent-status-badge="patterns"]' ) ).toHaveText( 'Status unavailable' );
	await expect( page.locator( '#flavor-agent-sync-summary' ) ).toContainText( 'Last confirmed status: Needs sync' );
	await expect( page.locator( '#flavor-agent-sync-button' ) ).toBeDisabled();
} );

for ( const legacyState of [ 'absent', 'empty' ] ) {
	test( `@wp70-site-editor saving Cloudflare setup preserves an explicit-sync requirement for ${ legacyState } legacy index state`, async ( { page } ) => {
		evalFixture( `
update_option( 'flavor_agent_pattern_retrieval_backend', 'qdrant' );
${ legacyState === 'absent'
		? "delete_option( FlavorAgent\\Patterns\\PatternIndex::STATE_OPTION );"
		: "update_option( FlavorAgent\\Patterns\\PatternIndex::STATE_OPTION, [] );" }
wp_clear_scheduled_hook( FlavorAgent\\Patterns\\PatternIndex::CRON_HOOK );
delete_transient( 'flavor_agent_sync_lock' );
` );
		await openSettings( page, '#flavor-agent-section-patterns' );
		await expect( page.locator( '[data-flavor-agent-status-badge="patterns"]' ) ).toHaveText( 'Needs sync' );
		await page.getByRole( 'button', { name: 'Save Changes', exact: true } ).click();
		await page.waitForLoadState( 'domcontentloaded' );
		await waitForWordPressReady( page );
		await openSection( page, 'patterns' );
		await expect( page.locator( '[data-flavor-agent-status-badge="patterns"]' ) ).toHaveText( 'Needs sync' );
		const saved = JSON.parse( evalFixture( `
echo wp_json_encode( [
 'backend' => get_option( 'flavor_agent_pattern_retrieval_backend' ),
 'state' => FlavorAgent\\Patterns\\PatternIndex::get_state(),
 'sync_scheduled' => (bool) wp_next_scheduled( FlavorAgent\\Patterns\\PatternIndex::CRON_HOOK ),
] );
` ) );
		expect( saved.backend ).toBe( 'cloudflare_ai_search' );
		expect( saved.state.stale_reasons ).toContain( 'pattern_store_migration_required' );
		expect( saved.state.indexed_count ).toBe( 0 );
		expect( saved.sync_scheduled ).toBe( false );
		await expect( page.locator( '#flavor-agent-sync-button' ) ).toHaveAccessibleName( 'Sync Pattern Catalog' );
		await expect( page.locator( '#flavor-agent-sync-button' ) ).toBeEnabled();
		await page.screenshot( { path: path.join( artifacts, `legacy-${ legacyState }-saved-needs-explicit-sync.png` ), fullPage: true } );
	} );
}

test( '@wp70-site-editor dashboard reports definite sync rejection without claiming an existing job failed', async ( { page } ) => {
	await page.route( syncURL, ( route ) => route.fulfill( {
		status: 403, contentType: 'application/json',
		body: JSON.stringify( { code: 'rest_forbidden', message: 'Mocked permission rejection: sign in with an administrator account.' } ),
	} ) );
	await openSettings( page, '#flavor-agent-section-patterns' );
	await openSyncPanel( page );
	const start = page.locator( '#flavor-agent-sync-button' );
	await start.click();
	await expect( page.locator( '[data-pattern-metric-value="status"]' ) ).toHaveText( 'Sync not started' );
	await expect( page.locator( '#flavor-agent-sync-notice' ) ).toContainText( 'sign in with an administrator account' );
	await expect( start ).toBeEnabled();
} );

test( '@wp70-site-editor dashboard keeps active shared Guidelines separate from saved and exported fallback edits', async ( { page } ) => {
	test.skip( ! harness.gutenbergVersion, 'The registered shared Guidelines management fixture requires Gutenberg 23.9.' );
	evalFixture( `update_option( 'gutenberg-experiments', [ 'gutenberg-guidelines' => true ] );` );
	const ids = JSON.parse( evalFixture( `
$ids = [];
foreach ( [ 'guideline-copy' => 'Active shared copy.', 'guideline-block-core_paragraph' => 'Active shared paragraph.' ] as $slug => $text ) {
	$id = wp_insert_post( [ 'post_type' => 'wp_knowledge', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $slug, 'post_content' => $text ], true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	wp_set_object_terms( $id, 'guideline', 'wp_knowledge_type' );
	$ids[] = $id;
}
update_option( 'flavor_agent_guideline_copy', 'Stored fallback copy.' );
update_option( 'flavor_agent_guideline_blocks', [ 'core/image' => 'Stored fallback image.' ] );
echo wp_json_encode( $ids );
` ) );
	try {
		await openSettings( page, '#flavor-agent-section-guidelines' );
		await expect( page.locator( '[data-guidelines-ownership]' ) ).toContainText( 'Shared Guidelines are active' );
		await expect( page.locator( '[data-guidelines-active-preview]' ) ).toContainText( 'Active shared copy.' );
		const manage = page.getByRole( 'link', { name: 'Manage Shared Guidelines' } );
		await expect( manage ).toHaveAttribute( 'href', /page=guidelines-wp-admin$/ );
		const fallback = page.locator( '[data-guidelines-fallback]' );
		await expect( fallback ).toHaveJSProperty( 'open', false );
		await fallback.locator( ':scope > summary' ).click();
		await expect( page.locator( '#flavor_agent_guideline_copy' ) ).toHaveValue( 'Stored fallback copy.' );
		await expect( page.locator( '[data-guidelines-block-list]' ) ).toContainText( 'Stored fallback image.' );
		await expect( page.locator( '[data-guidelines-block-list]' ) ).not.toContainText( 'Active shared paragraph.' );
		await page.locator( '[data-guidelines-file-input]' ).setInputFiles( {
			name: 'disposable-fallback.json', mimeType: 'application/json',
			buffer: Buffer.from( JSON.stringify( { guideline_categories: { copy: { guidelines: 'Changed fallback copy.' }, blocks: { 'core/image': { guidelines: 'Imported fallback image.' } } } } ) ),
		} );
		await expect( page.locator( '#flavor_agent_guideline_copy' ) ).toHaveValue( 'Changed fallback copy.' );
		await expect( page.locator( '[data-guidelines-notice]' ) ).toContainText( 'Save Changes to persist the inactive fallback' );
		await expect( page.locator( '[data-guidelines-active-preview]' ) ).toContainText( 'Active shared copy.' );
		const downloadPromise = page.waitForEvent( 'download' );
		await page.getByRole( 'button', { name: 'Export Fallback JSON' } ).click();
		const download = await downloadPromise;
		const exported = JSON.parse( fs.readFileSync( await download.path(), 'utf8' ) );
		expect( JSON.stringify( exported ) ).toContain( 'Changed fallback copy.' );
		expect( JSON.stringify( exported ) ).not.toContain( 'Active shared copy.' );
		await page.getByRole( 'button', { name: 'Save Changes', exact: true } ).click();
		await page.waitForLoadState( 'domcontentloaded' );
		await waitForWordPressReady( page );
		await expect( page.locator( '.flavor-agent-settings-save-summary' ) ).toContainText( 'Legacy fallback guidelines saved' );
		await expect( page.locator( '.flavor-agent-settings-save-summary' ) ).toContainText( 'Active shared or provider guidelines are unchanged' );
		const effective = JSON.parse( evalFixture( `echo wp_json_encode( FlavorAgent\\Guidelines::get_all() );` ) );
		expect( effective.copy ).toBe( 'Active shared copy.' );
		expect( effective.blocks[ 'core/paragraph' ] ).toBe( 'Active shared paragraph.' );
		await openSection( page, 'guidelines' );
		await page.screenshot( { path: path.join( artifacts, 'shared-guidelines-ownership.png' ), fullPage: true } );
		await manage.click();
		await expect( page ).toHaveURL( /page=guidelines-wp-admin$/ );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'Guidelines' );
	} finally {
		evalFixture( `foreach ( ${ JSON.stringify( ids ) } as $id ) { wp_delete_post( $id, true ); } update_option( 'gutenberg-experiments', [] );` );
	}
} );

test( '@wp70-site-editor dashboard omits editor links when the settings administrator cannot edit posts', async ( { page } ) => {
	evalFixture( `
add_role( 'flavor_settings_reviewer', 'Settings reviewer fixture', [ 'read' => true, 'manage_options' => true ] );
$id = username_exists( 'flavor-settings-reviewer' ) ?: wp_create_user( 'flavor-settings-reviewer', 'local-fixture-password', 'fixture@example.test' );
wp_set_password( 'local-fixture-password', $id );
( new WP_User( $id ) )->set_role( 'flavor_settings_reviewer' );
` );
	try {
		await page.context().clearCookies();
		await page.goto( '/wp-login.php', { waitUntil: 'load' } );
		await expect( page.locator( '#user_login' ) ).toBeFocused();
		await page.locator( '#user_login' ).fill( 'flavor-settings-reviewer' );
		await page.locator( '#user_pass' ).fill( 'local-fixture-password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /\/wp-admin\// );
		await openSettings( page );
		await expect( page.getByRole( 'link', { name: 'Open Post Editor', exact: true } ) ).toHaveCount( 0 );
		await expect( page.locator( '#flavor-agent-editor-handoff' ) ).toContainText( 'permission to create and edit posts' );
	} finally {
		evalFixture( `$id = username_exists( 'flavor-settings-reviewer' ); if ( $id ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id ); } remove_role( 'flavor_settings_reviewer' );` );
	}
} );

test( '@wp70-site-editor dashboard routes a disabled Flavor Agent feature to its registered setup screen', async ( { page } ) => {
	evalFixture( `update_option( 'wpai_feature_flavor-agent_enabled', '0' );` );
	try {
		await openSettings( page );
		await expect( page.getByRole( 'link', { name: 'Open Post Editor', exact: true } ) ).toHaveCount( 0 );
		await expect( page.locator( '#flavor-agent-editor-handoff' ) ).toContainText( 'editor recommendations are disabled' );
		const setup = page.locator( '.flavor-agent-admin-hero__actions' ).getByRole( 'link', { name: 'Open AI Settings', exact: true } );
		await expect( setup ).toHaveAttribute( 'href', /page=ai-wp-admin$/ );
		await setup.click();
		await expect( page ).toHaveURL( /page=ai-wp-admin$/ );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'Flavor Agent' );
	} finally {
		evalFixture( `update_option( 'wpai_feature_flavor-agent_enabled', '1' );` );
	}
} );
