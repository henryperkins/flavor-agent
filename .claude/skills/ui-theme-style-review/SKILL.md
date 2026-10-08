---
name: ui-theme-style-review
description: Audit Flavor Agent's WordPress editor and admin UI for confirmed theme, style, accessibility, and stale-state/apply bugs. Use for a focused review with severity-ranked findings grounded in active source and appropriate runtime evidence.
---

# Flavor Agent UI / Theme / Style Review

Use this workflow for a UI audit. Audit mode is review-only: inspect and report minimal fixes; do not change application source, configuration, or site data. Follow the user's latest scope and any already-authorized implementation or skill-maintenance request.

## Scope and setup

- Identify the Flavor Agent checkout from `flavor-agent.php`, `package.json`, and its repository instructions. Resolve all `src/`, `inc/`, `assets/`, `tests/`, and `docs/` paths below from that checkout root, not this installed skill's directory. If the checkout is unavailable, request its location.
- Read `docs/reference/ui-theme-style-review.md` for the coverage map, theme/accessibility evidence criteria, and current apply/admin seams. Follow `docs/reference/review-response-protocol.md` for the findings contract. If a reference moved, locate its replacement; record missing guidance instead of treating an old prompt as runtime proof.
- For a named surface, use only its relevant delta in `docs/prompts/surface-review-prompt.md` and adjacent shared code that affects its UI. A UI audit does not automatically require auditing the whole provider, ranking, or corpus stack.
- For a whole editor/admin audit, cover the mounted editor surfaces, Settings, AI Activity, shared UI, and each requested theme/style, accessibility, and stale-state category. Record exclusions and unavailable evidence. Do not stop merely because the first useful finding is confirmed.
- Establish the actual screen, bundle, imports/enqueues, portal document, and request/apply owner before reviewing a component. File existence or an exported fallback branch does not prove it is mounted.

## Evidence and tool choices

- Search with `rg` to locate evidence, then open the relevant source and trace the reachable path. Check the surrounding guards, wrappers, inherited styles, and nearest tests before reporting a defect.
- Confirm findings by either a complete source path that demonstrates the failure, a targeted isolated reproduction, or observed browser behavior traced back to source. Mark which evidence supports each finding; separate unresolved leads from confirmed issues.
- Styling literals, token fallbacks, experimental APIs, absent local `:focus-visible` rules, differing panel layouts, and missing tests are leads. Report them only when their active use causes a concrete failure or breaks an enforced contract.
- For contrast, focus, clipping, motion, and portal/iframe behavior, use computed styles and interaction evidence when static source cannot resolve the outcome. A screenshot alone does not prove keyboard behavior, contrast, or freshness safety.
- Prefer the available WordPress Design System MCP for component/token guidance. Consult the managed WordPress docs search for Gutenberg, theme.json, REST, and release-sensitive claims. Check primary sources, retrieval currency, and local package/core versions; use the corpus runbook and source policy when coverage or trust matters. Unavailable MCP tools do not block source review.
- Review source rather than generated `build/` or `dist/`. Inspect a matching bundle only when asset resolution is part of the hypothesis, and keep source, local build, fixture, deployed, and live evidence distinct.
- Browser observation must account for side effects: opening a pending AI Activity detail can acquire a review claim. Use an isolated fixture/local test site for claims, decisions, apply/undo, Settings saves, sync, or provider requests unless those live actions are authorized.
- Read relevant tests to understand expected behavior, including fixed regressions. Run the smallest useful check when it can settle a hypothesis; do not run write-formatters or broad build/verification pipelines by default. See the reference for test routing and implementation gates.

## Findings and completion

Lead with confirmed findings, ordered `P0` to `P3`. For each give:

- a concise title and priority;
- an exact current source file and line, linked using an absolute path when supported;
- the trigger/state, observed failure, and concrete user impact;
- the evidence used and a minimal credible fix direction.

Use priority proportional to demonstrated impact: `P0` for a critical exploitable exposure or widespread destructive failure; `P1` for a major flow, accessibility, or incorrect-apply blocker; `P2` for a bounded functional, accessibility, responsive, or theming defect; `P3` for a minor confirmed defect. Do not assign priority from the category or speculate about impact.

Keep open questions/assumptions separate. End with `Verification Reviewed`: requested coverage and exclusions, active paths inspected, environment details that matter, commands actually run and their results, and material browser/test checks not run. Keep it concise; do not dump every search hit or claim a full accessibility certification.

If no findings are confirmed, say so and state the remaining evidence gaps. Stop once requested coverage is addressed and each reported finding has a supported trigger, source location, and impact. Continue only to resolve a material gap or contradictory evidence.
