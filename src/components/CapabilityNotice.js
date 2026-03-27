import { Button, Notice } from '@wordpress/components';

import { getCapabilityNotice } from '../utils/capability-flags';

export default function CapabilityNotice( { surface, data = null } ) {
	const notice = getCapabilityNotice( surface, data );

	if ( ! notice ) {
		return null;
	}

	let actions = Array.isArray( notice.actions ) ? notice.actions : [];

	if ( actions.length === 0 && notice.actionHref ) {
		actions = [
			{
				label: notice.actionLabel,
				href: notice.actionHref,
			},
		];
	}

	return (
		<Notice status={ notice.status } isDismissible={ false }>
			<div className="flavor-agent-capability-notice">
				<p className="flavor-agent-panel__note">{ notice.message }</p>
				{ actions.map( ( action ) => (
					<Button
						key={ `${ surface }-${ action.href }` }
						href={ action.href }
						variant="link"
						className="flavor-agent-capability-notice__link"
					>
						{ action.label }
					</Button>
				) ) }
			</div>
		</Notice>
	);
}
