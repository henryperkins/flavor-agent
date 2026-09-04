const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

// Pinned to the exact stable WordPress 7.1.0 image so the harness is reproducible
// across runs. Use the fully qualified patch tag (not the floating `7.1` tag) so a
// future 7.1.x republish cannot silently change what the release gates verified.
// Override with FLAVOR_AGENT_WP70_BASE_IMAGE to test against another build.
const DEFAULT_BASE_IMAGE = 'wordpress:7.1.0-php8.2-apache';
const DEFAULT_WORDPRESS_PORT = '9404';
const DEFAULT_PHPMYADMIN_PORT = '9405';
const DEFAULT_THEME_SLUG = 'flavor-agent-e2e';
// Flavor Agent declares `Requires Plugins: ai` in its plugin header, so WP
// refuses to activate the plugin unless the AI plugin is present. The WP 7.1
// harness intentionally stays minimal (no MCP / AI provider plugins) — extend
// via FLAVOR_AGENT_WP70_COMPANION_PLUGINS only when a spec needs it. Entries
// accept a `slug@version` form so a gate can pin exactly what it verified.
const DEFAULT_COMPANION_PLUGINS = [ 'ai' ];
// The default plugin-backed editor gate targets the latest Gutenberg release,
// alongside a distinct bundled-editor gate for WordPress 7.0+. Keep this pin in
// sync with the package cohort in package.json and the CI/browser evidence.
// Explicit older versions remain available only for diagnostic runs.
const DEFAULT_GUTENBERG_VERSION = '23.9.0';

