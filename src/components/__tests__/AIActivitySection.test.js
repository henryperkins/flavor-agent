jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import AIActivitySection from '../AIActivitySection';

const { getContainer, getRoot } = setupReactTest();

describe( 'AIActivitySection', () => {
	test( 'renders ordered undo labels and only shows undo buttons for available rows', () => {
		const onUndo = jest.fn();

		act( () => {
			getRoot().render(
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
							request: {
								ai: {
									backendLabel: 'Azure OpenAI responses',
									model: 'gpt-5.3-chat',
									pathLabel:
										'Azure OpenAI via Settings > Flavor Agent',
								},
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
							request: {
								ai: {
									backendLabel: 'WordPress AI Client',
									model: 'provider-managed',
									pathLabel:
										'WordPress AI Client via Settings > Connectors',
									selectedProviderLabel: 'Azure OpenAI',
									usedFallback: true,
								},
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

		expect( getContainer().textContent ).toContain( 'Undo available' );
		expect( getContainer().textContent ).toContain( 'Undo blocked' );
		expect( getContainer().textContent ).toContain( 'Undo unavailable' );
		expect( getContainer().textContent ).toContain( 'Undo pending sync' );
		expect( getContainer().textContent ).toContain(
			'Undo blocked by newer AI actions.'
		);
		expect( getContainer().textContent ).toContain(
			'Undo unavailable because content drifted.'
		);
		expect( getContainer().textContent ).toContain(
			'Activity audit sync pending.'
		);
		expect( getContainer().textContent ).toContain(
			'Azure OpenAI responses · gpt-5.3-chat'
		);
		expect( getContainer().textContent ).toContain(
			'Azure OpenAI via Settings > Flavor Agent'
		);
		expect( getContainer().textContent ).toContain(
			'WordPress AI Client via Settings > Connectors'
		);
		expect( getContainer().textContent ).toContain(
			'Fallback from selected Azure OpenAI.'
		);
		expect( getContainer().textContent ).toContain( 'Global Styles action' );
		expect( getContainer().textContent ).toContain(
			'Style Book action · Paragraph'
		);
		expect( getContainer().querySelectorAll( 'button' ) ).toHaveLength( 1 );

		getContainer().querySelector( 'button' ).click();

		expect( onUndo ).toHaveBeenCalledWith( 'activity-1' );
	} );
} );
