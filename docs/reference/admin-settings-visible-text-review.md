# Admin Settings Visible Text Review

Review target: `Settings > Flavor Agent` visible copy, contextual Help placement, and string/test surfaces listed in `docs/reference/admin-settings-visible-text-review-prompt.md`.

## Findings

1. **JavaScript-driven settings strings are not translatable.**
   `src/admin/settings-page-controller.js` writes visible labels, notices, confirmation text, sync status text, and import/export errors as raw English strings instead of using `@wordpress/i18n` (`__`, `sprintf`). This affects pattern sync labels and reasons at lines 1-29, sync summaries at 99-157, block-guideline UI text at 640-774, import/export notices at 790-927, and sync notices/live-region text at 949-1065. The PHP-rendered page uses translation functions, so post-interaction copy can become untranslated and inconsistent with the initial render.

2. **Developer Docs still exposes implementation diagnostics inline.**
   `inc/Admin/Settings/Page.php` always renders `Developer Docs Source`, `Built-in public Cloudflare AI Search endpoint`, and optional `Instance: %s` in the main page at lines 766-792. The diagnostics subpanel then exposes runtime grounding, warm queue, trusted success, last errors, prewarm counts, and timestamps at lines 796-809 and 862-993. This is troubleshooting and architecture detail that belongs in contextual Help or behind a warning-only diagnostics flow. It also reintroduces Cloudflare implementation language into the Developer Docs section, where the page should present the built-in developer.wordpress.org grounding path.

3. **Settings > Connectors guidance is repeated across the page and Help.**
   The same routing concept appears in the hero actions (`inc/Admin/Settings/Page.php` lines 52-61), AI Model summary (`inc/Admin/Settings/State.php` line 130), AI Model body (`inc/Admin/Settings/Page.php` lines 492-507), chat warnings (`inc/Admin/Settings/State.php` lines 320-331), and three Help tabs (`inc/Admin/Settings/Help.php` lines 42, 59-62, and 77). The page needs one inline current-status statement and one `Open Connectors` action; the rest should move to Help.

4. **Guidelines migration copy is too prominent for ordinary settings use.**
   The section summary and inline status explain core Guidelines, legacy fields, migration tooling, JSON import/export, and rollback (`inc/Admin/Settings/State.php` lines 179-181, `inc/Admin/Settings/Page.php` lines 647-651, and `inc/Admin/Settings/Help.php` line 81). That is background and migration context. Inline copy should only identify the current storage state and the fields the admin can edit.

5. **Pattern Storage copy answers past confusion multiple times.**
   The page repeats that Pattern Storage is not another AI model in the section summary, body description, field description, and Help (`inc/Admin/Settings/State.php` line 163, `inc/Admin/Settings/Page.php` lines 557-582, `inc/Admin/Settings/Registrar.php` lines 272-287, and `inc/Admin/Settings/Help.php` lines 44 and 64). The concept is valid, but inline repetition makes setup feel more complex. Keep one short field description and move the model/storage relationship to Help.

6. **Several field descriptions use implementation or beta-internal language that can be shortened.**
   Examples include private AI Search account/namespace/instance/token descriptions (`inc/Admin/Settings/Registrar.php` lines 379, 394, 409, and 425), the AI Search threshold rationale (`line 464`), and the structural-actions beta description (`line 564`). These are useful details, but not all need to be visible beside the controls.

7. **Tests pin verbose copy rather than the simplified contract.**
   `tests/phpunit/SettingsTest.php` asserts PHP-rendered settings copy for embeddings, section summaries, Developer Docs source text, Guidelines import text, and Guidelines migration copy at lines 581-582, 1019-1027, 1552-1554, 1614, and 1630-1631. `tests/e2e/flavor-agent.settings.spec.js` asserts the hero paragraph, long section summaries, Developer Docs implementation copy, Help paragraphs, and migration wording at lines 30-32, 60-95, 125-130, and 140-172. `src/admin/__tests__/settings-page-controller.test.js` pins JS strings at lines 80-112, 320-322, 380-402, 473-480, 559-562, and 632-633. Any simplification will need deliberate test updates rather than broad snapshot churn.

## Visible-Text Inventory

