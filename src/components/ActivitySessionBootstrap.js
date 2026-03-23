import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';

import { STORE_NAME } from '../store';
import { resolveActivityScope } from '../store/activity-history';

export default function ActivitySessionBootstrap() {
	const scopeHint = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const editSite = select( 'core/edit-site' );
		const postType =
			editor?.getCurrentPostType?.() ||
			editSite?.getEditedPostType?.() ||
			'';
		const postId =
			editor?.getCurrentPostId?.() || editSite?.getEditedPostId?.() || '';

		return resolveActivityScope( postType, postId )?.hint || '';
	}, [] );
	const { loadActivitySession } = useDispatch( STORE_NAME );

	useEffect( () => {
		loadActivitySession();
	}, [ loadActivitySession, scopeHint ] );

	return null;
}
