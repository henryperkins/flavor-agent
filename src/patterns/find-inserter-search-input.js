/**
 * Inserter search input discovery.
 *
 * This module re-exports from the centralized inserter DOM adapter
 * (./inserter-dom.js). Import from './inserter-dom' for new code; this file
 * exists for backward compatibility with existing test imports.
 */
export { findInserterSearchInput } from './inserter-dom';
