import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';

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

function getExecutionPathLabel( entry ) {
	return getRequestMeta( entry )?.pathLabel || '';
}

function getNumericMetric( value ) {
	if ( typeof value === 'number' && Number.isFinite( value ) ) {
		return value;
	}

	if ( typeof value === 'string' && value.trim() ) {
		const parsed = Number( value );

		if ( Number.isFinite( parsed ) ) {
			return parsed;
		}
	}

	return null;
}

function getTokenUsageLabel( requestMeta ) {
	if ( ! requestMeta || typeof requestMeta !== 'object' ) {
		return '';
	}

	const totalTokens = getNumericMetric( requestMeta?.tokenUsage?.total );

	if ( totalTokens !== null ) {
		return `${ totalTokens } total tokens`;
	}

	const inputTokens = getNumericMetric( requestMeta?.tokenUsage?.input );
	const outputTokens = getNumericMetric( requestMeta?.tokenUsage?.output );

	if ( inputTokens === null && outputTokens === null ) {
		return '';
	}

	return [
		inputTokens !== null ? `${ inputTokens } input` : null,
		outputTokens !== null ? `${ outputTokens } output` : null,
	]
		.filter( Boolean )
		.join( ' / ' );
}

function getLatencyLabel( requestMeta ) {
	const latencyMs = getNumericMetric( requestMeta?.latencyMs );

	return latencyMs !== null ? `${ latencyMs } ms` : '';
}

function getTransportEndpointLabel( requestMeta ) {
	if ( ! requestMeta || typeof requestMeta !== 'object' ) {
		return '';
	}

	const host =
		typeof requestMeta?.transport?.host === 'string'
			? requestMeta.transport.host.trim()
			: '';
	const path =
		typeof requestMeta?.transport?.path === 'string'
			? requestMeta.transport.path.trim()
			: '';

	if ( ! host ) {
		return '';
	}

	return `${ host }${ path || '' }`;
}

function getTimeoutLabel( requestMeta ) {
	const timeoutSeconds = getNumericMetric(
		requestMeta?.transport?.timeoutSeconds
	);

	return timeoutSeconds !== null ? `${ timeoutSeconds } s` : '';
}

function getPayloadSummaryLabel( requestMeta ) {
	if ( ! requestMeta || typeof requestMeta !== 'object' ) {
		return '';
	}

	const bodyBytes = getNumericMetric( requestMeta?.requestSummary?.bodyBytes );
	const instructionsChars = getNumericMetric(
		requestMeta?.requestSummary?.instructionsChars
	);
	const inputChars = getNumericMetric( requestMeta?.requestSummary?.inputChars );
	const maxOutputTokens = getNumericMetric(
		requestMeta?.requestSummary?.maxOutputTokens
	);
	const reasoningEffort =
		typeof requestMeta?.requestSummary?.reasoningEffort === 'string'
			? requestMeta.requestSummary.reasoningEffort.trim()
			: '';

	const parts = [
		bodyBytes !== null ? `${ bodyBytes } bytes` : null,
		instructionsChars !== null
			? `${ instructionsChars } instruction chars`
			: null,
		inputChars !== null ? `${ inputChars } input chars` : null,
		maxOutputTokens !== null ? `${ maxOutputTokens } max output tokens` : null,
		reasoningEffort ? `reasoning ${ reasoningEffort }` : null,
	];

	return parts.filter( Boolean ).join( ' · ' );
}

