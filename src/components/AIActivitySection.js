import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { isDiagnosticActivityEntry } from '../store/activity-history';
import { formatCount } from '../utils/format-count';
import { truncateActivityTitle } from '../utils/activity-title';

const DEFAULT_SECTION_TITLE = __( 'Recent AI Actions', 'flavor-agent' );

/**
 * Diagnostic-entry descriptions keyed by surface. Surface keys are stable
 * tokens, so translating the description can never change lookup behavior.
 *
 * @type {Object<string, string>}
 */
const DIAGNOSTIC_SURFACE_DESCRIPTIONS = Object.freeze( {
	content: __( 'Content request diagnostic', 'flavor-agent' ),
	navigation: __( 'Navigation request diagnostic', 'flavor-agent' ),
	pattern: __( 'Pattern request diagnostic', 'flavor-agent' ),
	template: __( 'Template request diagnostic', 'flavor-agent' ),
	'template-part': __( 'Template part request diagnostic', 'flavor-agent' ),
	'post-blocks': __(
		'Post content structure request diagnostic',
		'flavor-agent'
	),
	'global-styles': __( 'Global Styles request diagnostic', 'flavor-agent' ),
	'style-book': __( 'Style Book request diagnostic', 'flavor-agent' ),
} );

/**
 * Applied-entry descriptions keyed by surface.
 *
 * @type {Object<string, string>}
 */
const SURFACE_ACTION_DESCRIPTIONS = Object.freeze( {
	template: __( 'Template action', 'flavor-agent' ),
	'template-part': __( 'Template part action', 'flavor-agent' ),
	'post-blocks': __( 'Post content action', 'flavor-agent' ),
	'global-styles': __( 'Global Styles action', 'flavor-agent' ),
} );

function getRequestMeta( entry ) {
	const requestMeta = entry?.request?.ai;

	return requestMeta &&
		typeof requestMeta === 'object' &&
		! Array.isArray( requestMeta )
		? requestMeta
		: null;
}

function getExecutionSummary( entry ) {
	const requestMeta = getRequestMeta( entry );

	if ( ! requestMeta ) {
		return '';
	}

	const parts = [
		requestMeta.backendLabel ||
			requestMeta.providerLabel ||
			requestMeta.provider,
		requestMeta.model,
	].filter( Boolean );

	return parts.join( ' · ' );
}

function getExternalApply( entry ) {
	const apply = entry?.apply || entry?.request?.apply;

	return apply && typeof apply === 'object' && ! Array.isArray( apply )
		? apply
		: null;
}

function getExternalApplyStatus( entry ) {
	const applyStatus = getExternalApply( entry )?.status;

	if ( typeof applyStatus === 'string' && applyStatus.trim() ) {
		return applyStatus.trim();
	}

	const executionResult =
		typeof entry?.executionResult === 'string'
			? entry.executionResult.trim()
			: '';

	return [ 'pending', 'rejected', 'expired' ].includes( executionResult )
		? executionResult
		: '';
}

/**
 * Map an activity entry onto a status pill.
 *
 * `tone` is a stable token rendered as `flavor-agent-pill--{tone}`, never
 * translated text — same rule as `SURFACE_TONES` in `surface-labels.js`. This
 * lane vocabulary adds `error`/`success`, which the recommendation lanes have
 * no use for; keep the two lists apart rather than mixing tokens across them.
 *
 * @param {Object} entry Activity entry.
 * @return {{label: string, tone: string}} Pill label and tone token.
 */
function getStatusLabel( entry ) {
	if (
		isDiagnosticActivityEntry( entry ) &&
		entry?.undo?.status !== 'failed'
	) {
		return { label: __( 'Review', 'flavor-agent' ), tone: 'review' };
	}

	if (
		isDiagnosticActivityEntry( entry ) &&
		entry?.undo?.status === 'failed'
	) {
		return {
			label: __( 'Request failed', 'flavor-agent' ),
			tone: 'error',
		};
	}

	switch ( getExternalApplyStatus( entry ) ) {
		case 'pending':
			return {
				label: __( 'Pending approval', 'flavor-agent' ),
				tone: 'review',
			};
		case 'rejected':
			return { label: __( 'Rejected', 'flavor-agent' ), tone: 'error' };
		case 'expired':
			return { label: __( 'Expired', 'flavor-agent' ), tone: 'stale' };
		case 'failed':
			return {
				label: __( 'Apply failed', 'flavor-agent' ),
				tone: 'error',
			};
	}

	if (
		entry?.persistence?.status !== 'server' &&
		entry?.persistence?.syncType === 'undo'
	) {
		return {
			label:
				entry?.undo?.status === 'undone'
					? __( 'Undo pending sync', 'flavor-agent' )
					: __( 'Audit sync pending', 'flavor-agent' ),
			tone: 'stale',
		};
	}

	if ( entry?.undo?.status === 'undone' ) {
		return { label: __( 'Undone', 'flavor-agent' ), tone: 'stale' };
	}

	if ( entry?.undo?.status === 'blocked' ) {
		return { label: __( 'Undo blocked', 'flavor-agent' ), tone: 'error' };
	}

	if ( entry?.undo?.status === 'failed' ) {
		return {
			label: __( 'Undo unavailable', 'flavor-agent' ),
			tone: 'error',
		};
	}

	if (
		entry?.undo?.status === 'available' &&
		entry?.undo?.canUndo === true
	) {
		return {
			label: __( 'Undo available', 'flavor-agent' ),
			tone: 'success',
		};
	}

	if ( entry?.undo?.status === 'available' ) {
		return {
			label: __( 'Undo unavailable', 'flavor-agent' ),
			tone: 'stale',
		};
	}

	return { label: __( 'Applied', 'flavor-agent' ), tone: 'success' };
}