| Location | Current text or summary | Classification | Reason | Proposed replacement or destination |
| --- | --- | --- | --- | --- |
| `Page.php:46-53` | `Flavor Agent`, `Flavor Agent Settings`, hero paragraph about readiness, embeddings, patterns, docs, and guidance | Shorten inline | The page title is needed; the paragraph is broad setup framing. | Keep title. Replace paragraph with `Configure Flavor Agent settings and review setup status.` or remove. |
| `Page.php:55-61` | `Activity Log` / `Connectors` hero buttons | Keep inline | Primary next actions are useful. | Keep, but prefer `Open Activity Log` / `Open Connectors` for consistency with Help. |
| `Page.php:74-132` | `1. AI Model` through `6. Experimental Features`, `Save Changes` | Keep inline | Preserves required IA and form action. | Keep. |
| `Page.php:215-252` | Post-save summaries for AI model, embedding model, patterns, docs, guidelines, experiments | Keep inline | Short success feedback tied to saved sections. | Keep; optionally combine repeated lines into one summary if many sections change. |
| `Page.php:274-324`; `State.php:216-309` | Glance card labels and badges: `Ready`, `Needs setup`, `Needs model & storage`, `Needs private AI Search`, `Refresh needed`, `Off`, `On` | Keep inline | Immediate status scanning. | Keep; align JS labels with these translated PHP labels. |
| `State.php:129-203` | Accordion summaries and badges: `Required`, `Optional`, `Built in`, `Core bridge`, `Beta`, longer explanatory summaries | Shorten inline | Summaries are useful but read like Help copy. | Use shorter summaries: `Text-generation provider status.`, `Embedding credentials for semantic features.`, `Storage and sync for pattern recommendations.`, `Built-in developer.wordpress.org grounding.`, `Site and block guidance.`, `Beta feature toggles.` |
| `Page.php:492-507`; `State.php:320-331` | AI Model routing copy and warnings for Connectors / legacy connector pin | Shorten inline | The action is needed, but repeated explanation is not. | Inline: `Text generation is managed in Connectors.` and `Current: %s.` Keep `Open Connectors`. Move connector ownership detail to Help. |
| `Page.php:519-538`; `Registrar.php:291-335` | Embedding section text, `Cloudflare Workers AI Embeddings`, Account ID, API Token, Embedding Model descriptions | Shorten inline | Field labels are needed; the section repeats semantic-feature context. | `Cloudflare Workers AI` heading, description `Used for embeddings.` Keep field descriptions short. |
| `Fields.php:33-35`, `Fields.php:87-88` | Saved secret notice and `Saved` pill | Keep inline | Security state and control behavior must remain accessible. | Keep, perhaps shorten to `Saved value hidden. Leave blank to keep it, or enter a replacement.` |
| `Page.php:557-612`; `Registrar.php:272-287` | Pattern Storage description, Qdrant heading, Cloudflare AI Search Pattern Storage heading, Advanced Ranking | Shorten inline | Controls are needed; model/storage education is repeated. | Keep headings and fields. Field help: `Choose where the pattern catalog is stored.` Move model relationship to Help. |
| `Registrar.php:337-429` | Qdrant and private Cloudflare AI Search fields | Shorten inline | Labels are required; descriptions drift into infrastructure detail. | Keep labels. Shorten to `Qdrant cluster URL.`, `Qdrant API key.`, `Private AI Search account.`, `Namespace.`, `Pattern index instance.`, `AI Search API token.` |
| `Registrar.php:430-485` | Advanced ranking fields and threshold/max descriptions | Shorten inline | Advanced controls need field help, but score-comparison rationale is Help material. | Keep `Higher values drop weaker matches.` and `Maximum recommendations returned.` Move score-comparison rationale to Help. |
| `Page.php:620-635`; `Registrar.php:487-501` | Developer Docs built-in endpoint text and `Max Grounding Sources` | Shorten inline | One short statement and field label are enough. | Inline: `Built-in developer.wordpress.org grounding is active.` Keep max sources field. |
| `Page.php:766-993`; `State.php:374-407` | Developer Docs source/diagnostics/runtime/prewarm details and warnings | Move to Help | Background, diagnostics, timestamps, queue state, and implementation details. | Show only warning notices when attention is required; move diagnostic definitions to Troubleshooting Help. |
| `Page.php:641-665`; `Registrar.php:502-553` | Guidelines storage message and site/copy/image/additional fields | Shorten inline | Fields are immediate controls; migration copy is not. | Inline: `Core Guidelines connected.` or no notice when healthy. Move legacy/migration explanation to Help. |
| `Page.php:685-761`; `settings-page-controller.js:640-900` | Block Guidelines UI, empty state, Add/Update/Edit/Remove, import/export notices | Keep inline | Direct controls and validation feedback. | Keep, translate JS strings, shorten empty state to `No block guidelines yet.` |
| `Page.php:674-682`; `Page.php:173-177`; `Registrar.php:554-565` | Experimental Features section and structural-actions checkbox | Shorten inline | Feature toggle is needed; internal beta wording is verbose. | Label: `Enable structural block actions`. Description: `Adds review-first insert and replace actions for the selected block.` Move production caution to Help. |
| `Help.php:36-81` | Overview, Models & Storage, Troubleshooting paragraphs and bullets | Shorten in Help | Help has absorbed copy but also repeats page copy and implementation terms. | Keep three tabs, remove self-referential first paragraph, consolidate repeated Connectors/model/storage statements. |
| `Help.php:96-105` | Quick Links, Open Connectors, Open Activity Log | Keep inline in Help | Useful action links. | Keep. |
| `Feedback.php:344-348`; `Validation.php:892-980` | Validation errors and preserved-settings notices | Keep inline | Error and preservation feedback is actionable. | Keep, but consider shortening private AI Search validation message if it wraps badly. |
| `settings.css` | Decorative toggle pseudo-elements with empty `content` | Keep | No user-visible text. | No copy change. |
| `settings-page-controller.js:1-1065` | JS labels, notices, live-region strings, confirm text, import/export errors | Keep/shorten inline, but translate | These are direct UI states, but currently untranslated and sometimes inconsistent with PHP. | Move strings through `@wordpress/i18n`; align with simplified PHP copy. |

