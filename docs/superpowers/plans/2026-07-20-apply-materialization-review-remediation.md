# Apply-Materialization Review Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the slug-normalization regression introduced into the template / template-part first-materialization path, make the new defensive branches and the `$fresh` identity change provable by test, and close the release-hygiene gaps the review found.

**Architecture:** Seven independent tasks against the uncommitted working tree. Task 1 is the only production behavior fix (a real regression) and is ordered first. Tasks 2–3 convert untested and unreachable new code into covered code, which requires two small fidelity improvements to the PHPUnit WordPress stubs. Tasks 4–6 are tooling/hygiene. Task 7 runs the cross-surface validation gates that `docs/reference/cross-surface-validation-gates.md` requires for a multi-surface apply-contract change.

**Tech Stack:** PHP 8.2 (PSR-4 `FlavorAgent\`), PHPUnit 9 with hand-rolled WordPress stubs in `tests/phpunit/bootstrap.php`, Bash + ripgrep for doc guards, GitHub Actions, Docker for the WP 7.0 E2E harness.

## Global Constraints

- PHP 8.2+, WordPress 7.0+. Do not use syntax newer than PHP 8.2.
- All PHP must pass `composer lint:php` (WPCS). Tabs for indentation, Yoda conditions, `sanitize_*`/`esc_*` discipline.
- Governed write paths must **fail closed**: on any uncertainty, return `WP_Error` and perform zero writes. Never widen a gate to make a test pass.
- Do not add filter seams to `inc/Apply/**` — the existing comments state that a governed write path must not be interceptable. Test seams belong in `tests/phpunit/bootstrap.php` only.
- Every new PHPUnit test must be *discriminating*: it must fail if the production change it covers is reverted. State that in the test's docblock.
- Existing behavior contracts that must not change: `flavor_agent_apply_target_changed` (409), `flavor_agent_apply_slug_conflict` (409), `flavor_agent_apply_write_failed` (500), `flavor_agent_apply_post_write_read_failed` (500).
- Run `vendor/bin/phpunit` after every PHP task. Baseline is **OK (1925 tests, 8827 assertions)** — the count only ever goes up.
- Commit after each task. Do not squash tasks into one commit.

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `inc/Apply/TemplateApplyExecutor.php` | Template lane: slug normalization fix, dead-code removal | 1, 5 |
| `inc/Apply/TemplatePartApplyExecutor.php` | Template-part lane: same fix, docblock, dead-code removal | 1, 5 |
| `tests/phpunit/bootstrap.php` | WP stub fidelity (`sanitize_title` on `post_name`) + two new test seams | 1, 3 |
| `tests/phpunit/TemplateApplyExecutorTest.php` | Template-lane coverage | 1, 2, 3 |
| `tests/phpunit/TemplatePartApplyExecutorTest.php` | Template-part-lane coverage + comment correction | 1, 2, 3, 5 |
| `scripts/check-doc-freshness.sh` | Regex guard helper; ability-count guard; `live_docs` list | 4 |
| `blueprint.json` | Playground WP pin | 5 |
| `.github/workflows/verify.yml` | CI: wp70 job, composer cache | 6 |

---

### Task 1: Fix the slug-normalization regression (P1)

`persist()` sends `sanitize_key( $entity->slug )` to `wp_insert_post`, but core stores `post_name` through `sanitize_title()`. `sanitize_key` preserves `--` and edge dashes; `sanitize_title` collapses and trims them. The new post-insert read-back compares the two normalizers, so any such slug reads as a collision: the correctly-written row is force-deleted and the apply fails permanently with `flavor_agent_apply_slug_conflict` — a diagnosis that is false. `reconcile_existing_row` cannot recover either, because it probes `slug__in => ['page--wide']` while the stored name is `page-wide`.

**Files:**
- Modify: `tests/phpunit/bootstrap.php:3334-3355` (`wp_insert_post` stub — fidelity)
- Modify: `inc/Apply/TemplateApplyExecutor.php:391` and `:454`
- Modify: `inc/Apply/TemplatePartApplyExecutor.php:382` and `:443`
- Test: `tests/phpunit/TemplateApplyExecutorTest.php`, `tests/phpunit/TemplatePartApplyExecutorTest.php`

**Interfaces:**
- Consumes: `WordPressTestState::$inserted_posts`, `WordPressTestState::$deleted_posts`; test helpers `seed_template( string $content, int $wp_id = 0, string $slug = 'home' )`, `seed_part( string $content, int $wp_id = 0, string $area = '', string $slug = 'header' )`, `entry( array $operations )`, `register_pattern( string $name, string $content )`, `paragraph( string $text )`.
- Produces: `$slug` inside both `persist()` methods is now idempotent under `sanitize_title()`, so `reconcile_existing_row( $slug, ... )` probes the value core actually stores. Tasks 2 and 3 depend on this.

- [ ] **Step 1: Make the `wp_insert_post` stub model core's `post_name` normalization**

Core runs `sanitize_title()` on `post_name`, then `wp_unique_post_slug()`, then the `wp_insert_post_data` filter. The stub skips the first step, so it cannot reproduce the bug. Add it *before* the filter so the existing collision tests (which override `post_name` from that filter) keep working.

In `tests/phpunit/bootstrap.php`, replace:

```php
	if (! function_exists('wp_insert_post')) {
		function wp_insert_post(array $postarr, bool $wp_error = false)
		{
			unset($wp_error);

			$filtered = apply_filters('wp_insert_post_data', $postarr, $postarr, $postarr, false);
```

with:

```php
	if (! function_exists('wp_insert_post')) {
		function wp_insert_post(array $postarr, bool $wp_error = false)
		{
			unset($wp_error);

			// Core normalizes post_name through sanitize_title() BEFORE the
			// wp_insert_post_data filter runs, so a caller-supplied slug that
			// sanitize_title() rewrites (`a--b` -> `a-b`, `-a-` -> `a`) is stored
			// rewritten. Modelling this is what lets the suite catch a caller that
			// compares its pre-insert slug against the stored post_name using a
			// different normalizer.
			if (isset($postarr['post_name']) && is_string($postarr['post_name'])) {
				$postarr['post_name'] = sanitize_title($postarr['post_name']);
			}

			$filtered = apply_filters('wp_insert_post_data', $postarr, $postarr, $postarr, false);
```

- [ ] **Step 2: Write the failing test for the template lane**

Append to `tests/phpunit/TemplateApplyExecutorTest.php`, inside the class:

```php
	/**
	 * Core writes post_name through sanitize_title(), which collapses repeated
	 * dashes and trims edge dashes; sanitize_key() does neither. Comparing the
	 * two normalizers read a legitimate slug as a collision, force-deleted the
	 * correctly written row, and failed the apply with a slug conflict that does
	 * not exist -- permanently, because reconcile_existing_row() then probes a
	 * slug that is not what got stored. Discriminating: reverting persist() to
	 * sanitize_key() makes this fail with flavor_agent_apply_slug_conflict.
	 */
	public function test_materialization_accepts_a_slug_core_renormalizes(): void {
		$this->seed_template( $this->paragraph( 'Anchor' ), 0, 'page--wide' );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A renormalized slug is not a collision and must not delete the row.' );
		$this->assertSame( 'page-wide', (string) WordPressTestState::$inserted_posts[0]['post_name'] );
	}
```

- [ ] **Step 3: Write the failing test for the template-part lane**

Append to `tests/phpunit/TemplatePartApplyExecutorTest.php`, inside the class:

```php
	/**
	 * Core writes post_name through sanitize_title(), which collapses repeated
	 * dashes and trims edge dashes; sanitize_key() does neither. Comparing the
	 * two normalizers read a legitimate slug as a collision, force-deleted the
	 * correctly written row, and failed the apply with a slug conflict that does
	 * not exist -- permanently, because reconcile_existing_row() then probes a
	 * slug that is not what got stored. Discriminating: reverting persist() to
	 * sanitize_key() makes this fail with flavor_agent_apply_slug_conflict.
	 */
	public function test_materialization_accepts_a_slug_core_renormalizes(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'site--header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A renormalized slug is not a collision and must not delete the row.' );
		$this->assertSame( 'site-header', (string) WordPressTestState::$inserted_posts[0]['post_name'] );
	}
