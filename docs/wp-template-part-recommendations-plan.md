# wp_template_part Recommendations Plan

> Created: 2026-03-23
> Scope: detailed implementation plan for first-class `wp_template_part` recommendations in the Site Editor
> Baseline: `wp_template` recommendations already exist and are executable; `wp_template_part` currently has no panel, no collector, and no prompt

## Goal

Ship a first-class recommendation surface for `wp_template_part` entities that feels native to the existing template panel, but models the real problem correctly:

1. A template is a slot map of assigned parts plus optional patterns.
2. A template part is a single editable block tree inside a fixed area such as `header`, `footer`, `sidebar`, or `navigation-overlay`.
3. Because those are different object models, `wp_template_part` should not be bolted onto the current template pipeline as a shallow post-type check.

The plan below favors a phased rollout:

1. advisory recommendations first
2. safe executable pattern insertion second
3. broader subtree replacement only after the advisory and apply loops are stable

## Current State

### What exists now

- [TemplateRecommender.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/templates/TemplateRecommender.js) mounts only when `getEditedPostType() === 'wp_template'`.
- [flavor-agent.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/flavor-agent.php) already localizes `canRecommendTemplates` and `templatePartAreas` to the editor client.
- [TemplateAbilities.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/inc/Abilities/TemplateAbilities.php) exposes `recommend_template()` and `list_template_parts()`.
- [TemplatePrompt.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/inc/LLM/TemplatePrompt.php) is built around template-part assignment and replacement, not block-tree composition inside a single part.
- [ServerCollector.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/inc/Context/ServerCollector.php) has `for_template()`, `for_template_parts()`, and navigation collectors, but no `for_template_part()`.
- [template-actions.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/utils/template-actions.js) already contains deterministic block-tree helpers, pattern insertion, apply, and undo logic that can be reused.
- [template-part-areas.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/utils/template-part-areas.js) already wraps the localized `templatePartAreas` lookup for client-side area inference.
- [src/store/index.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/store/index.js), [activity-history.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/store/activity-history.js), and [template-actions.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/utils/template-actions.js) already scope activity sessions by edited document, but template apply/undo bookkeeping is still hard-coded to `surface: 'template'`.

### What is missing

- No UI surface for `wp_template_part`.
- No server context collector for a single template part document.
- No prompt/output schema for part-level composition recommendations.
- No tests for `wp_template_part` fetch, preview, or apply flows.

## Recommended Architecture

### Core decision

Add a dedicated ability and prompt for template parts instead of overloading `recommend_template()`.

Recommended new server entry point:

- `flavor-agent/recommend-template-part`

Recommended new PHP methods and classes:

- `TemplateAbilities::recommend_template_part()`
- `ServerCollector::for_template_part()`
- `FlavorAgent\LLM\TemplatePartPrompt`

Recommended new client surface:

- `TemplatePartRecommender.js`

Recommended localized client capability:

- add `canRecommendTemplateParts` in [flavor-agent.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/flavor-agent.php) instead of implicitly reusing `canRecommendTemplates`

### Why a separate pipeline is the right choice

1. The current template output schema assumes operations like `assign_template_part` and `replace_template_part`, which do not make sense while editing the internals of a single part.
2. `wp_template_part` recommendations need a different prompt:
   - area-aware
   - block-tree-aware
   - pattern-centric
   - potentially block-selection-aware
3. A separate ability keeps validation tighter and lets the template-level surface continue evolving independently.

## Product Shape

### Phase 1 user experience

When the Site Editor is editing a `wp_template_part`:

1. Show a document settings panel named `AI Template Part Recommendations`.
2. Display the current area and slug, for example:
   - `Header Template Part`
   - `Footer Template Part`
   - `Navigation Overlay Template Part`
3. Let the user ask for goals such as:
   - "Make this header feel lighter and more editorial."
   - "Add a compact utility row."
   - "Improve this footer layout."
4. Return 1-3 recommendations with:
   - short label
   - short description
   - affected block hints
   - suggested patterns to browse
   - optional executable operations, if the suggestion can be represented safely
5. Allow the user to click linked blocks or browse linked patterns.

Phase 1 should be advisory-first. It can include executable operations when they are safe, but the panel must still be useful when a suggestion is browse-only.

### Phase 2 user experience

Add confirmable apply support for safe pattern insertion inside the current template part:

1. Show the exact insertion target:
   - start of the part
   - end of the part
   - after the currently selected block
2. Require explicit confirmation before mutation.
3. Reuse existing activity logging and undo patterns so the flow matches template recommendations.

