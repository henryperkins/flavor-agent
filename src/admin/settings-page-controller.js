import { __, sprintf } from '@wordpress/i18n';

const PATTERN_SYNC_POLL_INTERVAL_MS = 5000;

const PATTERN_STATUS_LABELS = {
	error: __( 'Error', 'flavor-agent' ),
	indexing: __( 'Syncing', 'flavor-agent' ),
	ready: __( 'Ready', 'flavor-agent' ),
	stale: __( 'Refresh needed', 'flavor-agent' ),
	uninitialized: __( 'Not synced', 'flavor-agent' ),
};

const PATTERN_STATUS_TONES = {
	error: 'error',
	indexing: 'accent',
	ready: 'success',
	stale: 'warning',
	uninitialized: 'neutral',
};

const PATTERN_REASON_LABELS = {
	embedding_signature_changed: __(
		'Cloudflare credentials or managed embedding model changed.',
		'flavor-agent'
	),
	pattern_store_migration_required: __(
		'The previous pattern catalog must be rebuilt in Cloudflare AI Search. Save Cloudflare settings and sync; existing vectors are not copied.',
		'flavor-agent'
	),
	pattern_backend_changed: __(
		'Pattern storage changed. Sync the Cloudflare AI Search catalog.',
		'flavor-agent'
	),
	cloudflare_ai_search_namespace_changed: __(
		'Cloudflare storage namespace changed. Save Cloudflare settings and sync the catalog.',
		'flavor-agent'
	),
	cloudflare_ai_search_instance_changed: __(
		'Cloudflare managed index changed. Save Cloudflare settings and sync the catalog.',
		'flavor-agent'
	),
	cloudflare_ai_search_signature_changed: __(
		'Cloudflare credentials or managed embedding model changed. Save Cloudflare settings and sync the catalog.',
		'flavor-agent'
	),
	pattern_registry_changed: __(
		'Registered patterns changed.',
		'flavor-agent'
	),
};

const PATTERN_COMPATIBILITY_REASONS = [
	'pattern_backend_changed',
	'pattern_store_migration_required',
	'cloudflare_ai_search_namespace_changed',
	'cloudflare_ai_search_instance_changed',
	'cloudflare_ai_search_signature_changed',
];

const GUIDELINE_CATEGORY_FIELDS = {
	site: 'flavor_agent_guideline_site',
	copy: 'flavor_agent_guideline_copy',
	images: 'flavor_agent_guideline_images',
	additional: 'flavor_agent_guideline_additional',
};

function getDefaultStorage() {
	try {
		return window.localStorage;
	} catch {
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
	} catch {
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
	} catch {
		// Ignore storage write failures and keep the page functional.
	}
}

function getPatternSyncStatusLabel( status, state = null ) {
	if ( state && patternCatalogNeedsSync( { ...state, status } ) ) {
		return __( 'Needs sync', 'flavor-agent' );
	}

	return PATTERN_STATUS_LABELS[ status ] || normalizeText( status );
}

function getPatternSyncStatusTone( status ) {
	return PATTERN_STATUS_TONES[ status ] || 'neutral';
}

function getPatternSyncReasonLabel( reason ) {
	return PATTERN_REASON_LABELS[ reason ] || normalizeText( reason );
}

function getPatternStaleReasons( state ) {
	const reasons = Array.isArray( state.stale_reasons )
		? state.stale_reasons.filter(
				( reason ) => typeof reason === 'string' && reason
		  )
		: [];
	return reasons.length ? reasons : [ normalizeText( state.stale_reason ) ];
}

function hasConfirmedPatternCatalog( state ) {
	return Boolean(
		state.pattern_backend === 'cloudflare_ai_search' &&
			state.last_synced_at &&
			state.cloudflare_ai_search_namespace &&
			state.cloudflare_ai_search_instance &&
			state.cloudflare_ai_search_signature
	);
}

function patternCatalogNeedsSync( state ) {
	if ( state.status === 'indexing' ) {
		return false;
	}

	if (
		getPatternStaleReasons( state ).some( ( reason ) =>
			PATTERN_COMPATIBILITY_REASONS.includes( reason )
		)
	) {
		return true;
	}

	if ( state.status !== 'stale' && state.status !== 'ready' ) {
		return false;
	}

	// Initial display state contains a server readiness flag instead of private catalog metadata.
	if ( typeof state.recommendations_ready === 'boolean' ) {
		return ! state.recommendations_ready;
	}

	return ! hasConfirmedPatternCatalog( state );
}

function getPatternOverviewStatus( runtimeState, hasPrerequisites ) {
	const state =
		runtimeState && typeof runtimeState === 'object' ? runtimeState : {};

	if ( ! hasPrerequisites ) {
		return {
			label: __( 'Needs setup', 'flavor-agent' ),
			tone: 'warning',
		};
	}

	if ( patternCatalogNeedsSync( state ) ) {
		return {
			label: __( 'Needs sync', 'flavor-agent' ),
			tone: 'warning',
		};
	}

	if ( state.last_error ) {
		return {
			label: __( 'Needs attention', 'flavor-agent' ),
			tone: 'error',
		};
	}

	switch ( normalizeText( state.status, 'uninitialized' ) ) {
		case 'ready':
			return {
				label: __( 'Ready', 'flavor-agent' ),
				tone: 'success',
			};
		case 'stale':
			return {
				label: __( 'Refresh needed', 'flavor-agent' ),
				tone: 'warning',
			};
		case 'indexing':
			return {
				label: __( 'Syncing', 'flavor-agent' ),
				tone: 'accent',
			};
		default:
			return {
				label: __( 'Needs sync', 'flavor-agent' ),
				tone: 'warning',
			};
	}
}