```

- [ ] **Step 4: Run both tests to verify they fail**

```bash
vendor/bin/phpunit --filter test_materialization_accepts_a_slug_core_renormalizes
```

Expected: **2 failures**, each reporting a `WP_Error` with code `flavor_agent_apply_slug_conflict` where an array was expected (the `assertIsArray` assertion fails first).

- [ ] **Step 5: Fix the template lane**

In `inc/Apply/TemplateApplyExecutor.php`, replace line 391:

```php
		$slug       = sanitize_key( (string) ( $template->slug ?? '' ) );
```

with:

```php
		// Normalize through core's own post_name normalizer so the post-insert
		// read-back compares like with like. sanitize_key() keeps `--` and edge
		// dashes that sanitize_title() -- which wp_insert_post applies to
		// post_name -- collapses and trims; that divergence reads as a phantom
		// slug collision. sanitize_title() is idempotent on its own output, so
		// this is exactly what core will store, and it is also what
		// reconcile_existing_row() must probe with.
		$slug       = sanitize_title( sanitize_key( (string) ( $template->slug ?? '' ) ) );
```

Then replace line 454:

```php
		$inserted_slug = sanitize_key( (string) ( $inserted->post_name ?? '' ) );
```

with:

```php
		$inserted_slug = (string) ( $inserted->post_name ?? '' );
```

- [ ] **Step 6: Fix the template-part lane**

In `inc/Apply/TemplatePartApplyExecutor.php`, replace line 382:

```php
		$slug       = sanitize_key( (string) ( $part->slug ?? '' ) );
```

with:

```php
		// Normalize through core's own post_name normalizer so the post-insert
		// read-back compares like with like. sanitize_key() keeps `--` and edge
		// dashes that sanitize_title() -- which wp_insert_post applies to
		// post_name -- collapses and trims; that divergence reads as a phantom
		// slug collision. sanitize_title() is idempotent on its own output, so
		// this is exactly what core will store, and it is also what
		// reconcile_existing_row() must probe with.
		$slug       = sanitize_title( sanitize_key( (string) ( $part->slug ?? '' ) ) );
```

Then replace line 443:

```php
		$inserted_slug = sanitize_key( (string) ( $inserted->post_name ?? '' ) );
```

with:

```php
		$inserted_slug = (string) ( $inserted->post_name ?? '' );
```

- [ ] **Step 7: Run the new tests to verify they pass**

```bash
vendor/bin/phpunit --filter test_materialization_accepts_a_slug_core_renormalizes
```

Expected: `OK (2 tests, ...)`.

- [ ] **Step 8: Run the full suite and PHPCS**

```bash
vendor/bin/phpunit && composer lint:php
```

Expected: `OK (1927 tests, ...)` and PHPCS silent. The existing `test_materialization_slug_conflict_removes_the_orphan_and_reports_accurately` and `test_materialization_slug_race_reconciles_against_the_winning_row` must still pass — they force `post_name` from the `wp_insert_post_data` filter, which still runs after the new `sanitize_title` call.

Note the empty-slug edge this also tightens: `sanitize_key( '-' )` returned `'-'` and passed the `'' === $slug` guard, letting core store an empty `post_name`. `sanitize_title( '-' )` returns `''`, so the guard now fails closed with `flavor_agent_apply_write_failed`. That is the correct outcome.

- [ ] **Step 9: Commit**

```bash
git add inc/Apply/TemplateApplyExecutor.php inc/Apply/TemplatePartApplyExecutor.php tests/phpunit/bootstrap.php tests/phpunit/TemplateApplyExecutorTest.php tests/phpunit/TemplatePartApplyExecutorTest.php
git commit -m "fix(apply): normalize materialization slug through sanitize_title

