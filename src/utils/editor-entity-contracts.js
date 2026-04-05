import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { isPlainObject } from './type-guards';

const EMPTY_OBJECT = Object.freeze( {} );
const EMPTY_ARRAY = Object.freeze( [] );
const EMPTY_FORM = Object.freeze( { fields: [] } );
const authorField = Object.freeze( { id: 'author', label: 'Author' } );
const excerptField = Object.freeze( { id: 'excerpt', label: 'Excerpt' } );
const pageTitleField = Object.freeze( { id: 'title', label: 'Title' } );
const patternTitleField = Object.freeze( { id: 'title', label: 'Title' } );
const slugField = Object.freeze( { id: 'slug', label: 'Slug' } );
const statusField = Object.freeze( { id: 'status', label: 'Status' } );
const stickyField = Object.freeze( { id: 'sticky', label: 'Sticky' } );
const templateField = Object.freeze( { id: 'template', label: 'Template' } );
const templateTitleField = Object.freeze( {
	id: 'title',
	label: 'Template',
} );
const titleField = Object.freeze( { id: 'title', label: 'Title' } );

function normalizePostType( postType ) {
	return typeof postType === 'string' ? postType.trim() : '';
}

export function normalizeEditedEntityRef( entityId ) {
	if ( typeof entityId === 'string' && entityId.trim() !== '' ) {
		return entityId.trim();
	}

	if ( Number.isInteger( entityId ) && entityId > 0 ) {
		return String( entityId );
	}

	return null;
}

export function getEditedPostTypeEntity( select, expectedPostType = null ) {
	const editor = select( 'core/editor' );
	const currentPostType = normalizePostType( editor?.getCurrentPostType?.() );

	if ( currentPostType ) {
		if ( expectedPostType && currentPostType !== expectedPostType ) {
			return null;
		}

		const currentPostId = normalizeEditedEntityRef(
			editor?.getCurrentPostId?.()
		);

		if ( currentPostId ) {
			return {
				postType: currentPostType,
				entityId: currentPostId,
				source: 'core/editor',
			};
		}
	}

	const editSite = select( 'core/edit-site' );

	if ( ! editSite?.getEditedPostType || ! editSite?.getEditedPostId ) {
		return null;
	}

	const editedPostType = normalizePostType( editSite.getEditedPostType() );

	if ( ! editedPostType ) {
		return null;
	}

	if ( expectedPostType && editedPostType !== expectedPostType ) {
		return null;
	}

	const editedPostId = normalizeEditedEntityRef( editSite.getEditedPostId() );

	if ( ! editedPostId ) {
		return null;
	}

	return {
		postType: editedPostType,
		entityId: editedPostId,
		source: 'core/edit-site',
	};
}

export function getPostTypeFieldDefinitions( postType ) {
	switch ( normalizePostType( postType ) ) {
		case 'post':
			return [
				titleField,
				authorField,
				statusField,
				excerptField,
				stickyField,
				templateField,
				slugField,
			];
		case 'page':
			return [
				pageTitleField,
				authorField,
				statusField,
				templateField,
				slugField,
			];
		case 'wp_template':
			return [ templateTitleField, authorField, slugField ];
		case 'wp_template_part':
			return [ titleField, authorField, slugField ];
		case 'wp_block':
			return [ patternTitleField, authorField, statusField, slugField ];
		default:
			return [ titleField ];
	}
}

function isKnownPostType( postType ) {
	switch ( normalizePostType( postType ) ) {
		case 'post':
		case 'page':
		case 'wp_template':
		case 'wp_template_part':
		case 'wp_block':
			return true;
		default:
			return false;
	}
}

function hasViewConfigData( viewConfigRecord = EMPTY_OBJECT ) {
	return (
		isPlainObject( viewConfigRecord?.default_view ) ||
		isPlainObject( viewConfigRecord?.default_layouts ) ||
		Array.isArray( viewConfigRecord?.view_list ) ||
		isPlainObject( viewConfigRecord?.form )
	);
}

export function getPostTypeFieldMap( postType ) {
	return getPostTypeFieldDefinitions( postType ).reduce(
		( fieldMap, field ) => {
			if ( field?.id ) {
				fieldMap[ field.id ] = field;
			}

			return fieldMap;
		},
		{}
	);
}

