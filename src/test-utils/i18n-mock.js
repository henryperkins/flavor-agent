/* global jest */

/**
 * Shared `@wordpress/i18n` test double.
 *
 * Suites used to hand-roll a partial mock each, so the first module to reach
 * for a function the local mock happened to omit (`_n`, `_x`, …) failed with
 * "is not a function" in tests that had nothing to do with the change. One
 * factory keeps the surface complete and consistent.
 *
 * Behavior matches the untranslated path: every string passes through as its
 * source text, and `sprintf` resolves both positional (`%1$s`) and sequential
 * (`%s`) placeholders.
 *
 * Usage:
 *   jest.mock( '@wordpress/i18n', () =>
 *       require( '../../test-utils/i18n-mock' ).createI18nMock()
 *   );
 */

function formatTemplate( template, values ) {
	let sequentialIndex = 0;

	return String( template )
		.replace( /%(\d+)\$[sd]/g, ( _match, position ) => {
			const value = values[ Number( position ) - 1 ];

			return value === undefined ? '' : String( value );
		} )
		.replace( /%[sd]/g, () => {
			const value = values[ sequentialIndex ];

			sequentialIndex += 1;

			return value === undefined ? '' : String( value );
		} );
}

/**
 * Build a complete `@wordpress/i18n` mock module.
 *
 * @param {Object} [overrides] Members to replace on the returned module.
 * @return {Object} Mock module.
 */
function createI18nMock( overrides = {} ) {
	return {
		__: jest.fn( ( text ) => text ),
		_x: jest.fn( ( text ) => text ),
		_n: jest.fn( ( single, plural, count ) =>
			Number( count ) === 1 ? single : plural
		),
		_nx: jest.fn( ( single, plural, count ) =>
			Number( count ) === 1 ? single : plural
		),
		sprintf: jest.fn( ( template, ...values ) =>
			formatTemplate( template, values )
		),
		isRTL: jest.fn( () => false ),
		setLocaleData: jest.fn(),
		getLocaleData: jest.fn( () => ( {} ) ),
		hasTranslation: jest.fn( () => false ),
		subscribe: jest.fn( () => () => {} ),
		...overrides,
	};
}

module.exports = { createI18nMock, formatTemplate };
