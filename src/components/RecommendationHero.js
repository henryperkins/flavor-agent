import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { joinClassNames } from '../utils/format-count';
import { getTonePillClassName, getToneLabel } from './surface-labels';

const DEFAULT_EYEBROW = __( 'Recommended Next Step', 'flavor-agent' );

export default function RecommendationHero( {
	eyebrow = DEFAULT_EYEBROW,
	title = '',
	description = '',
	tone = '',
	toneLabel = '',
	why = '',
	meta = null,
	primaryActionLabel = '',
	onPrimaryAction,
	primaryActionDisabled = false,
	secondaryActionLabel = '',
	onSecondaryAction,
	children = null,
	className = '',
} ) {
	const resolvedToneLabel = toneLabel || getToneLabel( tone );

	if (
		! eyebrow &&
		! title &&
		! description &&
		! resolvedToneLabel &&
		! why &&
		! meta &&
		! children
	) {
		return null;
	}

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-recommendation-hero',
				className
			) }
		>
			<div className="flavor-agent-recommendation-hero__header">
				<div className="flavor-agent-recommendation-hero__copy">
					{ eyebrow && (
						<p className="flavor-agent-recommendation-hero__eyebrow">
							{ eyebrow }
						</p>
					) }
					{ title && (
						<div className="flavor-agent-recommendation-hero__title">
							{ title }
						</div>
					) }
				</div>
				<div className="flavor-agent-card__meta">
					{ resolvedToneLabel && (
						<span
							className={ joinClassNames(
								'flavor-agent-pill',
								getTonePillClassName( tone ) ||
									'flavor-agent-pill--prominent'
							) }
						>
							{ resolvedToneLabel }
						</span>
					) }
					{ meta }
				</div>
			</div>

			{ description && (
				<p className="flavor-agent-recommendation-hero__description">
					{ description }
				</p>
			) }

			{ why && (
				<p className="flavor-agent-recommendation-hero__why">{ why }</p>
			) }

			{ children }

			{ ( primaryActionLabel && typeof onPrimaryAction === 'function' ) ||
			( secondaryActionLabel &&
				typeof onSecondaryAction === 'function' ) ? (
				<div className="flavor-agent-recommendation-hero__actions">
					{ secondaryActionLabel &&
						typeof onSecondaryAction === 'function' && (
							<Button
								variant="secondary"
								onClick={ onSecondaryAction }
								className="flavor-agent-card__apply"
							>
								{ secondaryActionLabel }
							</Button>
						) }
					{ primaryActionLabel &&
						typeof onPrimaryAction === 'function' && (
							<Button
								variant="primary"
								onClick={ onPrimaryAction }
								disabled={ primaryActionDisabled }
								className="flavor-agent-card__apply"
							>
								{ primaryActionLabel }
							</Button>
						) }
				</div>
			) : null }
		</div>
	);
}
