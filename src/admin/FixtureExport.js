import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl } from '@wordpress/components';
import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	buildFixtureReviewBundle,
	downloadFixtureReviewBundle,
	FIXTURE_SURFACES,
	validateFixtureSelection,
} from './fixture-export';

const SURFACE_LABELS = {
	block: __( 'Block', 'flavor-agent' ),
	template: __( 'Template', 'flavor-agent' ),
	'template-part': __( 'Template part', 'flavor-agent' ),
	'global-styles': __( 'Global Styles', 'flavor-agent' ),
	'style-book': __( 'Style Book', 'flavor-agent' ),
	pattern: __( 'Pattern', 'flavor-agent' ),
	navigation: __( 'Navigation', 'flavor-agent' ),
	content: __( 'Content', 'flavor-agent' ),
	'post-blocks': __( 'Post blocks', 'flavor-agent' ),
};

export default function FixtureExport( { bootData } ) {
	const inputId = useId();
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ rowLimit, setRowLimit ] = useState( '500' );
	const [ surfaces, setSurfaces ] = useState( [] );
	const [ custodian, setCustodian ] = useState( '' );
	const [ site, setSite ] = useState( '' );
	const [ collectionScope, setCollectionScope ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	// wp_localize_script serializes top-level scalar capabilities as strings.
	if ( ! [ true, 1, '1' ].includes( bootData?.canExportFixtures ) ) {
		return null;
	}
	const selection = {
		dateFrom,
		dateTo,
		surfaces,
		rowLimit: Number( rowLimit ),
	};
	const valid =
		validateFixtureSelection( selection ) &&
		[ custodian, site, collectionScope ].every(
			( value ) => !! value.trim() && value.length <= 512
		);
	const busy = status === 'exporting';
	const fields = [
		[ __( 'From (UTC)', 'flavor-agent' ), dateFrom, setDateFrom, 'text' ],
		[ __( 'Through (UTC)', 'flavor-agent' ), dateTo, setDateTo, 'text' ],
		[
			__( 'Row limit (1–1000)', 'flavor-agent' ),
			rowLimit,
			setRowLimit,
			'number',
		],
		[
			__( 'Source custodian (local record)', 'flavor-agent' ),
			custodian,
			setCustodian,
			'text',
		],
		[
			__( 'Source site (local record)', 'flavor-agent' ),
			site,
			setSite,
			'text',
		],
		[
			__(
				'Collection permission and scope (local record)',
				'flavor-agent'
			),
			collectionScope,
			setCollectionScope,
			'text',
		],
	];
	async function exportCandidate() {
		if ( ! valid || busy ) {
			return;
		}
		setStatus( 'exporting' );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: '/flavor-agent/v1/activity/fixture-export',
				method: 'POST',
				headers: { 'X-WP-Nonce': bootData.nonce },
				data: { selection },
			} );
			const bundle = await buildFixtureReviewBundle(
				response?.candidate,
				{ selection, custodian, site, collectionScope }
			);
			downloadFixtureReviewBundle( bundle );
			setStatus( 'downloaded' );
		} catch ( failure ) {
			setStatus( 'error' );
			setError(
				failure?.message ||
					__(
						'The local candidate could not be exported.',
						'flavor-agent'
					)
			);
		}
	}
	return (
		<details className="flavor-agent-activity-log__fixture-export">
			<summary>
				{ __( 'Local fixture review candidate', 'flavor-agent' ) }
			</summary>
			<p>
				{ __(
					'Choose up to 31 inclusive UTC days and selected surfaces. Use YYYY-MM-DD, or YYYY-MM-DDTHH:mm:ssZ for an exact UTC cutoff. The local download contains a v1 candidate and a separate restricted review record with site/custodian details. Keep the bundle local until provenance, privacy, and the intended recipient/use are approved.',
					'flavor-agent'
				) }
			</p>
			<div className="flavor-agent-activity-log__fixture-fields">
				{ fields.map( ( [ label, value, onChange, type ], index ) => (
					<label key={ label } htmlFor={ `${ inputId }-${ index }` }>
						<span>{ label }</span>
						<input
							id={ `${ inputId }-${ index }` }
							aria-label={ label }
							type={ type }
							value={ value }
							maxLength={ 512 }
							disabled={ busy }
							onInput={ ( event ) =>
								onChange( event.target.value )
							}
						/>
					</label>
				) ) }
			</div>
			<fieldset disabled={ busy }>
				<legend>{ __( 'Selected surfaces', 'flavor-agent' ) }</legend>
				{ FIXTURE_SURFACES.map( ( surface ) => (
					<CheckboxControl
						key={ surface }
						label={ SURFACE_LABELS[ surface ] }
						checked={ surfaces.includes( surface ) }
						onChange={ ( checked ) =>
							setSurfaces(
								checked
									? [ ...surfaces, surface ]
									: surfaces.filter(
											( item ) => item !== surface
									  )
							)
						}
						__nextHasNoMarginBottom
					/>
				) ) }
			</fieldset>
			<Button
				variant="secondary"
				onClick={ exportCandidate }
				disabled={ ! valid || busy }
				isBusy={ busy }
			>
				{ __( 'Download local review bundle', 'flavor-agent' ) }
			</Button>
			{ status === 'downloaded' && (
				<p role="status">
					{ __(
						'Local bundle downloaded. The review decision is pending; its reviewRecord contains restricted identifying custody details.',
						'flavor-agent'
					) }
				</p>
			) }
			{ error && <p role="alert">{ error }</p> }
		</details>
	);
}
