( function () {
	if ( ! window.wp || ! window.wp.domReady ) {
		return;
	}

	window.wp.domReady( function () {
		var blocks = window.wp.blocks;
		var data = window.wp.data;

		if ( ! blocks || ! data ) {
			return;
		}

		// getBlockStyles is a core/blocks store SELECTOR, not an export of
		// wp.blocks — reading it off wp.blocks yields undefined and this
		// fixture would silently no-op.
		var styles =
			data.select( 'core/blocks' ).getBlockStyles( 'core/quote' ) || [];

		// Remove every registered style from core/quote so the editor sends an
		// empty styles list. Exercises the D4 path where a client-asserted []
		// must override the server's list rather than be discarded.
		styles.forEach( function ( style ) {
			blocks.unregisterBlockStyle( 'core/quote', style.name );
		} );
	} );
} )();
