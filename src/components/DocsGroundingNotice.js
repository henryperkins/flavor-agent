import { Notice } from '@wordpress/components';

import { getDocsGroundingWarningMessage } from '../utils/docs-grounding-warning';

export default function DocsGroundingNotice( { warning = null } ) {
	const message = getDocsGroundingWarningMessage( warning );

	if ( ! message ) {
		return null;
	}

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="flavor-agent-docs-grounding-notice"
		>
			{ message }
		</Notice>
	);
}