function getPatternSyncStatusSentence( runtimeState, prerequisiteMessage ) {
	const state =
		runtimeState && typeof runtimeState === 'object' ? runtimeState : {};

	if ( prerequisiteMessage ) {
		return __(
			'Pattern recommendations are not available until the required setup is complete.',
			'flavor-agent'
		);
	}

	if ( patternCatalogNeedsSync( state ) ) {
		if (
			getPatternStaleReasons( state ).includes(
				'pattern_store_migration_required'
			)
		) {
			return __(
				'Pattern recommendations are unavailable until the catalog is rebuilt in Cloudflare AI Search. Save Cloudflare settings and sync the catalog; existing vectors are not copied.',
				'flavor-agent'
			);
		}

		return __(
			'Pattern recommendations are unavailable until the catalog is rebuilt or revalidated in Cloudflare AI Search. Review and save Cloudflare settings, then sync the catalog.',
			'flavor-agent'
		);
	}

	if ( state.last_error ) {
		return __(
			'Pattern recommendations need attention before they can be trusted.',
			'flavor-agent'
		);
	}

	switch ( normalizeText( state.status, 'uninitialized' ) ) {
		case 'ready':
			return __( 'Pattern recommendations are ready.', 'flavor-agent' );
		case 'stale':
			return __(
				'Pattern recommendations are usable but out of date.',
				'flavor-agent'
			);
		case 'indexing':
			return __(
				'Pattern recommendations are syncing now.',
				'flavor-agent'
			);
		default:
			return __(
				'Pattern recommendations are not available until you sync the catalog.',
				'flavor-agent'
			);
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
	const currentStatus = normalizeText( state.status, 'uninitialized' );
	const statusUnavailable = currentStatus === 'unavailable';
	const requestRejected = currentStatus === 'rejected';
	syncBody.dataset.patternSyncStatus = currentStatus;
	let syncBadgeTone = 'warning';

	if ( hasPrerequisites ) {
		syncBadgeTone = state.last_error
			? 'error'
			: getPatternSyncStatusTone( currentStatus );
	}

	let syncBadgeState = {
		label: hasPrerequisites
			? getPatternSyncStatusLabel( currentStatus, state )
			: __( 'Needs setup', 'flavor-agent' ),
		tone: syncBadgeTone,
	};
	let overviewState = getPatternOverviewStatus( state, hasPrerequisites );

	if ( statusUnavailable || requestRejected ) {
		syncBadgeState = {
			label: statusUnavailable
				? __( 'Status unavailable', 'flavor-agent' )
				: __( 'Sync not started', 'flavor-agent' ),
			tone: 'warning',
		};
		overviewState = syncBadgeState;
	}
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
		if ( statusUnavailable || requestRejected ) {
			const lastKnownLabel = getPatternSyncStatusLabel(
				normalizeText( state.last_known_status, 'uninitialized' ),
				state
			);
			summaryNode.textContent = statusUnavailable
				? sprintf(
						/* translators: %s: last confirmed sync status. */
						__(
							'Current sync status is unavailable. Last confirmed status: %s. Index details below are last confirmed values. The server may still be working. Check sync status before starting another sync.',
							'flavor-agent'
						),
						lastKnownLabel
				  )
				: sprintf(
						/* translators: %s: last confirmed sync status. */
						__(
							'The latest sync request was not accepted. Last confirmed catalog status: %s. Index details below are last confirmed values. Review the error below before trying again.',
							'flavor-agent'
						),
						lastKnownLabel
				  );
		} else {
			summaryNode.textContent = getPatternSyncStatusSentence(
				state,
				prerequisiteMessage
			);
		}
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
		normalizeText(
			state.last_synced_at,
			__( 'Not synced yet', 'flavor-agent' )
		),
		true
	);

	const staleReason = state.stale_reason
		? getPatternSyncReasonLabel( normalizeText( state.stale_reason ) )
		: '';
	updateMetric( root, 'stale_reason', staleReason, Boolean( staleReason ) );

	const lastError = normalizeText( state.last_error, '' );
	updateMetric( root, 'last_error', lastError, Boolean( lastError ), true );

	if (
		syncPanel &&
		( lastError ||
			statusUnavailable ||
			requestRejected ||
			currentStatus === 'stale' ||
			currentStatus === 'indexing' )
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
	const attentionSection = normalizeText( root.dataset.attentionSection );
	const storedSection = readStoredSection( storage, storageKey );
	const defaultSection = normalizeText( root.dataset.defaultSection );
	let isAdjustingSections = false;

	const findSectionByKey = ( key ) =>
		sections.find(
			( section ) =>
				normalizeText( section.dataset.flavorAgentSection ) === key
		) || null;
	const sectionHasValidationError = ( section ) =>
		normalizeText( section.dataset.flavorAgentValidationError ) === 'true';

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
			section.open =
				section === nextActiveSection ||
				( section.open && sectionHasValidationError( section ) );
		} );
		isAdjustingSections = false;

		writeStoredSection(
			storage,
			storageKey,
			normalizeText( nextActiveSection.dataset.flavorAgentSection )
		);
	};

	setActiveSection(
		forcedSection || attentionSection || storedSection || defaultSection
	);

	const view = root.ownerDocument.defaultView;
	const activateHashSection = ( hash ) => {
		let targetId;

		try {
			targetId = decodeURIComponent( normalizeText( hash ).slice( 1 ) );
		} catch {
			return false;
		}

		const target = root.ownerDocument.getElementById( targetId );
		const section = sections.find(
			( item ) => target === item || target?.contains( item )
		);

		if ( ! section || ! root.contains( target ) ) {
			return false;
		}

		setActiveSection( normalizeText( section.dataset.flavorAgentSection ) );
		section.querySelector( 'summary' )?.focus();
		return true;
	};

	if ( view?.location.hash ) {
		activateHashSection( view.location.hash );
	}

	root.addEventListener( 'click', ( event ) => {
		if (
			! view ||
			event.defaultPrevented ||
			event.button !== 0 ||
			event.altKey ||
			event.ctrlKey ||
			event.metaKey ||
			event.shiftKey
		) {
			return;
		}

		const link = event.target.closest?.( 'a[href]' );

		if ( ! link || ( link.target && link.target !== '_self' ) ) {
			return;
		}

		const destination = new URL( link.href, view.location.href );

		if (
			destination.origin !== view.location.origin ||
			destination.pathname !== view.location.pathname ||
			destination.search !== view.location.search ||
			! destination.hash ||
			! activateHashSection( destination.hash )
		) {
			return;
		}

		event.preventDefault();

		if ( view.location.hash !== destination.hash ) {
			view.history.pushState( null, '', destination.href );
		}
	} );

	view?.addEventListener( 'hashchange', () => {
		if ( root.isConnected ) {
			activateHashSection( view.location.hash );
		}
	} );

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

