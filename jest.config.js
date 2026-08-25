const baseConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...baseConfig,
	// tools/code-search is Python-only dev tooling; its .venv vendors pywin32
	// scripts under */test/ that Jest's default scan would try to parse.
	//
	// .worktrees holds git worktrees of other branches. Jest would otherwise run
	// their test copies alongside this tree's, which both slows the run and
	// reports failures for code that is not checked out here — a worktree with
	// its own node_modules resolves package paths to itself, so path assertions
	// legitimately disagree with the main tree.
	testPathIgnorePatterns: [
		...( baseConfig.testPathIgnorePatterns || [ '/node_modules/' ] ),
		'/tools/code-search/',
		'/\\.worktrees/',
	],
	transformIgnorePatterns: [
		'/node_modules/(?!(uuid)/)',
		'\\.pnp\\.[^\\/]+$',
	],
};
