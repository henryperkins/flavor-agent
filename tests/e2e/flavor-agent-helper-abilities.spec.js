const { test, expect } = require( '@playwright/test' );
const {
	getWp70HarnessConfig,
	resetSiteEditorState,
	runWpCli,
} = require( '../../scripts/wp70-e2e' );

const harness = getWp70HarnessConfig();

let applicationPassword = '';

function runWpEval( code ) {
	return runWpCli( harness, [ 'eval', code ] );
}

function resetHelperSmokeState() {
	resetSiteEditorState( harness );
	runWpCli( harness, [ 'theme', 'activate', harness.themeSlug ] );
	runWpCli(
		harness,
		[ 'plugin', 'deactivate', 'flavor-agent-dense-fixtures' ],
		{
			allowFailure: true,
		}
	);
	runWpEval(
		`
$ids = get_posts(
	array(
		'post_type'   => 'wp_block',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $ids as $post_id ) {
	wp_delete_post( $post_id, true );
}
`
	);
	runWpCli( harness, [ 'cache', 'flush' ], { allowFailure: true } );
}

function seedSyncedPatterns() {
	const result = runWpEval(
		`
$alpha = wp_insert_post(
	array(
		'post_title'   => 'Alpha Partial Pattern',
		'post_name'    => 'alpha-partial-pattern',
		'post_type'    => 'wp_block',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:group --><div>Alpha Partial</div><!-- /wp:group -->',
	)
);
update_post_meta( $alpha, 'wp_pattern_sync_status', 'partial' );

$beta = wp_insert_post(
	array(
		'post_title'   => 'Beta Partial Pattern',
		'post_name'    => 'beta-partial-pattern',
		'post_type'    => 'wp_block',
		'post_status'  => 'draft',
		'post_content' => '<!-- wp:group --><div>Beta Partial</div><!-- /wp:group -->',
	)
);
update_post_meta( $beta, 'wp_pattern_sync_status', 'partial' );

$gamma = wp_insert_post(
	array(
		'post_title'   => 'Gamma Unsynced Pattern',
		'post_name'    => 'gamma-unsynced-pattern',
		'post_type'    => 'wp_block',
		'post_status'  => 'draft',
		'post_content' => '<!-- wp:paragraph --><p>Gamma Unsynced</p><!-- /wp:paragraph -->',
	)
);
update_post_meta( $gamma, 'wp_pattern_sync_status', 'unsynced' );

echo wp_json_encode(
	array(
		'alpha' => (int) $alpha,
		'beta'  => (int) $beta,
		'gamma' => (int) $gamma,
	)
);
`
	);

	return JSON.parse( result.stdout.trim() );
}

async function callAbility( abilityName, input ) {
	const url = new URL(
		`/wp-json/wp-abilities/v1/abilities/${ abilityName }/run`,
		harness.baseURL
	);
	if ( input !== undefined && input !== null ) {
		url.searchParams.set( 'input', JSON.stringify( input ) );
	}
	const credentials =
		String( harness.adminUser ) + ':' + String( applicationPassword );
	const request = {
		method: 'GET',
		headers: {
			Authorization: `Basic ${ Buffer.from( credentials ).toString(
				'base64'
			) }`,
			Connection: 'close',
		},
	};
	let response;

	try {
		response = await fetch( url, request );
	} catch ( error ) {
		response = await fetch( url, request );
	}

	const text = await response.text();
	let body;

	try {
		body = JSON.parse( text );
	} catch ( error ) {
		throw new Error(
			`Expected JSON from ${ abilityName }, received: ${ text }`
		);
	}

	expect( response.ok, JSON.stringify( body ) ).toBeTruthy();

	return body;
}

test.beforeAll( async () => {
	const label = `flavor-agent-helper-smoke-${ Date.now() }`;
	const result = runWpCli( harness, [
		'user',
		'application-password',
		'create',
		harness.adminUser,
		label,
		'--porcelain',
	] );

	applicationPassword = result.stdout.trim();
	expect( applicationPassword ).not.toBe( '' );
} );

test.beforeEach( async () => {
	resetHelperSmokeState();
} );

