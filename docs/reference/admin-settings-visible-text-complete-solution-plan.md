# Admin Settings Visible Text Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify `Settings > Flavor Agent` visible copy, move conceptual and diagnostic detail into contextual Help, and make every JavaScript-driven settings string translatable without changing settings behavior.

**Architecture:** Keep the existing settings information architecture and ownership boundaries: `Settings > Connectors` owns text generation, while `Settings > Flavor Agent` owns setup status, embeddings, pattern storage/sync, Developer Docs grounding, Guidelines, and experimental feature toggles. The implementation is a presentation-layer cleanup across PHP-rendered settings copy, contextual Help, JavaScript interaction copy, and string-pinning tests; it must not change option names, provider precedence, validation, storage, migration behavior, or generated assets by hand.

**Tech Stack:** WordPress plugin PHP, WordPress Settings API, WordPress contextual Help, `@wordpress/i18n`, Jest via `@wordpress/scripts`, PHPUnit, Playwright, `wp-scripts build`.

---

## Source Review

Primary review artifact: `docs/reference/admin-settings-visible-text-review.md`

Review prompt artifact: `docs/reference/admin-settings-visible-text-review-prompt.md`

## Findings Addressed

1. `src/admin/settings-page-controller.js` writes visible settings strings as raw English instead of using `@wordpress/i18n`.
2. Developer Docs renders implementation source and diagnostics inline on the main settings page.
3. `Settings > Connectors` ownership guidance is repeated in the hero, section summaries, body copy, warnings, and Help.
4. Guidelines migration copy is too prominent for ordinary settings use.
5. Pattern Storage copy repeats the "not another AI model" correction in too many places.
6. Several field descriptions expose implementation, beta, or infrastructure detail that can be shorter.
7. PHP, JS, and E2E tests pin the current verbose strings rather than the simplified copy contract.

## Non-Goals

- Do not change option names, saved values, validation semantics, provider precedence, connector pin preservation, Guidelines migration behavior, Activity Log behavior, pattern sync behavior, or Cloudflare AI Search runtime behavior.
- Do not remove the existing section order: `1. AI Model`, `2. Embedding Model`, `3. Patterns`, `4. Developer Docs`, `5. Guidelines`, `6. Experimental Features`.
- Do not hand-edit `build/admin.js`, `build/admin.asset.php`, or anything under `dist/`; regenerate build artifacts with `npm run build` after source changes.
- Do not turn contextual Help into a copy dump. Help should absorb concepts and troubleshooting notes in concise, scannable text.

## File Responsibility Map

- `inc/Admin/Settings/Page.php`: main settings page copy, hero labels, section bodies, Developer Docs inline presentation, Guidelines core bridge notice, pattern sync prerequisite messages, and Experimental Features section callback.
- `inc/Admin/Settings/State.php`: section summaries, status badges, and warning notices tied to page state.
- `inc/Admin/Settings/Help.php`: contextual Help tabs and sidebar quick links.
- `inc/Admin/Settings/Registrar.php`: Settings API field labels, choices, and field descriptions.
- `inc/Admin/Settings/Fields.php`: shared field rendering, including saved secret helper text and `aria-describedby` wiring.
- `src/admin/settings-page-controller.js`: JavaScript-driven labels, notices, confirmation text, sync status text, import/export errors, and live-region text.
- `src/admin/__tests__/settings-page-controller.test.js`: JS interaction and i18n contract tests.
- `tests/phpunit/SettingsTest.php`: PHP-rendered settings page copy assertions.
- `tests/e2e/flavor-agent.settings.spec.js`: browser-level settings IA, Help placement, and accordion behavior.
- `build/admin.js` and `build/admin.asset.php`: generated admin bundle and dependency manifest, updated only by `npm run build`.

## Copy Contract

Use this table as the target copy for implementation and tests.

