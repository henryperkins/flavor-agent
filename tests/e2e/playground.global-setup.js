const { request } = require( '@playwright/test' );
const { DEFAULT_GUTENBERG_VERSION } = require( '../../scripts/wp70-e2e' );

module.exports = async function verifyPlaygroundEditorRuntime( config ) {
	const baseURL = config.projects[ 0 ]?.use?.baseURL;

	if ( typeof baseURL !== 'string' || baseURL.length === 0 ) {
		throw new Error(
			'Playground global setup could not resolve the configured baseURL.'
		);
	}

	const api = await request.newContext( { baseURL } );

	try {
		const response = await api.get(
			`/wp-json/flavor-agent-e2e/v1/editor-readiness?gutenberg=${ encodeURIComponent(
				DEFAULT_GUTENBERG_VERSION
			) }`,
			{ timeout: 30_000 }
		);
		const body = await response.text();

		if ( ! response.ok() ) {
			throw new Error(
				`Playground editor readiness returned HTTP ${ response.status() }: ${ body.slice(
					0,
					500
				) }`
			);
		}

		let payload;

		try {
			payload = JSON.parse( body );
		} catch {
			throw new Error(
				`Playground editor readiness returned invalid JSON: ${ body.slice(
					0,
					500
				) }`
			);
		}

		if (
			payload.status !== 'ready' ||
			payload.gutenberg_version !== DEFAULT_GUTENBERG_VERSION
		) {
			throw new Error(
				`Playground editor readiness did not confirm active Gutenberg ${ DEFAULT_GUTENBERG_VERSION }: ${ body.slice(
					0,
					500
				) }`
			);
		}
	} finally {
		await api.dispose();
	}
};
