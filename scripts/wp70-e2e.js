const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const DEFAULT_BASE_IMAGE = 'wordpress:beta-7.0-beta4-php8.2-apache';
const DEFAULT_WORDPRESS_PORT = '9404';
const DEFAULT_PHPMYADMIN_PORT = '9405';
const DEFAULT_THEME_SLUG = 'flavor-agent-e2e';

function getWp70HarnessConfig( rootDir = path.resolve( __dirname, '..' ) ) {
	const wordpressPort =
		process.env.FLAVOR_AGENT_WP70_PORT || DEFAULT_WORDPRESS_PORT;
	const baseURL =
		process.env.FLAVOR_AGENT_WP70_URL ||
		`http://127.0.0.1:${ wordpressPort }`;

	return {
		rootDir,
		baseURL,
		storageStatePath: path.join(
			rootDir,
			'output',
			'playwright',
			'wp70-storage-state.json'
		),
		resetOnBootstrap:
			process.env.FLAVOR_AGENT_WP70_RESET === undefined ||
			process.env.FLAVOR_AGENT_WP70_RESET === '1',
		themeSlug: process.env.FLAVOR_AGENT_WP70_THEME || DEFAULT_THEME_SLUG,
		wordpressTitle:
			process.env.FLAVOR_AGENT_WP70_TITLE || 'Flavor Agent WP 7.0 E2E',
		adminUser: process.env.FLAVOR_AGENT_WP70_ADMIN_USER || 'admin',
		adminPassword: process.env.FLAVOR_AGENT_WP70_ADMIN_PASSWORD || 'admin',
		adminEmail:
			process.env.FLAVOR_AGENT_WP70_ADMIN_EMAIL || 'admin@example.com',
		composeEnv: {
			...process.env,
			COMPOSE_PROJECT_NAME:
				process.env.FLAVOR_AGENT_WP70_COMPOSE_PROJECT_NAME ||
				'flavor-agent-wp70',
			WORDPRESS_BASE_IMAGE:
				process.env.FLAVOR_AGENT_WP70_BASE_IMAGE || DEFAULT_BASE_IMAGE,
			WORDPRESS_PORT: wordpressPort,
			PHPMYADMIN_PORT:
				process.env.FLAVOR_AGENT_WP70_PHPMYADMIN_PORT ||
				DEFAULT_PHPMYADMIN_PORT,
			MYSQL_DATABASE:
				process.env.FLAVOR_AGENT_WP70_MYSQL_DATABASE || 'wordpress',
			MYSQL_USER: process.env.FLAVOR_AGENT_WP70_MYSQL_USER || 'wordpress',
			MYSQL_PASSWORD:
				process.env.FLAVOR_AGENT_WP70_MYSQL_PASSWORD || 'wordpress',
			MYSQL_ROOT_PASSWORD:
				process.env.FLAVOR_AGENT_WP70_MYSQL_ROOT_PASSWORD || 'root',
			WORDPRESS_URL: baseURL,
			WORDPRESS_TITLE:
				process.env.FLAVOR_AGENT_WP70_TITLE ||
				'Flavor Agent WP 7.0 E2E',
			WORDPRESS_ADMIN_USER:
				process.env.FLAVOR_AGENT_WP70_ADMIN_USER || 'admin',
			WORDPRESS_ADMIN_PASSWORD:
				process.env.FLAVOR_AGENT_WP70_ADMIN_PASSWORD || 'admin',
			WORDPRESS_ADMIN_EMAIL:
				process.env.FLAVOR_AGENT_WP70_ADMIN_EMAIL ||
				'admin@example.com',
		},
	};
}

function runCommand( command, args, options = {} ) {
	const result = spawnSync( command, args, {
		cwd: options.cwd,
		env: options.env,
		encoding: 'utf8',
	} );

	if ( result.error ) {
		if ( result.error.code === 'ENOENT' ) {
			throw new Error(
				`${ command } was not found on PATH. Install Docker Desktop or Docker Engine plus the Docker CLI before running the WordPress 7.0 browser harness.`
			);
		}

		throw result.error;
	}

	if ( result.status !== 0 && ! options.allowFailure ) {
		throw new Error(
			[
				`Command failed: ${ command } ${ args.join( ' ' ) }`,
				result.stdout?.trim() || '',
				result.stderr?.trim() || '',
			]
				.filter( Boolean )
				.join( '\n' )
		);
	}

	return result;
}

function runDockerCompose( harness, args, options = {} ) {
	return runCommand( 'docker', [ 'compose', ...args ], {
		...options,
		cwd: harness.rootDir,
		env: harness.composeEnv,
	} );
}

function runWpCli( harness, args, options = {} ) {
	return runDockerCompose(
		harness,
		[ 'exec', '-T', 'wordpress', 'wp', ...args, '--allow-root' ],
		options
	);
}

