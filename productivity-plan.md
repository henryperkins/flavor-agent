# Personal Productivity Plan — v2

**Foundation 4 — Henry Perkins, AI Leaders Cohort 1**
_Drafted April 28, 2026 · Active window: April 28 – May 31, 2026_

---

## 1. Diagnosis (Why This Plan Looks Like This)

Two bottlenecks, not one.

**Bottleneck A — Excitement-driven premature deployment.** When a feature feels done, the pull to share it is immediate, and that pull is strongest the moment validation should be tightest. Shipping is how I currently offload the validation burden I can't carry solo on a plugin the size of Flavor Agent (≈79 backend PHP files under `inc/`, ≈148 source JS files under `src/`, 8 recommendation surfaces, admin/operator surfaces, a Connectors-owned chat path, plugin-owned embeddings/Qdrant, and 2 Playwright harnesses).

**Bottleneck B — Flavor Agent is tracking a moving target.** WordPress core and Gutenberg are evolving the exact APIs Flavor Agent is built on. WP 6.9 shipped the Abilities API; WP 7.0 shipped the server-side WP AI Client and Connectors UI; WP 7.1 is in planning with REST endpoints, JS client, agentic loop support, more core abilities, and a Guidelines refactor. Gutenberg ships every ~2 weeks. My `inc/Abilities/`, `inc/LLM/WordPressAIClient.php`, and `inc/Guidelines/` code all touch surfaces upstream is reshaping in real time. Without active tracking, I will either ship features core ships natively (wasted work) or build against an old upstream shape (wasted weeks).

The plan below is built on two principles: **redirect excitement instead of suppressing it**, and **treat upstream WordPress evolution as a first-class input, not a side concern.**

---

## 2. Active Goals

| #      | Goal                                                                                                                                                      | Domain                  | Deadline         | Why it matters now                                                                                                                       |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------- | ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| **G1** | Ship Flavor Agent to a validated state, with the 6-item personal preflight plus the formal 7-gate release reference as sustained practice and upstream alignment as continuous input | Professional (build)    | Ongoing          | Largest project; biggest risk to other goals if it eats the week; biggest risk to itself if upstream isn't tracked                       |
| **G2** | Complete remaining AI Leaders Foundations at Mastery                                                                                                      | Professional (learning) | Rolling, weekly  | Cohort selection rubric: "Mastery on most assessments"                                                                                   |
| **G3** | Position for the WordPress Career-Placement Cohort selection — explicitly leveraging hands-on Abilities API / WP AI Client experience as a differentiator | Professional (career)   | **May 31, 2026** | Hard external deadline. Most candidates won't have production experience with APIs that just landed in WP 7.0; this is rare and visible. |
| **G4** | Maintain a sustainable personal/social life                                                                                                               | Personal/social         | Ongoing          | Required by exemplar and required by reality                                                                                             |

**The G1↔G3 connection just got stronger.** Every cross-surface change well-validated against core is also a portfolio artifact for G3. The same hour can serve both — but only if I treat upstream tracking as cohort-relevant work, not just maintenance.

---

## 3. The 6-Item Personal Preflight + 7-Gate Release Reference

Triggered by: any change touching more than one of the 8 recommendation surfaces, any admin/operator path, or any surface upstream is actively reshaping (Abilities, AI Client, Guidelines).

The six items below are my personal preflight ritual. They do not replace the repo's release authority: `docs/reference/cross-surface-validation-gates.md`. When a change crosses surfaces or shared subsystems, run the personal preflight first, then satisfy every triggered formal gate.

Current surface map:

- 8 recommendation surfaces: Block Inspector, Pattern Inserter, Content Recommendations, Template Recommendations, Template Part Recommender, Global Styles Recommender, Style Book Recommender, Navigation Recommendations.
- 5 executable apply/undo surfaces: block, template, template-part, Global Styles, Style Book.
- 3 browse/advisory surfaces: content, pattern, navigation.
- Admin/operator surfaces: `Settings > Flavor Agent` and `Settings > AI Activity`.