### Phase 3 user experience

If Phase 2 proves stable, add safe subtree replacement for well-scoped suggestions:

1. replace a target slice with a validated pattern
2. preserve undo
3. reject ambiguous operations rather than guessing

## Scope Boundaries

### In scope for initial implementation

- `wp_template_part` post type only
- document-level panel in the Site Editor
- part-aware collector and prompt
- advisory recommendations
- pattern browse links
- block selection links
- optional safe `insert_pattern` execution

### Explicitly out of scope for the first version

- raw block markup generation
- free-form AI rewrites of the whole template part
- destructive delete operations
- auto-merging arbitrary AI-generated block trees into existing content
- collapsing `wp_navigation` recommendations into the same ability

## Data Model

### Proposed input schema

New ability input:

```json
{
  "templatePartRef": "theme//header",
  "prompt": "Make this header more editorial."
}
```

Optional future fields:

```json
{
  "templatePartRef": "theme//header",
  "templatePartArea": "header",
  "selectedBlockPath": [0, 1],
  "prompt": "Add a compact utility row."
}
```

Notes:

1. `templatePartRef` should be the canonical document identifier from the Site Editor, parallel to `templateRef`.
2. `templatePartArea` can be derived server-side and should stay optional unless tests or client validation benefit materially from sending it explicitly.
3. `selectedBlockPath` should stay optional for Phase 1 advisory requests and for Phase 2 `start` / `end` insertion.
4. If executable placement ever grows beyond `start` / `end`, the request must carry a stable block locator and the response must echo an explicit target path. Do not resolve executable placement from whatever block happens to be selected at apply time.

### Proposed context shape

`ServerCollector::for_template_part()` should return a focused context like:

```php
[
	'templatePartRef' => 'theme//header',
	'slug'            => 'header',
	'title'           => 'Header',
	'area'            => 'header',
	'blockTree'       => [
		[
			'path'       => [0],
			'name'       => 'core/group',
			'attributes' => [
				'tagName'    => 'header',
				'align'      => 'wide',
				'layoutType' => 'flex',
			],
			'childCount' => 2,
			'children'   => [
				[
					'path'       => [0, 0],
					'name'       => 'core/site-logo',
					'attributes' => [],
					'childCount' => 0,
					'children'   => [],
				],
				[
					'path'       => [0, 1],
					'name'       => 'core/navigation',
					'attributes' => [
						'overlayMenu'     => 'mobile',
						'maxNestingLevel' => 2,
					],
					'childCount' => 0,
					'children'   => [],
				],
			],
		],
	],
	'topLevelBlocks'  => [ 'core/group', 'core/navigation' ],
	'structureStats'  => [
		'blockCount'    => 8,
		'maxDepth'      => 3,
		'hasNavigation' => true,
	],
	'patterns'        => [ /* area-relevant candidate patterns */ ],
	'themeTokens'     => self::for_tokens(),
]
```

Important implementation note:

- Follow the existing `for_template()` convention: parse raw block content internally, summarize it, and discard it. Do not return serialized `content` from the public collector shape.
- `blockTree` should be depth-bounded and stable. Recommended Phase 1 format for every node is:
  - `path`: integer array from the template-part root
  - `name`: block name
  - `attributes`: selected scalar layout/composition attributes only
  - `childCount`: total direct children
  - `children`: recursively summarized children, capped to a small depth such as 2 or 3
- Keep `attributes` intentionally narrow so the prompt sees composition signals without prompt bloat. Good initial fields are `tagName`, `align`, `layoutType`, `layoutJustifyContent`, `layoutOrientation`, `overlayMenu`, `maxNestingLevel`, `showSubmenuIcon`, and `placeholder`.

### Recommended collector fields

The collector should include:

1. identity
   - `templatePartRef`
   - `slug`
   - `title`
   - `area`
2. structural summary
   - top-level block names
   - block counts by type
   - max nesting depth
   - whether the part contains `core/navigation`
   - whether it contains logo, site title, search, social links, query, columns, buttons, spacer, separator
3. selected structural hints
   - first top-level block type
   - last top-level block type
   - whether the part is mostly one wrapper group
   - whether it is empty or nearly empty
4. area-aware pattern candidates
   - header candidates
   - footer candidates
   - sidebar candidates
   - navigation overlay candidates
   - returned from a dedicated helper such as `collect_template_part_candidate_patterns()`, parallel to `collect_template_candidate_patterns()`
5. theme tokens
   - reuse `for_tokens()`
