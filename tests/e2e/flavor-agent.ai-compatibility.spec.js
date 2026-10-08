const { test, expect } = require( './test-fixtures' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

test( '@wp70-site-editor AI 1.4.0 enables native abilities without the retired master option', async () => {
	const result = runWpCli( getWp70HarnessConfig(), [
		'eval',
		`echo wp_json_encode( [
	'aiVersion' => defined( 'WPAI_VERSION' ) ? WPAI_VERSION : '',
	'masterOptionAbsent' => '__missing__' === get_option( 'wpai_features_enabled', '__missing__' ),
	'featureEnabled' => FlavorAgent\\AI\\FeatureBootstrap::recommendation_feature_enabled(),
	'abilityRegistered' => (bool) wp_get_ability( 'flavor-agent/recommend-block' ),
] );`,
	] );
	const state = JSON.parse( result.stdout.trim() );
	expect( state.aiVersion ).not.toBe( '' );
	test.skip(
		Number( state.aiVersion.split( '.' )[ 0 ] ) < 1 ||
			( state.aiVersion.startsWith( '1.' ) &&
				Number( state.aiVersion.split( '.' )[ 1 ] ) < 4 ),
		'This regression targets the master toggle retired in AI 1.4.0.'
	);
	expect( state ).toMatchObject( {
		masterOptionAbsent: true,
		featureEnabled: true,
		abilityRegistered: true,
	} );
} );
