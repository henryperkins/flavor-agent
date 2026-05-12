import {
	getDocsGroundingWarningMessage,
	normalizeDocsGroundingWarning,
} from '../docs-grounding-warning';

describe( 'normalizeDocsGroundingWarning', () => {
	test( 'returns null when grounding is absent or unavailable', () => {
		expect( normalizeDocsGroundingWarning( null ) ).toBeNull();
		expect(
			normalizeDocsGroundingWarning( { status: 'unavailable' } )
		).toBeNull();
	} );

	test( 'returns null for current or unknown coverage on grounded responses', () => {
		expect(
			normalizeDocsGroundingWarning( {
				status: 'grounded',
				coverage: { status: 'current' },
			} )
		).toBeNull();
		expect(
			normalizeDocsGroundingWarning( {
				status: 'grounded',
				coverage: { status: 'unknown' },
			} )
		).toBeNull();
	} );

	test( 'normalizes coverage-only warnings from grounded responses', () => {
		expect(
			normalizeDocsGroundingWarning( {
				status: 'grounded',
				message: 'Grounded with coverage caveats.',
				source: 'developer-docs',
				checkedAt: '2026-05-12T10:00:00Z',
				coverage: {
					status: 'missing-current-release-cycle',
					message: 'Current release-cycle docs were not confirmed.',
				},
			} )
		).toEqual( {
			status: 'grounded',
			message: 'Grounded with coverage caveats.',
			coverageStatus: 'missing-current-release-cycle',
			coverageMessage: 'Current release-cycle docs were not confirmed.',
			source: 'developer-docs',
			checkedAt: '2026-05-12T10:00:00Z',
		} );
	} );

	test( 'normalizes stale and degraded top-level warnings', () => {
		expect(
			normalizeDocsGroundingWarning( {
				status: 'stale',
				message: 'Cached docs are stale.',
				coverage: { status: 'current' },
			} )
		).toMatchObject( {
			status: 'stale',
			message: 'Cached docs are stale.',
			coverageStatus: 'current',
		} );
		expect(
			normalizeDocsGroundingWarning( { status: 'degraded' } )
		).toMatchObject( {
			status: 'degraded',
		} );
	} );
} );

describe( 'getDocsGroundingWarningMessage', () => {
	test( 'prefers current-release-cycle coverage copy', () => {
		expect(
			getDocsGroundingWarningMessage( {
				status: 'grounded',
				coverageStatus: 'missing-current-release-cycle',
			} )
		).toBe(
			'Developer Docs grounding is trusted, but current release-cycle sources have not been confirmed. Review current WordPress docs before applying.'
		);
	} );

	test( 'falls back to concise stale and degraded copy', () => {
		expect( getDocsGroundingWarningMessage( { status: 'stale' } ) ).toBe(
			'Developer Docs grounding is stale. Review current WordPress docs before applying.'
		);
		expect( getDocsGroundingWarningMessage( { status: 'degraded' } ) ).toBe(
			'Developer Docs grounding is incomplete. Review current WordPress docs before applying.'
		);
	} );
} );
