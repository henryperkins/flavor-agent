const baseConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...baseConfig,
	// tools/code-search is Python-only dev tooling; its .venv vendors pywin32
	// scripts under */test/ that Jest's default scan would try to parse.
	testPathIgnorePatterns: [
		...( baseConfig.testPathIgnorePatterns || [ '/node_modules/' ] ),
		'/tools/code-search/',
	],
	transformIgnorePatterns: [
		'/node_modules/(?!(uuid)/)',
		'\\.pnp\\.[^\\/]+$',
	],
};
