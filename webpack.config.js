const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'src/index.js' ),
		admin: path.resolve( __dirname, 'src/admin/settings-page.js' ),
		'activity-log': path.resolve( __dirname, 'src/admin/activity-log.js' ),
	},
};