6. docs guidance hooks
   - for Phase 1, keep docs grounding on exact-query cache plus area-aware `familyContext`; do not introduce a `template-part:{area}` entity key yet
   - include area, slug, and key structure hints in the query text so exact-query cache entries stay specific to the current part
   - use an area-aware family context, for example `surface: 'template-part'`, `area: 'header'`, `slug: 'header'`, `hasNavigation: true`
   - if area-scoped entity caching becomes necessary later, first extend `AISearchClient` entity-key normalization, warm-set coverage, and tests

## Prompt Design

### Recommended approach

Create a new prompt class instead of stretching `TemplatePrompt`.

Suggested file:

- `inc/LLM/TemplatePartPrompt.php`

### Why a new prompt class is cleaner

`TemplatePrompt` is centered on:

- assigned parts
- empty areas
- available parts
- template-global pattern candidates

`wp_template_part` needs:

- area-specific composition guidance
- internal block-tree understanding
- optional safe insertion operations

Those concerns are different enough that a dedicated prompt is easier to reason about and test.

### Proposed response shape

For Phase 1 and Phase 2:

```json
{
  "suggestions": [
    {
      "label": "Add a compact utility strip",
      "description": "A slim top row can hold support links or contact details without crowding the primary nav.",
      "operations": [
        {
          "type": "insert_pattern",
          "patternName": "theme/header-utility-row",
          "placement": "start"
        }
      ],
      "targetBlocks": [
        {
          "path": [0],
          "blockName": "core/group",
          "reason": "Current header wrapper where the new row should sit above navigation."
        }
      ],
      "patternSuggestions": [
        "theme/header-utility-row"
      ]
    }
  ],
  "explanation": "The current header has the right core elements but needs stronger hierarchy."
}
```

### Recommended operation policy

Phase 1:

- allow `operations` to be empty
- treat the result as advisory if no safe operation is available

Phase 2:

- support only:
  - `insert_pattern`
- require executable operations to include:
  - `patternName`
  - `placement`
- supported placements:
  - `start`
  - `end`
- resolve insertion from operation metadata against the current template-part root, not from the live inserter position
- optional later: `after_selected_block`, but only after the request/response schema carries a stable target block path or locator and the executor validates it before mutation

Phase 3:

- consider adding:
  - `replace_block_slice_with_pattern`

Do not add reorder, delete, or arbitrary block generation until the part-level activity and undo model is proven.

### Prompt rules to include

1. Do not invent pattern names.
2. Respect the template part area.
3. Prefer suggestions that match the area:
   - `header`: navigation, branding, utility rows, CTAs
   - `footer`: multi-column info, contact, social, legal
   - `sidebar`: supporting navigation, search, related content
   - `navigation-overlay`: overlay navigation and mobile menu behavior
4. Prefer using theme presets and tokens.
5. Do not emit destructive operations.
6. If no safe operation exists, still return advisory suggestions with linked targets and pattern suggestions.

## Pattern Candidate Strategy

### Initial strategy

Start with heuristics instead of waiting for perfect inserter-root fidelity:

1. add a dedicated helper in [ServerCollector.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/inc/Context/ServerCollector.php), for example `collect_template_part_candidate_patterns( string $area ): array`
2. start from `for_patterns( null, null, null )` for Phase 1 so area scoring is inclusive; `wp_template_part` areas do not map cleanly to the existing `templateTypes` filter
3. strip `content` from every candidate before returning it, matching `collect_template_candidate_patterns()`
4. rank candidates by:
   - explicit `templateTypes` matches where helpful
   - area-specific title and slug heuristics
   - block-type compatibility if available
5. keep the candidate list capped at 20, lower than the template cap of 30 because parts are narrower in scope

### Area heuristics

Recommended keyword families:

- `header`: header, masthead, navigation, menu, branding, hero-top, utility
- `footer`: footer, legal, contact, social, site-info, newsletter
- `sidebar`: sidebar, aside, filters, related, meta, secondary-nav
- `navigation-overlay`: overlay, mobile menu, offcanvas, drawer, navigation

### Future improvement

If Gutenberg exposes enough stable context from the current root, later narrow candidates with the existing `for_patterns( $categories, $block_types, $template_types )` filters, especially `$block_types`, before the heuristic rerank pass.

## Server Implementation Plan

### Phase 1: collector, ability, prompt, advisory parsing

Likely files:

- `inc/Abilities/Registration.php`
- `inc/Abilities/TemplateAbilities.php`
- `inc/Context/ServerCollector.php`
- `inc/LLM/TemplatePartPrompt.php`
- `inc/REST/Agent_Controller.php`
- `tests/phpunit/TemplatePart*`

