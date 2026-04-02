import {
	DELEGATED_SETTINGS_PANELS,
	DELEGATED_STYLE_PANELS,
	SETTINGS_PANEL_DELEGATIONS,
	STYLE_PANEL_DELEGATIONS,
	isDelegatedSettingsPanel,
	isDelegatedStylePanel,
} from '../panel-delegation';

describe( 'panel delegation constants', () => {
	test( 'shared style panel config drives the delegated style panel set', () => {
		expect( STYLE_PANEL_DELEGATIONS ).toEqual( [
			{
				group: 'color',
				panel: 'color',
				label: 'AI color suggestions',
				title: 'Color',
			},
			{
				group: 'typography',
				panel: 'typography',
				label: 'AI typography suggestions',
				title: 'Typography',
			},
			{
				group: 'dimensions',
				panel: 'dimensions',
				label: 'AI spacing suggestions',
				title: 'Dimensions',
			},
			{
				group: 'border',
				panel: 'border',
				label: 'AI border suggestions',
				title: 'Border',
			},
			{
				group: 'filter',
				panel: 'filter',
				label: 'AI filter suggestions',
				title: 'Filter',
			},
			{
				group: 'background',
				panel: 'background',
				label: 'AI background suggestions',
				title: 'Background',
			},
		] );
	} );

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

	test( 'shared settings panel config drives the delegated settings panel set', () => {
		expect( SETTINGS_PANEL_DELEGATIONS ).toEqual( [
			{
				group: 'position',
				panel: 'position',
				label: 'AI position suggestions',
				title: 'Position',
			},
			{
				group: 'advanced',
				panel: 'advanced',
				label: 'AI advanced suggestions',
				title: 'Advanced',
			},
			{
				group: 'bindings',
				panel: 'bindings',
				label: 'AI bindings suggestions',
				title: 'Bindings',
			},
			{
				group: 'list',
				panel: 'list',
				label: 'AI list view suggestions',
				title: 'List View',
			},
		] );
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
