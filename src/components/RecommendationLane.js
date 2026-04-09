import { formatCount, joinClassNames } from '../utils/format-count';
import { getTonePillClassName } from './surface-labels';

export default function RecommendationLane( {
	title = '',
	tone = '',
	badge = '',
	count = null,
	countLabel = '',
	countNoun = 'suggestion',
	description = '',
	meta = null,
	children = null,
	className = '',
	bodyClassName = '',
} ) {
	if ( ! title && ! children ) {
		return null;
	}

	const resolvedCountLabel = countLabel || formatCount( count, countNoun );

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-panel__group',
				'flavor-agent-recommendation-lane',
				className
			) }
		>
			<div className="flavor-agent-panel__group-header">
				<div className="flavor-agent-panel__group-title">{ title }</div>
				<div className="flavor-agent-card__meta">
					{ tone && (
						<span
							className={ joinClassNames(
								'flavor-agent-pill',
								getTonePillClassName( tone ) ||
									'flavor-agent-pill--prominent'
							) }
						>
							{ tone }
						</span>
					) }
					{ badge && (
						<span className="flavor-agent-pill">{ badge }</span>
					) }
					{ resolvedCountLabel && (
						<span className="flavor-agent-pill">
							{ resolvedCountLabel }
						</span>
					) }
					{ meta }
				</div>
			</div>

			{ description && (
				<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
					{ description }
				</p>
			) }

			{ children && (
				<div
					className={ joinClassNames(
						'flavor-agent-panel__group-body',
						bodyClassName
					) }
				>
					{ children }
				</div>
			) }
		</div>
	);
}
