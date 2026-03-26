# Fix Style Suggestion Panel Duplication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate duplicate rendering of AI suggestions that appear in both the main recommendation panels and in their dedicated Inspector sub-panel chip groups.

**Architecture:** Extract the implicit panel-delegation contract into a shared constants module (`panel-delegation.js`). Both `StylesRecommendations` and `SettingsRecommendations` import these constants and exclude delegated panels from their own rendering. This makes the contract explicit and prevents future drift. Also fixes the incomplete hint message, a missing `panelLabel` entry, and a limited color-detection regex.

**Tech Stack:** `@wordpress/element`, `@wordpress/components`, Jest via `wp-scripts test-unit-js`

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `src/inspector/panel-delegation.js` | Single source of truth for which panels are rendered as sub-panel chips vs. in the main panel body |
| Create | `src/inspector/__tests__/panel-delegation.test.js` | Unit tests for the delegation constants and helpers |
| Modify | `src/inspector/StylesRecommendations.js:72-85,168-177,232-234,240-250` | Import delegation set; fix exclusion list, hint, isColor, panelLabel |
| Create | `src/inspector/__tests__/StylesRecommendations.test.js` | Rendering tests proving delegated panels are excluded and hint is correct |
| Modify | `src/inspector/SettingsRecommendations.js:66-73` | Import delegation set; add exclusion filter |
| Create | `src/inspector/__tests__/SettingsRecommendations.test.js` | Rendering tests proving delegated panels are excluded |

---

### Task 1: Create panel delegation constants with tests

**Files:**
- Create: `src/inspector/panel-delegation.js`
- Create: `src/inspector/__tests__/panel-delegation.test.js`

- [ ] **Step 1: Write the failing tests**

Create `src/inspector/__tests__/panel-delegation.test.js`:

```js
import {
	DELEGATED_SETTINGS_PANELS,
	DELEGATED_STYLE_PANELS,
	isDelegatedSettingsPanel,
	isDelegatedStylePanel,
} from '../panel-delegation';

describe( 'panel delegation constants', () => {
	test( 'DELEGATED_STYLE_PANELS contains all sub-panel style groups', () => {
		expect( DELEGATED_STYLE_PANELS ).toEqual(
			new Set( [
				'color',
				'typography',
				'dimensions',
				'border',
				'filter',
				'background',
			] )
		);
	} );

	test( 'DELEGATED_SETTINGS_PANELS contains all sub-panel settings groups', () => {
		expect( DELEGATED_SETTINGS_PANELS ).toEqual(
			new Set( [ 'position', 'advanced', 'bindings' ] )
		);
	} );

	test( 'isDelegatedStylePanel returns true for delegated panels', () => {
		expect( isDelegatedStylePanel( 'color' ) ).toBe( true );
		expect( isDelegatedStylePanel( 'filter' ) ).toBe( true );
		expect( isDelegatedStylePanel( 'background' ) ).toBe( true );
	} );

	test( 'isDelegatedStylePanel returns false for non-delegated panels', () => {
		expect( isDelegatedStylePanel( 'general' ) ).toBe( false );
		expect( isDelegatedStylePanel( 'shadow' ) ).toBe( false );
		expect( isDelegatedStylePanel( 'effects' ) ).toBe( false );
	} );

	test( 'isDelegatedSettingsPanel returns true for delegated panels', () => {
		expect( isDelegatedSettingsPanel( 'position' ) ).toBe( true );
		expect( isDelegatedSettingsPanel( 'advanced' ) ).toBe( true );
		expect( isDelegatedSettingsPanel( 'bindings' ) ).toBe( true );
	} );

	test( 'isDelegatedSettingsPanel returns false for non-delegated panels', () => {
		expect( isDelegatedSettingsPanel( 'general' ) ).toBe( false );
		expect( isDelegatedSettingsPanel( 'layout' ) ).toBe( false );
		expect( isDelegatedSettingsPanel( 'alignment' ) ).toBe( false );
	} );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/panel-delegation'`

Expected: FAIL — module `../panel-delegation` does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/inspector/panel-delegation.js`:

```js
/**
 * Panel delegation constants.
 *
 * Panels listed here are rendered as SuggestionChips inside their
 * dedicated InspectorControls groups (SubPanelSuggestions in
 * InspectorInjector.js). The main StylesRecommendations and
 * SettingsRecommendations panels must exclude these to prevent
 * duplicate rendering.
 *
 * Keep in sync with the SubPanelSuggestions list in InspectorInjector.js.
 */

