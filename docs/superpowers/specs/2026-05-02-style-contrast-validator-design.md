# Style Contrast Validator — Design

**Date:** 2026-05-02
**Surfaces:** Global Styles, Style Book
**Stage:** Stage B (B0 closed; B1 commitments in `docs/reference/surfaces/global-styles.md` § "Stage B Design Commitments"; Stage A landed)

## Scope

Add deterministic WCAG AA contrast validation to the server-side style
recommendation pipeline so that low-contrast or unverifiable color suggestions
are downgraded from executable to advisory before they reach the editor.
Closes four open Next Steps in `docs/reference/surfaces/global-styles.md`:
contrast/readability validation, paired-op preference, low-contrast advisory
classification, and the design-quality-claim gate.

Out of scope for v1: AAA threshold, large-text exemptions, color-blindness
simulation, gradients, duotone, `set_theme_variation` evaluation, and any
JS-side contrast utility (server is authoritative; existing review/resolved
signature machinery already detects palette and merged-config drift).

Executable color operations remain preset-backed (already enforced by
`StylePrompt::validate_operations()`, which drops any color op whose value
is not a `var:preset|color|<slug>` reference). The contrast resolver itself
does accept direct hex values, but only when reading existing context or
complement values from `mergedConfig` or `themeTokens` — themes commonly
serialize element-level backgrounds as direct hex, and refusing those would
make the validator unable to evaluate any element-scope solo op against the
merged complement. The "preset-backed" rule constrains what executable
operations may carry; it does not constrain what the resolver may consume.

## Architecture

### New Class

`FlavorAgent\LLM\StyleContrastValidator` — file `inc/LLM/StyleContrastValidator.php`.

Lives alongside `StylePrompt` because (a) it is invoked only from
`StylePrompt::validate_suggestions()`, (b) the `LLM/` namespace already houses
prompt-pipeline validation (`StylePrompt::validate_operations()`), and (c) it
is style-specific, not a cross-cutting `Support/` helper.

### Public API

```php
final class StyleContrastValidator {
    public static function evaluate( array $operations, array $context ): array;
}
```

`evaluate()` returns:

```php
[
    'passed' => bool,            // false → caller forces tone=advisory
    'kind'   => string|null,     // 'low_ratio'|'unavailable'|null — drives trigger-priority selection
    'reason' => string|null,     // first-failure summary for description annotation
    'ratio'  => float|null,      // first ratio-failure ratio (null if unavailable)
]
```

`kind`, `reason`, and `ratio` together form the first-failure projection
used to drive both the description annotation and the trigger-priority
selection. v1 callers (`validate_suggestions`) read all four fields.

Per-scope failure diagnostics intended for the admin activity log are
deliberately **not** part of the v1 contract — see Out-of-Band Followups
for the deferred shape and the rationale for keeping the v1 surface narrow.

### Integration Point

In `StylePrompt::validate_suggestions()`, immediately after the existing
Stage A drop check and before the final tone decision:

```php
$input_operations     = is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [];
$operations           = self::validate_operations( $input_operations, $context );
$operation_dropped    = count( $input_operations ) !== count( $operations );
$contrast_result      = StyleContrastValidator::evaluate( $operations, $context );
$contrast_failed      = ! $contrast_result['passed'];
$should_downgrade     = $operation_dropped || $contrast_failed;
$effective_operations = $should_downgrade ? [] : $operations;

$tone = ( 'executable' === sanitize_key( (string) ( $suggestion['tone'] ?? '' ) ) )
    && [] !== $effective_operations
    ? 'executable'
    : 'advisory';
```

`$effective_operations` flows through everywhere downstream of the downgrade
decision — score weight, source signals, ranking normalization, `entry`
construction.

### Metadata Caveat (Existing Bug Closed)

Today `StylePrompt.php` lines ~775-790 weight `'has_operations'` and add the
`has_operations` source signal based on `[] !== $operations` (the validator
output, not the post-downgrade value). The patch swaps both checks to
`[] !== $effective_operations` so downgraded suggestions don't falsely
advertise structural operations in ranking metadata. The `tone_*` signal is
already correct since it reads post-downgrade `$tone`.

## Pair-Grouping Algorithm

### Scope Key Enum