| Surface | Target visible copy |
| --- | --- |
| Hero wordmark | `Flavor Agent` |
| Hero title | `Flavor Agent Settings` |
| Hero body | `Configure setup, storage, docs, and guidance.` |
| Hero actions | `Open Activity Log`, `Open Connectors` |
| AI Model summary | `Text-generation provider status.` |
| AI Model missing status | `Text generation is not configured. Open Connectors to choose a provider.` |
| AI Model body | `Text generation is managed in Connectors.` |
| AI Model meta | `Current: %s.` |
| Embedding Model summary | `Embedding credentials for semantic features.` |
| Embedding Model missing status | `Embedding model is not configured.` |
| Embedding subsection | `Cloudflare Workers AI` |
| Embedding subsection description | `Used for embeddings.` |
| Patterns summary | `Storage and sync for pattern recommendations.` |
| Patterns body | `Choose where the pattern catalog is stored.` |
| Pattern Storage field help | `Choose where the pattern catalog is stored.` |
| Qdrant subsection description | `Vector storage for the pattern index.` |
| Cloudflare AI Search pattern subsection description | `Managed index for site pattern content.` |
| Pattern sync prerequisite suffix | `before syncing.` |
| Developer Docs summary | `Built-in developer.wordpress.org grounding.` |
| Developer Docs body | `Built-in developer.wordpress.org grounding is active.` |
| Developer Docs healthy diagnostics | No inline diagnostics panel. |
| Developer Docs warning state | Short warning only; troubleshooting concepts live in Help. |
| Guidelines summary | `Site and block guidance.` |
| Core Guidelines bridge notice | `Core Guidelines connected.` |
| Block Guidelines empty state | `No block guidelines yet.` |
| Experimental Features summary | `Beta feature toggles.` |
| Structural actions label | `Enable structural block actions` |
| Structural actions description | `Adds review-first insert and replace actions for the selected block.` |
| Saved secret helper | `Saved value hidden. Leave blank to keep it, or enter a replacement.` |

---

## Task 1: Lock The Simplified PHP And E2E Copy Contract In Tests

**Files:**
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `tests/e2e/flavor-agent.settings.spec.js`

- [ ] **Step 1: Update PHP settings-page assertions**

In `tests/phpunit/SettingsTest.php`, update the existing settings-rendering assertions so the tests pin the simplified contract rather than verbose paragraphs.

Use these expected strings in the relevant existing tests:

```php
$this->assertStringContainsString( 'Flavor Agent Settings', $output );
$this->assertStringContainsString( 'Configure setup, storage, docs, and guidance.', $output );
$this->assertStringContainsString( 'Open Activity Log', $output );
$this->assertStringContainsString( 'Open Connectors', $output );
$this->assertStringContainsString( '1. AI Model', $output );
$this->assertStringContainsString( 'Text-generation provider status.', $output );
$this->assertStringContainsString( '2. Embedding Model', $output );
$this->assertStringContainsString( 'Embedding credentials for semantic features.', $output );
$this->assertStringContainsString( 'Cloudflare Workers AI', $output );
$this->assertStringContainsString( 'Used for embeddings.', $output );
$this->assertStringContainsString( '3. Patterns', $output );
$this->assertStringContainsString( 'Storage and sync for pattern recommendations.', $output );
$this->assertStringContainsString( 'Choose where the pattern catalog is stored.', $output );
$this->assertStringContainsString( '4. Developer Docs', $output );
$this->assertStringContainsString( 'Built-in developer.wordpress.org grounding is active.', $output );
$this->assertStringContainsString( '5. Guidelines', $output );
$this->assertStringContainsString( 'Site and block guidance.', $output );
$this->assertStringContainsString( 'Core Guidelines connected.', $output );
$this->assertStringContainsString( '6. Experimental Features', $output );
$this->assertStringContainsString( 'Beta feature toggles.', $output );
```

Add these negative assertions to the existing tests that currently cover Developer Docs and Guidelines migration copy:

