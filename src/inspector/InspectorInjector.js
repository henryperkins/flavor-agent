/**
 * Inspector Injector
 *
 * Uses the editor.BlockEdit filter to inject AI recommendation controls
 * into the native Inspector tabs.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import {
	collectBlockContext,
	getLiveBlockContextSignature,
} from '../context/collector';
import { STORE_NAME } from '../store';
import { BlockRecommendationsPanel } from './BlockRecommendationsPanel';
import {
	buildBlockRecommendationRequestData,
	getBlockRecommendationFreshness,
} from './block-recommendation-request';
import SettingsRecommendations from './SettingsRecommendations';
import StylesRecommendations from './StylesRecommendations';
import SuggestionChips from './SuggestionChips';
import {
	SETTINGS_PANEL_DELEGATIONS,
	STYLE_PANEL_DELEGATIONS,
} from './panel-delegation';

const withAIRecommendations = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { clientId, isSelected } = props;
		const [ promptState, setPromptState ] = useState( {
			clientId: null,
			value: '',
		} );
		const hydratedResultKeyRef = useRef( null );
		const {
			recommendations,
			editingMode,
			isInsideContentOnly,
			status,
			storedContextSignature,
			storedRequestToken,
			storedStaleReason,
		} = useSelect(
			( sel ) => {
				const editor = sel( blockEditorStore );
				const store = sel( STORE_NAME );
				const parentIds = editor.getBlockParents?.( clientId ) || [];

				return {
					recommendations: store.getBlockRecommendations( clientId ),
					status: store.getBlockStatus( clientId ),
					storedContextSignature:
						store.getBlockRecommendationContextSignature(
							clientId
						),
					storedRequestToken:
						store.getBlockRequestToken?.( clientId ) || 0,
					storedStaleReason:
						store.getBlockStaleReason?.( clientId ) || null,
					editingMode: editor.getBlockEditingMode( clientId ),
					isInsideContentOnly: parentIds.some(
						( parentId ) =>
							editor.getBlockEditingMode?.( parentId ) ===
							'contentOnly'
					),
				};
			},
			[ clientId ]
		);
		const liveContextSignature = useSelect(
			( select ) => getLiveBlockContextSignature( select, clientId ),
			[ clientId ]
		);
		const liveContext = useMemo( () => {
			void liveContextSignature;

			return clientId ? collectBlockContext( clientId ) : null;
		}, [ clientId, liveContextSignature ] );
		const prompt =
			promptState.clientId === clientId
				? promptState.value
				: recommendations?.prompt || '';
		const handlePromptChange = useCallback(
			( nextPrompt ) => {
				setPromptState( {
					clientId,
					value: nextPrompt,
				} );
			},
			[ clientId ]
		);
		const {
			hasFreshResult,
			hasStoredResult,
			isStaleResult,
			storedRequestSignature,
		} = useMemo(
			() =>
				getBlockRecommendationFreshness( {
					clientId,
					recommendations,
					status,
					storedContextSignature,
					storedStaleReason,
					liveContextSignature,
					prompt,
				} ),
			[
				clientId,
				liveContextSignature,
				prompt,
				recommendations,
				status,
				storedContextSignature,
				storedStaleReason,
			]
		);
		const {
			requestSignature: currentRequestSignature,
			requestInput: currentRequestInput,
		} = useMemo(
			() =>
				buildBlockRecommendationRequestData( {
					clientId,
					liveContext,
					liveContextSignature,
					prompt,
				} ),
			[ clientId, liveContext, liveContextSignature, prompt ]
		);
		const isDisabled = editingMode === 'disabled';
		const hasMatchingResult = hasStoredResult && hasFreshResult;
		const isContentRestricted =
			editingMode === 'contentOnly' || isInsideContentOnly;
		const hasVisibleResult = hasMatchingResult || isStaleResult;
		const visibleRecommendations = hasVisibleResult
			? recommendations
			: null;
		const visibleSettingsRecommendations = hasVisibleResult
			? recommendations?.settings || []
			: [];
		const visibleStyleRecommendations =
			! isContentRestricted && hasVisibleResult
				? recommendations?.styles || []
				: [];
		const visibleDelegatedStyleRecommendations =
			! isContentRestricted && hasVisibleResult
				? recommendations?.styles || []
				: [];

		useEffect( () => {
			hydratedResultKeyRef.current = null;
			setPromptState( {
				clientId: null,
				value: '',
			} );
		}, [ clientId ] );

		useEffect( () => {
			const hydrationKey =
				status === 'ready' && clientId && recommendations
					? `${ clientId }:${
							storedRequestToken || storedRequestSignature
					  }`
					: '';

			if (
				! hydrationKey ||
				hydratedResultKeyRef.current === hydrationKey
			) {
				return;
			}

			hydratedResultKeyRef.current = hydrationKey;
			setPromptState( {
				clientId,
				value: recommendations?.prompt || '',
			} );
		}, [
			clientId,
			recommendations,
			status,
			storedRequestSignature,
			storedRequestToken,
		] );

		if ( ! isSelected || isDisabled ) {
			return <BlockEdit { ...props } />;
		}

		const hasInlineRecs =
			visibleRecommendations &&
			( visibleSettingsRecommendations.length > 0 ||
				visibleDelegatedStyleRecommendations.length > 0 ||
				visibleRecommendations.block?.length > 0 );
		const hasStyleRecs = visibleStyleRecommendations.length > 0;

		return (
			<>
				<BlockEdit { ...props } />

				<InspectorControls>
					<BlockRecommendationsPanel
						clientId={ clientId }
						prompt={ prompt }
						onPromptChange={ handlePromptChange }
					/>

					{ hasInlineRecs &&
						visibleSettingsRecommendations.length > 0 && (
							<SettingsRecommendations
								clientId={ clientId }
								suggestions={ visibleSettingsRecommendations }
								isStale={ isStaleResult }
								currentRequestSignature={
									currentRequestSignature
								}
								currentRequestInput={ currentRequestInput }
							/>
						) }
				</InspectorControls>

				{ /* The Styles tab projects safe style results from the main
				   block request rather than owning its own request lifecycle. */ }
				{ hasStyleRecs && (
					<InspectorControls group="styles">
						<StylesRecommendations
							clientId={ clientId }
							suggestions={ visibleStyleRecommendations }
							isStale={ isStaleResult }
							currentRequestSignature={ currentRequestSignature }
							currentRequestInput={ currentRequestInput }
						/>
					</InspectorControls>
				) }

				{ hasInlineRecs && (
					<>
						{ SETTINGS_PANEL_DELEGATIONS.map( ( config ) => (
							<SubPanelSuggestions
								key={ `settings-${ config.group }` }
								{ ...config }
								clientId={ clientId }
								suggestions={ visibleSettingsRecommendations }
								isStale={ isStaleResult }
								currentRequestSignature={
									currentRequestSignature
								}
								currentRequestInput={ currentRequestInput }
							/>
						) ) }
						{ STYLE_PANEL_DELEGATIONS.map( ( config ) => (
							<SubPanelSuggestions
								key={ `styles-${ config.group }` }
								{ ...config }
								clientId={ clientId }
								suggestions={
									visibleDelegatedStyleRecommendations
								}
								isStale={ isStaleResult }
								currentRequestSignature={
									currentRequestSignature
								}
								currentRequestInput={ currentRequestInput }
							/>
						) ) }
					</>
				) }
			</>
		);
	};
}, 'withAIRecommendations' );

function SubPanelSuggestions( {
	group,
	panel,
	clientId,
	isStale = false,
	suggestions,
	label,
	title,
	currentRequestSignature = null,
	currentRequestInput = null,
} ) {
	const filtered = useMemo(
		() => ( suggestions || [] ).filter( ( s ) => s.panel === panel ),
		[ panel, suggestions ]
	);
	if ( ! filtered.length ) {
		return null;
	}
	return (
		<InspectorControls group={ group }>
			<SuggestionChips
				clientId={ clientId }
				suggestions={ filtered }
				isStale={ isStale }
				label={ label }
				title={ title }
				tone="Apply now"
				currentRequestSignature={ currentRequestSignature }
				currentRequestInput={ currentRequestInput }
			/>
		</InspectorControls>
	);
}

addFilter(
	'editor.BlockEdit',
	'flavor-agent/ai-recommendations',
	withAIRecommendations
);

export default withAIRecommendations;
