# Theme token enrichment plan for template, template-part, and navigation prompts

The block recommendation surface already receives richer theme-token context than the template, template-part, and navigation surfaces. The server collectors already gather the needed payload, but the prompt builders for those three surfaces currently expose only a narrow subset.

## Current state by surface

| Surface | Current theme-token coverage | Current limits |
| --- | --- | --- |
| Block (`inc/LLM/Prompt.php`) | Rich inline formatting across all major token categories plus capability metadata | Already good; unchanged in this plan |
| Style (`inc/LLM/StylePrompt.php`) | Rich inline formatting for style-specific workflows | Already good; unchanged in this plan |
| Template (`inc/LLM/TemplatePrompt.php`) | Colors, font families, font sizes, spacing | Inline formatting inside `build_user()`, no `enabledFeatures`, no `layout` |
| Template part (`inc/LLM/TemplatePartPrompt.php`) | Colors, font families, font sizes, spacing | Private `format_theme_tokens()` helper with the same narrow subset |
| Navigation (`inc/LLM/NavigationPrompt.php`) | Colors, font sizes, font families, spacing | Private `format_theme_tokens()` helper with the same narrow subset and tighter truncation |

The main gap is not just missing token categories. These surfaces also omit the capability signals in `themeTokens.enabledFeatures` and `themeTokens.layout`, so the model cannot reliably treat theme support and layout settings as hard constraints.

## Goal

Create a shared theme-token formatter for template, template-part, and navigation prompts that:

1. Preserves the value-bearing token lines these prompts already expose today.
2. Adds compact preset references and capability metadata.
3. Keeps the formatted section within a bounded prompt budget without emitting partial lines.

## Scope

PHP prompt assembly changes only.

- No REST API contract changes
- No collector changes
- No new UI
- No client-side changes
- `inc/LLM/Prompt.php` and `inc/LLM/StylePrompt.php` remain unchanged

## Slice 1: Shared theme token formatter

### Why

The three target surfaces currently drift in how they format theme tokens:

- `TemplatePrompt` formats tokens inline inside `build_user()`
- `TemplatePartPrompt` delegates to a private `format_theme_tokens()` helper
- `NavigationPrompt` delegates to a different private `format_theme_tokens()` helper

A shared formatter removes that drift while respecting the existing integration points.

### File to create

`inc/LLM/ThemeTokenFormatter.php`

Namespace: `FlavorAgent\LLM`

API:

```php
public static function format( array $tokens ): string
```

### Input contract

The formatter consumes the existing `ThemeTokenCollector` payload unchanged.

Primary value-bearing arrays:

- `colors`
- `gradients`
- `fontSizes`
- `fontFamilies`
- `spacing`
- `shadows`
- `duotone`

Preset-reference arrays:

- `colorPresets`
- `fontSizePresets`
- `fontFamilyPresets`
- `spacingPresets`

Capability and structure metadata:

- `layout`
- `enabledFeatures`
- `elementStyles`

Important: the formatter must use the real `layout` payload shape already emitted by the collector:

```json
{
  "content": "650px",
  "wide": "1200px",
  "allowEditing": true,
  "allowCustomContentAndWideSize": true
}
```

### Output format

Deterministic, one line per section, emitted only when that line's source data is non-empty.

Primary value lines (preserve the current value-rich signal):

- `Colors: primary: #0073aa, secondary: #111111, ...`
- `Gradients: vivid-cyan-blue-to-vivid-purple: linear-gradient(...), ...`
- `Font sizes: small: 0.875rem, medium: 1rem, large: 1.5rem, ...`
- `Font families: inter: Inter, sans-serif, system: -apple-system, BlinkMacSystemFont, ...`
- `Spacing: 10: 0.25rem, 20: 0.5rem, ...`
- `Shadows: natural: 6px 6px 9px rgba(...), ...`
- `Duotone: blue-orange: #0af / #fa0, dark-grayscale: #111 / #ddd, ...`

Secondary preset-reference lines (compact slug + CSS variable only, no duplicated raw values):

