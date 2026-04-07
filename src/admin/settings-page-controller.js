const PATTERN_STATUS_LABELS = {
	error: 'Error',
	indexing: 'Syncing',
	ready: 'Ready',
	stale: 'Refresh needed',
	uninitialized: 'Not synced',
};

const PATTERN_STATUS_TONES = {
	error: 'error',
	indexing: 'accent',
	ready: 'success',
	stale: 'warning',
	uninitialized: 'neutral',
};

const PATTERN_REASON_LABELS = {
	embedding_signature_changed:
		'Embedding provider, model, or vector size changed.',
	collection_name_changed:
		'Pattern index collection naming changed and needs a rebuild.',
	collection_missing:
		'Pattern index collection is missing and needs a rebuild.',
	collection_size_mismatch:
		'Pattern index collection vector size no longer matches the active embedding configuration.',
	qdrant_url_changed: 'Qdrant endpoint changed.',
	openai_endpoint_changed: 'Embedding endpoint changed.',
	pattern_registry_changed: 'Registered patterns changed.',
};

function getDefaultStorage() {
	try {
		return window.localStorage;
	} catch ( error ) {
		return null;
	}
}

function normalizeText( value, fallback = '' ) {
	if ( value === undefined || value === null ) {
		return fallback;
	}

	return String( value );
}

function readStoredSection( storage, key ) {
	if ( ! storage || ! key ) {
		return '';
	}

	try {
		return normalizeText( storage.getItem( key ), '' );
	} catch ( error ) {
		return '';
	}
}

function writeStoredSection( storage, key, value ) {
	if ( ! storage || ! key ) {
		return;
	}

	try {
		if ( value ) {
			storage.setItem( key, value );
			return;
		}

		storage.removeItem( key );
	} catch ( error ) {
		// Ignore storage write failures and keep the page functional.
	}
}

function getPatternSyncStatusLabel( status ) {
	return PATTERN_STATUS_LABELS[ status ] || normalizeText( status );
}

function getPatternSyncStatusTone( status ) {
	return PATTERN_STATUS_TONES[ status ] || 'neutral';
}

function getPatternSyncReasonLabel( reason ) {
	return PATTERN_REASON_LABELS[ reason ] || normalizeText( reason );
}

function getPatternOverviewStatus( runtimeState, hasPrerequisites ) {
	const state =
		runtimeState && typeof runtimeState === 'object' ? runtimeState : {};

	if ( ! hasPrerequisites ) {
		return {
			label: 'Needs embeddings & Qdrant',
			tone: 'warning',
		};
	}

	if ( state.last_error ) {
		return {
			label: 'Needs attention',
			tone: 'error',
		};
	}

	switch ( normalizeText( state.status, 'uninitialized' ) ) {
		case 'ready':
			return {
				label: 'Ready',
				tone: 'success',
			};
		case 'stale':
			return {
				label: 'Refresh needed',
				tone: 'warning',
			};
		case 'indexing':
			return {
				label: 'Syncing',
				tone: 'accent',
			};
		default:
			return {
				label: 'Needs sync',
				tone: 'warning',
			};
	}
}

function getPatternSyncStatusSentence( runtimeState, prerequisiteMessage ) {
	const state =
		runtimeState && typeof runtimeState === 'object' ? runtimeState : {};

	if ( prerequisiteMessage ) {
		return 'Pattern recommendations are not available until the required setup is complete.';
	}

	if ( state.last_error ) {
		return 'Pattern recommendations need attention before they can be trusted.';
	}

	switch ( normalizeText( state.status, 'uninitialized' ) ) {
		case 'ready':
			return 'Pattern recommendations are ready.';
		case 'stale':
			return 'Pattern recommendations are usable but out of date.';
		case 'indexing':
			return 'Pattern recommendations are syncing now.';
		default:
			return 'Pattern recommendations are not available until you sync the catalog.';
	}
}

function setBadgeState( badge, nextState ) {
	if ( ! badge || ! nextState ) {
		return;
	}

	badge.textContent = normalizeText( nextState.label );

	Array.from( badge.classList )
		.filter( ( className ) =>
			className.startsWith( 'flavor-agent-settings-section__badge--' )
		)
		.forEach( ( className ) => badge.classList.remove( className ) );

	badge.classList.add(
		`flavor-agent-settings-section__badge--${ normalizeText(
			nextState.tone,
			'neutral'
		) }`
	);
}

function setGlanceCardState( card, nextState ) {
	if ( ! card || ! nextState ) {
		return;
	}

	const valueNode = card.querySelector(
		'.flavor-agent-settings__glance-value'
	);

	if ( valueNode ) {
		valueNode.textContent = normalizeText( nextState.label );
	}

	Array.from( card.classList )
		.filter( ( className ) =>
			className.startsWith( 'flavor-agent-settings__glance-item--' )
		)
		.forEach( ( className ) => card.classList.remove( className ) );

	card.classList.add(
		`flavor-agent-settings__glance-item--${ normalizeText(
			nextState.tone,
			'neutral'
		) }`
	);
}