persist() compared a sanitize_key() slug against a post_name that core
stores via sanitize_title(). The normalizers diverge on repeated and edge
dashes, so a legitimate slug (page--wide) read as a collision: the freshly
written row was force-deleted and the apply failed permanently with a false
flavor_agent_apply_slug_conflict. Normalize once through core's own
normalizer and compare the raw stored post_name.

The wp_insert_post stub now applies sanitize_title() to post_name before the
wp_insert_post_data filter, matching core's order, so the suite can see this
class of bug.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Make the `$fresh` identity change load-bearing (P2)

`execute()` now sources `target.*` from the re-gated `$fresh` entity rather than the start-of-execute read — correct, and it matters because that value lands in the activity row and the Ring III attestation subject. But every fixture returns identity-identical objects from both resolves, so reverting `$fresh->` to `$template->` / `$part->` leaves the whole suite green.

**Files:**
- Test: `tests/phpunit/TemplateApplyExecutorTest.php`, `tests/phpunit/TemplatePartApplyExecutorTest.php`

**Interfaces:**
- Consumes: `WordPressTestState::$block_templates_read_hook` — a one-shot callable fired by the `get_block_templates` stub after it builds its result; it nulls itself before invoking, so it runs on `execute()`'s first store read. Established pattern: `test_same_content_materialization_race_updates_existing_row_in_place`.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test for the template lane**

Re-seed the same entity id and content (so the gate-2 freshness check still passes) under a renamed slug/title, with `wp_id > 0` so `persist()` takes the in-place update branch and the write itself is unaffected. Append to `tests/phpunit/TemplateApplyExecutorTest.php`:

```php
	/**
	 * target.* must describe the entity the write actually landed on, because it
	 * is what lands in the activity row and the Ring III attestation subject. The
	 * gate-2 re-resolve is the authority, not the start-of-execute read.
	 * Discriminating: reverting target to $template-> reports the pre-gate slug
	 * and title, and both assertions below fail.
	 */
	public function test_execute_reports_identity_from_the_regated_entity(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_template( $content, 9200 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		WordPressTestState::$block_templates_read_hook = static function () use ( $content ): void {
			WordPressTestState::$block_templates['wp_template'] = [
				(object) [
					'id'      => self::TEMPLATE_REF,
					'wp_id'   => 9200,
					'slug'    => 'home-renamed',
					'title'   => 'Home Renamed',
					'content' => $content,
				],
			];
		};

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'home-renamed', $result['target']['slug'] );
		$this->assertSame( 'Home Renamed', $result['target']['title'] );
	}
```

- [ ] **Step 2: Write the failing test for the template-part lane**

Append to `tests/phpunit/TemplatePartApplyExecutorTest.php`:

```php
	/**
	 * target.* must describe the entity the write actually landed on, because it
	 * is what lands in the activity row and the Ring III attestation subject. The
	 * gate-2 re-resolve is the authority, not the start-of-execute read.
	 * Discriminating: reverting target to $part-> reports the pre-gate slug and
	 * area, and both assertions below fail.
	 */
	public function test_execute_reports_identity_from_the_regated_entity(): void {
		$content = $this->paragraph( 'Body' );
		$this->seed_part( $content, 9201, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		WordPressTestState::$block_templates_read_hook = static function () use ( $content ): void {
			WordPressTestState::$block_templates['wp_template_part'] = [
				(object) [
					'id'      => self::PART_ID,
					'wp_id'   => 9201,
					'slug'    => 'header-renamed',
					'area'    => 'uncategorized',
					'title'   => 'Header Renamed',
					'content' => $content,
				],
			];
		};

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'header-renamed', $result['target']['slug'] );
		$this->assertSame( 'uncategorized', $result['target']['area'] );
	}
```

- [ ] **Step 3: Run both tests to verify they pass, then prove they discriminate**

```bash
vendor/bin/phpunit --filter test_execute_reports_identity_from_the_regated_entity
```

Expected: `OK (2 tests, ...)`.

Now prove the tests are not vacuous. Temporarily revert the identity source in `inc/Apply/TemplateApplyExecutor.php:132-135`, changing every `$fresh->` back to `$template->`, and re-run:

```bash
vendor/bin/phpunit --filter test_execute_reports_identity_from_the_regated_entity
```

Expected: **the template test FAILS** with `'home-renamed'` vs `'home'`. Restore the `$fresh->` version and confirm it passes again. Repeat for `inc/Apply/TemplatePartApplyExecutor.php:122-125` (`$fresh->` → `$part->`), expecting the template-part test to fail with `'header-renamed'` vs `'header'`.

If either test still passes under the revert, it is not discriminating — fix the fixture before continuing.

- [ ] **Step 4: Run the full suite**

```bash
vendor/bin/phpunit
```