## Help Relocation Table

| Text/concept to move | Current location | Target Help tab | Short Help wording |
| --- | --- | --- | --- |
| Connectors owns chat credentials and text-generation provider selection | `Page.php:492-507`, `State.php:130`, `Help.php:42,59-62,77` | Overview | `Use Connectors for text generation. Flavor Agent shows the active chat path here.` |
| Embedding settings do not provide chat | `Help.php:78`, repeated by `Page.php:519-527` | Models & Storage | `Embedding credentials power semantic matching, including Qdrant pattern recommendations.` |
| Pattern Storage is infrastructure, not another model selector | `Page.php:557-582`, `Registrar.php:286`, `Help.php:44,64` | Models & Storage | `Pattern Storage chooses where the pattern catalog is indexed. Qdrant uses the configured Embedding Model.` |
| Private Cloudflare AI Search pattern storage requirements | `State.php:351`, `Registrar.php:379-425` | Models & Storage | `Private AI Search pattern storage needs account, namespace, instance, and token values.` |
| AI Search threshold scores are not comparable with Qdrant | `Registrar.php:464` | Models & Storage | `Qdrant and AI Search scores use different scales, so tune thresholds separately.` |
| Developer Docs implementation source, instance ID, runtime grounding, queue, trusted success, prewarm counts | `Page.php:766-993`, `State.php:374-407`, `Help.php:80` | Troubleshooting | `Developer Docs use the built-in developer.wordpress.org grounding path. Diagnostics show runtime status, warm queue state, last success, and last error when troubleshooting.` |
| Core Guidelines bridge, legacy fields, JSON import/export migration and rollback | `State.php:179-181`, `Page.php:647-651`, `Help.php:81` | Overview or Troubleshooting | `When core Guidelines are available, Flavor Agent reads them first. Legacy fields remain available for migration and rollback.` |
| Structural actions beta caution | `Page.php:173-177`, `Registrar.php:564` | Troubleshooting | `Structural block actions are beta controls. Leave them off unless testing review-first insert and replace flows.` |

## Simplified Copy Proposal

### Hero

- Wordmark: `Flavor Agent`
- Title: `Flavor Agent Settings`
- Body: remove, or use `Configure setup, storage, docs, and guidance.`
- Actions: `Open Activity Log`, `Open Connectors`

### 1. AI Model

- Summary: `Text-generation provider status.`
- Status notice when missing: `Text generation is not configured.`
- Body: `Text generation is managed in Connectors.`
- Meta: `Current: %s.`
- Button: `Open Connectors`

### 2. Embedding Model

