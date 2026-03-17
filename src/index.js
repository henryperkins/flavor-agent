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
import './editor.css';

// Data store (self-registering).
import './store';

// Inspector injection — adds AI controls to native Inspector tabs
// via the editor.BlockEdit filter. This import registers the filter.
import './inspector/InspectorInjector';

// Plugin components.
import PatternRecommender from './patterns/PatternRecommender';
import InserterBadge from './patterns/InserterBadge';
import TemplateRecommender from './templates/TemplateRecommender';

registerPlugin( 'flavor-agent', {
	render: () => (
		<>
			<PatternRecommender />
			<InserterBadge />
			<TemplateRecommender />
		</>
	),
} );
