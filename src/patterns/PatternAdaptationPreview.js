/**
 * Presentational adapted-preview panel rendered inside the inserter portal.
 *
 * Renders the exact adapted block array via Gutenberg BlockPreview and exposes
 * Insert adapted / Insert original / Close. It owns no insertion, freshness, or
 * activity logic.
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

export default function PatternAdaptationPreview( {
	title = '',
	status = 'ready',
	changes = [],
	blocks = [],
	isStale = false,
	onInsertAdapted,
	onInsertOriginal,
	onClose,
} ) {
	const isReady = status === 'ready' && ! isStale;
	const uniqueReasons = [
		...new Set( changes.map( ( change ) => describeChange( change ) ) ),
	];

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

			{ isReady && uniqueReasons.length > 0 && (
				<ul className="flavor-agent-pattern-adaptation__changes">
					{ uniqueReasons.map( ( reason ) => (
						<li key={ reason }>{ reason }</li>
					) ) }
				</ul>
			) }

			{ isReady && ResolvedBlockPreview && (
				<div className="flavor-agent-pattern-adaptation__preview">
					<ResolvedBlockPreview
						blocks={ blocks }
						viewportWidth={ 800 }
					/>
				</div>
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
