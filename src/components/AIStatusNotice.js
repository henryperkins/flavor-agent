import { Button, Notice } from '@wordpress/components';

import { joinClassNames } from '../utils/format-count';

export default function AIStatusNotice( {
	notice = null,
	onAction,
	onDismiss,
	className = '',
} ) {
	if ( ! notice?.message ) {
		return null;
	}

	const {
		actionDisabled = false,
		actionLabel = '',
		isDismissible = false,
		message,
		tone = 'info',
	} = notice;

	return (
		<Notice
			status={ tone }
			isDismissible={ isDismissible }
			onDismiss={ isDismissible ? onDismiss : undefined }
			className={ joinClassNames(
				'flavor-agent-status-notice',
				className
			) }
		>
			<div className="flavor-agent-status-notice__content">
				<div className="flavor-agent-status-notice__message">
					{ message }
				</div>

				{ actionLabel && typeof onAction === 'function' && (
					<Button
						variant="link"
						onClick={ onAction }
						disabled={ actionDisabled }
						className="flavor-agent-status-notice__action"
					>
						{ actionLabel }
					</Button>
				) }
			</div>
		</Notice>
	);
}
