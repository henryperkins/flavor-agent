#!/usr/bin/env node
// PreToolUse guard (Edit|Write|MultiEdit): blocks writes to generated or
// dependency directories so edits land on source under src/ or inc/ instead.
//
// Runs identically under PowerShell, cmd, bash, or any OS — the harness (Claude
// Code / Copilot) pipes the tool payload as JSON on stdin, which Node reads
// directly (no jq, no shell-specific `case`/redirection syntax).
//
// Exit 2 + stderr message => blocked. Exit 0 => allowed. Fails open: any read
// or parse problem allows the tool through (this is a convenience guard, not a
// security boundary), matching the previous shell hook's behavior.

import { readFileSync } from 'node:fs';

const BLOCKED_DIRS = [ 'build', 'vendor', 'node_modules', 'output', 'dist' ];

function readStdin() {
	try {
		return readFileSync( 0, 'utf8' );
	} catch {
		return '';
	}
}

let payload = {};
try {
	payload = JSON.parse( readStdin() || '{}' );
} catch {
	process.exit( 0 );
}

const filePath = payload?.tool_input?.file_path || '';
if ( ! filePath ) {
	process.exit( 0 );
}

// Normalize separators, drop the filename, and match blocked names only as
// directory components (a file literally named e.g. `build` is fine).
const dirSegments = filePath
	.replace( /\\/g, '/' )
	.toLowerCase()
	.split( '/' )
	.slice( 0, -1 );

const hit = BLOCKED_DIRS.find( ( dir ) => dirSegments.includes( dir ) );
if ( hit ) {
	process.stderr.write(
		`Blocked: ${ filePath } is in a generated/dependency directory (${ hit }/). Edit source under src/ or inc/ instead.\n`
	);
	process.exit( 2 );
}

process.exit( 0 );