function getExternalApplyMessage( entry ) {
	const status = getExternalApplyStatus( entry );

	switch ( status ) {
		case 'pending':
			return __(
				'External apply awaiting admin approval.',
				'flavor-agent'
			);
		case 'rejected': {
			const decisionNote = getExternalApply( entry )?.decisionNote;

			return decisionNote
				? sprintf(
						/* translators: %s: reviewer-supplied rejection note. */
						__( 'External apply rejected: %s', 'flavor-agent' ),
						decisionNote
				  )
				: __( 'External apply request was rejected.', 'flavor-agent' );
		}
		case 'expired':
			return __(
				'External apply request expired before approval.',
				'flavor-agent'
			);
		case 'failed': {
			const failureMessage = getExternalApply( entry )?.failureMessage;

			return (
				failureMessage ||
				__( 'External apply request failed.', 'flavor-agent' )
			);
		}
		default:
			return '';
	}
}

function describeActivity( entry ) {
	if ( isDiagnosticActivityEntry( entry ) ) {
		const surfaceDescription =
			DIAGNOSTIC_SURFACE_DESCRIPTIONS[ entry?.surface ];

		if ( surfaceDescription ) {
			return surfaceDescription;
		}

		return entry?.target?.blockName
			? sprintf(
					/* translators: %s: block name, with the core/ prefix removed. */
					__( 'Block request diagnostic · %s', 'flavor-agent' ),
					entry.target.blockName.replace( 'core/', '' )
			  )
			: __( 'Block request diagnostic', 'flavor-agent' );
	}

	const actionDescription = SURFACE_ACTION_DESCRIPTIONS[ entry?.surface ];

	if ( actionDescription ) {
		return actionDescription;
	}

	if ( entry?.surface === 'style-book' ) {
		return entry?.target?.blockTitle
			? sprintf(
					/* translators: %s: Style Book block title. */
					__( 'Style Book action · %s', 'flavor-agent' ),
					entry.target.blockTitle
			  )
			: __( 'Style Book action', 'flavor-agent' );
	}

	if ( entry?.target?.blockName ) {
		return entry.target.blockName.replace( 'core/', '' );
	}

	return __( 'Block action', 'flavor-agent' );
}

function getLocalizedActivityLogUrl() {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	const activityLogUrl = window.flavorAgentData?.activityLogUrl;

	return typeof activityLogUrl === 'string' ? activityLogUrl.trim() : '';
}

function buildActivityLogEntryUrl( activityLogUrl, activityId ) {
	if (
		typeof activityLogUrl !== 'string' ||
		! activityLogUrl.trim() ||
		typeof activityId !== 'string' ||
		! activityId.trim()
	) {
		return '';
	}

	try {
		const url = new URL(
			activityLogUrl,
			typeof window !== 'undefined'
				? window.location?.href
				: 'https://example.test/'
		);

		url.searchParams.set( 'activity', activityId );

		return url.toString();
	} catch {
		return '';
	}
}