function getResponseSummaryLabel( requestMeta ) {
	if ( ! requestMeta || typeof requestMeta !== 'object' ) {
		return '';
	}

	const httpStatus = getNumericMetric( requestMeta?.responseSummary?.httpStatus );
	const bodyBytes = getNumericMetric( requestMeta?.responseSummary?.bodyBytes );
	const processingMs = getNumericMetric(
		requestMeta?.responseSummary?.processingMs
	);
	const retryAfter = getNumericMetric( requestMeta?.responseSummary?.retryAfter );
	const region =
		typeof requestMeta?.responseSummary?.region === 'string'
			? requestMeta.responseSummary.region.trim()
			: '';

	const parts = [
		httpStatus !== null ? `HTTP ${ httpStatus }` : null,
		bodyBytes !== null ? `${ bodyBytes } bytes` : null,
		processingMs !== null ? `${ processingMs } ms processing` : null,
		retryAfter !== null ? `${ retryAfter } s retry-after` : null,
		region ? `region ${ region }` : null,
	];

	return parts.filter( Boolean ).join( ' · ' );
}

function getProviderRequestIdLabel( requestMeta ) {
	if ( ! requestMeta || typeof requestMeta !== 'object' ) {
		return '';
	}

	return typeof requestMeta?.responseSummary?.providerRequestId === 'string'
		? requestMeta.responseSummary.providerRequestId.trim()
		: '';
}

function getWrappedTransportErrorLabel( requestMeta ) {
	if ( ! requestMeta || typeof requestMeta !== 'object' ) {
		return '';
	}

	return typeof requestMeta?.errorSummary?.wrappedMessage === 'string'
		? requestMeta.errorSummary.wrappedMessage.trim()
		: '';
}

function getExecutionDetailLines( entry ) {
	const requestMeta = getRequestMeta( entry );
	const selectedProvider =
		requestMeta?.selectedProviderLabel || requestMeta?.selectedProvider || '';
	const credentialSource =
		requestMeta?.credentialSourceLabel || requestMeta?.credentialSource || '';
	const lines = [];

	if ( getExecutionPathLabel( entry ) ) {
		lines.push( `Provider path: ${ getExecutionPathLabel( entry ) }` );
	}

	if ( requestMeta?.ownerLabel ) {
		lines.push( `Configured in: ${ requestMeta.ownerLabel }` );
	}

	if ( credentialSource ) {
		lines.push( `Credential source: ${ credentialSource }` );
	}

	if ( selectedProvider ) {
		lines.push( `Selected provider: ${ selectedProvider }` );
	}

	if ( requestMeta?.usedFallback && selectedProvider ) {
		lines.push( `Fallback from selected ${ selectedProvider }.` );
	}

	if ( requestMeta?.ability ) {
		lines.push( `Ability: ${ requestMeta.ability }` );
	}

	if ( requestMeta?.route ) {
		lines.push( `Route: ${ requestMeta.route }` );
	}

	if ( getTransportEndpointLabel( requestMeta ) ) {
		lines.push( `Endpoint: ${ getTransportEndpointLabel( requestMeta ) }` );
	}

	if ( getTimeoutLabel( requestMeta ) ) {
		lines.push( `Timeout: ${ getTimeoutLabel( requestMeta ) }` );
	}

	if ( getPayloadSummaryLabel( requestMeta ) ) {
		lines.push( `Payload: ${ getPayloadSummaryLabel( requestMeta ) }` );
	}

	if ( getResponseSummaryLabel( requestMeta ) ) {
		lines.push( `Response: ${ getResponseSummaryLabel( requestMeta ) }` );
	}

	if ( getProviderRequestIdLabel( requestMeta ) ) {
		lines.push(
			`Provider request ID: ${ getProviderRequestIdLabel( requestMeta ) }`
		);
	}

	if ( getWrappedTransportErrorLabel( requestMeta ) ) {
		lines.push(
			`Transport detail: ${ getWrappedTransportErrorLabel( requestMeta ) }`
		);
	}

	if (
		typeof entry?.request?.reference === 'string' &&
		entry.request.reference.trim()
	) {
		lines.push( `Reference: ${ entry.request.reference.trim() }` );
	}

	if (
		typeof entry?.request?.prompt === 'string' &&
		entry.request.prompt.trim()
	) {
		lines.push( `Prompt: ${ entry.request.prompt.trim() }` );
	}

	if ( getTokenUsageLabel( requestMeta ) ) {
		lines.push( `Token usage: ${ getTokenUsageLabel( requestMeta ) }` );
	}

	if ( getLatencyLabel( requestMeta ) ) {
		lines.push( `Latency: ${ getLatencyLabel( requestMeta ) }` );
	}

	return lines;
}