test( '@wp70-site-editor helper abilities smoke default theme contracts on live WordPress', async () => {
	const patternIds = seedSyncedPatterns();

	const themeStyles = await callAbility( 'flavor-agent/get-theme-styles' );
	expect( themeStyles.styles.color.background ).toBe( '#f5efe6' );
	expect( themeStyles.diagnostics.reason ).toBe( 'server-global-settings' );

	const templateParts = await callAbility(
		'flavor-agent/list-template-parts',
		{
			includeContent: false,
		}
	);
	expect( templateParts.templateParts ).toEqual(
		expect.arrayContaining( [
			expect.objectContaining( {
				slug: 'header',
				area: 'header',
			} ),
			expect.objectContaining( {
				slug: 'footer',
				area: 'footer',
			} ),
		] )
	);
	expect( templateParts.templateParts[ 0 ] ).not.toHaveProperty( 'content' );

	const partialPatterns = await callAbility(
		'flavor-agent/list-synced-patterns',
		{
			syncStatus: 'partial',
			search: 'partial',
			limit: 1,
			offset: 1,
		}
	);
	expect( partialPatterns.total ).toBe( 2 );
	expect( partialPatterns.patterns ).toHaveLength( 1 );
	expect( partialPatterns.patterns[ 0 ].id ).toBe( patternIds.beta );
	expect( partialPatterns.patterns[ 0 ].syncStatus ).toBe( 'partial' );
	expect( partialPatterns.patterns[ 0 ] ).not.toHaveProperty( 'content' );

	const syncedPattern = await callAbility(
		'flavor-agent/get-synced-pattern',
		{
			patternId: patternIds.beta,
		}
	);
	expect( syncedPattern.id ).toBe( patternIds.beta );
	expect( syncedPattern.syncStatus ).toBe( 'partial' );
	expect( syncedPattern.wpPatternSyncStatus ).toBe( 'partial' );
	expect( syncedPattern.content ).toContain( 'Beta Partial' );

	const blocks = await callAbility( 'flavor-agent/list-allowed-blocks', {
		search: 'paragraph',
		limit: 1,
	} );
	expect( blocks.total ).toBeGreaterThan( 0 );
	expect( blocks.blocks ).toHaveLength( 1 );
	expect( blocks.blocks[ 0 ].name ).toBe( 'core/paragraph' );
	expect( blocks.blocks[ 0 ].variations ).toEqual( [] );
} );

test( '@wp70-site-editor helper abilities smoke classic theme fallback data', async () => {
	runWpCli( harness, [ 'theme', 'activate', 'flavor-agent-classic' ] );
	runWpCli( harness, [ 'cache', 'flush' ], { allowFailure: true } );

	const activeTheme = await callAbility( 'flavor-agent/get-active-theme' );
	expect( activeTheme.stylesheet ).toBe( 'flavor-agent-classic' );
	expect( activeTheme.template ).toBe( 'flavor-agent-classic' );

	const themeStyles = await callAbility( 'flavor-agent/get-theme-styles' );
	expect( themeStyles.diagnostics.reason ).toBe( 'server-global-settings' );
	expect( themeStyles.styles ).toEqual( expect.any( Object ) );

	const templateParts = await callAbility(
		'flavor-agent/list-template-parts'
	);
	expect( templateParts.templateParts ).toEqual( [] );
} );

test( '@wp70-site-editor helper abilities smoke child theme identity and merged styles', async () => {
	runWpCli( harness, [ 'theme', 'activate', 'flavor-agent-child' ] );
	runWpCli( harness, [ 'cache', 'flush' ], { allowFailure: true } );

	const activeTheme = await callAbility( 'flavor-agent/get-active-theme' );
	expect( activeTheme.stylesheet ).toBe( 'flavor-agent-child' );
	expect( activeTheme.template ).toBe( 'flavor-agent-e2e' );

	const themeStyles = await callAbility( 'flavor-agent/get-theme-styles' );
	expect( themeStyles.styles.color.background ).toBe( '#fffaf2' );
	expect( themeStyles.elementStyles.button.base.text ).toBe( '#fffaf2' );
} );

test( '@wp70-site-editor helper abilities smoke plugin-dense block registry', async () => {
	runWpCli( harness, [
		'plugin',
		'activate',
		'flavor-agent-dense-fixtures',
	] );
	runWpCli( harness, [ 'cache', 'flush' ], { allowFailure: true } );

	const blocks = await callAbility( 'flavor-agent/list-allowed-blocks', {
		search: 'Dense Fixture',
		category: 'design',
		includeVariations: true,
		maxVariations: 1,
		limit: 5,
	} );

	expect( blocks.total ).toBe( 12 );
	expect( blocks.blocks ).toHaveLength( 5 );

	for ( const block of blocks.blocks ) {
		expect( block.name.startsWith( 'flavor-agent-dense/fixture-' ) ).toBe(
			true
		);
		expect( block.category ).toBe( 'design' );
		expect( block.variations.length ).toBeLessThanOrEqual( 1 );
	}
} );
