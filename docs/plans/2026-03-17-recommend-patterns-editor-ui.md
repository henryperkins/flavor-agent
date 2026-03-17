# Recommend Patterns Editor UI — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface AI-ranked pattern recommendations inside the native block inserter's Patterns tab via a "Recommended" category, with search-bar-triggered semantic search and a toolbar badge for high-confidence matches.

**Architecture:** PHP registers a "Recommended" pattern category and a REST endpoint that delegates to the existing RAG pipeline. Client JS fetches recommendations on editor load and on inserter search input, then patches `__experimentalBlockPatterns` in editor settings to tag/describe matching patterns. A badge component renders a `!` indicator on the inserter toggle when scores exceed 0.9.

**Tech Stack:** PHP 8.0+ (WordPress Settings/REST API), JavaScript (React, `@wordpress/data`, `@wordpress/plugins`, `@wordpress/block-editor`), existing Azure OpenAI + Qdrant RAG backend.

**Spec:** `docs/specs/2026-03-17-recommend-patterns-editor-ui-design.md`

---

## File Map

### New files

| File | Responsibility |
|---|---|
| `src/patterns/PatternRecommender.js` | Plugin component: passive fetch on load, search interception, `patchInserterPatterns()` |
| `src/patterns/InserterBadge.js` | Plugin component: renders `!` badge on inserter toggle for scores ≥ 0.9 |

### Modified files

| File | Lines | Change |
|---|---|---|
| `package.json:14-24` | Add `@wordpress/plugins` + `@wordpress/editor` dependencies |
| `flavor-agent.php:25-58` | Register `recommended` category, position filter, update `wp_localize_script` |
| `inc/REST/Agent_Controller.php:15-46` | Add `POST /recommend-patterns` route + handler |
| `src/store/index.js:13-117` | Add `patternRecommendations`, `patternStatus`, `patternBadge` state/actions/selectors |
| `src/index.js:1-11` | Switch to `registerPlugin()` rendering new components |

---

## Chunk 1: PHP Backend + Dependencies

### Task 1: Add `@wordpress/plugins` and `@wordpress/editor` Dependencies

**Files:**
- Modify: `package.json:14-24`

- [ ] **Step 1: Add dependencies**

Add `@wordpress/plugins` and `@wordpress/editor` to the `dependencies` object in `package.json`:

```json
"dependencies": {
    "@wordpress/api-fetch": "^7.0.0",
    "@wordpress/block-editor": "^14.0.0",
    "@wordpress/blocks": "^13.0.0",
    "@wordpress/components": "^28.0.0",
    "@wordpress/compose": "^7.0.0",
    "@wordpress/data": "^10.0.0",
    "@wordpress/editor": "^14.0.0",
    "@wordpress/element": "^6.0.0",
    "@wordpress/hooks": "^4.0.0",
    "@wordpress/icons": "^10.0.0",
    "@wordpress/plugins": "^7.0.0"
}
```

- [ ] **Step 2: Install**

```bash
cd flavor-agent && npm install
```

Expected: `package-lock.json` updates, no errors.

- [ ] **Step 3: Verify build still works**

```bash
npm run build
```

Expected: Both `build/index.js` and `build/admin.js` emitted without errors.

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore: add @wordpress/plugins and @wordpress/editor dependencies"
```

### Task 2: Register "Recommended" Pattern Category + Update Localized Data

**Files:**
- Modify: `flavor-agent.php:25-58`

- [ ] **Step 1: Add pattern category registration and settings filter**

After the existing lifecycle hooks (line 36), add:

```php
// Recommended pattern category for AI-ranked patterns in the inserter.
add_action( 'init', function () {
    if ( function_exists( 'register_block_pattern_category' ) ) {
        register_block_pattern_category( 'recommended', [
            'label' => __( 'Recommended', 'flavor-agent' ),
        ] );
    }
} );

