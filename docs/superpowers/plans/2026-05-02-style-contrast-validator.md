# Style Contrast Validator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a server-authoritative deterministic WCAG AA contrast validator that downgrades low-contrast or unverifiable style suggestions to advisory before they reach the editor.

**Architecture:** New `FlavorAgent\LLM\StyleContrastValidator` invoked from `StylePrompt::validate_suggestions()` after structural validation. Returns `[passed, kind, reason, ratio]`. Caller uses one downgrade path for both Stage A op-drops and Stage B contrast failures via a new `$effective_operations` variable that also corrects the existing has-operations metadata caveat. Single-line description annotation with three canonical prefixes and dedup-by-prefix. No JS-side utility (existing review/resolved signature machinery already covers palette drift).

**Tech Stack:** PHP 8.0+, PHPUnit, WordPress 7.0+, `@wordpress/scripts` (Jest for any JS tests), Playwright (for the WP 7.0 UI-evidence test). Spec at `docs/superpowers/specs/2026-05-02-style-contrast-validator-design.md`. Related surface doc at `docs/reference/surfaces/global-styles.md`.

---

## File Map

**Create:**
- `inc/LLM/StyleContrastValidator.php` — primary public entry point `evaluate( array $operations, array $context ): array`. Public pure helpers are also exposed for granular unit testing and potential reuse by future surfaces: `resolve_color_value()`, `contrast_ratio()`, `scope_key_for_operation()`, `merged_complement_hex()`. They are stateless and side-effect-free; callers that only need the suggestion-level decision should still go through `evaluate()`.
- `tests/phpunit/StyleContrastValidatorTest.php` — unit tests for resolver, math, scope extraction, pair grouping, complement resolution, and the orchestrator

**Modify:**
- `inc/LLM/StylePrompt.php` — wire validator into `validate_suggestions()`; introduce `$effective_operations`; fix has-operations metadata caveat; add prompt paragraph to `build_system()`; add description-annotation helper
- `tests/phpunit/StylePromptTest.php` — add integration tests covering the three-prefix annotation, trigger priority, dedup, and the metadata-caveat fix
- `tests/e2e/flavor-agent.smoke.spec.js` — one new UI-evidence-only test that mocks an already-downgraded server response and asserts the advisory presentation

**Reference (do not modify):**
- `docs/reference/surfaces/global-styles.md` § "Stage B Design Commitments" (contract source)
- `inc/Context/ThemeTokenCollector.php::for_tokens()` (preset list shape)
- `inc/Abilities/StyleAbilities.php::build_review_context_signature()` (freshness machinery)

---

## Task 1: Scaffold validator + Form 3 hex resolution test

**Files:**
- Create: `inc/LLM/StyleContrastValidator.php`
- Create: `tests/phpunit/StyleContrastValidatorTest.php`

- [ ] **Step 1: Write the failing test for direct hex resolution (Form 3)**

Create `tests/phpunit/StyleContrastValidatorTest.php`:

```php
<?php

declare(strict_types=1);

use FlavorAgent\LLM\StyleContrastValidator;
use PHPUnit\Framework\TestCase;

final class StyleContrastValidatorTest extends TestCase {

    public function test_resolver_form_3_accepts_hex_value(): void {
        $resolved = StyleContrastValidator::resolve_color_value(
            '#112233',
            [ 'colorPresets' => [] ]
        );

        $this->assertSame(
            [ 'resolved' => true, 'hex' => '#112233', 'reason' => null ],
            $resolved
        );
    }

    public function test_resolver_form_3_truncates_alpha_channel(): void {
        $resolved = StyleContrastValidator::resolve_color_value(
            '#11223344',
            [ 'colorPresets' => [] ]
        );

        $this->assertSame(
            [ 'resolved' => true, 'hex' => '#112233', 'reason' => null ],
            $resolved
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: FAIL with "Class FlavorAgent\\LLM\\StyleContrastValidator not found".

- [ ] **Step 3: Write minimal implementation**

Create `inc/LLM/StyleContrastValidator.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class StyleContrastValidator {

    public static function evaluate( array $operations, array $context ): array {
        return [
            'passed' => true,
            'kind'   => null,
            'reason' => null,
            'ratio'  => null,
        ];
    }

    /**
     * @param array<string, mixed> $theme_tokens
     * @return array{resolved: bool, hex: string|null, reason: string|null}
     */
    public static function resolve_color_value( mixed $value, array $theme_tokens ): array {
        if ( is_string( $value ) && preg_match( '/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $value ) === 1 ) {
            return [
                'resolved' => true,
                'hex'      => strtolower( substr( $value, 0, 7 ) ),
                'reason'   => null,
            ];
        }

        return [
            'resolved' => false,
            'hex'      => null,
            'reason'   => 'unknown-form',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: PASS (2 tests, 2 assertions).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StyleContrastValidator.php tests/phpunit/StyleContrastValidatorTest.php
git commit -m "feat(style-contrast): scaffold validator with Form 3 hex resolution"
```

---

## Task 2: Resolver — Forms 1 & 2 (preset references) + unknown-preset

**Files:**
- Modify: `inc/LLM/StyleContrastValidator.php`
- Modify: `tests/phpunit/StyleContrastValidatorTest.php`

- [ ] **Step 1: Add failing tests for preset reference forms**

Append to `tests/phpunit/StyleContrastValidatorTest.php` inside the class:

```php
public function test_resolver_form_1_resolves_flavor_agent_preset_reference(): void {
    $resolved = StyleContrastValidator::resolve_color_value(
        'var:preset|color|accent',
        [ 'colorPresets' => [
            [ 'slug' => 'base',   'color' => '#ffffff' ],
            [ 'slug' => 'accent', 'color' => '#aabbcc' ],
        ] ]
    );

    $this->assertSame(
        [ 'resolved' => true, 'hex' => '#aabbcc', 'reason' => null ],
        $resolved
    );
}

public function test_resolver_form_2_resolves_wp_css_var_preset_reference(): void {
    $resolved = StyleContrastValidator::resolve_color_value(
        'var(--wp--preset--color--accent)',
        [ 'colorPresets' => [
            [ 'slug' => 'accent', 'color' => '#aabbcc' ],
        ] ]
    );

    $this->assertSame(
        [ 'resolved' => true, 'hex' => '#aabbcc', 'reason' => null ],
        $resolved
    );
}

public function test_resolver_form_1_unknown_slug_returns_unknown_preset(): void {
    $resolved = StyleContrastValidator::resolve_color_value(
        'var:preset|color|nope',
        [ 'colorPresets' => [
            [ 'slug' => 'accent', 'color' => '#aabbcc' ],
        ] ]
    );

    $this->assertSame(
        [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-preset' ],
        $resolved
    );
}

public function test_resolver_form_2_unknown_slug_returns_unknown_preset(): void {
    $resolved = StyleContrastValidator::resolve_color_value(
        'var(--wp--preset--color--nope)',
        [ 'colorPresets' => [
            [ 'slug' => 'accent', 'color' => '#aabbcc' ],
        ] ]
    );

    $this->assertSame(
        [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-preset' ],
        $resolved
    );
}

public function test_resolver_form_1_empty_preset_color_returns_unknown_preset(): void {
    $resolved = StyleContrastValidator::resolve_color_value(
        'var:preset|color|accent',
        [ 'colorPresets' => [
            [ 'slug' => 'accent', 'color' => '' ],
        ] ]
    );

    $this->assertSame(
        [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-preset' ],
        $resolved
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: 5 new FAIL lines (preset paths return `unknown-form` instead of resolving).

- [ ] **Step 3: Implement preset resolution with slug index**

In `inc/LLM/StyleContrastValidator.php`, replace `resolve_color_value` and add a private helper:

```php
public static function resolve_color_value( mixed $value, array $theme_tokens ): array {
    if ( ! is_string( $value ) || $value === '' ) {
        return [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-form' ];
    }

    if ( preg_match( '/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $value ) === 1 ) {
        return [
            'resolved' => true,
            'hex'      => strtolower( substr( $value, 0, 7 ) ),
            'reason'   => null,
        ];
    }

    if ( preg_match( '/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $m ) === 1 ) {
        return self::resolve_preset_slug( sanitize_key( $m[1] ), $theme_tokens );
    }

    if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i', $value, $m ) === 1 ) {
        return self::resolve_preset_slug( sanitize_key( $m[1] ), $theme_tokens );
    }

    return [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-form' ];
}

/**
 * @param array<string, mixed> $theme_tokens
 * @return array{resolved: bool, hex: string|null, reason: string|null}
 */
private static function resolve_preset_slug( string $slug, array $theme_tokens ): array {
    $index = self::build_preset_index( $theme_tokens );
    $hex   = $index[ $slug ] ?? '';

    if ( $hex === '' ) {
        return [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-preset' ];
    }

    if ( preg_match( '/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $hex ) !== 1 ) {
        return [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-preset' ];
    }

    return [
        'resolved' => true,
        'hex'      => strtolower( substr( $hex, 0, 7 ) ),
        'reason'   => null,
    ];
}

/**
 * @param array<string, mixed> $theme_tokens
 * @return array<string, string>
 */
private static function build_preset_index( array $theme_tokens ): array {
    $presets = is_array( $theme_tokens['colorPresets'] ?? null ) ? $theme_tokens['colorPresets'] : [];
    $index   = [];

    foreach ( $presets as $preset ) {
        if ( ! is_array( $preset ) ) {
            continue;
        }

        $slug  = sanitize_key( (string) ( $preset['slug'] ?? '' ) );
        $color = (string) ( $preset['color'] ?? '' );

        if ( $slug === '' ) {
            continue;
        }

        $index[ $slug ] = $color;
    }

    return $index;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: PASS (7 tests, 7 assertions).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StyleContrastValidator.php tests/phpunit/StyleContrastValidatorTest.php
git commit -m "feat(style-contrast): resolve preset references via slug index"
```

---

## Task 3: Resolver — Forms 4 & 5 (missing + unknown-form)

**Files:**
- Modify: `tests/phpunit/StyleContrastValidatorTest.php`

- [ ] **Step 1: Add failing tests for missing and unknown forms**

Append to `tests/phpunit/StyleContrastValidatorTest.php`:

```php
public function test_resolver_form_4_null_returns_missing(): void {
    $resolved = StyleContrastValidator::resolve_color_value( null, [ 'colorPresets' => [] ] );
    $this->assertSame(
        [ 'resolved' => false, 'hex' => null, 'reason' => 'missing' ],
        $resolved
    );
}

public function test_resolver_form_4_empty_string_returns_missing(): void {
    $resolved = StyleContrastValidator::resolve_color_value( '', [ 'colorPresets' => [] ] );
    $this->assertSame(
        [ 'resolved' => false, 'hex' => null, 'reason' => 'missing' ],
        $resolved
    );
}

/**
 * @dataProvider provide_form_5_values
 */
public function test_resolver_form_5_returns_unknown_form( mixed $value ): void {
    $resolved = StyleContrastValidator::resolve_color_value( $value, [ 'colorPresets' => [] ] );
    $this->assertSame(
        [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-form' ],
        $resolved
    );
}

public static function provide_form_5_values(): array {
    return [
        'named color'   => [ 'red' ],
        'rgb function'  => [ 'rgb(0, 0, 0)' ],
        'hsl function'  => [ 'hsl(0, 0%, 0%)' ],
        'rgba function' => [ 'rgba(0, 0, 0, 0.5)' ],
        'currentColor'  => [ 'currentColor' ],
        'inherit'       => [ 'inherit' ],
        'transparent'   => [ 'transparent' ],
        'gradient'      => [ 'linear-gradient(to right, #000, #fff)' ],
        'numeric'       => [ 0 ],
        'array'         => [ [ '#000000' ] ],
    ];
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: 12 FAIL lines (current code returns `unknown-form` for null/empty instead of `missing`; arrays/numbers aren't currently caught).

- [ ] **Step 3: Update `resolve_color_value` to distinguish missing from unknown-form**

In `inc/LLM/StyleContrastValidator.php`, replace the leading guard of `resolve_color_value`:

```php
public static function resolve_color_value( mixed $value, array $theme_tokens ): array {
    if ( $value === null || $value === '' ) {
        return [ 'resolved' => false, 'hex' => null, 'reason' => 'missing' ];
    }

    if ( ! is_string( $value ) ) {
        return [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-form' ];
    }

    if ( preg_match( '/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $value ) === 1 ) {
        return [
            'resolved' => true,
            'hex'      => strtolower( substr( $value, 0, 7 ) ),
            'reason'   => null,
        ];
    }

    if ( preg_match( '/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $m ) === 1 ) {
        return self::resolve_preset_slug( sanitize_key( $m[1] ), $theme_tokens );
    }

    if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i', $value, $m ) === 1 ) {
        return self::resolve_preset_slug( sanitize_key( $m[1] ), $theme_tokens );
    }

    return [ 'resolved' => false, 'hex' => null, 'reason' => 'unknown-form' ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: PASS (19 tests, 19 assertions).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StyleContrastValidator.php tests/phpunit/StyleContrastValidatorTest.php
git commit -m "feat(style-contrast): distinguish missing from unknown-form values"
```

---

## Task 4: WCAG math — relative luminance + contrast ratio

**Files:**
- Modify: `inc/LLM/StyleContrastValidator.php`
- Modify: `tests/phpunit/StyleContrastValidatorTest.php`

- [ ] **Step 1: Add failing tests for the contrast-ratio function**

Append to `tests/phpunit/StyleContrastValidatorTest.php`:

```php
public function test_contrast_ratio_black_on_white_is_21(): void {
    $ratio = StyleContrastValidator::contrast_ratio( '#000000', '#ffffff' );
    $this->assertEqualsWithDelta( 21.0, $ratio, 0.01 );
}

public function test_contrast_ratio_same_color_is_1(): void {
    $ratio = StyleContrastValidator::contrast_ratio( '#777777', '#777777' );
    $this->assertEqualsWithDelta( 1.0, $ratio, 0.01 );
}

public function test_contrast_ratio_is_symmetric(): void {
    $forward  = StyleContrastValidator::contrast_ratio( '#112233', '#ddeeff' );
    $backward = StyleContrastValidator::contrast_ratio( '#ddeeff', '#112233' );
    $this->assertEqualsWithDelta( $forward, $backward, 0.0001 );
}

public function test_contrast_ratio_low_contrast_pair(): void {
    // gray-on-gray, well below 4.5:1
    $ratio = StyleContrastValidator::contrast_ratio( '#888888', '#aaaaaa' );
    $this->assertLessThan( 4.5, $ratio );
}

public function test_contrast_ratio_at_threshold(): void {
    // #767676 on #ffffff is approximately 4.54:1 — the standard "just passes" example
    $ratio = StyleContrastValidator::contrast_ratio( '#767676', '#ffffff' );
    $this->assertGreaterThanOrEqual( 4.5, $ratio );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: 5 FAIL lines ("Method contrast_ratio not found").

- [ ] **Step 3: Implement WCAG relative luminance and contrast ratio**

Add to `inc/LLM/StyleContrastValidator.php`:

```php
public static function contrast_ratio( string $hex_a, string $hex_b ): float {
    $l_a = self::relative_luminance( $hex_a );
    $l_b = self::relative_luminance( $hex_b );

    $lighter = max( $l_a, $l_b );
    $darker  = min( $l_a, $l_b );

    return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

private static function relative_luminance( string $hex ): float {
    [ $r, $g, $b ] = self::hex_to_srgb_channels( $hex );

    $r_lin = self::linearize_channel( $r );
    $g_lin = self::linearize_channel( $g );
    $b_lin = self::linearize_channel( $b );

    return 0.2126 * $r_lin + 0.7152 * $g_lin + 0.0722 * $b_lin;
}

/**
 * @return array{0: float, 1: float, 2: float}
 */
private static function hex_to_srgb_channels( string $hex ): array {
    $hex = ltrim( $hex, '#' );

    return [
        (float) hexdec( substr( $hex, 0, 2 ) ) / 255.0,
        (float) hexdec( substr( $hex, 2, 2 ) ) / 255.0,
        (float) hexdec( substr( $hex, 4, 2 ) ) / 255.0,
    ];
}

private static function linearize_channel( float $channel ): float {
    return $channel <= 0.03928
        ? $channel / 12.92
        : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: PASS (24 tests, 24 assertions).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StyleContrastValidator.php tests/phpunit/StyleContrastValidatorTest.php
git commit -m "feat(style-contrast): WCAG 2.1 relative luminance and contrast ratio"
```

---

## Task 5: Scope key extraction from operation paths

**Files:**
- Modify: `inc/LLM/StyleContrastValidator.php`
- Modify: `tests/phpunit/StyleContrastValidatorTest.php`

- [ ] **Step 1: Add failing tests for scope-key extraction**

Append to `tests/phpunit/StyleContrastValidatorTest.php`:

```php
public function test_scope_key_root_color_text(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'color', 'text' ],
    ] );
    $this->assertSame( 'root', $key );
}

public function test_scope_key_root_color_background(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'color', 'background' ],
    ] );
    $this->assertSame( 'root', $key );
}

public function test_scope_key_elements_button(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'elements', 'button', 'color', 'text' ],
    ] );
    $this->assertSame( 'elements.button', $key );
}

public function test_scope_key_elements_link(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'elements', 'link', 'color', 'text' ],
    ] );
    $this->assertSame( 'elements.link', $key );
}

public function test_scope_key_elements_heading(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'elements', 'heading', 'color', 'text' ],
    ] );
    $this->assertSame( 'elements.heading', $key );
}

public function test_scope_key_block_styles(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type'      => 'set_block_styles',
        'blockName' => 'core/paragraph',
        'path'      => [ 'color', 'background' ],
    ] );
    $this->assertSame( 'blocks.core/paragraph', $key );
}

public function test_scope_key_border_color_returns_null(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'border', 'color' ],
    ] );
    $this->assertNull( $key );
}

public function test_scope_key_unknown_readable_path_returns_unsupported_marker(): void {
    // elements.caption is not in the enum but the path looks like a readable color leaf.
    // Returns the literal sentinel `'unsupported'` so the caller can fail closed.
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'elements', 'caption', 'color', 'text' ],
    ] );
    $this->assertSame( 'unsupported', $key );
}

public function test_scope_key_typography_op_returns_null(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type' => 'set_styles',
        'path' => [ 'typography', 'fontSize' ],
    ] );
    $this->assertNull( $key );
}

public function test_scope_key_set_theme_variation_returns_null(): void {
    $key = StyleContrastValidator::scope_key_for_operation( [
        'type'           => 'set_theme_variation',
        'variationIndex' => 1,
    ] );
    $this->assertNull( $key );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: 10 FAIL lines ("Method scope_key_for_operation not found").

- [ ] **Step 3: Implement scope-key extraction**

Add to `inc/LLM/StyleContrastValidator.php`:

```php
private const SUPPORTED_ELEMENTS = [ 'button', 'link', 'heading' ];

/**
 * @param array<string, mixed> $operation
 */
public static function scope_key_for_operation( array $operation ): ?string {
    $type = sanitize_key( (string) ( $operation['type'] ?? '' ) );
    $path = is_array( $operation['path'] ?? null ) ? array_values( $operation['path'] ) : [];

    if ( $type === 'set_block_styles' ) {
        if ( ! self::path_ends_in_color_pair_leaf( $path ) ) {
            return null;
        }

        $block_name = sanitize_text_field( (string) ( $operation['blockName'] ?? '' ) );

        return $block_name !== '' ? 'blocks.' . $block_name : null;
    }

    if ( $type !== 'set_styles' ) {
        return null;
    }

    if ( ! self::path_ends_in_color_pair_leaf( $path ) ) {
        return null;
    }

    if ( count( $path ) === 2 ) {
        return 'root';
    }

    if ( count( $path ) === 4 && $path[0] === 'elements' ) {
        $element = sanitize_key( (string) $path[1] );

        if ( in_array( $element, self::SUPPORTED_ELEMENTS, true ) ) {
            return 'elements.' . $element;
        }

        return 'unsupported';
    }

    return 'unsupported';
}

/**
 * @param array<int, mixed> $path
 */
private static function path_ends_in_color_pair_leaf( array $path ): bool {
    if ( count( $path ) < 2 ) {
        return false;
    }

    $tail = array_slice( $path, -2 );

    return $tail[0] === 'color' && in_array( $tail[1], [ 'text', 'background' ], true );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: PASS (34 tests, 34 assertions).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StyleContrastValidator.php tests/phpunit/StyleContrastValidatorTest.php
git commit -m "feat(style-contrast): scope-key extraction with unsupported-path sentinel"
```

---

## Task 6: Within-scope resolution + complement fallback chain

**Files:**
- Modify: `inc/LLM/StyleContrastValidator.php`
- Modify: `tests/phpunit/StyleContrastValidatorTest.php`

- [ ] **Step 1: Add failing tests for complement lookup**

Append to `tests/phpunit/StyleContrastValidatorTest.php`:

```php
public function test_complement_root_uses_merged_styles_color_branch(): void {
    $context = [
        'styleContext' => [
            'mergedConfig' => [ 'styles' => [ 'color' => [ 'background' => '#ffffff' ] ] ],
        ],
    ];

    $hex = StyleContrastValidator::merged_complement_hex( 'root', 'background', $context );

    $this->assertSame( '#ffffff', $hex );
}

public function test_complement_element_button_uses_element_styles_then_root(): void {
    $context = [
        'styleContext' => [
            'themeTokens'  => [
                'colorPresets'  => [ [ 'slug' => 'accent', 'color' => '#aabbcc' ] ],
                'elementStyles' => [
                    'button' => [
                        'base' => [ 'background' => 'var(--wp--preset--color--accent)' ],
                    ],
                ],
            ],
            'mergedConfig' => [
                'styles' => [ 'color' => [ 'background' => '#ffffff' ] ],
            ],
        ],
    ];

    $hex = StyleContrastValidator::merged_complement_hex( 'elements.button', 'background', $context );

    $this->assertSame( '#aabbcc', $hex );
}

public function test_complement_element_falls_back_to_root_when_missing(): void {
    $context = [
        'styleContext' => [
            'themeTokens'  => [
                'colorPresets'  => [],
                'elementStyles' => [
                    'link' => [ 'base' => [ 'text' => '#0000ff' ] ],
                ],
            ],
            'mergedConfig' => [
                'styles' => [ 'color' => [ 'background' => '#ffffff' ] ],
            ],
        ],
    ];

    $hex = StyleContrastValidator::merged_complement_hex( 'elements.link', 'background', $context );

    $this->assertSame( '#ffffff', $hex );
}

public function test_complement_block_uses_block_branch_then_root(): void {
    $context = [
        'styleContext' => [
            'themeTokens'  => [ 'colorPresets' => [] ],
            'mergedConfig' => [
                'styles' => [
                    'color'  => [ 'background' => '#ffffff' ],
                    'blocks' => [
                        'core/quote' => [ 'color' => [ 'background' => '#eeeeee' ] ],
                    ],
                ],
            ],
        ],
    ];

    $hex = StyleContrastValidator::merged_complement_hex( 'blocks.core/quote', 'background', $context );

    $this->assertSame( '#eeeeee', $hex );
}

public function test_complement_block_falls_back_to_root(): void {
    $context = [
        'styleContext' => [
            'themeTokens'  => [ 'colorPresets' => [] ],
            'mergedConfig' => [
                'styles' => [ 'color' => [ 'background' => '#ffffff' ] ],
            ],
        ],
    ];

    $hex = StyleContrastValidator::merged_complement_hex( 'blocks.core/paragraph', 'background', $context );

    $this->assertSame( '#ffffff', $hex );
}

public function test_complement_returns_null_when_all_sources_empty(): void {
    $context = [
        'styleContext' => [
            'themeTokens'  => [ 'colorPresets' => [] ],
            'mergedConfig' => [ 'styles' => [] ],
        ],
    ];

    $hex = StyleContrastValidator::merged_complement_hex( 'root', 'text', $context );

    $this->assertNull( $hex );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: 6 FAIL lines ("Method merged_complement_hex not found").

- [ ] **Step 3: Implement complement lookup with fallback chain**

Add to `inc/LLM/StyleContrastValidator.php`:

```php
/**
 * @param array<string, mixed> $context
 */
public static function merged_complement_hex( string $scope_key, string $side, array $context ): ?string {
    if ( ! in_array( $side, [ 'text', 'background' ], true ) ) {
        return null;
    }

    $style_context = is_array( $context['styleContext'] ?? null ) ? $context['styleContext'] : [];
    $theme_tokens  = is_array( $style_context['themeTokens'] ?? null ) ? $style_context['themeTokens'] : [];
    $merged        = is_array( $style_context['mergedConfig'] ?? null ) ? $style_context['mergedConfig'] : [];
    $merged_styles = is_array( $merged['styles'] ?? null ) ? $merged['styles'] : [];

    $candidate = self::scope_specific_complement_value( $scope_key, $side, $merged_styles, $theme_tokens );

    if ( $candidate !== null ) {
        $resolved = self::resolve_color_value( $candidate, $theme_tokens );

        if ( $resolved['resolved'] ) {
            return $resolved['hex'];
        }
    }

    $root_value = is_array( $merged_styles['color'] ?? null )
        ? ( $merged_styles['color'][ $side ] ?? null )
        : null;

    if ( $root_value !== null ) {
        $resolved = self::resolve_color_value( $root_value, $theme_tokens );

        if ( $resolved['resolved'] ) {
            return $resolved['hex'];
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $merged_styles
 * @param array<string, mixed> $theme_tokens
 */
private static function scope_specific_complement_value(
    string $scope_key,
    string $side,
    array $merged_styles,
    array $theme_tokens
): mixed {
    if ( $scope_key === 'root' ) {
        return null; // root falls through to the root-color branch below.
    }

    if ( str_starts_with( $scope_key, 'elements.' ) ) {
        $element        = substr( $scope_key, strlen( 'elements.' ) );
        $element_styles = is_array( $theme_tokens['elementStyles'][ $element ]['base'] ?? null )
            ? $theme_tokens['elementStyles'][ $element ]['base']
            : [];

        return $element_styles[ $side ] ?? null;
    }

    if ( str_starts_with( $scope_key, 'blocks.' ) ) {
        $block_name = substr( $scope_key, strlen( 'blocks.' ) );
        $block      = is_array( $merged_styles['blocks'][ $block_name ]['color'] ?? null )
            ? $merged_styles['blocks'][ $block_name ]['color']
            : [];

        return $block[ $side ] ?? null;
    }

    return null;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: PASS (40 tests, 40 assertions).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StyleContrastValidator.php tests/phpunit/StyleContrastValidatorTest.php
git commit -m "feat(style-contrast): merged complement lookup with root fallback"
```

---

## Task 7: evaluate() orchestration — pair grouping + first-failure projection

**Files:**
- Modify: `inc/LLM/StyleContrastValidator.php`
- Modify: `tests/phpunit/StyleContrastValidatorTest.php`

- [ ] **Step 1: Add failing tests for the public `evaluate()` orchestration**

Append to `tests/phpunit/StyleContrastValidatorTest.php`:

```php
private function context_with_palette(): array {
    return [
        'styleContext' => [
            'themeTokens' => [
                'colorPresets' => [
                    [ 'slug' => 'base',   'color' => '#ffffff' ],
                    [ 'slug' => 'accent', 'color' => '#222222' ],
                    [ 'slug' => 'wash',   'color' => '#dddddd' ],
                ],
                'elementStyles' => [],
            ],
            'mergedConfig' => [
                'styles' => [ 'color' => [ 'background' => '#ffffff', 'text' => '#000000' ] ],
            ],
        ],
    ];
}

public function test_evaluate_passes_high_contrast_pair(): void {
    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|base' ],
            [ 'type' => 'set_styles', 'path' => [ 'color', 'text' ],       'value' => 'var:preset|color|accent' ],
        ],
        $this->context_with_palette()
    );

    $this->assertTrue( $result['passed'] );
    $this->assertNull( $result['kind'] );
    $this->assertNull( $result['reason'] );
    $this->assertNull( $result['ratio'] );
}

public function test_evaluate_fails_low_contrast_pair(): void {
    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|wash' ],
            [ 'type' => 'set_styles', 'path' => [ 'color', 'text' ],       'value' => 'var:preset|color|base' ],
        ],
        $this->context_with_palette()
    );

    $this->assertFalse( $result['passed'] );
    $this->assertSame( 'low_ratio', $result['kind'] );
    $this->assertNotNull( $result['ratio'] );
    $this->assertLessThan( 4.5, $result['ratio'] );
    $this->assertStringContainsString( 'root', $result['reason'] );
}

public function test_evaluate_solo_op_evaluated_against_merged_complement(): void {
    $result = StyleContrastValidator::evaluate(
        [
            // Only background is proposed; text complement comes from mergedConfig (#000000 on #ffffff = 21:1).
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|base' ],
        ],
        $this->context_with_palette()
    );

    $this->assertTrue( $result['passed'] );
}

public function test_evaluate_solo_op_with_unresolved_complement_fails_unavailable(): void {
    $context = [
        'styleContext' => [
            'themeTokens'  => [ 'colorPresets' => [ [ 'slug' => 'base', 'color' => '#ffffff' ] ] ],
            'mergedConfig' => [ 'styles' => [] ],
        ],
    ];

    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|base' ],
        ],
        $context
    );

    $this->assertFalse( $result['passed'] );
    $this->assertSame( 'unavailable', $result['kind'] );
    $this->assertNull( $result['ratio'] );
    $this->assertStringContainsString( 'root', $result['reason'] );
}

public function test_evaluate_uses_last_write_when_same_scope_side_is_written_twice(): void {
    // Apply path is last-write-wins: writing background twice means the SECOND
    // value is what the user sees. The validator must agree with the applier.
    $context = $this->context_with_palette();

    $result = StyleContrastValidator::evaluate(
        [
            // High-contrast first write (passes if evaluated alone)
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|base' ],
            // Low-contrast overwrite (the actual rendered state)
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|wash' ],
            // Text op (paired against the LAST background)
            [ 'type' => 'set_styles', 'path' => [ 'color', 'text' ], 'value' => 'var:preset|color|base' ],
        ],
        $context
    );

    $this->assertFalse( $result['passed'] );
    $this->assertSame( 'low_ratio', $result['kind'] );
    $this->assertStringContainsString( 'wash', $result['reason'] );
}

public function test_evaluate_proposed_op_with_unresolved_value_fails_unavailable(): void {
    // Critical: a proposed op whose value cannot be resolved MUST fail unavailable;
    // the validator must NOT silently substitute the merged complement for the
    // proposed-but-broken value.
    $context = [
        'styleContext' => [
            'themeTokens'  => [
                'colorPresets'  => [ [ 'slug' => 'base', 'color' => '#ffffff' ] ],
                'elementStyles' => [],
            ],
            'mergedConfig' => [ 'styles' => [ 'color' => [ 'background' => '#ffffff', 'text' => '#000000' ] ] ],
        ],
    ];

    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|nope' ],
        ],
        $context
    );

    $this->assertFalse( $result['passed'] );
    $this->assertSame( 'unavailable', $result['kind'] );
    $this->assertStringContainsString( 'background', $result['reason'] );
    $this->assertStringContainsString( 'root', $result['reason'] );
}

public function test_evaluate_unsupported_readable_path_fails_unavailable(): void {
    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_styles', 'path' => [ 'elements', 'caption', 'color', 'text' ], 'value' => 'var:preset|color|base' ],
        ],
        $this->context_with_palette()
    );

    $this->assertFalse( $result['passed'] );
    $this->assertSame( 'unavailable', $result['kind'] );
}

public function test_evaluate_border_color_op_is_skipped(): void {
    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_styles', 'path' => [ 'border', 'color' ], 'value' => 'var:preset|color|base' ],
        ],
        $this->context_with_palette()
    );

    $this->assertTrue( $result['passed'] );
}

public function test_evaluate_first_failure_by_enum_order(): void {
    // Both root and elements.button fail; root must win (enum order).
    $context = [
        'styleContext' => [
            'themeTokens' => [
                'colorPresets' => [
                    [ 'slug' => 'base',  'color' => '#ffffff' ],
                    [ 'slug' => 'wash',  'color' => '#dddddd' ],
                ],
                'elementStyles' => [],
            ],
            'mergedConfig' => [
                'styles' => [ 'color' => [ 'background' => '#ffffff', 'text' => '#dddddd' ] ],
            ],
        ],
    ];

    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ],                       'value' => 'var:preset|color|wash' ],
            [ 'type' => 'set_styles', 'path' => [ 'color', 'text' ],                             'value' => 'var:preset|color|wash' ],
            [ 'type' => 'set_styles', 'path' => [ 'elements', 'button', 'color', 'background' ], 'value' => 'var:preset|color|wash' ],
        ],
        $context
    );

    $this->assertFalse( $result['passed'] );
    $this->assertStringContainsString( 'root', $result['reason'] );
}

public function test_evaluate_blocks_per_block_distinct(): void {
    // Two block scopes in same suggestion: paragraph fails, button passes.
    // The paragraph failure should be reported (alphabetical).
    $context = [
        'styleContext' => [
            'themeTokens' => [
                'colorPresets' => [
                    [ 'slug' => 'base', 'color' => '#ffffff' ],
                    [ 'slug' => 'wash', 'color' => '#dddddd' ],
                    [ 'slug' => 'dark', 'color' => '#111111' ],
                ],
                'elementStyles' => [],
            ],
            'mergedConfig' => [ 'styles' => [ 'color' => [ 'background' => '#ffffff', 'text' => '#000000' ] ] ],
        ],
    ];

    $result = StyleContrastValidator::evaluate(
        [
            [
                'type'      => 'set_block_styles',
                'blockName' => 'core/button',
                'path'      => [ 'color', 'background' ],
                'value'     => 'var:preset|color|dark',
            ],
            [
                'type'      => 'set_block_styles',
                'blockName' => 'core/button',
                'path'      => [ 'color', 'text' ],
                'value'     => 'var:preset|color|base',
            ],
            [
                'type'      => 'set_block_styles',
                'blockName' => 'core/paragraph',
                'path'      => [ 'color', 'background' ],
                'value'     => 'var:preset|color|wash',
            ],
            [
                'type'      => 'set_block_styles',
                'blockName' => 'core/paragraph',
                'path'      => [ 'color', 'text' ],
                'value'     => 'var:preset|color|base',
            ],
        ],
        $context
    );

    $this->assertFalse( $result['passed'] );
    $this->assertStringContainsString( 'core/paragraph', $result['reason'] );
}

public function test_evaluate_set_theme_variation_does_not_affect_passed(): void {
    $result = StyleContrastValidator::evaluate(
        [
            [ 'type' => 'set_theme_variation', 'variationIndex' => 1, 'variationTitle' => 'Midnight' ],
        ],
        $this->context_with_palette()
    );

    $this->assertTrue( $result['passed'] );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: 9 FAIL lines (current `evaluate()` always returns `passed=true`).

- [ ] **Step 3: Implement `evaluate()` orchestration**

Replace the placeholder `evaluate()` body in `inc/LLM/StyleContrastValidator.php`:

```php
private const ENUM_SCOPE_ORDER = [ 'root', 'elements.button', 'elements.link', 'elements.heading' ];
private const AA_THRESHOLD     = 4.5;

public static function evaluate( array $operations, array $context ): array {
    $style_context = is_array( $context['styleContext'] ?? null ) ? $context['styleContext'] : [];
    $theme_tokens  = is_array( $style_context['themeTokens'] ?? null ) ? $style_context['themeTokens'] : [];
    $groups        = []; // scope_key => [ 'background' => [op,...], 'text' => [op,...] ]
    $unsupported   = false;

    foreach ( $operations as $operation ) {
        if ( ! is_array( $operation ) ) {
            continue;
        }

        $scope_key = self::scope_key_for_operation( $operation );

        if ( $scope_key === null ) {
            continue;
        }

        if ( $scope_key === 'unsupported' ) {
            $unsupported = true;
            continue;
        }

        $path = is_array( $operation['path'] ?? null ) ? array_values( $operation['path'] ) : [];
        $side = (string) end( $path );

        if ( ! in_array( $side, [ 'text', 'background' ], true ) ) {
            continue;
        }

        $groups[ $scope_key ][ $side ][] = $operation;
    }

    if ( $unsupported ) {
        return [
            'passed' => false,
            'kind'   => 'unavailable',
            'reason' => __( 'Contrast check unavailable: unsupported readable color path.', 'flavor-agent' ),
            'ratio'  => null,
        ];
    }

    $ordered_scopes = self::scope_keys_in_enum_order( array_keys( $groups ) );

    foreach ( $ordered_scopes as $scope_key ) {
        $sides = $groups[ $scope_key ];

        // Last-write-wins. The applier writes operations in order, so the LAST
        // operation per scope/side is what the user actually sees. Evaluating the
        // first op would let a high-contrast pair pass while a later same-scope
        // duplicate write produced the actual low-contrast final state.
        $bg_ops        = $sides['background'] ?? [];
        $tx_ops        = $sides['text'] ?? [];
        $background_op = $bg_ops !== [] ? $bg_ops[ count( $bg_ops ) - 1 ] : null;
        $text_op       = $tx_ops !== [] ? $tx_ops[ count( $tx_ops ) - 1 ] : null;

        $bg_lookup = self::resolve_side_for_evaluation( $background_op, 'background', $scope_key, $context, $theme_tokens );

        if ( $bg_lookup['fail'] !== null ) {
            return $bg_lookup['fail'];
        }

        $tx_lookup = self::resolve_side_for_evaluation( $text_op, 'text', $scope_key, $context, $theme_tokens );

        if ( $tx_lookup['fail'] !== null ) {
            return $tx_lookup['fail'];
        }

        $ratio = self::contrast_ratio( $bg_lookup['hex'], $tx_lookup['hex'] );

        if ( $ratio < self::AA_THRESHOLD ) {
            $bg_label = self::label_for( $background_op, $bg_lookup['hex'] );
            $tx_label = self::label_for( $text_op, $tx_lookup['hex'] );

            return [
                'passed' => false,
                'kind'   => 'low_ratio',
                'reason' => sprintf(
                    /* translators: 1: ratio, 2: foreground label, 3: background label, 4: scope */
                    __( 'Contrast check: %1$s:1 between "%2$s" and "%3$s" at %4$s, below the 4.5:1 minimum.', 'flavor-agent' ),
                    number_format( $ratio, 1 ),
                    $tx_label,
                    $bg_label,
                    $scope_key
                ),
                'ratio'  => round( $ratio, 2 ),
            ];
        }
    }

    return [
        'passed' => true,
        'kind'   => null,
        'reason' => null,
        'ratio'  => null,
    ];
}

/**
 * Resolve one side of a pair to a hex, honouring the proposed-vs-absent distinction:
 * - Op proposed AND resolved → use the resolved hex.
 * - Op proposed AND unresolved → fail closed (unavailable). Do NOT fall back to merged complement;
 *   the user proposed a specific value and silently substituting the existing value would mask the failure.
 * - Op absent → look up the merged complement; if also absent, fail closed.
 *
 * @param array<string, mixed>|null $operation
 * @param array<string, mixed>      $context
 * @param array<string, mixed>      $theme_tokens
 *
 * @return array{hex: string|null, fail: array{passed: bool, kind: string, reason: string, ratio: null}|null}
 */
private static function resolve_side_for_evaluation(
    ?array $operation,
    string $side,
    string $scope_key,
    array $context,
    array $theme_tokens
): array {
    if ( $operation !== null ) {
        $resolved = self::resolve_color_value( $operation['value'] ?? null, $theme_tokens );

        if ( ! $resolved['resolved'] ) {
            return [
                'hex'  => null,
                'fail' => [
                    'passed' => false,
                    'kind'   => 'unavailable',
                    'reason' => sprintf(
                        /* translators: 1: side ('background' or 'text'), 2: scope key */
                        __( 'Contrast check unavailable: unresolved %1$s at %2$s.', 'flavor-agent' ),
                        $side,
                        $scope_key
                    ),
                    'ratio'  => null,
                ],
            ];
        }

        return [ 'hex' => $resolved['hex'], 'fail' => null ];
    }

    $hex = self::merged_complement_hex( $scope_key, $side, $context );

    if ( $hex === null ) {
        return [
            'hex'  => null,
            'fail' => [
                'passed' => false,
                'kind'   => 'unavailable',
                'reason' => sprintf(
                    /* translators: 1: side ('background' or 'text'), 2: scope key */
                    __( 'Contrast check unavailable: unresolved %1$s at %2$s.', 'flavor-agent' ),
                    $side,
                    $scope_key
                ),
                'ratio'  => null,
            ],
        ];
    }

    return [ 'hex' => $hex, 'fail' => null ];
}

/**
 * @param array<int, string> $scope_keys
 * @return array<int, string>
 */
private static function scope_keys_in_enum_order( array $scope_keys ): array {
    $known = [];
    $blocks = [];

    foreach ( $scope_keys as $key ) {
        if ( in_array( $key, self::ENUM_SCOPE_ORDER, true ) ) {
            $known[ array_search( $key, self::ENUM_SCOPE_ORDER, true ) ] = $key;
            continue;
        }

        if ( str_starts_with( $key, 'blocks.' ) ) {
            $blocks[] = $key;
        }
    }

    ksort( $known );
    sort( $blocks ); // alphabetical

    return array_merge( array_values( $known ), $blocks );
}

/**
 * @param array<string, mixed>|null $operation
 */
private static function label_for( ?array $operation, string $hex_fallback ): string {
    if ( is_array( $operation ) && is_string( $operation['value'] ?? null ) ) {
        $value = $operation['value'];

        if ( preg_match( '/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $m ) === 1 ) {
            return sanitize_text_field( $m[1] );
        }

        if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/i', $value, $m ) === 1 ) {
            return sanitize_text_field( $m[1] );
        }
    }

    return $hex_fallback;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter StyleContrastValidatorTest`
Expected: PASS (~51 tests, including proposed-but-unresolved and last-write-wins for duplicate same-scope writes).

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StyleContrastValidator.php tests/phpunit/StyleContrastValidatorTest.php
git commit -m "feat(style-contrast): evaluate() orchestrator with first-failure projection"
```

---

## Task 8: Integrate into StylePrompt::validate_suggestions() + fix metadata caveat

**Files:**
- Modify: `inc/LLM/StylePrompt.php`
- Modify: `tests/phpunit/StylePromptTest.php`

- [ ] **Step 1: Add failing integration tests covering the contrast downgrade and metadata fix**

Append to `tests/phpunit/StylePromptTest.php` inside the existing class:

```php
public function test_parse_response_downgrades_low_contrast_executable_suggestion_to_advisory(): void {
    $context = $this->build_global_styles_context_with_low_contrast_palette();
    $raw     = wp_json_encode( [
        'suggestions' => [
            [
                'label'       => 'Soft on soft',
                'description' => 'Use the wash on base.',
                'category'    => 'color',
                'tone'        => 'executable',
                'operations'  => [
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'background' ],
                        'value' => 'var:preset|color|wash',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'wash',
                    ],
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'text' ],
                        'value' => 'var:preset|color|base',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'base',
                    ],
                ],
            ],
        ],
        'explanation' => 'demo',
    ] );

    $parsed = \FlavorAgent\LLM\StylePrompt::parse_response( $raw, $context );

    $this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
    $this->assertSame( [], $parsed['suggestions'][0]['operations'] );
    $this->assertStringContainsString( 'Contrast check:', $parsed['suggestions'][0]['description'] );
}

public function test_parse_response_uses_validation_prefix_when_drop_and_contrast_both_fire(): void {
    $context = $this->build_global_styles_context_with_low_contrast_palette();
    $raw     = wp_json_encode( [
        'suggestions' => [
            [
                'label'       => 'Mixed bag',
                'description' => 'Two ops, one bad.',
                'category'    => 'color',
                'tone'        => 'executable',
                'operations'  => [
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'background' ],
                        'value' => 'var:preset|color|wash',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'wash',
                    ],
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'text' ],
                        'value' => 'var:preset|color|nonexistent',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'nonexistent',
                    ],
                ],
            ],
        ],
        'explanation' => 'demo',
    ] );

    $parsed = \FlavorAgent\LLM\StylePrompt::parse_response( $raw, $context );

    $this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
    $this->assertStringStartsWith( 'Two ops, one bad. Validation:', $parsed['suggestions'][0]['description'] );
    $this->assertStringNotContainsString( 'Contrast check', $parsed['suggestions'][0]['description'] );
}

public function test_parse_response_dedups_canonical_prefix_already_present(): void {
    $context = $this->build_global_styles_context_with_low_contrast_palette();
    $raw     = wp_json_encode( [
        'suggestions' => [
            [
                'label'       => 'Pre-annotated',
                'description' => 'Soft on soft. Contrast check: already noted.',
                'category'    => 'color',
                'tone'        => 'executable',
                'operations'  => [
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'background' ],
                        'value' => 'var:preset|color|wash',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'wash',
                    ],
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'text' ],
                        'value' => 'var:preset|color|base',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'base',
                    ],
                ],
            ],
        ],
        'explanation' => 'demo',
    ] );

    $parsed = \FlavorAgent\LLM\StylePrompt::parse_response( $raw, $context );

    $this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
    // Description should not contain TWO "Contrast check:" prefixes.
    $this->assertSame(
        1,
        substr_count( $parsed['suggestions'][0]['description'], 'Contrast check:' )
    );
}

public function test_parse_response_downgraded_suggestion_has_no_has_operations_signal(): void {
    $context = $this->build_global_styles_context_with_low_contrast_palette();
    $raw     = wp_json_encode( [
        'suggestions' => [
            [
                'label'       => 'Soft on soft',
                'description' => 'Use the wash on base.',
                'category'    => 'color',
                'tone'        => 'executable',
                'ranking'     => [ 'score' => 0.9 ],
                'operations'  => [
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'background' ],
                        'value' => 'var:preset|color|wash',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'wash',
                    ],
                    [
                        'type'  => 'set_styles',
                        'path'  => [ 'color', 'text' ],
                        'value' => 'var:preset|color|base',
                        'valueType' => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'base',
                    ],
                ],
            ],
        ],
        'explanation' => 'demo',
    ] );

    $parsed       = \FlavorAgent\LLM\StylePrompt::parse_response( $raw, $context );
    $signals      = $parsed['suggestions'][0]['ranking']['sourceSignals'] ?? [];

    $this->assertContains( 'tone_advisory', $signals );
    $this->assertNotContains( 'has_operations', $signals );
    $this->assertNotContains( 'tone_executable', $signals );
}

public function test_parse_response_uses_unavailable_prefix_when_contrast_inputs_unresolved(): void {
    // Solo background op + missing merged complement → 'unavailable' kind →
    // 'Contrast check unavailable:' prefix in description.
    $context = [
        'scope'        => [ 'surface' => 'global-styles', 'globalStylesId' => 'gs-1' ],
        'styleContext' => [
            'themeTokens' => [
                'colors'        => [ 'base: #ffffff' ],
                'colorPresets'  => [ [ 'slug' => 'base', 'color' => '#ffffff' ] ],
                'elementStyles' => [],
            ],
            'mergedConfig'        => [ 'styles' => [] ], // no complement available anywhere
            'currentConfig'       => [ 'styles' => [], 'settings' => [] ],
            'supportedStylePaths' => [
                [ 'path' => [ 'color', 'background' ], 'valueSource' => 'color' ],
                [ 'path' => [ 'color', 'text' ],       'valueSource' => 'color' ],
            ],
        ],
    ];
    $raw = wp_json_encode( [
        'suggestions' => [
            [
                'label'       => 'Solo background',
                'description' => 'Just background.',
                'category'    => 'color',
                'tone'        => 'executable',
                'operations'  => [
                    [
                        'type'       => 'set_styles',
                        'path'       => [ 'color', 'background' ],
                        'value'      => 'var:preset|color|base',
                        'valueType'  => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'base',
                    ],
                ],
            ],
        ],
        'explanation' => 'demo',
    ] );

    $parsed = \FlavorAgent\LLM\StylePrompt::parse_response( $raw, $context );

    $this->assertSame( 'advisory', $parsed['suggestions'][0]['tone'] );
    $this->assertSame( [], $parsed['suggestions'][0]['operations'] );
    $this->assertStringContainsString(
        'Contrast check unavailable:',
        $parsed['suggestions'][0]['description']
    );
}

public function test_parse_response_downgraded_suggestion_score_excludes_operations_boost(): void {
    // Without the metadata-caveat fix, the derived score would still credit
    // 'has_operations' (0.15 weight) and 'is_executable' (0.25 weight) for a
    // suggestion that ended up advisory. After the fix, those two weights drop.
    // Expected derived score for an advisory-downgraded suggestion with a
    // non-empty description and category: 0.45 (base) + 0.0 (not executable) +
    // 0.0 (no effective operations) + 0.1 (description) + 0.05 (category) = 0.60.
    $context = $this->build_global_styles_context_with_low_contrast_palette();
    $raw     = wp_json_encode( [
        'suggestions' => [
            [
                'label'       => 'Soft on soft',
                'description' => 'Use the wash on base.',
                'category'    => 'color',
                'tone'        => 'executable',
                // No explicit ranking → derived score path executes
                'operations'  => [
                    [
                        'type'       => 'set_styles',
                        'path'       => [ 'color', 'background' ],
                        'value'      => 'var:preset|color|wash',
                        'valueType'  => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'wash',
                    ],
                    [
                        'type'       => 'set_styles',
                        'path'       => [ 'color', 'text' ],
                        'value'      => 'var:preset|color|base',
                        'valueType'  => 'preset',
                        'presetType' => 'color',
                        'presetSlug' => 'base',
                    ],
                ],
            ],
        ],
        'explanation' => 'demo',
    ] );

    $parsed = \FlavorAgent\LLM\StylePrompt::parse_response( $raw, $context );
    $score  = $parsed['suggestions'][0]['ranking']['score'] ?? null;

    $this->assertNotNull( $score );
    $this->assertEqualsWithDelta( 0.60, (float) $score, 0.01 );
}

private function build_global_styles_context_with_low_contrast_palette(): array {
    return [
        'scope'        => [ 'surface' => 'global-styles', 'globalStylesId' => 'gs-1' ],
        'styleContext' => [
            'themeTokens' => [
                'colors' => [ 'base: #ffffff', 'wash: #dddddd' ],
                'colorPresets' => [
                    [ 'slug' => 'base', 'color' => '#ffffff' ],
                    [ 'slug' => 'wash', 'color' => '#dddddd' ],
                ],
                'elementStyles' => [],
            ],
            'mergedConfig'        => [ 'styles' => [ 'color' => [ 'background' => '#ffffff', 'text' => '#000000' ] ] ],
            'currentConfig'       => [ 'styles' => [], 'settings' => [] ],
            'supportedStylePaths' => [
                [ 'path' => [ 'color', 'background' ], 'valueSource' => 'color' ],
                [ 'path' => [ 'color', 'text' ],       'valueSource' => 'color' ],
            ],
        ],
    ];
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter StylePromptTest`
Expected: 6 new FAIL lines (current `validate_suggestions()` does not invoke the contrast validator and does not emit the canonical prefixes; the score test fails because the metadata caveat is not yet fixed).

- [ ] **Step 3: Wire validator into `validate_suggestions()` with metadata fix**

In `inc/LLM/StylePrompt.php`, locate the existing post-Stage-A block inside `validate_suggestions()` (around lines 745-790) and replace the segment that runs from `$input_operations  = is_array(...)` through the end of the entry assembly with:

```php
$input_operations  = is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [];
$operations        = self::validate_operations( $input_operations, $context );
$operation_dropped = count( $input_operations ) !== count( $operations );

$contrast_result = StyleContrastValidator::evaluate( $operations, $context );
$contrast_failed = ! $contrast_result['passed'];
$should_downgrade     = $operation_dropped || $contrast_failed;
$effective_operations = $should_downgrade ? [] : $operations;

$tone = ( 'executable' === sanitize_key( (string) ( $suggestion['tone'] ?? '' ) ) )
    && [] !== $effective_operations
    ? 'executable'
    : 'advisory';

$base_description = sanitize_text_field( (string) ( $suggestion['description'] ?? '' ) );
$annotated_description = self::annotate_description_for_downgrade(
    $base_description,
    $operation_dropped,
    count( $input_operations ),
    count( $operations ),
    $contrast_result
);

$entry = [
    'label'       => sanitize_text_field( (string) ( $suggestion['label'] ?? '' ) ),
    'description' => $annotated_description,
    'category'    => sanitize_key( (string) ( $suggestion['category'] ?? 'advisory' ) ),
    'tone'        => $tone,
    'operations'  => 'executable' === $tone ? $effective_operations : [],
];
```

Then update the score/signal assembly that follows (in the same loop body) to read from `$effective_operations`:

```php
$ranking_input  = is_array( $suggestion['ranking'] ?? null ) ? $suggestion['ranking'] : [];
$computed_score = RankingContract::resolve_score_candidate(
    $ranking_input['score'] ?? null,
    $suggestion['score'] ?? null,
    $ranking_input['confidence'] ?? null,
    $suggestion['confidence'] ?? null
);
if ( null === $computed_score ) {
    $computed_score = RankingContract::derive_score(
        0.45,
        [
            'is_executable'   => 'executable' === $tone ? 0.25 : 0.0,
            'has_operations'  => [] !== $effective_operations ? 0.15 : 0.0,
            'has_description' => '' !== $entry['description'] ? 0.1 : 0.0,
            'has_category'    => '' !== $entry['category'] ? 0.05 : 0.0,
        ]
    );
}
$source_signals = [ 'llm_response', 'style_surface', 'tone_' . $tone ];

if ( [] !== $effective_operations ) {
    $source_signals[] = 'has_operations';
}
```

Then add the `annotate_description_for_downgrade` helper that the integration code above calls. Place it as a new private static method on the same class (e.g., right after `validate_suggestions()`):

```php
private const DOWNGRADE_PREFIXES = [
    'Validation:',
    'Contrast check unavailable:',
    'Contrast check:',
];

/**
 * @param array{passed: bool, kind: string|null, reason: string|null, ratio: float|null} $contrast_result
 */
private static function annotate_description_for_downgrade(
    string $base_description,
    bool $operation_dropped,
    int $input_op_count,
    int $surviving_op_count,
    array $contrast_result
): string {
    if ( ! $operation_dropped && $contrast_result['passed'] ) {
        return $base_description;
    }

    foreach ( self::DOWNGRADE_PREFIXES as $prefix ) {
        if ( str_contains( $base_description, $prefix ) ) {
            return $base_description;
        }
    }

    $reason = self::select_downgrade_reason(
        $operation_dropped,
        $input_op_count,
        $surviving_op_count,
        $contrast_result
    );

    if ( $reason === '' ) {
        return $base_description;
    }

    if ( $base_description === '' ) {
        return $reason;
    }

    return $base_description . ' ' . $reason;
}

/**
 * @param array{passed: bool, kind: string|null, reason: string|null, ratio: float|null} $contrast_result
 */
private static function select_downgrade_reason(
    bool $operation_dropped,
    int $input_op_count,
    int $surviving_op_count,
    array $contrast_result
): string {
    if ( $operation_dropped ) {
        return sanitize_text_field(
            sprintf(
                /* translators: 1: number of dropped operations, 2: total operations submitted */
                __( 'Validation: %1$d of %2$d operations could not be applied safely at this scope.', 'flavor-agent' ),
                max( 0, $input_op_count - $surviving_op_count ),
                $input_op_count
            )
        );
    }

    if ( $contrast_result['kind'] === 'unavailable' && is_string( $contrast_result['reason'] ) ) {
        return sanitize_text_field( $contrast_result['reason'] );
    }

    if ( $contrast_result['kind'] === 'low_ratio' && is_string( $contrast_result['reason'] ) ) {
        return sanitize_text_field( $contrast_result['reason'] );
    }

    return '';
}
```

`StyleContrastValidator` lives in the same `FlavorAgent\LLM` namespace as `StylePrompt`, so no `use` statement is needed.

The `Validation:`, `Contrast check unavailable:`, and `Contrast check:` strings shown above and inside `StyleContrastValidator::evaluate()` are wrapped in `__( ..., 'flavor-agent' )` so the annotation pipeline is i18n-clean end to end. The helper does not re-translate the validator's `reason` (already wrapped) — it just sanitizes and dedups.

- [ ] **Step 4: Audit and update existing executable-color test fixtures**

**Important:** several pre-existing tests in `StylePromptTest.php` exercise executable
color suggestions with fixtures that do **not** include `themeTokens.colorPresets` or
that omit a merged complement. Before Stage B, those fixtures were sufficient because
no contrast check ran. After this task wires the validator in, those same fixtures
will trigger a contrast-unavailable downgrade and the assertions that expect
`tone === 'executable'` (or non-empty `operations`) will fail.

Search for affected fixtures:

```bash
grep -nE "tone\s*=>\s*'executable'|'tone'\s*=>\s*'executable'" tests/phpunit/StylePromptTest.php
```

For each existing test that asserts an executable color suggestion survives:

1. Check whether its `$context` fixture has `themeTokens.colorPresets` populated with
   the slugs the operation references.
2. Check whether its `mergedConfig.styles.color.{background,text}` provides a
   resolvable complement for any solo color op.
3. If either is missing, extend the fixture so the contrast check resolves and passes.
   Use safely high-contrast pairs (e.g. `#ffffff` + `#000000`) when the test does not
   itself care about the colors — those produce a 21:1 ratio that comfortably passes
   AA.

Tests that use color presets only as preset-validation fixtures (i.e., they assert
the op is dropped because the preset is wrong) generally do not need updates because
they end up advisory either way.

This is intentionally a per-test audit rather than a global mock substitution: the
fixtures encode the test's intent, and the right adjustment depends on what each
test is checking.

- [ ] **Step 5: Run all StylePrompt and validator tests**

Run: `vendor/bin/phpunit --filter 'StylePromptTest|StyleContrastValidatorTest'`
Expected: PASS — existing Stage A tests still green (with fixtures updated per
Step 4), the 6 new integration tests pass, validator tests unchanged.

- [ ] **Step 6: Commit**

```bash
git add inc/LLM/StylePrompt.php tests/phpunit/StylePromptTest.php
git commit -m "feat(style-contrast): integrate validator with annotation helper"
```

---

## Task 9: Prompt update — pair guidance in `build_system()`

**Files:**
- Modify: `inc/LLM/StylePrompt.php`
- Modify: `tests/phpunit/StylePromptTest.php`

- [ ] **Step 1: Add failing test for the prompt addition**

Append to `tests/phpunit/StylePromptTest.php`:

```php
public function test_build_system_includes_pair_guidance_for_color_ops(): void {
    $system = \FlavorAgent\LLM\StylePrompt::build_system();

    $this->assertStringContainsString( 'pairing foreground and background operations', $system );
    $this->assertStringContainsString( 'downgraded to advisory', $system );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter StylePromptTest::test_build_system_includes_pair_guidance_for_color_ops`
Expected: FAIL (string not present in current system prompt).

- [ ] **Step 3: Add the paragraph to `build_system()`**

In `inc/LLM/StylePrompt.php`, locate the closing of the heredoc inside `build_system()` (currently ends with `- Keep labels under 60 characters and descriptions under 180 characters.`). Add two new bullet lines just before the closing `SYSTEM;`:

```
	- When recommending color changes, prefer pairing foreground and background operations together at the same scope so the resulting contrast can be validated.
	- Solo color operations remain valid but may be downgraded to advisory if the resulting pair fails contrast against the existing complement.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter StylePromptTest::test_build_system_includes_pair_guidance_for_color_ops`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/LLM/StylePrompt.php tests/phpunit/StylePromptTest.php
git commit -m "feat(style-contrast): prompt guidance for paired color ops"
```

---

## Task 10: E2E UI-evidence test

**Files:**
- Modify: `tests/e2e/flavor-agent.smoke.spec.js`

- [ ] **Step 1: Read the existing Global Styles tests and helpers as templates**

The new test must match the surrounding test pattern exactly. Read these blocks first:

- `mockGlobalStylesRecommendations` definition (around line 343 of `tests/e2e/flavor-agent.smoke.spec.js`). Signature is `( page, styleRequests, responseBody = GLOBAL_STYLES_RESPONSE )`. The second arg is an array that gets `.push`-ed into when a non-`resolveSignatureOnly` request fires; the third arg is the JSON body the route returns.
- `mockRecommendationRoute` (around line 365 of the same file) for the route-fulfill behavior.
- The first existing Global Styles test, `'@wp70-site-editor global styles surface previews, applies, and undoes executable recommendations'` (around line 2717), as the canonical setup template — navigation, welcome-guide dismissal, sidebar enable, fetch trigger.
- `getGlobalStylesState` helper (around line 1186) to confirm what fields it returns. If it doesn't already expose `advisorySuggestions` / `executableSuggestions` / `applyButtonVisible` fields keyed for what this test needs, extend it minimally — read the current return shape and add the missing accessors as part of this task.

This task adds a fourth Global Styles smoke test alongside the existing three. It is **UI evidence only**: the PHP validator (Tasks 1–9) does the real work; the smoke harness mocks the REST response and so cannot itself trigger a server-side downgrade.

- [ ] **Step 2: Add the failing test, mirroring the existing setup flow**

Locate the third existing Global Styles test (the one ending with `'keeps stale results visible but disables review and apply until refresh'`) and add the new test immediately after it. Mirror that test's setup verbatim — only the mocked response and the assertions change. Skeleton (fill in any setup helpers the surrounding tests use that are not shown here):

```javascript
test( '@wp70-site-editor global styles surface renders contrast advisory annotation and disables apply', async ( {
	page,
} ) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations( page, styleRequests, {
		suggestions: [
			{
				label: 'Soft on soft',
				description:
					'Use the wash on base. Contrast check: 1.2:1 between "base" and "wash" at root, below the 4.5:1 minimum.',
				category: 'color',
				tone: 'advisory',
				operations: [],
			},
		],
		explanation: 'Advisory because the proposed pair fails contrast.',
		reviewContextSignature: 'mock-review-sig',
		resolvedContextSignature: 'mock-resolved-sig',
	} );

	// IMPORTANT: copy the navigation + welcome-guide dismissal + sidebar enable + fetch
	// trigger from the existing 'previews, applies, and undoes executable recommendations'
	// test verbatim. Do not reinvent the setup — the surrounding tests took several
	// iterations to stabilize against the WP 7.0 Site Editor harness.
	//
	// After setup, trigger the recommendation fetch the same way the existing tests do
	// (typically a button click in the Global Styles panel or a programmatic store
	// dispatch — match whatever the first existing test uses).

	const state = await getGlobalStylesState( page );

	expect( state.advisorySuggestions ).toHaveLength( 1 );
	expect( state.executableSuggestions ).toHaveLength( 0 );
	expect( state.advisorySuggestions[ 0 ].description ).toContain(
		'Contrast check:'
	);
	expect( state.applyButtonVisible ).toBe( false );

	// Confirm the request actually fired (defensive — catches harness regressions
	// where the panel never queries the route).
	expect( styleRequests.length ).toBeGreaterThan( 0 );
} );
```

If `getGlobalStylesState` doesn't already return `advisorySuggestions` / `executableSuggestions` / `applyButtonVisible`, extend it minimally in the same commit. Use the existing helper's introspection style (likely a `page.evaluate` against the `flavor-agent` data store) — match the existing pattern, do not introduce a new abstraction.

- [ ] **Step 3: Run the new e2e test**

Run: `npm run test:e2e:wp70 -- --grep 'renders contrast advisory annotation'`
Expected: PASS. If the WP 7.0 harness is unavailable in the current environment, record the run as a known blocker per `docs/reference/cross-surface-validation-gates.md`.

- [ ] **Step 4: Run the full Global Styles e2e block to confirm no regression**

Run: `npm run test:e2e:wp70 -- --grep '@wp70-site-editor global styles'`
Expected: PASS (4 tests including the new one).

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/flavor-agent.smoke.spec.js
git commit -m "test(style-contrast): wp7.0 e2e ui evidence for advisory annotation"
```

---

## Task 11: Final verification + Stage B closeout

**Files:**
- Modify: `docs/reference/surfaces/global-styles.md`

- [ ] **Step 1: Run targeted PHPUnit and Jest gates**

Run: `vendor/bin/phpunit --filter 'StyleContrastValidatorTest|StylePromptTest|StyleAbilitiesTest'`
Expected: PASS (all style-related PHPUnit tests green).

Run: `npm run test:unit -- --runInBand --testPathPattern='style|global-styles'`
Expected: PASS (no JS regressions on style surfaces).

- [ ] **Step 2: Run the aggregate verifier without e2e**

Run: `node scripts/verify.js --skip-e2e`
Expected: `VERIFY_RESULT={...,"status":"pass",...}` on the final stdout line. Inspect `output/verify/summary.json` for any unexpected `incomplete` step.

- [ ] **Step 3: Re-run docs gate**

Run: `npm run check:docs`
Expected: clean (no stale-doc warnings).

- [ ] **Step 4: Update the Global Styles surface doc to mark contrast next-steps complete**

In `docs/reference/surfaces/global-styles.md`, change the four open Next Steps that Stage B closes from `- [ ]` to `- [x]` and append a single sentence to each:

```
- [x] Add deterministic contrast/readability validation before executable color
  suggestions are treated as release-quality design recommendations.
  `StyleContrastValidator` performs WCAG AA checks server-side per the
  Stage B Design Commitments.
- [x] Prefer paired foreground/background operations when one color change alone
  could create poor contrast. `StylePrompt::build_system()` now nudges the
  model toward paired emission, and the validator evaluates within-suggestion
  pairs first.
- [x] Classify low-contrast or unsupported combined results as advisory.
  `StylePrompt::validate_suggestions()` downgrades via `$effective_operations`
  with a canonical `Contrast check:` or `Contrast check unavailable:`
  annotation.
- [x] Preserve grouped operations as one review-safe transaction when splitting
  would create a bad intermediate state. The server parser now downgrades any
  suggestion to advisory when validation drops part of its operation sequence,
  and the client applier still writes only after every grouped operation passes.
- [x] Keep design-quality claims limited until contrast/readability validation
  exists. WCAG AA contrast validation now ships as of Stage B.
```

(The fourth item was already marked from Stage A; leave its existing language intact and just confirm the box is `[x]`.)

- [ ] **Step 5: Re-run docs gate after the surface-doc edit**

Run: `npm run check:docs`
Expected: clean (no stale-doc warnings). The earlier `check:docs` in Step 3 ran
against the pre-edit tree; this re-run confirms the surface-doc change in Step 4
did not break any freshness assertion.

- [ ] **Step 6: Commit**

```bash
git add docs/reference/surfaces/global-styles.md
git commit -m "docs(style-contrast): mark Stage B next-steps complete"
```

- [ ] **Step 7: Run WP 7.0 e2e harness if available**

Run: `npm run test:e2e:wp70 -- --grep '@wp70-site-editor (global styles|style book)'`
Expected: PASS for all matching tests including the new advisory test from Task 10. If the harness is unavailable, record the blocker per `docs/reference/cross-surface-validation-gates.md`.

---

## Self-Review Notes

- Spec coverage: each Stage B Design Commitment in `docs/reference/surfaces/global-styles.md` § "Stage B Design Commitments" maps to a task above (Authority → Task 8; Threshold → Task 4; Pairing source → Task 6; Out of scope → enforced via the scope-key enum in Task 5; Implementation split → entire plan; Prompt and copy → Tasks 8 + 9; Upstream alignment → no code, recorded in spec).
- Trigger priority and dedup are implemented entirely in Task 8 (single TDD cycle): the integration tests, the wiring change, the metadata-caveat fix, and the `annotate_description_for_downgrade` helper all land together so the build is never red between commits.
- The `proposed-but-unresolved` distinction is enforced by `resolve_side_for_evaluation()` in Task 7: a proposed op whose value cannot be resolved fails closed (`unavailable`); only an absent side falls back to the merged complement.
- Duplicate same-scope same-side operations are handled with **last-write-wins** in `evaluate()` (Task 7), matching the apply path's serial-write semantics. Stage A is intentionally not extended to reject duplicates because over-hardening gate #3 disfavors guards without a real failure mode — the contrast check on the effective last-write naturally surfaces any user-visible problem. A regression test (`test_evaluate_uses_last_write_when_same_scope_side_is_written_twice`) locks the behavior.
- All user-facing reason strings — both `Validation:` (in `StylePrompt`) and `Contrast check:` / `Contrast check unavailable:` (in `StyleContrastValidator`) — wrap in `__( ..., 'flavor-agent' )` at construction time. The annotation helper does not re-translate them; it just sanitizes and dedups.
- All test methods follow the existing snake_case `test_*` convention in `tests/phpunit/StylePromptTest.php`.
- `StyleContrastValidator::evaluate()` returns the four-field shape locked in the spec; no `failures` field per the deferred Out-of-Band Followup. Public pure helpers (`resolve_color_value`, `contrast_ratio`, `scope_key_for_operation`, `merged_complement_hex`) are exposed for granular testing and possible future reuse.
