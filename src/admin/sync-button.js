import './wpds-runtime.css';
import '../tokens.css';
import './brand.css';
import './settings.css';

( function () {
	const button = document.getElementById( 'flavor-agent-sync-button' );
	const spinner = document.getElementById( 'flavor-agent-sync-spinner' );
	const status = document.getElementById( 'flavor-agent-sync-status' );
	const noticeRoot = document.getElementById( 'flavor-agent-sync-notice' );

	if ( ! button || ! spinner || ! status || ! noticeRoot ) {
		return;
	}

	const renderNotice = ( type, message ) => {
		noticeRoot.innerHTML = '';

		if ( ! message ) {
			return;
		}

		const notice = document.createElement( 'div' );
		const paragraph = document.createElement( 'p' );

		notice.className = `notice notice-${ type } inline`;
		paragraph.textContent = message;
		notice.appendChild( paragraph );
		noticeRoot.appendChild( notice );
	};

	const setBusy = ( isBusy ) => {
		button.disabled = isBusy;
		spinner.classList.toggle( 'is-active', isBusy );
	};

	button.addEventListener( 'click', async function () {
		setBusy( true );
		status.textContent = 'Syncing...';
		renderNotice( '', '' );

		try {
			const response = await fetch(
				window.flavorAgentAdmin.restUrl +
					'flavor-agent/v1/sync-patterns',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': window.flavorAgentAdmin.nonce,
					},
				}
			);

			const data = await response.json();

			if ( ! response.ok ) {
				throw new Error( data.message || 'Sync failed.' );
			}

			renderNotice(
				'success',
				`Synced ${ data.indexed } patterns, removed ${ data.removed }. Status: ${ data.status }.`
			);
			status.textContent = '';
		} catch ( err ) {
			renderNotice( 'error', err.message || 'Sync failed.' );
			status.textContent = '';
		} finally {
			setBusy( false );
		}
	} );
} )();
