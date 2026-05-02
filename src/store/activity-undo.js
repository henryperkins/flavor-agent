import {
	getGlobalStylesActivityUndoState,
	undoGlobalStyleSuggestionOperations,
} from '../utils/style-operations';
import { undoBlockStructuralSuggestionOperations } from '../utils/block-structural-actions';
import {
	getTemplateActivityUndoState,
	getTemplatePartActivityUndoState,
	undoTemplatePartSuggestionOperations,
	undoTemplateSuggestionOperations,
} from '../utils/template-actions';
import {
	BLOCK_TARGET_MOVED_ERROR,
	getBlockByPath,
	resolveActivityBlockTarget,
} from './block-targeting';
import {
	createActivityEntry,
	getActivityEntityKey,
	getBlockActivityUndoState,
	getPendingActivitySyncType,
	getResolvedActivityEntries,
} from './activity-history';
import {
	buildUndoAttributeUpdates,
	hasRecordedAttributeSnapshot,
	recordedAttributeSnapshotMatchesCurrent,
} from './update-helpers';
import {
	buildActivityPersistenceUpdate,
	buildActivityDocument,
	buildNonRetryableUndoSyncEntry,
	buildUndoAuditSyncError,
	fetchServerActivityEntries,
	isNonRetryableUndoSyncError,
	isServerBackedActivityEntry,
	isUndoSyncConflictError,
	mergeActivityEntries,
	persistActivitySession,
	persistActivityUndoTransition,
	reconcileActivityEntryFromServer,
	refreshActivitySession,
	shouldSyncUndoTransition,
	syncActivitySession,
} from './activity-session';

export function getEntityActivityEntries( activityLog, activity ) {
	const entityKey = getActivityEntityKey( activity );

	if ( ! entityKey ) {
		return [];
	}

	return activityLog.filter(
		( entry ) => getActivityEntityKey( entry ) === entityKey
	);
}

export function findBlockPath( blocks, clientId, path = [] ) {
	for ( let index = 0; index < blocks.length; index++ ) {
		const block = blocks[ index ];
		const nextPath = [ ...path, index ];

		if ( block?.clientId === clientId ) {
			return nextPath;
		}

		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			const nestedPath = findBlockPath(
				block.innerBlocks,
				clientId,
				nextPath
			);

			if ( nestedPath ) {
				return nestedPath;
			}
		}
	}

	return null;
}

function buildDocumentOperationBeforeState( operations = [] ) {
	return operations.map( ( operation ) => {
		switch ( operation?.type ) {
			case 'assign_template_part':
			case 'replace_template_part':
				return {
					type: operation.type,
					area: operation?.undoLocator?.area || operation?.area || '',
					expectedSlug:
						operation?.undoLocator?.expectedSlug ||
						operation?.nextAttributes?.slug ||
						operation?.slug ||
						'',
					previousAttributes: operation.previousAttributes || null,
				};

			case 'insert_pattern':
				return {
					type: operation.type,
					patternName: operation.patternName || '',
					patternTitle: operation.patternTitle || '',
					placement: operation.placement || '',
					targetPath: Array.isArray( operation.targetPath )
						? operation.targetPath
						: null,
					expectedTarget:
						operation.expectedTarget &&
						typeof operation.expectedTarget === 'object'
							? operation.expectedTarget
							: null,
					targetBlockName: operation.targetBlockName || '',
					rootLocator: operation.rootLocator || null,
					index: Number.isInteger( operation.index )
						? operation.index
						: null,
				};

			case 'replace_block_with_pattern':
			case 'remove_block':
				return {
					type: operation.type,
					patternName: operation.patternName || '',
					patternTitle: operation.patternTitle || '',
					expectedBlockName: operation.expectedBlockName || '',
					expectedTarget:
						operation.expectedTarget &&
						typeof operation.expectedTarget === 'object'
							? operation.expectedTarget
							: null,
					targetPath: Array.isArray( operation.targetPath )
						? operation.targetPath
						: null,
					rootLocator: operation.rootLocator || null,
					index: Number.isInteger( operation.index )
						? operation.index
						: null,
				};

			default:
				return {
					type: operation?.type || 'unknown',
				};
		}
	} );
}

