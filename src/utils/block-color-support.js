/**
 * Text/background support defaults to enabled once a block declares color
 * support. An explicit false disables the individual facet.
 *
 * @param {Object} blockSupports Registered block supports.
 * @param {string} facet         Text or background color facet.
 * @return {boolean} Whether the block supports the color facet.
 */
export function hasBlockColorSupport( blockSupports, facet ) {
	const color = blockSupports?.color;
	return Boolean( color ) && color[ facet ] !== false;
}
