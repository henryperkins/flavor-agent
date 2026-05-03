# Review Response Protocol

## Required Output Contract

- Treat every task as a code review, not an implementation pass.
- Start with findings first, ordered by severity `P0`, `P1`, `P2`, `P3`.
- Every finding must include:
  - exact file/line references,
  - observed behavior,
  - user-visible/security impact,
  - minimal credible fix direction.
- Keep confirmed findings and open questions/assumptions separate.
- If no findings are confirmed, state that plainly and list remaining residual risk.
- Include a short `Verification Reviewed` section naming inspected commands/files and any not run.

## Scope Control

- Confirm findings against live code paths and avoid treating stale docs as proof.
- For multi-surface or shared-contract work, include both shared-layer and surface-specific checks.
- Do not convert into implementation recommendations unless tied to a concrete bug or contract mismatch.