```php
$this->assertStringNotContainsString( 'Developer Docs Source', $output );
$this->assertStringNotContainsString( 'Built-in public Cloudflare AI Search endpoint', $output );
$this->assertStringNotContainsString( 'Instance:', $output );
$this->assertStringNotContainsString( 'Runtime Grounding', $output );
$this->assertStringNotContainsString( 'Developer Docs Prewarm', $output );
$this->assertStringNotContainsString( 'legacy migration tooling', $output );
$this->assertStringNotContainsString( 'JSON import/export, and rollback support', $output );
```

- [ ] **Step 2: Run PHP tests to confirm they fail before implementation**

Run:

```bash
composer run test:php -- --filter SettingsTest
```

Expected before implementation: failures identify the old verbose strings still rendered by `Page.php`, `State.php`, `Registrar.php`, and `Help.php`.

- [ ] **Step 3: Update E2E settings IA assertions**

In `tests/e2e/flavor-agent.settings.spec.js`, keep the accordion behavior and Help-tab coverage, but replace verbose text assertions with the simplified contract.

Use these browser assertions in the first test:

```js
await expect( page.locator( '.flavor-agent-admin-hero__copy' ) ).toHaveText(
	'Configure setup, storage, docs, and guidance.'
);
await expect( chatSection.locator( sectionSummarySelector ) ).toContainText(
	'Text-generation provider status.'
);
await expect(
	embeddingSection.locator( sectionSummarySelector )
).toContainText( 'Embedding credentials for semantic features.' );
await expect(
	patternSection.locator( sectionSummarySelector )
).toContainText( 'Storage and sync for pattern recommendations.' );
await expect( docsSection.locator( sectionSummarySelector ) ).toContainText(
	'Built-in developer.wordpress.org grounding.'
);
await expect(
	guidelinesSection.locator( sectionSummarySelector )
).toContainText( 'Site and block guidance.' );
await expect(
	experimentsSection.locator( sectionSummarySelector )
).toContainText( 'Beta feature toggles.' );
await expect( docsSection ).toContainText(
	'Built-in developer.wordpress.org grounding is active.'
);
await expect( docsSection ).not.toContainText( 'Developer Docs Source' );
await expect( docsSection ).not.toContainText(
	'Built-in public Cloudflare AI Search endpoint'
);
await expect( docsSection ).not.toContainText( 'Runtime Grounding' );
await expect( docsSection ).not.toContainText( 'Developer Docs Prewarm' );
```

Update Help assertions to expect the moved concepts there:

```js
await expect( overviewPanel ).toContainText(
	'Use Connectors for text generation. Flavor Agent shows the active chat path here.'
);
await expect(
	page.locator( '#tab-panel-flavor-agent-configuration' )
).toContainText(
	'Pattern Storage chooses where the pattern catalog is indexed.'
);
await expect(
	page.locator( '#tab-panel-flavor-agent-troubleshooting' )
).toContainText(
	'Developer Docs use the built-in developer.wordpress.org grounding path.'
);
await expect(
	page.locator( '#tab-panel-flavor-agent-troubleshooting' )
).toContainText(
	'When core Guidelines are available, Flavor Agent reads them first.'
);
await expect(
	page.locator( '#tab-panel-flavor-agent-troubleshooting' )
).toContainText(
	'Structural block actions are beta controls.'
);
```

- [ ] **Step 4: Run the E2E settings test to confirm it fails before implementation**

Run the WP 7.0 settings harness when the local stack has the required companion plugins:

```bash
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.settings.spec.js
```

Expected before implementation: failures point to old visible copy and inline Developer Docs diagnostics. If the harness is blocked by missing local plugins, record the exact activation/setup error and continue with PHPUnit/Jest verification until the stack is available.

---

## Task 2: Simplify PHP-Rendered Settings Copy And Inline Diagnostics

**Files:**
- Modify: `inc/Admin/Settings/Page.php`
- Modify: `inc/Admin/Settings/State.php`
- Modify: `inc/Admin/Settings/Registrar.php`
- Modify: `inc/Admin/Settings/Fields.php`
- Test: `tests/phpunit/SettingsTest.php`

- [ ] **Step 1: Shorten hero labels and body**

In `inc/Admin/Settings/Page.php`, change the hero action labels to include the verb and replace the hero body with the short contract.