function parseJsonValue( value, fallback = null ) {
	if ( ! value ) {
		return fallback;
	}

	try {
		return JSON.parse( value );
	} catch {
		return fallback;
	}
}

function isValidBlockName( blockName ) {
	return /^[a-z0-9-]+\/[a-z0-9-]+$/.test( normalizeText( blockName ).trim() );
}

function normalizeBlockGuidelines( value ) {
	if ( ! value || typeof value !== 'object' ) {
		return {};
	}

	return Object.fromEntries(
		Object.entries( value )
			.map( ( [ blockName, blockData ] ) => {
				const normalizedBlockName = normalizeText( blockName )
					.trim()
					.toLowerCase();

				if ( ! isValidBlockName( normalizedBlockName ) ) {
					return null;
				}

				const rawGuidelines =
					blockData &&
					typeof blockData === 'object' &&
					! Array.isArray( blockData )
						? blockData.guidelines
						: blockData;
				const guidelines = normalizeText( rawGuidelines ).trim();

				if ( ! guidelines ) {
					return null;
				}

				return [ normalizedBlockName, guidelines ];
			} )
			.filter( Boolean )
			.sort( ( [ leftName ], [ rightName ] ) =>
				leftName.localeCompare( rightName )
			)
	);
}

function buildGuidelinesPayload( root, blockGuidelines ) {
	const categories = Object.fromEntries(
		Object.entries( GUIDELINE_CATEGORY_FIELDS ).map(
			( [ key, fieldId ] ) => {
				const field = root.querySelector( `#${ fieldId }` );

				return [
					key,
					{
						guidelines: field
							? normalizeText( field.value ).trim()
							: '',
					},
				];
			}
		)
	);

	return {
		guideline_categories: {
			...categories,
			blocks: Object.fromEntries(
				Object.entries( blockGuidelines ).map(
					( [ blockName, guidelines ] ) => [
						blockName,
						{ guidelines: normalizeText( guidelines ).trim() },
					]
				)
			),
		},
	};
}

