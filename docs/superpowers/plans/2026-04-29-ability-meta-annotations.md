# Ability `meta.annotations` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add behavior annotations to all 20 Flavor Agent abilities so the WP 7.0 client-side abilities API keeps safe HTTP method routing and the MCP Adapter surfaces accurate behavior hints. True data-read abilities use WP-format `meta.annotations.{readonly,destructive,idempotent}`; LLM-invoking recommendation abilities intentionally keep WP-format `readonly` unset so large prompt/editor-context payloads continue to execute via POST, while exposing the MCP `readOnlyHint` directly.

**Architecture:** The repo already factors meta payloads through two private helpers in `inc/Abilities/Registration.php`: `public_recommendation_meta()` (7 LLM-invoking recommend-* abilities) and `readonly_rest_meta()` (10 read-only abilities). Three remaining ability registrations have inline `meta` arrays equivalent to `readonly_rest_meta()`. The plan extends both helpers with a nested `annotations` block, converts the three inline call sites to use the helper, and adds tests asserting the new shape per ability group plus complete coverage of every registered ability. No call-site rewrites beyond the three inline ones.

**Tech Stack:** PHP 8.0+, PHPUnit 9, WordPress 7.0 AI/Abilities API, MCP Adapter annotation format per [`WordPress/mcp-adapter/docs/guides/creating-abilities.md`](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md).

**Annotation values by ability group:**

| Group | Abilities | WP `readonly` | MCP `readOnlyHint` | `destructive` | `idempotent` | Core run method | Rationale |
|---|---|---|---|---|---|---|---|
| A — LLM-invoking | `recommend-block`, `recommend-content`, `recommend-patterns`, `recommend-template`, `recommend-template-part`, `recommend-navigation`, `recommend-style` (7) | unset | `true` | `false` | `false` | POST | Recommendation calls send large and sometimes sensitive prompts, post/editor context, and template/style structures. Setting WP-format `readonly:true` would make core and `@wordpress/core-abilities` execute them as GET query-string requests. Direct MCP `readOnlyHint:true` still advertises non-mutating behavior to MCP clients. |
| B — Data read | `introspect-block`, `list-allowed-blocks`, `list-patterns`, `get-pattern`, `list-synced-patterns`, `get-synced-pattern`, `list-template-parts`, `get-active-theme`, `get-theme-presets`, `get-theme-styles`, `get-theme-tokens`, `check-status`, `search-wordpress-docs` (13) | `true` | derived from WP `readonly` | `false` | `true` | GET | These are safe to call as read-oriented ability requests. They do not mutate user content, theme configuration, settings, or plugin-owned records; docs search may seed transient/cache state, which is acceptable for the read-only behavioral hint. |

Helpers map to groups: A → `public_recommendation_meta()`, B → `readonly_rest_meta()`. None of the 20 abilities are destructive from a user-content/configuration perspective. Do not set WP-format `annotations.readonly` on Group A unless the recommendation run contract is redesigned for GET-safe inputs.

---

### Task 1: RED — failing test for recommend-* annotations

**Files:**
- Modify: `tests/phpunit/RegistrationTest.php` (append a new test method)

- [ ] **Step 1.1: Append failing test**

```php
public function test_register_abilities_emits_annotations_for_recommend_abilities(): void {
    Registration::register_category();
    Registration::register_abilities();

    $expected = [
        'readOnlyHint' => true,
        'destructive'  => false,
        'idempotent'   => false,
    ];

    foreach ( [
        'flavor-agent/recommend-block',
        'flavor-agent/recommend-content',
        'flavor-agent/recommend-patterns',
        'flavor-agent/recommend-template',
        'flavor-agent/recommend-template-part',
        'flavor-agent/recommend-navigation',
        'flavor-agent/recommend-style',
    ] as $ability_id ) {
        $ability     = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
        $annotations = $ability['meta']['annotations'] ?? null;

        $this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
        $this->assertArrayNotHasKey( 'readonly', $annotations, "{$ability_id} must keep WP-format readonly unset so core executes it with POST." );
        $this->assertSame( $expected, $annotations, "{$ability_id} should declare LLM-invoking annotations." );
    }
}
```

