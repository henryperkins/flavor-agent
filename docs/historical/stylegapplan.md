# Style Surface Follow-Up Checklist

Validated against the current tree on 2026-04-03.

This file is retained as a historical pointer only. It was written during the transition from a Global Styles-only implementation to the shared Global Styles plus Style Book contract, and it is no longer the active execution plan.

## Current Baseline

- The repo already ships the shared style recommendation contract through `inc/Abilities/StyleAbilities.php`, `inc/LLM/StylePrompt.php`, `inc/REST/Agent_Controller.php`, and `inc/Abilities/Registration.php`.
- The repo already ships both first-party style surfaces:
  - `src/global-styles/GlobalStylesRecommender.js`
  - `src/style-book/StyleBookRecommender.js`
- The current style executor already supports the bounded executable operations that are in scope for v1:
  - `set_styles`
  - `set_block_styles`
  - `set_theme_variation`
- Capability, activity, and admin-audit coverage already includes both `global-styles` and `style-book` scopes through `inc/Abilities/SurfaceCapabilities.php`, `src/store/activity-history.js`, `src/components/AIActivitySection.js`, `src/components/ActivitySessionBootstrap.js`, and the `Settings > AI Activity` screen.
- `customCSS` remains out of scope for v1, and width/height preset transforms plus pseudo-element-aware extraction remain explicitly deferred follow-up work.

## Active Documents

Use these files instead of treating this checklist as live backlog:

1. `docs/2026-03-25-roadmap-aligned-execution-plan.md`
2. `docs/2026-04-03-three-phase-roadmap.md`
3. `docs/superpowers/plans/2026-03-27-epic-3-style-and-theme-intelligence-plan.md`
4. `docs/features/style-and-theme-intelligence.md`
5. `STATUS.md`

## Archived Notes

The earlier checklist items for the following work have already landed in tree and should not be treated as open implementation tasks:

- broadening `flavor-agent/recommend-style` beyond a Global Styles-only scope
- adding `set_block_styles` and deterministic `styles.blocks` apply/undo support
- shipping the Style Book surface
- wiring Style Book into capability, activity, and audit flows

## Remaining Follow-Ups

- Swap the Docker-backed WP 7.0 harness from the beta image to the official stable `7.0` image once it exists.
- Keep any deeper second-stage Style Book expansion, width/height preset transforms, pseudo-element-aware extraction, and any future custom-CSS product decision as separate bounded follow-up work rather than reopening the shipped Epic 3 slice.
