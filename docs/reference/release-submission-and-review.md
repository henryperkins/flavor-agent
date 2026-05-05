# Release Submission And Review

Date: 2026-05-02

This document defines the WordPress.org submission and review path for Flavor Agent. It picks up where [`release-surface-scope-review.md`](./release-surface-scope-review.md) leaves off: that doc declares the plugin internally coherent enough to ship; this one defines the gates between "internally release-quality" and "approved by WordPress.org."

## Audience And Rubric

The product-coherence rubric asks: does Flavor Agent know what it is?

The WordPress.org reviewer rubric asks: does Flavor Agent comply with the plugin directory rules?

Both must pass. They are different audits, run by different audiences, against different artifacts. Most surface-scope decisions in the sibling doc do not carry weight with a reviewer; most reviewer concerns do not appear in the sibling doc at all. Treat them as orthogonal release tracks.

This doc covers the second rubric and the events between submission and approval.

## Path Overview

Five gates between "internally release-quality" and "approved":

1. Submission artifacts complete and Plugin Check clean against WordPress 7.0.
2. First submission accepted into the human-review queue.
3. Reviewer email cycle, typically one to three rounds of requested changes.
4. Reviewer approval and SVN access for the slug.
5. First tag and trunk push, with banner/icon/screenshots in `assets/`.

Skipping any of these — or treating them as internal doc work rather than reviewer-facing artifacts — invites rejection or extra rounds.

## Current Submission Blockers

Snapshot of work that must land before the plugin can credibly enter the review queue:

- `readme.txt` now exists as a reviewer-facing artifact with setup, FAQ, and external-service disclosure language. Before submission it still needs final screenshot captions/assets and any final version/changelog polish against the frozen scope.
- `lint-plugin` is currently optional in ad-hoc `npm run verify` usage (`--skip=lint-plugin` when WP-CLI or a WordPress root is unavailable). For the submission half of the plan it must be a required, weekly-tracked KPI run against a representative WordPress 7.0 environment; any skipped Plugin Check run is a recorded blocker or waiver, not a green release signal.
- The external-service disclosure inventory lives at `docs/reference/external-service-disclosure.md`. It enumerates outbound call sites (OpenAI, Cloudflare Workers AI, connector-backed chat providers such as Anthropic, Qdrant, Cloudflare AI Search, GitHub) with trigger, data sent, and setup/explicit-action gates, and must stay 1:1 with the disclosure block in `readme.txt`.
- Cron-driven outbound calls (`flavor_agent_reindex_patterns`, `flavor_agent_prewarm_docs`, `flavor_agent_warm_docs_context`, `flavor_agent_warm_core_roadmap_guidance`) are audited in `docs/reference/external-service-disclosure.md`. Keep tests and docs aligned so none can phone home on activation before the corresponding backend is configured or explicitly enabled.
- No banner, icon, or screenshot assets exist yet for the WordPress.org listing. They cannot be produced until scope is fully frozen, since they show the product visually.

Treat each of the above as a release stop in the same sense as the cross-surface validation gates: they are additive to product-coherence work, not part of it.

## Submission Artifacts

### `readme.txt`

Required sections, in order:

- Header (Plugin Name, Contributors, Tags, Requires at least, Tested up to, Requires PHP, Stable tag, License, License URI)
- Description
- Installation
- Frequently Asked Questions
- Screenshots (numbered captions matching the files in `assets/`)
- Changelog
- Upgrade Notice (only when an upgrade requires user action)

Header constraints specific to this plugin:

- `Requires at least` reflects the lowest WordPress version actually tested. Currently 7.0.
- `Requires PHP` reflects the lowest PHP version actually tested. Currently 8.0.
- `Stable tag` will not point to a real SVN tag at first submission, because SVN access is only granted on approval. It points to the version of the zip uploaded for review.
- `Tags` are descriptive only. No marketing terms, no third-party trademarks, no "AI" used in a way that implies official endorsement.
- License is GPLv2 or compatible.

Description requirements specific to this plugin:

- An explicit "uses external services" disclosure block listing OpenAI, Cloudflare Workers AI, connector-backed chat providers such as Anthropic, Qdrant, Cloudflare AI Search, and GitHub; what data is sent to each; when (only after the user configures or explicitly enables the corresponding backend); and a link to each provider's terms of service and privacy policy.
- A clear "AI-assisted recommendation plugin" framing that does not over-claim accuracy, safety, accessibility, design quality, or compliance.
- Setup steps that make the external dependencies obvious before installation, not buried in an Installation section the reviewer has to dig for.

### Slug

The intended slug is `flavor-agent`. Slug availability must be confirmed against the directory before submission. The slug cannot be changed after approval, so this is a one-shot decision.

### Banner And Icon

Live in `assets/` in SVN, not in the plugin zip. Required files:

- `banner-772x250.png` (standard)
- `banner-1544x500.png` (retina)
- `icon-128x128.png`
- `icon-256x256.png`

### Screenshots

Live in `assets/` in SVN as `screenshot-1.png`, `screenshot-2.png`, etc. Captions are written in `readme.txt` under `== Screenshots ==`, in numeric order. Captions describe the surface and the user action; they do not over-claim.