add_filter( 'block_editor_settings_all', function ( $settings ) {
    $cats        = $settings['__experimentalBlockPatternCategories'] ?? [];
    $recommended = null;
    $rest        = [];

    foreach ( $cats as $cat ) {
        if ( ( $cat['name'] ?? '' ) === 'recommended' ) {
            $recommended = $cat;
        } else {
            $rest[] = $cat;
        }
    }

    if ( $recommended ) {
        $settings['__experimentalBlockPatternCategories'] = array_merge( [ $recommended ], $rest );
    }

    return $settings;
} );
```

- [ ] **Step 2: Update `wp_localize_script` to include `canRecommendPatterns`**

Replace the existing `wp_localize_script` call in `flavor_agent_enqueue_editor()` (lines 54-58):

```php
wp_localize_script( 'flavor-agent-editor', 'flavorAgentData', [
    'restUrl'              => rest_url( 'flavor-agent/v1/' ),
    'nonce'                => wp_create_nonce( 'wp_rest' ),
    'hasApiKey'            => (bool) get_option( 'flavor_agent_api_key' ),
    'canRecommendPatterns' => (bool) (
        get_option( 'flavor_agent_azure_openai_endpoint' )
        && get_option( 'flavor_agent_azure_openai_key' )
        && get_option( 'flavor_agent_azure_embedding_deployment' )
        && get_option( 'flavor_agent_azure_chat_deployment' )
        && get_option( 'flavor_agent_qdrant_url' )
        && get_option( 'flavor_agent_qdrant_key' )
    ),
] );
```

- [ ] **Step 3: Verify PHP syntax**

```bash
php -l flavor-agent.php
```

Expected: No syntax errors detected.

- [ ] **Step 4: Commit**

```bash
git add flavor-agent.php
git commit -m "feat: register Recommended pattern category and canRecommendPatterns flag"
```

### Task 3: Add `POST /recommend-patterns` REST Route

**Files:**
- Modify: `inc/REST/Agent_Controller.php:9-46`

- [ ] **Step 1: Add use statement and route registration**

Add `use FlavorAgent\Abilities\PatternAbilities;` to the imports (after line 9).

Inside `register_routes()`, after the `sync-patterns` route registration (after line 45), add:

```php
register_rest_route( self::NAMESPACE, '/recommend-patterns', [
    'methods'             => 'POST',
    'callback'            => [ __CLASS__, 'handle_recommend_patterns' ],
    'permission_callback' => fn() => current_user_can( 'edit_posts' ),
    'args'                => [
        'postType'     => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
        ],
        'templateType' => [
            'required'          => false,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
        ],
        'blockContext' => [
            'required' => false,
            'type'     => 'object',
        ],
        'prompt'       => [
            'required'          => false,
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
    ],
] );
```

- [ ] **Step 2: Add handler method**

After `handle_sync_patterns()` (after line 91), add:

```php
public static function handle_recommend_patterns( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
    $input = [
        'postType'     => $request->get_param( 'postType' ),
        'templateType' => $request->get_param( 'templateType' ),
        'blockContext'  => $request->get_param( 'blockContext' ),
        'prompt'       => $request->get_param( 'prompt' ),
    ];

    $result = PatternAbilities::recommend_patterns( array_filter( $input ) );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return new \WP_REST_Response( $result, 200 );
}
```

- [ ] **Step 3: Verify PHP syntax**

```bash
php -l inc/REST/Agent_Controller.php
```

Expected: No syntax errors detected.

- [ ] **Step 4: Commit**

```bash
git add inc/REST/Agent_Controller.php
git commit -m "feat: add POST /recommend-patterns REST route"
```

---

## Chunk 2: Store Expansion

### Task 4: Add Pattern Recommendation State to the Store

**Files:**
- Modify: `src/store/index.js:13-117`

- [ ] **Step 1: Expand DEFAULT_STATE**

Add three new fields to `DEFAULT_STATE` (after `activityLog`, line 17):

```js
const DEFAULT_STATE = {
	status: 'idle',
	error: null,
	blockRecommendations: {},
	activityLog: [],
	// Pattern recommendation state (isolated lifecycle).
	patternRecommendations: [],
	patternStatus: 'idle',
	patternBadge: null,
};
```

- [ ] **Step 2: Add pattern actions**

Add these actions to the `actions` object (after `applySuggestion`, before the closing `};` on line 76):

```js
setPatternStatus( status ) {
    return { type: 'SET_PATTERN_STATUS', status };
},