export default function AIActivitySection( {
	entries = [],
	isUndoing = false,
	onUndo,
	description = '',
	title = DEFAULT_SECTION_TITLE,
	initialOpen = true,
	resetKey = null,
	maxVisible = Number.POSITIVE_INFINITY,
	showMore = true,
	className = '',
	activityLogUrl = '',
} ) {
	const [ isOpen, setIsOpen ] = useState( initialOpen );
	const [ showAll, setShowAll ] = useState( false );
	const previousResetKey = useRef( resetKey );
	const previousInitialOpen = useRef( initialOpen );

	useEffect( () => {
		if ( previousResetKey.current === resetKey ) {
			return;
		}

		previousResetKey.current = resetKey;
		setIsOpen( initialOpen );
		setShowAll( false );
	}, [ initialOpen, resetKey ] );

	useEffect( () => {
		if ( ! previousInitialOpen.current && initialOpen ) {
			setIsOpen( true );
			setShowAll( false );
		}

		previousInitialOpen.current = initialOpen;
	}, [ initialOpen ] );

	if ( entries.length === 0 ) {
		return null;
	}

	const hasOverflow =
		showMore &&
		Number.isFinite( maxVisible ) &&
		maxVisible > 0 &&
		entries.length > maxVisible &&
		! showAll;
	const visibleEntries = hasOverflow
		? entries.slice( 0, maxVisible )
		: entries;
	const resolvedActivityLogUrl =
		activityLogUrl || getLocalizedActivityLogUrl();

	return (
		<div
			className={ `flavor-agent-panel__group flavor-agent-activity-section ${ className }` }
		>
			<button
				type="button"
				className="flavor-agent-panel__group-header flavor-agent-activity-section__toggle"
				onClick={ () => setIsOpen( ( previous ) => ! previous ) }
				aria-expanded={ isOpen }
			>
				<span className="flavor-agent-panel__group-title">
					{ title }
				</span>
				<span className="flavor-agent-card__meta">
					<span className="flavor-agent-pill">
						{ formatCount( entries.length, 'action' ) }
					</span>
				</span>
				<span
					className="flavor-agent-activity-section__chevron"
					aria-hidden="true"
				>
					{ isOpen ? '\u25B2' : '\u25BC' }
				</span>
			</button>

			{ isOpen && description && (
				<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
					{ description }
				</p>
			) }

			{ isOpen && visibleEntries.length > 0 && (
				<div className="flavor-agent-panel__group-body">
					{ visibleEntries.map( ( entry ) => {
						const status = getStatusLabel( entry );
						const externalApplyMessage =
							getExternalApplyMessage( entry );
						const canUndo =
							entry?.undo?.status === 'available' &&
							entry?.undo?.canUndo === true &&
							typeof onUndo === 'function';
						const hasPendingUndoSync =
							entry?.persistence?.status !== 'server' &&
							entry?.persistence?.syncType === 'undo';
						const activityEntryUrl = buildActivityLogEntryUrl(
							resolvedActivityLogUrl,
							entry?.id
						);

						return (
							<div
								key={ entry.id }
								className="flavor-agent-activity-row"
							>
								<div className="flavor-agent-activity-row__info">
									<div className="flavor-agent-activity-row__label">
										{ truncateActivityTitle(
											entry?.suggestion ||
												__(
													'AI action',
													'flavor-agent'
												)
										) }
									</div>
									<div className="flavor-agent-activity-row__meta">
										{ describeActivity( entry ) }
									</div>
									{ getExecutionSummary( entry ) && (
										<div className="flavor-agent-activity-row__meta">
											{ getExecutionSummary( entry ) }
										</div>
									) }
									{ hasPendingUndoSync && (
										<div className="flavor-agent-activity-row__meta">
											{ __(
												'Activity audit sync pending.',
												'flavor-agent'
											) }
										</div>
									) }
									{ externalApplyMessage && (
										<div className="flavor-agent-activity-row__meta">
											{ externalApplyMessage }
										</div>
									) }
									{ ( entry?.undo?.status === 'failed' ||
										entry?.undo?.status === 'blocked' ) &&
										entry?.undo?.error && (
											<div className="flavor-agent-activity-row__meta">
												{ entry.undo.error }
											</div>
										) }
								</div>

								<span
									className={ `flavor-agent-pill flavor-agent-pill--${ status.tone }` }
								>
									{ status.label }
								</span>

								{ activityEntryUrl && (
									<Button
										size="small"
										variant="link"
										href={ activityEntryUrl }
										className="flavor-agent-activity-row__link"
									>
										{ __(
											'View activity',
											'flavor-agent'
										) }
									</Button>
								) }

								{ canUndo && (
									<Button
										size="small"
										variant="secondary"
										onClick={ () => onUndo( entry.id ) }
										className="flavor-agent-card__apply"
										disabled={ isUndoing }
									>
										{ isUndoing
											? __( 'Undoing…', 'flavor-agent' )
											: __( 'Undo', 'flavor-agent' ) }
									</Button>
								) }
							</div>
						);
					} ) }
				</div>
			) }

			{ isOpen && hasOverflow && (
				<Button
					variant="link"
					onClick={ () => setShowAll( true ) }
					className="flavor-agent-advisory-section__show-more"
				>
					{ sprintf(
						/* translators: %d: number of additional activity rows revealed by the control. */
						__( 'Show %d more', 'flavor-agent' ),
						entries.length - maxVisible
					) }
				</Button>
			) }
		</div>
	);
}
