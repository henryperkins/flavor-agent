import { getInserterBadgeState } from '../inserter-badge-state';

describe( 'getInserterBadgeState', () => {
	test( 'returns hidden for idle state with no recommendations', () => {
		expect(
			getInserterBadgeState( {
				status: 'idle',
				recommendations: [],
				badge: null,
				error: null,
			} )
		).toEqual( {
			status: 'hidden',
			count: 0,
			content: null,
			ariaLabel: null,
			className: null,
		} );
	} );

	test( 'returns hidden for ready state with zero recommendations', () => {
		expect(
			getInserterBadgeState( {
				status: 'ready',
				recommendations: [],
			} )
		).toMatchObject( {
			status: 'hidden',
			count: 0,
		} );
	} );

	test( 'returns loading state with the agreed accessibility copy', () => {
		expect(
			getInserterBadgeState( {
				status: 'loading',
				recommendations: [],
				error: 'Old error',
			} )
		).toEqual( {
			status: 'loading',
			count: 0,
			content: null,
			ariaLabel: 'Finding pattern recommendations',
			className:
				'flavor-agent-inserter-badge flavor-agent-inserter-badge--loading',
		} );
	} );

	test( 'returns ready state with the high-confidence badge reason', () => {
		expect(
			getInserterBadgeState( {
				status: 'ready',
				recommendations: [ { name: 'theme/hero' } ],
				badge: 'High-confidence recommendation',
			} )
		).toEqual( {
			status: 'ready',
			count: 1,
			content: '1',
			ariaLabel:
				'1 pattern recommendation available. High-confidence recommendation',
			className:
				'flavor-agent-inserter-badge flavor-agent-inserter-badge--ready',
		} );
	} );

	test( 'falls back to count copy when ready state has no badge reason', () => {
		expect(
			getInserterBadgeState( {
				status: 'ready',
				recommendations: [
					{ name: 'theme/hero' },
					{ name: 'theme/gallery' },
					{ name: 'theme/cta' },
				],
				badge: null,
			} )
		).toMatchObject( {
			status: 'ready',
			count: 3,
			content: '3',
			ariaLabel: '3 pattern recommendations available',
			className:
				'flavor-agent-inserter-badge flavor-agent-inserter-badge--ready',
		} );
	} );

	test( 'returns error state with the failure reason folded into the aria-label', () => {
		expect(
			getInserterBadgeState( {
				status: 'error',
				recommendations: [],
				error: 'Server failed',
			} )
		).toEqual( {
			status: 'error',
			count: 0,
			content: '!',
			ariaLabel: 'Pattern recommendation error: Server failed',
			className:
				'flavor-agent-inserter-badge flavor-agent-inserter-badge--error',
		} );
	} );
} );
