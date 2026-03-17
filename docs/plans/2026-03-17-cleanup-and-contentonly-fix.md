# Cleanup & ContentOnly Enforcement Fix

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Archive stale plans, write a STATUS.md, mark legacy design docs as superseded, and close the contentOnly UI enforcement gap in the Inspector.

**Architecture:** Two independent workstreams. Workstream 1 is pure file moves and new docs. Workstream 2 extends the existing `update-helpers.js` data layer to also check the block's own `editingMode`, then gates the `InspectorInjector` component on editing mode so disabled blocks get no AI panels and content-restricted blocks get an explanatory notice.

**Tech Stack:** JavaScript (WordPress data stores, React components), Jest for unit tests.

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `STATUS.md` | Project status: working / stubbed / superseded / known issues |
| Create | `docs/plans/completed/` | Directory for archived plans |
| Move | `docs/plans/2026-03-16-abilities-api-integration.md` → `docs/plans/completed/` | Archive |
| Move | `docs/plans/2026-03-16-recommend-patterns.md` → `docs/plans/completed/` | Archive |
| Move | `docs/plans/2026-03-17-recommend-patterns-editor-ui.md` → `docs/plans/completed/` | Archive |
| Modify | `docs/LLM-WordPress-Assistant.md:1` | Add superseded notice |
| Modify | `docs/LLM-WordPress-Assistant-Notes.md:1` | Add superseded notice |
| Modify | `docs/LLM-WordPress-Phases.md:1` | Add superseded notice |
| Modify | `src/store/update-helpers.js:151-196` | Handle `editingMode` in sanitize + getSuggestionAttributeUpdates |
| Test | `src/store/update-helpers.test.js` | New tests for `editingMode` scenarios |
| Modify | `src/inspector/InspectorInjector.js:21-50` | Read editing mode, gate rendering |

---

## Chunk 1: Clean House

### Task 1: Archive completed plans

**Files:**
- Create: `docs/plans/completed/` (directory)
- Move: `docs/plans/2026-03-16-abilities-api-integration.md`
- Move: `docs/plans/2026-03-16-recommend-patterns.md`
- Move: `docs/plans/2026-03-17-recommend-patterns-editor-ui.md`

- [ ] **Step 1: Create completed directory and move plans**

```bash
mkdir -p docs/plans/completed
git mv docs/plans/2026-03-16-abilities-api-integration.md docs/plans/completed/
git mv docs/plans/2026-03-16-recommend-patterns.md docs/plans/completed/
git mv docs/plans/2026-03-17-recommend-patterns-editor-ui.md docs/plans/completed/
```

- [ ] **Step 2: Verify moves**

Run: `ls docs/plans/completed/`
Expected: Three `.md` files listed.

- [ ] **Step 3: Commit**

```bash
git add docs/plans/completed/
git commit -m "chore: move completed plans to docs/plans/completed/"
```

---

### Task 2: Mark legacy design docs as superseded

**Files:**
- Modify: `docs/LLM-WordPress-Assistant.md:1`
- Modify: `docs/LLM-WordPress-Assistant-Notes.md:1`
- Modify: `docs/LLM-WordPress-Phases.md:1`

These three docs describe a Dispatcher → Generator/Transformer/Executor architecture
that was never implemented. The project uses an Abilities-native design instead.

- [ ] **Step 1: Add superseded banner to each doc**

Prepend the following block to the top of each file (before the existing first line):

```markdown
> **⚠ SUPERSEDED** — This document describes an early design (Dispatcher/Generator/Transformer/Executor, REST-only approval flow) that was never implemented. The project evolved to an Abilities API–native, dual-backend architecture. See `STATUS.md` for what's actually built. Kept for historical reference only.

---

```

Apply to:
- `docs/LLM-WordPress-Assistant.md`
- `docs/LLM-WordPress-Assistant-Notes.md`
- `docs/LLM-WordPress-Phases.md`

- [ ] **Step 2: Commit**

```bash
git add docs/LLM-WordPress-Assistant.md docs/LLM-WordPress-Assistant-Notes.md docs/LLM-WordPress-Phases.md
git commit -m "docs: mark legacy design docs as superseded"
```

---

### Task 3: Write STATUS.md

**Files:**
- Create: `STATUS.md`

- [ ] **Step 1: Create STATUS.md**