function getWp70HarnessConfig(
	rootDir = path.resolve( __dirname, '..' ),
	options = {}
) {
	const wordpressPort =
		process.env.FLAVOR_AGENT_WP70_PORT || DEFAULT_WORDPRESS_PORT;
	const baseURL =
		process.env.FLAVOR_AGENT_WP70_URL ||
		`http://127.0.0.1:${ wordpressPort }`;
	let requestedGutenberg = DEFAULT_GUTENBERG_VERSION;

	if ( process.env.FLAVOR_AGENT_WP70_GUTENBERG !== undefined ) {
		requestedGutenberg = process.env.FLAVOR_AGENT_WP70_GUTENBERG;
	}

	if ( options.gutenberg !== undefined ) {
		requestedGutenberg = options.gutenberg;
	}

	const gutenbergVersion = parseGutenbergVersion( requestedGutenberg );

	return {
		rootDir,
		baseURL,
		storageStatePath: path.join(
			rootDir,
			'output',
			'playwright-wp70',
			'wp70-storage-state.json'
		),
		resetOnBootstrap:
			process.env.FLAVOR_AGENT_WP70_RESET === undefined ||
			process.env.FLAVOR_AGENT_WP70_RESET === '1',
		themeSlug: process.env.FLAVOR_AGENT_WP70_THEME || DEFAULT_THEME_SLUG,
		companionPlugins: parseCompanionPlugins(
			process.env.FLAVOR_AGENT_WP70_COMPANION_PLUGINS,
			gutenbergVersion
		),
		gutenbergVersion,
		wordpressTitle:
			process.env.FLAVOR_AGENT_WP70_TITLE || 'Flavor Agent WP 7.1 E2E',
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
				'Flavor Agent WP 7.1 E2E',
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

/**
 * Split a companion entry into its slug and optional pinned version.
 *
 * @param {string} entry Either `slug` or `slug@version`.
 * @return {{slug: string, version: (string|null)}} Parsed companion.
 */
function parseCompanionPlugin( entry ) {
	const separator = entry.lastIndexOf( '@' );

	if ( separator <= 0 ) {
		return { slug: entry, version: null };
	}

	return {
		slug: entry.slice( 0, separator ),
		version: entry.slice( separator + 1 ),
	};
}

/**
 * Build the companion plugin list for a harness run.
 *
 * @param {string|undefined} raw       FLAVOR_AGENT_WP70_COMPANION_PLUGINS value.
 * @param {string|null}      gutenberg Gutenberg version to add, or null to skip.
 * @return {Array<{slug: string, version: (string|null)}>} Companions to install.
 */
function parseCompanionPlugins( raw, gutenberg = null ) {
	const entries =
		raw === undefined || raw === null || raw === ''
			? [ ...DEFAULT_COMPANION_PLUGINS ]
			: String( raw )
					.split( ',' )
					.map( ( entry ) => entry.trim() )
					.filter( ( entry ) => entry.length > 0 );

	const companions = entries.map( parseCompanionPlugin );

	if ( ! companions.some( ( companion ) => companion.slug === 'ai' ) ) {
		// `ai` is mandatory (Flavor Agent's plugin header requires it). Always
		// install it first regardless of how the override list is ordered.
		companions.unshift( { slug: 'ai', version: null } );
	}

	if ( gutenberg ) {
		// An explicit `gutenberg` entry in the override list wins, so a caller
		// can pin a different version than the flag's default.
		const existing = companions.find(
			( companion ) => companion.slug === 'gutenberg'
		);

		if ( ! existing ) {
			companions.push( { slug: 'gutenberg', version: gutenberg } );
		}
	}

	return companions;
}

/**
 * Resolve the requested Gutenberg version, if any.
 *
 * Accepts a boolean-ish opt-in (`1`, `true`) that selects the pinned default,
 * or an explicit version string. Anything falsy leaves Gutenberg out.
 *
 * @param {string|undefined} raw Flag or environment value.
 * @return {string|null} Version to install, or null.
 */
function parseGutenbergVersion( raw ) {
	if ( raw === undefined || raw === null || raw === '' ) {
		return null;
	}

	const value = String( raw ).trim();

	if ( value === '0' || value.toLowerCase() === 'false' ) {
		return null;
	}

	if ( value === '1' || value.toLowerCase() === 'true' ) {
		return DEFAULT_GUTENBERG_VERSION;
	}

	return value;
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
				`${ command } was not found on PATH. Install Docker Desktop or Docker Engine plus the Docker CLI before running the WordPress 7.1 browser harness.`
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
	const wrapperPath = path.join( __dirname, 'docker-compose.js' );
	return runCommand( 'node', [ wrapperPath, ...args ], {
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
		'The WordPress 7.1 browser harness did not become ready in time.'
	);
}

async function waitForHttp( harness ) {
	// A single successful response is not proof the server is stably serving.
	// Immediately after a first boot + core install, WordPress answers one GET
	// and then stalls the next request long enough for the auth setup spec to
	// burn its whole timeout on the login POST. Require consecutive successes
	// so the harness is only declared ready once it is actually settled.
	const requiredConsecutiveSuccesses = 3;
	let consecutiveSuccesses = 0;

	for ( let attempt = 0; attempt < 60; attempt++ ) {
		try {
			const response = await fetch( `${ harness.baseURL }/wp-login.php`, {
				redirect: 'manual',
			} );

			if ( response.ok || response.status === 302 ) {
				consecutiveSuccesses += 1;

				if ( consecutiveSuccesses >= requiredConsecutiveSuccesses ) {
					return;
				}

				await wait( 1000 );
				continue;
			}

			consecutiveSuccesses = 0;
		} catch {
			// The container may be up before Apache is fully reachable.
			consecutiveSuccesses = 0;
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
$table_name = $GLOBALS['wpdb']->prefix . 'flavor_agent_activity';
if ( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
	$GLOBALS['wpdb']->query( "TRUNCATE TABLE {$table_name}" );
}
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
		'post_content' => 'Seed content for the Flavor Agent WP 7.1 Site Editor harness.',
	)
);
update_option( 'show_on_front', 'posts' );
update_option( 'page_on_front', 0 );
update_option( 'page_for_posts', 0 );
`,
	] );
}

function installCompanionPlugins( harness ) {
	const companions = harness.companionPlugins || [];
	if ( companions.length === 0 ) {
		return;
	}

	// `wp plugin install --version=` applies to the whole command, so pinned
	// companions each get their own call and the rest install together.
	const unpinned = companions
		.filter( ( companion ) => ! companion.version )
		.map( ( companion ) => companion.slug );

	if ( unpinned.length > 0 ) {
		runWpCli( harness, [
			'plugin',
			'install',
			...unpinned,
			'--activate',
			'--force',
		] );
	}

	for ( const companion of companions.filter(
		( candidate ) => candidate.version
	) ) {
		runWpCli( harness, [
			'plugin',
			'install',
			companion.slug,
			`--version=${ companion.version }`,
			'--activate',
			'--force',
		] );
	}
}

function seedFlavorAgentOptions( harness ) {
	const optionValues = {
		wpai_features_enabled: '1',
		'wpai_feature_flavor-agent_enabled': '1',
		flavor_agent_openai_provider: 'cloudflare_workers_ai',
		flavor_agent_cloudflare_workers_ai_account_id: 'playground-account',
		flavor_agent_cloudflare_workers_ai_api_token:
			'playground-workers-token',
		flavor_agent_cloudflare_workers_ai_embedding_model:
			'@cf/qwen/qwen3-embedding-0.6b',
		flavor_agent_qdrant_url: 'https://example.test/qdrant',
		flavor_agent_qdrant_key: 'playground-qdrant-key',
	};

	for ( const [ optionName, value ] of Object.entries( optionValues ) ) {
		runWpCli( harness, [ 'option', 'update', optionName, value ] );
	}
}

async function bootstrapWp70Harness( options = {} ) {
	const harness = getWp70HarnessConfig(
		path.resolve( __dirname, '..' ),
		options
	);

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
	installCompanionPlugins( harness );
	runWpCli( harness, [ 'plugin', 'activate', 'flavor-agent' ] );
	seedFlavorAgentOptions( harness );
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

/**
 * Read `--with-gutenberg` / `--with-gutenberg=<version>` off the argument list.
 *
 * A flag rather than an environment prefix so the npm scripts stay portable.
 *
 * @param {string[]} argv Arguments after the subcommand.
 * @return {string|undefined} Raw flag value, or undefined when absent.
 */
function readGutenbergFlag( argv ) {
	const flag = argv
		.filter(
			( argument ) =>
				argument === '--with-gutenberg' ||
				argument.startsWith( '--with-gutenberg=' )
		)
		.at( -1 );

	if ( flag === undefined ) {
		return undefined;
	}

	const [ , value ] = flag.split( '=' );

	return value === undefined || value === '' ? '1' : value;
}

async function main() {
	const command = process.argv[ 2 ] || 'bootstrap';

	if ( command === 'bootstrap' ) {
		const harness = await bootstrapWp70Harness( {
			gutenberg: readGutenbergFlag( process.argv.slice( 3 ) ),
		} );
		const pinned = harness.companionPlugins
			.map(
				( companion ) =>
					companion.slug +
					( companion.version ? `@${ companion.version }` : '' )
			)
			.join( ', ' );
		process.stdout.write(
			`WP 7.1 browser harness ready at ${ harness.baseURL }\n` +
				`  WordPress image: ${ harness.composeEnv.WORDPRESS_BASE_IMAGE }\n` +
				`  Companion plugins: ${ pinned }\n`
		);
		return;
	}

	if ( command === 'teardown' ) {
		const harness = teardownWp70Harness();
		process.stdout.write(
			`WP 7.1 browser harness stopped for ${ harness.baseURL }\n`
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
	DEFAULT_GUTENBERG_VERSION,
	bootstrapWp70Harness,
	getWp70HarnessConfig,
	parseCompanionPlugins,
	parseGutenbergVersion,
	readGutenbergFlag,
	resetSiteEditorState,
	runWpCli,
	teardownWp70Harness,
};