/**
 * Style panels delegated to sub-panel chip groups.
 *
 * Maps to: color, typography, dimensions, border, filter, background
 * groups in InspectorInjector.js SubPanelSuggestions.
 */
export const DELEGATED_STYLE_PANELS = new Set( [
	'color',
	'typography',
	'dimensions',
	'border',
	'filter',
	'background',
] );

/**
 * Settings panels delegated to sub-panel chip groups.
 *
 * Maps to: position, advanced, bindings groups in
 * InspectorInjector.js SubPanelSuggestions.
 */
export const DELEGATED_SETTINGS_PANELS = new Set( [
	'position',
	'advanced',
	'bindings',
] );

/**
 * @param {string} panel Panel key.
 * @return {boolean} Whether this panel is rendered as sub-panel style chips.
 */
export function isDelegatedStylePanel( panel ) {
	return DELEGATED_STYLE_PANELS.has( panel );
}

/**
 * @param {string} panel Panel key.
 * @return {boolean} Whether this panel is rendered as sub-panel settings chips.
 */
export function isDelegatedSettingsPanel( panel ) {
	return DELEGATED_SETTINGS_PANELS.has( panel );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/panel-delegation'`

Expected: all 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/inspector/panel-delegation.js src/inspector/__tests__/panel-delegation.test.js
git commit -m "feat: add shared panel delegation constants for inspector sub-panels"
```

---

### Task 2: Fix StylesRecommendations panel exclusion and hint

**Files:**
- Modify: `src/inspector/StylesRecommendations.js:1-2,72-79,168-177,240-250`
- Create: `src/inspector/__tests__/StylesRecommendations.test.js`

- [ ] **Step 1: Write the failing tests**

Create `src/inspector/__tests__/StylesRecommendations.test.js`:

```js
const mockApplySuggestion = jest.fn();
const mockUseDispatch = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
} ) );

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		PanelBody: ( { children, title } ) =>
			createElement( 'div', { 'data-panel': title }, children ),
		Button: ( { children, className, disabled, onClick, title: btnTitle } ) =>
			createElement(
				'button',
				{ type: 'button', className, disabled, onClick, title: btnTitle },
				children
			),
		ButtonGroup: ( { children, className } ) =>
			createElement( 'div', { className }, children ),
	};
} );

