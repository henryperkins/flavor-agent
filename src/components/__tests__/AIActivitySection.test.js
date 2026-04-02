jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		Button: ( { children, disabled, onClick } ) =>
			createElement(
				'button',
				{
					type: 'button',
					disabled,
					onClick,
				},
				children
			),
	};
} );

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import AIActivitySection from '../AIActivitySection';

let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => {
		root.unmount();
	} );
	container.remove();
} );

describe( 'AIActivitySection', () => {
	test( 'renders ordered undo labels and only shows undo buttons for available rows', () => {
		const onUndo = jest.fn();

		act( () => {
			root.render(
				<AIActivitySection
					onUndo={ onUndo }
					entries={ [
						{
							id: 'activity-1',
							suggestion: 'Refresh content',
							surface: 'block',
							target: {
								blockName: 'core/paragraph',
							},
							undo: {
								canUndo: true,
								status: 'available',
							},
						},
						{
							id: 'activity-2',
							suggestion: 'Tighten spacing',
							surface: 'block',
							target: {
								blockName: 'core/paragraph',
							},
							undo: {
								canUndo: false,
								status: 'blocked',
								error: 'Undo blocked by newer AI actions.',
							},
						},
						{
							id: 'activity-3',
							suggestion: 'Legacy insert',
							surface: 'template',
							target: {
								templateRef: 'theme//home',
							},
							undo: {
								canUndo: false,
								status: 'failed',
								error: 'Undo unavailable because content drifted.',
							},
						},
						{
							id: 'activity-4',
							suggestion: 'Undo header cleanup',
							surface: 'template',
							target: {
								templateRef: 'theme//home',
							},
							undo: {
								canUndo: false,
								status: 'undone',
								error: null,
							},
							persistence: {
								status: 'local',
								syncType: 'undo',
							},
						},
						{
							id: 'activity-5',
							suggestion: 'Darken the site canvas',
							surface: 'global-styles',
							target: {
								globalStylesId: '17',
							},
							undo: {
								canUndo: false,
								status: 'failed',
								error: 'Global Styles changed after apply.',
							},
						},
						{
							id: 'activity-6',
							suggestion: 'Refine paragraph spacing',
							surface: 'style-book',
							target: {
								globalStylesId: '17',
								blockName: 'core/paragraph',
								blockTitle: 'Paragraph',
							},
							undo: {
								canUndo: false,
								status: 'failed',
								error: 'Style Book block styles changed after apply.',
							},
						},
					] }
				/>
			);
		} );

		expect( container.textContent ).toContain( 'Undo available' );
		expect( container.textContent ).toContain( 'Undo blocked' );
		expect( container.textContent ).toContain( 'Undo unavailable' );
		expect( container.textContent ).toContain( 'Undo pending sync' );
		expect( container.textContent ).toContain(
			'Undo blocked by newer AI actions.'
		);
		expect( container.textContent ).toContain(
			'Undo unavailable because content drifted.'
		);
		expect( container.textContent ).toContain(
			'Activity audit sync pending.'
		);
		expect( container.textContent ).toContain( 'Global Styles action' );
		expect( container.textContent ).toContain(
			'Style Book action · Paragraph'
		);
		expect( container.querySelectorAll( 'button' ) ).toHaveLength( 1 );

		container.querySelector( 'button' ).click();

		expect( onUndo ).toHaveBeenCalledWith( 'activity-1' );
	} );
} );
