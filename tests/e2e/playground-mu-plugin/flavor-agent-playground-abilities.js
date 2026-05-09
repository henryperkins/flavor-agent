export async function executeAbility() {
	const error = new Error( 'Ability not found.' );
	error.code = 'ability_not_found';

	throw error;
}