export function buildBlockActivityEntry( {
	afterAttributes,
	beforeAttributes,
	blockContext,
	blockPath = null,
	clientId,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	scope = null,
	suggestion,
} ) {
	return createActivityEntry( {
		type: 'apply_suggestion',
		surface: 'block',
		target: {
			clientId,
			blockName: blockContext?.name || '',
			blockPath: Array.isArray( blockPath ) ? blockPath : [],
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			attributes: beforeAttributes,
		},
		after: {
			attributes: afterAttributes,
		},
		prompt: requestPrompt,
		requestRef: `block:${ clientId }:${ requestToken }`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

export function buildBlockStructuralActivityEntry( {
	blockContext,
	blockPath = null,
	clientId,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	result,
	scope = null,
	suggestion,
} ) {
	const operations = Array.isArray( result?.operations )
		? result.operations
		: [];

	return createActivityEntry( {
		type: 'apply_block_structural_suggestion',
		surface: 'block',
		target: {
			clientId,
			blockName: blockContext?.name || '',
			blockPath: Array.isArray( blockPath ) ? blockPath : [],
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			structuralSignature: result?.beforeSignature || '',
			operations: buildDocumentOperationBeforeState( operations ),
		},
		after: {
			structuralSignature: result?.afterSignature || '',
			operations,
		},
		prompt: requestPrompt,
		requestRef: `block:${ clientId }:${ requestToken }:structural`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

export function buildTemplateActivityEntry( {
	operations,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	scope = null,
	suggestion,
	templateRef,
} ) {
	return createActivityEntry( {
		type: 'apply_template_suggestion',
		surface: 'template',
		target: {
			templateRef,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			operations: buildDocumentOperationBeforeState( operations ),
		},
		after: { operations },
		prompt: requestPrompt,
		requestRef: `template:${ templateRef || 'unknown' }:${ requestToken }`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

export function buildTemplatePartActivityEntry( {
	operations,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	scope = null,
	suggestion,
	templatePartRef,
} ) {
	return createActivityEntry( {
		type: 'apply_template_part_suggestion',
		surface: 'template-part',
		target: {
			templatePartRef,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			operations: buildDocumentOperationBeforeState( operations ),
		},
		after: { operations },
		prompt: requestPrompt,
		requestRef: `template-part:${
			templatePartRef || 'unknown'
		}:${ requestToken }`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

export function buildGlobalStylesActivityEntry( {
	operations,
	beforeConfig,
	afterConfig,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	scope = null,
	suggestion,
	globalStylesId,
} ) {
	return createActivityEntry( {
		type: 'apply_global_styles_suggestion',
		surface: 'global-styles',
		target: {
			globalStylesId,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			userConfig: beforeConfig,
		},
		after: {
			userConfig: afterConfig,
			operations,
		},
		prompt: requestPrompt,
		requestRef: `global-styles:${
			globalStylesId || 'unknown'
		}:${ requestToken }`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

// Style-book undo only needs the targeted block's branch from styles.blocks.<blockName>.
// Storing the full user config inflates activity rows by 10-50 KB per entry; this trim
// keeps before/after small while remaining backwards-compatible with old (full) entries
// because every reader routes through readPath(config, ['styles', 'blocks', blockName]).
function trimStyleBookUserConfigToBlockBranch( config, blockName ) {
	if ( ! config || typeof config !== 'object' || ! blockName ) {
		return {};
	}

	const branch = config?.styles?.blocks?.[ blockName ];

	if ( branch === undefined ) {
		return {};
	}

	return {
		styles: {
			blocks: {
				[ blockName ]: branch,
			},
		},
	};
}

export function buildStyleBookActivityEntry( {
	operations,
	beforeConfig,
	afterConfig,
	requestPrompt = '',
	requestMeta = null,
	requestToken = 0,
	scope = null,
	suggestion,
	globalStylesId,
	blockName,
	blockTitle = '',
} ) {
	return createActivityEntry( {
		type: 'apply_style_book_suggestion',
		surface: 'style-book',
		target: {
			globalStylesId,
			blockName,
			blockTitle,
		},
		suggestion: suggestion?.label || '',
		suggestionKey: suggestion?.suggestionKey || null,
		before: {
			userConfig: trimStyleBookUserConfigToBlockBranch(
				beforeConfig,
				blockName
			),
		},
		after: {
			userConfig: trimStyleBookUserConfigToBlockBranch(
				afterConfig,
				blockName
			),
			operations,
		},
		prompt: requestPrompt,
		requestRef: `style-book:${ globalStylesId || 'unknown' }:${
			blockName || 'unknown'
		}:${ requestToken }`,
		requestMeta,
		document: buildActivityDocument( scope ),
	} );
}

export function buildTemplateActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildTemplateActivityEntry( {
		operations: result.operations,
		requestPrompt: select.getTemplateRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getTemplateResultToken?.() || 0,
		scope,
		suggestion,
		templateRef: select.getTemplateResultRef(),
	} );
}

export function buildTemplatePartActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildTemplatePartActivityEntry( {
		operations: result.operations,
		requestPrompt: select.getTemplatePartRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getTemplatePartResultToken?.() || 0,
		scope,
		suggestion,
		templatePartRef: select.getTemplatePartResultRef(),
	} );
}

export function buildGlobalStylesActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildGlobalStylesActivityEntry( {
		operations: result.operations,
		beforeConfig: result.beforeConfig,
		afterConfig: result.afterConfig,
		requestPrompt: select.getGlobalStylesRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getGlobalStylesResultToken?.() || 0,
		scope,
		suggestion,
		globalStylesId: result.globalStylesId,
	} );
}

export function buildStyleBookActivityEntryFromStore( {
	result,
	scope,
	select,
	suggestion,
} ) {
	return buildStyleBookActivityEntry( {
		operations: result.operations,
		beforeConfig: result.beforeConfig,
		afterConfig: result.afterConfig,
		requestPrompt: select.getStyleBookRequestPrompt?.() || '',
		requestMeta: suggestion?.requestMeta || null,
		requestToken: select.getStyleBookResultToken?.() || 0,
		scope,
		suggestion,
		globalStylesId: result.globalStylesId,
		blockName: scope?.blockName || '',
		blockTitle: scope?.blockTitle || '',
	} );
}

export function undoBlockActivity( activity, registry ) {
	const target = activity?.target || {};
	const blockEditorSelect = registry?.select?.( 'core/block-editor' ) || {};
	const blockEditorDispatch =
		registry?.dispatch?.( 'core/block-editor' ) || {};
	const beforeAttributes = activity?.before?.attributes || {};
	const afterAttributes = activity?.after?.attributes || {};

	if ( activity?.type === 'apply_block_structural_suggestion' ) {
		return undoBlockStructuralSuggestionOperations( activity, registry );
	}

	if (
		activity?.type !== 'apply_suggestion' ||
		! hasRecordedAttributeSnapshot( afterAttributes )
	) {
		return {
			ok: false,
			error: 'This block action is missing its recorded after state and cannot be undone automatically.',
		};
	}

	const resolvedTarget = resolveActivityBlockTarget(
		blockEditorSelect,
		target
	);
	let resolvedBlock = resolvedTarget.block;

	if ( ! resolvedBlock?.clientId ) {
		return {
			ok: false,
			error: 'The original block target for this AI action is missing.',
		};
	}

	let currentAttributes =
		blockEditorSelect.getBlockAttributes?.( resolvedBlock.clientId ) ||
		resolvedBlock.attributes ||
		null;

	if ( target.blockName && resolvedBlock.name !== target.blockName ) {
		return {
			ok: false,
			error: BLOCK_TARGET_MOVED_ERROR,
		};
	}

	if ( ! currentAttributes ) {
		return {
			ok: false,
			error: 'The target block is no longer available to undo.',
		};
	}

	if (
		! recordedAttributeSnapshotMatchesCurrent(
			afterAttributes,
			currentAttributes
		)
	) {
		const pathBlock = Array.isArray( target.blockPath )
			? getBlockByPath(
					blockEditorSelect.getBlocks?.() || [],
					target.blockPath
			  )
			: null;
		const pathAttributes = pathBlock?.clientId
			? blockEditorSelect.getBlockAttributes?.( pathBlock.clientId ) ||
			  pathBlock.attributes ||
			  null
			: null;

		if (
			pathBlock?.clientId &&
			( ! target.blockName || pathBlock.name === target.blockName ) &&
			recordedAttributeSnapshotMatchesCurrent(
				afterAttributes,
				pathAttributes
			)
		) {
			resolvedBlock = pathBlock;
			currentAttributes = pathAttributes;
		}
	}

	if (
		! recordedAttributeSnapshotMatchesCurrent(
			afterAttributes,
			currentAttributes
		)
	) {
		return {
			ok: false,
			error: 'The target block changed after Flavor Agent applied this suggestion and cannot be undone automatically.',
		};
	}

	if ( typeof blockEditorDispatch.updateBlockAttributes !== 'function' ) {
		return {
			ok: false,
			error: 'The block editor could not restore the previous block attributes.',
		};
	}

	blockEditorDispatch.updateBlockAttributes(
		resolvedBlock.clientId,
		buildUndoAttributeUpdates( beforeAttributes, afterAttributes )
	);

	return { ok: true };
}

export function getActivityRuntimeUndoResolver( surface, registry ) {
	const blockEditorSelect = registry?.select?.( 'core/block-editor' ) || {};

	switch ( surface ) {
		case 'template':
			return ( entry ) =>
				getTemplateActivityUndoState( entry, blockEditorSelect );
		case 'template-part':
			return ( entry ) =>
				getTemplatePartActivityUndoState( entry, blockEditorSelect );
		case 'global-styles':
		case 'style-book':
			return ( entry ) =>
				getGlobalStylesActivityUndoState( entry, registry );
		case 'block':
			return ( entry ) =>
				getBlockActivityUndoState( entry, blockEditorSelect );
		default:
			return null;
	}
}

export function getNextLastUndoneActivityId( currentValue, action ) {
	if ( action.status === 'success' ) {
		return action.activityId ?? null;
	}

	if ( action.status === 'idle' ) {
		return null;
	}

	return currentValue;
}

export function createUndoActivityAction( {
	getCurrentActivityScope,
	setActivitySession,
	setUndoState,
	updateActivityUndoState,
} ) {
	return function undoActivity( activityId ) {
		return async ( { dispatch: localDispatch, registry, select } ) => {
			const scope = getCurrentActivityScope( registry );

			syncActivitySession(
				localDispatch,
				select,
				scope,
				setActivitySession
			);
			let activityLog = select.getActivityLog?.() || [];
			let activity =
				activityLog.find( ( entry ) => entry?.id === activityId ) ||
				null;

			if ( ! activity ) {
				localDispatch(
					setUndoState(
						'error',
						'There is no AI action available to undo.'
					)
				);

				return {
					ok: false,
					error: 'There is no AI action available to undo.',
				};
			}

			const scopeKey =
				activity?.document?.scopeKey ||
				buildActivityDocument( scope )?.scopeKey ||
				select.getActivityScopeKey?.() ||
				null;
			const reconcileUndoConflict = async ( syncError, timestamp ) => {
				if ( ! isUndoSyncConflictError( syncError ) || ! scopeKey ) {
					return null;
				}

				try {
					const reconciledEntry =
						await reconcileActivityEntryFromServer(
							activity,
							scopeKey
						);

					if ( ! reconciledEntry ) {
						return null;
					}

					const reconciledEntries = mergeActivityEntries(
						select.getActivityLog?.() || [],
						[ reconciledEntry ]
					);

					refreshActivitySession(
						localDispatch,
						scopeKey,
						reconciledEntries,
						setActivitySession
					);

					const reconciledUndo = reconciledEntry?.undo || {};

					if ( reconciledUndo.status === 'undone' ) {
						return {
							ok: true,
							timestamp:
								reconciledUndo.updatedAt ||
								reconciledUndo.undoneAt ||
								timestamp,
							entry: reconciledEntry,
						};
					}

					if ( reconciledUndo.status === 'failed' ) {
						return {
							ok: false,
							timestamp: reconciledUndo.updatedAt || timestamp,
							error:
								reconciledUndo.error ||
								'This AI action can no longer be undone automatically.',
							entry: reconciledEntry,
						};
					}
				} catch {
					return null;
				}

				return null;
			};

			if ( scopeKey && isServerBackedActivityEntry( activity ) ) {
				try {
					const serverEntries =
						await fetchServerActivityEntries( scopeKey );
					const refreshedEntries = mergeActivityEntries(
						serverEntries,
						activityLog.filter(
							( entry ) => entry?.persistence?.status !== 'server'
						)
					);

					refreshActivitySession(
						localDispatch,
						scopeKey,
						refreshedEntries,
						setActivitySession
					);
					activityLog = refreshedEntries;
					activity =
						activityLog.find(
							( entry ) => entry?.id === activityId
						) || null;

					if ( ! activity ) {
						localDispatch(
							setUndoState(
								'error',
								'There is no AI action available to undo.'
							)
						);

						return {
							ok: false,
							error: 'There is no AI action available to undo.',
						};
					}
				} catch {
					// Fall back to the local activity cache when the server is unavailable.
				}
			}

			const entityEntries = getEntityActivityEntries(
				activityLog,
				activity
			);
			const runtimeUndoResolver = getActivityRuntimeUndoResolver(
				activity?.surface,
				registry
			);
			const resolvedActivity =
				getResolvedActivityEntries(
					entityEntries,
					runtimeUndoResolver
				).find( ( entry ) => entry?.id === activityId ) || null;
			const currentPendingSyncType =
				getPendingActivitySyncType( activity );
			const buildUndoTransitionEntry = (
				status,
				error = null,
				timestamp = new Date().toISOString()
			) => ( {
				...activity,
				undo: {
					...( activity.undo || {} ),
					canUndo: false,
					status,
					error,
					updatedAt: timestamp,
					undoneAt:
						status === 'undone'
							? timestamp
							: activity?.undo?.undoneAt || null,
				},
			} );
			const syncUndoStateChange = async ( status, error = null ) => {
				const timestamp = new Date().toISOString();
				const persistence = shouldSyncUndoTransition( activity )
					? buildActivityPersistenceUpdate(
							'server',
							null,
							timestamp
					  )
					: buildActivityPersistenceUpdate(
							'local',
							currentPendingSyncType || 'create',
							timestamp
					  );

				localDispatch(
					updateActivityUndoState(
						activityId,
						status,
						error,
						timestamp,
						persistence
					)
				);

				if ( ! shouldSyncUndoTransition( activity ) ) {
					persistActivitySession( select );

					return {
						ok: true,
						timestamp,
					};
				}

				try {
					await persistActivityUndoTransition(
						buildUndoTransitionEntry( status, error, timestamp )
					);
					persistActivitySession( select );

					return {
						ok: true,
						timestamp,
					};
				} catch ( syncError ) {
					const reconciledResult = await reconcileUndoConflict(
						syncError,
						timestamp
					);

					if ( reconciledResult ) {
						return reconciledResult;
					}

					if ( isNonRetryableUndoSyncError( activity, syncError ) ) {
						const terminalEntry = buildNonRetryableUndoSyncEntry(
							activity,
							syncError,
							timestamp
						);

						localDispatch(
							updateActivityUndoState(
								activityId,
								terminalEntry.undo.status,
								terminalEntry.undo.error,
								terminalEntry.undo.updatedAt,
								terminalEntry.persistence
							)
						);
						persistActivitySession( select );

						return {
							ok: false,
							timestamp,
							error: terminalEntry.undo.error,
						};
					}

					localDispatch(
						updateActivityUndoState(
							activityId,
							status,
							error,
							timestamp,
							buildActivityPersistenceUpdate(
								'local',
								'undo',
								timestamp
							)
						)
					);
					persistActivitySession( select );

					return {
						ok: false,
						timestamp,
					};
				}
			};
			const resolvedUndo = resolvedActivity?.undo || activity?.undo || {};

			if ( resolvedUndo?.status === 'undone' ) {
				return {
					ok: true,
					alreadyUndone: true,
				};
			}

			if ( resolvedUndo?.status === 'failed' ) {
				const failureMessage =
					resolvedUndo?.error ||
					'This AI action can no longer be undone automatically.';
				const syncResult = await syncUndoStateChange(
					'failed',
					failureMessage
				);
				const surfacedError = syncResult.ok
					? failureMessage
					: syncResult.error ||
					  buildUndoAuditSyncError( failureMessage );

				localDispatch(
					setUndoState( 'error', surfacedError, activityId )
				);

				return {
					ok: false,
					error: surfacedError,
				};
			}

			if (
				resolvedUndo?.canUndo !== true ||
				resolvedUndo?.status !== 'available'
			) {
				localDispatch(
					setUndoState(
						'error',
						resolvedUndo?.error ||
							'This AI action can no longer be undone automatically.',
						activityId
					)
				);

				return {
					ok: false,
					error:
						resolvedUndo?.error ||
						'This AI action can no longer be undone automatically.',
				};
			}

			localDispatch( setUndoState( 'undoing', null, activityId ) );

			let result;

			if ( activity.surface === 'template' ) {
				result = undoTemplateSuggestionOperations( activity );
			} else if ( activity.surface === 'template-part' ) {
				result = undoTemplatePartSuggestionOperations( activity );
			} else if (
				activity.surface === 'global-styles' ||
				activity.surface === 'style-book'
			) {
				result = undoGlobalStyleSuggestionOperations(
					activity,
					registry
				);
			} else {
				result = undoBlockActivity( activity, registry );
			}

			if ( ! result.ok ) {
				const failureMessage = result.error || 'Undo failed.';
				const syncResult = await syncUndoStateChange(
					'failed',
					failureMessage
				);
				const surfacedError = syncResult.ok
					? failureMessage
					: syncResult.error ||
					  buildUndoAuditSyncError( failureMessage );

				localDispatch(
					setUndoState( 'error', surfacedError, activityId )
				);

				return {
					...result,
					error: surfacedError,
				};
			}

			const syncResult = await syncUndoStateChange( 'undone' );

			if ( ! syncResult.ok ) {
				const surfacedError =
					syncResult.error ||
					buildUndoAuditSyncError( 'Undo applied locally.' );

				localDispatch(
					setUndoState( 'error', surfacedError, activityId )
				);

				return {
					...result,
					ok: false,
					error: surfacedError,
				};
			}

			localDispatch( setUndoState( 'success', null, activityId ) );

			return result;
		};
	};
}
