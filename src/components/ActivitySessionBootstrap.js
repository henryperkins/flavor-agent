import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';

import { getStyleBookUiState, subscribeToStyleBookUi } from '../style-book/dom';
import { STORE_NAME } from '../store';
import {
	resolveActivityScope,
	resolveGlobalStylesScope,
	resolveStyleBookScope,
} from '../store/activity-history';

export default function ActivitySessionBootstrap() {
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
			globalStylesId:
				coreStore?.__experimentalGetCurrentGlobalStylesId?.() || '',
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
		if ( typeof document === 'undefined' ) {
			return undefined;
		}

		return subscribeToStyleBookUi( document, setStyleBookUiState );
	}, [] );
	const { loadActivitySession } = useDispatch( STORE_NAME );
	const previousScope = useRef( scope );
	const scopeKey = scope?.key ?? null;
	const scopeHint = scope?.hint ?? '';
	const scopePostType = scope?.postType ?? '';
	const scopeEntityId = scope?.entityId ?? '';

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
		} );
	}, [
		loadActivitySession,
		scopeEntityId,
		scopeHint,
		scopeKey,
		scopePostType,
	] );

	return null;
}
