/**
 * Presentational adapted-preview panel rendered inside the inserter portal.
 *
 * Renders the exact original and adapted block arrays via Gutenberg
 * `BlockPreview`, plus a deterministic per-change summary. It owns no
 * insertion, freshness, or activity logic.
 */
import * as blockEditor from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const ResolvedBlockPreview =
	blockEditor.BlockPreview || blockEditor.__experimentalBlockPreview;

const CHANGE_REASON_LABELS = {
	nearby_heading_hierarchy: __(
		'Heading level matched to nearby headings',
		'flavor-agent'
	),
	match_container_alignment: __(
		'Alignment matched to the container',
		'flavor-agent'
	),
	theme_color_alignment: __(
		'Colors aligned to theme presets',
		'flavor-agent'
	),
	theme_spacing_alignment: __(
		'Spacing aligned to theme presets',
		'flavor-agent'
	),
	theme_button_style: __(
		'Button style matched to the theme',
		'flavor-agent'
	),
};

function describeChange( change ) {
	return (
		CHANGE_REASON_LABELS[ change?.reason ] ||
		__( 'Cosmetic adjustment', 'flavor-agent' )
	);
}

function isStructuredValue( value ) {
	return !! value && typeof value === 'object';
}

function formatChangeValue( value ) {
	if ( value === null || value === undefined ) {
		return __( 'none', 'flavor-agent' );
	}

	if ( value === '' ) {
		return __( 'empty', 'flavor-agent' );
	}

	if ( [ 'string', 'number', 'boolean' ].includes( typeof value ) ) {
		return String( value );
	}

	if ( Array.isArray( value ) ) {
		return value.length === 0
			? __( 'empty', 'flavor-agent' )
			: __( 'updated', 'flavor-agent' );
	}

	return Object.keys( value ).length === 0
		? __( 'empty', 'flavor-agent' )
		: __( 'updated', 'flavor-agent' );
}

function collectChangedLeafRows( from, to, pathSegments = [] ) {
	if ( Object.is( from, to ) ) {
		return [];
	}

	if ( Array.isArray( from ) || Array.isArray( to ) ) {
		const fromArray = Array.isArray( from ) ? from : [];
		const toArray = Array.isArray( to ) ? to : [];
		const rows = [];
		const length = Math.max( fromArray.length, toArray.length );

		for ( let index = 0; index < length; index += 1 ) {
			rows.push(
				...collectChangedLeafRows(
					fromArray[ index ],
					toArray[ index ],
					[ ...pathSegments, String( index ) ]
				)
			);
		}

		return rows.length > 0 ? rows : [ { pathSegments, from, to } ];
	}

	if ( isStructuredValue( from ) || isStructuredValue( to ) ) {
		const fromObject =
			isStructuredValue( from ) && ! Array.isArray( from ) ? from : {};
		const toObject =
			isStructuredValue( to ) && ! Array.isArray( to ) ? to : {};
		const rows = [];
		const keys = new Set( [
			...Object.keys( fromObject ),
			...Object.keys( toObject ),
		] );

		for ( const key of keys ) {
			rows.push(
				...collectChangedLeafRows( fromObject[ key ], toObject[ key ], [
					...pathSegments,
					key,
				] )
			);
		}

		return rows.length > 0 ? rows : [ { pathSegments, from, to } ];
	}

	return [ { pathSegments, from, to } ];
}

function normalizeChangeRows( changes ) {
	return changes.flatMap( ( change ) => {
		const attributePath =
			typeof change?.attribute === 'string' ? change.attribute : '';
		const pathPrefix = attributePath ? [ attributePath ] : [];
		const rows = collectChangedLeafRows(
			change?.from,
			change?.to,
			pathPrefix
		);

		return rows.map( ( row ) => ( {
			reason: describeChange( change ),
			blockName:
				change?.blockName || __( 'Unknown block', 'flavor-agent' ),
			attributePath:
				row.pathSegments.length > 0
					? row.pathSegments.join( '.' )
					: attributePath || __( 'change', 'flavor-agent' ),
			from: formatChangeValue( row.from ),
			to: formatChangeValue( row.to ),
		} ) );
	} );
}

