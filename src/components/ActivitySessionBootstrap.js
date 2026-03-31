import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';

import { STORE_NAME } from '../store';
import {
	resolveActivityScope,
	resolveGlobalStylesScope,
} from '../store/activity-history';

export default function ActivitySessionBootstrap() {
	const scope = useSelect( ( select ) => {
		const interfaceStore = select( 'core/interface' );
		const coreStore = select( 'core' );
		const activeComplementaryArea =
			interfaceStore?.getActiveComplementaryArea?.( 'core' ) || '';
		const globalStylesId =
			coreStore?.__experimentalGetCurrentGlobalStylesId?.() || '';

		if (
			activeComplementaryArea === 'edit-site/global-styles' &&
			globalStylesId
		) {
			return (
				resolveGlobalStylesScope( globalStylesId ) || {
					key: null,
					hint: '',
					postType: '',
					entityId: '',
				}
			);
		}

		const editor = select( 'core/editor' );
		const editSite = select( 'core/edit-site' );
		const postType =
			editor?.getCurrentPostType?.() ||
			editSite?.getEditedPostType?.() ||
			'';
		const postId =
			editor?.getCurrentPostId?.() || editSite?.getEditedPostId?.() || '';

		return (
			resolveActivityScope( postType, postId ) || {
				key: null,
				hint: '',
				postType: '',
				entityId: '',
			}
		);
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
