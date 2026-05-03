# Guidelines Bridge Do-Now Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Follow the execution protocol in [../reference/agentic-plan-implementation-guide.md](../reference/agentic-plan-implementation-guide.md). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the Workstream D bridge slice that can read core/Gutenberg Guidelines when available, keep legacy options as fallback, and include guidelines in recommendation prompts.

**Architecture:** Keep `FlavorAgent\Guidelines` as the public facade and move storage reads behind small repository classes. Prompt builders consume guidelines through one formatter so storage can change without prompt code churn. Settings copy reports core storage as the runtime source while leaving legacy fields available for migration/import-export.

**Tech Stack:** WordPress plugin PHP 8.0, Composer PSR-4 autoloading under `inc/`, PHPUnit, WordPress Settings API, Gutenberg experimental Guidelines storage shape (`wp_guideline`, `wp_guideline_type`).

---

### Task 1: Repository Bridge Tests

**Files:**
- Modify: `tests/phpunit/bootstrap.php`
- Modify: `tests/phpunit/GuidelinesTest.php`

- [ ] **Step 1: Add test bootstrap support**

Add registered post type and taxonomy state to `WordPressTestState`, reset it in `reset()`, and add test stubs for `register_post_type()`, `post_type_exists()`, `register_taxonomy()`, and `taxonomy_exists()`.

- [ ] **Step 2: Write failing repository tests**

Add tests proving `Guidelines::get_all()` prefers `wp_guideline` post meta over legacy options, `Guidelines::storage_status()` reports core storage, and legacy options remain the fallback.

- [ ] **Step 3: Run tests to verify red**

Run: `vendor/bin/phpunit tests/phpunit/GuidelinesTest.php`

Expected: failures for missing facade methods/classes or legacy-only reads.

### Task 2: Repository Bridge Implementation

**Files:**
- Create: `inc/Guidelines/GuidelinesRepository.php`
- Create: `inc/Guidelines/LegacyGuidelinesRepository.php`
- Create: `inc/Guidelines/CoreGuidelinesRepository.php`
- Create: `inc/Guidelines/RepositoryResolver.php`
- Modify: `inc/Guidelines.php`

- [ ] **Step 1: Add repository contract and legacy implementation**

Define `GuidelinesRepository` with `get_all()` and `source()`. Move option reads into `LegacyGuidelinesRepository`.

- [ ] **Step 2: Add core implementation**

Read the newest `wp_guideline` post when the post type exists, normalize `_guideline_*` meta, and parse `_guideline_block_*` meta into `core/block` names. Support the older `wp_content_guideline` post type as a read-only compatibility fallback.

- [ ] **Step 3: Add resolver and facade methods**

Resolve the active repository through a filter override, then core/Gutenberg detection, then legacy fallback. Add `Guidelines::storage_status()` and keep existing facade methods stable.

- [ ] **Step 4: Run tests to verify green**

Run: `vendor/bin/phpunit tests/phpunit/GuidelinesTest.php`

Expected: all `GuidelinesTest` tests pass.

### Task 3: Prompt Guidelines Formatter

**Files:**
- Create: `inc/Guidelines/PromptGuidelinesFormatter.php`
- Modify: `inc/LLM/Prompt.php`
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Modify: `inc/LLM/NavigationPrompt.php`
- Modify: `inc/LLM/StylePrompt.php`
- Modify: `inc/LLM/WritingPrompt.php`
- Modify: prompt-related PHPUnit tests

- [ ] **Step 1: Write failing prompt tests**

Add prompt tests proving site/copy/image/additional guidelines and a selected block guideline are included in block prompts, and site/copy guidelines are included in content prompts.

- [ ] **Step 2: Implement formatter**

Create one formatter that emits a compact `## Site Guidelines` section and omits the section when no guidelines exist.

- [ ] **Step 3: Wire prompt builders**

Add the formatted section to all recommendation prompt builders with existing prompt-budget APIs where applicable.

- [ ] **Step 4: Run targeted prompt tests**

Run: `vendor/bin/phpunit tests/phpunit/PromptFormattingTest.php tests/phpunit/WritingPromptTest.php tests/phpunit/TemplatePromptTest.php tests/phpunit/TemplatePartPromptTest.php tests/phpunit/NavigationAbilitiesTest.php tests/phpunit/StylePromptTest.php`

Expected: all targeted prompt tests pass.

### Task 4: Settings Copy And Docs

**Files:**
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/State.php`
- Modify: `inc/Admin/Settings/Help.php`
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `docs/wordpress-7.0-gutenberg-overlap-remediation-plan.md`
- Modify: `docs/features/settings-backends-and-sync.md`
- Modify: `docs/reference/wordpress-ai-roadmap-tracking.md`

- [ ] **Step 1: Write failing settings test**

Add a test proving the settings page labels legacy guideline fields as migration/admin tooling when core Guidelines storage is detected.

- [ ] **Step 2: Implement settings copy/status**

Use `Guidelines::storage_status()` to show the active source, keep legacy controls visible, and update Help text.

- [ ] **Step 3: Update docs**

Document the immediate bridge, the `wp_guideline` / `wp_guideline_type` target, the future `wp_register_guideline()` hook point, and deferred theme file defaults.

- [ ] **Step 4: Run targeted settings/docs tests**

Run: `vendor/bin/phpunit tests/phpunit/SettingsTest.php --filter 'guidelines|help|render_page_moves_setup_guidance'` and `npm run check:docs`.

Expected: settings tests pass and docs freshness is clean.

### Task 5: Verification

**Files:**
- All touched implementation, test, and docs files.

- [ ] **Step 1: Run targeted PHP suite**

Run: `vendor/bin/phpunit tests/phpunit/GuidelinesTest.php tests/phpunit/PromptFormattingTest.php tests/phpunit/WritingPromptTest.php tests/phpunit/SettingsTest.php`

Expected: all targeted tests pass.

- [ ] **Step 2: Run lint/docs checks**

Run: `composer run lint:php` and `npm run check:docs`.

Expected: no PHP lint warnings/errors and docs check clean.

- [ ] **Step 3: Report residual gates**

If full `npm run verify` or browser E2E is not run, report that explicitly with the reason.
