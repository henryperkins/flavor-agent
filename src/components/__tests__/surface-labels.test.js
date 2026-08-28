jest.mock( '@wordpress/i18n', () =>
	require( '../../test-utils/i18n-mock' ).createI18nMock()
);

import {
	ADVISORY_ONLY_LABEL,
	APPLY_NOW_LABEL,
	CURRENT_STATUS_LABEL,
	EXECUTABLE_LABEL,
	getTonePillClassName,
	getToneLabel,
	MANUAL_IDEAS_LABEL,
	REFRESH_ACTION_LABEL,
	REVIEW_LANE_LABEL,
	REVIEW_SECTION_TITLE,
	STALE_STATUS_LABEL,
	SURFACE_TONES,
} from '../surface-labels';

const i18n = require( '@wordpress/i18n' );

describe( 'surface-labels', () => {
	test( 'every tone token resolves to a pill class and a label', () => {
		for ( const tone of Object.values( SURFACE_TONES ) ) {
			expect( getToneLabel( tone ) ).not.toBe( '' );
		}

		// `executable` is a label-only tone: AIReviewSection renders it in a
		// plain pill, so it intentionally has no modifier class.
		const styledTones = Object.values( SURFACE_TONES ).filter(
			( tone ) => tone !== SURFACE_TONES.EXECUTABLE
		);

		for ( const tone of styledTones ) {
			expect( getTonePillClassName( tone ) ).toMatch(
				/^flavor-agent-pill--/
			);
		}
	} );

	test( 'pill class is keyed off the token, never the rendered label', () => {
		// This is the regression guard: styling must not depend on display text,
		// or translating a label silently drops the state colour.
		expect( getTonePillClassName( SURFACE_TONES.APPLY ) ).toBe(
			'flavor-agent-pill--apply'
		);
		expect( getTonePillClassName( APPLY_NOW_LABEL ) ).toBe( '' );
		expect( getTonePillClassName( 'Apply now' ) ).toBe( '' );
		expect( getTonePillClassName( 'apply now' ) ).toBe( '' );
		expect( getTonePillClassName( REVIEW_LANE_LABEL ) ).toBe( '' );
		expect( getTonePillClassName( STALE_STATUS_LABEL ) ).toBe( '' );
	} );

	test( 'unknown tones degrade to empty strings rather than throwing', () => {
		expect( getTonePillClassName() ).toBe( '' );
		expect( getTonePillClassName( 'not-a-tone' ) ).toBe( '' );
		expect( getToneLabel() ).toBe( '' );
		expect( getToneLabel( 'not-a-tone' ) ).toBe( '' );
	} );

	test( 'advisory and manual share one pill treatment', () => {
		expect( getTonePillClassName( SURFACE_TONES.ADVISORY ) ).toBe(
			getTonePillClassName( SURFACE_TONES.MANUAL )
		);
	} );

	test( 'every exported label goes through the text domain', () => {
		const translated = [
			APPLY_NOW_LABEL,
			MANUAL_IDEAS_LABEL,
			REVIEW_LANE_LABEL,
			REVIEW_SECTION_TITLE,
			ADVISORY_ONLY_LABEL,
			EXECUTABLE_LABEL,
			CURRENT_STATUS_LABEL,
			STALE_STATUS_LABEL,
			REFRESH_ACTION_LABEL,
		];

		for ( const label of translated ) {
			expect( i18n.__ ).toHaveBeenCalledWith( label, 'flavor-agent' );
		}
	} );
} );