Five and only five scope keys. Anything outside this enum that looks like a
readable color path (ends in `color.text` or `color.background`) fails closed:
contrast-unavailable → suggestion downgrades.

| Scope key | Pair semantics | Background config path | Text config path |
| --- | --- | --- | --- |
| `root` | Pair | `styles.color.background` | `styles.color.text` |
| `elements.button` | Pair | `styles.elements.button.color.background` | `styles.elements.button.color.text` |
| `elements.link` | Solo (text only) | — | `styles.elements.link.color.text` |
| `elements.heading` | Solo (text only) | — | `styles.elements.heading.color.text` |
| `blocks.<blockName>` | Pair (Style Book) | `styles.blocks.<blockName>.color.background` | `styles.blocks.<blockName>.color.text` |

The path columns above show the merged-config path used for complement
lookup (read from `mergedConfig.styles.*`). Operation-to-scope mapping is
distinct because operation paths are relative and `set_block_styles`
carries `blockName` separately:

| Operation | Mapping rule |
| --- | --- |
| `set_styles` with `path` `['color', 'background'\|'text']` | `root` |
| `set_styles` with `path` `['elements', '<element>', 'color', 'background'\|'text']` | `elements.<element>` (when `<element>` is in the enum) |
| `set_block_styles` with `blockName` `<name>` and `path` `['color', 'background'\|'text']` | `blocks.<name>` (Style Book only) |

`blocks.<blockName>` scope keys carry the explicit block name so
`blocks.core/paragraph` and `blocks.core/button` are distinct and never
cross-pair.

### Within-Scope Resolution

Proposed ops fill first; missing side fills from:

- `root` → `mergedConfig.styles.color.{background|text}`
- `elements.{button|link|heading}` → `themeTokens['elementStyles'][element]['base'][text|background]`,
  falling back to `mergedConfig.styles.color.{background|text}` (root) if the
  element-scoped value is absent
- `blocks.<name>` → `mergedConfig.styles.blocks.<name>.color.{background|text}`,
  falling back to `mergedConfig.styles.color.{background|text}` (root) if the
  block-scoped value is absent

The fallback to root is necessary because most themes do not define explicit
backgrounds at every element/block scope — the rendered contrast the user
actually sees is text-on-root in those cases. If both the scope-specific
source and the root fallback yield nothing, fail closed with
`missing`.

For solo-by-design scopes (`elements.link`, `elements.heading`), the same
fallback chain applies — the "complement" is whatever background the
inheriting context provides.

### Excluded From Validation

`border.color` is a preset-color operation but not a foreground/background
readability pair. The validator skips border ops silently (no contrast
evaluation, no effect on tone). `set_theme_variation` is also excluded for v1
per the surface-doc commitment (theme-authored, trusted).

## Color Resolution

### Five Accepted Forms

The resolver normalizes any incoming color value to a `#rrggbb` hex or marks
it unresolved with a typed reason:

1. **Flavor Agent preset reference** — `var:preset|color|<slug>` → look up
   `<slug>` in a slug index built from `themeTokens['colorPresets']`. The
   collector emits a numeric list of `{name, slug, color, cssVar}` objects
   (not a slug-keyed map), so the validator builds `[slug => color]` once
   per `evaluate()` call before resolving.
2. **WordPress CSS var preset reference** — `var(--wp--preset--color--<slug>)` →
   extract `<slug>` via regex, then look up the same slug index. Required
   because `ThemeTokenCollector::collect_element_styles()` reads from
   `wp_get_global_styles()` which serializes preset references in CSS-var
   form.
3. **Direct hex** — `#rrggbb` or `#rrggbbaa` → use directly, alpha truncated
   for v1.
4. **Missing/null** — `missing`.
5. **Anything else** (named colors, `rgb()`/`hsl()` functions, `currentColor`,
   `inherit`, `transparent`, gradients, etc.) → `unknown-form`.

Forms 1 and 2 with a slug that isn't in `colorPresets`, or whose `.color` is
empty → `unknown-preset`.

### Resolver Result Shape

```php
[
    'resolved' => bool,
    'hex'      => string|null,    // '#rrggbb'
    'reason'   => string|null,    // 'missing'|'unknown-form'|'unknown-preset'|null
]
```

Any unresolved side in an evaluated pair downgrades the suggestion with a
scope-specific reason.

