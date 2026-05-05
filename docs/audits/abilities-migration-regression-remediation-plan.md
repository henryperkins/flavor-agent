# Abilities Migration Regression Remediation Plan

## Scope

This plan addresses the four findings from the uncommitted-changes review of the recommendation migration from Flavor Agent REST routes to the WordPress AI Feature and Abilities API path.

The goal is to preserve the intended design:

- Recommendation abilities are controlled by the WordPress AI plugin Feature toggle.
- Infra/helper abilities remain available when the Abilities API exists, even if the AI Feature framework is absent or disabled.
- Editor JS can execute recommendation abilities reliably while still using the canonical `@wordpress/abilities` bridge when available.
- Permissions remain capability-specific: post recommendations check post edit access, while theme/style recommendations use theme editing capability.

## Findings

1. `FeatureBootstrap::recommendation_feature_enabled()` defaults missing AI feature options to enabled, exposing recommendation abilities before Settings -> AI has enabled the feature.
2. Global helper ability registration requires AI plugin Feature/Ability classes, so helper/status abilities disappear when only the Abilities API is available.
3. `executeFlavorAgentAbility()` prefers the JS Abilities bridge for non-abort calls and does not fall back to direct REST when the bridge is loaded but not ready.
4. `RecommendationAbility::permission_callback()` treats any numeric `entityId` as a post ID, which can over-restrict Global Styles and other theme-scoped recommendations.

## Solution Overview

Make the migration contract explicit by separating three concerns:

- Abilities API availability: `wp_register_ability()` and category registration are enough for helper abilities.
- AI plugin Feature availability: `Abstract_Feature` and `Abstract_Ability` are required only for recommendation ability classes and editor recommendation runtime.
- Feature enabled state: missing AI plugin feature options should be interpreted the same way the AI plugin interprets them, not as automatically enabled.

The minimal implementation should keep the existing class structure and update the smallest number of branches and tests needed to lock the intended behavior.

## Implementation Plan

### 1. Split Availability Checks

Update `inc/AI/FeatureBootstrap.php` to separate Abilities API support from AI Feature framework support.

Add or refactor helpers:

```php
public static function abilities_api_available(): bool {
	return \function_exists( 'wp_register_ability' );
}

public static function ai_feature_contracts_available(): bool {
	return \class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' )
		&& \class_exists( '\WordPress\AI\Abstracts\Abstract_Ability' );
}

public static function canonical_contracts_available(): bool {
	return self::abilities_api_available() && self::ai_feature_contracts_available();
}
```

Use these checks as follows:

- `register_feature_class()` should require `ai_feature_contracts_available()`.
- `editor_runtime_available()` should require `canonical_contracts_available()` and `wp_enqueue_script_module()`.
- `register_global_ability_category()` should require only the Abilities API category function, not AI plugin classes.
- `register_global_helper_abilities()` should register helper abilities when `wp_register_ability()` exists.
- Recommendation ability registration should still require `canonical_contracts_available()` and `recommendation_feature_enabled()`.

Acceptance criteria:

- Helper abilities register when `wp_register_ability()` exists, even if AI plugin classes are absent.
- Recommendation abilities do not register unless AI plugin contracts exist and the feature is enabled.

### 2. Match AI Feature Toggle Semantics

Update `FeatureBootstrap::recommendation_feature_enabled()` and `enabled_option()`.

Recommended behavior:

- If AI Feature contracts are unavailable, return `false`.
- Default missing `wpai_features_enabled` to `false`.
- Default missing `wpai_feature_flavor-agent_enabled` to `false`.
- Continue applying `wpai_features_enabled` and `wpai_feature_flavor-agent_enabled` filters so host or test environments can force-enable the feature.

Implementation direction:

```php
private static function enabled_option( string $option_name, bool $default = false ): bool {
	$value = \get_option( $option_name, $default );
	// keep existing bool/numeric/string normalization
}
```

Then call:

```php
self::enabled_option( 'wpai_features_enabled', false )
self::enabled_option( 'wpai_feature_flavor-agent_enabled', false )
```

Acceptance criteria:

- Fresh install with missing AI options does not expose `flavor-agent/recommend-*` abilities.
- Setting both AI options to true exposes recommendation abilities.
- A `wpai_feature_flavor-agent_enabled` filter can still force-enable or force-disable the feature.