Use this replacement around `Page::render_page()`:

```php
$primary_label   = $chat_ready ? __( 'Open Activity Log', 'flavor-agent' ) : __( 'Open Connectors', 'flavor-agent' );
$secondary_label = $chat_ready ? __( 'Open Connectors', 'flavor-agent' ) : __( 'Open Activity Log', 'flavor-agent' );
```

Use this hero paragraph:

```php
<p class="flavor-agent-admin-hero__copy">
	<?php echo esc_html__( 'Configure setup, storage, docs, and guidance.', 'flavor-agent' ); ?>
</p>
```

- [ ] **Step 2: Replace verbose section bodies in `Page.php`**

Use these exact copy changes in the section renderers:

```php
// AI Model.
<?php echo esc_html__( 'Text generation is managed in Connectors.', 'flavor-agent' ); ?>
esc_html__( 'Current: %s.', 'flavor-agent' )

// Embedding Model.
self::render_subsection_heading(
	__( 'Cloudflare Workers AI', 'flavor-agent' ),
	__( 'Used for embeddings.', 'flavor-agent' )
);

// Patterns.
<?php echo esc_html__( 'Choose where the pattern catalog is stored.', 'flavor-agent' ); ?>
self::render_subsection_heading(
	__( 'Qdrant Pattern Storage', 'flavor-agent' ),
	__( 'Vector storage for the pattern index.', 'flavor-agent' )
);
self::render_subsection_heading(
	__( 'Cloudflare AI Search Pattern Storage', 'flavor-agent' ),
	__( 'Managed index for site pattern content.', 'flavor-agent' )
);

// Developer Docs.
<?php echo esc_html__( 'Built-in developer.wordpress.org grounding is active.', 'flavor-agent' ); ?>

// Guidelines core bridge.
<?php echo esc_html__( 'Core Guidelines connected.', 'flavor-agent' ); ?>
```

Remove these calls from `render_docs_grounding_group()`:

```php
self::render_docs_source_status();
self::render_prewarm_diagnostics_panel( $state );
```

After removing those calls, delete the now-unused private methods unless a later implementation deliberately keeps a warning-only diagnostics flow:

```php
private static function render_docs_source_status(): void
private static function render_prewarm_diagnostics_panel( array $state ): void
private static function render_runtime_grounding_diagnostics(): void
private static function render_prewarm_diagnostics(): void
```

- [ ] **Step 3: Shorten pattern sync prerequisite messages**

In `Page::get_pattern_sync_prerequisite_message()`, replace the long button-specific messages with:

```php
return ! empty( $page_state['cloudflare_pattern_ai_search_configured'] )
	? ''
	: __( 'Add private Cloudflare AI Search pattern storage before syncing.', 'flavor-agent' );
```

```php
return __( 'Complete Embedding Model and Qdrant Pattern Storage before syncing.', 'flavor-agent' );
```

```php
return __( 'Complete Embedding Model before syncing.', 'flavor-agent' );
```

```php
return __( 'Add the Qdrant URL and API key before syncing.', 'flavor-agent' );
```

- [ ] **Step 4: Replace section summaries and warnings in `State.php`**

In `State::get_group_card_meta()`, keep the existing badges and status logic but replace summaries with:

```php
Config::GROUP_CHAT => [
	'summary' => __( 'Text-generation provider status.', 'flavor-agent' ),
```

```php
Config::GROUP_EMBEDDINGS => [
	'summary' => __( 'Embedding credentials for semantic features.', 'flavor-agent' ),
```

```php
Config::GROUP_PATTERNS => [
	'summary' => __( 'Storage and sync for pattern recommendations.', 'flavor-agent' ),
```

```php
Config::GROUP_DOCS => [
	'summary' => __( 'Built-in developer.wordpress.org grounding.', 'flavor-agent' ),
```

```php
Config::GROUP_GUIDELINES => [
	'summary' => __( 'Site and block guidance.', 'flavor-agent' ),
```