function renderNotice( noticeRoot, type, message ) {
	if ( ! noticeRoot ) {
		return;
	}

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
}

function setStatusText( node, message ) {
	if ( ! node ) {
		return;
	}

	node.textContent = normalizeText( message );
	node.setAttribute( 'aria-hidden', message ? 'false' : 'true' );
}

function updateMetric( root, metric, nextValue, isVisible, isError = false ) {
	const metricNode = root.querySelector(
		`[data-pattern-metric="${ metric }"]`
	);
	const valueNode = root.querySelector(
		`[data-pattern-metric-value="${ metric }"]`
	);

	if ( ! metricNode || ! valueNode ) {
		return;
	}

	valueNode.textContent = normalizeText( nextValue );
	valueNode.classList.toggle(
		'flavor-agent-sync-panel__metric-value--error',
		Boolean( isError )
	);

	metricNode.classList.toggle( 'is-hidden', ! isVisible );
	metricNode.hidden = ! isVisible;
}

function updateSyncPanelState( root, runtimeState ) {
	const syncBody = root.querySelector( '.flavor-agent-sync-panel' );

	if ( ! syncBody ) {
		return;
	}

	const hasPrerequisites =
		normalizeText( syncBody.dataset.patternPrerequisitesReady ) === '1';
	const prerequisiteMessage = normalizeText(
		syncBody.dataset.patternPrerequisiteMessage
	);
	const state =
		runtimeState && typeof runtimeState === 'object' ? runtimeState : {};
	let syncBadgeTone = 'warning';

	if ( hasPrerequisites ) {
		syncBadgeTone = state.last_error
			? 'error'
			: getPatternSyncStatusTone(
					normalizeText( state.status, 'uninitialized' )
			  );
	}

	const syncBadgeState = {
		label: hasPrerequisites
			? getPatternSyncStatusLabel(
					normalizeText( state.status, 'uninitialized' )
			  )
			: 'Needs setup',
		tone: syncBadgeTone,
	};
	const overviewState = getPatternOverviewStatus( state, hasPrerequisites );
	const summaryNode = root.querySelector( '#flavor-agent-sync-summary' );
	const syncPanel = root.querySelector( '[data-flavor-agent-sync-panel]' );

	setBadgeState(
		root.querySelector( '[data-pattern-status-badge="panel"]' ),
		syncBadgeState
	);
	setBadgeState(
		root.querySelector( '[data-flavor-agent-status-badge="patterns"]' ),
		overviewState
	);
	setGlanceCardState(
		root.querySelector( '[data-pattern-overview-status="true"]' ),
		overviewState
	);

	if ( summaryNode ) {
		summaryNode.textContent = getPatternSyncStatusSentence(
			state,
			prerequisiteMessage
		);
	}

	updateMetric( root, 'status', syncBadgeState.label, true );
	updateMetric(
		root,
		'indexed_count',
		String( Math.max( 0, Number( state.indexed_count || 0 ) ) ),
		true
	);
	updateMetric(
		root,
		'last_synced_at',
		normalizeText( state.last_synced_at, 'Not synced yet' ),
		true
	);

	const staleReason = state.stale_reason
		? getPatternSyncReasonLabel( normalizeText( state.stale_reason ) )
		: '';
	updateMetric( root, 'stale_reason', staleReason, Boolean( staleReason ) );

	const lastError = normalizeText( state.last_error, '' );
	updateMetric( root, 'last_error', lastError, Boolean( lastError ), true );

	if ( state.qdrant_collection ) {
		updateMetric(
			root,
			'qdrant_collection',
			normalizeText( state.qdrant_collection ),
			true
		);
	}

	if ( state.embedding_dimension !== undefined ) {
		updateMetric(
			root,
			'embedding_dimension',
			String( Math.max( 0, Number( state.embedding_dimension || 0 ) ) ),
			true
		);
	}

	if (
		syncPanel &&
		( lastError ||
			normalizeText( state.status ) === 'stale' ||
			normalizeText( state.status ) === 'indexing' )
	) {
		syncPanel.open = true;
	}
}

