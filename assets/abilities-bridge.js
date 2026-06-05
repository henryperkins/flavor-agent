/* eslint-disable import/no-unresolved */
import * as coreAbilities from '@wordpress/core-abilities';
import { executeAbility } from '@wordpress/abilities';
import {
	buildAbilityRunPath,
	normalizeAbilityExecutionResult,
	shouldFallbackToAbilityRest,
} from './ability-execution-utils.js';

const ready =
	coreAbilities.ready && typeof coreAbilities.ready.then === 'function'
		? coreAbilities.ready
		: Promise.resolve();

/**
 * Self-healing executeAbility.
 *
 * On WordPress 7.0 / Gutenberg 23.3 the core abilities store defers fetching
 * abilities until the command/workflow palette opens (gutenberg#78316). A
 * direct call against an un-hydrated store therefore throws "Ability not
 * found". External consumers of `window.flavorAgentAbilities` (MCP / Abilities
 * Explorer dry-runs) have no editor-store context to hydrate, so we recover by
 * running the ability over its REST endpoint — the same fallback the editor's
 * own `src/store/abilities-client.js` performs. `wp.apiFetch` is used because
 * this asset is a raw script module and cannot import the bundled client; it is
 * already nonce-configured by the editor runtime.
 *
 * @param {string} abilityName Fully-qualified ability name.
 * @param {*}      input       Ability input payload.
 * @return {Promise<*>} The ability result.
 */
async function executeAbilityWithRestFallback( abilityName, input ) {
	try {
		return normalizeAbilityExecutionResult(
			await executeAbility( abilityName, input )
		);
	} catch ( error ) {
		const apiFetch =
			typeof window !== 'undefined' ? window.wp?.apiFetch : null;

		if (
			! shouldFallbackToAbilityRest( error ) ||
			typeof apiFetch !== 'function'
		) {
			throw error;
		}

		return normalizeAbilityExecutionResult(
			await apiFetch( {
				path: buildAbilityRunPath( abilityName ),
				method: 'POST',
				data: { input },
			} )
		);
	}
}

window.flavorAgentAbilities = Object.freeze( {
	ready,
	executeAbility: executeAbilityWithRestFallback,
} );
