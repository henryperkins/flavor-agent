#!/usr/bin/env node

'use strict';

const { spawnSync } = require( 'child_process' );

function commandExists( command ) {
	const probeCommand = process.platform === 'win32' ? 'where' : 'which';
	const probe = spawnSync( probeCommand, [ command ], {
		stdio: [ 'ignore', 'ignore', 'ignore' ],
	} );
	return probe.status === 0;
}

function detectComposeCommand() {
	const dockerComposePluginProbe = spawnSync(
		'docker',
		[ 'compose', 'version' ],
		{
			stdio: [ 'ignore', 'ignore', 'ignore' ],
		}
	);
	if ( dockerComposePluginProbe.status === 0 ) {
		return { command: 'docker', args: [ 'compose' ] };
	}
	if ( commandExists( 'docker-compose' ) ) {
		return { command: 'docker-compose', args: [] };
	}
	throw new Error(
		'Docker Compose not found. Install one of: `docker compose` (Docker CLI plugin) or `docker-compose`.'
	);
}

function main() {
	const command = process.argv.slice( 2 );
	if ( command.length === 0 ) {
		process.stderr.write(
			'Usage: node scripts/docker-compose.js <compose-command> [args...]\n'
		);
		process.exit( 1 );
	}

	const compose = detectComposeCommand();
	const cmd = compose.command;
	const args = [ ...compose.args, ...command ];
	const result = spawnSync( cmd, args, {
		stdio: 'inherit',
		cwd: process.cwd(),
		env: process.env,
	} );

	if ( result.error ) {
		throw result.error;
	}

	process.exit( result.status === null ? 1 : result.status );
}

if ( require.main === module ) {
	try {
		main();
	} catch ( err ) {
		process.stderr.write( `${ err.message }\n` );
		process.exit( 1 );
	}
}
