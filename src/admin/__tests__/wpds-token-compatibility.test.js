/* eslint-disable @wordpress/no-unknown-ds-tokens */
const fs = require( 'fs' );
const path = require( 'path' );

const PACKAGE_JSON = require( '../../../package.json' );

const SRC_DIR = path.join( __dirname, '../../' );
const LEGACY_WPDS_COLOR_TOKEN =
	/--wpds-color-(?:(?:bg|fg)-[a-z0-9-]+|stroke-focus-brand)/g;

function listCssFiles( directory ) {
	return fs
		.readdirSync( directory, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const entryPath = path.join( directory, entry.name );

			if ( entry.isDirectory() ) {
				return listCssFiles( entryPath );
			}

			return entry.name.endsWith( '.css' ) ? [ entryPath ] : [];
		} );
}

function removeComments( css ) {
	return css.replace( /\/\*[\s\S]*?\*\//g, '' );
}

function toCurrentToken( token ) {
	return token
		.replace( '--wpds-color-bg-', '--wpds-color-background-' )
		.replace( '--wpds-color-fg-', '--wpds-color-foreground-' )
		.replace(
			'--wpds-color-stroke-focus-brand',
			'--wpds-color-stroke-focus'
		);
}

function collectUnpairedLegacyTokens( css, file ) {
	const unpairedLegacyTokens = [];
	const declarations = removeComments( css )
		.split( ';' )
		.map( ( declaration ) => declaration.trim() )
		.filter( Boolean );

	for ( const declaration of declarations ) {
		const legacyTokens = [
			...new Set( declaration.match( LEGACY_WPDS_COLOR_TOKEN ) || [] ),
		];

		for ( const legacyToken of legacyTokens ) {
			const currentToken = toCurrentToken( legacyToken );
			// Token names only contain [a-z0-9-]; the lookahead stops a longer
			// token (…-surface-neutral) from satisfying a shorter pairing (…-surface).
			const pairedCurrentToken = new RegExp(
				currentToken + '(?![a-z0-9-])'
			);

			if ( ! pairedCurrentToken.test( declaration ) ) {
				unpairedLegacyTokens.push( {
					declaration: declaration.replace( /\s+/g, ' ' ),
					file,
					legacyToken,
					currentToken,
				} );
			}
		}
	}

	return unpairedLegacyTokens;
}

describe( 'WPDS token compatibility', () => {
	test( 'declares the directly imported theme package as a dependency', () => {
		expect( PACKAGE_JSON.dependencies ).toEqual(
			expect.objectContaining( {
				'@wordpress/theme': expect.any( String ),
			} )
		);
	} );

	test( 'imports the design tokens file the theme package publicly exports', () => {
		const themePackageJsonPath = require.resolve(
			'@wordpress/theme/package.json'
		);
		const themeExportTarget = require( '@wordpress/theme/package.json' )
			.exports[ './design-tokens.css' ];
		const exportedFile = path.resolve(
			path.dirname( themePackageJsonPath ),
			themeExportTarget
		);

		const bridgePath = path.join( SRC_DIR, 'admin', 'wpds-runtime.css' );
		const importTarget = fs
			.readFileSync( bridgePath, 'utf8' )
			.match( /@import\s+"([^"]+design-tokens\.css)"/ )?.[ 1 ];
		const importedFile = importTarget
			? path.resolve( path.dirname( bridgePath ), importTarget )
			: null;

		expect( importedFile ).toBe( exportedFile );
		expect( fs.existsSync( exportedFile ) ).toBe( true );
	} );

	test( 'pairs legacy color tokens with the current names', () => {
		const unpairedLegacyTokens = [];

		for ( const filePath of listCssFiles( SRC_DIR ) ) {
			const relativePath = path.relative(
				path.join( __dirname, '../../../' ),
				filePath
			);

			unpairedLegacyTokens.push(
				...collectUnpairedLegacyTokens(
					fs.readFileSync( filePath, 'utf8' ),
					relativePath
				)
			);
		}

		expect( unpairedLegacyTokens ).toEqual( [] );
	} );

	test( 'flags a legacy token whose current name only appears as a longer token prefix', () => {
		const css =
			'.a { background: var(--wpds-color-background-surface-neutral, var(--wpds-color-bg-surface, #fff)); }';

		expect( collectUnpairedLegacyTokens( css, 'fixture.css' ) ).toEqual( [
			{
				declaration: expect.stringContaining(
					'--wpds-color-bg-surface'
				),
				file: 'fixture.css',
				legacyToken: '--wpds-color-bg-surface',
				currentToken: '--wpds-color-background-surface',
			},
		] );
	} );
} );