- `Color preset refs: primary (var(--wp--preset--color--primary)), secondary (var(--wp--preset--color--secondary)), ...`
- `Font size preset refs: small (var(--wp--preset--font-size--small)), medium (var(--wp--preset--font-size--medium)), ...`
- `Font family preset refs: inter (var(--wp--preset--font-family--inter)), system (var(--wp--preset--font-family--system)), ...`
- `Spacing preset refs: 10 (var(--wp--preset--spacing--10)), 20 (var(--wp--preset--spacing--20)), ...`

Capability and structure lines:

- `Layout: {"content":"650px","wide":"1200px","allowEditing":true,"allowCustomContentAndWideSize":true}`
- `Enabled features: {"backgroundColor":true,"textColor":true,"blockGap":true,...}`
- `Element style keys: link, button, heading, ...`

Notes:

- Preset-reference lines are emitted independently. If `colors` is empty but `colorPresets` exists, `Color preset refs:` still emits.
- Preset references use `slug` plus `cssVar`. Entries missing either value are skipped.
- `elementStyles` is intentionally summarized as top-level keys only. Do not dump the full nested structure into these prompts.
- JSON metadata lines use `wp_json_encode()`.

### Emission order

Lines are assembled in this fixed order:

1. Colors
2. Color preset refs
3. Gradients
4. Font sizes
5. Font size preset refs
6. Font families
7. Font family preset refs
8. Spacing
9. Spacing preset refs
10. Shadows
11. Duotone
12. Layout
13. Enabled features
14. Element style keys

### Budget guardrail

Use `MAX_FORMATTED_LENGTH = 2000` in the formatter.

To make that cap real rather than aspirational:

1. Every line uses item caps before assembly.
2. The formatter never mid-line truncates or emits partial JSON.
3. If the assembled output still exceeds the cap, the formatter removes lower-priority lines in a fixed order until the joined result is `<= MAX_FORMATTED_LENGTH`.

Per-line item caps:

| Line | Max items |
| --- | --- |
| Colors | 20 |
| Color preset refs | 20 |
| Gradients | 12 |
| Font sizes | 20 |
| Font size preset refs | 20 |
| Font families | 12 |
| Font family preset refs | 12 |
| Spacing | 12 |
| Spacing preset refs | 12 |
| Shadows | 8 |
| Duotone | 8 |
| Element style keys | 8 |

Trim priority when still over budget:

1. Remove preset-reference lines in reverse order
2. Remove `Element style keys`
3. Remove `Shadows`, then `Duotone`, then `Gradients`
4. Remove `Font family preset refs`, then `Font families`
5. Remove other remaining low-priority value lines until the result fits

Do not remove both `Layout` and `Enabled features`; those are the highest-priority constraint lines and should survive every trim path unless their own source data is empty.

### Empty input behavior

- If `$tokens` is empty or every supported category is empty, return `''`
- Each line is emitted only when its own source data is non-empty after filtering

## Slice 1 integration points

### `inc/LLM/TemplatePrompt.php`

Replace the current inline theme-token block inside `build_user()` with:

```php
$formatted_tokens = ThemeTokenFormatter::format(
	is_array( $context['themeTokens'] ?? null ) ? $context['themeTokens'] : []
);

if ( $formatted_tokens !== '' ) {
	$sections[] = "## Theme Tokens\n{$formatted_tokens}";
}
```

Important: `TemplatePrompt` does not currently have a private `format_theme_tokens()` method. This is an inline replacement only.

### `inc/LLM/TemplatePartPrompt.php`

- Replace the existing `self::format_theme_tokens()` call with `ThemeTokenFormatter::format()`
- Delete the private `format_theme_tokens()` helper once the shared formatter is wired in
- Keep the existing `## Theme Tokens` section heading

### `inc/LLM/NavigationPrompt.php`

- Replace the existing `self::format_theme_tokens()` call with `ThemeTokenFormatter::format()`
- Delete the private `format_theme_tokens()` helper once the shared formatter is wired in
- Keep the existing `## Theme Design Tokens` section heading to minimize surface churn

## Slice 2: Add explicit capability-constraint language to system prompts

### Why