## Math

WCAG 2.1 relative-luminance formula on sRGB:

```
L = 0.2126 * R + 0.7152 * G + 0.0722 * B
where each channel = (c/255 ≤ 0.03928) ? (c/255)/12.92 : ((c/255 + 0.055)/1.055)^2.4
```

Contrast ratio:

```
ratio = (L1 + 0.05) / (L2 + 0.05)   // L1 = lighter, L2 = darker
```

Single threshold for v1: **4.5:1 across all scopes**. Suggestions whose
evaluated pair ratio is `< 4.5` fail.

## Description Annotation

### Three Canonical Prefixes

| Trigger | Prefix |
| --- | --- |
| Op dropped during structural validation (Stage A) | `Validation:` |
| Contrast input could not be resolved | `Contrast check unavailable:` |
| Contrast resolved and ratio below 4.5:1 | `Contrast check:` |

### Trigger Priority

When multiple triggers fire on a single suggestion, append only the first by
this order:

1. `Validation:` — when `operation_dropped`
2. `Contrast check unavailable:` — when `contrast_result['kind'] === 'unavailable'`
3. `Contrast check:` — when `contrast_result['kind'] === 'low_ratio'`

Rationale: contrast on a partial operation set could be misleading, so
structural validation reasons take precedence.

### Format Examples

```
Validation: 1 of 2 operations could not be applied safely at this scope.
Contrast check unavailable: unresolved background at root.
Contrast check: 3.2:1 between "accent" and "base" at root, below the 4.5:1 minimum.
```

When multiple scopes fail in the same suggestion, report only the first by
enum order: `root`, `elements.button`, `elements.link`, `elements.heading`,
then `blocks.<name>` alphabetical.

### Dedup Rule

Skip append only if `description` already contains one of the three exact
canonical prefixes. Broad keyword matches like "contrast" do not count — the
LLM may have written "Improve contrast with the accent palette" precisely
when the user still needs to know why the suggestion became advisory.

### Sanitization and i18n

All prefix strings wrap in `__( ..., 'flavor-agent' )` and the full
annotation runs through `sanitize_text_field()` before append. `sprintf`
templates use positional placeholders so translators can reorder. Concrete
templates:

```php
__( 'Validation: %1$d of %2$d operations could not be applied safely at this scope.', 'flavor-agent' )
__( 'Contrast check unavailable: unresolved %1$s at %2$s.', 'flavor-agent' )
__( 'Contrast check: %1$s:1 between "%2$s" and "%3$s" at %4$s, below the 4.5:1 minimum.', 'flavor-agent' )
```

The ratio is formatted to one decimal place. Slug names are wrapped in
double quotes for readability and run through `sanitize_text_field()` before
substitution.

## Prompt Update

Add to `StylePrompt::build_system()` after the existing rule block, before
the few-shot examples:

> When recommending color changes, prefer pairing foreground and background
> operations together at the same scope so the resulting contrast can be
> validated. Solo color operations may be downgraded to advisory if the
> resulting pair fails contrast against the existing complement.

