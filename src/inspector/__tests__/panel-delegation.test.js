import {
	SETTINGS_PANEL_DELEGATIONS,
	STYLE_PANEL_DELEGATIONS,
} from '../panel-delegation';

describe( 'panel delegation constants', () => {
	test( 'shared style panel config drives the delegated style panel set', () => {
		expect( STYLE_PANEL_DELEGATIONS ).toEqual( [
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
				group: 'border',
				panel: 'shadow',
				label: 'AI shadow suggestions',
				title: 'Shadow',
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
} );