Expected: `OK (1929 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add tests/phpunit/TemplateApplyExecutorTest.php tests/phpunit/TemplatePartApplyExecutorTest.php
git commit -m "test(apply): pin target identity to the re-gated entity

execute() reports target.* from the gate-2 re-resolve, which is what lands in
the activity row and the Ring III attestation subject, but every fixture
returned identity-identical objects from both resolves -- reverting the change
left the suite green. Re-seed a renamed slug/title behind the read hook so the
two sources differ and the assertion discriminates.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Make the defensive error arms reachable and tested (P3)

Four new `WP_Error` arms across the two executors are unreachable in the current harness: `wp_insert_post` always writes to `$posts` so the read-back never misses, and `wp_delete_post` has no failure mode so the "row survived deletion" re-check never fires. Both guard behaviors that real WordPress exhibits (`pre_delete_post` short-circuit, object-cache miss), so the right answer is to give the stubs the two seams rather than delete the guards. This task also ports the template-part's reconcile-after-delete test to the template lane, which currently has no coverage for that path.

**Files:**
- Modify: `tests/phpunit/bootstrap.php` (`WordPressTestState` properties + `reset()`, `get_post`, `wp_delete_post`)
- Test: `tests/phpunit/TemplateApplyExecutorTest.php`, `tests/phpunit/TemplatePartApplyExecutorTest.php`

**Interfaces:**
- Produces (consumed by the tests in this task only):
  - `WordPressTestState::$next_get_post_returns_null` — `bool`, default `false`. When `true`, the next `get_post()` call returns `null` and the flag clears itself.
  - `WordPressTestState::$delete_post_short_circuits` — `bool`, default `false`. When `true`, `wp_delete_post()` returns the `WP_Post` without removing it from `WordPressTestState::$posts`, modelling a `pre_delete_post` filter that short-circuits deletion while still returning a post.

- [ ] **Step 1: Add the two seams to `WordPressTestState`**

In `tests/phpunit/bootstrap.php`, immediately after the `public static array $deleted_posts = [];` declaration, add:

```php
		/**
		 * One-shot: the next get_post() returns null. Models an object-cache miss
		 * or a filtered-away read, which is the only way to reach the
		 * post-insert read-back failure arm in the apply executors.
		 */
		public static bool $next_get_post_returns_null = false;

		/**
		 * When true, wp_delete_post() returns the WP_Post without removing it.
		 * Models a pre_delete_post filter that short-circuits deletion while
		 * still returning a post -- the exact case the executors guard against.
		 */
		public static bool $delete_post_short_circuits = false;
```

In `reset()`, immediately after `self::$deleted_posts = [];`, add:

```php
			self::$next_get_post_returns_null  = false;
			self::$delete_post_short_circuits  = false;
```

- [ ] **Step 2: Wire the seams into the `get_post` and `wp_delete_post` stubs**

Replace the `get_post` stub body so the one-shot fires before the lookup:

```php
	if (! function_exists('get_post')) {
		function get_post($post_id = null)
		{
			if (WordPressTestState::$next_get_post_returns_null) {
				WordPressTestState::$next_get_post_returns_null = false;

				return null;
			}

			if ($post_id === null) {
				return null;
			}

			$id = (int) (is_object($post_id) ? ($post_id->ID ?? 0) : $post_id);

			return WordPressTestState::$posts[$id] ?? null;
		}
	}
```

Replace the `wp_delete_post` stub body so the short-circuit returns truthy without deleting:

```php
	if (! function_exists('wp_delete_post')) {
		function wp_delete_post(int $post_id, bool $force_delete = false)
		{
			unset($force_delete);

			if (! isset(WordPressTestState::$posts[$post_id])) {
				return false;
			}

			$post = WordPressTestState::$posts[$post_id];

			// A pre_delete_post filter can short-circuit deletion and still return
			// a WP_Post. Callers that trust the return value strand the row.
			if (WordPressTestState::$delete_post_short_circuits) {
				return $post;
			}

			unset(WordPressTestState::$posts[$post_id]);
			WordPressTestState::$deleted_posts[] = $post_id;

			return $post;
		}
	}
```

- [ ] **Step 3: Write the read-back-failure tests**

Append to `tests/phpunit/TemplateApplyExecutorTest.php`:

```php
	/**
	 * A failed post-insert read-back is not a slug collision. Falling into the
	 * collision arm would delete a row that was almost certainly written
	 * correctly and report a cause known to be false, so the executor must fail
	 * closed and LEAVE the row for an operator to reconcile.
	 */
	public function test_materialization_read_back_failure_leaves_the_row_and_reports_accurately(): void {
		$this->seed_template( $this->paragraph( 'Anchor' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template' === ( $data['post_type'] ?? '' ) ) {
					WordPressTestState::$next_get_post_returns_null = true;
				}

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A read-back failure must not delete the row.' );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
	}
```

Append to `tests/phpunit/TemplatePartApplyExecutorTest.php`:

```php
	/**
	 * A failed post-insert read-back is not a slug collision. Falling into the
	 * collision arm would delete a row that was almost certainly written
	 * correctly and report a cause known to be false, so the executor must fail
	 * closed and LEAVE the row for an operator to reconcile.
	 */
	public function test_materialization_read_back_failure_leaves_the_row_and_reports_accurately(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template_part' === ( $data['post_type'] ?? '' ) ) {
					WordPressTestState::$next_get_post_returns_null = true;
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_post_write_read_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$deleted_posts, 'A read-back failure must not delete the row.' );
		$this->assertCount( 1, WordPressTestState::$inserted_posts );
	}
```

- [ ] **Step 4: Write the delete-failure tests**

Append to `tests/phpunit/TemplateApplyExecutorTest.php`:

```php
	/**
	 * wp_delete_post can return a WP_Post while a pre_delete_post filter
	 * short-circuits the actual deletion. Trusting the return value would strand
	 * the duplicate row AND then update the winning row too, so the executor
	 * must confirm the row is gone and fail closed when it is not.
	 */
	public function test_materialization_slug_conflict_fails_closed_when_the_orphan_cannot_be_removed(): void {
		$this->seed_template( $this->paragraph( 'Anchor' ), 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		WordPressTestState::$delete_post_short_circuits = true;

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template' === ( $data['post_type'] ?? '' ) ) {
					$data['post_name'] = 'home-2';
				}

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Failing to remove the orphan must not also update a winning row.' );
	}
