#!/usr/bin/env node
// PostToolUse hook (Edit|Write|MultiEdit): runs `eslint --fix` on edited
// src/ JavaScript files. Best-effort — never fails the tool.
//
// Cross-shell / cross-OS: reads the JSON payload from stdin (no jq) and invokes
// ESLint via `node node_modules/eslint/bin/eslint.js` instead of the platform
// `.bin/eslint` shim, so it works under PowerShell, cmd, and bash alike.

import { readFileSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

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

const filePath =
	payload?.tool_input?.file_path ||
	payload?.tool_response?.filePath ||
	'';
if ( ! filePath ) {
	process.exit( 0 );
}

// Only lint JS/JSX under a src/ directory.
if ( ! /\/src\/.+\.jsx?$/.test( filePath.replace( /\\/g, '/' ) ) ) {
	process.exit( 0 );
}

// Resolve ESLint relative to the repo root (two levels up from .claude/hooks/),
// independent of the current working directory.
const projectRoot = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const eslintBin = join( projectRoot, 'node_modules', 'eslint', 'bin', 'eslint.js' );
if ( ! existsSync( eslintBin ) ) {
	process.exit( 0 );
}

spawnSync( process.execPath, [ eslintBin, '--fix', filePath ], {
	stdio: 'ignore',
	cwd: projectRoot,
} );

// Lint outcome must never block the edit.
process.exit( 0 );