- Summary: `Embedding credentials for semantic features.`
- Status notice when missing: `Embedding model is not configured.`
- Subheading: `Cloudflare Workers AI`
- Subheading description: `Used for embeddings.`
- Fields: `Account ID`, `API Token`, `Embedding Model`

### 3. Patterns

- Summary: `Storage and sync for pattern recommendations.`
- Body: `Choose where the pattern catalog is stored.`
- Field: `Pattern Storage`
- Choices: `Qdrant vector storage`, `Cloudflare AI Search managed index`
- Qdrant heading: `Qdrant Pattern Storage`
- AI Search heading: `Cloudflare AI Search Pattern Storage`
- Advanced heading: `Advanced Ranking`
- Sync panel: `Sync Pattern Catalog`, `Status`, `Indexed Patterns`, `Last Synced`, `Last Error`, `Technical details`
- Prerequisite messages: keep but shorten `before the Sync Pattern Catalog button can run` to `before syncing.`

### 4. Developer Docs

- Summary: `Built-in developer.wordpress.org grounding.`
- Body: `Built-in developer.wordpress.org grounding is active.`
- Field: `Max Grounding Sources`
- Healthy state: no inline diagnostics.
- Warning state: one actionable notice, e.g. `Developer Docs needs attention. Open Help for diagnostics.`

### 5. Guidelines

- Summary: `Site and block guidance.`
- Core bridge notice: `Core Guidelines connected.`
- Fields: `Site Context`, `Copy Guidelines`, `Image Guidelines`, `Additional Guidelines`
- Block panel: `Block Guidelines`, `Block`, `Guideline text`, `Add Block Guideline`, `Update Block Guideline`, `Edit`, `Remove`
- Empty state: `No block guidelines yet.`
- Import/export: `Import JSON`, `Export JSON`, `Import fills the form. Save Changes to persist.`

### 6. Experimental Features

- Summary: `Beta feature toggles.`
- Checkbox label: `Enable structural block actions`
- Description: `Adds review-first insert and replace actions for the selected block.`

## Implementation Notes

- `inc/Admin/Settings/Page.php`: shorten hero, inline section descriptions, Developer Docs source/diagnostics display, Guidelines core-bridge notice, sync prerequisite messages, and Experimental Features copy.
- `inc/Admin/Settings/State.php`: shorten accordion summaries, status warnings, pattern prerequisite labels, and Developer Docs warning text.
- `inc/Admin/Settings/Help.php`: consolidate repeated Connectors/model/storage text; add concise diagnostics/migration/beta notes to Help.
- `inc/Admin/Settings/Registrar.php`: shorten field descriptions and experimental checkbox copy.
- `inc/Admin/Settings/Fields.php`: optionally shorten saved secret description while preserving `aria-describedby`.
- `src/admin/settings-page-controller.js`: import `__` and `sprintf` from `@wordpress/i18n`; translate all visible strings; align dynamic sync/guidelines notices with PHP copy.
- `src/admin/__tests__/settings-page-controller.test.js`: update expected strings and add at least one assertion that translated strings are used through the simplified copy contract.
- `tests/phpunit/SettingsTest.php`: update PHP-rendered copy assertions for section summaries, Developer Docs source state, Guidelines migration state, and field descriptions.
- `tests/e2e/flavor-agent.settings.spec.js`: stop asserting verbose paragraphs; assert section order, required actions, healthy Developer Docs state, contextual Help tabs, and migration/diagnostic text only in Help.
- `src/admin/settings.css`: no copy change expected unless diagnostics are visually hidden or warning-only.
- `build/admin.js` and `build/admin.asset.php`: regenerate with `npm run build` after source changes; do not hand-edit.

## Test Updates And Verification Commands

- Targeted JS: `npm run test:unit -- --runTestsByPath src/admin/__tests__/settings-page-controller.test.js`
- Targeted PHP: `composer run test:php -- --filter SettingsTest`
- E2E settings surface: `npm run test:e2e -- tests/e2e/flavor-agent.settings.spec.js` or the repo-specific WP 7.0 settings harness if available.
- Build after JS changes: `npm run build`
- Docs/copy sanity: `npm run check:docs`
- Fast aggregate gate for source changes: `npm run verify -- --skip-e2e`
- Add `git diff --check` before handoff to catch whitespace issues in the review/copy edits.