Steps:

1. Register a new ability:
   - `flavor-agent/recommend-template-part`
2. Add a new static route handler in [Agent_Controller.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/inc/REST/Agent_Controller.php):
   - route: `/recommend-template-part`
   - handler: `handle_recommend_template_part()`
   - keep it in the existing flat controller rather than introducing a new controller pattern
3. Implement `ServerCollector::for_template_part( string $template_part_ref ): array|\WP_Error`.
4. Resolve the template part by canonical ref first, slug fallback second, mirroring `for_template()`.
5. Parse block content and build a compact structural summary.
6. Parse-and-discard raw content internally; do not expose serialized block markup in the returned collector shape.
7. Collect pattern candidates filtered and ranked by area.
8. Build area-aware docs guidance query and cache family context.
9. Add `TemplatePartPrompt::build_system()`, `build_user()`, and `parse_response()`.
10. Validate:
   - only known pattern names
   - only supported operation types
   - only supported placement values
   - valid block paths if target blocks are returned

### Phase 2: execution wiring

Likely files:

- `src/utils/template-actions.js`
- `src/store/index.js`
- `src/templates/template-part-recommender-helpers.js`

Steps:

1. Extend apply helpers to support part-scoped `insert_pattern` with operation-driven placement, not the live `getBlockInsertionPoint()` result.
2. For Phase 2, support only deterministic placement:
   - `start`
   - `end`
3. Capture root locator, resolved insertion index, and inserted block snapshots for refresh-safe undo.
4. If block-relative placement is added later, require a stable target path/locator in the operation and re-resolve it before mutation.
5. Reuse the existing activity entry format only after the undo/apply bookkeeping is generalized for a second document surface; do not introduce `surface: 'template-part'` without also updating persisted history normalization, undo selectors, and post-undo reducer cleanup.
6. Keep validation ahead of mutation:
   - abort if pattern is missing
   - abort if insertion root cannot be resolved
   - abort if the live tree drifted too far from the recommendation context

## Client Implementation Plan

### Panel strategy

Do not widen the current `TemplateRecommender` with a large branching tree if it starts mixing unrelated logic. Prefer one of these two options:

1. preferred: create `TemplatePartRecommender.js`
2. acceptable fallback: extract shared panel pieces and keep `TemplateRecommender.js` thin

### Recommended client files

- `flavor-agent.php`
- `src/index.js`
- `src/templates/TemplatePartRecommender.js`
- `src/templates/template-part-recommender-helpers.js`
- `src/utils/template-actions.js`
- `src/store/index.js`
- `src/editor.css`

### Client steps

1. Add a dedicated `canRecommendTemplateParts` client flag in [flavor-agent.php](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/flavor-agent.php).
2. Mount the panel when `getEditedPostType() === 'wp_template_part'`.
3. Resolve the current edited post id or ref from `core/edit-site`.
4. Reuse the already localized `flavorAgentData.templatePartAreas` map through [template-part-areas.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/utils/template-part-areas.js) for area label display and client-side validation; do not add a second fetch path.
5. Fetch recommendations through the new REST route.
6. Render:
   - area label
   - explanation
   - suggestion cards
   - linked blocks
   - linked patterns
7. For Phase 1, support:
   - browse to linked blocks
   - open inserter filtered to linked patterns
8. For Phase 2, add:
   - preview state
   - confirm button
   - apply status
   - undo entry

### Store strategy

Do not blindly clone the current `template*` store slice before the template-specific apply/undo bookkeeping is generalized.

What can already be reused:

- the existing document activity scope bootstrap, which already keys sessions by `postType` + `entityId`
- the existing request/apply/undo flow structure for document recommendations

Required groundwork before introducing a distinct `template-part` surface:

- generalize persisted activity normalization so refresh-safe undo metadata is not limited to `surface: 'template'`
- generalize reducer cleanup after undo so it resets the correct apply state for both template and template-part flows
- generalize the template undo selectors/helpers, or add a parallel template-part helper set that covers the same cases

After that groundwork, either of these options is reasonable:

1. extract shared document-recommendation state transitions and keep template / template-part payloads in sibling keyed slices
2. add dedicated `templatePart*` state, but only after the shared activity and undo plumbing is surface-aware

The important constraint is sequencing: activity history and undo support must be generalized before a new surface string and a second apply-state family are introduced.

### Linked entity behavior

Recommended linking behavior:

1. target blocks
   - resolve `path` to a live clientId with the existing `getBlockByPath()` helper in [template-actions.js](/home/hperkins-wp/htdocs/wp.hperkins.com/wp-content/plugins/flavor-agent/src/utils/template-actions.js), or move that helper into a shared utility if it needs to be imported
   - select the block in the canvas
2. pattern suggestions
   - open inserter on the Patterns tab
   - pre-filter to the pattern title or name

## Testing Plan

### PHP unit tests

Add:

- `TemplatePartCollectorTest.php`
- `TemplatePartPromptTest.php`
- `TemplatePartAbilitiesTest.php`

Test cases:

1. resolve template part by canonical ref
2. resolve template part by slug fallback
3. include area, slug, title, and structure summary
4. filter pattern candidates by area heuristics
5. parse valid advisory suggestions
6. reject unknown patterns
7. reject unknown operation types
8. reject invalid placements
9. build docs guidance query and family context with area-aware data without requiring a new entity-key namespace

### JS unit tests

Add:

- `src/templates/__tests__/template-part-recommender-helpers.test.js`
- `src/utils/__tests__/template-actions.test.js`
- store tests for fetch, preview, apply, and undo state

Test cases:

1. panel mounts only for `wp_template_part`
2. fetch input is trimmed and stable
3. linked target blocks resolve by path
4. advisory-only suggestions render without apply controls
5. `insert_pattern` apply resolves `start` and `end` from operation metadata rather than the live inserter position
6. undo removes inserted blocks and restores prior slice
7. template-part activity and undo state reset correctly after apply and undo when a distinct surface is introduced

### Browser coverage

Extend:

- `tests/e2e/flavor-agent.smoke.spec.js`

Scenarios:

1. open a `wp_template_part` entity and fetch recommendations
2. verify explanation and suggestion cards render
3. click a linked block and confirm selection changes
4. click a linked pattern and confirm inserter opens filtered
5. Phase 2: apply a safe pattern insertion and undo it

## Rollout Plan

### Milestone 1

Advisory-only `wp_template_part` recommendations:

- new panel
- new ability
- new collector
- new prompt
- no direct mutations required for launch

This is the fastest path to shipping value without forcing risky block-tree execution up front.

### Milestone 2

Safe executable `insert_pattern` inside the current template part:

- confirmable apply
- activity history
- undo

### Milestone 3

Optional subtree replacement:

- only if Milestone 2 proves stable and the recommendation quality is strong enough to justify more automation

## Risks And Mitigations

### Risk: prompt tries to act like a template-level advisor

Mitigation:

- keep a separate prompt class
- keep operation types separate from template-level `assign_template_part` and `replace_template_part`

### Risk: block paths drift before the user clicks

Mitigation:

- advisory suggestions remain usable even if apply becomes invalid
- re-resolve live tree before every mutation
- fail closed when a path no longer matches

### Risk: pattern suggestions are too broad for the area

Mitigation:

- start with explicit area heuristics
- cap candidate count
- later add tighter root-based narrowing if needed

### Risk: docs grounding adds an unsupported `template-part:*` entity key

Mitigation:

- use query text plus area-aware family context in Phase 1
- if entity-level caching becomes necessary later, land `AISearchClient` support and tests first

### Risk: executable placement drifts because it follows live editor selection or inserter state

Mitigation:

- make Phase 2 placement explicit in the operation schema
- support only `start` / `end` first
- defer block-relative insertion until a stable target path/locator is part of the contract

### Risk: `navigation-overlay` behaves differently

Mitigation:

- support it as a valid area in the collector and prompt
- keep navigation-specific structural intelligence in a later integration pass with the existing navigation ability rather than blocking the first template-part ship
- when that later integration lands, reuse overlay data from `ServerCollector::for_navigation()` instead of duplicating navigation-overlay-specific collection logic

## Exit Criteria

The initial `wp_template_part` project is complete when:

1. the Site Editor shows a recommendation panel while editing a `wp_template_part`
2. the panel fetches area-aware, structure-aware recommendations
3. linked blocks and linked patterns work
4. server and client tests cover the new pipeline
5. if Phase 2 is included in the same ship, safe `insert_pattern` apply and undo both work

## Recommended First Implementation Slice

If this work starts now, the best first PR is:

1. `ServerCollector::for_template_part()`
2. `TemplatePartPrompt`
3. `TemplateAbilities::recommend_template_part()`
4. REST registration and controller route
5. `TemplatePartRecommender.js`
6. PHP tests for collector and prompt
7. one browser smoke for fetch and linked entity actions

That slice gets the architecture right without overcommitting to block-tree mutation before the recommendation quality is known.
