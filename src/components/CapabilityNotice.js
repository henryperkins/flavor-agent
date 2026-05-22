import { Button, Notice } from '@wordpress/components';

import { getCapabilityNotice } from '../utils/capability-flags';

export default function CapabilityNotice( {
	surface,
	data = null,
	notice = null,
} ) {
	const resolvedNotice = notice || getCapabilityNotice( surface, data );

	if ( ! resolvedNotice ) {
		return null;
	}

	let actions = Array.isArray( resolvedNotice.actions )
		? resolvedNotice.actions
		: [];

	if ( actions.length === 0 && resolvedNotice.actionHref ) {
		actions = [
			{
				label: resolvedNotice.actionLabel,
				href: resolvedNotice.actionHref,
			},
		];
	}

	return (
		<Notice status={ resolvedNotice.status } isDismissible={ false }>
			<div className="flavor-agent-capability-notice">
				<p className="flavor-agent-panel__note">
					{ resolvedNotice.message }
				</p>
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