The block prompt already tells the model to treat `themeTokens.enabledFeatures` and `themeTokens.layout` as hard capability constraints. The template, template-part, and navigation prompts should add the same rule now that their user prompts will include those fields.

### Constraint wording

Use consistent wording across the three system prompts:

- Treat `enabledFeatures` and `layout` in Theme Tokens as hard capability constraints.
- When a recommendation depends on color, spacing, typography, border, background, or layout controls, do not recommend patterns, operations, or attribute changes that rely on disabled features or unsupported layout capabilities.

### Files to modify

- `inc/LLM/TemplatePrompt.php` `build_system()`
- `inc/LLM/TemplatePartPrompt.php` `build_system()`
- `inc/LLM/NavigationPrompt.php` `build_system()`

Navigation can keep its existing structure-focused rules; this new line is specifically for visual and layout-dependent guidance, not for unrelated structural advice.

## File summary

| File | Action |
| --- | --- |
| `inc/LLM/ThemeTokenFormatter.php` | Create shared formatter with one public static method |
| `inc/LLM/TemplatePrompt.php` | Replace inline theme-token formatting and add capability-constraint text |
| `inc/LLM/TemplatePartPrompt.php` | Use shared formatter, delete old private helper, add capability-constraint text |
| `inc/LLM/NavigationPrompt.php` | Use shared formatter, delete old private helper, add capability-constraint text |
| `tests/phpunit/ThemeTokenFormatterTest.php` | Add formatter unit coverage |
| `tests/phpunit/TemplatePromptTest.php` | Add prompt-formatting and system-text assertions |
| `tests/phpunit/TemplatePartPromptTest.php` | Add prompt-formatting and system-text assertions |
| `tests/phpunit/NavigationAbilitiesTest.php` | Add prompt-formatting and system-text assertions for navigation |

## Token budget impact

Before:

- Roughly 40-80 tokens in the theme-token section for these three surfaces
- Limited to four categories with no capability metadata

After:

- Roughly 150-300 tokens for typical themes
- More categories plus explicit capability metadata
- Still bounded by a formatter-level 2000-character cap and line-priority trimming

This is still comfortably inside the prompt budget for these surfaces and provides materially better design and capability grounding.

## Test strategy

### New file: `tests/phpunit/ThemeTokenFormatterTest.php`

Add targeted tests for:

1. Empty input returns `''`
2. Typical payload emits primary value lines, preset-reference lines, and metadata lines
3. Preset-reference lines emit even when the paired value line is absent
4. Malformed preset entries without `slug` or `cssVar` are skipped
5. `layout` output uses the real collector keys: `content`, `wide`, `allowEditing`, `allowCustomContentAndWideSize`
6. `elementStyles` is summarized as top-level keys only
7. Per-line item caps are enforced
8. Oversized input trims by priority and remains `<= MAX_FORMATTED_LENGTH`

Assertions should rely on `assertStringContainsString()` / `assertStringNotContainsString()` for labels and representative values rather than exact full-output matching.

### Modified existing tests

`TemplatePromptTest.php`

- Add a `build_user()` test with `themeTokens` containing gradients, shadows, enabled features, and layout
- Assert the prompt contains the new value lines and metadata lines
- Assert `build_system()` contains the new capability-constraint text

`TemplatePartPromptTest.php`

- Add a `build_user()` test with the same richer `themeTokens` payload
- Assert the prompt contains the new value lines and metadata lines
- Assert `build_system()` contains the new capability-constraint text

`NavigationAbilitiesTest.php`

- Extend the prompt-building coverage to assert the richer theme-token output appears in navigation prompts
- Assert `NavigationPrompt::build_system()` contains the new capability-constraint text
- Keep this in `NavigationAbilitiesTest.php`; there is no dedicated `NavigationPromptTest.php`

No new area-header test is needed here because `TemplatePartPrompt` already emits `Area: ...` unconditionally and that behavior is unrelated to this change.

## Regression commands

Targeted:

```bash
vendor/bin/phpunit --filter '(ThemeTokenFormatterTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest)'
```

Full:

```bash
vendor/bin/phpunit
npm run test:unit -- --runInBand
```
