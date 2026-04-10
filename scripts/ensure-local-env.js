#!/usr/bin/env node

const fs = require( 'fs' );
const path = require( 'path' );

const repoRoot = path.resolve( __dirname, '..' );
const envPath = path.join( repoRoot, '.env' );
const examplePath = path.join( repoRoot, '.env.example' );

if ( fs.existsSync( envPath ) ) {
	process.exit( 0 );
}

if ( ! fs.existsSync( examplePath ) ) {
	console.error( 'Missing .env.example; cannot create local .env defaults.' );
	process.exit( 1 );
}

fs.copyFileSync( examplePath, envPath );
console.log( 'Created .env from .env.example' );