### 3. Preserve Helper Ability Registration

Update `FeatureBootstrap::register_global_helper_abilities()`.

Expected behavior:

- Always call `Registration::register_abilities()` when `wp_register_ability()` exists.
- Call `Registration::register_recommendation_abilities()` only when recommendation contracts are available and the feature is enabled.

Implementation direction:

```php
public static function register_global_helper_abilities(): void {
	if ( ! self::abilities_api_available() ) {
		return;
	}

	Registration::register_abilities();

	if ( self::recommendation_feature_enabled() ) {
		Registration::register_recommendation_abilities();
	}
}
```

`Registration::register_recommendation_abilities()` already guards `Abstract_Ability`; keep or tighten that guard to `FeatureBootstrap::canonical_contracts_available()` if avoiding direct coupling is acceptable.

Acceptance criteria:

- `flavor-agent/check-status`, `flavor-agent/introspect-block`, `flavor-agent/list-patterns`, `flavor-agent/list-template-parts`, and docs helper abilities are still registered without AI plugin Feature classes.
- Recommendation abilities remain hidden when the feature toggle is disabled.

### 4. Add JS Bridge Fallback

Update `src/store/abilities-client.js` so the bridge remains preferred, but direct REST is used when bridge execution fails for readiness/lookup reasons.

Implementation approach:

- Extract direct REST execution into a helper.
- Try `window.flavorAgentAbilities.executeAbility()` first when no abort signal is supplied.
- If the bridge throws, fall back to direct REST unless the error indicates a caller/input/permission failure that should be surfaced unchanged.

Conservative fallback rule:

- Fall back for unknown bridge errors, ability lookup/readiness errors, or missing callback errors.
- Do not fall back for permission or validation errors, because REST would produce the same error and retrying could duplicate work.

Example shape:

```js
function shouldFallbackToRest( error ) {
	return ! [
		'ability_permission_denied',
		'ability_invalid_input',
		'ability_invalid_output',
	].includes( error?.code );
}
```

Acceptance criteria:

- If the bridge resolves, no REST request is made.
- If the bridge throws an ability-not-found/readiness error, direct REST is attempted.
- If the bridge throws a permission or invalid-input error, that error is surfaced without REST retry.
- Abort-signal requests continue using direct REST so `apiFetch` can receive the signal.

### 5. Tighten Recommendation Permission Scope

Update `inc/AI/Abilities/RecommendationAbility.php` so post-specific permission checks apply only to post-scoped recommendation surfaces.

Recommended behavior:

- For `block`, `content`, and `pattern` surfaces, require `edit_posts` first, then require `edit_post` only when the input clearly references a post.
- For `template`, `template-part`, `navigation`, and `style` surfaces, require `edit_theme_options` and do not interpret numeric `entityId` / Global Styles IDs as posts.
- Preserve any explicit post checks only if a theme-scoped request includes a field that is clearly post-scoped, such as `postContext.postId` or `editorContext.postId`, and the product intentionally wants both checks.

Minimal implementation direction:

```php
public function permission_callback( mixed $input = null ): bool {
	if ( ! \current_user_can( static::CAPABILITY ) ) {
		return false;
	}

	if ( ! static::uses_post_scoped_permission() ) {
		return true;
	}

	$post_id = $this->post_id_from_input( $input );

	return $post_id > 0 ? \current_user_can( 'edit_post', $post_id ) : true;
}

private static function uses_post_scoped_permission(): bool {
	return \in_array( static::SURFACE, [ 'block', 'content', 'pattern' ], true );
}
```

If block recommendations can run inside Site Editor template contexts, consider deriving post IDs only from `postContext.postId`, `editorContext.postId`, or `document.scopeKey` matching `post:<id>`, not from a generic `entityId`.

Acceptance criteria:

- Global Styles and Style Book recommendation abilities pass for users with `edit_theme_options`, regardless of numeric Global Styles IDs.
- Content recommendation with `postContext.postId` still requires `edit_post` for that post.
- Block recommendation in a post scope still checks `edit_post` when the post ID is explicit.
- Template/template-part/navigation recommendations remain governed by `edit_theme_options`.

### 6. Optional Abort Metadata Follow-Up

The review noted a lower-confidence residual issue: aborted requests are not explicitly sent to PHP as `clientRequest.aborted: true`. This is less critical than the four primary findings because aborting an HTTP request may prevent PHP completion, and token supersession already suppresses many stale diagnostics.

