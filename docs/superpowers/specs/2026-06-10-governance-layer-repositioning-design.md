# Governance-Layer Repositioning — Design

> Date: 2026-06-10
> Status: approved (Approach B), implemented in the same pass
> Scope: positioning/docs plus one translatable PHP string; zero behavior change

## Goal

Reposition Flavor Agent so the governance layer is the product and the recommendation surfaces are the demonstration, per the approved thesis:

> Flavor Agent lets AI work on a live WordPress site without unchecked control. Every AI action runs through one governance layer: operations validated against bounded schemas, structural changes gated behind review, every apply attributed and recorded server-side, every change reversible with drift detection so an undo never clobbers later human edits. Humans get this through native Gutenberg and Site Editor surfaces; external agents get the same contracts through the Abilities API and MCP. Built on the WordPress 7.0 AI stack. The recommendation surfaces are the demonstration; the governance layer is the product.

## Approved scope (Approach B)

1. New canonical reference doc `docs/reference/governance-layer.md`: governed loop, five pillars (Bounded / Reviewed / Attributed / Reversible / Fresh) each mapped to enforcing code and tests, surface loop-coverage table, external-agent parity boundary. Wired into the docs backbone (`docs/README.md` reading order, doc ownership, current backbone) and registered in `scripts/check-doc-freshness.sh` (`live_docs` plus a per-file reference guard).
2. Lead inversion in outward artifacts: `flavor-agent.php` header description, `readme.txt` short description and Description lead, `README.md` lead paragraph, `docs/SOURCE_OF_TRUTH.md` "What This Plugin Is" and the programmatic-surface paragraph, `docs/README.md` Product Direction (governance principle promoted to #1), `CLAUDE.md` and `.github/copilot-instructions.md` opening paragraphs (kept identical).
3. Protocol-level positioning: the dedicated MCP server description in `inc/MCP/ServerBootstrap.php` (the string external agents read in `tools/list`).
4. `docs/FEATURE_SURFACE_MATRIX.md` intro reframed as the governed-loop demonstration table.
5. Honesty qualifiers applied wherever the thesis is restated:
   - "every apply **the plugin owns**" — pattern-shelf direct inserts are signature-revalidated but intentionally outside apply/undo recording; content is editorial-only; navigation is advisory-only.
   - "the same **recommendation, validation, and freshness** contracts" — no apply/undo/activity abilities exist; applies and undo are editor-owned and activity persistence is REST-only.

## Out of scope

- Approach C (apply/undo/activity abilities for external agents) — not approved; no roadmap entry added.
- `readme.txt` tags, FAQ, installation, and external-services disclosures — unchanged (WordPress.org review-sensitive text).
- Any behavior change.

## Truth constraints the copy must respect

- Full governed loop: block, template, template-part, Global Styles, Style Book.
- Generation-side governance is caller-independent: every recommendation execution (including advisory and external) flows through `RecommendationAbilityExecution::execute()` and writes a `request_diagnostic` activity row.
- `docs/SOURCE_OF_TRUTH.md` must keep the doc-freshness-guarded string `Eight first-party recommendation surfaces exist today` byte-exact.
- `CLAUDE.md` and `.github/copilot-instructions.md` openings stay in sync (parity-guard convention).

## Verification

- `npm run check:docs` (now also enforces `governance-layer.md` existence and backbone references)
- `vendor/bin/phpunit --filter MCPServerBootstrapTest` and phpcs on `inc/MCP/ServerBootstrap.php`
- `git diff --check` (CRLF-sensitive files `docs/README.md` and `STATUS.md` edited via perl)
