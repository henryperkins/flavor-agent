jest.mock( '@wordpress/components', () =>
	require( '../../test-utils/wp-components' ).mockWpComponents()
);

// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { setupReactTest } = require( '../../test-utils/setup-react-test' );

import AIActivitySection from '../AIActivitySection';

const { getContainer, getRoot } = setupReactTest();

describe( 'AIActivitySection', () => {
	test( 'suppresses the section by default when there is no activity history yet', () => {
		act( () => {
			getRoot().render(
				<AIActivitySection
					description="Undo is only available while the current state still matches the applied AI change."
					entries={ [] }
					onUndo={ jest.fn() }
				/>
			);
		} );

		expect( getContainer().textContent ).toBe( '' );
		expect(
			getContainer().querySelector( '.flavor-agent-panel__group' )
		).toBeNull();
	} );

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
		expect( getContainer().textContent ).toContain(
			'Global Styles action'
		);
		expect( getContainer().textContent ).toContain(
			'Style Book action · Paragraph'
		);
		const undoButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Undo' );

		expect( undoButton ).toBeTruthy();

		undoButton.click();

		expect( onUndo ).toHaveBeenCalledWith( 'activity-1' );
	} );

	test( 'hides undo controls when an undo handler is not provided', () => {
		act( () => {
			getRoot().render(
				<AIActivitySection
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
					] }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Undo available' );
		expect(
			Array.from( getContainer().querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Undo'
			)
		).toBeUndefined();
	} );

	test( 'renders request diagnostics as review rows instead of undo failures', () => {
		act( () => {
			getRoot().render(
				<AIActivitySection
					entries={ [
						{
							id: 'diagnostic-1',
							type: 'request_diagnostic',
							suggestion:
								'No block-lane suggestions returned',
							surface: 'block',
							target: {
								blockName: 'core/paragraph',
							},
							request: {
								ai: {
									backendLabel: 'Azure OpenAI responses',
									model: 'gpt-5.4-mini',
								},
							},
							diagnostic: {
								detailLines: [
									'Flavor Agent returned 1 style, but none in the block lane.',
									'The block context exposed no mapped inspector panels for this request.',
								],
							},
							undo: {
								canUndo: false,
								status: 'failed',
								error: null,
							},
						},
					] }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain(
			'No block-lane suggestions returned'
		);
		expect( getContainer().textContent ).toContain(
			'Block request diagnostic · paragraph'
		);
		expect( getContainer().textContent ).toContain(
			'Azure OpenAI responses · gpt-5.4-mini'
		);
		expect( getContainer().textContent ).toContain( 'Review' );
		expect( getContainer().textContent ).toContain(
			'Flavor Agent returned 1 style, but none in the block lane.'
		);
		expect( getContainer().textContent ).not.toContain(
			'Undo unavailable'
		);
	} );

	test( 'supports collapsed history and show-more overflow behavior', () => {
		const entries = Array.from( { length: 5 }, ( _, index ) => ( {
			id: `activity-${ index + 1 }`,
			suggestion: `Suggestion ${ index + 1 }`,
			surface: 'block',
			target: {
				blockName: 'core/paragraph',
			},
			undo: {
				canUndo: false,
				status: 'failed',
			},
		} ) );

		act( () => {
			getRoot().render(
				<AIActivitySection
					entries={ entries }
					initialOpen={ false }
					maxVisible={ 2 }
					onUndo={ jest.fn() }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Recent AI Actions' );
		expect( getContainer().textContent ).not.toContain( 'Suggestion 1' );

		const toggle = getContainer().querySelector(
			'.flavor-agent-activity-section__toggle'
		);

		act( () => {
			toggle.click();
		} );

		expect( getContainer().textContent ).toContain( 'Suggestion 1' );
		expect( getContainer().textContent ).not.toContain( 'Suggestion 3' );
		expect( getContainer().textContent ).toContain( 'Show 3 more' );

		const showMoreButton = Array.from(
			getContainer().querySelectorAll( 'button' )
		).find( ( button ) => button.textContent.includes( 'Show 3 more' ) );

		act( () => {
			showMoreButton.click();
		} );

		expect( getContainer().textContent ).toContain( 'Suggestion 5' );
	} );

	test( 'keeps the user-selected open state when new entries are appended for the same surface', () => {
		const initialEntries = [
			{
				id: 'activity-1',
				suggestion: 'Suggestion 1',
				surface: 'block',
				target: {
					blockName: 'core/paragraph',
				},
				undo: {
					canUndo: false,
					status: 'failed',
				},
			},
		];

		act( () => {
			getRoot().render(
				<AIActivitySection
					entries={ initialEntries }
					initialOpen={ false }
					resetKey="block-1"
					onUndo={ jest.fn() }
				/>
			);
		} );

		const toggle = getContainer().querySelector(
			'.flavor-agent-activity-section__toggle'
		);

		act( () => {
			toggle.click();
		} );

		expect( getContainer().textContent ).toContain( 'Suggestion 1' );

		act( () => {
			getRoot().render(
				<AIActivitySection
					entries={ [
						{
							id: 'activity-2',
							suggestion: 'Suggestion 2',
							surface: 'block',
							target: {
								blockName: 'core/paragraph',
							},
							undo: {
								canUndo: false,
								status: 'failed',
							},
						},
						...initialEntries,
					] }
					initialOpen={ false }
					resetKey="block-1"
					onUndo={ jest.fn() }
				/>
			);
		} );

		expect( getContainer().textContent ).toContain( 'Suggestion 2' );
		expect( getContainer().textContent ).toContain( 'Suggestion 1' );
	} );

	test( 'resets the open state when the reset key changes', () => {
		const entries = [
			{
				id: 'activity-1',
				suggestion: 'Suggestion 1',
				surface: 'block',
				target: {
					blockName: 'core/paragraph',
				},
				undo: {
					canUndo: false,
					status: 'failed',
				},
			},
		];

		act( () => {
			getRoot().render(
				<AIActivitySection
					entries={ entries }
					initialOpen={ false }
					resetKey="block-1"
					onUndo={ jest.fn() }
				/>
			);
		} );

		const toggle = getContainer().querySelector(
			'.flavor-agent-activity-section__toggle'
		);

		act( () => {
			toggle.click();
		} );

		expect( getContainer().textContent ).toContain( 'Suggestion 1' );

		act( () => {
			getRoot().render(
				<AIActivitySection
					entries={ entries }
					initialOpen={ false }
					resetKey="block-2"
					onUndo={ jest.fn() }
				/>
			);
		} );

		expect( getContainer().textContent ).not.toContain( 'Suggestion 1' );
	} );
} );
