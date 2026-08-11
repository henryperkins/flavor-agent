/**
 * PostCSS configuration.
 *
 * `@wordpress/scripts` only supplies its fallback PostCSS options when a project
 * has no config of its own (see `hasPostCSSConfig()` in its `utils/config.js`),
 * so this file takes over that job. It reuses `@wordpress/postcss-plugins-preset`
 * verbatim except for `postcss-import`, which is re-instantiated with a resolver
 * that understands package `exports` maps.
 *
 * Why: `postcss-import`'s default resolver goes through the `resolve` package,
 * which ignores `exports`. Stylesheets therefore had to reach past a package's
 * public entry points and hard-code a path into `node_modules` — a private
 * layout detail that upstream is free to move. `@wordpress/theme` did exactly
 * that: through 0.x it published the design tokens at
 * `src/prebuilt/css/design-tokens.css`, and 1.0.0 (Gutenberg 23.7,
 * gutenberg#80213) stopped shipping `src` and moved the file to
 * `prebuilt/css/design-tokens.css`. Both releases map the same public
 * `./design-tokens.css` export at it, so resolving through `exports` survives
 * the move while the deep path does not.
 *
 * Bundling the stylesheet from JavaScript instead is not an option here:
 * `@wordpress/dependency-extraction-webpack-plugin` externalizes any
 * `@wordpress/*` request, so `import '@wordpress/theme/design-tokens.css'`
 * becomes a `wp-theme/design-tokens.css` script handle in `*.asset.php` and no
 * CSS reaches the bundle at all.
 */

const path = require( 'path' );
const postcssImport = require( 'postcss-import' );
const presetPlugins = require( '@wordpress/postcss-plugins-preset' );

const isProduction = process.env.NODE_ENV === 'production';

/**
 * Resolve an `@import` target.
 *
 * Relative and absolute targets keep plain filesystem semantics — that is what a
 * stylesheet author writing `../foo.css` means, and it is the only way to reach
 * files that a package ships but does not export. `@wordpress/base-styles` and
 * `@wordpress/dataviews` both fall in that bucket: they map their stylesheets
 * with a trailing-slash directory export, which Node's resolver dropped
 * (DEP0148), so a bare specifier for them raises ERR_PACKAGE_PATH_NOT_EXPORTED.
 *
 * Bare specifiers go through Node's own resolution so `exports` maps, including
 * conditional and subpath entries, are honored.
 *
 * A failed bare lookup is rethrown through postcss's own `node.error()` so the
 * build names the stylesheet and line that asked for the import, the way
 * postcss-import's default resolver does. Node's reason is kept verbatim in the
 * message because it is the part worth reading: MODULE_NOT_FOUND means the
 * package is missing, while ERR_PACKAGE_PATH_NOT_EXPORTED means it is installed
 * but does not publish that subpath — a distinction that decides whether the fix
 * is an install or a relative path.
 *
 * @param {string} id      The `@import` target as written in the stylesheet.
 * @param {string} base    Directory of the importing stylesheet.
 * @param {Object} options postcss-import options (unused).
 * @param {Object} node    The `@import` at-rule, used for source position.
 * @return {string} Absolute path to the imported file.
 */
function resolveImport( id, base, options, node ) {
	if ( id.startsWith( '.' ) || path.isAbsolute( id ) ) {
		return path.resolve( base, id );
	}

	try {
		return require.resolve( id, { paths: [ base ] } );
	} catch ( error ) {
		if ( typeof node?.error !== 'function' ) {
			throw error;
		}

		throw node.error(
			`Failed to resolve "${ id }" from ${ base }: ${ error.message }`
		);
	}
}

const plugins = [
	postcssImport( { resolve: resolveImport } ),
	...presetPlugins.filter(
		( plugin ) => plugin.postcssPlugin !== 'postcss-import'
	),
];

if ( isProduction ) {
	// Mirrors the production branch of `@wordpress/scripts`'s fallback config.
	plugins.push(
		require( 'cssnano' )( {
			preset: [ 'default', { discardComments: { removeAll: true } } ],
		} )
	);
}

module.exports = { plugins };
