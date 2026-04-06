/**
 * Surface Scope Bar
 *
 * Shows what entity current recommendations target and whether the
 * results are still fresh relative to the current editor state.
 * Every recommendation surface already computes `hasMatchingResult`;
 * this component surfaces that information to the user.
 */
import { joinClassNames } from '../utils/format-count';

export default function SurfaceScopeBar( {
	scopeLabel = '',
	scopeDetails = [],
	isFresh = false,
	hasResult = false,
	staleMessage = 'Context has changed since the last request.',
	className = '',
} ) {
	if ( ! hasResult && ! scopeLabel && scopeDetails.length === 0 ) {
		return null;
	}

	const showFreshness = hasResult;

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
				<span
					className={ joinClassNames(
						'flavor-agent-pill',
						isFresh
							? 'flavor-agent-pill--fresh'
							: 'flavor-agent-pill--stale'
					) }
				>
					{ isFresh ? 'Current' : 'Stale' }
				</span>
			) }

			{ ! isFresh && hasResult && staleMessage && (
				<p className="flavor-agent-scope-bar__stale-message">
					{ staleMessage }
				</p>
			) }
		</div>
	);
}