| #     | Gate                                                                         | Anchor in Flavor Agent                                                                                                                                                                                                                                                                                                                                                                                              |
| ----- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1     | **REST/Abilities contract change propagates everywhere it's read**           | `inc/REST/Agent_Controller.php` schema → `inc/Abilities/*Abilities.php` → JS consumers (`BlockRecommendationsPanel.js`, `PatternRecommender.js`, etc.). All three layers agree, or no push.                                                                                                                                                                                                                         |
| 2     | **Cross-surface store change runs the formal release gates**                 | `src/store/` (`executable-surface-runtime.js`, `activity-history.js`, `activity-undo.js`, `update-helpers.js`) is shared by the store-backed surfaces, while content has its own panel path. Run the nearest PHPUnit/JS suites, `node scripts/verify.js --skip-e2e`, docs checks when contracts change, and the matching Playwright harnesses or a recorded blocker/waiver. |
| 3     | **Over-hardening gate: name the failure mode for every guard, or delete it** | Standard already lives in `SurfaceCapabilities::build()` — every gate maps to a real config state. Match that bar everywhere.                                                                                                                                                                                                                                                                                       |
| 4     | **Dead-code sweep before push**                                              | Unused exports, untouched branches, orphaned readers/writers. Specific: did I touch `inc/OpenAI/Provider.php` constants, `REQUEST_META_ROUTES` keys, or `inc/Activity/Serializer.php`? Find every consumer.                                                                                                                                                                                                         |
| 5     | **Provider/backend path tested under owned boundaries**                      | Chat is Connectors/WordPress AI Client-owned. Embeddings and Qdrant remain plugin-owned for pattern search. Validate generic Connectors chat, selected connector pinning, Azure embeddings, OpenAI Native embeddings, connector-selected chat with direct embedding fallback, and missing-provider/missing-Qdrant states. Apply/undo round-trips belong to executable chat surfaces; pattern recommendations validate ranking/insertion. |
| **6** | **Upstream alignment check — the new one**                                   | _Does this conflict with, duplicate, or fight WP core/Gutenberg current or planned work?_ Specifically: would the same change break or be redundant against the Abilities API in WP 6.9+, the WP AI Client in 7.0, the Guidelines experiment in Gutenberg 22.7+, or any tracked Trac ticket landing for 7.1? If yes → adopt the upstream shape, contribute upstream, or deliberately deviate with a written reason. |

**Ritual at deploy time:** change feels done → run personal preflight → satisfy `docs/reference/cross-surface-validation-gates.md` for every triggered formal gate → produce artifact (grep output, test logs, `output/verify/summary.json`, per-gate notes, upstream-check note, browser evidence or waiver) → post artifact _first_ → only then announce or share.

---

## 4. Time Blocks (Weekly Template)

> _Note: schedule below assumes mornings are my peak deep-work hours. If reversed, swap the AM and late-PM blocks; the reasoning still holds._

| Block                                         | Time (typical day)                               | Goal                 | What happens                                                                                                                              |
| --------------------------------------------- | ------------------------------------------------ | -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| **Deep build**                                | 8:00 – 11:30 AM                                  | G1                   | Flavor Agent feature work, refactors, cross-surface changes. Highest cognitive load goes here. No Slack, no email.                        |
| **Coursework**                                | 12:30 – 2:30 PM                                  | G2                   | AI Leaders Foundations: drafting deliverables, plato sessions. Protected from build creep.                                                |
| **Community + portfolio + upstream tracking** | 3:00 – 4:30 PM                                   | G3 + part of G1 + G2 | Slack #ai-leaders-participants engagement, portfolio drafting, peer responses, jobs.wordpress.net review **AND** upstream review (see §5) |
| **Validation ritual** _(trigger-based)_       | ~20 min minimum, slotted before any cross-surface deploy | G1                   | Run the personal preflight, satisfy the formal release gates, produce artifact, post artifact first                                        |
| **Personal/social**                           | After 5:30 PM + protected weekend blocks         | G4                   | Hard cutoff from G1/G2/G3 unless gate ritual is in flight                                                                                 |
| **Weekly review**                             | **Sunday 7:00 – 7:45 PM** (fixed)                | All four             | Cross-domain audit — see §7                                                                                                               |

**Why upstream tracking sits inside the community/portfolio block:** because it serves _both_ G1 (avoid duplicating core) and G3 (portfolio differentiator). Treating it as build-block work would crowd out building; treating it as separate maintenance would let it slip. Co-locating it with portfolio work is the honest fit.