```

Append to `tests/phpunit/TemplatePartApplyExecutorTest.php`:

```php
	/**
	 * wp_delete_post can return a WP_Post while a pre_delete_post filter
	 * short-circuits the actual deletion. Trusting the return value would strand
	 * the duplicate row AND then update the winning row too, so the executor
	 * must confirm the row is gone and fail closed when it is not.
	 */
	public function test_materialization_slug_conflict_fails_closed_when_the_orphan_cannot_be_removed(): void {
		$this->seed_part( $this->paragraph( 'Anchor' ), 0, 'header', 'header' );
		$this->register_pattern( 'fa-test/tail', $this->paragraph( 'TailPat' ) );

		WordPressTestState::$delete_post_short_circuits = true;

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( 'wp_template_part' === ( $data['post_type'] ?? '' ) ) {
					$data['post_name'] = 'header-2';
				}

				return $data;
			},
			10,
			4
		);

		$result = TemplatePartApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'fa-test/tail',
						'placement'   => 'end',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_write_failed', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$updated_posts, 'Failing to remove the orphan must not also update a winning row.' );
	}
```

- [ ] **Step 5: Port the reconcile-after-delete test to the template lane**

The template lane has no equivalent of `TemplatePartApplyExecutorTest::test_materialization_slug_race_reconciles_against_the_winning_row`. Append to `tests/phpunit/TemplateApplyExecutorTest.php`:

```php
	/**
	 * When the slug suffix WAS caused by a genuine concurrent materialization,
	 * the winning row becomes visible only after our insert. The post-insert
	 * re-probe must drop our orphan and reconcile against the winner rather than
	 * failing the operator out on a race the guard can safely resolve.
	 */
	public function test_materialization_slug_race_reconciles_against_the_winning_row(): void {
		$content = $this->paragraph( 'Anchor' );
		$this->seed_template( $content, 0 );
		$this->register_pattern( 'tt5/hero', $this->paragraph( 'Hero' ) );

		// Simulate the winner committing inside our insert: force the suffix and
		// publish the concurrent row in the same step, so only the post-insert
		// re-probe can see it.
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ) use ( $content ): array {
				if ( 'wp_template' !== ( $data['post_type'] ?? '' ) ) {
					return $data;
				}

				$data['post_name'] = 'home-2';

				WordPressTestState::$block_templates['wp_template'][] = (object) [
					'id'      => 'twentytwentyfive//home-winner',
					'wp_id'   => 9300,
					'slug'    => 'home',
					'title'   => 'Home',
					'content' => $content,
				];

				WordPressTestState::$posts[9300] = new \WP_Post(
					[
						'ID'           => 9300,
						'post_type'    => 'wp_template',
						'post_content' => $content,
					]
				);

				return $data;
			},
			10,
			4
		);

		$result = \FlavorAgent\Apply\TemplateApplyExecutor::execute(
			$this->entry(
				[
					[
						'type'        => 'insert_pattern',
						'patternName' => 'tt5/hero',
						'placement'   => 'start',
					],
				]
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, WordPressTestState::$deleted_posts, 'The suffixed orphan must be removed.' );
		$this->assertCount( 1, WordPressTestState::$updated_posts );
		$this->assertSame( 9300, WordPressTestState::$updated_posts[0]['ID'], 'The winning row must be updated in place.' );
	}
```

- [ ] **Step 6: Run the new tests**

```bash
vendor/bin/phpunit --filter 'read_back_failure|cannot_be_removed|slug_race_reconciles'
```

Expected: `OK (5 tests, ...)` — two read-back, two delete-failure, one newly ported template-lane race.

- [ ] **Step 7: Run the full suite and PHPCS**

```bash
vendor/bin/phpunit && composer lint:php
```

Expected: `OK (1934 tests, ...)`, PHPCS silent. Confirm `WordPressTestState::reset()` clears both new flags — if a later test in the same run fails unexpectedly, a leaked flag is the first thing to check.

- [ ] **Step 8: Commit**

```bash
git add tests/phpunit/bootstrap.php tests/phpunit/TemplateApplyExecutorTest.php tests/phpunit/TemplatePartApplyExecutorTest.php
git commit -m "test(apply): make the defensive materialization arms reachable

Four WP_Error arms guarding a failed post-insert read-back and a failed
orphan deletion were unreachable in the harness, so neither the error codes
nor the fail-closed decisions were pinned. Add two stub seams modelling a
get_post miss and a pre_delete_post short-circuit, and cover all four. Also
port the reconcile-after-delete race test to the template lane, which had
none.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Fix the doc-freshness ability-count guard (P2)

`check_absent` searches with `rg -F` — fixed-string and case-sensitive. The new eight-pattern loop guards `'{29..32} abilities'` and `'{29..32} ability contracts'`, but `README.md:77` writes **`35 WordPress Ability contracts`** — capital `A`, extra word. No pattern can match that shape, so the guard cannot catch a recurrence of the exact drift its own comment cites. Replayed against pre-fix `HEAD`, seven of the eight patterns miss and README's stale `31 WordPress Ability contracts` is caught by zero count guards.

Also fold in: the `'five preview siblings'` guard matches nothing in any revision (dead), and `docs/releases/v0.1.0-proof-assets.md` is a new release doc absent from `live_docs`.

**Files:**
- Modify: `scripts/check-doc-freshness.sh`

**Interfaces:**
- Produces: `check_absent_regex <description> <pattern> <files...>` — same contract and failure accounting as `check_absent`, but the pattern is a regex (no `-F`).

- [ ] **Step 1: Add the regex helper**

In `scripts/check-doc-freshness.sh`, immediately after the closing `}` of `check_absent()`, add:

```bash
# Regex search -- pattern must NOT appear in the given files.
check_absent_regex() {
	local description="$1" pattern="$2"
	shift 2
	(( ++total ))
	local output rc=0
	output=$(rg -n --no-heading --color never -- "$pattern" "$@" 2>&1) || rc=$?
	case "$rc" in
		0)
			echo "Doc freshness check failed: ${description}" >&2
			echo "$output" >&2
			(( ++failed ))
			;;
		1) ;;
		*)
			echo "Doc freshness check could not run: ${description} (rg exit=${rc})" >&2
			echo "$output" >&2
			(( ++failed ))
			;;
	esac
}
```

