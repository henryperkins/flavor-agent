# Current Open Work

This document is the contract reference for the current Flavor Agent work queue.

Use it when you need to answer:

- which planned work is still open after completed plans were archived
- which items are actionable implementation candidates versus upstream watch items
- which source doc owns the detailed design or verification gate for each item
- whether a fresh implementation plan is required before coding

## Status

Updated: 2026-06-06.

Source basis: all repository/workspace doc-like files enumerated on 2026-06-05 (77 files: 69 under `docs/`, 8 root docs including the untracked `ConfirmedFindings.txt`; dependency/generated directories excluded). Open-work signals were compared across `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`, `improving-levers.md`, `docs/features/`, `docs/reference/`, `docs/wp7-migration-opportunities.md`, release docs, root docs, and the Gutenberg 23.3 validation records.

2026-06-06 branch refresh: the pattern relevance / design-validator / expanded-evaluation cluster is no longer active open work on this checkout. The current implementation adds pattern design metadata to index/search payloads, component ranking hints, parser-emitted design quality signals, new shared validation reason codes, and offline metrics for contrast preservation, top-three relevance, stale false positives, and prompt token deltas.

Archived files under `docs/superpowers/plans/archive/` and implemented design specs under `docs/superpowers/specs/` are historical context only. Do not treat them as active implementation plans. Any item below needs a fresh source-grounded plan before code changes unless the change is a narrow docs or verification update.

## Current Implementation Candidates

These items are not blocked by a known upstream API prerequisite. They still need ordinary design, tests, and cross-surface validation before implementation.

| Workstream | Why it is open | Current source | Next move |
| --- | --- | --- | --- |
| Docs fingerprint split | Docs guidance still has mixed content/runtime freshness in applicability signatures. The open goal is to stale recommendations only when guidance content changes, while keeping runtime metadata in diagnostics. | `improving-levers.md` Phase 5; `inc/Support/DocsGuidanceResult.php`; docs-grounding feature docs | Plan as a shared-subsystem change with signature and ability tests. |
| Learning attribution join contract | Request diagnostics have partial attribution, but the future learning loop still needs a server-side generation id and bounded join metadata propagated through shown, review, apply/insert, undo, stale-blocked, validation-blocked, and insert-failed rows. | `improving-levers.md` Phase 8 and Phase 4 future-learning note; `inc/Activity/*`; `src/store/recommendation-outcomes.js` | Treat as a shared activity/recommendation contract change. |
| Admin activity deepening and learning reports | `Settings > AI Activity` is a first audit slice, not a full observability product. Row actions/discovery, richer before/after visual inspection, and aggregate reports remain open. | `STATUS.md`; `docs/SOURCE_OF_TRUTH.md`; `improving-levers.md` Phase 9; `docs/features/activity-and-audit.md` | Start with product/UI design because this changes wp-admin workflows and report semantics. |
| Pattern adapted preview | The pattern surface currently inserts ranked patterns as Gutenberg exposes them. A forward-looking design exists for previewing and inserting a cosmetically adapted clone, with explicit open decisions around default action, content adaptation, synced-pattern detachment, local-versus-model planning, and original/adapted comparison. | `docs/features/pattern-recommendations-adapted-preview.md`; `docs/reference/block-operation-pipeline-extension-notes.md`; `docs/features/pattern-recommendations.md` | Do not implement from the outline alone. Write a fresh plan that shares the deterministic sub-block mutation engine with any block-operation expansion. |
| Content prompt-budget hardening | Content recommendations cap extracted attributes, but rendered visible text is not yet capped before broader Layer 2/3 context expansion. | `docs/features/content-recommendations.md`; `tests/phpunit/PromptBudgetTest.php` | Scope as prompt-budget work before expanding content context depth. |
| Settings validation feedback disclosure | A root review note reports that outer settings groups open for feedback errors, but nested Advanced Ranking, Block Guidelines, Sync Pattern Catalog, and Cloudflare AI Search advanced details can still hide rejected fields or detailed error codes. | `ConfirmedFindings.txt`; `inc/Admin/Settings/Page.php` | Verify the current settings markup and feedback propagation first, then open the affected nested panels from field/group error state. |
| Shared ability client/runtime normalization | A root review note reports duplicated ability path building, wrapper normalization, fallback detection, and separate editor-session client ids across the abilities bridge, bundled client, block flows, and executable-surface flows. | `ConfirmedFindings.txt`; `assets/abilities-bridge.js`; `src/store/abilities-client.js`; `src/store/executable-surface-runtime.js`; `src/store/index.js` | Revalidate the duplication, then plan one shared client/runtime identity contract before touching multiple execution paths. |
| Toast accessibility and timing cleanup | A root review note reports drift-prone toast duration duplication, missing landmark role on the toast portal root, title-only disabled Undo explanations, and duplicate surface-title casing maps. | `ConfirmedFindings.txt`; `src/store/toasts.js`; `src/components/ToastRegion.js`; `src/components/UndoToast.js`; `src/editor.css` | Treat as a focused UI/accessibility cleanup with JS unit coverage and manual screen-reader semantics review where practical. |