- [ ] **Step 1.2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_recommend_abilities tests/phpunit/RegistrationTest.php
```

Expected: FAIL with `Failed asserting that null is of type "array"` on the first ability id, because `meta.annotations` does not exist yet.

---

### Task 2: GREEN — extend `public_recommendation_meta()`

**Files:**
- Modify: `inc/Abilities/Registration.php:1251-1258`

- [ ] **Step 2.1: Update helper**

```php
private static function public_recommendation_meta(): array {
    return [
        'show_in_rest' => true,
        'mcp'          => [
            'public' => true,
        ],
        'annotations'  => [
            'readOnlyHint' => true,
            'destructive'  => false,
            'idempotent'   => false,
        ],
    ];
}
```

Do not add `readonly => true` here. WordPress core's Abilities run controller maps WP-format `annotations.readonly:true` to GET, and `@wordpress/core-abilities` sends GET inputs as query parameters. The seven recommendation abilities need POST because their inputs can include prompts, selected block context, post content, template structures, and style snapshots.

- [ ] **Step 2.2: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_recommend_abilities tests/phpunit/RegistrationTest.php
```

Expected: PASS (1 test, 21 assertions).

- [ ] **Step 2.3: Run regression test for the existing MCP-public coverage**

```bash
vendor/bin/phpunit --filter test_register_abilities_marks_ai_recommendations_public_for_mcp tests/phpunit/RegistrationTest.php
```

Expected: PASS — existing `meta.show_in_rest` and `meta.mcp.public` assertions still hold because we added a sibling key, not replaced anything.

---

### Task 3: RED — failing test for read-ability annotations

**Files:**
- Modify: `tests/phpunit/RegistrationTest.php` (append a second new test method)

- [ ] **Step 3.1: Append failing test**

```php
public function test_register_abilities_emits_annotations_for_read_abilities(): void {
    Registration::register_category();
    Registration::register_abilities();

    $expected = [
        'readonly'    => true,
        'destructive' => false,
        'idempotent'  => true,
    ];

    foreach ( [
        'flavor-agent/introspect-block',
        'flavor-agent/list-allowed-blocks',
        'flavor-agent/list-patterns',
        'flavor-agent/get-pattern',
        'flavor-agent/list-synced-patterns',
        'flavor-agent/get-synced-pattern',
        'flavor-agent/list-template-parts',
        'flavor-agent/get-active-theme',
        'flavor-agent/get-theme-presets',
        'flavor-agent/get-theme-styles',
        'flavor-agent/get-theme-tokens',
        'flavor-agent/check-status',
        'flavor-agent/search-wordpress-docs',
    ] as $ability_id ) {
        $ability     = WordPressTestState::$registered_abilities[ $ability_id ] ?? null;
        $annotations = $ability['meta']['annotations'] ?? null;

        $this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
        $this->assertSame( $expected, $annotations, "{$ability_id} should declare read-only annotations." );
    }
}
```