---

## 5. Upstream Tracking System (New)

**Cadence:** 30 minutes, 2× per week (e.g., Tuesday + Friday afternoons), inside the community block.

**What I monitor:**

| Source                                              | What I'm watching for                                                                                                                                                 | Why it matters to my code                                             |
| --------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| **Make Core AI blog** (make.wordpress.org/ai)       | Weekly meeting summaries, plugin releases, contributor calls                                                                                                          | Direction-of-travel signals before they hit Trac                      |
| **`docs/reference/wordpress-ai-roadmap-tracking.md`** | Current local map of WordPress/ai project-board items, especially `WordPress/ai#419` observability/Site Agent work                                                     | Fastest way to connect upstream movement to Flavor Agent code paths    |
| **WordPress/ai GitHub project board** (project 240) | Active issues, milestones, what's in flight for 7.1                                                                                                                   | Concrete near-term changes                                            |
| **Tracked Trac tickets and GitHub issues**          | Trac tickets plus roadmap items such as ability lifecycle/filtering, `wp-ai/v1` REST, `@wordpress/ai` JS, agentic loops, schema normalization, observability, provider controls, and ability exposure | Each one directly touches a layer Flavor Agent has its own version of |
| **Gutenberg releases** (every ~2 weeks)             | Experiments graduating, breaking changes, Site Editor changes                                                                                                         | The editor and Site Editor surfaces live inside Gutenberg; release notes are non-optional |
| **gziolo.pl** + select contributor blogs            | Architectural reasoning behind the changes                                                                                                                            | Helps me decide whether to adopt, contribute, or deliberately deviate |

**Output of each session:** a 3-line note in `upstream-log.md` — date, what changed, whether Flavor Agent needs to respond. If "needs response," it becomes a G1 task with a priority slot.

**What this is not:** doomscrolling Twitter, watching every PR, or trying to read the whole #core-ai backlog. It's a 30-minute targeted scan against a fixed list.

---

## 6. Tracking & Organization System

| Domain                | Tool                                                                                    | What lives there                                                                                         | Why this works for me                                                                 |
| --------------------- | --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| G1 Flavor Agent       | GitHub Issues + commit-attached validation artifacts + `upstream-log.md`                | Tasks, gate outputs, contract-mismatch logs, upstream-change notes                                       | Already where the code lives; artifact-attachment makes the gate non-skippable        |
| G2 AI Leaders         | Mem (notes) + plato chat history                                                        | Lesson drafts, plato exchanges, learning reflections                                                     | Cross-lesson search; survives across Foundations                                      |
| G3 Cohort positioning | Single living `portfolio.md` + Slack saved-items + `upstream-log.md` (cross-referenced) | Portfolio drafts, jobs.wordpress.net targets, peer-engagement log, upstream-tracking proof for portfolio | Selection rubric maps to one document; upstream log doubles as evidence of WP fluency |
| G4 Personal/social    | Calendar (blocks only, not tasks)                                                       | Sleep/exercise/social anchors                                                                            | Tasks turn rest into work; blocks just protect the time                               |

---

## 7. Prioritization (Important × Urgent)

|                   | Urgent                                                                                                                                                                        | Not urgent                                                                                                                        |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| **Important**     | **Q1** — Foundation 5 due-date items, Cohort May 31 deadline artifacts, breaking upstream change that touches a Flavor Agent surface, Slack replies on time-sensitive threads | **Q2** — Validation-gate practice, portfolio refinement, Foundation 7 ongoing engagement, deep build, scheduled upstream tracking |
| **Not important** | **Q3** — generic Slack notifications, GitHub stars, low-signal email                                                                                                          | **Q4** — yak-shaving, refactors with no caller, "just one more feature," reading every WP-related blog post                       |

**My specific traps:**

- Letting Q2-Flavor-Agent-build crowd out Q1-Cohort-deadline because building feels more rewarding than polishing portfolio language.
- Letting Q4-upstream-doomscrolling pose as Q2-upstream-tracking. The 30-minute timer + 3-line output is the discipline.

**Rule:** if a Q1 item is open and a Q2 build session beckons, Q1 wins until cleared. Q4 over-hardening is now caught by gate item #3; Q4 doomscrolling is caught by the upstream-log output requirement.

