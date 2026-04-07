/**
 * Surface Scope Bar
 *
 * Shows what entity current recommendations target and whether the
 * results are still fresh relative to the current editor state.
 * Every recommendation surface already computes `hasMatchingResult`;
 * this component surfaces that information to the user.
 */
import { Button } from '@wordpress/components';

import { joinClassNames } from '../utils/format-count';
import {
	CURRENT_STATUS_LABEL,
	REFRESH_ACTION_LABEL,
	STALE_STATUS_LABEL,
} from './surface-labels';

export default function SurfaceScopeBar( {
	scopeLabel = '',
	scopeDetails = [],
	isFresh = false,
	hasResult = false,
	staleMessage = 'Context has changed since the last request.',
	staleReason = '',
	refreshLabel = REFRESH_ACTION_LABEL,
	onRefresh,
	isRefreshing = false,
	className = '',
} ) {
	if ( ! hasResult && ! scopeLabel && scopeDetails.length === 0 ) {
		return null;
	}

	const showFreshness = hasResult;
	const resolvedStaleMessage = staleReason || staleMessage;

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-scope-bar',
				! isFresh && hasResult ? 'flavor-agent-scope-bar--stale' : '',
				className
			) }
			role="status"
			aria-live="polite"
		>
			<div className="flavor-agent-scope-bar__scope">
				{ scopeLabel && (
					<span className="flavor-agent-scope-bar__label">
						{ scopeLabel }
					</span>
				) }
				{ scopeDetails.length > 0 && (
					<span className="flavor-agent-scope-bar__details">
						{ scopeDetails.map( ( detail, index ) => (
							<span
								key={ index }
								className="flavor-agent-pill flavor-agent-pill--scope"
							>
								{ detail }
							</span>
						) ) }
					</span>
				) }
			</div>

			{ showFreshness && (
				<div className="flavor-agent-scope-bar__status">
					<span
						className={ joinClassNames(
							'flavor-agent-pill',
							isFresh
								? 'flavor-agent-pill--fresh'
								: 'flavor-agent-pill--stale'
						) }
					>
						{ isFresh ? CURRENT_STATUS_LABEL : STALE_STATUS_LABEL }
					</span>

					{ ! isFresh && typeof onRefresh === 'function' && (
						<Button
							size="small"
							variant="secondary"
							onClick={ onRefresh }
							disabled={ isRefreshing }
							className="flavor-agent-scope-bar__refresh"
						>
							{ isRefreshing
								? `${ refreshLabel }\u2026`
								: refreshLabel }
						</Button>
					) }
				</div>
			) }

			{ ! isFresh && hasResult && resolvedStaleMessage && (
				<p className="flavor-agent-scope-bar__stale-message">
					{ resolvedStaleMessage }
				</p>
			) }
		</div>
	);
}
