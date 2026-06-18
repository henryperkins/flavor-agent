# Content Prompt-Budget Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cap oversized content-recommendation draft context before it can consume the whole prompt budget.

**Architecture:** Keep prompt budgeting centralized in `PromptBudget`; add a reusable string trimmer and call it only from `WritingPrompt`'s existing-draft section. Documentation closes the live `current-open-work` row once the targeted PHPUnit coverage is green.

**Tech Stack:** PHP 8.2, PHPUnit, WordPress prompt filters, existing `FlavorAgent\LLM` prompt builders.

**Spec:** `docs/superpowers/specs/2026-06-18-content-prompt-budget-hardening-design.md`

---

## Task 1: Add Failing Prompt-Budget Coverage

**Files:**
- Modify: `tests/phpunit/PromptBudgetTest.php`
- Modify: `tests/phpunit/WritingPromptTest.php`

- [x] **Step 1: Add `PromptBudget` trimmer tests**

Add tests proving `PromptBudget::trim_to_tokens()` preserves in-budget text and trims long
text with a marker, head context, and tail context.

- [x] **Step 2: Add `WritingPrompt` existing-draft cap test**

Add a test with `flavor_agent_prompt_budget_max_tokens` forced to `2000`, a very large draft,
and a user instruction. Expected behavior: the user prompt stays within the normalized budget,
contains `## Existing draft`, contains the start and end of the draft, contains the truncation
marker, and keeps `## User instruction`.

- [x] **Step 3: Run red tests**

Run:

```bash
vendor/bin/phpunit --filter "PromptBudgetTest|WritingPromptTest"
```

Expected: FAIL because `PromptBudget::trim_to_tokens()` does not exist and `WritingPrompt`
does not yet add a truncation marker.

## Task 2: Implement The Draft Cap

**Files:**
- Modify: `inc/LLM/PromptBudget.php`
- Modify: `inc/LLM/WritingPrompt.php`

- [x] **Step 1: Add the shared trimmer**

Add `PromptBudget::get_max_tokens()` plus `PromptBudget::trim_to_tokens()`. The trimmer uses
the existing estimated-token model, preserves UTF-8 when `mb_*` is available, and keeps head
and tail context around a marker.

- [x] **Step 2: Cap `WritingPrompt` existing draft**

Before adding `existing_draft`, call the trimmer with:

- cap = 60% of normalized prompt budget;
- minimum = 800 estimated tokens;
- maximum = 8000 estimated tokens;
- marker = `[... draft truncated for prompt budget ...]`.

- [x] **Step 3: Run green tests**

Run:

```bash
vendor/bin/phpunit --filter "PromptBudgetTest|WritingPromptTest"
```

Expected: PASS.

## Task 3: Update Docs And Queue State

**Files:**
- Modify: `docs/features/content-recommendations.md`
- Modify: `docs/reference/current-open-work.md`
- Move: `docs/superpowers/plans/2026-06-18-content-prompt-budget-hardening.md` to `docs/superpowers/plans/archive/2026-06-18-content-prompt-budget-hardening.md`

- [x] **Step 1: Update content recommendation docs**

Replace the old guardrail saying Layer 1 does not cap rendered visible text with the new
section-cap behavior.

- [x] **Step 2: Update current open work**

Add a 2026-06-18 status note and remove the `Content prompt-budget hardening` row from
`Current Implementation Candidates`.

- [x] **Step 3: Archive this plan**

Move this plan into `docs/superpowers/plans/archive/` after the implementation is complete.

## Task 4: Verify

- [x] **Step 1: Run targeted PHPUnit**

```bash
vendor/bin/phpunit --filter "PromptBudgetTest|WritingPromptTest|PostContentRendererTest"
```

Expected: PASS.

- [x] **Step 2: Run docs and whitespace checks**

```bash
npm run check:docs
git diff --check
```

Expected: PASS.