function initializeGuidelinesManager( root ) {
	const guidelinesRoot = root.querySelector(
		'[data-flavor-agent-guidelines-root]'
	);

	if ( ! guidelinesRoot ) {
		return;
	}

	const hiddenInput = guidelinesRoot.querySelector(
		'[data-guidelines-block-input]'
	);
	const listRoot = guidelinesRoot.querySelector(
		'[data-guidelines-block-list]'
	);
	const blockSelect = guidelinesRoot.querySelector(
		'[data-guidelines-block-select]'
	);
	const blockText = guidelinesRoot.querySelector(
		'[data-guidelines-block-text]'
	);
	const saveButton = guidelinesRoot.querySelector(
		'[data-guidelines-block-save]'
	);
	const cancelButton = guidelinesRoot.querySelector(
		'[data-guidelines-block-cancel]'
	);
	const importButton = guidelinesRoot.querySelector(
		'[data-guidelines-import-button]'
	);
	const exportButton = guidelinesRoot.querySelector(
		'[data-guidelines-export-button]'
	);
	const fileInput = guidelinesRoot.querySelector(
		'[data-guidelines-file-input]'
	);
	if (
		! hiddenInput ||
		! listRoot ||
		! blockSelect ||
		! blockText ||
		! saveButton ||
		! cancelButton ||
		! importButton ||
		! exportButton ||
		! fileInput
	) {
		return;
	}

	const noticeRoot = guidelinesRoot.querySelector(
		'[data-guidelines-notice]'
	);
	const blocksPanel = guidelinesRoot.querySelector(
		'.flavor-agent-guidelines__blocks-panel'
	);
	const fallbackInactive = Boolean(
		guidelinesRoot.closest( '[data-guidelines-fallback]' )
	);
	const config =
		typeof window !== 'undefined' ? window.flavorAgentAdmin || null : null;

	const parsedOptions = config?.guidelinesBlockOptions;
	const blockOptions = Array.isArray( parsedOptions )
		? parsedOptions
				.map( ( option ) => ( {
					value: normalizeText( option?.value ).trim(),
					label: normalizeText(
						option?.label || option?.value
					).trim(),
				} ) )
				.filter( ( option ) => option.value && option.label )
		: [];
	const blockLabels = new Map(
		blockOptions.map( ( option ) => [ option.value, option.label ] )
	);
	let blockGuidelines = normalizeBlockGuidelines(
		parseJsonValue( hiddenInput.value, {} )
	);
	let editingBlock = '';

	const createFieldError = ( field, selector, id ) => {
		let error = guidelinesRoot.querySelector( selector );

		if ( ! error ) {
			error = document.createElement( 'p' );
			error.className =
				'description flavor-agent-guidelines__field-error';
			error.hidden = true;
			field.insertAdjacentElement( 'afterend', error );
		}

		error.id = id;
		return error;
	};
	const blockSelectError = createFieldError(
		blockSelect,
		'[data-guidelines-block-select-error]',
		'flavor-agent-block-select-error'
	);
	const blockTextError = createFieldError(
		blockText,
		'[data-guidelines-block-text-error]',
		'flavor-agent-block-text-error'
	);
	const setFieldError = ( field, error, message = '' ) => {
		const descriptions = new Set(
			normalizeText( field.getAttribute( 'aria-describedby' ) )
				.split( /\s+/ )
				.filter( Boolean )
		);

		error.textContent = message;
		error.hidden = ! message;

		if ( message ) {
			field.setAttribute( 'aria-invalid', 'true' );
			descriptions.add( error.id );
		} else {
			field.removeAttribute( 'aria-invalid' );
			descriptions.delete( error.id );
		}

		if ( descriptions.size ) {
			field.setAttribute(
				'aria-describedby',
				Array.from( descriptions ).join( ' ' )
			);
		} else {
			field.removeAttribute( 'aria-describedby' );
		}
	};
	const clearFieldErrors = () => {
		setFieldError( blockSelect, blockSelectError );
		setFieldError( blockText, blockTextError );
	};
	const clearResolvedFieldError = ( field, error ) => {
		if ( ! field.hasAttribute( 'aria-invalid' ) ) {
			return;
		}
		setFieldError( field, error );
		if (
			! blockSelect.hasAttribute( 'aria-invalid' ) &&
			! blockText.hasAttribute( 'aria-invalid' )
		) {
			renderNotice( noticeRoot, '', '' );
		}
	};
	const reportFieldError = ( field, error, message ) => {
		setFieldError( field, error, message );
		renderNotice( noticeRoot, 'error', message );
		let panel = field.closest( 'details' );
		while ( panel && root.contains( panel ) ) {
			panel.open = true;
			panel = panel.parentElement?.closest( 'details' );
		}
		field.focus();
	};

	const writeBlockGuidelines = () => {
		hiddenInput.value = JSON.stringify( blockGuidelines );
	};

	const getSortedBlockEntries = () =>
		Object.entries( blockGuidelines ).sort(
			( [ leftName ], [ rightName ] ) => {
				const leftLabel = blockLabels.get( leftName ) || leftName;
				const rightLabel = blockLabels.get( rightName ) || rightName;
				const labelComparison = leftLabel.localeCompare( rightLabel );

				if ( labelComparison !== 0 ) {
					return labelComparison;
				}

				return leftName.localeCompare( rightName );
			}
		);

	const renderBlockSelect = () => {
		const usedBlocks = new Set( Object.keys( blockGuidelines ) );

		if ( editingBlock ) {
			usedBlocks.delete( editingBlock );
		}

		const availableOptions = blockOptions.filter(
			( option ) => ! usedBlocks.has( option.value )
		);
		const selectedValue =
			editingBlock || normalizeText( blockSelect.value );

		blockSelect.innerHTML = '';

		const placeholderOption = document.createElement( 'option' );
		placeholderOption.value = '';
		placeholderOption.textContent = __( 'Select a block', 'flavor-agent' );
		blockSelect.appendChild( placeholderOption );

		if (
			editingBlock &&
			! availableOptions.find(
				( option ) => option.value === editingBlock
			)
		) {
			availableOptions.unshift( {
				value: editingBlock,
				label: blockLabels.get( editingBlock ) || editingBlock,
			} );
		}

		availableOptions.forEach( ( option ) => {
			const optionNode = document.createElement( 'option' );
			optionNode.value = option.value;
			optionNode.textContent = option.label;
			blockSelect.appendChild( optionNode );
		} );

		blockSelect.disabled = Boolean( editingBlock );

		if ( selectedValue ) {
			blockSelect.value = selectedValue;
		}
	};

	const resetEditor = () => {
		clearFieldErrors();
		editingBlock = '';
		blockText.value = '';
		saveButton.textContent = __( 'Add Block Guideline', 'flavor-agent' );
		cancelButton.hidden = true;
		renderBlockSelect();
		blockSelect.value = '';
	};

	const startEditing = ( blockName ) => {
		clearFieldErrors();
		editingBlock = normalizeText( blockName );
		blockText.value = blockGuidelines[ editingBlock ] || '';
		saveButton.textContent = __( 'Update Block Guideline', 'flavor-agent' );
		cancelButton.hidden = false;
		renderBlockSelect();
		blockSelect.value = editingBlock;
		blockText.focus();
	};

	const renderBlockList = () => {
		listRoot.innerHTML = '';

		const entries = getSortedBlockEntries();

		if ( entries.length === 0 ) {
			const emptyState = document.createElement( 'p' );
			emptyState.className = 'flavor-agent-guidelines__empty-state';
			emptyState.textContent = __(
				'No block guidelines yet.',
				'flavor-agent'
			);
			listRoot.appendChild( emptyState );
			return;
		}

		const list = document.createElement( 'div' );
		list.className = 'flavor-agent-guidelines__items';

		entries.forEach( ( [ blockName, guidelines ] ) => {
			const item = document.createElement( 'article' );
			item.className = 'flavor-agent-guidelines__item';

			const header = document.createElement( 'div' );
			header.className = 'flavor-agent-guidelines__item-header';

			const titleWrap = document.createElement( 'div' );
			titleWrap.className = 'flavor-agent-guidelines__item-copy';

			const title = document.createElement( 'h4' );
			title.className = 'flavor-agent-guidelines__item-title';
			title.textContent = blockLabels.get( blockName ) || blockName;

			const meta = document.createElement( 'p' );
			meta.className = 'flavor-agent-guidelines__item-meta';
			meta.textContent = blockName;

			const preview = document.createElement( 'p' );
			preview.className = 'flavor-agent-guidelines__item-preview';
			preview.textContent = guidelines;

			titleWrap.appendChild( title );
			titleWrap.appendChild( meta );
			titleWrap.appendChild( preview );

			const actions = document.createElement( 'div' );
			actions.className = 'flavor-agent-guidelines__item-actions';

			const editButton = document.createElement( 'button' );
			editButton.type = 'button';
			editButton.className = 'button button-secondary';
			editButton.textContent = __( 'Edit', 'flavor-agent' );
			editButton.setAttribute(
				'aria-label',
				sprintf(
					/* translators: %s: block label. */
					__( 'Edit guideline for %s', 'flavor-agent' ),
					blockLabels.get( blockName ) || blockName
				)
			);
			editButton.addEventListener( 'click', () =>
				startEditing( blockName )
			);

			const removeButton = document.createElement( 'button' );
			removeButton.type = 'button';
			removeButton.className = 'button button-link-delete';
			removeButton.textContent = __( 'Remove', 'flavor-agent' );
			removeButton.dataset.guidelinesBlockRemove = blockName;
			removeButton.setAttribute(
				'aria-label',
				sprintf(
					/* translators: %s: block label. */
					__( 'Remove guideline for %s', 'flavor-agent' ),
					blockLabels.get( blockName ) || blockName
				)
			);
			removeButton.addEventListener( 'click', () => {
				const label = blockLabels.get( blockName ) || blockName;

				if (
					typeof window !== 'undefined' &&
					typeof window.confirm === 'function' &&
					// eslint-disable-next-line no-alert
					! window.confirm(
						sprintf(
							/* translators: %s: block label. */
							__(
								'Remove the block guideline for %s?',
								'flavor-agent'
							),
							label
						)
					)
				) {
					return;
				}

				const removedIndex = getSortedBlockEntries().findIndex(
					( [ name ] ) => name === blockName
				);
				delete blockGuidelines[ blockName ];
				writeBlockGuidelines();

				if ( editingBlock === blockName ) {
					resetEditor();
				} else {
					renderBlockSelect();
				}
				renderBlockList();
				const remainingRemoveButtons = listRoot.querySelectorAll(
					'[data-guidelines-block-remove]'
				);
				const nextFocus =
					remainingRemoveButtons[
						Math.min(
							removedIndex,
							remainingRemoveButtons.length - 1
						)
					] || blockSelect;
				nextFocus.focus();

				renderNotice(
					noticeRoot,
					'success',
					fallbackInactive
						? __(
								'Fallback block guideline removed. Save Changes to persist the inactive fallback.',
								'flavor-agent'
						  )
						: __(
								'Block guideline removed. Save Changes to persist.',
								'flavor-agent'
						  )
				);
			} );

			actions.appendChild( editButton );
			actions.appendChild( removeButton );

			header.appendChild( titleWrap );
			header.appendChild( actions );
			item.appendChild( header );
			list.appendChild( item );
		} );

		listRoot.appendChild( list );
	};

	const applyImportedPayload = ( payload ) => {
		if (
			! payload ||
			typeof payload !== 'object' ||
			! payload.guideline_categories ||
			typeof payload.guideline_categories !== 'object'
		) {
			throw new Error(
				__(
					'Check that your file contains a guideline_categories object.',
					'flavor-agent'
				)
			);
		}

		Object.entries( GUIDELINE_CATEGORY_FIELDS ).forEach(
			( [ category, fieldId ] ) => {
				const field = root.querySelector( `#${ fieldId }` );

				if ( ! field ) {
					return;
				}

				const nextValue = normalizeText(
					payload.guideline_categories?.[ category ]?.guidelines
				);
				field.value = nextValue;
			}
		);

		blockGuidelines = normalizeBlockGuidelines(
			payload.guideline_categories?.blocks || {}
		);
		writeBlockGuidelines();
		renderBlockSelect();
		renderBlockList();
		resetEditor();

		if ( blocksPanel && Object.keys( blockGuidelines ).length > 0 ) {
			blocksPanel.open = true;
		}
	};

	writeBlockGuidelines();
	renderBlockSelect();
	renderBlockList();
	resetEditor();

	saveButton.addEventListener( 'click', () => {
		clearFieldErrors();
		const blockName =
			editingBlock || normalizeText( blockSelect.value ).trim();

		if ( ! blockName ) {
			reportFieldError(
				blockSelect,
				blockSelectError,
				__(
					'Choose a block before saving a block guideline.',
					'flavor-agent'
				)
			);
			return;
		}

		const guidelines = normalizeText( blockText.value ).trim();

		if ( ! guidelines ) {
			reportFieldError(
				blockText,
				blockTextError,
				__(
					'Enter guideline text before saving a block guideline.',
					'flavor-agent'
				)
			);
			return;
		}

		blockGuidelines[ blockName ] = guidelines;
		writeBlockGuidelines();
		renderBlockList();
		resetEditor();
		blockSelect.focus();
		renderNotice(
			noticeRoot,
			'success',
			fallbackInactive
				? __(
						'Fallback block guideline ready. Save Changes to persist the inactive fallback.',
						'flavor-agent'
				  )
				: __(
						'Block guideline ready. Save Changes to persist.',
						'flavor-agent'
				  )
		);
	} );

	cancelButton.addEventListener( 'click', () => {
		resetEditor();
		renderNotice( noticeRoot, '', '' );
		blockSelect.focus();
	} );

	blockSelect.addEventListener( 'change', () => {
		if ( blockSelect.value ) {
			clearResolvedFieldError( blockSelect, blockSelectError );
		}
	} );
	blockText.addEventListener( 'input', () => {
		if ( normalizeText( blockText.value ).trim() ) {
			clearResolvedFieldError( blockText, blockTextError );
		}
	} );

	importButton.addEventListener( 'click', () => {
		fileInput.click();
	} );

	fileInput.addEventListener( 'change', async ( event ) => {
		const [ file ] = Array.from( event.target.files || [] );
		event.target.value = '';

		if ( ! file ) {
			return;
		}

		try {
			const parsed = parseJsonValue( await file.text(), null );

			if ( ! parsed ) {
				throw new Error(
					__(
						'Check that your file contains valid JSON and try again.',
						'flavor-agent'
					)
				);
			}

			applyImportedPayload( parsed );
			renderNotice(
				noticeRoot,
				'success',
				fallbackInactive
					? __(
							'Fallback guidelines imported into the form. Save Changes to persist the inactive fallback.',
							'flavor-agent'
					  )
					: __(
							'Guidelines imported into the form. Save Changes to persist.',
							'flavor-agent'
					  )
			);
		} catch ( error ) {
			renderNotice(
				noticeRoot,
				'error',
				error instanceof Error
					? error.message
					: __( 'Could not import guidelines.', 'flavor-agent' )
			);
		} finally {
			importButton.focus();
		}
	} );

	exportButton.addEventListener( 'click', () => {
		const payload = buildGuidelinesPayload( root, blockGuidelines );
		const blob = new Blob( [ JSON.stringify( payload, null, 2 ) ], {
			type: 'application/json',
		} );
		const objectUrl = URL.createObjectURL( blob );
		const anchor = document.createElement( 'a' );
		anchor.href = objectUrl;
		anchor.download = 'flavor-agent-guidelines.json';
		document.body.appendChild( anchor );
		anchor.click();
		document.body.removeChild( anchor );
		URL.revokeObjectURL( objectUrl );
		renderNotice(
			noticeRoot,
			'success',
			fallbackInactive
				? __( 'Fallback guidelines exported.', 'flavor-agent' )
				: __( 'Guidelines exported.', 'flavor-agent' )
		);
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
	} catch {
		return {
			message: rawBody,
		};
	}
}

function buildSyncSuccessMessage( payload ) {
	const statusLabel = getPatternSyncStatusLabel(
		normalizeText(
			payload?.runtimeState?.status,
			normalizeText( payload?.status, 'ready' )
		),
		payload?.runtimeState
	);

	if ( payload?.queued ) {
		return sprintf(
			/* translators: %s: sync status label. */
			__( 'Pattern catalog sync queued. Status: %s.', 'flavor-agent' ),
			statusLabel
		);
	}

	const indexed = Math.max( 0, Number( payload?.indexed || 0 ) );
	const removed = Math.max( 0, Number( payload?.removed || 0 ) );

	return sprintf(
		/* translators: 1: indexed pattern count, 2: removed pattern count, 3: sync status label. */
		__(
			'Synced %1$d patterns, removed %2$d. Status: %3$s.',
			'flavor-agent'
		),
		indexed,
		removed,
		statusLabel
	);
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

	const checkButton = root.querySelector( '#flavor-agent-sync-check-button' );
	const statusNode = root.querySelector( '#flavor-agent-sync-status' );
	const liveRegion = root.querySelector( '#flavor-agent-sync-live-region' );
	const syncPanel = root.querySelector( '[data-flavor-agent-sync-panel]' );
	const syncEndpoint = `${ config.restUrl }flavor-agent/v1/sync-patterns`;
	const confirmedStatuses = [
		'error',
		'indexing',
		'ready',
		'stale',
		'uninitialized',
	];
	let pollTimeout = null;
	let requestInFlight = false;
	let lastRuntimeState;

	try {
		lastRuntimeState = JSON.parse( syncBody.dataset.patternRuntimeState );
	} catch {
		lastRuntimeState = null;
	}

	if ( ! lastRuntimeState || typeof lastRuntimeState !== 'object' ) {
		lastRuntimeState = {
			status: normalizeText(
				syncBody.dataset.patternSyncStatus,
				'uninitialized'
			),
			indexed_count: Number(
				root.querySelector(
					'[data-pattern-metric-value="indexed_count"]'
				)?.textContent || 0
			),
			last_synced_at: root.querySelector(
				'[data-pattern-metric-value="last_synced_at"]'
			)?.textContent,
		};
	}

	const getCurrentSyncStatus = () =>
		normalizeText( syncBody.dataset.patternSyncStatus, 'uninitialized' );
	const prerequisiteCopy = syncBody.querySelector(
		'[data-pattern-prerequisite-copy]'
	);
	const configHint = root.querySelector( '[data-pattern-sync-config-hint]' );
	const syncSummary = root.querySelector( '#flavor-agent-sync-summary' );
	const storageFields = [
		'flavor_agent_cloudflare_workers_ai_account_id',
		'flavor_agent_cloudflare_workers_ai_api_token',
		'flavor_agent_cloudflare_workers_ai_embedding_model',
		'flavor_agent_cloudflare_pattern_ai_search_account_id',
		'flavor_agent_cloudflare_pattern_ai_search_namespace',
		'flavor_agent_cloudflare_pattern_ai_search_instance_id',
		'flavor_agent_cloudflare_pattern_ai_search_api_token',
	];
	const settingsFields = storageFields
		.map( ( name ) => root.querySelector( `[name="${ name }"]` ) )
		.filter( Boolean );
	const savedFieldValues = new Map(
		settingsFields.map( ( field ) => [ field, field.defaultValue ] )
	);
	const hasUnsavedSyncSettings = () =>
		settingsFields.some(
			( field ) => field.value !== savedFieldValues.get( field )
		);
	const havePatternPrerequisites = () =>
		normalizeText( syncBody.dataset.patternPrerequisitesReady ) === '1';
	const canSyncNow = () =>
		! requestInFlight &&
		havePatternPrerequisites() &&
		! hasUnsavedSyncSettings() &&
		! [ 'indexing', 'unavailable' ].includes( getCurrentSyncStatus() );

	const updateButtonAccessibility = () => {
		const unsavedSettings = hasUnsavedSyncSettings();
		if ( configHint ) {
			configHint.hidden = ! unsavedSettings;
		}

		button.disabled = ! canSyncNow();
		button.setAttribute(
			'aria-disabled',
			button.disabled ? 'true' : 'false'
		);
		if ( checkButton ) {
			checkButton.disabled = requestInFlight;
			checkButton.setAttribute(
				'aria-disabled',
				requestInFlight ? 'true' : 'false'
			);
		}

		let description = null;
		if ( unsavedSettings ) {
			description = configHint;
		} else if ( ! havePatternPrerequisites() ) {
			description = prerequisiteCopy;
		} else if (
			[ 'indexing', 'unavailable' ].includes( getCurrentSyncStatus() )
		) {
			description = syncSummary;
		}

		if ( description ) {
			if ( ! description.id ) {
				description.id = 'flavor-agent-sync-prerequisites';
			}
			button.setAttribute( 'aria-describedby', description.id );
		} else {
			button.removeAttribute( 'aria-describedby' );
		}
	};

	const updateActivity = ( message = '' ) => {
		updateButtonAccessibility();
		spinner.classList.toggle( 'is-active', Boolean( message ) );
		setStatusText( statusNode, message );
	};
	const clearSyncPolling = () => {
		if ( pollTimeout ) {
			clearTimeout( pollTimeout );
			pollTimeout = null;
		}
	};
	const acceptRuntimeState = ( state ) => {
		if (
			! confirmedStatuses.includes( state?.status ) ||
			( state.status === 'ready' &&
				! Object.prototype.hasOwnProperty.call(
					state,
					'pattern_backend'
				) )
		) {
			throw new Error(
				__(
					'The server did not return a usable sync status.',
					'flavor-agent'
				)
			);
		}
		lastRuntimeState = {
			...lastRuntimeState,
			...state,
			// A partial response cannot inherit a previous catalog confirmation.
			recommendations_ready: hasConfirmedPatternCatalog( state ),
		};
		updateSyncPanelState( root, lastRuntimeState );
	};
	const showUnavailable = ( error, requestMayHaveStarted = false ) => {
		const detail =
			error instanceof Error && error.message
				? error.message
				: __( 'Unable to refresh sync status.', 'flavor-agent' );
		const message = requestMayHaveStarted
			? sprintf(
					/* translators: %s: error returned while contacting the server. */
					__(
						'The sync request could not be confirmed and may have been accepted. Check sync status before starting another sync. %s',
						'flavor-agent'
					),
					detail
			  )
			: sprintf(
					/* translators: %s: error returned while contacting the server. */
					__(
						'Sync monitoring is unavailable. The server may still be working. Check sync status before starting another sync. %s',
						'flavor-agent'
					),
					detail
			  );
		updateSyncPanelState( root, {
			...lastRuntimeState,
			status: 'unavailable',
			last_known_status: lastRuntimeState.status,
		} );
		renderNotice( noticeRoot, 'error', message );
		setStatusText( liveRegion, message );
	};

	const pollSyncState = async () => {
		if ( requestInFlight ) {
			return;
		}
		clearSyncPolling();
		requestInFlight = true;
		updateActivity( __( 'Checking sync status…', 'flavor-agent' ) );

		try {
			const response = await fetchImpl( syncEndpoint, {
				method: 'GET',
				headers: { 'X-WP-Nonce': config.nonce },
			} );
			const payload = await readJsonResponse( response );
			if ( ! response.ok ) {
				throw new Error(
					normalizeText(
						payload?.message,
						__( 'Unable to refresh sync status.', 'flavor-agent' )
					)
				);
			}
			acceptRuntimeState( payload?.runtimeState );
			const statusMessage = sprintf(
				/* translators: %s: confirmed sync status. */
				__( 'Pattern catalog status updated: %s.', 'flavor-agent' ),
				getPatternSyncStatusLabel(
					lastRuntimeState.status,
					lastRuntimeState
				)
			);
			const message = lastRuntimeState.last_error
				? `${ statusMessage } ${ lastRuntimeState.last_error }`
				: statusMessage;
			renderNotice(
				noticeRoot,
				lastRuntimeState.last_error ? 'error' : 'success',
				message
			);
			setStatusText( liveRegion, message );
			if ( lastRuntimeState.status === 'indexing' ) {
				pollTimeout = setTimeout(
					pollSyncState,
					PATTERN_SYNC_POLL_INTERVAL_MS
				);
			}
		} catch ( error ) {
			showUnavailable( error );
		} finally {
			requestInFlight = false;
			updateActivity(
				getCurrentSyncStatus() === 'indexing'
					? __( 'Syncing…', 'flavor-agent' )
					: ''
			);
		}
	};

	settingsFields.forEach( ( field ) => {
		field.addEventListener( 'input', updateButtonAccessibility );
		field.addEventListener( 'change', updateButtonAccessibility );
	} );
	checkButton?.addEventListener( 'click', pollSyncState );

	button.addEventListener( 'click', async () => {
		if ( ! canSyncNow() ) {
			return;
		}
		if ( syncPanel ) {
			syncPanel.open = true;
		}
		clearSyncPolling();
		requestInFlight = true;
		updateActivity( __( 'Starting sync…', 'flavor-agent' ) );
		renderNotice( noticeRoot, '', '' );
		setStatusText(
			liveRegion,
			__( 'Requesting pattern catalog sync.', 'flavor-agent' )
		);

		try {
			const response = await fetchImpl( syncEndpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
			} );
			const payload = await readJsonResponse( response );
			if ( ! response.ok ) {
				const message = normalizeText(
					payload?.message,
					__( 'The sync request was rejected.', 'flavor-agent' )
				);
				const definitelyRejected =
					payload?.code === 'pattern_sync_schedule_failed' ||
					[ 400, 401, 403, 404, 405, 422, 429 ].includes(
						response.status
					);
				if ( ! definitelyRejected ) {
					throw new Error( message );
				}
				updateSyncPanelState( root, {
					...lastRuntimeState,
					status: 'rejected',
					last_known_status: lastRuntimeState.status,
				} );
				renderNotice( noticeRoot, 'error', message );
				setStatusText( liveRegion, message );
				return;
			}
			acceptRuntimeState( payload?.runtimeState );
			const successMessage = buildSyncSuccessMessage( payload );
			renderNotice( noticeRoot, 'success', successMessage );
			setStatusText( liveRegion, successMessage );
		} catch ( error ) {
			showUnavailable( error, true );
		} finally {
			requestInFlight = false;
			updateActivity();
			if ( getCurrentSyncStatus() === 'indexing' ) {
				pollSyncState();
			}
		}
	} );

	updateButtonAccessibility();
	if ( getCurrentSyncStatus() === 'indexing' ) {
		pollSyncState();
	}
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
	initializeGuidelinesManager( root );

	return {
		root,
	};
}