## Sequenced Later

These are real roadmap items, but they should not jump ahead of the prerequisites listed above.

| Item | Gate |
| --- | --- |
| Fixture harvest from learning signals (`improving-levers.md` Phase 10) | Needs learning reports and a reviewable export/redaction story first. |
| Bounded local ranking feedback (`improving-levers.md` Phase 11) | Needs reports, harvested fixtures, versioned signal families, and an operator disable path first. |
| Editable site preference summaries (`improving-levers.md` Phase 12) | Needs enough local learning signal to propose preferences, plus explicit operator review before prompt guidance changes. |
| Navigation apply | Intentional post-v1 milestone only. Do not add apply/undo without a bounded previewable executor and a dedicated plan. |
| Block operation and section/page plan expansion | `docs/reference/block-operation-pipeline-extension-notes.md` outlines larger structural operations, section/page scope, parameterized patterns, and review diffs. This is real product direction but needs a separate surface plan, path/target allowlists, and atomic multi-operation apply before code work. |
| Broader generative/editor-transform features from the original vision | Still out of scope unless promoted into a current surface plan: block subtree transforms, pattern generation/promotion, navigation overlay generation, approval-pipeline UI, dynamic block scaffolding, and pattern-to-file promotion. |

## Operational And Release Validation

These are verification or release-readiness tasks rather than product implementation work.

| Task | Source | Notes |
| --- | --- | --- |
| Swap the Docker-backed WP 7.0 browser harness from the pinned pre-release image to the official stable 7.0 image once the local stack verifies the replacement. | `STATUS.md`; `docs/reference/local-environment-setup.md` | Keep Docker available wherever the WP 7.0 harness is expected to run. |
| Complete the real-browser Site Editor Global Styles / Style Book recheck for Gutenberg 23.3.x. | `docs/reference/gutenberg-23-3-nightly-validation-2026-06-04.md`; `docs/validation/2026-06-05-gutenberg-23-3-nightly-validation.md` | The 2026-06-04 nightly pass waived canvas-backed Style Book/Global Styles in headless automation; the 2026-06-05 shell record is historical and not runtime evidence. |
| Re-run the Connector Approvals post-approval smoke when a representative text-generation provider and AI plugin state are available. | `docs/reference/wordpress-ai-roadmap-tracking.md`; `docs/validation/2026-05-21-connector-approvals-smoke.md` | Verify the pending approval caller records `flavor-agent/flavor-agent.php`; keep the artifact honest when the stack returns `missing_text_generation_provider`. |
| Keep provider-backed live recommendation validation explicit. | `docs/SOURCE_OF_TRUTH.md`; `STATUS.md` | Live recommendation proof depends on configured Connectors plus plugin-owned embedding and retrieval credentials. |
| Run release-surface sign-off checklists before v0.1.0 release decisions. | `docs/reference/release-surface-scope-review.md`; `docs/reference/release-submission-and-review.md`; `docs/reference/surfaces/release-stop-lines.md` | Open checkboxes there are release-quality and product-signoff chores unless promoted into an implementation plan. |
| Keep uninstall cleanup aligned with new plugin-owned options, tables, transients, and scheduled hooks. | `docs/SOURCE_OF_TRUTH.md`; `uninstall.php` | Add cleanup coverage as new runtime storage is introduced. |

## Upstream Watch Items

These can turn into implementation work only after the upstream contract changes or a source-grounded local decision promotes them.

