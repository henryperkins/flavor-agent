# Flavor Agent Direction Review

Date: 2026-04-03

I reviewed the repo docs in `docs/README.md`, `docs/SOURCE_OF_TRUTH.md`, `docs/2026-03-25-roadmap-aligned-execution-plan.md`, and `STATUS.md`, then checked current WordPress Core and ecosystem signals.

## Updated Read

The repo's core thesis is still right: keep AI inside native Gutenberg and wp-admin surfaces, keep actions bounded/reviewable/undoable, and align with core infrastructure instead of building a parallel AI app.

The important correction is release timing. As of the March 31, 2026 Core post, WordPress 7.0 is no longer on the earlier fixed final-release target. Core says the 7.0 cycle has been extended by "a few weeks" and that a new final timeline will be announced later. The reason is finalizing collaboration architecture, not broadening scope.

That means the near-term recommendation should be more conservative around anything tied to collaboration internals.

## What WordPress Is Signaling

- Core is standardizing AI infrastructure, not shipping one giant chat UI: AI Client, Connectors, and client/server Abilities are the platform direction.
- Editor-native review and admin-native data surfaces are getting deeper: DataViews, DataForm, in-editor revisions, activity-style layouts, and collaboration plumbing.
- Site building is getting more structural: navigation overlays, pattern overrides for custom blocks, viewport visibility, stronger `theme.json` and pattern workflows.
- Front-end experience is trending app-like, but through native primitives like the Interactivity API rather than custom framework sprawl.
- The 7.0 extension is specifically about collaboration architecture. Core also says no new features not already in core will be considered for inclusion during the delay.
- Real-time collaboration remains opt-in, and it is disabled when metaboxes are present. Plugin authors are expected to bridge metabox-based UI or adopt more modern Gutenberg APIs.

## Direction Recommendation

### 1. Keep Flavor Agent as WordPress intelligence infrastructure, not a chat product

Stay on the current line:

- native Gutenberg and wp-admin surfaces first
- provider-agnostic integration via Connectors and AI Client
- Abilities as the public machine-readable contract
- review/apply/undo instead of freeform mutation

Do not pivot into a floating AI workspace or site-builder shell. Current WordPress direction does not support that as the strongest ecosystem-aligned move.

### 2. Make trust, provenance, and observability the next differentiator

This now looks more strategically valuable than broadening into a bigger "agent" product.

Prioritize:

- richer activity records
- before/after inspection
- provider/model/token provenance where available
- clearer why-unavailable states
- explicit honoring of platform-level AI gating
- stronger diagnostics in the admin audit screen

This fits both the repo's current shape and WordPress's emphasis on reviewability and controlled adoption.

### 3. Expand only along core nouns WordPress is actively strengthening

The strongest expansion areas are:

- pattern overrides for custom blocks
- navigation overlay-aware recommendations
- viewport visibility-aware recommendations
- deeper style/system intelligence tied to `theme.json`, variations, and supported paths

These are aligned with current core investment and keep the plugin inside Gutenberg semantics.

### 4. Stay off unstable collaboration internals for now

Because the 7.0 cycle was extended specifically to finalize collaboration architecture, do not make near-term product bets that depend on:

- the final sync storage model
- collaboration data persistence internals
- editor-session assumptions that may change before 7.0 ships

Consume stable editor/admin APIs around the collaboration feature, but do not build Flavor Agent's next milestone around collaboration-specific primitives yet.

### 5. Keep client-side Abilities adoption narrow

The current repo stance still looks right.

Keep first-party editor runtime on scoped REST plus store flows for now. Use Abilities as:

- the external contract boundary
- an admin/runtime integration surface
- a future narrow integration point where it removes duplication

Do not re-architect the editor around `@wordpress/core-abilities` yet.

### 6. Prefer modern editor APIs and avoid legacy UI dependencies

The collaboration note about metaboxes is a strong ecosystem signal even beyond RTC. If any Flavor Agent surface still depends on patterns that resemble compatibility-mode assumptions, move away from them. The safer long-term direction is:

- block editor native panels
- Site Editor native panels
- DataViews/DataForm in admin
- script modules and current core packages where practical

## What I Would Not Prioritize

- a floating chat workspace
- freeform site generation inside this plugin
- front-end Interactivity API work until the plugin ships a front-end runtime surface
- a broad "site agent" expansion in the next milestone
- an immediate migration from `@wordpress/scripts` to newer build tooling just because it exists

## Practical Near-Term Call

If I were setting direction from today, I would do this:

1. Finish docs and verification closeout around the existing surfaces.
2. Update repo assumptions anywhere they still imply the old fixed WordPress 7.0 final-release target.
3. Deepen admin audit/diagnostics and provenance.
4. Expand recommendation quality around patterns, styles, navigation overlays, and structural editor constraints.
5. Wait for the revised 7.0 final timeline and stable collaboration architecture before making any collaboration-adjacent product move.

## Sources

- WordPress 7.0 release page: <https://make.wordpress.org/core/7-0/>
- Extending the 7.0 cycle: <https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/>
- AI Client dev note: <https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/>
- Connectors API dev note: <https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/>
- Client-side Abilities API dev note: <https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/>
- DataViews/DataForm dev note: <https://make.wordpress.org/core/2026/03/04/dataviews-dataform-et-al-in-wordpress-7-0/>
- Interactivity API changes: <https://make.wordpress.org/core/2026/02/23/changes-to-the-interactivity-api-in-wordpress-7-0/>
- Pattern overrides for custom blocks: <https://make.wordpress.org/core/2026/03/16/pattern-overrides-in-wp-7-0-support-for-custom-blocks/>
- Block visibility: <https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/>
- Big picture goals for 2026: <https://make.wordpress.org/project/2026/01/23/big-picture-goals-for-2026/>
- Ecosystem trend signal: <https://wordpress.com/blog/2026/03/30/wordpress-design-trends/>
