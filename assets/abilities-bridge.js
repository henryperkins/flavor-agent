/* eslint-disable import/no-unresolved */
import { executeAbility } from '@wordpress/abilities';
import {
	buildAbilityRunPath,
	normalizeAbilityExecutionResult,
	shouldFallbackToAbilityRest,
} from './ability-execution-utils.js';

/**
 * Readiness signal for `window.flavorAgentAbilities`.
 *
 * Deliberately resolved rather than tied to `@wordpress/core-abilities`.
 * Gutenberg 23.6 (gutenberg#79155) made that package auto-initialize on import
 * and moved its laziness into the module graph, so the workflow palette now
 * dynamically imports it only when opened. Statically importing it — or naming
 * it as a script-module dependency — defeats that and fires its two
 * `per_page: -1` requests for categories and abilities on every editor load.
 * The current REST controller caps `per_page` at 100, so both are rejected and
 * logged before the store ends up empty anyway.
 *
 * Flavor Agent's abilities are registered server-side and executed over REST,
 * so this bridge never needed the client store: `executeAbilityWithRestFallback`
 * below still uses the hydrated store when some other consumer has loaded it,
 * and otherwise falls through to the REST endpoint. `ready` stays on the frozen
 * global as a resolved promise so existing `await ...ready` callers keep working.
 */
const ready = Promise.resolve();

/**
 * Self-healing executeAbility.
 *
 * The core abilities store is only populated when something else on the page
 * loads `@wordpress/core-abilities` — since gutenberg#78316/#79155 that is the
 * workflow palette, on open. A call against an un-hydrated store therefore
 * throws "Ability not found", which is the normal path here. External consumers
 * of `window.flavorAgentAbilities` (MCP / Abilities Explorer dry-runs) have no
 * editor-store context either, so we recover by running the ability over its
 * REST endpoint — the same fallback the editor's own
 * `src/store/abilities-client.js` performs. `wp.apiFetch` is used because this
 * asset is a raw script module and cannot import the bundled client; it is
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
