import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';

import { getCurrentGlobalStylesId } from '../global-styles/selectors';
import { getStyleBookUiState, subscribeToStyleBookUi } from '../style-book/dom';
import { STORE_NAME } from '../store';
import {
	resolveActivityScope,
	resolveGlobalStylesScope,
	resolveStyleBookScope,
	readPersistedActivityLog,
	writePersistedActivityLog,
} from '../store/activity-history';
import { installSavePersistenceObserver } from '../store/save-persistence';

export default function ActivitySessionBootstrap() {
	const registry = useRegistry();
	const { loadActivitySession, setActivitySession } =
		useDispatch( STORE_NAME );
	useEffect(
		() =>
			installSavePersistenceObserver( {
				selectCore: () => registry.select( 'core' ),
				onReconcile: ( entries ) => {
					const store = registry.select( STORE_NAME );
					const currentScope = store.getActivityScopeKey?.();
					const updates = new Map(
						entries.map(
							( { id, document: entryDocument, ...facts } ) => [
								id,
								facts,
							]
						)
					);
					const scopes = new Set(
						entries
							.map( ( entry ) => entry.document?.scopeKey )
							.filter( Boolean )
					);
					for ( const scopeKey of scopes ) {
						const current =
							scopeKey === currentScope
								? store.getActivityLog?.() || []
								: readPersistedActivityLog( scopeKey );
						const next = current.map( ( entry ) =>
							updates.has( entry.id )
								? { ...entry, ...updates.get( entry.id ) }
								: entry
						);
						writePersistedActivityLog( scopeKey, next );
						if ( scopeKey === currentScope ) {
							setActivitySession( scopeKey, next );
						}
					}
				},
			} ),
		[ registry, setActivitySession ]
	);
	const [ styleBookUiState, setStyleBookUiState ] = useState( () =>
		typeof document === 'undefined'
			? {
					isActive: false,
					target: null,
			  }
			: getStyleBookUiState( document )
	);
	const editorState = useSelect( ( select ) => {
		const interfaceStore = select( 'core/interface' );
		const coreStore = select( 'core' );
		const editor = select( 'core/editor' );
		const editSite = select( 'core/edit-site' );

		return {
			activeComplementaryArea:
				interfaceStore?.getActiveComplementaryArea?.( 'core' ) || '',
			globalStylesId: getCurrentGlobalStylesId( coreStore ) || '',
			postType:
				editor?.getCurrentPostType?.() ||
				editSite?.getEditedPostType?.() ||
				'',
			postId:
				editor?.getCurrentPostId?.() ||
				editSite?.getEditedPostId?.() ||
				'',
		};
	}, [] );
	const fallbackScope = { key: null, hint: '', postType: '', entityId: '' };
	let scope;
	if (
		editorState.activeComplementaryArea === 'edit-site/global-styles' &&
		editorState.globalStylesId
	) {
		scope =
			styleBookUiState?.isActive && styleBookUiState?.target?.blockName
				? resolveStyleBookScope(
						editorState.globalStylesId,
						styleBookUiState.target.blockName,
						{
							blockTitle:
								styleBookUiState.target.blockTitle || '',
						}
				  ) || fallbackScope
				: resolveGlobalStylesScope( editorState.globalStylesId ) ||
				  fallbackScope;
	} else {
		scope =
			resolveActivityScope( editorState.postType, editorState.postId ) ||
			fallbackScope;
	}

	useEffect( () => {
		if (
			editorState.activeComplementaryArea !== 'edit-site/global-styles' ||
			typeof document === 'undefined'
		) {
			return undefined;
		}

		return subscribeToStyleBookUi( document, setStyleBookUiState );
	}, [ editorState.activeComplementaryArea ] );
	const previousScope = useRef( scope );
	const scopeKey = scope?.key ?? null;
	const scopeHint = scope?.hint ?? '';
	const scopePostType = scope?.postType ?? '';
	const scopeEntityId = scope?.entityId ?? '';
	const scopeEntityKind = scope?.entityKind ?? '';
	const scopeEntityName = scope?.entityName ?? '';
	const scopeStylesheet = scope?.stylesheet ?? '';
	const scopeGlobalStylesId = scope?.globalStylesId ?? '';
	const scopeBlockName = scope?.blockName ?? '';
	const scopeBlockTitle = scope?.blockTitle ?? '';

	useEffect( () => {
		const allowUnsavedMigration =
			previousScope.current?.key === null &&
			previousScope.current?.hint?.endsWith?.( ':__unsaved__' ) &&
			scopeKey !== null &&
			scopePostType !== '' &&
			scopePostType === previousScope.current?.postType;

		previousScope.current = {
			key: scopeKey,
			hint: scopeHint,
			postType: scopePostType,
			entityId: scopeEntityId,
		};

		loadActivitySession( {
			allowUnsavedMigration,
			scope: {
				key: scopeKey,
				hint: scopeHint,
				postType: scopePostType,
				entityId: scopeEntityId,
				entityKind: scopeEntityKind,
				entityName: scopeEntityName,
				stylesheet: scopeStylesheet,
				globalStylesId: scopeGlobalStylesId,
				blockName: scopeBlockName,
				blockTitle: scopeBlockTitle,
			},
		} );
	}, [
		scopeBlockName,
		scopeBlockTitle,
		scopeEntityId,
		scopeEntityKind,
		scopeEntityName,
		scopeGlobalStylesId,
		loadActivitySession,
		scopeHint,
		scopeKey,
		scopePostType,
		scopeStylesheet,
	] );

	return null;
}
