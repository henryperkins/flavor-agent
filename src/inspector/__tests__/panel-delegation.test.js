import {
	DELEGATED_SETTINGS_PANELS,
	DELEGATED_STYLE_PANELS,
	isDelegatedSettingsPanel,
	isDelegatedStylePanel,
} from '../panel-delegation';

describe( 'panel delegation constants', () => {
	test( 'DELEGATED_STYLE_PANELS contains all sub-panel style groups', () => {
		expect( DELEGATED_STYLE_PANELS ).toEqual(
			new Set( [
				'color',
				'typography',
				'dimensions',
				'border',
				'filter',
				'background',
			] )
		);
	} );

	test( 'DELEGATED_SETTINGS_PANELS contains all sub-panel settings groups', () => {
		expect( DELEGATED_SETTINGS_PANELS ).toEqual(
			new Set( [ 'position', 'advanced', 'bindings', 'list' ] )
		);
	} );

	test( 'isDelegatedStylePanel returns true for delegated panels', () => {
		expect( isDelegatedStylePanel( 'color' ) ).toBe( true );
		expect( isDelegatedStylePanel( 'filter' ) ).toBe( true );
		expect( isDelegatedStylePanel( 'background' ) ).toBe( true );
	} );

	test( 'isDelegatedStylePanel returns false for non-delegated panels', () => {
		expect( isDelegatedStylePanel( 'general' ) ).toBe( false );
		expect( isDelegatedStylePanel( 'shadow' ) ).toBe( false );
		expect( isDelegatedStylePanel( 'effects' ) ).toBe( false );
	} );

	test( 'isDelegatedSettingsPanel returns true for delegated panels', () => {
		expect( isDelegatedSettingsPanel( 'position' ) ).toBe( true );
		expect( isDelegatedSettingsPanel( 'advanced' ) ).toBe( true );
		expect( isDelegatedSettingsPanel( 'bindings' ) ).toBe( true );
		expect( isDelegatedSettingsPanel( 'list' ) ).toBe( true );
	} );

	test( 'isDelegatedSettingsPanel returns false for non-delegated panels', () => {
		expect( isDelegatedSettingsPanel( 'general' ) ).toBe( false );
		expect( isDelegatedSettingsPanel( 'layout' ) ).toBe( false );
		expect( isDelegatedSettingsPanel( 'alignment' ) ).toBe( false );
	} );
} );
