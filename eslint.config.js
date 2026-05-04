const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpScriptsConfig,
	{
		ignores: [
			'build/',
			'dist/',
			'node_modules/',
		],
	},
];