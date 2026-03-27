import { Button } from '@wordpress/components';

function formatCountLabel( count, noun ) {
	if ( ! Number.isFinite( count ) || count < 0 || ! noun ) {
		return '';
	}

	return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}

function joinClassNames( ...values ) {
	return values.filter( Boolean ).join( ' ' );
}

export default function AIReviewSection( {
	title = 'Review Before Apply',
	statusLabel = 'Executable',
	count = null,
	countLabel = '',
	countNoun = 'operation',
	summary = null,
	children = null,
	hint = '',
	confirmLabel = 'Confirm Apply',
	cancelLabel = 'Cancel Preview',
	onConfirm,
	onCancel,
	confirmDisabled = false,
	className = '',
} ) {
	const resolvedCountLabel =
		countLabel || formatCountLabel( count, countNoun );

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-template-preview',
				'flavor-agent-review-section',
				className
			) }
		>
			<div className="flavor-agent-template-list">
				<div className="flavor-agent-template-list__header">
					<div className="flavor-agent-section-label">{ title }</div>
					<div className="flavor-agent-card__meta">
						{ statusLabel && (
							<span className="flavor-agent-pill">
								{ statusLabel }
							</span>
						) }
						{ resolvedCountLabel && (
							<span className="flavor-agent-pill">
								{ resolvedCountLabel }
							</span>
						) }
					</div>
				</div>

				{ summary && (
					<p className="flavor-agent-review-section__summary">
						{ summary }
					</p>
				) }

				{ children }
			</div>

			{ hint && (
				<p className="flavor-agent-subpanel-hint flavor-agent-review-section__hint">
					{ hint }
				</p>
			) }

			<div className="flavor-agent-template-preview__actions">
				{ typeof onCancel === 'function' && (
					<Button
						variant="secondary"
						onClick={ onCancel }
						className="flavor-agent-card__apply"
					>
						{ cancelLabel }
					</Button>
				) }

				<Button
					variant="primary"
					onClick={ onConfirm }
					disabled={ confirmDisabled }
					className="flavor-agent-card__apply"
				>
					{ confirmLabel }
				</Button>
			</div>
		</div>
	);
}
