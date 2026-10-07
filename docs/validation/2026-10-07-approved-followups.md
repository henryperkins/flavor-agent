# Approved follow-up implementation and validation - 2026-10-07

Implementation branch: `codex/oct07-followup-validation`, based on
`6b96752897d31338e0d074f23b0e64914eef2502` (the evidence-only child of master
`1cae8e14d6a90bc9f6ff81a7afccb8e7d7689270`). All product changes were made in
the managed validation worktree. The primary checkout and older worktrees were
preserved. This is local development evidence, not publication or deployment.

The approved verification decision retains maintainer-local verification as
the standing release gate. An independent runner is optional additional
assurance, not a prerequisite introduced by this follow-up. No independent
runner was installed or used; the existing release controls remain required.

## Resulting behavior

- Issue 83: public keys labelled active must match the currently usable signer;
  stale active labels become `verification-only`, and historical signatures
  remain verifiable. A status read does not register or rewrite keys. Malformed,
  unavailable, throwing, or context-drifting key configuration fails closed.
- Eligible AI Activity approvals show a non-dismissible signing-unavailable
  advisory before the note and decision controls. Approval stays enabled.
  Post-blocks is excluded; the page-load advisory does not replace execution
  checks. Boot data contains only availability and eligible surfaces.
- Issue 85: the read-only `get-theme-styles` helper accepts `edit_posts` or
  `edit_theme_options`, consistently with the available-ability inventory.
  Other helper and status permissions retain their documented boundaries.
- The [dismissal/export v1 contract](../reference/recommendation-outcome-followups.md)
  is proposed documentation. Neither runtime feature was added. The actual
  [bounded production collection](2026-10-07-real-site-outcomes.md) remains a
  restricted local review candidate with deployed-SHA/config provenance gaps.
  No ranking or scoring code changed.

## Non-browser gates

The final `node scripts/verify.js --strict --skip-e2e --json
--output=output/verify-oct07-approved-final` finished at 10:47:02 UTC with exit
0 and `status=pass`: seven requested steps passed, zero failed, and the two
browser steps were intentionally excluded and exercised separately below.

| Gate | Final result |
| --- | --- |
| Production build | Pass |
| JavaScript lint | Pass |
| Docker-backed Plugin Check 2.1.0 | Pass; no errors |
| Jest | 119 suites, 2,120 tests passed |
| PHP lint | Pass |
| Documentation checks | Pass |
| PHPUnit | 2,497 tests, 11,756 assertions passed |

The first aggregate recorded six passes and one PHP test-formatting error. The
inline associative capability array was corrected; a scoped lint/docs repair
run passed before the complete final aggregate above. Original failure logs
remain retained. Baseline webpack size warnings did not fail the build.

## Browser evidence

Playground used WordPress 7.1, Gutenberg 23.9.0, pinned CLI 3.1.51, port 19602,
and `CI=1` to require a fresh server. The isolated Docker Desktop Linux project
`flavor-agent-oct07-followups` used WordPress 7.1/PHP 8.2 at port 19604, AI
1.4.0, MCP Adapter 0.7.0, Plugin Check 2.1.0, and the exact validation worktree
bind. Existing primary/WSL Docker projects and their data were preserved.

| Run | Recorded result |
| --- | --- |
| Full Playground, first run | 30 passed, 4 failed in new advisory assertions |
| Playground advisory repair target | All 6 advisory cases passed |
| Full Docker/Gutenberg 23.9.0, first run | 41 passed, 5 failed |
| Docker/Gutenberg advisory repair target | Setup plus all 6 advisory cases passed |
| Docker/Gutenberg color-support hydration target | Setup plus both affected text/background cases passed |
| Docker/bundled editor, first attempt | Login setup failed; 45 product cases did not run |
| Docker/bundled editor after login readiness repair | All 46 cases passed in 8.8 minutes; exit 0 |

These are separate runs, not a claim that either first full suite passed. Four
new assertions per harness included WordPress Notice's hidden `Warning notice`
label in exact wrapper text; the repaired assertion checks the actual content
while retaining visibility, non-dismissal, ordering, and enabled controls.
Representative successful screenshots are retained for each harness. The
Docker screenshot SHA-256 is
`71a86a35e795734e79b8942a23cc97f656c48eaf78f3b3a48fcaa807ebb6539e`.

The fifth Gutenberg failure was an existing test's history-hydration race:
baseline count zero preceded a completed GET containing an older apply row;
final count one followed that hydration. The rejected action did not apply a
new mutation. The affected test now awaits canonical `loadActivitySession`
completion and verifies its exact scope before snapshotting the count, while
retaining unchanged styles, rejection, count, and absent-Undo assertions.

The bundled login attempt produced no POST request and remained on the login
form despite nonempty fill arguments. Public WordPress HTML schedules initial
username autofocus after load. Focus interruption is the corroborated readiness
explanation, not a logged timer event. The setup now awaits initial username
focus before either fill, preserving normal authentication and navigation
checks without sleeps, bypasses, or altered credentials.

Gutenberg 23.9.0 was explicitly deactivated before the bundled-editor runs and
independently confirmed inactive after the successful full run. The final
bundled log is `output/oct07-approved-docker-bundled-repair.log`; traces and
screenshots are retained under `output/playwright-wp70-bundled-repair/`.

## Review and remaining operational limits

Independent source/privacy review found one usable-key defect: a correctly sized
but inconsistent Ed25519 secret could extract a public half yet produce invalid
signatures. Regression failures preceded the repair. The status helper now
proves native sign/verify usability, and the reviewer independently confirmed
unavailable status plus `verification-only` for that key. All 136 focused
attestation tests/711 assertions passed. No open P0-P2 source/privacy findings
remained after repair and final documentation reconciliation.

Local source hashes, original/final verifier summaries, browser logs/traces,
positive screenshots, and safe reviewer reports are retained in ignored output.
All 17 product/test files hashed before the final gates remained unchanged;
the final 18-file manifest also includes the repaired login setup. Its SHA-256
is `db3e7fd190f41898433fbafea556179102df2390ec48bc653060ce55956997e2`.
Raw production responses and credentials were not included in this branch.
This change has no hosted CI, exact-tag, production UI, or deployed-file proof.
The [current-master gate/artifact record](2026-10-07-current-master-gates.md) and
[native Anthropic Inspector proof](2026-10-07-live-anthropic-inspector.md) retain
their earlier exact-master boundary rather than being attributed to this code.

The [scheduler record](2026-10-07-public-corpus-scheduler.md) preserves the failed
exercise and later completion of all original desired items. Its default
mixed-source freshness gate still failed. The independently triggered 10:17 UTC
daily run finished at 10:49:46 UTC with exit 1: four desired items remained
pending, mixed-source freshness failed after five attempts, and guarded cleanup
deleted zero items. This is a separate failed run, not a replacement for the
original exercise or later observation. No guard was weakened and no manual
deletion or resync was performed to manufacture a pass.