This nudges the model toward emitting paired ops so the validator can
evaluate the intended pair directly. Solo color ops remain structurally
valid (Stage A's drop guard does not fire on a single valid op); the
validator evaluates them against the merged complement at the same scope
per the Within-Scope Resolution rule, and downgrades the suggestion to
advisory only if the resulting pair fails contrast or cannot be resolved.

## Test Matrix

### Resolver Unit Tests (`StyleContrastValidatorTest`)

- Form 1 happy path → resolves to hex
- Form 2 happy path → resolves to hex
- Form 1 unknown slug → `unknown-preset`
- Form 2 unknown slug → `unknown-preset`
- Form 1 slug present but empty `color` → `unknown-preset`
- Form 3 `#rrggbb` → use directly
- Form 3 `#rrggbbaa` → truncate alpha
- Form 4 null/missing → `missing`
- Form 5 each variant: named color, `rgb()`, `hsl()`, `currentColor`,
  `transparent`, gradient, `inherit` → `unknown-form`

### Math Unit Tests

- Black on white → 21:1 (passes)
- White on white → 1:1 (fails)
- At threshold 4.5:1 (passes — boundary case)
- Just below 4.49:1 (fails)
- Channel-wise: sRGB linearization branch (luminance < 0.03928 vs ≥)

### Pair Grouping Unit Tests

- Two ops at same `root` scope → paired internally
- Two ops at same `elements.button` scope → paired internally
- Two ops at different scopes → not paired (each evaluated independently)
- `border.color` op → skipped (no effect on tone)
- Op at scope ending in `color.text` not in enum (e.g.
  `elements.caption.color.text`) → fail closed
- `blocks.core/paragraph` and `blocks.core/button` ops in same suggestion →
  distinct scopes, no cross-pairing

### Solo + Complement Unit Tests

- Solo root op + merged complement (preset form) → resolves and evaluates
- Solo `elements.button` op + `elementStyles` complement (CSS var form) →
  resolves and evaluates
- Solo block op + `mergedConfig.styles.blocks.<name>.color.*` complement →
  resolves and evaluates
- Solo op with no recorded complement at any source → `missing` →
  fail-closed advisory

### Integration Tests (`StylePromptTest`)

- Pure-pass executable suggestion stays executable
- Single-scope ratio failure → tone advisory, ops blanked,
  `Contrast check:` prefix appended
- Single-scope unresolved → tone advisory, ops blanked,
  `Contrast check unavailable:` prefix appended
- Multiple failures → first by enum order in description
- Existing `Validation:` prefix in description → skip append
- Existing `Contrast check:` prefix in description → skip append
- Op-drop AND contrast fail → `Validation:` wins (priority order)
- Description has the word "contrast" but not canonical prefix → still
  append (dedup is by canonical prefix, not keyword)

### Metadata Tests

- Downgraded suggestion has `tone_advisory` source signal, no
  `has_operations` source signal
- Downgraded suggestion's score weight uses `$effective_operations` not
  `$operations` (no false 0.15 boost)

### E2E (WP 7.0 — `tests/e2e/flavor-agent.smoke.spec.js`)

E2E coverage at the smoke layer is **UI evidence only**. The PHP validator
is exercised by the integration tests above; the smoke harness mocks REST
responses and so cannot trigger the validator's downgrade decision (Stage B
has no JS contrast guard by design — a low-contrast executable mock would
flow through to the editor unchanged).

One new test under the existing Global Styles block mocks a server response
that already represents a downgraded suggestion: `tone: 'advisory'`,
`operations: []`, and a `description` already containing the canonical
`Contrast check:` annotation. The test asserts the editor renders the
suggestion in the advisory lane (not executable), shows the annotation in
the visible description, and offers no apply button. This is
end-to-end UI evidence that the advisory presentation works, not a check
on the validator itself.

## Verification After Implementation

- `npm run test:unit -- --runInBand` (Jest)
- `composer test:php` (PHPUnit, including the new `StyleContrastValidatorTest`
  and updated `StylePromptTest`)
- `node scripts/verify.js --skip-e2e` and inspect `output/verify/summary.json`
- `npm run check:docs` (this design doc + surface doc were already touched)
- `npm run test:e2e:wp70` for the new advisory flow
- Cross-surface validation gates per `docs/reference/cross-surface-validation-gates.md`

## Surface Doc Reference

See `docs/reference/surfaces/global-styles.md` § "Stage B Design Commitments"
for the high-level commitments this design implements.

## Out-of-Band Followups (Post-Stage-B)

- **Per-scope contrast failure diagnostics for activity log.** v1 surfaces
  only the first failure via `kind`/`reason`/`ratio`. A follow-up could
  expand the validator return with a `failures` array (one entry per scope:
  `{ scope, kind, reason, ratio, foreground, background }`) and extend
  `inc/Activity/Repository.php` plus `inc/Activity/Serializer.php` to
  persist it for the admin audit view. Keeping v1 narrow avoids a
  half-promised diagnostic field with no consumer.
- AAA threshold support (would add a per-suggestion `level` field and a
  configurable threshold).
- Large-text size detection from typography ops (would relax to 3:1 when
  font-size meets the WCAG large-text definition).
- Color-blindness simulation (separate validator, not just contrast).
- `set_theme_variation` cross-pair contrast evaluation (deferred as
  theme-authored).
- JS-side contrast helper if a future Block Inspector surface needs it
  (Stage B is server-only by design).
