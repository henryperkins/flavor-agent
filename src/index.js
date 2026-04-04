/**
 * Flavor Agent — Editor entry point.
 *
 * Registers:
 *   1. The data store (auto-registers on import)
 *   2. The editor.BlockEdit filter for native Inspector injection
 *   3. Plugin components for pattern recommendations + inserter badge
 */
import { registerPlugin } from '@wordpress/plugins';

// Shared editor styles.
import './tokens.css';
import './editor.css';

// Data store (self-registering).
import './store';

// Inspector injection — adds AI controls to native Inspector tabs
// via the editor.BlockEdit filter. This import registers the filter.
import './inspector/InspectorInjector';
import { BlockRecommendationsDocumentPanel } from './inspector/BlockRecommendationsPanel';

// Plugin components.
import ActivitySessionBootstrap from './components/ActivitySessionBootstrap';
import PatternRecommender from './patterns/PatternRecommender';
import InserterBadge from './patterns/InserterBadge';
import TemplateRecommender from './templates/TemplateRecommender';
import TemplatePartRecommender from './template-parts/TemplatePartRecommender';
import GlobalStylesRecommender from './global-styles/GlobalStylesRecommender';
import StyleBookRecommender from './style-book/StyleBookRecommender';

registerPlugin( 'flavor-agent', {
	render: () => (
		<>
			<ActivitySessionBootstrap />
			<BlockRecommendationsDocumentPanel />
			<PatternRecommender />
			<InserterBadge />
			<TemplateRecommender />
			<TemplatePartRecommender />
			<GlobalStylesRecommender />
			<StyleBookRecommender />
		</>
	),
} );
