/**
 * Stale Result Banner
 *
 * A lightweight, shared component that renders a consistent stale-result
 * indicator with an optional refresh button. Used by SurfaceScopeBar for
 * full-panel surfaces and directly by embedded surfaces (e.g. embedded
 * navigation recommendations) for inline stale notices.
 *
 * Replaces per-surface inline stale rendering with a single treatment.
 */
import { Button } from '@wordpress/components';
import { caution } from '@wordpress/icons';

import { joinClassNames } from '../utils/format-count';
import { REFRESH_ACTION_LABEL } from './surface-labels';

export default function StaleResultBanner( {
	message = 'Context has changed since the last request. Refresh before relying on the previous results.',
	onRefresh,
	isRefreshing = false,
	refreshLabel = REFRESH_ACTION_LABEL,
	variant = 'inline',
	showIcon = true,
	className = '',
} ) {
	if ( ! message ) {
		return null;
	}

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-stale-banner',
				variant === 'embedded'
					? 'flavor-agent-stale-banner--embedded'
					: '',
				className
			) }
			role="status"
			aria-live="polite"
		>
			{ showIcon && (
				<span
					className="flavor-agent-stale-banner__icon"
					aria-hidden="true"
				>
					{ caution }
				</span>
			) }
			<p className="flavor-agent-stale-banner__message">{ message }</p>

			{ typeof onRefresh === 'function' && (
				<Button
					size="small"
					variant="secondary"
					onClick={ onRefresh }
					disabled={ isRefreshing }
					className="flavor-agent-stale-banner__refresh"
				>
					{ isRefreshing ? `${ refreshLabel }…` : refreshLabel }
				</Button>
			) }
		</div>
	);
}