---

## 8. Success Metrics & Weekly Review (Sunday 7:00 PM)

A single 45-minute audit covering all four domains.

### G1 — Flavor Agent

- **Gate execution rate:** % of cross-surface changes that ran the personal preflight plus every triggered formal release gate (target: 100%)
- **Gate effectiveness:** # of contract mismatches / dead code / over-hardening / upstream conflicts caught pre-deploy vs. found post-deploy (target: caught > found)
- **Ritual skip rate:** # of times I shared a change before posting the artifact (target: 0)
- **Upstream alignment hits:** # of times gate item #6 caught a duplication or conflict (target: > 0 over any 4-week window — zero hits means I've stopped looking)

### G2 — AI Leaders Coursework

- **Lessons completed at Mastery:** progress vs. release cadence (target: no lesson > 1 week behind release)
- **Assessment quality:** plato pushed back vs. signed off

### G3 — Cohort Positioning

- **Portfolio % complete:** rough % vs. May 31 deadline (target: 100% by May 24, leaving a week's buffer)
- **Slack engagement count:** ≥ 3 substantive peer interactions per week
- **Job-fit review:** ≥ 1 new posting from jobs.wordpress.net reviewed weekly
- **Differentiator articulation:** does my portfolio explicitly name production experience with Abilities API + WP AI Client + Guidelines? (Yes/no — should be yes by mid-May)

### G4 — Personal/Social

- **Domain-zero check:** did personal/social hit zero hours this week? (target: never)
- **Sleep + exercise minimums met?** Yes/no.

### Plan-on-the-plan

- **Was the plan itself useful this week, or did I work around it?** Working around it is data, not failure.
- **Is upstream tracking still scoped (30 min × 2) or has it expanded?** Expansion is a signal — either an upstream event is genuinely big enough to need more time, or I'm Q4-doomscrolling.

---

## 9. Contingency Triggers (When the Plan Breaks)

| Trigger                                                             | Response                                                                                                                                                   |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Gate skip rate > 20% in a week                                      | 48-hour shipping freeze on Flavor Agent + ritual reset before next deploy                                                                                  |
| Validation gate catches 0 issues for 2 consecutive weeks            | Re-examine whether the personal preflight and formal gates still match real failure modes; recalibrate or retire items                                     |
| Any domain at zero hours for 2 consecutive weeks                    | Reallocate the next week's hours from the dominant domain                                                                                                  |
| G3 (Cohort) falls > 1 week behind portfolio milestone               | **Freeze new Flavor Agent feature work**; shift those hours to portfolio until caught up                                                                   |
| **WP core or Gutenberg ships a feature that overlaps Flavor Agent** | 24-hour assessment block: adopt the core version, contribute upstream, deprecate the duplicate, or deliberately deviate with written reason. Not optional. |
| **A Gutenberg minor release breaks a Flavor Agent surface**         | Freeze new features; fix the broken harness first. Update the relevant `playwright.*.config.js`.                                                           |
| **A tracked upstream roadmap item or Trac ticket lands**            | Re-read my implementation against the merged version within 1 week. Migrate or document the deviation.                                                     |
| A Foundation lesson misses its release-week window                  | Schedule explicit catch-up block before the next Foundation drops                                                                                          |
| Personal/social erosion (sleep < 6h three nights in a week)         | Mandatory full-day cutoff from G1/G2/G3 the following weekend                                                                                              |

---

## 10. What Makes This Plan Different from a Generic One

Four structural choices, all derived from my actual diagnosis:

1. **Trigger-based validation, not calendar-based deployment.** Cadence set by scope-of-change.
2. **Validation artifact = the thing I share, not the feature.** Excitement redirected.
3. **Weekly review audits the gate itself, not just outputs.** "Did I actually run it?" is a metric.
4. **Upstream tracking is a scheduled, scoped, output-producing activity** — not vibes-based "staying informed" — and it's co-located with G3 portfolio work because the same scan serves both.

The plan is sustainable if at the Sunday review I can answer _yes_ to all four:

- Did every cross-surface change leave the machine with an artifact attached?
- Did all four domains get non-zero hours?
- Is G3 still on track for May 31?
- Did the upstream log get two entries this week?

If any answer is no, §9 fires.