- [ ] **Step 2: Replace the eight-pattern loop with one regex guard**

Replace this block:

```bash
# Ability-count drift guards. README.md and docs/releases/v0.1.0.md sat outside
# live_docs through four consecutive count bumps, which is how "31"/"32" survived
# to the release artifacts. Guard every superseded total, not just the last one.
for stale_count in '29 abilities' '30 abilities' '31 abilities' '32 abilities' \
	'29 ability contracts' '30 ability contracts' '31 ability contracts' '32 ability contracts'; do
	check_absent \
		"superseded ability count \"${stale_count}\" still appears in live docs (current: 35)" \
		"${stale_count}" \
		"${live_docs[@]}"
done
```

with:

```bash
# Ability-count drift guard. README.md and docs/releases/v0.1.0.md sat outside
# live_docs through four consecutive count bumps (29 -> 30 -> 31 -> 32 -> 35),
# which is how "31"/"32" survived to the release artifacts. A fixed-string list
# could not catch it: README phrases the total as "31 WordPress Ability
# contracts" (capital A, extra word), which matched none of the guarded
# literals. Match every superseded total across every phrasing instead.
check_absent_regex \
	'superseded ability count still appears in live docs (current: 35)' \
	'\b(29|30|31|32|33|34) +(WordPress +)?[Aa]bilit(y|ies)\b' \
	"${live_docs[@]}"
```

This regex was validated against ripgrep before this plan was written: it matches `31 WordPress Ability contracts`, `32 ability contracts`, `29 abilities`, and `34 WordPress Abilities`; it does not match `35 WordPress Ability contracts` or `35 abilities`; and it produces zero hits across the current `live_docs` set. Steps 5 and 6 re-prove both halves in place.

- [ ] **Step 3: Drop the dead preview-sibling guard**

`'five preview siblings'` matches nothing in any revision. Delete this block, leaving the `'five signature-only'` guard directly above it intact:

```bash
check_absent \
	'superseded preview-sibling count still appears in live docs (current: six)' \
	'five preview siblings' \
	"${live_docs[@]}"
```

- [ ] **Step 4: Add the new release doc to `live_docs`**

In the `live_docs` array, immediately after the `docs/releases/v0.1.0.md` entry, add:

```bash
	"${repo_root}/docs/releases/v0.1.0-proof-assets.md"
```

- [ ] **Step 5: Run the guard and confirm it still passes**

```bash
bash scripts/check-doc-freshness.sh; echo "EXIT=$?"
```

Expected: no output, `EXIT=0`. A non-zero exit means the broadened regex or the newly scanned doc tripped a guard — read the reported `file:line` and fix the doc, not the guard.

- [ ] **Step 6: Prove the guard now catches what it missed**

`README.md` has uncommitted changes in this working tree, so **do not** use `git checkout`/`git restore` to undo this probe — that reverts to `HEAD` and destroys them. Record the line count first, append the probe line, then delete exactly that line.

```bash
wc -l < README.md                                                     # note this number
printf '\nThe runtime defines 31 WordPress Ability contracts.\n' >> README.md
bash scripts/check-doc-freshness.sh; echo "EXIT=$?"
```

Expected: `Doc freshness check failed: superseded ability count still appears in live docs (current: 35)` with the README line quoted, and `EXIT=1`. If it exits `0`, the regex is wrong — fix it before continuing.

Now remove the two appended lines (the blank line and the probe line):

```bash
sed -i -e '$ d' -e '$ d' README.md
wc -l < README.md                                                     # must equal the number from above
bash scripts/check-doc-freshness.sh; echo "EXIT=$?"
git diff --stat README.md
```

Expected: the line count matches, `EXIT=0`, and `git diff --stat README.md` reports the same insertion/deletion counts it did before this step.

- [ ] **Step 7: Commit**

```bash
git add scripts/check-doc-freshness.sh
git commit -m "fix(docs): make the ability-count guard match README's phrasing

check_absent is fixed-string and case-sensitive, so the eight guarded
literals ('31 abilities', '31 ability contracts', ...) could not match
README's actual wording, '31 WordPress Ability contracts' -- the exact drift
the guard's comment says it exists to prevent. Replace the loop with a regex
guard covering every superseded total and phrasing, drop a preview-sibling
guard that matches nothing in any revision, and scan the new proof-assets
release doc.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Cleanup — dead code, docblock, RC pin, vacuous comment (P3)

Four independent small corrections, grouped because none carries its own test cycle.

**Files:**
- Modify: `inc/Apply/TemplateApplyExecutor.php:345-351`
- Modify: `inc/Apply/TemplatePartApplyExecutor.php:294-302`, `:336-342`
- Modify: `blueprint.json:4`
- Modify: `tests/phpunit/TemplatePartApplyExecutorTest.php:697-701`

- [ ] **Step 1: Remove the dead `op_path()` wrappers**

`TemplateApplyExecutor::op_path()` lost its only caller when `restore_requested_expected_targets` moved to `StructuralOperationsApplier`. `TemplatePartApplyExecutor::op_path()` was already unreferenced before this diff. Neither is called anywhere — confirmed by `grep -n "op_path" inc/Apply/TemplateApplyExecutor.php inc/Apply/TemplatePartApplyExecutor.php`, which returns only the definition and its own body.

Delete this block from **both** files:

```php
	/**
	 * @param array<string, mixed> $operation
	 * @return int[]|null
	 */
	private static function op_path( array $operation ): ?array {
		return StructuralOperationsApplier::op_path( $operation );
	}