```markdown
# Flavor Agent — Status

> Last updated: 2026-03-17

## Working

### Abilities API (WP 6.9+)

| Ability | Handler | Description |
|---------|---------|-------------|
| `flavor-agent/recommend-block` | `BlockAbilities` | Full LLM pipeline: ServerCollector → Prompt → Client → per-tab suggestions |
| `flavor-agent/introspect-block` | `BlockAbilities` | Block type registry introspection |
| `flavor-agent/recommend-patterns` | `PatternAbilities` | Azure OpenAI embeddings + Qdrant vector search + GPT reranking |
| `flavor-agent/list-patterns` | `PatternAbilities` | WP patterns registry with category/blockType/templateType filtering |
| `flavor-agent/list-template-parts` | `TemplateAbilities` | WP template parts with area filtering |
| `flavor-agent/get-theme-tokens` | `InfraAbilities` | theme.json global settings and styles extraction |
| `flavor-agent/check-status` | `InfraAbilities` | Backend configuration status and available abilities |

### REST API

| Route | Permission | Description |
|-------|------------|-------------|
| `POST /flavor-agent/v1/recommend-block` | `edit_posts` | Block recommendations (client-provided context) |
| `POST /flavor-agent/v1/sync-patterns` | `manage_options` | Trigger pattern index sync |
| `POST /flavor-agent/v1/recommend-patterns` | `edit_posts` | Pattern recommendations (delegates to PatternAbilities) |

### Editor UI

- Inspector sidebar: AI recommendation panels injected via `editor.BlockEdit` filter
- Pattern inserter: "Recommended" category with AI-ranked patterns + badge for high-confidence matches
- Admin page: Pattern index sync button

## Stubbed (501)

| Ability | Permission | What's needed |
|---------|------------|---------------|
| `flavor-agent/recommend-template` | `edit_theme_options` | `ServerCollector::for_template()`, system prompt in `Prompt.php`, ability implementation |
| `flavor-agent/recommend-navigation` | `edit_theme_options` | `ServerCollector::for_navigation()`, system prompt, ability implementation |

## Known Issues

- **REST/Abilities code path duplication**: The `/recommend-block` REST route has its own inline implementation parallel to `BlockAbilities::recommend_block()`. The REST route takes client-provided context via `editorContext`; the Abilities version builds context server-side via `ServerCollector::for_block()`. These should be consolidated.

## Superseded Design Docs

These files in `docs/` describe an earlier architecture (Dispatcher → Generator/Transformer/Executor, REST-only, approval-based) that was never implemented. The project evolved to an Abilities-native, dual-backend design:

- `docs/LLM-WordPress-Assistant.md`
- `docs/LLM-WordPress-Assistant-Notes.md`
- `docs/LLM-WordPress-Phases.md`

Kept for historical reference. Each file has a superseded banner.
```

- [ ] **Step 2: Commit**

```bash
git add STATUS.md
git commit -m "docs: add STATUS.md with project inventory"
```

---

## Chunk 2: ContentOnly Enforcement Fix

### Task 4: Write failing tests for editingMode handling

**Files:**
- Modify: `src/store/update-helpers.test.js`

The existing tests only cover `isInsideContentOnly` (parent chain). We need tests for:
- Block's own `editingMode === 'contentOnly'` treated the same as `isInsideContentOnly`
- `editingMode === 'disabled'` returns empty everything

- [ ] **Step 1: Add test for editingMode === 'contentOnly' sanitization**

Append to the `describe( 'update helpers', () => { ... })` block in `src/store/update-helpers.test.js`:

```js
test( 'sanitizeRecommendationsForContext restricts when block itself is contentOnly', () => {
	const recommendations = {
		settings: [
			{ label: 'Toggle', attributeUpdates: { isToggled: true } },
		],
		styles: [
			{ label: 'Bold bg', attributeUpdates: { style: { color: { background: '#000' } } } },
		],
		block: [
			{ label: 'Update content', attributeUpdates: { content: 'New text', metadata: { foo: 1 } } },
		],
		explanation: 'Test',
	};
	const blockContext = {
		editingMode: 'contentOnly',
		isInsideContentOnly: false,
		contentAttributes: { content: { role: 'content' } },
	};

	const result = sanitizeRecommendationsForContext( recommendations, blockContext );

	expect( result.settings ).toEqual( [] );
	expect( result.styles ).toEqual( [] );
	expect( result.block ).toEqual( [
		{ label: 'Update content', attributeUpdates: { content: 'New text' } },
	] );
} );
```

- [ ] **Step 2: Add test for editingMode === 'disabled' sanitization**

