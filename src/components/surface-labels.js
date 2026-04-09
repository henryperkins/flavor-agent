export const APPLY_NOW_LABEL = 'Apply now';
export const MANUAL_IDEAS_LABEL = 'Manual ideas';
export const REVIEW_LANE_LABEL = 'Review first';
export const REVIEW_SECTION_TITLE = 'Review Before Apply';
export const ADVISORY_ONLY_LABEL = 'Advisory only';
export const EXECUTABLE_LABEL = 'Executable';
export const CURRENT_STATUS_LABEL = 'Current';
export const STALE_STATUS_LABEL = 'Stale';
export const REFRESH_ACTION_LABEL = 'Refresh';

export function getTonePillClassName( label = '' ) {
	switch ( String( label ).trim().toLowerCase() ) {
		case APPLY_NOW_LABEL.toLowerCase():
			return 'flavor-agent-pill--apply';
		case REVIEW_LANE_LABEL.toLowerCase():
			return 'flavor-agent-pill--review';
		case MANUAL_IDEAS_LABEL.toLowerCase():
		case ADVISORY_ONLY_LABEL.toLowerCase():
			return 'flavor-agent-pill--manual';
		case CURRENT_STATUS_LABEL.toLowerCase():
			return 'flavor-agent-pill--fresh';
		case STALE_STATUS_LABEL.toLowerCase():
			return 'flavor-agent-pill--stale';
		default:
			return '';
	}
}
