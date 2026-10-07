import * as exporter from '../fixture-export';

function candidate() {
	return {
		schemaVersion: 'recommendation-fixture-export-v1',
		provenance: {
			kind: 'synthetic_fixture',
			implementationSha: 'a'.repeat( 40 ),
			publicVersions: {
				plugin: '0.1.1',
				wordpress: '7.1.3',
				ranking: 'contextual-ranking-v1',
				validationVocabulary: 'validation-reasons-v1',
				report: 'governance-learning-report-v1',
			},
			config: { surfaces: [ 'block' ], rankingMode: 'static' },
		},
		sample: {
			rowLimit: 500,
			sampleSize: 0,
			exportedRowCount: 0,
			excludedRowCount: 0,
			truncated: false,
			shownSetCount: 0,
			shownSuggestionCount: 0,
			unlinkedApplyCount: 0,
			missingIdentityCount: 0,
		},
		rows: [],
		coverage: {
			surfaces: [ 'block' ],
			missingShownLinks: 0,
			missingApplyLinks: 0,
			unverifiedCoverageCount: 0,
		},
		metrics: [
			{
				name: 'reviewSelectionRate',
				numerator: 0,
				denominator: 0,
				coverage: 'no_denominator',
			},
		],
	};
}

describe( 'local fixture review bundle', () => {
	test( 'accepts only the literal closed candidate and keeps custody outside it', async () => {
		expect( typeof exporter.buildFixtureReviewBundle ).toBe( 'function' );
		const source = candidate();
		const localReview = {
			selection: {
				dateFrom: '2026-10-01',
				dateTo: '2026-10-07',
				surfaces: [ 'block' ],
				rowLimit: 500,
			},
			custodian: 'Local Reviewer',
			site: 'private.example',
			collectionScope: 'Approved bounded local sample',
		};
		const bundle = await exporter.buildFixtureReviewBundle(
			source,
			localReview,
			async () => 'b'.repeat( 64 )
		);
		expect( bundle.candidate ).toEqual( source );
		expect( JSON.stringify( bundle.candidate ) ).not.toContain(
			'private.example'
		);
		expect( bundle.reviewRecord ).toMatchObject( {
			custodian: 'Local Reviewer',
			site: 'private.example',
			collectionPeriod: { dateFrom: '2026-10-01', dateTo: '2026-10-07' },
			reviewerDecision: 'pending',
			candidateDigest: 'b'.repeat( 64 ),
			ordering: 'newest-first: created_at DESC, id DESC',
		} );
	} );

	test.each( [ 'schema', 'raw', 'nested', 'metric', 'nonfinite' ] )(
		'refuses unsafe response: %s',
		async ( field ) => {
			const source = candidate();
			if ( field === 'schema' ) {
				source.schemaVersion = 'unknown-v2';
			}
			if ( field === 'raw' ) {
				source.activity = { prompt: 'Private prompt' };
			}
			if ( field === 'nested' ) {
				source.provenance.publicVersions.secret = 'sk-private';
			}
			if ( field === 'metric' ) {
				source.metrics[ 0 ].name = 'dismissalRate';
			}
			if ( field === 'nonfinite' ) {
				source.metrics[ 0 ] = {
					name: 'reviewSelectionRate',
					numerator: 1,
					denominator: 1,
					value: Infinity,
					coverage: 'complete_sample',
				};
			}
			expect( exporter.validateFixtureCandidate( source ) ).toBe( false );
		}
	);

	test( 'requires bounded explicit selection rather than activity-feed filters', () => {
		const selection = {
			dateFrom: '2026-10-01',
			dateTo: '2026-10-07',
			surfaces: [ 'block' ],
			rowLimit: 500,
		};
		expect( exporter.validateFixtureSelection( selection ) ).toBe( true );
		expect(
			exporter.validateFixtureSelection( {
				...selection,
				dateFrom: '2026-10-07T12:00:01Z',
				dateTo: '2026-10-07T23:59:59Z',
			} )
		).toBe( true );
		for ( const invalid of [
			{ rowLimit: '500' },
			{ rowLimit: 1001 },
			{ surfaces: [] },
			{ surfaces: [ 'unknown' ] },
			{ dateFrom: '2026-02-30' },
			{ dateFrom: '2026-10-08' },
			{ rawPrompt: 'private' },
		] ) {
			expect(
				exporter.validateFixtureSelection( {
					...selection,
					...invalid,
				} )
			).toBe( false );
		}
	} );
} );