```js
test( 'sanitizeRecommendationsForContext returns empty for disabled blocks', () => {
	const recommendations = {
		settings: [
			{ label: 'Toggle', attributeUpdates: { isToggled: true } },
		],
		styles: [
			{ label: 'Bold bg', attributeUpdates: { style: { color: { background: '#000' } } } },
		],
		block: [
			{ label: 'Update content', attributeUpdates: { content: 'New text' } },
		],
		explanation: 'Disabled block test',
	};
	const blockContext = {
		editingMode: 'disabled',
		isInsideContentOnly: false,
		contentAttributes: { content: { role: 'content' } },
	};

	const result = sanitizeRecommendationsForContext( recommendations, blockContext );

	expect( result.settings ).toEqual( [] );
	expect( result.styles ).toEqual( [] );
	expect( result.block ).toEqual( [] );
	expect( result.explanation ).toBe( 'Disabled block test' );
} );
```

- [ ] **Step 3: Add test for getSuggestionAttributeUpdates with editingMode === 'contentOnly'**

```js
test( 'getSuggestionAttributeUpdates restricts when block itself is contentOnly', () => {
	const blockContext = {
		editingMode: 'contentOnly',
		isInsideContentOnly: false,
		contentAttributes: { content: { role: 'content' } },
	};

	expect(
		getSuggestionAttributeUpdates(
			{ attributeUpdates: { content: 'Hi', backgroundColor: 'accent' } },
			blockContext
		)
	).toEqual( { content: 'Hi' } );
} );
```

- [ ] **Step 4: Add test for getSuggestionAttributeUpdates with editingMode === 'disabled'**

```js
test( 'getSuggestionAttributeUpdates returns empty for disabled blocks', () => {
	const blockContext = {
		editingMode: 'disabled',
		isInsideContentOnly: false,
		contentAttributes: { content: { role: 'content' } },
	};

	expect(
		getSuggestionAttributeUpdates(
			{ attributeUpdates: { content: 'Hi' } },
			blockContext
		)
	).toEqual( {} );
} );
```

- [ ] **Step 5: Add test for disabled + isInsideContentOnly precedence**

When both `editingMode === 'disabled'` and `isInsideContentOnly` are true, disabled should win (return empty everything, not content-only filtering).

```js
test( 'sanitizeRecommendationsForContext: disabled takes precedence over isInsideContentOnly', () => {
	const recommendations = {
		settings: [],
		styles: [],
		block: [
			{ label: 'Update content', attributeUpdates: { content: 'New text' } },
		],
		explanation: 'Precedence test',
	};
	const blockContext = {
		editingMode: 'disabled',
		isInsideContentOnly: true,
		contentAttributes: { content: { role: 'content' } },
	};

	const result = sanitizeRecommendationsForContext( recommendations, blockContext );

	expect( result.block ).toEqual( [] );
	expect( result.explanation ).toBe( 'Precedence test' );
} );
```

- [ ] **Step 6: Run tests — expect 5 new tests to FAIL**

Run: `npm run test:unit -- --testPathPattern=update-helpers`
Expected: 5 failures — `sanitizeRecommendationsForContext` and `getSuggestionAttributeUpdates` don't check `editingMode` yet. The 5 existing tests still pass.

---

### Task 5: Implement editingMode checks in update-helpers.js

**Files:**
- Modify: `src/store/update-helpers.js:151-196`

Two functions need the same change: check `editingMode` in addition to `isInsideContentOnly`.

- [ ] **Step 1: Add isContentRestricted helper**

Add this function after the existing `getContentAttributeKeys` function (after line 61):

```js
/**
 * Derive editing restrictions from block context.
 *
 * WordPress editing modes: 'default' (unrestricted), 'contentOnly', 'disabled'.
 * 'default' intentionally falls through — no restrictions applied.
 *
 * @param {Object} blockContext Block context.
 * @return {{ contentOnly: boolean, disabled: boolean }} Editing restriction flags.
 */
function getEditingRestrictions( blockContext ) {
	return {
		disabled: blockContext?.editingMode === 'disabled',
		contentOnly:
			blockContext?.isInsideContentOnly ||
			blockContext?.editingMode === 'contentOnly',
	};
}
```

- [ ] **Step 2: Update sanitizeRecommendationsForContext**

Replace the existing function body (lines 151-176) with:

```js
export function sanitizeRecommendationsForContext(
	recommendations,
	blockContext = {}
) {
	const normalized = normalizeSuggestionGroups( recommendations );
	const restrictions = getEditingRestrictions( blockContext );

	if ( restrictions.disabled ) {
		return {
			...normalized,
			settings: [],
			styles: [],
			block: [],
		};
	}

	if ( ! restrictions.contentOnly ) {
		return normalized;
	}

	const contentAttributeKeys = getContentAttributeKeys( blockContext );

	return {
		...normalized,
		settings: [],
		styles: [],
		block: normalized.block
			.map( ( suggestion ) =>
				filterSuggestionForContentOnly(
					suggestion,
					contentAttributeKeys
				)
			)
			.filter( Boolean ),
	};
}
```

