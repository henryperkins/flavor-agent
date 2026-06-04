/* eslint-disable import/no-unresolved */
import * as coreAbilities from '@wordpress/core-abilities';
import { executeAbility } from '@wordpress/abilities';

const ready =
	coreAbilities.ready && typeof coreAbilities.ready.then === 'function'
		? coreAbilities.ready
		: Promise.resolve();

function buildAbilityRunPath( abilityName ) {
	const encoded = String( abilityName )
		.split( '/' )
		.map( encodeURIComponent )
		.join( '/' );

	return `/wp-abilities/v1/abilities/${ encoded }/run`;
}

function normalizeResult( result ) {
	if ( ! result || typeof result !== 'object' || Array.isArray( result ) ) {
		return result;
	}

	if ( Object.prototype.hasOwnProperty.call( result, 'payload' ) ) {
		return result.payload;
	}

	if ( Object.prototype.hasOwnProperty.call( result, 'result' ) ) {
		return result.result;
	}

	if ( Object.prototype.hasOwnProperty.call( result, 'output' ) ) {
		return result.output;
	}

	return result;
}

function shouldFallbackToRest( error ) {
	const message = typeof error?.message === 'string' ? error.message : '';

	return (
		error?.code === 'ability_not_found' ||
		message.includes( 'Ability not found' )
	);
}

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
		return normalizeResult( await executeAbility( abilityName, input ) );
	} catch ( error ) {
		const apiFetch =
			typeof window !== 'undefined' ? window.wp?.apiFetch : null;

		if (
			! shouldFallbackToRest( error ) ||
			typeof apiFetch !== 'function'
		) {
			throw error;
		}

		return normalizeResult(
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