setPatternRecommendations( recommendations ) {
    return { type: 'SET_PATTERN_RECS', recommendations };
},

fetchPatternRecommendations( input ) {
    return async ( { dispatch } ) => {
        // Abort any in-flight pattern request.
        if ( actions._patternAbort ) {
            actions._patternAbort.abort();
        }
        const controller = new AbortController();
        actions._patternAbort = controller;

        dispatch( actions.setPatternStatus( 'loading' ) );
        try {
            const result = await apiFetch( {
                path: '/flavor-agent/v1/recommend-patterns',
                method: 'POST',
                data: input,
                signal: controller.signal,
            } );
            dispatch( actions.setPatternRecommendations(
                result.recommendations || []
            ) );
            dispatch( actions.setPatternStatus( 'ready' ) );
        } catch ( err ) {
            if ( err.name === 'AbortError' ) {
                return; // Silently ignore aborted requests.
            }
            dispatch( actions.setPatternRecommendations( [] ) );
            dispatch( actions.setPatternStatus( 'error' ) );
        }
    };
},
```

Note: `_patternAbort` is a module-level property on the `actions` object used to track the current `AbortController`. This is acceptable because thunks are singletons within the store.

- [ ] **Step 3: Add reducer cases**

Add two new cases to the `reducer` function (before `default:`, line 97):

```js
case 'SET_PATTERN_STATUS':
    return { ...state, patternStatus: action.status };
case 'SET_PATTERN_RECS': {
    const badge = action.recommendations.find( ( r ) => r.score >= 0.9 );
    return {
        ...state,
        patternRecommendations: action.recommendations,
        patternBadge: badge ? badge.reason : null,
    };
}
```

- [ ] **Step 4: Add selectors**

Add three selectors to the `selectors` object (after `getActivityLog`, line 110):

```js
getPatternRecommendations: ( state ) => state.patternRecommendations,
getPatternBadge: ( state ) => state.patternBadge,
isPatternLoading: ( state ) => state.patternStatus === 'loading',
```

- [ ] **Step 5: Verify build**

```bash
npm run build
```

Expected: No errors. Both entries emit.

- [ ] **Step 6: Commit**

```bash
git add src/store/index.js
git commit -m "feat: add pattern recommendation state with isolated lifecycle"
```

---

## Chunk 3: PatternRecommender Component

### Task 5: Create `patchInserterPatterns` Helper and `PatternRecommender` Component

**Files:**
- Create: `src/patterns/PatternRecommender.js`

- [ ] **Step 1: Create the file**

The actual implementation file is at `src/patterns/PatternRecommender.js`. Key design decisions applied during review:

- `originalMetadata` stores `{ description, keywords, categories }` — categories are preserved and fully restored on rollback instead of filtered
- `isInserterOpened()` reads from `editorStore` (`core/editor`), not `blockEditorStore`
- `templateType` resolved best-effort from `core/edit-site`, normalized to known pattern vocabulary via `normalizeTemplateType()` (maps slugs like `single-post` to `single`), and sent in both passive and active requests
- Recommended patterns are sorted to the front of the array by server score order after patching
- Error handler in the store thunk clears stale recommendations before setting error status
- `attachToSearch()` always rebinds the listener (no early return for same element) so it picks up fresh `handleSearchInput` closures when `selectedBlockName` or `templateType` change
- Debounce timer is cleared on inserter close and effect cleanup to prevent stale network calls

See `src/patterns/PatternRecommender.js` for the complete implementation. The source file is the canonical reference. Below is a summary of the structure:

```
PatternRecommender.js (329 lines)
├── normalizeTemplateType() — maps template slugs to known pattern types
├── patchInserterPatterns() — read-modify-write with full rollback + score sort
└── PatternRecommender component
    ├── useSelect: postType, isInserterOpen (editorStore), templateType (core/edit-site), selectedBlockName
    ├── Passive fetch on mount (postType + templateType?)
    ├── Patch inserter on recommendations change
    ├── Search interception via MutationObserver + input listener
    │   ├── attachToSearch() — always rebinds (no stale closure)
    │   └── debounce cleanup on close/unmount
    └── Returns null (renderless)