- [ ] **Step 3: Update getSuggestionAttributeUpdates**

Replace the existing function body (lines 183-196) with:

```js
export function getSuggestionAttributeUpdates( suggestion, blockContext = {} ) {
	if ( ! isPlainObject( suggestion?.attributeUpdates ) ) {
		return {};
	}

	const restrictions = getEditingRestrictions( blockContext );

	if ( restrictions.disabled ) {
		return {};
	}

	if ( ! restrictions.contentOnly ) {
		return suggestion.attributeUpdates;
	}

	return filterAttributeUpdatesForContentOnly(
		suggestion.attributeUpdates,
		getContentAttributeKeys( blockContext )
	);
}
```

- [ ] **Step 4: Run tests — all 10 should PASS**

Run: `npm run test:unit -- --testPathPattern=update-helpers`
Expected: 10 tests pass (5 existing + 5 new).

- [ ] **Step 5: Commit**

```bash
git add src/store/update-helpers.js src/store/update-helpers.test.js
git commit -m "fix: enforce contentOnly for block's own editingMode and disabled blocks

The data layer previously only checked isInsideContentOnly (parent chain).
Now also checks the block's own editingMode for contentOnly and disabled."
```

---

### Task 6: Gate InspectorInjector on editing mode

**Files:**
- Modify: `src/inspector/InspectorInjector.js:21-50,58-62`

The component needs to:
1. Read `editingMode` and `isInsideContentOnly` from `core/block-editor`
2. Return early (no AI panels) when `editingMode === 'disabled'`
3. Show an info notice when content-restricted

- [ ] **Step 1: Add editing mode selector**

In the `withAIRecommendations` HOC, add a second `useSelect` call after the existing one (after line 35). No new imports needed — `useSelect` is already imported and the store is referenced by string name `'core/block-editor'`:

```js
const { editingMode, isInsideContentOnly } = useSelect(
	( sel ) => {
		const editor = sel( 'core/block-editor' );
		const mode = editor.getBlockEditingMode( clientId );
		const parentIds = editor.getBlockParents( clientId );
		return {
			editingMode: mode,
			isInsideContentOnly: parentIds.some(
				( parentId ) =>
					editor.getBlockEditingMode( parentId ) ===
					'contentOnly'
			),
		};
	},
	[ clientId ]
);

const isDisabled = editingMode === 'disabled';
const isContentRestricted =
	editingMode === 'contentOnly' || isInsideContentOnly;
```

- [ ] **Step 2: Gate rendering for disabled blocks**

Change the existing early return (line 48-50) from:

```js
if ( ! isSelected ) {
	return <BlockEdit { ...props } />;
}
```

to:

```js
if ( ! isSelected || isDisabled ) {
	return <BlockEdit { ...props } />;
}
```

- [ ] **Step 3: Add content-restriction notice inside PanelBody**

Inside the `<PanelBody title="AI Recommendations" ...>`, add a notice immediately after the opening `<PanelBody>` tag and before the textarea `<div>` (before line 68):

```jsx
{ isContentRestricted && (
	<Notice
		status="info"
		isDismissible={ false }
		style={ { margin: '0 0 8px' } }
	>
		This block is inside a content-only container
		— only content edits are available.
	</Notice>
) }
```

- [ ] **Step 4: Build and verify**

Run: `npm run build`
Expected: Build succeeds with no errors. Output in `build/index.js`.

- [ ] **Step 5: Commit**

```bash
git add src/inspector/InspectorInjector.js
git commit -m "fix: gate Inspector AI panels on block editing mode

- Skip all AI panels for disabled blocks (editingMode === 'disabled')
- Show info notice for content-restricted blocks (contentOnly or inside contentOnly container)
- Panels for settings/styles were already empty due to data layer filtering;
  this adds UI-level gating and user feedback"
```

---

## Verification

After all tasks:

- [ ] Run full test suite: `npm run test:unit`
- [ ] Run lint: `npm run lint:js`
- [ ] Run build: `npm run build`
- [ ] Verify `STATUS.md` exists at repo root
- [ ] Verify `docs/plans/completed/` has 3 files
- [ ] Verify `docs/plans/` has only this plan remaining (plus the completed/ dir)
- [ ] Verify 3 legacy docs have superseded banners
