# Block external introspection parity — design

Date: 2026-07-20
Status: proposed
Scope: defect slice (Spec 0) preceding the block-recommendation grounding and ranking specs

## Problem

Four defects in server-side block introspection, in three shapes:

- **D1 and D3** share one shape: the editor path is correct *because the client overrides the server's value*, and the server-derived paths are not.
- **D4** is the inverse: three places where that override does not fire on a client-asserted empty value, so a stale server value survives. D1 widens the damage.
- **D2** is neither — a type-safety hole in the shared server collector, reachable on every path.

The affected server-derived paths are the MCP-public `flavor-agent/introspect-block` and `flavor-agent/list-allowed-blocks` abilities — both return `build_block_manifest()` output (`inc/Context/BlockTypeIntrospector.php:52`, `:200`) — and the external `selectedBlock` recommendation path.

This is the boundary `docs/reference/governance-layer.md` governs, so this slice is parity repair, not feature work.

These are separated from the two follow-on specs (context grounding, ranking honesty) because each has an obvious correct behavior. D1, D2 and D4 need no design debate; D3 needs only a documentation decision, taken below.

D1 and D4 also corrupt the measurement baseline, in opposite directions: while `registeredStyles` under-reports (D1) or over-reports (D4), any before/after on recommendation quality is read through a filter that silently deletes — or silently admits — a whole suggestion class. Both should land before the grounding and ranking specs are measured.

## D1 — `register_block_style()` styles invisible to server-derived introspection

### Current behavior

`BlockTypeIntrospector::build_block_manifest()` reads block styles from `$block_type->styles` only (`inc/Context/BlockTypeIntrospector.php:248`), which is populated from the block's `block.json` `styles` key. `WP_Block_Styles_Registry` is referenced nowhere in `inc/` or `src/`.

`register_block_style()` writes **only** to `WP_Block_Styles_Registry` and never touches `WP_Block_Type::$styles`:

```php
// wp-includes/blocks.php
function register_block_style( $block_name, $style_properties ) {
	return WP_Block_Styles_Registry::get_instance()->register( $block_name, $style_properties );
}
```

Two separate core mechanisms reunite the registry with `block.json` styles, and it matters which one is which.

**The editor** gets registry styles as explicit `registerBlockStyle()` calls, emitted per registered style:

```php
// wp-includes/script-loader.php — enqueue_editor_block_styles_assets()
// hooked at: add_action( 'enqueue_block_editor_assets', 'enqueue_editor_block_styles_assets' );
$block_styles = WP_Block_Styles_Registry::get_instance()->get_all_registered();
foreach ( $block_styles as $block_name => $styles ) {
    foreach ( $styles as $style_properties ) {
        $block_style = array( 'name' => $style_properties['name'], 'label' => $style_properties['label'] );
        if ( isset( $style_properties['is_default'] ) ) {
            $block_style['isDefault'] = $style_properties['is_default'];
        }
        $register_script_lines[] = sprintf( '	wp.blocks.registerBlockStyle( \'%s\', %s );', ... );
    }
}
```

This is the mechanism that makes the editor's `getBlockStyles()` complete. It is *not* the REST controller — nothing in the editor packages consumes `/wp/v2/block-types`, and `get_block_editor_server_block_settings()` (which feeds the server-side block bootstrap) reads `$block_type->styles` only and never consults the styles registry.

**Core's REST representation** merges separately, for REST consumers:

```php
// wp-includes/rest-api/endpoints/class-wp-rest-block-types-controller.php
if ( rest_is_field_included( 'styles', $fields ) ) {
    $styles         = $this->style_registry->get_registered_styles_for_block( $block_type->name );
    $styles         = array_values( $styles );
    $data['styles'] = wp_parse_args( $styles, $data['styles'] );
    $data['styles'] = array_filter( $data['styles'] );
}
```