function getStatusLabel( entry ) {
	if ( isDiagnosticEntry( entry ) && entry?.undo?.status !== 'failed' ) {
		return 'Review';
	}

	if ( isDiagnosticEntry( entry ) && entry?.undo?.status === 'failed' ) {
		return 'Request failed';
	}

	if (
		entry?.persistence?.status !== 'server' &&
		entry?.persistence?.syncType === 'undo'
	) {
		return entry?.undo?.status === 'undone'
			? 'Undo pending sync'
			: 'Audit sync pending';
	}

	if ( entry?.undo?.status === 'undone' ) {
		return 'Undone';
	}

	if ( entry?.undo?.status === 'blocked' ) {
		return 'Undo blocked';
	}

	if ( entry?.undo?.status === 'failed' ) {
		return 'Undo unavailable';
	}

	if ( entry?.undo?.status === 'available' ) {
		return 'Undo available';
	}

	return 'Applied';
}

function describeActivity( entry ) {
	if ( isDiagnosticEntry( entry ) ) {
		if ( entry?.surface === 'content' ) {
			return 'Content request diagnostic';
		}

		if ( entry?.surface === 'navigation' ) {
			return 'Navigation request diagnostic';
		}

		if ( entry?.surface === 'pattern' ) {
			return 'Pattern request diagnostic';
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

function getDiagnosticDetailLines( entry ) {
	return Array.isArray( entry?.diagnostic?.detailLines )
		? entry.diagnostic.detailLines.filter(
				( line ) => typeof line === 'string' && line.trim() !== ''
		  )
		: [];
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
				<div className="flavor-agent-panel__group-title">{ title }</div>
				<div className="flavor-agent-card__meta">
					<span className="flavor-agent-pill">
						{ entries.length }{ ' ' }
						{ entries.length === 1 ? 'action' : 'actions' }
					</span>
				</div>
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
							const canUndo =
								entry?.undo?.status === 'available' &&
								entry?.undo?.canUndo === true &&
								typeof onUndo === 'function';
							const hasPendingUndoSync =
								entry?.persistence?.status !== 'server' &&
								entry?.persistence?.syncType === 'undo';
							const executionDetailLines =
								getExecutionDetailLines( entry );

							return (
							<div
								key={ entry.id }
								className="flavor-agent-activity-row"
							>
								<div className="flavor-agent-activity-row__info">
									<div className="flavor-agent-activity-row__label">
										{ entry?.suggestion || 'AI action' }
									</div>
									<div className="flavor-agent-activity-row__meta">
										{ describeActivity( entry ) }
									</div>
										{ getExecutionSummary( entry ) && (
											<div className="flavor-agent-activity-row__meta">
												{ getExecutionSummary( entry ) }
											</div>
										) }
										{ executionDetailLines.length > 0 && (
											<details className="flavor-agent-activity-row__details">
												<summary className="flavor-agent-activity-row__meta">
													Execution details
												</summary>
												{ executionDetailLines.map(
													( line, index ) => (
														<div
															key={ `${ entry.id }:execution:${ index }` }
															className="flavor-agent-activity-row__meta"
														>
															{ line }
														</div>
													)
												) }
											</details>
										) }
										{ getDiagnosticDetailLines( entry ).map(
										( line, index ) => (
											<div
												key={ `${ entry.id }:diagnostic:${ index }` }
												className="flavor-agent-activity-row__meta"
											>
												{ line }
											</div>
										)
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

								<span className="flavor-agent-pill">
									{ getStatusLabel( entry ) }
								</span>

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
