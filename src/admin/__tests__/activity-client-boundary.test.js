import {
	getGovernanceDetails,
	isPendingExternalApply,
} from '../activity-log-utils';

const governedEntry = {
	id: 'server-apply',
	type: 'apply_global_styles_suggestion',
	surface: 'global-styles',
	applyLane: 'server-executed',
	status: 'pending',
	apply: {
		status: 'pending',
		requestedBy: 7,
		decidedBy: 1,
		decidedByName: 'Site Admin',
		attestationStatus: 'recorded',
	},
};

describe( 'activity client boundary', () => {
	test.each( [
		[ 'missing lane', { applyLane: null } ],
		[ 'editor lane', { applyLane: 'editor-state' } ],
		[ 'non-apply type', { type: 'request_diagnostic' } ],
		[ 'wrong surface type', { type: 'apply_post_blocks_suggestion' } ],
	] )(
		'%s cannot present approval controls or governed audit claims',
		( _, overrides ) => {
			const entry = { ...governedEntry, ...overrides };
			expect( isPendingExternalApply( entry ) ).toBe( false );
			expect( getGovernanceDetails( entry ) ).toBeNull();
			expect(
				getGovernanceDetails( {
					...entry,
					status: 'undone',
					undo: { status: 'undone', attestationStatus: 'recorded' },
				} )
			).toBeNull();
		}
	);

	test( 'server apply identity retains approval controls', () => {
		expect( isPendingExternalApply( governedEntry ) ).toBe( true );
		expect( getGovernanceDetails( governedEntry ).requestedBy ).toBe( 7 );
	} );

	test( 'client-reported undo cannot display a recorded undo attestation', () => {
		const details = getGovernanceDetails( {
			...governedEntry,
			status: 'undone',
			undo: {
				status: 'undone',
				verification: 'client-reported',
				attestationStatus: 'recorded',
			},
		} );
		expect( details.undoAttestationMessage ).toBe( '' );
	} );
} );