```php
Config::GROUP_EXPERIMENTS => [
	'summary' => __( 'Beta feature toggles.', 'flavor-agent' ),
```

In `State::get_section_status_blocks()`, replace verbose warning messages with:

```php
__( 'Text generation is not configured. Open Connectors to choose a provider.', 'flavor-agent' )
```

```php
__( '%1$s is saved from an older setup, but that connector is not available. Open Connectors to choose a provider.', 'flavor-agent' )
```

```php
__( 'Embedding model is not configured.', 'flavor-agent' )
```

```php
__( 'Private AI Search pattern storage is not configured.', 'flavor-agent' )
```

```php
__( 'Embedding Model is required before syncing patterns.', 'flavor-agent' )
```

```php
__( 'Pattern Storage is required before syncing.', 'flavor-agent' )
```

For Developer Docs warnings, use short warning-only messages and remove the "diagnostics below" reference:

```php
__( 'Developer Docs grounding is retrying after a runtime search failure.', 'flavor-agent' )
__( 'Developer Docs grounding is warming in the background.', 'flavor-agent' )
__( 'Developer Docs live grounding needs attention. Check Activity Log for the latest error.', 'flavor-agent' )
__( 'Docs prewarm did not finish cleanly. Check Activity Log for the latest run.', 'flavor-agent' )
```

- [ ] **Step 5: Shorten Settings API field descriptions**

In `inc/Admin/Settings/Registrar.php`, keep labels, options, classes, placeholders, and callbacks unchanged. Replace descriptions only:

```php
'description' => 'Choose where the pattern catalog is stored.',
'description' => 'Cloudflare account for Workers AI.',
'description' => 'Workers AI API token.',
'description' => 'Workers AI embedding model ID.',
'description' => 'Private AI Search account.',
'description' => 'Namespace.',
'description' => 'Pattern index instance.',
'description' => 'AI Search API token.',
'description' => 'AI Search match threshold.',
'description' => 'Adds review-first insert and replace actions for the selected block.',
```

Also change the structural actions field label from:

```php
'label' => 'Enable selected-block structural actions',
```

to:

```php
'label' => 'Enable structural block actions',
```

- [ ] **Step 6: Shorten saved-secret helper text**

In `inc/Admin/Settings/Fields.php`, replace the repeated saved-secret sentence with:

```php
esc_html__( 'Saved value hidden. Leave blank to keep it, or enter a replacement.', 'flavor-agent' )
```

Keep the existing `aria-describedby`, `data-saved-secret`, password blanking, and `Saved` status pill behavior unchanged.

- [ ] **Step 7: Run focused PHP tests**

Run:

```bash
composer run test:php -- --filter SettingsTest
```

Expected after implementation: `SettingsTest` passes and no assertion expects `Developer Docs Source`, `Built-in public Cloudflare AI Search endpoint`, `legacy migration tooling`, or the old long section summaries.

---

## Task 3: Consolidate Contextual Help Into Three Concise Tabs

**Files:**
- Modify: `inc/Admin/Settings/Help.php`
- Test: `tests/phpunit/SettingsTest.php`
- Test: `tests/e2e/flavor-agent.settings.spec.js`

- [ ] **Step 1: Replace contextual Help tab content**

In `Help::get_contextual_help_tabs()`, keep the same three tab IDs and titles but replace the content with concise Help text.

Use this copy:

