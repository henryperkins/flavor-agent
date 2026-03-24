const { bootstrapWp70Harness } = require( '../../scripts/wp70-e2e' );

module.exports = async function globalSetup() {
	await bootstrapWp70Harness();
};