The minimum set should cover the surfaces the surface scope review keeps for release: a block recommendation in the Inspector, a pattern recommendation in the inserter, a content recommendation in the post editor, a template recommendation in the Site Editor, a Global Styles review/apply panel, the AI Activity admin audit page, and the Settings page. Screenshots that show review-first flows should show the review state, not the applied state, to match the product framing.

## Plugin Check Baseline

Plugin Check is the reviewer's first automated gate. Failures here often produce immediate change requests in round one, so passing it cleanly compresses the review cycle.

Operational changes required:

- Promote `lint-plugin` from `--skip`-able to a tracked weekly KPI in `npm run verify`.
- Run against a representative WordPress 7.0 environment with the companion plugins listed in `docs/reference/local-environment-setup.md`.
- Capture warning and error counts per category in `output/verify/summary.json` so the trend can be tracked week-over-week.

Categories that block submission:

- Security: nonce checks, capability checks, sanitization on input, escaping on output.
- Plugin repository compliance: header completeness, slug uniqueness, license declaration, function/option/post-meta prefixing, directory layout.
- Internationalization: text domain, translator comments, escape-then-translate ordering.
- Disallowed calls: `eval`, `exec`, direct file writes outside the plugin sandbox.

Categories that should be reviewed but rarely block on their own:

- Performance hints.
- Style and lint suggestions.

A category does not need to be at zero to submit, but every non-zero category needs a documented disposition (resolved, deferred with reason, false positive with citation).

## WordPress.org Guideline Audit

Run as a separate audit pass independent of product-coherence. The artifacts produced here are reviewer-facing.

### External Services And Disclosure

Highest-probability rejection vector for an AI plugin in 2026. The reviewer will check this whether or not the directory's automated tooling does.

Audit procedure:

- Inventory every code path that issues an outbound HTTP call. Cover REST handlers, ability handlers, cron callbacks, and admin AJAX paths.
- For each call site, record: service, endpoint, data sent, trigger (always vs. on user action vs. on cron), and whether explicit user setup of that backend is required first.
- Confirm each service is named in `readme.txt` Description with a privacy/ToS link.
- Confirm no outbound call fires before the user has explicitly configured the corresponding backend. This includes activation hooks and scheduled cron events.
- Maintain `docs/reference/external-service-disclosure.md` as the canonical inventory and reviewer-facing summary. The summary in `readme.txt` is derived from it.

### Cron And Background Calls

Cron events are functionally indistinguishable from "the plugin phones home periodically" if they fire before user setup. Specific events to audit:

- `flavor_agent_reindex_patterns` (calls Qdrant)
- `flavor_agent_prewarm_docs` (calls Cloudflare AI Search)
- `flavor_agent_warm_docs_context` (calls Cloudflare AI Search)
- `flavor_agent_warm_core_roadmap_guidance` (calls GitHub when explicitly enabled)

Each must be:

- Scheduled only after the user has saved working credentials for the relevant backend, triggered a recommendation that queues follow-up warming, or explicitly enabled the optional filter-backed backend.
- A no-op if credentials/explicit enablement are missing or invalid at fire time.
- Documented in `readme.txt` with frequency and data sent.

### GPL Compatibility

- All bundled PHP and JS dependencies must be GPL-compatible.
- Audit `composer.json` and `package.json` for direct and transitive license declarations.
- Anything not GPL-compatible must be removed, replaced, or — where it ships only as a build/test dependency that does not enter the plugin zip — confirmed absent from the release artifact.

### AI Plugin Policy

Reviewer scrutiny on AI plugins has tightened. The relevant rules for this plugin:

- No automatic posting, mutation, or external-visible side effect without explicit user action. The product-coherence doc already constrains executable apply paths; reviewer-facing copy must match — no readme language that implies the plugin "writes" or "edits" autonomously.
- No claims of accuracy, safety, accessibility, compliance, or design quality the plugin cannot deterministically validate. Style and Style Book copy in particular cannot claim contrast/readability quality until deterministic validation exists.
- No exfiltration of post content, user data, or admin context outside what the user has explicitly configured to be sent.
- No dark patterns around external-service signup or subscription.

### Telemetry

- No anonymous usage telemetry without explicit opt-in.
- No phone-home on activation.
- No update checks beyond the standard `update_plugins` filter behavior.

### Naming And Trademark

- "Flavor Agent" must not collide with another reserved slug or registered trademark.
- Plugin name and description cannot use "WordPress" in a way that implies official endorsement.
- Cannot use OpenAI, Azure, Anthropic, Cloudflare, GitHub, or Qdrant logos in the listing or in-plugin promotional artifacts.
- "AI" can appear descriptively, not in a way that implies official AI partnership or certification.

### Promotional And Premium Behavior

- No upsell screens.
- No nag notices on activation.
- No "Pro version" prompts in the editor or admin surface.
- No affiliate links.

## First-Submission Process