```php
[
	'id'       => 'flavor-agent-overview',
	'title'    => __( 'Overview', 'flavor-agent' ),
	'content'  => implode(
		'',
		[
			'<p>' . esc_html__( 'Use Connectors for text generation. Flavor Agent shows the active chat path here.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'Use this page for embedding credentials, pattern storage, developer-doc grounding limits, Guidelines, and beta feature toggles.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'When core Guidelines are available, Flavor Agent reads them first. Legacy fields remain available for migration and rollback.', 'flavor-agent' ) . '</p>',
		]
	),
	'priority' => 10,
],
[
	'id'       => 'flavor-agent-configuration',
	'title'    => __( 'Models & Storage', 'flavor-agent' ),
	'content'  => implode(
		'',
		[
			'<p>' . esc_html__( 'Embedding credentials power semantic matching, including Qdrant pattern recommendations.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'Pattern Storage chooses where the pattern catalog is indexed. Qdrant uses the configured Embedding Model.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'Private AI Search pattern storage needs account, namespace, instance, and token values.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'Qdrant and AI Search scores use different scales, so tune thresholds separately.', 'flavor-agent' ) . '</p>',
		]
	),
	'priority' => 20,
],
[
	'id'       => 'flavor-agent-troubleshooting',
	'title'    => __( 'Troubleshooting', 'flavor-agent' ),
	'content'  => implode(
		'',
		[
			'<p>' . esc_html__( 'Developer Docs use the built-in developer.wordpress.org grounding path. Runtime warnings identify grounding, warm queue, or prewarm states that need attention.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'Pattern Sync stays unavailable until the selected storage path is configured. The sync panel shows stale reasons, last errors, and technical index details.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'Guidelines import fills the form. Save Changes persists imported values, and export uses the Gutenberg-compatible guideline_categories JSON shape.', 'flavor-agent' ) . '</p>',
			'<p>' . esc_html__( 'Structural block actions are beta controls. Leave them off unless testing review-first insert and replace flows.', 'flavor-agent' ) . '</p>',
		]
	),
	'priority' => 30,
],
```

- [ ] **Step 2: Keep the Help sidebar actions**

Keep `Help::get_contextual_help_sidebar()` as the quick-link destination for:

```php
esc_html__( 'Open Connectors', 'flavor-agent' )
esc_html__( 'Open Activity Log', 'flavor-agent' )
```

Do not duplicate those links in every tab.

- [ ] **Step 3: Run copy-specific PHP and E2E checks**

Run:

```bash
composer run test:php -- --filter SettingsTest
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.settings.spec.js
```

Expected: Help assertions pass when the WP 7.0 harness is available. If Playwright is blocked by local environment setup, capture the blocker and keep the test command in the final verification record.

---

## Task 4: Translate And Align JavaScript-Driven Settings Strings

**Files:**
- Modify: `src/admin/settings-page-controller.js`
- Modify: `src/admin/__tests__/settings-page-controller.test.js`
- Generate: `build/admin.js`
- Generate: `build/admin.asset.php`

- [ ] **Step 1: Add an i18n mock to the settings controller unit test**

At the top of `src/admin/__tests__/settings-page-controller.test.js`, before importing `initializeSettingsPage`, add the same style of mock used by `src/admin/__tests__/activity-log.test.js`:

```js
jest.mock( '@wordpress/i18n', () => ( {
	__: jest.fn( ( value ) => value ),
	sprintf: jest.fn( ( template, ...values ) =>
		values.reduce( ( result, value, index ) => {
			return result
				.replaceAll( `%${ index + 1 }$s`, String( value ) )
				.replace( '%s', String( value ) )
				.replace( '%d', String( value ) );
		}, template )
	),
} ) );
```

Then import the mocked module so one test can assert usage:

```js
import * as i18n from '@wordpress/i18n';
```

- [ ] **Step 2: Update pinned JS string expectations**

Update existing expectations to match simplified copy:

```js
expect( root.querySelector( '[data-guidelines-block-list]' ).textContent ).toContain(
	'No block guidelines yet.'
);
expect(
	root.querySelector( '[data-guidelines-notice]' ).textContent
).toContain( 'Guidelines imported into the form. Save Changes to persist.' );
expect(
	root.querySelector( '#flavor-agent-sync-notice' ).textContent
).toContain( 'Synced 3 patterns, removed 1. Status: Ready.' );
```

Add one i18n usage assertion after initializing and interacting with the sync or Guidelines UI:

```js
expect( i18n.__ ).toHaveBeenCalledWith( 'No block guidelines yet.', 'flavor-agent' );
expect( i18n.sprintf ).toHaveBeenCalled();
```

- [ ] **Step 3: Import i18n in `settings-page-controller.js`**

