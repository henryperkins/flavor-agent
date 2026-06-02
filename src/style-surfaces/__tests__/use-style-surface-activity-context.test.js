// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createElement } = require( '@wordpress/element' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import { useStyleSurfaceActivityContext } from '../use-style-surface-activity-context';

const { getRoot } = setupReactTest();

let captures = [];
let resolveRuntimeUndoState = null;
const registry = {
	select: jest.fn( () => ( {} ) ),
};

function Probe( props ) {
	captures.push( useStyleSurfaceActivityContext( props ) );
	return null;
}

function render( props ) {
	act( () => {
		getRoot().render( createElement( Probe, props ) );
	} );
}

function baseProps( overrides = {} ) {
	return {
		surface: 'global-styles',
		activityLog: [],
		globalStylesId: '17',
		blockName: '',
		registry,
		lastUndoneActivityId: null,
		runtimeDependency: null,
		resolveRuntimeUndoState,
		...overrides,
	};
}

beforeEach( () => {
	captures = [];
	registry.select.mockClear();
	resolveRuntimeUndoState = jest.fn( () => ( {
		canUndo: true,
		status: 'available',
		error: null,
	} ) );
} );

describe( 'useStyleSurfaceActivityContext', () => {
	test( 'returns stable activity entries when the live inputs are unchanged', () => {
		const activityLog = [
			{
				id: 'global-1',
				surface: 'global-styles',
				timestamp: '2026-06-02T00:00:00Z',
				target: { globalStylesId: '17' },
				after: { userConfig: { styles: {} } },
			},
		];
		const props = baseProps( { activityLog } );

		render( props );
		render( props );

		expect( captures[ 1 ].activityEntries ).toBe(
			captures[ 0 ].activityEntries
		);
		expect( captures[ 1 ].hasUndoSuccess ).toBe( false );
	} );

	test( 'filters entries to the active Global Styles entity and resolves undo state', () => {
		const activityLog = [
			{
				id: 'other-global',
				surface: 'global-styles',
				timestamp: '2026-06-02T00:00:00Z',
				target: { globalStylesId: '99' },
			},
			{
				id: 'matching-global',
				surface: 'global-styles',
				timestamp: '2026-06-02T00:01:00Z',
				target: { globalStylesId: '17' },
			},
			{
				id: 'style-book',
				surface: 'style-book',
				timestamp: '2026-06-02T00:02:00Z',
				target: {
					globalStylesId: '17',
					blockName: 'core/paragraph',
				},
			},
		];

		render( baseProps( { activityLog } ) );

		expect(
			captures[ 0 ].activityEntries.map( ( entry ) => entry.id )
		).toEqual( [ 'matching-global' ] );
		expect( resolveRuntimeUndoState ).toHaveBeenCalledTimes( 1 );
		expect( resolveRuntimeUndoState ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'matching-global' } ),
			registry
		);
		expect( captures[ 0 ].activityEntries[ 0 ].undo.status ).toBe(
			'available'
		);
	} );

	test( 'filters Style Book activity to the selected block target', () => {
		const activityLog = [
			{
				id: 'paragraph',
				surface: 'style-book',
				timestamp: '2026-06-02T00:00:00Z',
				target: {
					globalStylesId: '17',
					blockName: 'core/paragraph',
				},
			},
			{
				id: 'heading',
				surface: 'style-book',
				timestamp: '2026-06-02T00:01:00Z',
				target: {
					globalStylesId: '17',
					blockName: 'core/heading',
				},
			},
		];

		render(
			baseProps( {
				surface: 'style-book',
				activityLog,
				blockName: 'core/paragraph',
			} )
		);

		expect(
			captures[ 0 ].activityEntries.map( ( entry ) => entry.id )
		).toEqual( [ 'paragraph' ] );
	} );

	test( 'recomputes undo correctness when live style runtime changes', () => {
		const activityLog = [
			{
				id: 'global-1',
				surface: 'global-styles',
				timestamp: '2026-06-02T00:00:00Z',
				target: { globalStylesId: '17' },
				after: { userConfig: { styles: {} } },
			},
		];
		resolveRuntimeUndoState
			.mockReturnValueOnce( {
				canUndo: true,
				status: 'available',
				error: null,
			} )
			.mockReturnValueOnce( {
				canUndo: false,
				status: 'failed',
				error: 'Global Styles changed after Flavor Agent applied this suggestion.',
			} );

		render(
			baseProps( {
				activityLog,
				runtimeDependency: { version: 1 },
			} )
		);
		render(
			baseProps( {
				activityLog,
				runtimeDependency: { version: 2 },
			} )
		);

		expect( captures[ 1 ].activityEntries ).not.toBe(
			captures[ 0 ].activityEntries
		);
		expect( captures[ 0 ].activityEntries[ 0 ].undo.status ).toBe(
			'available'
		);
		expect( captures[ 1 ].activityEntries[ 0 ].undo.status ).toBe(
			'failed'
		);
		expect( captures[ 1 ].activityEntries[ 0 ].undo.canUndo ).toBe( false );
	} );

	test( 'reports undo success only for an undone entry in the active scope', () => {
		const activityLog = [
			{
				id: 'global-1',
				surface: 'global-styles',
				timestamp: '2026-06-02T00:00:00Z',
				target: { globalStylesId: '17' },
			},
		];
		resolveRuntimeUndoState.mockReturnValue( {
			canUndo: false,
			status: 'undone',
			error: null,
		} );

		render(
			baseProps( {
				activityLog,
				lastUndoneActivityId: 'global-1',
			} )
		);

		expect( captures[ 0 ].hasUndoSuccess ).toBe( true );
	} );
} );