export default function PatternAdaptationPreview( {
	title = '',
	status = 'ready',
	changes = [],
	originalBlocks = [],
	adaptedBlocks = [],
	isStale = false,
	onInsertAdapted,
	onInsertOriginal,
	onClose,
} ) {
	const isReady = status === 'ready' && ! isStale;
	const changeRows = normalizeChangeRows( changes );
	const hasComparePreview =
		isReady && ( originalBlocks.length > 0 || adaptedBlocks.length > 0 );

	return (
		<div className="flavor-agent-pattern-adaptation">
			<div className="flavor-agent-pattern-adaptation__header">
				<span className="flavor-agent-pill flavor-agent-pill--lane">
					{ __( 'Adapted preview', 'flavor-agent' ) }
				</span>
				<span className="flavor-agent-pattern-adaptation__title">
					{ title }
				</span>
			</div>

			{ isStale && (
				<p
					className="flavor-agent-pattern-adaptation__status"
					role="status"
				>
					{ __(
						'The insertion point changed, so this adapted preview is out of date. Insert the original or close and try again.',
						'flavor-agent'
					) }
				</p>
			) }

			{ status === 'blocked' && ! isStale && (
				<p
					className="flavor-agent-pattern-adaptation__status"
					role="status"
				>
					{ __(
						'Flavor Agent could not build a safe adaptation for this pattern. You can still insert the original.',
						'flavor-agent'
					) }
				</p>
			) }

			{ hasComparePreview && (
				<div
					className="flavor-agent-pattern-adaptation__compare"
					role="group"
					aria-label={ __( 'Pattern comparison', 'flavor-agent' ) }
				>
					<div
						className="flavor-agent-pattern-adaptation__panel flavor-agent-pattern-adaptation__panel--original"
						role="group"
						aria-label={ __( 'Original pattern', 'flavor-agent' ) }
					>
						<span className="flavor-agent-pattern-adaptation__panel-title">
							{ __( 'Original pattern', 'flavor-agent' ) }
						</span>
						{ ResolvedBlockPreview && originalBlocks.length > 0 && (
							<div className="flavor-agent-pattern-adaptation__preview">
								<ResolvedBlockPreview
									blocks={ originalBlocks }
									viewportWidth={ 800 }
								/>
							</div>
						) }
					</div>
					<div
						className="flavor-agent-pattern-adaptation__panel flavor-agent-pattern-adaptation__panel--adapted"
						role="group"
						aria-label={ __( 'Adapted result', 'flavor-agent' ) }
					>
						<span className="flavor-agent-pattern-adaptation__panel-title">
							{ __( 'Adapted result', 'flavor-agent' ) }
						</span>
						{ ResolvedBlockPreview && adaptedBlocks.length > 0 && (
							<div className="flavor-agent-pattern-adaptation__preview">
								<ResolvedBlockPreview
									blocks={ adaptedBlocks }
									viewportWidth={ 800 }
								/>
							</div>
						) }
					</div>
				</div>
			) }

			{ isReady && changeRows.length > 0 && (
				<ul className="flavor-agent-pattern-adaptation__changes">
					{ changeRows.map( ( row, index ) => (
						<li
							key={ [
								row.reason,
								row.blockName,
								row.attributePath,
								index,
							].join( ':' ) }
						>
							<span>{ row.reason }</span>
							{ ' - ' }
							<span>{ row.blockName }</span>
							{ ' - ' }
							<span className="flavor-agent-pattern-adaptation__change-path">
								{ row.attributePath }
							</span>
							{ ' - ' }
							<span className="flavor-agent-pattern-adaptation__change-value">
								{ row.from }
							</span>
							{ ' -> ' }
							<span className="flavor-agent-pattern-adaptation__change-value">
								{ row.to }
							</span>
						</li>
					) ) }
				</ul>
			) }

			<div className="flavor-agent-pattern-adaptation__actions">
				<Button
					variant="primary"
					size="small"
					disabled={ ! isReady }
					onClick={ onInsertAdapted }
					aria-label={ sprintf(
						/* translators: %s: pattern title. */
						__( 'Insert adapted %s', 'flavor-agent' ),
						title
					) }
				>
					{ __( 'Insert adapted', 'flavor-agent' ) }
				</Button>
				<Button
					variant="secondary"
					size="small"
					onClick={ onInsertOriginal }
				>
					{ __( 'Insert original', 'flavor-agent' ) }
				</Button>
				<Button variant="tertiary" size="small" onClick={ onClose }>
					{ __( 'Close', 'flavor-agent' ) }
				</Button>
			</div>
		</div>
	);
}