jest.mock( '@wordpress/icons', () => ( {
	arrowRight: 'arrow-right',
	check: 'check',
	styles: 'styles-icon',
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

import { createElement } from '@wordpress/element';
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import StylesRecommendations from '../StylesRecommendations';

let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	jest.clearAllMocks();
	mockApplySuggestion.mockResolvedValue( true );
	mockUseDispatch.mockReturnValue( {
		applySuggestion: mockApplySuggestion,
	} );
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
	container = null;
	root = null;
} );

function renderComponent( suggestions ) {
	act( () => {
		root.render(
			createElement( StylesRecommendations, {
				clientId: 'block-1',
				suggestions,
			} )
		);
	} );
}

function makeSuggestion( panel, label = `Suggestion for ${ panel }` ) {
	return {
		label,
		description: `${ panel } description`,
		panel,
		type: 'attribute_change',
		attributeUpdates: {},
		confidence: 0.8,
	};
}

describe( 'StylesRecommendations', () => {
	test( 'does not render suggestions for delegated style panels', () => {
		const delegated = [
			makeSuggestion( 'color' ),
			makeSuggestion( 'typography' ),
			makeSuggestion( 'dimensions' ),
			makeSuggestion( 'border' ),
			makeSuggestion( 'filter' ),
			makeSuggestion( 'background' ),
		];
		const kept = [ makeSuggestion( 'shadow' ) ];

		renderComponent( [ ...delegated, ...kept ] );

		const text = container.textContent;

		// Delegated panels must NOT appear as group titles or row labels
		expect( text ).not.toContain( 'Suggestion for color' );
		expect( text ).not.toContain( 'Suggestion for typography' );
		expect( text ).not.toContain( 'Suggestion for dimensions' );
		expect( text ).not.toContain( 'Suggestion for border' );
		expect( text ).not.toContain( 'Suggestion for filter' );
		expect( text ).not.toContain( 'Suggestion for background' );

		// Non-delegated panel must render
		expect( text ).toContain( 'Suggestion for shadow' );
	} );

	test( 'renders non-delegated panels in the panel body', () => {
		renderComponent( [
			makeSuggestion( 'shadow' ),
			makeSuggestion( 'general' ),
		] );

		const text = container.textContent;
		expect( text ).toContain( 'Suggestion for shadow' );
		expect( text ).toContain( 'Suggestion for general' );
	} );

	test( 'shows hint when delegated style panels have suggestions', () => {
		renderComponent( [
			makeSuggestion( 'filter' ),
			makeSuggestion( 'shadow' ),
		] );

		expect( container.textContent ).toContain(
			'More suggestions appear in'
		);
	} );

	test( 'does not show hint when no delegated panels have suggestions', () => {
		renderComponent( [ makeSuggestion( 'shadow' ) ] );

		expect( container.textContent ).not.toContain(
			'More suggestions appear in'
		);
	} );

	test( 'renders style variations separately', () => {
		const variation = {
			label: 'Outline',
			description: 'Outline style',
			panel: 'general',
			type: 'style_variation',
			attributeUpdates: { className: 'is-style-outline' },
			isCurrentStyle: false,
			isRecommended: true,
		};

		renderComponent( [ variation ] );

		expect( container.textContent ).toContain( 'Outline' );
		expect( container.textContent ).toContain( 'Style Variations' );
	} );

	test( 'returns null for empty suggestions', () => {
		renderComponent( [] );
		expect( container.innerHTML ).toBe( '' );
	} );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/StylesRecommendations'`

Expected: FAIL — the first test fails because `filter` and `background` suggestions currently render in the panel body.

- [ ] **Step 3: Update StylesRecommendations.js**

In `src/inspector/StylesRecommendations.js`, apply these changes:

**Line 1-2** — add the import (after the existing imports, before the `FEEDBACK_MS` constant):

```js
import { DELEGATED_STYLE_PANELS } from './panel-delegation';
```

**Lines 72-79** — replace the hardcoded exclusion array with the shared set:

```js
	const byPanel = {};
	for ( const s of attributeSuggestions ) {
		if ( DELEGATED_STYLE_PANELS.has( s.panel ) ) {
			continue;
		}
		const key = getSuggestionPanel( s );
		if ( ! byPanel[ key ] ) {
			byPanel[ key ] = [];
		}
		byPanel[ key ].push( s );
	}
```

**Lines 168-177** — update the hint condition and message:

```js
			{ attributeSuggestions.some( ( s ) =>
				DELEGATED_STYLE_PANELS.has( s.panel )
			) && (
				<p className="flavor-agent-subpanel-hint flavor-agent-panel__note">
					More suggestions appear in the Color, Typography,
					Dimensions, Border, Filter, and Background panels
					above.
				</p>
			) }
```

**Lines 240-250** — add `shadow` to `panelLabel`:

```js
function panelLabel( panel ) {
	const labels = {
		general: 'General',
		layout: 'Layout',
		position: 'Position',
		advanced: 'Advanced',
		effects: 'Effects',
		shadow: 'Shadow',
	};

	return labels[ panel ] || panel;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/StylesRecommendations'`

Expected: all 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/inspector/StylesRecommendations.js src/inspector/__tests__/StylesRecommendations.test.js
git commit -m "fix: exclude filter/background from StylesRecommendations to prevent duplication"
```

---

### Task 3: Fix SettingsRecommendations panel exclusion

**Files:**
- Modify: `src/inspector/SettingsRecommendations.js:1-2,66-73`
- Create: `src/inspector/__tests__/SettingsRecommendations.test.js`

- [ ] **Step 1: Write the failing tests**

Create `src/inspector/__tests__/SettingsRecommendations.test.js`:

```js
const mockApplySuggestion = jest.fn();
const mockUseDispatch = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	useDispatch: ( ...args ) => mockUseDispatch( ...args ),
} ) );

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		PanelBody: ( { children, title } ) =>
			createElement( 'div', { 'data-panel': title }, children ),
		Button: ( { children, className, disabled, onClick } ) =>
			createElement(
				'button',
				{ type: 'button', className, disabled, onClick },
				children
			),
	};
} );

jest.mock( '@wordpress/icons', () => ( {
	Icon: ( { icon } ) => null,
	check: 'check',
	arrowRight: 'arrow-right',
} ) );

jest.mock( '../../store', () => ( {
	STORE_NAME: 'flavor-agent',
} ) );

