import { Button } from '@wordpress/components';

import {
	MANUAL_IDEAS_LABEL,
	REVIEW_LANE_LABEL,
} from '../components/surface-labels';

function formatPath( path = [] ) {
	return Array.isArray( path ) ? path.join( '.' ) : '';
}

function getCanonicalPresetSlug( operation = {} ) {
	if ( typeof operation?.value === 'string' ) {
		const match = operation.value.match(
			/^var:preset\|[a-z0-9-]+\|([a-z0-9_-]+)$/i
		);

		if ( match?.[ 1 ] ) {
			return match[ 1 ];
		}
	}

	return typeof operation?.presetSlug === 'string'
		? operation.presetSlug
		: '';
}

export function formatStyleOperation( operation = {} ) {
	if ( operation?.type === 'set_theme_variation' ) {
		return `Switch to variation: ${ operation.variationTitle }`;
	}

	if (
		operation?.type === 'set_styles' ||
		operation?.type === 'set_block_styles'
	) {
		const pathLabel = formatPath( operation.path );
		const presetSlug = getCanonicalPresetSlug( operation );

		if ( presetSlug ) {
			return `${ pathLabel } → ${ presetSlug }`;
		}

		return `${ pathLabel } → ${ String( operation.value || '' ) }`;
	}

	return 'Review this change before applying it.';
}

export function isInlineStyleNotice( notice ) {
	return notice?.source === 'apply' || notice?.source === 'undo';
}

export function formatStyleBadgeLabel( value = '' ) {
	return String( value )
		.replace( /[-_]+/g, ' ' )
		.replace( /\b\w/g, ( char ) => char.toUpperCase() );
}

export function getStyleSuggestionToneLabel( suggestion ) {
	return suggestion?.tone === 'executable'
		? REVIEW_LANE_LABEL
		: MANUAL_IDEAS_LABEL;
}

export function StyleOperationList( {
	operations = [],
	compact = false,
	suggestionKey = '',
} ) {
	if ( operations.length === 0 ) {
		return null;
	}

	return (
		<ul
			className={ `flavor-agent-style-operations${
				compact ? ' flavor-agent-style-operations--compact' : ''
			}` }
		>
			{ operations.map( ( operation, index ) => (
				<li key={ `${ suggestionKey }-${ index }` }>
					{ formatStyleOperation( operation ) }
				</li>
			) ) }
		</ul>
	);
}

export function StyleSuggestionCard( {
	suggestion,
	isSelected = false,
	isStale = false,
	onReview,
	showSecondaryGuidance = false,
	executableGuidance = '',
	manualGuidance = '',
} ) {
	const secondaryGuidance =
		suggestion?.tone === 'executable' ? executableGuidance : manualGuidance;

	return (
		<div
			key={ suggestion?.suggestionKey }
			className={ `flavor-agent-card flavor-agent-style-card${
				isSelected ? ' flavor-agent-style-card--active' : ''
			}` }
		>
			<div className="flavor-agent-card__header flavor-agent-card__header--spaced">
				<div className="flavor-agent-card__lead">
					<div className="flavor-agent-card__label">
						{ suggestion?.label }
					</div>
					{ suggestion?.description && (
						<p className="flavor-agent-card__description">
							{ suggestion.description }
						</p>
					) }
				</div>
				<div className="flavor-agent-style-card__badges">
					<span className="flavor-agent-pill">
						{ getStyleSuggestionToneLabel( suggestion ) }
					</span>
					{ suggestion?.category && (
						<span className="flavor-agent-pill">
							{ formatStyleBadgeLabel( suggestion.category ) }
						</span>
					) }
					{ isSelected && (
						<span className="flavor-agent-pill flavor-agent-pill--success">
							Review open
						</span>
					) }
				</div>
			</div>

			<StyleOperationList
				operations={ suggestion?.operations || [] }
				compact
				suggestionKey={ suggestion?.suggestionKey || '' }
			/>

			<div className="flavor-agent-style-card__footer">
				{ showSecondaryGuidance && secondaryGuidance && (
					<span className="flavor-agent-panel__intro-copy">
						{ secondaryGuidance }
					</span>
				) }

				{ suggestion?.tone === 'executable' && (
					<div className="flavor-agent-style-card__actions">
						<Button
							variant="secondary"
							size="small"
							onClick={ () =>
								onReview( suggestion?.suggestionKey )
							}
							className="flavor-agent-card__apply"
							disabled={ isStale }
						>
							{ isSelected ? 'Reviewing' : 'Review' }
						</Button>
					</div>
				) }
			</div>
		</div>
	);
}