| Watch item | Flavor Agent impact |
| --- | --- |
| Ability consolidation, filtering, and surface controls (`WordPress/ai#21`, `WordPress/ai#354`, `WordPress/abilities-api#38`) | Current contract is 25 abilities: seven recommendation, twelve helper/read, one docs search, and five preview siblings. Future work may consolidate them behind a smaller router or discovery layer. |
| Ability input-schema sanitization (`WordPress/ai#481`) | Re-check Flavor Agent ability input schemas and normalization once upstream callback execution lands; avoid divergent REST and Abilities sanitization paths. |
| REST-as-ability and execution lifecycle filters (`WordPress/abilities-api#75`, `WordPress/abilities-api#149`) | If accepted, decide whether activity persistence, undo-status updates, and manual pattern sync need ability equivalents or lifecycle-filter instrumentation. |
| Connector Approvals caller attribution (`WordPress/ai#595`) | Local request-time denial handling exists; final post-approval runtime success remains a smoke gate. |
| Connector ecosystem and provider-specific option contracts | Keep connector-backed chat framed broadly beyond the initial official trio. Anthropic reasoning/thinking custom options stay unmapped until that provider contract is documented; add provider-specific tests when it is. |
| Prompt-template extension points (`WordPress/ai#192`) | Hold on bespoke Flavor Agent prompt extension hooks until the canonical hook lands. |
| Site Agent / Natural Language Admin (`WordPress/ai#419`) | Future admin-chat mutation surfaces may absorb or complement editor-bound recommendation panels. Watch architecture, but do not migrate current panels preemptively. |
| Experiment initialization refactor (`WordPress/ai#159`) | If hook-based experiment initialization becomes canonical, reassess `inc/AI/FlavorAgentFeature.php`; no local refactor until the upstream pattern resolves. |
| Native streaming (`WordPress/php-ai-client#100`, AI plugin 7.1 streaming work) | `docs/reference/streaming-recommendations-design.md` owns the trigger condition. Do not add streaming endpoints, job tables, or `is_streaming_supported()` stubs before upstream primitives stabilize. |
| Secrets Management (`WordPress/ai#560` / future core API) | Watch for a sealed/encrypted backend, then migrate the two true plaintext secret options and audit adjacent retrieval/embedding settings. |
| Gutenberg 7.1 navigation sidebar, Block Fields/Bindings, Content Guidelines, RTC, DataViews/Details layouts, React 19 retries, design-tool locks, and core revisions | These affect navigation embedding, template-part apply strategy, guideline bridge write/defaults, apply freshness under collaboration, admin activity UI choices, bundled DataViews behavior under React 19, locked-attribute filtering, and whether template/template-part undo can lean on core revisions. Track in `docs/reference/gutenberg-feature-tracking.md` and the Gutenberg 23.3 validation records. |
| WP 7.0 migration opportunities that remain future-facing | Client-side Abilities admin/browser-agent tooling, Playground MCP contributor workflow, Connectors metadata growth, per-block custom CSS, pseudo-element theme tokens, broader `contentOnly`, and Pattern Overrides/Block Fields support are opportunities, not current blockers. Track in `docs/wp7-migration-opportunities.md` and promote only when tied to a surface plan. |
| Pattern settings and theme-token adapter stabilization | Keep the current compatibility layers until stable replacements are confirmed for the experimental settings keys and theme-token source shape. |
| `@wordpress/build` / `@wordpress/boot` | Tooling watch only. Stay on `@wordpress/scripts` until a migration clearly simplifies the repo or becomes required. |

## Not Active Backlog

- Archived implementation and review plans under `docs/superpowers/plans/archive/`.
- Implemented design specs under `docs/superpowers/specs/` unless a current source doc explicitly promotes a follow-up.
- Interactivity API runtime work while Flavor Agent remains editor/admin only.
- Pattern apply/undo semantics. Pattern recommendations remain an inserter ranking assist surface unless a future plan deliberately changes that product boundary.
- Media-library, media-editor, image-generation, focal-point, or crop-metadata features unless upstream starts sharing the same recommendation/apply infrastructure with Flavor Agent surfaces.

## Suggested Next Planning Order

1. Docs fingerprint split if the priority is shared-subsystem correctness rather than product-visible recommendation quality.
2. Pattern adapted preview or block-operation expansion if the priority is a new product surface; plan the shared sub-block mutation engine once, not separately per surface.
3. Learning attribution and admin activity reports if the priority is the future learning loop.
4. Release-validation chores before any v0.1.0 sign-off or upstream compatibility claim.
