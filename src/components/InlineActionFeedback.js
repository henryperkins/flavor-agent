import { Button } from '@wordpress/components';

import { joinClassNames } from '../utils/format-count';

export default function InlineActionFeedback( {
	label = 'Applied',
	message = '',
	actionLabel = '',
	onAction,
	compact = false,
	className = '',
} ) {
	if ( ! message && ! actionLabel ) {
		return null;
	}

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-inline-feedback',
				compact ? 'flavor-agent-inline-feedback--compact' : '',
				className
			) }
			role="status"
			aria-live="polite"
		>
			{ label && (
				<span className="flavor-agent-pill flavor-agent-pill--success">
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
