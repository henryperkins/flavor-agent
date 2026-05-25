#!/usr/bin/env node
'use strict';

/* eslint-disable no-console */

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

function resolveBashExecutable( {
	platform = process.platform,
	env = process.env,
	fileExists = fs.existsSync,
} = {} ) {
	if ( env.FLAVOR_AGENT_BASH ) {
		return env.FLAVOR_AGENT_BASH;
	}

	if ( platform === 'win32' ) {
		const candidates = [
			env.ProgramFiles &&
				path.win32.join( env.ProgramFiles, 'Git', 'bin', 'bash.exe' ),
			env[ 'ProgramFiles(x86)' ] &&
				path.win32.join(
					env[ 'ProgramFiles(x86)' ],
					'Git',
					'bin',
					'bash.exe'
				),
		].filter( Boolean );

		for ( const candidate of candidates ) {
			if ( fileExists( candidate ) ) {
				return candidate;
			}
		}
	}

	return 'bash';
}

function main() {
	const args = process.argv.slice( 2 );

	if ( args.length === 0 ) {
		console.error( 'Usage: node scripts/run-bash.js <script> [args...]' );
		process.exit( 2 );
	}

	const result = spawnSync( resolveBashExecutable(), args, {
		stdio: 'inherit',
		env: process.env,
	} );

	if ( result.error ) {
		console.error( result.error.message );
		process.exit( 1 );
	}

	if ( result.signal ) {
		console.error( `Bash command terminated by signal ${ result.signal }` );
		process.exit( 1 );
	}

	process.exit( result.status ?? 1 );
}

if ( require.main === module ) {
	main();
}

module.exports = {
	resolveBashExecutable,
};