function wait( delayMs ) {
	return new Promise( ( resolve ) => {
		setTimeout( resolve, delayMs );
	} );
}

async function waitForWordPressCli( harness ) {
	for ( let attempt = 0; attempt < 40; attempt++ ) {
		const result = runWpCli( harness, [ 'core', 'version' ], {
			allowFailure: true,
		} );

		if ( result.status === 0 ) {
			return;
		}

		await wait( 3000 );
	}

	throw new Error(
		'The WordPress 7.0 browser harness did not become ready in time.'
	);
}

async function waitForHttp( harness ) {
	for ( let attempt = 0; attempt < 40; attempt++ ) {
		try {
			const response = await fetch( `${ harness.baseURL }/wp-login.php`, {
				redirect: 'manual',
			} );

			if ( response.ok || response.status === 302 ) {
				return;
			}
		} catch ( error ) {
			// The container may be up before Apache is fully reachable.
		}

		await wait( 1000 );
	}

	throw new Error(
		`WordPress login never became reachable at ${ harness.baseURL }/wp-login.php.`
	);
}

function ensureOutputDirectory( harness ) {
	fs.mkdirSync( path.dirname( harness.storageStatePath ), {
		recursive: true,
	} );

	if ( fs.existsSync( harness.storageStatePath ) ) {
		fs.unlinkSync( harness.storageStatePath );
	}
}

function resetSiteEditorState( harness ) {
	runWpCli( harness, [
		'eval',
		`
$ids = get_posts(
	array(
		'post_type'   => array( 'post', 'page', 'wp_template', 'wp_template_part' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $ids as $post_id ) {
	wp_delete_post( $post_id, true );
}
wp_insert_post(
	array(
		'post_title'   => 'Flavor Agent E2E Post',
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => 'Seed content for the Flavor Agent WP 7.0 Site Editor harness.',
	)
);
update_option( 'show_on_front', 'posts' );
update_option( 'page_on_front', 0 );
update_option( 'page_for_posts', 0 );
`,
	] );
}

async function bootstrapWp70Harness() {
	const harness = getWp70HarnessConfig();

	ensureOutputDirectory( harness );

	if ( harness.resetOnBootstrap ) {
		runDockerCompose( harness, [ 'down', '-v', '--remove-orphans' ], {
			allowFailure: true,
		} );
	}

	runDockerCompose( harness, [ 'up', '-d', '--build' ] );
	await waitForWordPressCli( harness );

	const isInstalled =
		runWpCli( harness, [ 'core', 'is-installed' ], { allowFailure: true } )
			.status === 0;

	if ( ! isInstalled ) {
		runWpCli( harness, [
			'core',
			'install',
			`--url=${ harness.baseURL }`,
			`--title=${ harness.wordpressTitle }`,
			`--admin_user=${ harness.adminUser }`,
			`--admin_password=${ harness.adminPassword }`,
			`--admin_email=${ harness.adminEmail }`,
			'--skip-email',
		] );
	}

	runWpCli( harness, [ 'option', 'update', 'home', harness.baseURL ] );
	runWpCli( harness, [ 'option', 'update', 'siteurl', harness.baseURL ] );
	runWpCli( harness, [ 'plugin', 'activate', 'flavor-agent' ] );
	runWpCli( harness, [ 'theme', 'activate', harness.themeSlug ] );
	runWpCli( harness, [ 'rewrite', 'structure', '/%postname%/', '--hard' ] );
	resetSiteEditorState( harness );
	runWpCli( harness, [ 'cache', 'flush' ], { allowFailure: true } );
	await waitForHttp( harness );

	return harness;
}

function teardownWp70Harness() {
	const harness = getWp70HarnessConfig();

	runDockerCompose( harness, [ 'down', '-v', '--remove-orphans' ], {
		allowFailure: true,
	} );

	return harness;
}

async function main() {
	const command = process.argv[ 2 ] || 'bootstrap';

	if ( command === 'bootstrap' ) {
		const harness = await bootstrapWp70Harness();
		process.stdout.write(
			`WP 7.0 browser harness ready at ${ harness.baseURL }\n`
		);
		return;
	}

	if ( command === 'teardown' ) {
		const harness = teardownWp70Harness();
		process.stdout.write(
			`WP 7.0 browser harness stopped for ${ harness.baseURL }\n`
		);
		return;
	}

	throw new Error(
		`Unknown wp70-e2e command "${ command }". Expected "bootstrap" or "teardown".`
	);
}

if ( require.main === module ) {
	main().catch( ( error ) => {
		process.stderr.write( `${ error.message }\n` );
		process.exit( 1 );
	} );
}

module.exports = {
	bootstrapWp70Harness,
	getWp70HarnessConfig,
	teardownWp70Harness,
};
