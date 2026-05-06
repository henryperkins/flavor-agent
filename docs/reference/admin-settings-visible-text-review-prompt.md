# Admin Settings Visible Text Review Prompt

Use this prompt to review the Flavor Agent settings page for unnecessary visible copy, unclear labels, and help text that should move into WordPress contextual Help.

```text
You are reviewing the Flavor Agent WordPress admin settings page as a senior product designer, WordPress admin UX reviewer, and copy editor.

Goal:
Make Settings > Flavor Agent substantially simpler. The page is currently too verbose and complicated. Inline text should help administrators complete the current task only. Background explanation, setup rationale, troubleshooting, migration context, and conceptual education should move into WordPress contextual Help, which appears from the Help tab under the admin bar.

Primary files to inspect:
- inc/Admin/Settings/Page.php
- inc/Admin/Settings/Help.php
- inc/Admin/Settings/Registrar.php
- inc/Admin/Settings/State.php
- inc/Admin/Settings/Feedback.php
- inc/Admin/Settings/Fields.php
- src/admin/settings-page-controller.js
- src/admin/settings.css
- src/admin/__tests__/settings-page-controller.test.js
- tests/e2e/flavor-agent.settings.spec.js

Current information architecture to preserve unless you find a confirmed bug:
1. AI Model
2. Embedding Model
3. Patterns
4. Developer Docs
5. Guidelines
6. Experimental Features

Ownership boundaries:
- Settings > Connectors owns shared chat credentials and text-generation provider selection.
- Settings > Flavor Agent owns Flavor Agent setup status, embedding model configuration, pattern storage/sync settings, developer-doc grounding, guidelines migration, and experimental feature toggles.
- Pattern Storage copy should describe storage/index infrastructure, not another AI model selector.
- Developer Docs should describe the built-in developer.wordpress.org grounding path. Legacy Cloudflare developer-doc override fields should stay hidden unless saved legacy values require migration.

Review method:
1. Inventory every user-visible string on the settings page, including hero text, status cards, section titles, field labels, field descriptions, notices, badges, empty states, button labels, validation errors, sync/status diagnostics, and JavaScript-driven strings.
2. Classify each string as one of:
   - Keep inline: required to choose, enter, save, or understand immediate status.
   - Shorten inline: useful, but too long or too explanatory.
   - Move to Help: background, troubleshooting, rationale, migration detail, architecture detail, or repeated explanation.
   - Remove: redundant, obvious, or not useful to the admin.
3. Check whether the same concept is explained multiple times across the hero, status cards, section headers, field descriptions, notices, and Help tabs.
4. Check whether copy makes setup feel harder than it is by listing implementation details before the user needs them.
5. Check whether labels and button text use plain WordPress admin language and avoid product-internal terms unless the user must choose between those terms.
6. Check whether success and error notices are short, actionable, and tied to the field or section that needs attention.
7. Check whether contextual Help in inc/Admin/Settings/Help.php can absorb the removed explanation cleanly.
8. Check accessibility impact: any shortened visible text must still preserve label purpose, aria-describedby usefulness, and field-specific error clarity.
9. Check test coverage that would need updates if strings or help placement change.

Inline copy standards:
- Prefer short labels and direct status over paragraphs.
- Keep one sentence of field help only when the field cannot be completed safely without it.
- Do not explain how the whole system works inside each section.
- Do not repeat "Settings > Connectors" guidance everywhere; keep the page-level status and Help tab responsible for broader routing.
- Avoid numbered setup prose inside field descriptions unless the field truly requires ordered steps.
- Use "Save Changes", "Run Pattern Sync", "Activity Log", and "Connectors" consistently.
- Avoid new marketing copy, hero-style explanation, or reassurance text.

Contextual Help standards:
- Move conceptual explanation, troubleshooting, migration notes, and provider/storage relationships to Help tabs.
- Organize Help around scannable tabs such as Overview, Models & Storage, and Troubleshooting.
- Keep Help text useful but not bloated; moving text to Help is not permission to preserve every sentence.
- Include links only when they help the admin take the next action, such as Connectors or Activity Log.

Required output:
1. Findings, ordered by severity, with file and line references.
2. A visible-text inventory table with columns:
   - Location
   - Current text or summary
   - Classification
   - Reason
   - Proposed replacement or destination
3. A Help relocation table with columns:
   - Text/concept to move
   - Current location
   - Target Help tab
   - Short Help wording
4. A simplified copy proposal for the full page, grouped by section.
5. Implementation notes listing exact files likely to change.
6. Test updates and verification commands.

Constraints:
- Do not change behavior, option names, provider precedence, validation semantics, or saved legacy migration behavior as part of a copy review.
- Do not hand-edit build/ or dist/ artifacts. If source changes require built assets, run the repo build step.
- Treat untranslated strings, missing escaping, or accessibility regressions as review findings.
- Keep the final page focused on controls, status, and next actions. Put the rest in WordPress contextual Help.
```