At the top of `src/admin/settings-page-controller.js`, add:

```js
import { __, sprintf } from '@wordpress/i18n';
```

Wrap all visible UI strings in `__()` and use `sprintf()` for dynamic visible strings.

Use this pattern for constants:

```js
const PATTERN_STATUS_LABELS = {
	error: __( 'Error', 'flavor-agent' ),
	indexing: __( 'Syncing', 'flavor-agent' ),
	ready: __( 'Ready', 'flavor-agent' ),
	stale: __( 'Refresh needed', 'flavor-agent' ),
	uninitialized: __( 'Not synced', 'flavor-agent' ),
};
```

Use this pattern for dynamic messages:

```js
return sprintf(
	__( 'Synced %1$d patterns, removed %2$d. Status: %3$s.', 'flavor-agent' ),
	indexed,
	removed,
	statusLabel
);
```

```js
! window.confirm(
	sprintf(
		__( 'Remove the block guideline for %s?', 'flavor-agent' ),
		label
	)
)
```

Use this simplified empty state:

```js
emptyState.textContent = __( 'No block guidelines yet.', 'flavor-agent' );
```

Translate these visible strings:

```text
Error
Syncing
Ready
Refresh needed
Not synced
Embedding provider, model, or vector size changed.
Pattern index collection naming changed and needs a rebuild.
Pattern index collection is missing and needs a rebuild.
Pattern index collection vector size no longer matches the active embedding configuration.
Qdrant endpoint changed.
Embedding endpoint changed.
Registered patterns changed.
Needs setup
Needs attention
Needs sync
Pattern recommendations are not available until the required setup is complete.
Pattern recommendations need attention before they can be trusted.
Pattern recommendations are ready.
Pattern recommendations are usable but out of date.
Pattern recommendations are syncing now.
Pattern recommendations are not available until you sync the catalog.
Not synced yet
Select a block
Add Block Guideline
Update Block Guideline
No block guidelines yet.
Edit
Remove
Block guideline removed. Save Changes to persist.
Check that your file contains a guideline_categories object.
Choose a block before saving a block guideline.
Enter guideline text before saving a block guideline.
Block guideline ready. Save Changes to persist.
Check that your file contains valid JSON and try again.
Guidelines imported into the form. Save Changes to persist.
Could not import guidelines.
Guidelines exported.
Syncing...
Syncing pattern catalog.
Sync failed.
```

Do not translate server-provided error messages or the non-visible download filename `flavor-agent-guidelines.json`.

- [ ] **Step 4: Run JS unit tests**

Run:

```bash
npm run test:unit -- --runTestsByPath src/admin/__tests__/settings-page-controller.test.js
```

Expected: the settings controller unit test passes, and the i18n mock proves the UI path calls `__()` and `sprintf()`.

- [ ] **Step 5: Run JS lint**

Run:

```bash
npm run lint:js
```

Expected: no raw-string or import-order lint failures.

- [ ] **Step 6: Regenerate admin build artifacts**

Run:

```bash
npm run build
```

Expected: `build/admin.asset.php` gains `wp-i18n` in its dependency array, and `build/admin.js` contains the generated i18n-aware bundle. Do not edit either file manually.

---

## Task 5: Remove Stale Verbose-Copy Test Pins And Search For Stragglers

**Files:**
- Modify: `tests/phpunit/SettingsTest.php`
- Modify: `src/admin/__tests__/settings-page-controller.test.js`
- Modify: `tests/e2e/flavor-agent.settings.spec.js`
- Review: `docs/reference/admin-settings-visible-text-review.md`

- [ ] **Step 1: Search for old inline page copy outside the review artifact**

Run:

```bash
rg -n "Review model readiness|Current AI model path|Pattern setup does not choose another AI model|Built-in public Cloudflare AI Search endpoint|Developer Docs Source|legacy migration tooling|selected-block structural actions|ordinary production use|before the Sync Pattern Catalog button can run" --glob '!docs/reference/admin-settings-visible-text-review.md'
```

