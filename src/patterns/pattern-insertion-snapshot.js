import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';

import { buildContextSignature } from '../utils/context-signature';
import { getEditorBlockIdentity } from '../utils/editor-block-identity';
import { buildPatternInsertionTargetSignature } from '../utils/recommendation-request-signature';
import { normalizeTemplateType } from '../utils/template-types';
import { buildPatternAdaptationContext } from './pattern-adaptation-context';
import {
	buildInsertionContext,
	getNonEmptyString,
} from './use-pattern-insertion-context';

/**
 * Compare catalog content without the fresh client IDs created by parsing it.
 *
 * @param {Array} sourceBlocks Parsed pattern blocks.
 */
export function getPatternSourceSignature( sourceBlocks ) {
	const normalizeBlock = ( block, path ) => ( {
		...block,
		clientId: path.join( '-' ),
		innerBlocks: ( block.innerBlocks || [] ).map( ( child, index ) =>
			normalizeBlock( child, [ ...path, index ] )
		),
	} );
	return buildContextSignature(
		sourceBlocks.map( ( block, index ) =>
			getEditorBlockIdentity( normalizeBlock( block, [ index ] ) )
		)
	);
}

/**
 * Read selectors synchronously, including changes not yet reflected in React.
 *
 * @param {Object} registry Live editor data registry.
 */
export function getPatternInsertionSnapshot( registry ) {
	const editor = registry.select( editorStore );
	const editSite = registry.select( 'core/edit-site' );
	const blocks = registry.select( blockEditorStore );
	const point = blocks?.getBlockInsertionPoint?.() || null;
	const inserterRootClientId = point?.rootClientId ?? null;
	const insertionIndex = point?.index;
	const postType =
		getNonEmptyString( editor?.getCurrentPostType?.() ) ||
		( editSite?.getEditedPostType?.() === 'wp_template'
			? 'wp_template'
			: '' );
	const templateType =
		editSite?.getEditedPostType?.() === 'wp_template'
			? normalizeTemplateType( editSite.getEditedPostId?.() )
			: undefined;
	const insertionContext = point
		? buildInsertionContext( blocks, inserterRootClientId, point )
		: null;
	const targetSignature = buildPatternInsertionTargetSignature( {
		postType,
		templateType,
		inserterRootClientId,
		insertionIndex,
		insertionContext,
	} );
	const settings = blocks?.getSettings?.() || {};

	return {
		targetSignature,
		scopeSignature: buildContextSignature( {
			postType,
			postId: editor?.getCurrentPostId?.() || null,
			editSitePostType: editSite?.getEditedPostType?.() || null,
			editSitePostId: editSite?.getEditedPostId?.() || null,
		} ),
		signature: buildContextSignature( {
			targetSignature,
			postId: editor?.getCurrentPostId?.() || null,
			editSitePostId: editSite?.getEditedPostId?.() || null,
			isInserterOpen: editor?.isInserterOpened?.() || false,
			blocks: ( blocks?.getBlocks?.() || [] ).map(
				getEditorBlockIdentity
			),
			rootBlocks: (
				blocks?.getBlocks?.( inserterRootClientId ) || []
			).map( getEditorBlockIdentity ),
			settings: {
				allowedBlockTypes: settings.allowedBlockTypes,
				templateLock: settings.templateLock,
				canLockBlocks: settings.canLockBlocks,
				supportsLayout: settings.supportsLayout,
				features: settings.__experimentalFeatures,
				resolvedFeatures: settings.features,
				layout: settings.layout,
				colors: settings.colors,
				gradients: settings.gradients,
				fontSizes: settings.fontSizes,
			},
			adaptationContext: buildPatternAdaptationContext( blocks, {
				inserterRootClientId,
				insertionIndex,
			} ),
		} ),
	};
}
