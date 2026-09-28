const { test, expect } = require( './test-fixtures' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const harness = getWp70HarnessConfig();

test( '@wp70-site-editor contributor activity sync cannot disclose or rewrite a server apply', async ( {
	page,
} ) => {
	const username = `activity-boundary-${ Date.now() }`;
	const password = 'activity-boundary-harness-only';
	const seeded = JSON.parse(
		runWpCli( harness, [
			'eval',
			`
$user_id = wp_insert_user( array( 'user_login' => '${ username }', 'user_pass' => '${ password }', 'role' => 'contributor' ) );
if ( is_wp_error( $user_id ) ) { WP_CLI::error( $user_id ); }
$post_id = wp_insert_post( array( 'post_title' => 'Activity boundary draft', 'post_status' => 'draft', 'post_author' => $user_id ), true );
if ( is_wp_error( $post_id ) ) { WP_CLI::error( $post_id ); }
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$victim_id = 'activity-boundary-' . wp_generate_uuid4();
$victim = \\FlavorAgent\\Activity\\Repository::create( array(
	'id' => $victim_id,
	'type' => 'apply_global_styles_suggestion',
	'surface' => 'global-styles',
	'applyLane' => 'server-executed',
	'target' => array( 'globalStylesId' => '999999' ),
	'document' => array( 'scopeKey' => 'global_styles:999999', 'postType' => 'global_styles', 'entityId' => '999999' ),
	'suggestion' => 'Private server apply',
	'undo' => array( 'status' => 'available' ),
	'request' => array( 'prompt' => 'Private server prompt', 'apply' => array( 'status' => 'executed' ) ),
) );
if ( is_wp_error( $victim ) ) { WP_CLI::error( $victim ); }
echo wp_json_encode( array( 'userId' => $user_id, 'postId' => $post_id, 'victimId' => $victim_id, 'victim' => $victim ) );
`,
		] ).stdout.trim()
	);

	try {
		await page.context().clearCookies();
		await page.goto( '/wp-login.php', { waitUntil: 'load' } );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /\/wp-admin(?:\/|\/index\.php)?$/ );
		await page.goto(
			`/wp-admin/post.php?post=${ seeded.postId }&action=edit`
		);
		await page.waitForFunction( () => Boolean( window.wp?.apiFetch ) );

		const results = await page.evaluate( async ( { postId, victimId } ) => {
			const entry = {
				id: victimId,
				type: 'apply_suggestion',
				surface: 'block',
				applyLane: 'editor-state',
				document: {
					scopeKey: `post:${ postId }`,
					postType: 'post',
					entityId: String( postId ),
				},
				undo: { status: 'undone', attestationStatus: 'recorded' },
			};
			const call = async ( options ) => {
				try {
					return {
						ok: true,
						data: await window.wp.apiFetch( options ),
					};
				} catch ( error ) {
					return {
						ok: false,
						status: error.data?.status,
						code: error.code,
					};
				}
			};
			const collision = await call( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
				data: { entry },
			} );
			const ownEntry = { ...entry, id: `${ victimId }-editor` };
			const created = await call( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
				data: { entry: ownEntry },
			} );
			const globalRead = await call( {
				path: `/flavor-agent/v1/activity?global=true&id=${ ownEntry.id }`,
			} );
			const pending = await call( {
				path: '/flavor-agent/v1/activity',
				method: 'POST',
				data: {
					entry: {
						...ownEntry,
						id: `${ victimId }-pending`,
						executionResult: 'pending',
						request: {
							apply: {
								status: 'pending',
								expiresAt: '2099-01-01T00:00:00Z',
							},
						},
					},
				},
			} );
			return { collision, created, globalRead, pending };
		}, seeded );
		expect( results.collision ).toMatchObject( { ok: false, status: 403 } );
		expect( results.globalRead ).toMatchObject( {
			ok: false,
			status: 403,
		} );
		expect( results.pending ).toMatchObject( { ok: false, status: 400 } );
		expect( results.created.ok ).toBe( true );
		expect( results.created.data.entry.undo ).toMatchObject( {
			status: 'undone',
			verification: 'client-reported',
		} );
		expect( results.created.data.entry.undo ).not.toHaveProperty(
			'attestationStatus'
		);
		const victim = JSON.parse(
			runWpCli( harness, [
				'eval',
				`echo wp_json_encode( \\FlavorAgent\\Activity\\Repository::find( '${ seeded.victimId }' ) );`,
			] ).stdout.trim()
		);
		expect( victim ).toEqual( seeded.victim );
	} finally {
		runWpCli( harness, [
			'eval',
			`
foreach ( array( '${ seeded.victimId }', '${ seeded.victimId }-editor', '${ seeded.victimId }-pending' ) as $id ) {
	$GLOBALS['wpdb']->delete( \\FlavorAgent\\Activity\\Repository::table_name(), array( 'activity_id' => $id ) );
}
wp_delete_post( ${ seeded.postId }, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( ${ seeded.userId } );
`,
		] );
	}
} );
