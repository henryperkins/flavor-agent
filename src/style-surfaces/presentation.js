import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { getToneLabel, SURFACE_TONES } from '../components/surface-labels';

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
		return sprintf(
			/* translators: %s: theme style variation title. */
			__( 'Switch to variation: %s', 'flavor-agent' ),
			operation.variationTitle
		);
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

	return __( 'Review this change before applying it.', 'flavor-agent' );
}

export function isInlineStyleNotice( notice ) {
	return notice?.source === 'apply' || notice?.source === 'undo';
}

export function formatStyleBadgeLabel( value = '' ) {
	return String( value )
		.replace( /[-_]+/g, ' ' )
		.replace( /\b\w/g, ( char ) => char.toUpperCase() );
}

/**
 * Map a style suggestion onto a shared surface tone token.
 *
 * Returns a token, never rendered text, so pill styling stays independent of
 * the active locale.
 *
 * @param {Object} suggestion Style suggestion.
 * @return {string} Tone token from `SURFACE_TONES`.
 */
export function getStyleSuggestionTone( suggestion ) {
	return suggestion?.tone === 'executable'
		? SURFACE_TONES.REVIEW
		: SURFACE_TONES.MANUAL;
}

export function getStyleSuggestionToneLabel( suggestion ) {
	return getToneLabel( getStyleSuggestionTone( suggestion ) );
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
	const reviewActionLabel = isSelected
		? __( 'Reviewing', 'flavor-agent' )
		: __( 'Review', 'flavor-agent' );
	const suggestionLabel =
		suggestion?.label || __( 'suggestion', 'flavor-agent' );

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
							{ __( 'Review open', 'flavor-agent' ) }
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
							aria-label={ sprintf(
								/* translators: 1: review action label. 2: suggestion label. */
								__( '%1$s %2$s', 'flavor-agent' ),
								reviewActionLabel,
								suggestionLabel
							) }
						>
							{ reviewActionLabel }
						</Button>
					</div>
				) }
			</div>
		</div>
	);
}