export function normalizeViewConfigContract( viewConfig ) {
	return {
		defaultView: isPlainObject( viewConfig?.default_view )
			? viewConfig.default_view
			: EMPTY_OBJECT,
		defaultLayouts: isPlainObject( viewConfig?.default_layouts )
			? viewConfig.default_layouts
			: EMPTY_OBJECT,
		viewList: Array.isArray( viewConfig?.view_list )
			? viewConfig.view_list
			: EMPTY_ARRAY,
		form: isPlainObject( viewConfig?.form ) ? viewConfig.form : EMPTY_FORM,
	};
}

export function getLockedViewFilterValue( view, field ) {
	if ( typeof field !== 'string' || field === '' ) {
		return '';
	}

	const filters = Array.isArray( view?.view?.filters )
		? view.view.filters
		: EMPTY_ARRAY;
	const lockedFilter =
		filters.find(
			( filter ) => filter?.field === field && filter?.isLocked
		) || null;

	return typeof lockedFilter?.value === 'string' ? lockedFilter.value : '';
}

export function getLockedViewOptions( viewList = [], field ) {
	if ( ! Array.isArray( viewList ) ) {
		return [];
	}

	return viewList
		.map( ( view ) => {
			const value = getLockedViewFilterValue( view, field );

			if ( ! value ) {
				return null;
			}

			return {
				value,
				label:
					typeof view?.title === 'string' && view.title
						? view.title
						: value,
				slug:
					typeof view?.slug === 'string' && view.slug
						? view.slug
						: value,
			};
		} )
		.filter( Boolean );
}

export function buildOptionLabelMap( options = [] ) {
	return options.reduce( ( labelMap, option ) => {
		if ( option?.value && option?.label ) {
			labelMap[ option.value ] = option.label;
		}

		return labelMap;
	}, {} );
}

export function getRecommendedPatternCategorySlug( viewList = [] ) {
	if ( ! Array.isArray( viewList ) ) {
		return 'recommended';
	}

	const directSlugMatch = viewList.find(
		( view ) =>
			typeof view?.slug === 'string' && view.slug === 'recommended'
	);

	if ( directSlugMatch ) {
		return directSlugMatch.slug;
	}

	const titleMatch = viewList.find(
		( view ) =>
			typeof view?.title === 'string' &&
			view.title.trim().toLowerCase() === 'recommended' &&
			typeof view?.slug === 'string' &&
			view.slug
	);

	return titleMatch?.slug || 'recommended';
}

export function buildPostTypeEntityContract(
	postType,
	viewConfigRecord = EMPTY_OBJECT
) {
	const normalizedPostType = normalizePostType( postType );
	const viewConfig = normalizeViewConfigContract( viewConfigRecord );
	const fields = getPostTypeFieldDefinitions( normalizedPostType );
	const fieldMap = getPostTypeFieldMap( normalizedPostType );
	const titleFieldId =
		typeof viewConfig.defaultView?.titleField === 'string'
			? viewConfig.defaultView.titleField
			: fields[ 0 ]?.id || '';
	const resolvedTitleField = titleFieldId
		? fieldMap[ titleFieldId ] || null
		: null;
	const templatePartAreaOptions = getLockedViewOptions(
		viewConfig.viewList,
		'area'
	);

	return {
		postType: normalizedPostType,
		fields,
		fieldMap,
		titleField: resolvedTitleField,
		defaultView: viewConfig.defaultView,
		defaultLayouts: viewConfig.defaultLayouts,
		viewList: viewConfig.viewList,
		form: viewConfig.form,
		hasConfig:
			normalizedPostType !== '' &&
			( isKnownPostType( normalizedPostType ) ||
				hasViewConfigData( viewConfigRecord ) ),
		recommendedPatternCategory: getRecommendedPatternCategorySlug(
			viewConfig.viewList
		),
		templatePartAreaOptions,
		templatePartAreaLabels: buildOptionLabelMap( templatePartAreaOptions ),
	};
}

export function usePostTypeEntityContract( postType ) {
	const normalizedPostType = normalizePostType( postType );
	const viewConfigRecord = useSelect(
		( select ) => {
			if ( ! normalizedPostType ) {
				return EMPTY_OBJECT;
			}

			const coreSelect = select( 'core' );

			if ( ! coreSelect ) {
				return EMPTY_OBJECT;
			}

			return (
				coreSelect.getPostType?.( normalizedPostType ) ||
				coreSelect.getEntityRecord?.(
					'root',
					'postType',
					normalizedPostType
				) ||
				EMPTY_OBJECT
			);
		},
		[ normalizedPostType ]
	);

	return useMemo( () => {
		return buildPostTypeEntityContract(
			normalizedPostType,
			viewConfigRecord
		);
	}, [ normalizedPostType, viewConfigRecord ] );
}
