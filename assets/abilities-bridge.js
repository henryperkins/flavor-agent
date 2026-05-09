/* eslint-disable import/no-unresolved */
import * as coreAbilities from '@wordpress/core-abilities';
import { executeAbility } from '@wordpress/abilities';

const ready =
	coreAbilities.ready && typeof coreAbilities.ready.then === 'function'
		? coreAbilities.ready
		: Promise.resolve();

window.flavorAgentAbilities = Object.freeze( {
	ready,
	executeAbility,
} );
