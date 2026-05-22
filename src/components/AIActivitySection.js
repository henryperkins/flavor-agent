import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';

import { truncateActivityTitle } from '../utils/activity-title';

function isDiagnosticEntry( entry ) {
	return entry?.type === 'request_diagnostic';
}

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

function getStatusLabel( entry ) {
	if ( isDiagnosticEntry( entry ) && entry?.undo?.status !== 'failed' ) {
		return { label: 'Review', tone: 'review' };
	}

	if ( isDiagnosticEntry( entry ) && entry?.undo?.status === 'failed' ) {
		return { label: 'Request failed', tone: 'error' };
	}

	if (
		entry?.persistence?.status !== 'server' &&
		entry?.persistence?.syncType === 'undo'
	) {
		return {
			label:
				entry?.undo?.status === 'undone'
					? 'Undo pending sync'
					: 'Audit sync pending',
			tone: 'stale',
		};
	}

	if ( entry?.undo?.status === 'undone' ) {
		return { label: 'Undone', tone: 'stale' };
	}

	if ( entry?.undo?.status === 'blocked' ) {
		return { label: 'Undo blocked', tone: 'error' };
	}

	if ( entry?.undo?.status === 'failed' ) {
		return { label: 'Undo unavailable', tone: 'error' };
	}

	if (
		entry?.undo?.status === 'available' &&
		entry?.undo?.canUndo === true
	) {
		return { label: 'Undo available', tone: 'success' };
	}

	if ( entry?.undo?.status === 'available' ) {
		return { label: 'Undo unavailable', tone: 'stale' };
	}

	return { label: 'Applied', tone: 'success' };
}

function describeActivity( entry ) {
	if ( isDiagnosticEntry( entry ) ) {
		switch ( entry?.surface ) {
			case 'content':
				return 'Content request diagnostic';
			case 'navigation':
				return 'Navigation request diagnostic';
			case 'pattern':
				return 'Pattern request diagnostic';
			case 'template':
				return 'Template request diagnostic';
			case 'template-part':
				return 'Template part request diagnostic';
			case 'global-styles':
				return 'Global Styles request diagnostic';
			case 'style-book':
				return 'Style Book request diagnostic';
		}

		return entry?.target?.blockName
			? `Block request diagnostic · ${ entry.target.blockName.replace(
					'core/',
					''
			  ) }`
			: 'Block request diagnostic';
	}

	if ( entry?.surface === 'template' ) {
		return 'Template action';
	}

	if ( entry?.surface === 'template-part' ) {
		return 'Template part action';
	}

	if ( entry?.surface === 'global-styles' ) {
		return 'Global Styles action';
	}

	if ( entry?.surface === 'style-book' ) {
		return entry?.target?.blockTitle
			? `Style Book action · ${ entry.target.blockTitle }`
			: 'Style Book action';
	}

	if ( entry?.target?.blockName ) {
		return entry.target.blockName.replace( 'core/', '' );
	}

	return 'Block action';
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
	title = 'Recent AI Actions',
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
						{ entries.length }{ ' ' }
						{ entries.length === 1 ? 'action' : 'actions' }
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
											entry?.suggestion || 'AI action'
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
											Activity audit sync pending.
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
										View activity
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
										{ isUndoing ? 'Undoing…' : 'Undo' }
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
					{ `Show ${ entries.length - maxVisible } more` }
				</Button>
			) }
		</div>
	);
}