Expected after implementation: no matches in source or tests. Matches in the review artifact are allowed because it records the old findings.

- [ ] **Step 2: Search for untranslated settings-controller visible strings**

Run:

```bash
rg -n "textContent = '|\`Remove the block guideline|return 'Pattern recommendations|return \`Synced|Syncing\\.\\.\\.|Sync failed\\.|Guidelines exported\\.|Could not import guidelines" src/admin/settings-page-controller.js
```

Expected after implementation: no raw visible English strings remain in `settings-page-controller.js` except server-provided text, non-visible filenames, and values explicitly justified in code.

- [ ] **Step 3: Confirm generated asset dependency changed only through build**

Run:

```bash
git diff -- build/admin.asset.php
```

Expected: dependency array includes `wp-i18n`; version hash changes.

- [ ] **Step 4: Run focused test suite**

Run:

```bash
composer run test:php -- --filter SettingsTest
npm run test:unit -- --runTestsByPath src/admin/__tests__/settings-page-controller.test.js
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.settings.spec.js
```

Expected: PHP and JS pass. E2E passes when the WP 7.0 stack is available; if it is blocked, record the blocker and the exact command.

---

## Task 6: Run Final Verification Gates

**Files:**
- Review changed source, tests, and generated assets.
- Review: `output/verify/summary.json` after aggregate verification.

- [ ] **Step 1: Run whitespace and docs checks**

Run:

```bash
git diff --check
npm run check:docs
```

Expected: both pass.

- [ ] **Step 2: Run build and targeted gates**

Run:

```bash
npm run build
npm run lint:js
composer run lint:php
composer run test:php -- --filter SettingsTest
npm run test:unit -- --runTestsByPath src/admin/__tests__/settings-page-controller.test.js
```

Expected: all pass.

- [ ] **Step 3: Run fast aggregate verifier**

Run:

```bash
npm run verify -- --skip-e2e
```

Expected: final output includes a `VERIFY_RESULT={...}` JSON line with successful required steps, and `output/verify/summary.json` reflects the run.

- [ ] **Step 4: Run or record settings E2E evidence**

Run:

```bash
npx playwright test -c playwright.wp70.config.js tests/e2e/flavor-agent.settings.spec.js
```

Expected: settings E2E passes against the WP 7.0 harness. If the harness cannot activate required companion plugins or cannot reach the local stack, record the exact failure and treat it as an explicit environment blocker, not a silent skip.

- [ ] **Step 5: Review the final diff**

Run:

```bash
git diff --stat
git diff -- inc/Admin/Settings/Page.php inc/Admin/Settings/State.php inc/Admin/Settings/Help.php inc/Admin/Settings/Registrar.php inc/Admin/Settings/Fields.php src/admin/settings-page-controller.js src/admin/__tests__/settings-page-controller.test.js tests/phpunit/SettingsTest.php tests/e2e/flavor-agent.settings.spec.js build/admin.asset.php
```

Expected: diff is limited to visible copy, contextual Help, translatable JavaScript strings, tests, and generated admin assets. No settings behavior, option names, provider precedence, validation semantics, or migration behavior changed.

---

## Completion Checklist

- [ ] JavaScript-driven settings strings use `@wordpress/i18n`.
- [ ] `build/admin.asset.php` includes `wp-i18n` because the source import requires it.
- [ ] Developer Docs healthy state shows no inline implementation diagnostics.
- [ ] Developer Docs warning state remains short and actionable.
- [ ] Contextual Help contains the moved Connectors, storage, diagnostics, Guidelines migration, and beta concepts.
- [ ] Pattern Storage appears as storage/index infrastructure, not another model selector.
- [ ] Guidelines migration copy is limited inline to `Core Guidelines connected.`
- [ ] Field descriptions are short enough to support form completion without teaching architecture inline.
- [ ] PHP, JS, and E2E tests pin the simplified copy contract.
- [ ] Generated assets were rebuilt, not hand-edited.
- [ ] Verification commands and any E2E environment blocker are recorded in the handoff.

