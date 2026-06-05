#!/usr/bin/env node
'use strict';

/**
 * Install this repo's Codex skills into the local Codex skills home.
 *
 * Codex only auto-discovers skills from `$CODEX_HOME/skills` (default
 * `~/.codex/skills`), so the repo-tracked skills under `.codex/skills/` are not
 * picked up directly. This copies (or, with `--link`, symlinks) each repo skill
 * into the Codex skills home so it becomes invocable as `$skill-name`.
 *
 * Usage:
 *   node scripts/install-codex-skills.js            # copy (default)
 *   node scripts/install-codex-skills.js --link     # symlink (repo edits stay live)
 *   node scripts/install-codex-skills.js --dry-run  # print plan only
 */

const fs = require('fs');
const os = require('os');
const path = require('path');

function log(message) {
	process.stdout.write(`${message}\n`);
}

function resolveCodexHome() {
	const fromEnv = process.env.CODEX_HOME;
	if (fromEnv && fromEnv.trim() !== '') {
		return fromEnv;
	}
	return path.join(os.homedir(), '.codex');
}

function listSkillDirs(sourceRoot) {
	if (!fs.existsSync(sourceRoot)) {
		return [];
	}
	return fs
		.readdirSync(sourceRoot, { withFileTypes: true })
		.filter((entry) => entry.isDirectory())
		.map((entry) => entry.name)
		.filter((name) => fs.existsSync(path.join(sourceRoot, name, 'SKILL.md')))
		.sort();
}

function main() {
	const args = process.argv.slice(2);
	const useLink = args.includes('--link');
	const dryRun = args.includes('--dry-run');

	const repoRoot = path.resolve(__dirname, '..');
	const sourceRoot = path.join(repoRoot, '.codex', 'skills');
	const destRoot = path.join(resolveCodexHome(), 'skills');

	const skills = listSkillDirs(sourceRoot);
	if (skills.length === 0) {
		log(`No skills found in ${sourceRoot} (expected <name>/SKILL.md).`);
		return;
	}

	log(`Source: ${sourceRoot}`);
	log(`Target: ${destRoot}`);
	log(`Mode:   ${useLink ? 'symlink' : 'copy'}${dryRun ? ' (dry-run)' : ''}`);
	log('');

	if (!dryRun) {
		fs.mkdirSync(destRoot, { recursive: true });
	}

	for (const name of skills) {
		const src = path.join(sourceRoot, name);
		const dest = path.join(destRoot, name);

		if (dryRun) {
			log(`would install: ${name} -> ${dest}`);
			continue;
		}

		// Replace any existing copy/symlink so installs are idempotent.
		fs.rmSync(dest, { recursive: true, force: true });

		if (useLink) {
			try {
				// 'junction' avoids needing elevated privileges on Windows and is
				// ignored on POSIX (treated as a directory symlink).
				fs.symlinkSync(src, dest, 'junction');
				log(`linked:  ${name}`);
				continue;
			} catch (error) {
				fs.cpSync(src, dest, { recursive: true });
				log(`copied:  ${name} (symlink failed: ${error.code || error.message})`);
				continue;
			}
		}

		fs.cpSync(src, dest, { recursive: true });
		log(`copied:  ${name}`);
	}

	if (dryRun) {
		return;
	}

	log('');
	log(`Done. ${skills.length} skill(s) installed to ${destRoot}.`);
	log('Invoke in Codex with e.g. `$ui-theme-style-review` (implicit triggering is enabled).');
}

main();