function initializeSectionState( root, storage ) {
	const sections = Array.from(
		root.querySelectorAll( '[data-flavor-agent-section]' )
	);

	if ( sections.length === 0 ) {
		return;
	}

	const storageKey = normalizeText( root.dataset.openSectionStorageKey );
	const forcedSection = normalizeText( root.dataset.forceSection );
	const storedSection = readStoredSection( storage, storageKey );
	const defaultSection = normalizeText( root.dataset.defaultSection );
	let isAdjustingSections = false;

	const findSectionByKey = ( key ) =>
		sections.find(
			( section ) =>
				normalizeText( section.dataset.flavorAgentSection ) === key
		) || null;

	const setActiveSection = ( key ) => {
		const nextActiveSection =
			findSectionByKey( key ) ||
			sections.find( ( section ) => section.open ) ||
			sections[ 0 ];

		if ( ! nextActiveSection ) {
			return;
		}

		isAdjustingSections = true;
		sections.forEach( ( section ) => {
			section.open = section === nextActiveSection;
		} );
		isAdjustingSections = false;

		writeStoredSection(
			storage,
			storageKey,
			normalizeText( nextActiveSection.dataset.flavorAgentSection )
		);
	};

	setActiveSection( forcedSection || storedSection || defaultSection );

	sections.forEach( ( section ) => {
		section.addEventListener( 'toggle', () => {
			if ( isAdjustingSections ) {
				return;
			}

			if ( section.open ) {
				setActiveSection(
					normalizeText( section.dataset.flavorAgentSection )
				);
				return;
			}

			const nextOpenSection = sections.find( ( item ) => item.open );

			writeStoredSection(
				storage,
				storageKey,
				nextOpenSection
					? normalizeText(
							nextOpenSection.dataset.flavorAgentSection
					  )
					: ''
			);
		} );
	} );
}

async function readJsonResponse( response ) {
	const rawBody =
		response && typeof response.text === 'function'
			? await response.text()
			: '';

	if ( ! rawBody ) {
		return {};
	}

	try {
		return JSON.parse( rawBody );
	} catch ( error ) {
		return {
			message: rawBody,
		};
	}
}

function buildSyncSuccessMessage( payload ) {
	const indexed = Math.max( 0, Number( payload?.indexed || 0 ) );
	const removed = Math.max( 0, Number( payload?.removed || 0 ) );
	const statusLabel = getPatternSyncStatusLabel(
		normalizeText(
			payload?.runtimeState?.status,
			normalizeText( payload?.status, 'ready' )
		)
	);

	return `Synced ${ indexed } patterns, removed ${ removed }. Status: ${ statusLabel }.`;
}

function initializePatternSync( root, fetchImpl ) {
	const button = root.querySelector( '#flavor-agent-sync-button' );
	const spinner = root.querySelector( '#flavor-agent-sync-spinner' );
	const noticeRoot = root.querySelector( '#flavor-agent-sync-notice' );
	const syncBody = root.querySelector( '.flavor-agent-sync-panel' );
	const config =
		typeof window !== 'undefined' ? window.flavorAgentAdmin || null : null;

	if (
		! button ||
		! spinner ||
		! noticeRoot ||
		! syncBody ||
		! fetchImpl ||
		! config?.restUrl ||
		! config?.nonce
	) {
		return;
	}

	const statusNode = root.querySelector( '#flavor-agent-sync-status' );
	const liveRegion = root.querySelector( '#flavor-agent-sync-live-region' );
	const syncPanel = root.querySelector( '[data-flavor-agent-sync-panel]' );

	const canSync =
		normalizeText( syncBody.dataset.patternPrerequisitesReady ) === '1';

	const setBusy = ( isBusy ) => {
		button.disabled = isBusy || ! canSync;
		spinner.classList.toggle( 'is-active', isBusy );
		setStatusText( statusNode, isBusy ? 'Syncing...' : '' );
	};

	button.disabled = ! canSync;

	button.addEventListener( 'click', async () => {
		if ( ! canSync ) {
			return;
		}

		if ( syncPanel ) {
			syncPanel.open = true;
		}

		setBusy( true );
		renderNotice( noticeRoot, '', '' );
		setStatusText( liveRegion, 'Syncing pattern catalog.' );

		try {
			const response = await fetchImpl(
				`${ config.restUrl }flavor-agent/v1/sync-patterns`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': config.nonce,
					},
				}
			);
			const payload = await readJsonResponse( response );

			if ( ! response.ok ) {
				throw new Error(
					normalizeText( payload?.message, 'Sync failed.' )
				);
			}

			if ( payload?.runtimeState ) {
				updateSyncPanelState( root, payload.runtimeState );
			}

			const successMessage = buildSyncSuccessMessage( payload );

			renderNotice( noticeRoot, 'success', successMessage );
			setStatusText( liveRegion, successMessage );
		} catch ( error ) {
			const message =
				error instanceof Error && error.message
					? error.message
					: 'Sync failed.';

			renderNotice( noticeRoot, 'error', message );
			setStatusText( liveRegion, message );

			if ( syncPanel ) {
				syncPanel.open = true;
			}
		} finally {
			setBusy( false );
		}
	} );
}

export function initializeSettingsPage( {
	root = document.querySelector( '.flavor-agent-settings' ),
	fetchImpl = typeof window !== 'undefined' &&
	typeof window.fetch === 'function'
		? window.fetch.bind( window )
		: null,
	storage = getDefaultStorage(),
} = {} ) {
	if ( ! root ) {
		return null;
	}

	initializeSectionState( root, storage );
	initializePatternSync( root, fetchImpl );

	return {
		root,
	};
}
