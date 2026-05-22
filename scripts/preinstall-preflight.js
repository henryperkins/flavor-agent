#!/usr/bin/env node

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );

function parseVersion( version ) {
	const normalized = String( version ).trim().replace( /^v/, '' );
	const [ core ] = normalized.split( '-' );
	const parts = core.split( '.' ).map( Number );
	if ( parts.length < 3 || parts.some( Number.isNaN ) ) {
		throw new Error( `Unable to parse semver version "${ version }".` );
	}
	return parts;
}

function compareVersions( a, b ) {
	for ( let i = 0; i < 3; i++ ) {
		if ( a[ i ] > b[ i ] ) return 1;
		if ( a[ i ] < b[ i ] ) return -1;
	}
	return 0;
}

function matchComparator( version, token ) {
	const comparator = token.trim();
	if ( comparator === '' || comparator === '*' ) {
		return true;
	}

	const caret = comparator.match( /^\^(\d+\.\d+\.\d+)$/ );
	if ( caret ) {
		const min = parseVersion( caret[ 1 ] );
		const max = [ min[ 0 ] + 1, 0, 0 ];
		return compareVersions( version, min ) >= 0 && compareVersions( version, max ) < 0;
	}

	const tilde = comparator.match( /^~(\d+\.\d+\.\d+)$/ );
	if ( tilde ) {
		const min = parseVersion( tilde[ 1 ] );
		const max = [ min[ 0 ], min[ 1 ] + 1, 0 ];
		return compareVersions( version, min ) >= 0 && compareVersions( version, max ) < 0;
	}

	const bounded = comparator.match( /^(>=|<=|>|<|=)?\s*(\d+\.\d+\.\d+)$/ );
	if ( bounded ) {
		const operator = bounded[ 1 ] || '=';
		const target = parseVersion( bounded[ 2 ] );
		const cmp = compareVersions( version, target );
		switch ( operator ) {
			case '>':
				return cmp > 0;
			case '>=':
				return cmp >= 0;
			case '<':
				return cmp < 0;
			case '<=':
				return cmp <= 0;
			case '=':
				return cmp === 0;
		}
	}

	throw new Error( `Unsupported semver comparator "${ comparator }".` );
}

function satisfiesRange( versionString, rangeString ) {
	const version = parseVersion( versionString );
	const range = String( rangeString || '' ).trim();
	if ( ! range ) {
		return true;
	}

	return range.split( '||' ).some( ( alternate ) => {
		const comparators = alternate.trim().split( /\s+/ ).filter( Boolean );
		if ( comparators.length === 0 ) {
			return true;
		}
		return comparators.every( ( token ) => matchComparator( version, token ) );
	} );
}

function main() {
	const packagePath = path.resolve( __dirname, '..', 'package.json' );
	const packageJson = JSON.parse( fs.readFileSync( packagePath, 'utf8' ) );
	const nodeRange = packageJson.engines?.node;
	const npmRange = packageJson.engines?.npm;

	if ( ! nodeRange && ! npmRange ) {
		return;
	}

	const currentNode = process.versions.node;
	const currentNpm = execFileSync( 'npm', [ '--version' ], {
		encoding: 'utf8',
	} ).trim();

	const nodeOk = ! nodeRange || satisfiesRange( currentNode, nodeRange );
	const npmOk = ! npmRange || satisfiesRange( currentNpm, npmRange );

	if ( nodeOk && npmOk ) {
		console.log( `Engine preflight passed (node ${ currentNode }, npm ${ currentNpm }).` );
		return;
	}

	const requirements = [
		nodeRange ? `node ${ nodeRange }` : null,
		npmRange ? `npm ${ npmRange }` : null,
	].filter( Boolean ).join( '; ' );

	console.error( 'Engine preflight failed.' );
	console.error( `Required: ${ requirements }` );
	console.error( `Current: node ${ currentNode }; npm ${ currentNpm }` );
	process.exit( 1 );
}

main();