- [ ] **Step 3.2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_read_abilities tests/phpunit/RegistrationTest.php
```

Expected: PARTIAL FAIL — abilities that already use `readonly_rest_meta()` (10 of 13) will fail with `null is not of type "array"`, AND the three inline-meta abilities (`search-wordpress-docs`, `get-theme-tokens`, `check-status`) will also fail. All 13 must fail at this point.

---

### Task 4: GREEN — extend `readonly_rest_meta()`

**Files:**
- Modify: `inc/Abilities/Registration.php:1260-1265`

- [ ] **Step 4.1: Update helper**

```php
private static function readonly_rest_meta(): array {
    return [
        'show_in_rest' => true,
        'readonly'     => true,
        'annotations'  => [
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ];
}
```

The top-level `readonly` key stays (it's an existing Flavor Agent convention used by callers we don't want to break). The new `annotations.readonly` is the canonical WP 7.0 / MCP placement.

- [ ] **Step 4.2: Run test**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_read_abilities tests/phpunit/RegistrationTest.php
```

Expected: PARTIAL PASS — the 10 abilities using the helper now pass; 3 inline-meta abilities still fail (`search-wordpress-docs`, `get-theme-tokens`, `check-status`). Confirm the failure message names exactly those three.

---

### Task 5: GREEN — convert inline-meta call sites to the helper

**Files:**
- Modify: `inc/Abilities/Registration.php:1065-1068` (search-wordpress-docs)
- Modify: `inc/Abilities/Registration.php:1161-1164` (get-theme-tokens)
- Modify: `inc/Abilities/Registration.php:1239-1242` (check-status)

- [ ] **Step 5.1: Replace inline meta on `flavor-agent/search-wordpress-docs`**

Locate this block at line 1065:

```php
				'meta'                => [
					'show_in_rest' => true,
					'readonly'     => true,
				],
```

Replace with:

```php
				'meta'                => self::readonly_rest_meta(),
```

- [ ] **Step 5.2: Replace inline meta on `flavor-agent/get-theme-tokens`**

Locate the same exact block at line 1161 (immediately after the `enabledFeatures`/`blockPseudoStyles` output_schema closure). Replace with:

```php
				'meta'                => self::readonly_rest_meta(),
```

- [ ] **Step 5.3: Replace inline meta on `flavor-agent/check-status`**

Locate the same exact block at line 1239 (inside the `register_infra_abilities()` `check-status` registration). Replace with:

```php
				'meta'                => self::readonly_rest_meta(),
```

- [ ] **Step 5.4: Run the read-ability annotations test**

```bash
vendor/bin/phpunit --filter test_register_abilities_emits_annotations_for_read_abilities tests/phpunit/RegistrationTest.php
```

Expected: PASS (1 test, 26 assertions).

- [ ] **Step 5.5: Add a complete coverage guard for all registered abilities**

Append a third test method so future abilities cannot be added without assigning them to an annotation group:

```php
public function test_register_abilities_annotation_expectations_cover_every_registered_ability(): void {
    Registration::register_category();
    Registration::register_abilities();

    $expected = [
        'flavor-agent/recommend-block',
        'flavor-agent/recommend-content',
        'flavor-agent/recommend-patterns',
        'flavor-agent/recommend-template',
        'flavor-agent/recommend-template-part',
        'flavor-agent/recommend-navigation',
        'flavor-agent/recommend-style',
        'flavor-agent/introspect-block',
        'flavor-agent/list-allowed-blocks',
        'flavor-agent/list-patterns',
        'flavor-agent/get-pattern',
        'flavor-agent/list-synced-patterns',
        'flavor-agent/get-synced-pattern',
        'flavor-agent/list-template-parts',
        'flavor-agent/get-active-theme',
        'flavor-agent/get-theme-presets',
        'flavor-agent/get-theme-styles',
        'flavor-agent/get-theme-tokens',
        'flavor-agent/check-status',
        'flavor-agent/search-wordpress-docs',
    ];

    $actual = array_keys( WordPressTestState::$registered_abilities );
    sort( $expected );
    sort( $actual );

    $this->assertSame( $expected, $actual, 'Every registered ability should be assigned to an annotation group.' );

    foreach ( $actual as $ability_id ) {
        $annotations = WordPressTestState::$registered_abilities[ $ability_id ]['meta']['annotations'] ?? null;

        $this->assertIsArray( $annotations, "{$ability_id} should declare meta.annotations." );
    }
}
```

- [ ] **Step 5.6: Run the full RegistrationTest file to catch regressions**

```bash
vendor/bin/phpunit tests/phpunit/RegistrationTest.php
```

Expected: PASS — all existing tests still green, plus 3 new tests. The inline→helper conversion is byte-equivalent for `show_in_rest` and top-level `readonly` so no existing test should break.

---

### Task 6: Verify full PHP suite + lint

- [ ] **Step 6.1: Run the full PHPUnit suite**

```bash
composer test:php
```

Expected: PASS — previous suite count + 3 new tests, all green.

- [ ] **Step 6.2: Run PHPCS lint**

```bash
composer lint:php
```

Expected: PASS clean. If WPCS flags equals-sign alignment in `public_recommendation_meta()` or `readonly_rest_meta()`, run `vendor/bin/phpcbf inc/Abilities/Registration.php` and re-run lint.

- [ ] **Step 6.3: Run docs freshness check**

```bash
npm run check:docs
```

Expected: PASS clean.

---

### Task 7: Update tracking doc

**Files:**
- Modify: `docs/reference/gutenberg-feature-tracking.md`

- [ ] **Step 7.1: Update the "Stabilized APIs Already Used" row for the Abilities API**

Locate the row matching `Client-side Abilities API (\`@wordpress/abilities\`, \`@wordpress/core-abilities\`)` and replace its Status column text:

```markdown
| Client-side Abilities API (`@wordpress/abilities`, `@wordpress/core-abilities`) | WP 7.0 | `inc/Abilities/Registration.php` | Server abilities hydrate into the client `core/abilities` store automatically. All 20 abilities now declare behavior annotations: the 7 LLM-invoking recommend-* abilities keep WP-format `readonly` unset so core/client execution stays POST for large prompt/editor payloads while declaring direct MCP `readOnlyHint:true`, `destructive:false`, and `idempotent:false`; the 13 read abilities declare WP-format `readonly:true`, `destructive:false`, and `idempotent:true`. The MCP Adapter exposes the equivalent MCP `readOnlyHint` / `destructiveHint` / `idempotentHint` hints. |
```

- [ ] **Step 7.2: Remove the abilities-annotations row from "Worth Adopting"**

Delete this row entirely from the "Stabilized APIs Worth Adopting (Not Yet Used)" table:

```markdown
| Ability `meta.annotations.{readonly,destructive,idempotent}` | WP 7.0 | Drives client-side method routing and MCP exposure. Read abilities should be `readonly: true`; apply abilities should declare `destructive` and `idempotent` correctly. | `inc/Abilities/Registration.php`, `inc/Abilities/*Abilities.php` |
```

- [ ] **Step 7.3: Strike through action implication #2**

Locate action implication #2 in the `## Action Implications For Flavor Agent` section and replace with:

```markdown
2. ~~Add behavior annotations to every ability registration in `inc/Abilities/Registration.php` and per-category ability files. Read abilities default to `readonly: true`; recommendation/apply-like surfaces declare method-safe MCP and idempotency hints correctly.~~ **Done 2026-04-29.** Both meta helpers (`public_recommendation_meta()`, `readonly_rest_meta()`) emit nested `annotations` blocks; recommendation abilities keep WP-format `readonly` unset to preserve POST routing while exposing MCP `readOnlyHint:true`, and the three inline `meta` call sites now use `readonly_rest_meta()`. Tests at `tests/phpunit/RegistrationTest.php` cover both ability groups plus complete registered-ability coverage.
```

- [ ] **Step 7.4: Update the "Action implications X..." summary line**

Locate the line that reads:

```markdown
Action implications 2, 3, 4, 5, 7, and 8 above describe upstream pressures with no corresponding workstream yet. Implication 1 (`wp_ai_client_prevent_prompt`) shipped 2026-04-29 as a small additive change in `inc/LLM/WordPressAIClient.php` (no workstream needed).
```

Replace with:

```markdown
Action implications 3, 4, 5, 7, and 8 above describe upstream pressures with no corresponding workstream yet. Implications 1 (`wp_ai_client_prevent_prompt`) and 2 (`meta.annotations`) shipped 2026-04-29 as small additive changes in `inc/LLM/WordPressAIClient.php` and `inc/Abilities/Registration.php` (no workstream needed).
```

---

### Task 8: Update STATUS.md

**Files:**
- Modify: `STATUS.md`

- [ ] **Step 8.1: Append a bullet immediately after the existing `wp_ai_client_prevent_prompt` line**

After the bullet starting `- WP 7.0 \`wp_ai_client_prevent_prompt\` filter is honored:`, append:

```markdown
- WP 7.0 ability `meta.annotations` are populated for all 20 abilities. The 7 LLM-invoking recommend-* abilities keep WP-format `readonly` unset so core/client execution stays POST for large prompt/editor payloads while declaring direct MCP `readOnlyHint:true`, `destructive:false`, and `idempotent:false`; the 13 read abilities declare `readonly:true`, `destructive:false`, and `idempotent:true`. The MCP Adapter exposes the equivalent MCP `readOnlyHint`/`destructiveHint`/`idempotentHint`.
```

---

### Task 9: Update abilities-and-routes reference

**Files:**
- Modify: `docs/reference/abilities-and-routes.md`

- [ ] **Step 9.1: Add annotation behavior to Ability Notes**

The doc already surfaces ability metadata in the Ability Notes section via `meta.mcp.public`, so update that section instead of adding columns to every ability row.

Append this bullet immediately after the existing bullet about the seven AI recommendation abilities opting into `meta.mcp.public = true`:

```markdown
- All twenty abilities declare behavior annotations. The seven AI recommendation abilities keep WP-format `meta.annotations.readonly` unset so core and `@wordpress/core-abilities` run calls stay POST for large prompt/editor payloads, while exposing direct MCP `readOnlyHint:true`, `destructive:false`, and `idempotent:false`; the 13 data-read abilities declare WP-format `readonly:true`, `destructive:false`, and `idempotent:true`.
```

---

### Task 10: Final verification

- [ ] **Step 10.1: Re-run the full verification triple**

```bash
composer test:php && composer lint:php && npm run check:docs
```

Expected: all three PASS clean.

- [ ] **Step 10.2: Re-run the targeted file to confirm new test count**

```bash
vendor/bin/phpunit tests/phpunit/RegistrationTest.php
```

Expected: previous count + 3 new tests, all green.

---

### Task 11: Commit

- [ ] **Step 11.1: Stage the diff**

```bash
git add inc/Abilities/Registration.php tests/phpunit/RegistrationTest.php docs/reference/gutenberg-feature-tracking.md docs/reference/abilities-and-routes.md STATUS.md
```

- [ ] **Step 11.2: Commit**

```bash
git commit -F - <<'EOF'
feat: add ability behavior annotations

Populate meta.annotations for every ability so the WP 7.0 client-side
abilities API and MCP Adapter route HTTP methods safely. The 7
recommend-* abilities keep WP-format readonly unset so large
prompt/editor payloads continue to execute via POST while exposing MCP
readOnlyHint:true; the 13 data-read abilities declare readonly:true and
idempotent:true. All 20 declare destructive:false.

Two existing meta helpers were extended; three inline meta call sites
converted to use readonly_rest_meta().

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>
EOF
```

- [ ] **Step 11.3: Confirm commit succeeded**

```bash
git status
git log -1 --stat
```

Expected: clean working tree; commit shows ~5 files changed.

---

## Self-Review

**Spec coverage:**
- Group A (7 recommend-* abilities) → covered by Task 1 (RED) + Task 2 (GREEN).
- Group B (13 read abilities) → covered by Task 3 (RED) + Task 4 (GREEN, helper) + Task 5 (GREEN, inline conversions).
- Complete annotation coverage guard → Task 5.
- Doc updates → Tasks 7, 8, 9.
- Verification → Task 6 (mid-flow) + Task 10 (final).
- Commit → Task 11.

**Placeholder scan:** None present. Every step shows the actual code, file path, or command.

**Type / name consistency:** Helper names (`public_recommendation_meta`, `readonly_rest_meta`) match across all tasks. Ability ids match the canonical 20 in `STATUS.md`. The annotation keys (`readOnlyHint`, `readonly`, `destructive`, `idempotent`) are byte-identical across all task references. Group A intentionally uses direct MCP `readOnlyHint` and omits WP-format `readonly`; Group B uses WP-format `readonly`.

**Counts sanity check:** 7 + 13 = 20 abilities. Helper users = 7 (Group A) + 10 (Group B excluding inlines) + 3 (newly using Group B helper after Task 5) = 20. Matches. Three new PHPUnit tests cover Group A shape, Group B shape, and complete registered-ability coverage.
