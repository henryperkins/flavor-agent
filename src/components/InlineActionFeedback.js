import { Button } from '@wordpress/components';
import { caution, check, closeSmall, info } from '@wordpress/icons';

import { joinClassNames } from '../utils/format-count';

const TONE_PILL_MODIFIER = {
	success: 'flavor-agent-pill--success',
	error: 'flavor-agent-pill--error',
	warning: 'flavor-agent-pill--stale',
	info: 'flavor-agent-pill--review',
};

const TONE_ICON = {
	success: check,
	error: closeSmall,
	warning: caution,
	info,
};

export default function InlineActionFeedback( {
	label = 'Applied',
	message = '',
	actionLabel = '',
	onAction,
	compact = false,
	tone = 'success',
	icon: iconOverride = null,
	showIcon = false,
	className = '',
} ) {
	if ( ! message && ! actionLabel ) {
		return null;
	}

	const resolvedTone = tone in TONE_PILL_MODIFIER ? tone : 'success';
	const pillModifier = TONE_PILL_MODIFIER[ resolvedTone ];
	const resolvedIcon = iconOverride || TONE_ICON[ resolvedTone ] || null;

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-inline-feedback',
				`flavor-agent-inline-feedback--${ resolvedTone }`,
				compact ? 'flavor-agent-inline-feedback--compact' : '',
				className
			) }
			role="status"
			aria-live="polite"
		>
			{ label && (
				<span
					className={ joinClassNames(
						'flavor-agent-pill',
						pillModifier
					) }
				>
					{ showIcon && resolvedIcon && (
						<span
							className="flavor-agent-pill__icon"
							aria-hidden="true"
						>
							{ resolvedIcon }
						</span>
					) }
					{ label }
				</span>
			) }
			{ message && (
				<span className="flavor-agent-inline-feedback__message">
					{ message }
				</span>
			) }
			{ actionLabel && typeof onAction === 'function' && (
				<Button
					variant="link"
					onClick={ onAction }
					className="flavor-agent-inline-feedback__action"
				>
					{ actionLabel }
				</Button>
			) }
		</div>
	);
}