If implemented, keep it small:

- When aborting a previous controller, optionally fire a best-effort non-blocking ability request or activity cleanup marker with `clientRequest.aborted: true` only if the current architecture can do so without another model call.
- Do not send a second recommendation execution just to mark an abort.

Recommendation: defer unless stale diagnostic persistence remains reproducible after the main fixes.

## Test Plan

### PHP Unit Tests

Update `tests/phpunit/FeatureBootstrapTest.php`:

- Missing `wpai_features_enabled` and `wpai_feature_flavor-agent_enabled` options should skip recommendation abilities.
- Setting both options to true should register recommendation abilities.
- Disabled per-feature option should skip recommendation abilities.
- Helper abilities should register when Abilities API functions exist, even if AI plugin Feature classes are unavailable. If the current bootstrap stubs always define AI plugin classes, add a seam around the new availability methods or test the behavior through a dedicated guard method.

Update `tests/phpunit/RegistrationTest.php`:

- Content recommendation permission requires `edit_post` for `postContext.postId`.
- Block recommendation permission checks explicit post scope when present.
- Style recommendation permission does not call or require `edit_post` for `scope.entityId`, `scope.globalStylesId`, or `document.entityId`.
- Template, template-part, and navigation permissions do not treat numeric `entityId` as a post ID.

Update `tests/phpunit/InfraAbilitiesTest.php`:

- `check_status` should report recommendation surfaces unavailable when the AI Feature is disabled.
- Helper abilities should remain in `availableAbilities` when recommendations are disabled if the user has the required capability.

### JS Unit Tests

Update `src/store/__tests__/abilities-client.test.js`:

- Bridge success still wins over REST.
- Bridge readiness/lookup failure falls back to direct REST.
- Bridge permission and invalid-input failures do not fall back.
- Abort-signal execution continues direct REST with the signal.

Update `src/store/__tests__/store-actions.test.js` as needed:

- Apply/review revalidation continues to work when the bridge is present but not ready.
- Existing ability run request shapes remain unchanged: `{ input: requestData }`.

### Targeted Commands

Run these before broader verification:

```bash
composer run test:php -- --filter FeatureBootstrapTest
composer run test:php -- --filter RegistrationTest
composer run test:php -- --filter InfraAbilitiesTest
npm run test:unit -- src/store/__tests__/abilities-client.test.js
npm run test:unit -- src/store/__tests__/store-actions.test.js
```

Then run the fast aggregate gate:

```bash
npm run verify -- --skip-e2e
```

If the changes affect contributor-facing docs or behavior descriptions, run:

```bash
npm run check:docs
```

## Manual Verification

Use a local WordPress nightly/trunk environment with Gutenberg, the AI plugin, provider connectors, MCP Adapter, and Flavor Agent active.

Verify these scenarios:

- Fresh install, AI plugin active, global AI feature toggle off: editor recommendations are unavailable and recommendation abilities are not exposed.
- AI global toggle on, Flavor Agent feature toggle off: helper abilities are exposed, recommendation abilities are not exposed, and UI shows disabled recommendation surfaces.
- AI global toggle on, Flavor Agent feature toggle on: recommendation abilities are exposed and editor recommendation flows work.
- Abilities API available but AI plugin Feature classes unavailable: helper abilities still register and no PHP fatal occurs.
- Global Styles and Style Book recommendations work for a user with `edit_theme_options` even when `globalStylesId` is numeric.
- A temporarily delayed or unready `@wordpress/core-abilities` client store does not break apply/review revalidation because direct REST fallback succeeds.

## Rollout Notes

- Keep recommendation route removal as-is if the Abilities API run endpoint is the intended public contract.
- Do not reintroduce plugin REST recommendation routes unless compatibility with older clients is a stated requirement.
- Keep error messages explicit: missing AI Feature contracts should explain that recommendation UI requires the WordPress AI plugin Feature framework; missing providers should continue pointing users to Settings -> Connectors.

## Definition of Done

- The four reported findings have targeted code fixes.
- New tests fail before the fixes and pass after the fixes.
- `npm run verify -- --skip-e2e` completes or any incomplete step is documented with a concrete environmental reason.
- Documentation still matches the actual runtime behavior: helper abilities are infra, recommendation abilities are AI Feature-gated.
