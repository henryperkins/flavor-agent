function formatCountLabel( count, noun ) {
	if ( ! Number.isFinite( count ) || count < 0 || ! noun ) {
		return '';
	}

	return `${ count } ${ count === 1 ? noun : `${ noun }s` }`;
}

function joinClassNames( ...values ) {
	return values.filter( Boolean ).join( ' ' );
}

export default function AIAdvisorySection( {
	title = 'Advisory Guidance',
	advisoryLabel = 'Advisory only',
	count = null,
	countLabel = '',
	countNoun = 'suggestion',
	description = '',
	meta = null,
	children = null,
	className = '',
} ) {
	const resolvedCountLabel =
		countLabel || formatCountLabel( count, countNoun );

	return (
		<div
			className={ joinClassNames(
				'flavor-agent-panel__group',
				'flavor-agent-advisory-section',
				className
			) }
		>
			<div className="flavor-agent-panel__group-header">
				<div className="flavor-agent-panel__group-title">{ title }</div>
				<div className="flavor-agent-card__meta">
					{ meta }
					{ advisoryLabel && (
						<span className="flavor-agent-pill">
							{ advisoryLabel }
						</span>
					) }
					{ resolvedCountLabel && (
						<span className="flavor-agent-pill">
							{ resolvedCountLabel }
						</span>
					) }
				</div>
			</div>

			{ description && (
				<p className="flavor-agent-panel__intro-copy flavor-agent-panel__note">
					{ description }
				</p>
			) }

			{ children && (
				<div className="flavor-agent-panel__group-body">
					{ children }
				</div>
			) }
		</div>
	);
}
