import { __, sprintf } from '@wordpress/i18n';

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
		'Embedding provider, model, or vector size changed.',
		'flavor-agent'
	),
	collection_name_changed: __(
		'Pattern index collection naming changed and needs a rebuild.',
		'flavor-agent'
	),
	collection_missing: __(
		'Pattern index collection is missing and needs a rebuild.',
		'flavor-agent'
	),
	collection_size_mismatch: __(
		'Pattern index collection vector size no longer matches the active embedding configuration.',
		'flavor-agent'
	),
	qdrant_url_changed: __( 'Qdrant endpoint changed.', 'flavor-agent' ),
	openai_endpoint_changed: __(
		'Embedding endpoint changed.',
		'flavor-agent'
	),
	pattern_registry_changed: __(
		'Registered patterns changed.',
		'flavor-agent'
	),
};

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
			label: __( 'Needs setup', 'flavor-agent' ),
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
			: __( 'Needs setup', 'flavor-agent' ),
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
	const optionsNode = guidelinesRoot.querySelector(
		'[data-guidelines-block-options]'
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
		! optionsNode ||
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

	const parsedOptions = parseJsonValue( optionsNode.textContent, [] );
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
		editingBlock = '';
		blockText.value = '';
		saveButton.textContent = __( 'Add Block Guideline', 'flavor-agent' );
		cancelButton.hidden = true;
		renderBlockSelect();
		blockSelect.value = '';
	};

	const startEditing = ( blockName ) => {
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
			editButton.addEventListener( 'click', () =>
				startEditing( blockName )
			);

			const removeButton = document.createElement( 'button' );
			removeButton.type = 'button';
			removeButton.className = 'button button-link-delete';
			removeButton.textContent = __( 'Remove', 'flavor-agent' );
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

				delete blockGuidelines[ blockName ];
				writeBlockGuidelines();
				renderBlockSelect();
				renderBlockList();

				if ( editingBlock === blockName ) {
					resetEditor();
				}

				renderNotice(
					noticeRoot,
					'success',
					__(
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
		const blockName =
			editingBlock || normalizeText( blockSelect.value ).trim();

		if ( ! blockName ) {
			renderNotice(
				noticeRoot,
				'error',
				__(
					'Choose a block before saving a block guideline.',
					'flavor-agent'
				)
			);
			return;
		}

		const guidelines = normalizeText( blockText.value ).trim();

		if ( ! guidelines ) {
			renderNotice(
				noticeRoot,
				'error',
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
		renderNotice(
			noticeRoot,
			'success',
			__(
				'Block guideline ready. Save Changes to persist.',
				'flavor-agent'
			)
		);
	} );

	cancelButton.addEventListener( 'click', () => {
		resetEditor();
		renderNotice( noticeRoot, '', '' );
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
				__(
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
			__( 'Guidelines exported.', 'flavor-agent' )
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
	const indexed = Math.max( 0, Number( payload?.indexed || 0 ) );
	const removed = Math.max( 0, Number( payload?.removed || 0 ) );
	const statusLabel = getPatternSyncStatusLabel(
		normalizeText(
			payload?.runtimeState?.status,
			normalizeText( payload?.status, 'ready' )
		)
	);

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

	const statusNode = root.querySelector( '#flavor-agent-sync-status' );
	const liveRegion = root.querySelector( '#flavor-agent-sync-live-region' );
	const syncPanel = root.querySelector( '[data-flavor-agent-sync-panel]' );

	const canSync =
		normalizeText( syncBody.dataset.patternPrerequisitesReady ) === '1';
	const prerequisiteCopy = syncBody.querySelector(
		'[data-pattern-prerequisite-copy]'
	);

	const updateButtonAccessibility = ( isBusy = false ) => {
		const isDisabled = isBusy || ! canSync;

		button.disabled = isDisabled;
		button.setAttribute( 'aria-disabled', isDisabled ? 'true' : 'false' );

		if ( canSync || ! prerequisiteCopy ) {
			button.removeAttribute( 'aria-describedby' );
			return;
		}

		if ( ! prerequisiteCopy.id ) {
			prerequisiteCopy.id = 'flavor-agent-sync-prerequisites';
		}

		button.setAttribute( 'aria-describedby', prerequisiteCopy.id );
	};

	const setBusy = ( isBusy ) => {
		updateButtonAccessibility( isBusy );
		spinner.classList.toggle( 'is-active', isBusy );
		setStatusText(
			statusNode,
			isBusy ? __( 'Syncing…', 'flavor-agent' ) : ''
		);
	};

	updateButtonAccessibility();

	button.addEventListener( 'click', async () => {
		if ( ! canSync ) {
			return;
		}

		if ( syncPanel ) {
			syncPanel.open = true;
		}

		setBusy( true );
		renderNotice( noticeRoot, '', '' );
		setStatusText(
			liveRegion,
			__( 'Syncing pattern catalog.', 'flavor-agent' )
		);

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
					normalizeText(
						payload?.message,
						__( 'Sync failed.', 'flavor-agent' )
					)
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
					: __( 'Sync failed.', 'flavor-agent' );

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
	initializeGuidelinesManager( root );

	return {
		root,
	};
}