1. Confirm Plugin Check clean against the representative WordPress 7.0 environment.
2. Confirm the disclosure audit is fully resolved and `readme.txt` matches the inventory.
3. Confirm the release zip artifact builds reproducibly from a clean tree and matches the plugin code under review.
4. Submit via the WordPress.org plugin submission form (linked from the developer area at `wordpress.org/plugins/developers/`).
5. Wait for the automated initial response (immediate to a few hours).
6. Wait for the human reviewer's first email (typically one to four weeks; variable, do not anchor on the median).

Do not resubmit during the queue. Do not email the plugins team unless the queue exceeds the documented response window with no automated reply at all.

## Reviewer Email Cycle

### Response Discipline

- Acknowledge the email within one business day, even if the work will take longer.
- Address every concern raised; do not silently skip any.
- Do not argue policy interpretation. Clarify implementation when asked, but treat reviewer concerns as release blockers, not suggestions.
- Bundle revisions into one resubmit per round; do not send multiple emails per round.

### Resubmit Format

- Bump the plugin version per the changelog convention.
- Update `readme.txt` `Stable tag` and `Changelog` entries.
- Reattach a fresh zip; do not assume the reviewer will pull from a public repo.
- Reply on the same email thread.

### Anti-Patterns

- Long defensive replies that re-litigate scope.
- Reframing the product to avoid a reviewer concern instead of fixing the underlying behavior.
- Multiple resubmits inside one round.
- Treating the reviewer's concerns as bugs to triage rather than release blockers.

## Approval, SVN, And First Tag

On approval:

1. SVN access is granted for the slug.
2. The first commit goes to `trunk/`.
3. The first tag goes under `tags/<version>/`.
4. Banner, icon, and screenshots go under `assets/` (sibling to `trunk/` and `tags/`).
5. `readme.txt` `Stable tag` matches the tagged version.

Subsequent releases:

- Update `trunk/`, then copy to `tags/<new-version>/`, then update `Stable tag` to match.
- Asset changes (banner, icon, screenshots) commit to `assets/` independently of release tags.

## KPIs

### Pre-Submission (Weekly Leading)

| KPI                                                   | Source                              | Target trend                                      |
| ----------------------------------------------------- | ----------------------------------- | ------------------------------------------------- |
| Plugin Check error count                              | `npm run verify` with `lint-plugin` | Trending to zero                                  |
| Plugin Check warning count by category                | `npm run verify` with `lint-plugin` | Documented disposition for each non-zero category |
| External-service call sites disclosed in `readme.txt` | Disclosure inventory                | 1:1 with code reality                             |
| External-service call sites gated by user setup       | Disclosure inventory                | 1:1 with code reality                             |
| Verifier status on master                             | `output/verify/summary.json`        | Pass on green branches                            |
| Scope-freeze unchecked items                          | Surface scope review                | Monotonically down                                |
| Stale-doc count                                       | `npm run check:docs`                | Zero before submission                            |
| Mixed-scope week count                                | Tree hygiene log                    | Zero in the four weeks before submission          |

### In-Flight (Per-Event)

| KPI                                        | Source                      | What it means                                                    |
| ------------------------------------------ | --------------------------- | ---------------------------------------------------------------- |
| Days in initial queue                      | Submission email timestamps | Calendar context only; not actionable until first reviewer email |
| Reviewer-email round count                 | Email thread                | Each round = one set of requested changes                        |
| Issues per round                           | Email content               | Trending down indicates convergence                              |
| Issue severity distribution                | Email content               | Shift from blockers to nits indicates convergence                |
| Issue category drift                       | Email content               | Drop of policy-level concerns indicates convergence              |
| Resubmit cycle time                        | Email timestamps            | Compression indicates convergence                                |
| Open vs. resolved concerns at end of round | Email content               | Should be zero open                                              |

### Binary Outcome

- Approved: SVN access granted, first commit and tag follow.
- Not approved: an explicit rejection requires a fresh submission, not a resubmit, and resets the queue clock.

### Signals Of Convergence In Review

- Round two contains only nits (readme phrasing, screenshot ordering, copy edits).
- Reviewer asks about release or marketing detail rather than scope or security.
- Reviewer requests a single specific clarification rather than a list.
- The next response from the reviewer arrives faster than the previous one.

If round N+1 contains a new policy-level concern that round N did not, the cycle has not converged and the resubmit was incomplete; treat that round as a reset, not progress.

## Stop Lines

Distinct from the product scope stop lines in the sibling doc.

Stop at:

- Disclosure that matches the product as it ships.
- Plugin Check clean against WordPress 7.0.
- A `readme.txt` that does not over-claim accuracy, safety, accessibility, or design quality.
- A response cadence that addresses every reviewer concern in one round.

Do not add:

- Marketing claims about AI accuracy, safety, accessibility, or design quality.
- Telemetry without opt-in.
- Premium upsell screens or nag notices.
- Disclosure language that softens what the plugin actually does.
- Resubmit churn inside one round.

## Final Acceptance Point

The release-acceptance stopping point is the reviewer email containing approval and SVN credentials. Anything after that — first tag push, asset upload, post-launch operating concerns — is post-acceptance work and belongs in a separate operating doc, not here.
