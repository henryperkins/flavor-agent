import { Button } from '@wordpress/components';

function getStatusLabel( entry, latestUndoableActivityId ) {
	if ( entry?.undo?.status === 'undone' ) {
		return 'Undone';
	}

	if ( entry?.undo?.status === 'failed' ) {
		return 'Undo unavailable';
	}

	if ( entry?.id === latestUndoableActivityId ) {
		return 'Undo available';
	}

	return 'Applied';
}

function describeActivity( entry ) {
	if ( entry?.surface === 'template' ) {
		return 'Template action';
	}

	if ( entry?.surface === 'template-part' ) {
		return 'Template part action';
	}

	if ( entry?.target?.blockName ) {
		return entry.target.blockName.replace( 'core/', '' );
	}

	return 'Block action';
}

export default function AIActivitySection( {
	entries = [],
	isUndoing = false,
	latestUndoableActivityId = null,
	onUndo,
	title = 'Recent AI Actions',
} ) {
	if ( entries.length === 0 ) {
		return null;
	}

	return (
		<div className="flavor-agent-panel__group">
			<div className="flavor-agent-panel__group-header">
				<div className="flavor-agent-panel__group-title">{ title }</div>
				<span className="flavor-agent-pill">
					{ entries.length }{ ' ' }
					{ entries.length === 1 ? 'action' : 'actions' }
				</span>
			</div>

			<div className="flavor-agent-panel__group-body">
				{ entries.map( ( entry ) => {
					const canUndo =
						entry?.id === latestUndoableActivityId &&
						entry?.undo?.status === 'available';

					return (
						<div
							key={ entry.id }
							className="flavor-agent-activity-row"
						>
							<div className="flavor-agent-activity-row__info">
								<div className="flavor-agent-activity-row__label">
									{ entry?.suggestion || 'AI action' }
								</div>
								<div className="flavor-agent-activity-row__meta">
									{ describeActivity( entry ) }
								</div>
								{ entry?.undo?.status === 'failed' &&
									entry?.undo?.error && (
										<div className="flavor-agent-activity-row__meta">
											{ entry.undo.error }
										</div>
									) }
							</div>

							<span className="flavor-agent-pill">
								{ getStatusLabel(
									entry,
									latestUndoableActivityId
								) }
							</span>

							{ canUndo && (
								<Button
									size="small"
									variant="secondary"
									onClick={ () => onUndo( entry.id ) }
									className="flavor-agent-card__apply"
									disabled={ isUndoing }
								>
									{ isUndoing ? 'Undoing…' : 'Undo' }
								</Button>
							) }
						</div>
					);
				} ) }
			</div>
		</div>
	);
}