```

- [ ] **Step 2: Verify build** (see Task 8 for full verification)

- [ ] **Step 3: Commit**

```bash
git add src/patterns/PatternRecommender.js
git commit -m "feat: add PatternRecommender component with patchInserterPatterns"
```

---

## Chunk 4: InserterBadge + Entry Point

### Task 6: Create `InserterBadge` Component

**Files:**
- Create: `src/patterns/InserterBadge.js`

- [ ] **Step 1: Create the file**

```js
/**
 * Inserter Badge
 *
 * Renders a "!" indicator next to the inserter toggle button
 * when a pattern recommendation scores >= 0.9. Hovering shows
 * a tooltip with the recommendation reason.
 */
import { useSelect } from '@wordpress/data';
import { useEffect, useState, createPortal } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';

import { STORE_NAME } from '../store';

export default function InserterBadge() {
	const badge = useSelect(
		( select ) => select( STORE_NAME ).getPatternBadge(),
		[]
	);
	const [ anchor, setAnchor ] = useState( null );

	useEffect( () => {
		if ( ! badge ) {
			setAnchor( null );
			return;
		}

		// Primary: stable class selector.
		let button = document.querySelector(
			'button.block-editor-inserter__toggle'
		);

		// Fallback: aria-label containing "inserter" (case-insensitive).
		if ( ! button ) {
			const allButtons = document.querySelectorAll(
				'.edit-post-header-toolbar button, .edit-site-header_start button'
			);
			for ( const b of allButtons ) {
				const label = b.getAttribute( 'aria-label' ) || '';
				if ( /inserter/i.test( label ) ) {
					button = b;
					break;
				}
			}
		}

		if ( button?.parentElement ) {
			setAnchor( button.parentElement );
		}
	}, [ badge ] );

	if ( ! badge || ! anchor ) {
		return null;
	}

	return createPortal(
		<Tooltip text={ badge }>
			<span
				aria-label="Pattern recommendations available"
				style={ {
					position: 'absolute',
					top: '2px',
					right: '-4px',
					width: '16px',
					height: '16px',
					borderRadius: '50%',
					background: '#3858e9',
					color: '#fff',
					fontSize: '11px',
					fontWeight: 700,
					lineHeight: '16px',
					textAlign: 'center',
					cursor: 'default',
					zIndex: 100,
					pointerEvents: 'auto',
				} }
			>
				!
			</span>
		</Tooltip>,
		anchor
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/patterns/InserterBadge.js
git commit -m "feat: add InserterBadge component for high-score notification"
```

### Task 7: Switch Entry Point to `registerPlugin`

**Files:**
- Modify: `src/index.js:1-11`

- [ ] **Step 1: Replace entry point**

Replace the entire contents of `src/index.js`:

```js
/**
 * Flavor Agent — Editor entry point.
 *
 * Registers:
 *   1. The data store (auto-registers on import)
 *   2. The editor.BlockEdit filter for native Inspector injection
 *   3. Plugin components for pattern recommendations + inserter badge
 */
import { registerPlugin } from '@wordpress/plugins';

// Data store (self-registering).
import './store';

// Inspector injection — adds AI controls to native Inspector tabs
// via the editor.BlockEdit filter. This import registers the filter.
import './inspector/InspectorInjector';

// Plugin components.
import PatternRecommender from './patterns/PatternRecommender';
import InserterBadge from './patterns/InserterBadge';

registerPlugin( 'flavor-agent', {
	render: () => (
		<>
			<PatternRecommender />
			<InserterBadge />
		</>
	),
} );
```

- [ ] **Step 2: Build and verify**

```bash
npm run build
```

Expected: Both `build/index.js` and `build/admin.js` emitted. No errors.

- [ ] **Step 3: Verify build artifacts include @wordpress/plugins**

```bash
cat build/index.asset.php
```

Expected: The `dependencies` array should include `wp-plugins`.

- [ ] **Step 4: Commit**

```bash
git add src/index.js
git commit -m "feat: register plugin with PatternRecommender and InserterBadge"
```

---

## Chunk 5: Build + Verification

### Task 8: Full Build and Manual Verification

- [ ] **Step 1: Clean build**

```bash
cd flavor-agent && rm -rf build && npm run build
```

Expected: `build/index.js`, `build/index.asset.php`, `build/admin.js`, `build/admin.asset.php` all present.

- [ ] **Step 2: PHP syntax check all modified files**

```bash
php -l flavor-agent.php && php -l inc/REST/Agent_Controller.php
```

Expected: No syntax errors detected.

- [ ] **Step 3: Verify in browser (if local WordPress is running)**

1. Load the block editor at `http://localhost:8888/wp-admin/post-new.php?post_type=page`
2. Open browser DevTools console
3. Check `window.flavorAgentData.canRecommendPatterns` — should be `true` if Azure/Qdrant credentials are configured, `false` otherwise
4. Check that the Flavor Agent store has pattern selectors:
   ```js
   wp.data.select('flavor-agent').getPatternRecommendations()
   wp.data.select('flavor-agent').getPatternBadge()
   wp.data.select('flavor-agent').isPatternLoading()
   ```
5. Open the inserter (`+` button) → Patterns tab → verify "Recommended" category appears in the category list (it may be empty if no credentials are configured or index is not synced)
6. If credentials are configured and index is synced: verify recommended patterns appear with contextual descriptions
7. Type in the search bar → verify the Recommended category updates after ~400ms

- [ ] **Step 4: Update adjacent abilities spec**

In `docs/specs/2026-03-16-abilities-api-integration-design.md`, update the file structure comment for `Agent_Controller.php` to note it now also exposes `recommend-patterns`:

```
│   └── Agent_Controller.php    # REST controller with recommend-block, recommend-patterns, + sync-patterns routes
```

- [ ] **Step 5: Final commit**

```bash
git add build/ docs/specs/2026-03-16-abilities-api-integration-design.md
git commit -m "feat: complete recommend-patterns editor UI integration"
```

---

## Summary of all commits

| Order | Message | Files |
|---|---|---|
| 1 | `chore: add @wordpress/plugins and @wordpress/editor dependencies` | `package.json`, `package-lock.json` |
| 2 | `feat: register Recommended pattern category and canRecommendPatterns flag` | `flavor-agent.php` |
| 3 | `feat: add POST /recommend-patterns REST route` | `inc/REST/Agent_Controller.php` |
| 4 | `feat: add pattern recommendation state with isolated lifecycle` | `src/store/index.js` |
| 5 | `feat: add PatternRecommender component with patchInserterPatterns` | `src/patterns/PatternRecommender.js` |
| 6 | `feat: add InserterBadge component for high-score notification` | `src/patterns/InserterBadge.js` |
| 7 | `feat: register plugin with PatternRecommender and InserterBadge` | `src/index.js` |
| 8 | `feat: complete recommend-patterns editor UI integration` | `build/*`, `docs/specs/*` |
