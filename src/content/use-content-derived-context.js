import { useMemo } from '@wordpress/element';

/**
 * Derives referentially-stable content-surface context from raw store values.
 *
 * Selection (`useSelect`) must return only stable store references and
 * primitives; this hook owns the derivation so the derived objects/arrays keep
 * a stable identity across re-renders when their inputs are unchanged. Doing
 * the `.filter()`/object construction inside `useSelect` instead would make it
 * return a new reference on every store tick, which trips the editor's
 * "useSelect returns different values" warning and churns downstream memos.
 *
 * @param {Object}      input             Raw, stable inputs.
 * @param {Array}       input.activityLog Full activity log (stable store ref).
 * @param {number|null} input.postId      Current post id.
 * @param {string}      input.postType    Current post type.
 * @param {string}      input.title       Edited post title.
 * @param {string}      input.excerpt     Edited post excerpt.
 * @param {string}      input.content     Edited post content.
 * @param {string}      input.slug        Edited post slug.
 * @param {string}      input.status      Edited post status.
 * @return {{ activityEntries: Array, postContext: Object }} Memoized derived context.
 */
export function useContentDerivedContext( {
	activityLog,
	postId,
	postType,
	title,
	excerpt,
	content,
	slug,
	status,
} ) {
	const activityEntries = useMemo(
		() =>
			( activityLog || [] )
				.filter( ( entry ) => entry?.surface === 'content' )
				.reverse(),
		[ activityLog ]
	);

	const postContext = useMemo(
		() => ( {
			postId,
			postType,
			title,
			excerpt,
			content,
			slug,
			status,
		} ),
		[ postId, postType, title, excerpt, content, slug, status ]
	);

	return { activityEntries, postContext };
}