That is the relevant parity comparison for this defect: **core's own block-types REST endpoint already merges the registry; Flavor Agent's two MCP-public introspection abilities do not.** (`wp_parse_args( $args, $defaults )` is `array_merge( $defaults, $args )`, so on numeric-keyed arrays this appends registry styles after `block.json` styles without deduping — core's REST output can legitimately carry two entries with the same `name`.)

So the editor path is *mostly* unaffected: `BlockAbilities.php:344-347` overrides the server's `styles` with the client's — but only `! empty( $styles )`, which is a defect in its own right (D4 below).

### Impact

1. `flavor-agent/introspect-block` and `flavor-agent/list-allowed-blocks` are both MCP-public (`meta.mcp.public = true` via `Registration::mcp_public_readonly_rest_meta()`, `inc/Abilities/Registration.php:2180-2188`, applied at `:732` and `:787`). External agents asking what styles a block offers receive a list missing every `register_block_style()` registration.
2. The external `selectedBlock` recommendation path derives `executionContract.registeredStyles` from the same short list (`BlockRecommendationExecutionContract::collect_registered_style_names`, `inc/Context/BlockRecommendationExecutionContract.php:51`, `:94`). `Prompt::is_valid_style_variation_suggestion()` (`inc/LLM/Prompt.php:2534-2585`) then silently deletes style-variation suggestions that name a legitimately registered style.

The loss is silent — the call site is `Prompt.php:1831`, which `return null`s with no entry in the `validationReasons` vocabulary the payload schema already supports (`Registration.php:2669-2688`).

**Not affected:** the governed Style Book apply path. `StyleApplyExecutor.php:102-118` also consumes a `build_block_manifest()` result, but `StyleAbilities::supported_block_style_paths_from_manifest()` (`inc/Abilities/StyleAbilities.php:784-786`) reads only `supports` and `title`. No governed write reads manifest `styles`.

### Fix

Replace the inline `styles` projection in `build_block_manifest()` (`BlockTypeIntrospector.php:294-301`) with a collector that merges both sources and normalizes once:

```php
private function collect_block_styles( string $block_name, object $block_type ): array {
	$styles = [];

	foreach ( (array) ( $block_type->styles ?? [] ) as $style ) {
		$normalized = $this->normalize_block_style( $style );

		if ( null !== $normalized ) {
			// First writer wins, so block.json survives a name collision.
			$styles[ $normalized['name'] ] ??= $normalized;
		}
	}

	if ( class_exists( 'WP_Block_Styles_Registry' ) ) {
		$registered = \WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block( $block_name );

		foreach ( $registered as $style ) {
			$normalized = $this->normalize_block_style( $style );

			if ( null !== $normalized ) {
				$styles[ $normalized['name'] ] ??= $normalized;
			}
		}
	}

	return array_values( $styles );
}

private function normalize_block_style( mixed $style ): ?array {
	if ( ! is_array( $style ) ) {
		return null;
	}

	$name = is_string( $style['name'] ?? null ) ? $style['name'] : '';

	if ( '' === $name ) {
		return null;
	}

	return [
		'name'      => $name,
		'label'     => is_string( $style['label'] ?? null ) ? $style['label'] : '',
		// block.json uses isDefault; the registry uses is_default.
		'isDefault' => (bool) ( $style['isDefault'] ?? $style['is_default'] ?? false ),
	];
}
```

`build_block_manifest()` then sets `'styles' => $this->collect_block_styles( $block_name, $block_type )`.

Design points, in order of how easy they are to get wrong:

- **Normalize during the merge, not after.** `get_registered_styles_for_block()` returns a name-keyed map of `{name, label, inline_style, style_handle, is_default}` — snake_case. The existing `array_map` projection reads `$style['isDefault'] ?? false`, so appending raw registry entries and reusing that projection would flag every registry style `isDefault: false`. Doing the shape mapping inside the merge means there is exactly one place that knows both key spellings.
- **`block.json` wins on collision**, matching the editor. Two layers agree. Core's REST controller does not dedupe at all (`wp_parse_args` above), and the editor dedupes with `getUniqueItemsByName`, which keeps the *first* occurrence (`node_modules/@wordpress/blocks/build/store/reducer.cjs:61-68`). Whichever order the two editor actions arrive in, `block.json` is first: `ADD_BLOCK_STYLES` builds `[...state[blockName], ...action.styles]` (`:138-146`) with `block.json` already in state, and `ADD_BLOCK_TYPES` rebuilds as `[...blockType.styles tagged source:'block', ...state.filter(source !== 'block')]` (`:118-137`). Taking registry-wins here would diverge from the editor rather than converge on it.
- **We dedupe where core's REST does not.** The manifest emits one entry per name; core's REST endpoint can emit two. Matching the editor's post-dedupe view is the right target for a manifest that feeds `registeredStyles`, which is itself a deduped name list.
- **Entries with no usable `name` are now dropped** rather than emitted as `{name: '', label: '', isDefault: false}`. This aligns the manifest with `collect_registered_style_names()`, which already skips them, and no current test asserts the old shape (`BlockTypeIntrospectorTest` today covers only `extract_active_style`).

Guard the registry read with `class_exists( 'WP_Block_Styles_Registry' )`. The unit harness does not currently define that class — `tests/phpunit/bootstrap.php` stubs `WP_Block_Type_Registry` (`:1944-1945`) but not the styles registry — so without the guard every introspector test fatals.

The guard is production defence-in-depth and stays deliberately uncovered: once the stub exists in bootstrap, `class_exists()` is unconditionally true in-process, so the false branch is unreachable without `@runInSeparateProcess` or an injected registry resolver. Neither is worth the machinery for a one-line guard — see Testing.

### Out of scope

JS-only `registerBlockStyle()` registrations remain invisible server-side. They are unreachable from PHP by construction, and the editor path carries them via the client override — once D4 makes that override fire on an explicit empty list as well. This is a known and accepted residual gap for the two MCP-public abilities and the `selectedBlock` path; it should be stated in those abilities' documentation rather than worked around.

## D2 — `extract_active_style()` fatals on a non-string `className`

### Current behavior

`BlockTypeIntrospector::extract_active_style( string $class_name, array $styles )` (`inc/Context/BlockTypeIntrospector.php:115`) is typed `string`. It is called from exactly one place, in a file that declares `strict_types=1` (`inc/Context/BlockContextCollector.php:3`) — which is what makes the call strict, since `declare(strict_types=1)` governs the *calling* file:

```php
// inc/Context/BlockContextCollector.php:40-43
'activeStyle' => $this->block_type_introspector->extract_active_style(
    $attributes['className'] ?? '',
    $type_info['styles'] ?? []
),
```

`$attributes` is client-supplied. `NormalizesInput::normalize_value()` (`inc/Support/NormalizesInput.php:45-71`) is structural only — it recurses arrays and passes strings, ints, floats, bools and null through unchanged. The ability's input schema does not narrow it either: `selectedBlock.attributes` is `open_object_schema()` with no per-property types (`Registration.php:2328-2331`, `:2641-2656`). So `className` reaches the call as whatever type the caller sent.

### Impact

A request with `attributes.className` as an int, float, bool, or array raises an uncaught `TypeError`, surfacing as a 500 rather than a 400. Reachable by any caller with `edit_posts`, on **every** path into the block context collector:

- the external `selectedBlock` path (`BlockAbilities.php:437-451`);
- the `editorContext` path, which funnels through the same `build_context_from_selected_block()` (`BlockAbilities.php:296`) — so a fabricated `editorContext` fatals too, even though the first-party editor never sends a non-string `className`;
- `flavor-agent/preview-recommend-block`, which is MCP-public (`Registration.php:135-149`) and reuses the parent's input schema and `execute_callback` (`PreviewRecommendationAbility.php:80-85`, `:121-133`). `prepare_recommend_block_input()` runs at `BlockAbilities.php:61`, *before* the `resolveSignatureOnly` short-circuit at `:88`, so the signature-only dry run fatals on the same input.

### Fix

Coerce at the boundary in `BlockContextCollector`, not by widening the introspector's signature:

```php
'activeStyle' => $this->block_type_introspector->extract_active_style(
    is_string( $attributes['className'] ?? null ) ? $attributes['className'] : '',
    $type_info['styles'] ?? []
),
```

Rationale: the introspector's `string` contract is correct and worth keeping honest. The coercion belongs where untrusted data enters the collector, and `extract_active_style` has exactly one call site, so one coercion closes every path. `extract_active_style('')` already returns `null` on the empty-string path, so a non-string `className` degrades to "no active style" — the same result as a block with no class, which is the right answer.

## D3 — `themeTokens` client override is outside the documented trust boundary

### Current behavior

On the `editorContext` path, a non-empty client `themeTokens` replaces the server's `ThemeTokenCollector::for_tokens()` output wholesale:

```php
// inc/Abilities/BlockAbilities.php:416-419
$theme_tokens = self::normalize_theme_tokens( $context['themeTokens'] ?? [] );
if ( ! empty( $theme_tokens ) ) {
    $normalized['themeTokens'] = $theme_tokens;
}
```

That value feeds `executionContract.presetSlugs`, `enabledFeatures`, and `layout` (`inc/Context/BlockRecommendationExecutionContract.php:52-62`) — the whitelist deciding which suggested values survive validation.

`docs/reference/governance-layer.md` § "Recommendation context trust boundary" enumerates the trusted first-party surface as "introspected `inspectorPanels`, `bindableAttributes`, and content/config attribute keys." Theme tokens are not in that list.

### Decision: keep the override, correct the documentation

The override is retained. The client value is frequently *more* accurate than the server's: the editor's `features` / `__experimentalFeatures` settings reflect per-context resolution — a style variation being previewed in the Site Editor, block-level setting overrides — that `wp_get_global_settings()` cannot observe. Removing it would regress preset accuracy in exactly the case it exists to serve.

The security impact of the override is low and does not warrant the accuracy cost. Widening the preset whitelist lets a user obtain suggestions naming values their theme does not define; that same user holds `edit_posts` and can set any attribute by hand. It is not privilege escalation. Consistent with the doc's own framing, a recommendation's `executionContract` is "an advisory shaping and attribution artifact, never an apply authority" — the governed write paths re-collect and re-validate server-side.

So this is documentation drift, not a hole.

### Fix

Update `docs/reference/governance-layer.md` § "Recommendation context trust boundary" (lines 184-188). The correction is larger than adding one key to a list, because the section's "trusted first-party surface" framing does not survive inspection:

**There is no enforced first-party path.** `flavor-agent/recommend-block` accepts `editorContext` as an open object from any caller (`inc/Abilities/Registration.php:1337-1343`), and `prepare_recommend_block_input()` selects the path purely on key presence (`inc/Abilities/BlockAbilities.php:236-237`) with no provenance signal — no nonce class, no origin marker, nothing distinguishing the editor from any other client. Any holder of `edit_posts` can POST a fabricated `editorContext` to `/wp-abilities/v1/abilities/flavor-agent%2Frecommend-block/run`. The `selectedBlock` path is no better sealed: `supportsContentRole` is OR-widened from client input (`BlockAbilities.php:462`), so a caller can turn it on but not off.

**Exposure, stated precisely** — this replaces an earlier draft of this note that got it wrong, and the corrected doc must not contradict `governance-layer.md:175`, which already describes the dedicated-server exposure:

- `recommend-block` declares no `mcp` meta, so it is **not** on the universal MCP *default* server. `mcp_public_readonly_rest_meta()` (`Registration.php:2180-2188`) is used only by the read helpers — D1's two abilities among them.
- It **is** a first-class tool on the *dedicated* Flavor Agent MCP server at `/wp-json/mcp/flavor-agent`: `inc/MCP/ServerBootstrap.php:33-50` registers every entry of `Registration::recommendation_ability_classes()`, transport-gated on `edit_posts || edit_theme_options` (`:57-61`).
- Its sibling `flavor-agent/preview-recommend-block` **is** `mcp.public` (`Registration.php:135-149`) and reaches the same `prepare_recommend_block_input()` with the same open `editorContext` (`PreviewRecommendationAbility.php:80-85`, `:121-133`), forcing `resolveSignatureOnly` so a fabricated context shapes the signature and docs query but not suggestions.
- Plus the abilities REST route itself.

So the fabricated-`editorContext` surface is reachable from both MCP servers and from REST. This does not change the decision — the permission gates are identical on every vector, and the argument below is about what a caller *gains*, not how they arrive.

So:

1. Drop the "trusted first-party surface" enumeration in favor of describing both paths as **caller-supplied advisory context**. This is what the section's own closing already argues — that an `executionContract` is "an advisory shaping and attribution artifact, never an apply authority" — so the fix makes the section internally consistent rather than weakening it.
2. State the actual security property, which is real and unchanged: context shapes *suggestions*, and every governed write re-collects and re-validates server-side. Widening the preset whitelist buys a caller nothing they cannot already do by hand with `edit_posts`. Name the exposure vectors above rather than implying a single one.
3. Fold `themeTokens` into that framing with the reason it is client-preferred (per-context editor resolution — previewed style variations, block-level overrides — that `wp_get_global_settings()` cannot observe).
4. Correct the adjacent claim that the `selectedBlock` path "re-introspects the block type server-side (`ServerCollector::for_block`), so an external caller cannot fabricate capabilities." The non-fabrication property holds for the fields it actually re-derives, but the implied completeness does not — D1 is a case where re-introspection under-reports, and `supportsContentRole` is a case where client input still widens the result. Say what re-introspection covers rather than implying it is total.

## D4 — client-asserted absence is discarded

### Current behavior

Three adjacent keys in `build_context_from_editor_context()` treat "client asserted empty" as "client said nothing", so a stale server value survives:

```php
// inc/Abilities/BlockAbilities.php:344-352
$styles = self::normalize_list( $block['styles'] ?? [] );
if ( ! empty( $styles ) ) {
    $normalized['block']['styles'] = $styles;
}

$active_style = is_string( $block['activeStyle'] ?? null ) ? sanitize_text_field( $block['activeStyle'] ) : '';
if ( '' !== $active_style ) {
    $normalized['block']['activeStyle'] = $active_style;
}

// inc/Abilities/BlockAbilities.php:364-367
$variations = self::normalize_list( $block['variations'] ?? [] );
if ( ! empty( $variations ) ) {
    $normalized['block']['variations'] = $variations;
}
```

The editor **always** sends all three keys: `collectBlockContext` enumerates `styles: instance.styles` (`src/context/collector.js:650`), `activeStyle: instance.activeStyle` (`:651`) and `variations: instance.variations` (`:652`) unconditionally. And each has a legitimate empty value:

- `instance.styles` is `store.getBlockStyles( blockName ) || []` (`src/context/block-inspector.js:195`), legitimately `[]` after `unregisterBlockStyle()` removes the last style;
- `instance.variations` is `store.getBlockVariations( blockName, 'block' ) || []` (`:196`), legitimately `[]` after `unregisterBlockVariation()` (`REMOVE_BLOCK_VARIATIONS`, `reducer.cjs:191-197`);
- `instance.activeStyle` is `currentAttrs?.className ? extractActiveStyle( currentAttrs.className, typeMeta.styles ) : null` (`:290-292`) — `null` whenever nothing in the editor's style list matches, which is exactly what happens once that list is empty.

`! empty()` and `'' !== $active_style` cannot distinguish "client omitted the key" from "client asserted nothing is registered".

### Impact

Pre-existing, and widened by D1.

**`styles` is the severe one.** Today a block whose styles were all JS-unregistered still receives the server's `block.json` styles. After D1 the server list also carries every `register_block_style()` registration, so the reappearing set grows. `executionContract.registeredStyles` is built from it (`BlockRecommendationExecutionContract.php:51`), so `Prompt::is_valid_style_variation_suggestion()` will accept — and the model may propose — style variations the user removed from the editor.

**`activeStyle` leaks through the same scenario even after `styles` is fixed.** With `block.styles = []` the client sends `activeStyle: null`, which fails the `is_string()` test, so the server-derived value from `BlockContextCollector.php:40-43` survives — computed against the server's list, which D1 widens. The prompt would then carry `Active style: outline` (`inc/LLM/Prompt.php:297-298`) alongside `registeredStyles: []`: a self-contradicting context that re-asserts the removed style by name. Fixing `styles` without `activeStyle` moves the leak rather than closing it.

**`variations` is the same bug, lower blast radius.** Variations reach the prompt (`Prompt.php:293-294`) but not the execution contract, so the consequence is a stale menu in the prompt rather than a widened validation whitelist.

This is the one defect in this slice with editor-path behavior consequences, which is why the `playground` Playwright work below is required.

### Fix

Override whenever the key is present, matching the pattern `inspectorPanels` already uses (`BlockAbilities.php:326-330`, which sets `inspectorPanelsExplicit` on key presence even for `{}`):

```php
if ( array_key_exists( 'styles', $block ) ) {
    $normalized['block']['styles'] = self::normalize_list( $block['styles'] ?? [] );
}

if ( array_key_exists( 'activeStyle', $block ) ) {
    $active_style = is_string( $block['activeStyle'] ?? null ) ? sanitize_text_field( $block['activeStyle'] ) : '';
    // null is the canonical "no active style", matching extract_active_style(): ?string.
    $normalized['block']['activeStyle'] = '' !== $active_style ? $active_style : null;
}

if ( array_key_exists( 'variations', $block ) ) {
    $normalized['block']['variations'] = self::normalize_list( $block['variations'] ?? [] );
}
```

`activeStyle => null` is safe for every reader: the prompt guards on `! empty()` (`Prompt.php:297`), and no other code consumes the key.

Note that `styles: null` from a client would also normalize to `[]` under `array_key_exists`, i.e. read as an assertion rather than as "unknown". The editor never sends null, and `inspectorPanels` already behaves this way, so this stays consistent rather than introducing a third convention.

### Deferred, deliberately

`contentAttributes` and `configAttributes` (`BlockAbilities.php:373-381`) carry the identical `! empty()` guard and are **not** changed here. They feed `executionContract.contentAttributeKeys` / `configAttributeKeys` and `usesInnerBlocksAsContent` (`BlockRecommendationExecutionContract.php:26-50`), so flipping them changes suggestion filtering, not just prompt text. Reaching a divergence also requires the client and server to disagree about the block type's *attributes* — a narrower and less well-understood scenario than an unregistered style. Left for the ranking spec, which already has to reason about attribute-level validation.

## Testing

Extend existing suites; no new PHPUnit test files.

**Harness prerequisite.** `tests/phpunit/bootstrap.php` must gain a `WP_Block_Styles_Registry` stub mirroring the `WP_Block_Type_Registry` stub at `:1944-2000`: a private `?self $instance` with `get_instance()`, `register( string $block_name, array $properties )` storing into `$registered[ $block_name ][ $properties['name'] ]`, `get_registered_styles_for_block( string $block_name ): array` returning that name-keyed map (or `[]`), and a `reset()` wired into the existing teardown beside `WP_Block_Type_Registry::get_instance()->reset()` (`:444`) so styles do not leak between tests.

Tests register through `WP_Block_Styles_Registry::get_instance()->register()` directly. A `register_block_style()` function stub is not needed and should not be added — the production code path reads the registry, and stubbing the wrapper would only test the stub.

`tests/phpunit/BlockTypeIntrospectorTest.php` (currently covers only `extract_active_style`, so all manifest-style assertions are new)
- a registry-registered style appears in the manifest with `{name, label, isDefault}`, with `is_default => true` surfacing as `isDefault => true`
- a name collision between `block.json` and the registry yields one entry, **`block.json` wins** (assert on `label`, so the winner is observable)
- a block with no registry entries yields its `block.json` styles unchanged
- `list_registered_blocks()` manifests carry registry-registered styles, matching `introspect_block_type()` — regression coverage for the second MCP-public consumer, which shares `build_block_manifest()` via `collect_registered_blocks()` (`inc/Context/BlockTypeIntrospector.php:200`)

The third case replaces an originally proposed "registry unavailable → falls back to `block.json`". That branch is unreachable once the class is stubbed in bootstrap — `class_exists()` is process-global — and the observable outcome is identical, so this asserts the same contract without `@runInSeparateProcess` or an injected registry resolver.

`tests/phpunit/BlockAbilitiesTest.php` — the existing `invoke_prepare_recommend_block_input()` helper covers the context-shape cases; for `executionContract` assertions without a live provider, reuse the `editingMode: 'disabled'` short-circuit seam already exercised at `:562-589`, which returns `executionContract` before any `ChatClient::chat()` call (`BlockAbilities.php:96-109`).

- **D1:** on the `selectedBlock` path, a registry-registered style reaches `executionContract.registeredStyles`
- **D1:** a style-variation suggestion naming a registry-registered style survives `Prompt::enforce_block_context_rules()` with that contract — the end-to-end assertion that D1 actually unblocks the filter (assert at the `enforce_block_context_rules` seam rather than through a stubbed model response)
- **D4:** an `editorContext` carrying `styles: []` yields `executionContract.registeredStyles === []`, not the server's list
- **D4:** an `editorContext` omitting `styles` entirely still falls back to the server's list
- **D4:** an `editorContext` carrying `styles: []` and `activeStyle: null` yields `block.activeStyle === null`, not the server-derived style name — the regression guard for the leak that survives a `styles`-only fix
- **D4:** an `editorContext` carrying `variations: []` yields `block.variations === []`; omitting the key still falls back to the server's list
- **D2:** `attributes.className` as int / bool / array produces a normal response, not a fatal — asserted on **both** the `selectedBlock` and the `editorContext` path
- **D2:** non-string `className` yields `activeStyle === null`

`tests/phpunit/PromptRulesTest.php`
- no new cases required, but re-run: D1 and D4 both move `registeredStyles`, which is this suite's primary input for style-variation filtering

## Verification

`docs/reference/cross-surface-validation-gates.md` applies — this touches ability contracts and the shared execution contract.

- targeted PHPUnit: `BlockTypeIntrospectorTest`, `BlockAbilitiesTest`, `PromptRulesTest`
- `node scripts/verify.js --skip-e2e`, inspect `output/verify/summary.json`
- `npm run check:docs` (the governance doc changes under D3)
- Playwright `playground` suite: **required, and requires new fixture coverage.** D1–D3 change no editor-path behavior — the client already overrides those values — but D4 does: a block whose styles were all JS-unregistered will now correctly present no style variations where it previously inherited the server's list. No existing fixture unregisters a block style, so running the suite unchanged would go green without exercising the change. Add to `tests/e2e/playground-mu-plugin/`, which already carries both seams (`flavor-agent-loader.php` hooks `enqueue_block_editor_assets` at `:122-137` and ships editor JS):
  - PHP: a `register_block_style()` call on `init`, so D1's server-side merge is exercised end to end;
  - editor JS: a `wp.blocks.unregisterBlockStyle()` call against a block that has `block.json` styles, plus a spec asserting the AI Recommendations panel offers no style-variation suggestion for it.

  D1's own editor-path claim needs no new coverage: the editor already sees registry styles via `enqueue_editor_block_styles_assets()`.
- Playwright `wp70` suite: not required. No Site Editor template, template-part, Global Styles, or Style Book path is touched. Record this as the reason rather than as a silent skip.

## Explicitly out of scope

Deferred to the follow-on specs, listed so they are not silently dropped:

- `presetBacked` returning a hard `false` for every block attribute suggestion (`inc/Support/RecommendationDesignValidator.php:88` — `$operations` is empty and `attributeUpdates` is not, so the expression is always `false`) — fixing it properly means teaching the validator to read `attributeUpdates`, which is the core of the ranking spec
- the `contentAttributes` / `configAttributes` half of D4 (see "Deferred, deliberately" above)
- style-variation rejection emitting no `validationReasons` code, despite the schema supporting one (`Prompt.php:1831`, `Registration.php:2669-2688`) — belongs with the ranking spec's reason-vocabulary work
- the lossy `supports` → `inspectorPanels` projection (unmapped supports dropped; values flattened to booleans; explicit `false` indistinguishable from absent)
- block variations shipped without `attributes` / `innerBlocks`
- `blockInterior` and sibling nodes carrying no text content
- `theme_token_values` prompt priority
- unused server machinery unreachable from the block surface (`PostBlocksContextCollector`, `ViewportVisibilityAnalyzer`, `PatternOverrideAnalyzer`, `SyncedPatternRepository`)