```

Leave the neighbouring `apply_operations()` wrapper alone — its docblock explains it is a deliberate per-executor test seam.

- [ ] **Step 2: Fix the `assert_part_unchanged` return docblock**

`inc/Apply/TemplatePartApplyExecutor.php` returns `WP_Error` from this method as well as the fresh entity, but the docblock claims only `object`. The template lane's counterpart already documents both. Replace:

```php
	 * @return object
	 */
	private static function assert_part_unchanged( string $ref, string $expected_hash ): object {
```

with:

```php
	 * @return object|\WP_Error
	 */
	private static function assert_part_unchanged( string $ref, string $expected_hash ): object {
```

- [ ] **Step 3: Move the Playground blueprint off the release candidate**

`blueprint.json` is the last file in the repo pinned to a 7.0 release candidate; everything else moved to stable `7.0.0`. Replace:

```json
	"wp": "7.0-RC1",
```

with:

```json
	"wp": "7.0",
```

- [ ] **Step 4: Correct the overstated test comment**

`tests/phpunit/TemplatePartApplyExecutorTest.php` claims the winner's "deliberately non-canonical id" makes the assertion discriminate against reporting the reconciled row's identity. That path cannot exist: `persist()` returns `int|\WP_Error`, so a reconciled row's `id` can never reach `target`. Replace the sentence making that claim with:

```php
	 * The winner is seeded under a non-canonical id so the row is unambiguously
	 * distinct from the entity under test; target identity always comes from the
	 * re-gated entity, since persist() returns only a post id.
```

- [ ] **Step 5: Verify**

```bash
vendor/bin/phpunit && composer lint:php && node -e "JSON.parse(require('fs').readFileSync('blueprint.json','utf8')); console.log('blueprint.json OK')"
```

Expected: `OK (1934 tests, ...)`, PHPCS silent, `blueprint.json OK`. If PHPUnit reports an undefined-method error, something did reference `op_path()` via reflection — restore it in that file and note why.

- [ ] **Step 6: Commit**

```bash
git add inc/Apply/TemplateApplyExecutor.php inc/Apply/TemplatePartApplyExecutor.php blueprint.json tests/phpunit/TemplatePartApplyExecutorTest.php
git commit -m "chore(apply): drop dead op_path wrappers, fix docblock and RC pin

op_path() lost its only caller when restore_requested_expected_targets moved
to StructuralOperationsApplier; the template-part copy was already unused.
assert_part_unchanged also returns WP_Error, matching the template lane's
docblock. blueprint.json was the last 7.0-RC pin in the repo. One test comment
claimed a discriminating property that persist()'s int|WP_Error return makes
impossible.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Close the CI coverage gaps (open questions 2 and 3)

The workflow is well-built — SHA-pinned actions, least-privilege `contents: read`, correct `--skip=lint-plugin --skip-e2e --strict` invocation, ripgrep installed for `check-docs`. Two gaps remain: the Docker-backed WP 7.0 Site Editor harness never runs in CI (so the `wordpress:7.0.0-php8.2-apache` repin is unverified by automation, and `README.md:71` already flags that the harnesses have not been re-run), and Composer runs cold in both jobs.

**Files:**
- Modify: `.github/workflows/verify.yml`

- [ ] **Step 1: Verify the four pinned action SHAs resolve to their commented tags**

The file currently pins these four, each with a trailing version comment:

| Action | Pinned SHA | Comment |
|---|---|---|
| `actions/checkout` | `93cb6efe18208431cddfb8368fd83d5badbf9bfd` | `v5.0.1` |
| `actions/setup-node` | `a0853c24544627f65ddf259abe73b1d18a591444` | `v5.0.0` |
| `shivammathur/setup-php` | `f3e473d116dcccaddc5834248c87452386958240` | `2.37.2` |
| `actions/upload-artifact` | `ea165f8d65b6e75b540449e92b4886f43607fa02` | `v4.6.2` |

```bash
gh api repos/actions/checkout/commits/v5.0.1 --jq .sha
gh api repos/actions/setup-node/commits/v5.0.0 --jq .sha
gh api repos/shivammathur/setup-php/commits/2.37.2 --jq .sha
gh api repos/actions/upload-artifact/commits/v4.6.2 --jq .sha
```

Each printed SHA must equal the pinned value in the table. `commits/<tag>` dereferences annotated tags, so it returns the commit SHA directly. **If any SHA does not match its comment, stop and report it — a mislabelled pin is a supply-chain issue, not a formatting nit.**

- [ ] **Step 2: Resolve a SHA for `actions/cache`**

```bash
gh api repos/actions/cache/commits/v4.2.3 --jq .sha
```

Record the printed value; Step 3 substitutes it for `<CACHE_SHA>`. If `v4.2.3` no longer exists, list current tags with `gh api repos/actions/cache/tags --jq '.[].name' | head` and pick the newest `v4.x`, adjusting the trailing comment to match.

- [ ] **Step 3: Cache Composer downloads in both existing jobs**

In **both** the `verify` and `e2e-playground` jobs, immediately after the `shivammathur/setup-php` step, add (substituting the SHA from Step 2):

```yaml
      - name: Cache Composer packages
        uses: actions/cache@<CACHE_SHA> # v4.2.3
        with:
          path: ~/.cache/composer/files
          key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-
```

- [ ] **Step 4: Add the WP 7.0 E2E job**

Append to the `jobs:` map. GitHub-hosted Ubuntu runners ship Docker, and `wordpress:7.0.0-php8.2-apache` is public, so no credentials are needed. It is marked `continue-on-error: true` on its first landing so a harness that has never run in CI cannot block merges before anyone has seen it go green.

```yaml
  e2e-wp70:
    name: E2E (WordPress 7.0 Site Editor)
    runs-on: ubuntu-latest
    # First CI landing for a harness that has only ever run locally. Flip this
    # to false once it has gone green on master, so it becomes a real gate.
    continue-on-error: true
    steps:
      - uses: actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd # v5.0.1
        with:
          persist-credentials: false

      - uses: actions/setup-node@a0853c24544627f65ddf259abe73b1d18a591444 # v5.0.0
        with:
          node-version-file: .nvmrc
          cache: npm

      - name: Install JS dependencies
        run: npm ci

      - name: Build
        run: npm run build

      - name: Provision the WP 7.0 harness
        run: npm run wp:e2e:wp70:bootstrap

      - name: Run the WP 7.0 Site Editor suite
        run: npm run test:e2e:wp70

      - name: Tear down the WP 7.0 harness
        if: always()
        run: npm run wp:e2e:wp70:teardown

      - name: Upload Playwright artifacts
        if: always()
        uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4.6.2
        with:
          name: e2e-wp70-artifacts
          path: |
            output/
            test-results/
          if-no-files-found: ignore
```

- [ ] **Step 5: Validate the workflow parses**

```bash
node -e "const y=require('js-yaml');const d=y.load(require('fs').readFileSync('.github/workflows/verify.yml','utf8'));console.log(Object.keys(d.jobs).join(', '))"
```

Expected: `verify, e2e-playground, e2e-wp70`. If `js-yaml` is not resolvable, run `npx --yes js-yaml .github/workflows/verify.yml > /dev/null && echo "YAML OK"`.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/verify.yml
git commit -m "ci: add the WP 7.0 E2E job and cache Composer

The Docker-backed Site Editor harness had no CI coverage, leaving the
wordpress:7.0.0-php8.2-apache repin verified only by local runs. Land it
non-blocking so its first CI execution cannot gate merges, and cache Composer
downloads in both existing jobs.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Run the cross-surface validation gates

This change set touches two recommendation surfaces and shared apply contracts, so `docs/reference/cross-surface-validation-gates.md` makes these additive release stops. The P1 fix lives in the first-materialization path, which only the `wp70` harness exercises end to end.

**Files:** none modified unless a gate fails.

- [ ] **Step 1: Run the aggregate verifier**

```bash
node scripts/verify.js --skip-e2e --skip=lint-plugin
cat output/verify/summary.json
```

Expected: final stdout line `VERIFY_RESULT={"status":"pass",...}`. In `summary.json`, confirm `status` is `pass` and every step's `exitCode` is `0`. A `status` of `incomplete` means a required tool was missing — record which, do not ignore it.

- [ ] **Step 2: Run the Playground E2E suite**

```bash
npm run test:e2e:playground
```

Expected: all specs pass. This covers post-editor / block / pattern / navigation, none of which this change set touches — a failure here is pre-existing and should be recorded as such, not fixed inside this plan.

- [ ] **Step 3: Run the WP 7.0 Site Editor suite**

```bash
npm run wp:e2e:wp70:bootstrap && npm run test:e2e:wp70; npm run wp:e2e:wp70:teardown
```

Expected: all specs pass. **This is the gate that matters for Task 1** — template and template-part apply, including first materialization of a theme-file entity. If Docker is unavailable, record an explicit blocker or waiver per the gates doc; do not silently skip.

- [ ] **Step 4: Re-run the doc guard**

```bash
npm run check:docs; echo "EXIT=$?"
```

Expected: `EXIT=0`.

- [ ] **Step 5: Record the evidence**

Append a dated entry to the verification log in `STATUS.md` naming: the four commands above, their pass/fail, and any harness recorded as blocked or waived. Quote the `VERIFY_RESULT` line verbatim. Do not write "all gates pass" unless every command above actually returned success — if one was skipped, say it was skipped.

- [ ] **Step 6: Commit**

```bash
git add STATUS.md
git commit -m "docs: record cross-surface validation evidence for the apply fixes

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Findings Coverage

| Severity | Finding | Task |
|---|---|---|
| P1 | `sanitize_key`/`sanitize_title` mismatch deletes a correct row and fails with a false conflict | 1 |
| P2 | Ability-count guard cannot match README's phrasing | 4 |
| P2 | `$fresh` identity change is untested (revert leaves suite green) | 2 |
| P3 | Read-back-failure and delete-failure arms unreachable in the harness | 3 |
| P3 | `assert_part_unchanged` docblock omits `\WP_Error` | 5 |
| P3 | Dead `op_path()` wrappers in both executors | 5 |
| P3 | `blueprint.json` still pinned to `7.0-RC1` | 5 |
| P3 | Template lane missing the reconcile-after-delete test | 3 |
| P3 | Vacuous discriminating-property claim in a test comment | 5 |
| P3 | `v0.1.0-proof-assets.md` absent from `live_docs` | 4 |
| OQ1 | Are the unreachable arms deliberate defense-only? | 3 (resolved by making them reachable) |
| OQ2 | No `e2e-wp70` CI job | 6 |
| OQ3 | Action SHA ↔ tag mapping unverified | 6 |
| OQ4 | `five preview siblings` dead guard | 4 |

## Deliberately Not Changed

- **`reconcile_existing_row`'s `get_block_templates` probe takes no explicit theme filter.** Core scopes it by a `wp_theme` tax query on `get_stylesheet()` (`wp-includes/block-template-utils.php`), so the probe is already active-theme scoped. Adding a redundant filter would imply the default is untrusted.
- **The publish-only probe.** `get_block_templates` forces `post_status => 'publish'` when `wp_id` is unset, so it cannot see the non-published rows core's uniquifier counts. The executors already document this and report `flavor_agent_apply_slug_conflict` rather than phantom concurrency — that is the correct call, not a gap.
- **`restore_requested_expected_targets` moving to `StructuralOperationsApplier`.** Verified correct and load-bearing: `StructuralOperationsGrammar::validate_operations` rebuilds `expectedTarget` from the live tree, so without the restore the drift gate would be a tautology. Both new lanes already have discriminating tests.
- **The JS changes** (`activity-log-utils.js` i18n and `baselineRow`, and the `wp_template_part:` scope-key test fix). All three verified correct against runtime; the i18n change additionally repairs a latent bug where the old code compared the *translated label* to `'start'`.
- **`concurrency.cancel-in-progress: true` applying to `push: master`.** A newer push can cancel a master run. Real, but a deliberate cost/latency tradeoff that belongs to the repo owner, not this remediation.
