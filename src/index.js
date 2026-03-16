/**
 * Flavor Agent — Editor entry point.
 *
 * Registers the data store and loads the Inspector injection filter.
 */

// Register the store (side-effect import).
import './store';

// Register the editor.BlockEdit filter (side-effect import).
import './inspector/InspectorInjector';