import { createElement } from '@wordpress/element';
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
const { createRoot } = require( '@wordpress/element' );

import SettingsRecommendations from '../SettingsRecommendations';

let container = null;
let root = null;

window.IS_REACT_ACT_ENVIRONMENT = true;

beforeEach( () => {
	jest.clearAllMocks();
	mockApplySuggestion.mockResolvedValue( true );
	mockUseDispatch.mockReturnValue( {
		applySuggestion: mockApplySuggestion,
	} );
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
	container = null;
	root = null;
} );

function renderComponent( suggestions ) {
	act( () => {
		root.render(
			createElement( SettingsRecommendations, {
				clientId: 'block-1',
				suggestions,
			} )
		);
	} );
}

function makeSuggestion( panel, label = `Suggestion for ${ panel }` ) {
	return {
		label,
		description: `${ panel } description`,
		panel,
		type: 'attribute_change',
		attributeUpdates: {},
		confidence: 0.8,
		currentValue: 'old',
		suggestedValue: 'new',
	};
}

describe( 'SettingsRecommendations', () => {
	test( 'does not render suggestions for delegated settings panels', () => {
		const delegated = [
			makeSuggestion( 'position' ),
			makeSuggestion( 'advanced' ),
			makeSuggestion( 'bindings' ),
		];
		const kept = [ makeSuggestion( 'general' ) ];

		renderComponent( [ ...delegated, ...kept ] );

		const text = container.textContent;

		// Delegated panels must NOT appear as card labels
		expect( text ).not.toContain( 'Suggestion for position' );
		expect( text ).not.toContain( 'Suggestion for advanced' );
		expect( text ).not.toContain( 'Suggestion for bindings' );

		// Non-delegated panel must render
		expect( text ).toContain( 'Suggestion for general' );
	} );

	test( 'renders non-delegated panels normally', () => {
		renderComponent( [
			makeSuggestion( 'general' ),
			makeSuggestion( 'layout' ),
			makeSuggestion( 'alignment' ),
		] );

		const text = container.textContent;
		expect( text ).toContain( 'Suggestion for general' );
		expect( text ).toContain( 'Suggestion for layout' );
		expect( text ).toContain( 'Suggestion for alignment' );
	} );

	test( 'returns null when all suggestions are delegated', () => {
		renderComponent( [
			makeSuggestion( 'position' ),
			makeSuggestion( 'advanced' ),
		] );

		expect( container.innerHTML ).toBe( '' );
	} );

	test( 'returns null for empty suggestions', () => {
		renderComponent( [] );
		expect( container.innerHTML ).toBe( '' );
	} );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/SettingsRecommendations'`

Expected: FAIL — the first test fails because `position`, `advanced`, and `bindings` suggestions currently render in the panel body.

- [ ] **Step 3: Update SettingsRecommendations.js**

In `src/inspector/SettingsRecommendations.js`, apply these changes:

**Line 1-2 area** — add the import (after the existing imports, before the `FEEDBACK_MS` constant):

```js
import { DELEGATED_SETTINGS_PANELS } from './panel-delegation';
```

**Lines 66-73** — add the exclusion filter:

```js
	const grouped = {};
	for ( const s of suggestions ) {
		const key = getSuggestionPanel( s );
		if ( DELEGATED_SETTINGS_PANELS.has( key ) ) {
			continue;
		}
		if ( ! grouped[ key ] ) {
			grouped[ key ] = [];
		}
		grouped[ key ].push( s );
	}

	if ( ! Object.keys( grouped ).length ) {
		return null;
	}
```

Note the early return: if every suggestion was delegated, render nothing (the sub-panel chips handle them). This replaces the existing `! suggestions.length` check at line 62-64, which should be updated to come before the grouping loop. The full replacement for lines 62-73:

```js
	if ( ! suggestions.length ) {
		return null;
	}

	const grouped = {};
	for ( const s of suggestions ) {
		const key = getSuggestionPanel( s );
		if ( DELEGATED_SETTINGS_PANELS.has( key ) ) {
			continue;
		}
		if ( ! grouped[ key ] ) {
			grouped[ key ] = [];
		}
		grouped[ key ].push( s );
	}

	if ( ! Object.keys( grouped ).length ) {
		return null;
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/SettingsRecommendations'`

Expected: all 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/inspector/SettingsRecommendations.js src/inspector/__tests__/SettingsRecommendations.test.js
git commit -m "fix: exclude position/advanced/bindings from SettingsRecommendations to prevent duplication"
```

---

### Task 4: Fix isColor regex for modern CSS color functions

**Files:**
- Modify: `src/inspector/StylesRecommendations.js:232-234`
- Modify: `src/inspector/__tests__/StylesRecommendations.test.js` (append tests)

- [ ] **Step 1: Write the failing tests**

Append to `src/inspector/__tests__/StylesRecommendations.test.js`:

```js
describe( 'color preview swatch', () => {
	test( 'renders swatch for oklch preview value', () => {
		const suggestion = {
			label: 'Accent color',
			description: 'Use accent',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: 'oklch(0.7 0.15 240)',
		};

		renderComponent( [ suggestion ] );

		const swatch = container.querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect( swatch ).not.toBeNull();
	} );

	test( 'renders swatch for var() preview value', () => {
		const suggestion = {
			label: 'Accent var',
			description: 'Use var',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: 'var(--wp--preset--color--accent)',
		};

		renderComponent( [ suggestion ] );

		const swatch = container.querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect( swatch ).not.toBeNull();
	} );

	test( 'does not render swatch for non-color preview value', () => {
		const suggestion = {
			label: 'Font size',
			description: 'Bigger text',
			panel: 'shadow',
			type: 'attribute_change',
			attributeUpdates: {},
			confidence: 0.9,
			preview: '1.5rem',
		};

		renderComponent( [ suggestion ] );

		const swatch = container.querySelector(
			'.flavor-agent-style-row__preview'
		);
		expect( swatch ).toBeNull();
	} );
} );
```

- [ ] **Step 2: Run tests to verify the oklch test fails**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/StylesRecommendations'`

Expected: the `oklch` test FAILS (swatch is null because current regex doesn't match `oklch(`).

- [ ] **Step 3: Update the isColor function**

In `src/inspector/StylesRecommendations.js`, replace lines 232-234:

```js
function isColor( str ) {
	return /^(#|rgb|hsl|oklch|lab|lch|var\()/.test( str );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/__tests__/StylesRecommendations'`

Expected: all tests PASS (including the 3 new swatch tests).

- [ ] **Step 5: Commit**

```bash
git add src/inspector/StylesRecommendations.js src/inspector/__tests__/StylesRecommendations.test.js
git commit -m "fix: extend isColor to recognize oklch/lab/lch color functions"
```

---

### Task 5: Full test suite verification and build

**Files:**
- No file changes — verification only.

- [ ] **Step 1: Run all inspector and suggestion-key tests together**

Run: `npm run test:unit -- --runInBand --testPathPattern='inspector/'`

Expected: all tests in `inspector/` pass — panel-delegation, StylesRecommendations, SettingsRecommendations, NavigationRecommendations, suggestion-keys.

- [ ] **Step 2: Run the full JS test suite**

Run: `npm run test:unit -- --runInBand`

Expected: all tests pass, no regressions.

- [ ] **Step 3: Run production build**

Run: `npm run build`

Expected: clean build, no warnings. `build/index.js` is updated.

- [ ] **Step 4: Run lint**

Run: `npm run lint:js`

Expected: no new lint errors.

- [ ] **Step 5: Final commit (build artifacts)**

```bash
git add build/
git commit -m "chore: rebuild after style suggestion duplication fixes"
```

---

## Verification Checklist

After all tasks complete, verify in the WordPress editor:

1. Select a block that supports color + typography + dimensions (e.g., `core/group`).
2. Fetch AI suggestions with a prompt like "make this stand out".
3. Confirm:
   - Color suggestions appear ONLY as chips in the Color sub-panel, NOT as cards in the AI Style Suggestions panel.
   - Typography suggestions appear ONLY as chips in the Typography sub-panel.
   - Same for dimensions, border, filter, background.
   - Shadow/general/layout suggestions appear as cards in the AI Style Suggestions panel (no sub-panel chips for these).
   - The hint message mentions all 6 delegated panels and only appears when at least one delegated panel has suggestions.
4. Select a block and confirm:
   - Position/advanced/bindings suggestions appear ONLY as chips in their sub-panels, NOT as cards in the AI Settings panel.
   - General/layout/alignment suggestions still appear as cards in the AI Settings panel.
5. If a suggestion has an `oklch(...)` preview value, confirm the color swatch renders.
