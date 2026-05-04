PS C:\Users\htper\flavor-agent> npm run verify

> flavor-agent@0.1.0 verify
> node scripts/verify.js


[RUN] build — npm run build

> flavor-agent@0.1.0 build
> wp-scripts build

assets by status 299 KiB [compared for emit]
  assets by path *.css 286 KiB
    assets by chunk 216 KiB (name: activity-log) 2 assets
    assets by chunk 70.1 KiB (name: admin) 2 assets
  assets by path *.php 622 bytes
    asset index.asset.php 304 bytes [emitted] [compared for emit] (name: index)
    asset activity-log.asset.php 234 bytes [emitted] [compared for emit] (name: activity-log)
    asset admin.asset.php 84 bytes [compared for emit] (name: admin)
  asset admin.js 12.7 KiB [compared for emit] [minimized] (name: admin)
assets by status 2.26 MiB [big]
  asset activity-log.js 1.87 MiB [emitted] [minimized] [big] (name: activity-log)
  asset index.js 398 KiB [emitted] [minimized] [big] (name: index)
asset index-rtl.css 51.1 KiB [emitted] (name: index)
asset index.css 51.1 KiB [emitted] (name: index)
Entrypoint index [big] 501 KiB = index.css 51.1 KiB index.js 398 KiB index-rtl.css 51.1 KiB index.asset.php 304 bytes
Entrypoint admin 82.9 KiB = admin.css 35.1 KiB admin.js 12.7 KiB admin-rtl.css 35.1 KiB admin.asset.php 84 bytes
Entrypoint activity-log [big] 2.08 MiB = activity-log.css 108 KiB activity-log.js 1.87 MiB activity-log-rtl.css 108 KiB activity-log.asset.php 234 bytes
orphan modules 8.6 MiB (javascript) 6.41 KiB (runtime) [orphan] 3282 modules
runtime modules 2.74 KiB 9 modules
built modules 4.43 MiB (javascript) 171 KiB (css/mini-extract) [built]
  javascript modules 4.43 MiB
    cacheable modules 52.1 KiB 10 modules
    ./src/index.js + 103 modules 898 KiB [not cacheable] [built] [code generated]
    ./src/admin/activity-log.js + 667 modules 3.51 MiB [not cacheable] [built] [code generated]
    external "React" 42 bytes [built] [code generated]
  css modules 171 KiB
    modules by path ./src/admin/ 120 KiB
      css ./node_modules/css-loader/dist/cjs.js??ruleSet[1].rules[1].use[1]!./node_modules/postcss-loader/dist/cjs.js??ruleSet[1].rules[1].use[2]!./src/admin/wpds-runtime.css 12.4 KiB [built] [code generated]
      + 4 modules
    modules by path ./src/*.css 51.1 KiB
      css ./node_modules/css-loader/dist/cjs.js??ruleSet[1].rules[1].use[1]!./node_modules/postcss-loader/dist/cjs.js??ruleSet[1].rules[1].use[2]!./src/tokens.css 2.74 KiB [built] [code generated]
      css ./node_modules/css-loader/dist/cjs.js??ruleSet[1].rules[1].use[1]!./node_modules/postcss-loader/dist/cjs.js??ruleSet[1].rules[1].use[2]!./src/editor.css 48.3 KiB [built] [code generated]

WARNING in asset size limit: The following asset(s) exceed the recommended size limit (244 KiB).
This can impact web performance.
Assets: 
  index.js (398 KiB)
  activity-log.js (1.87 MiB)

WARNING in entrypoint size limit: The following entrypoint(s) combined asset size exceeds the recommended limit (244 KiB). This can impact web performance.
Entrypoints:
  index (501 KiB)
      index.css
      index.js
      index-rtl.css
      index.asset.php
  activity-log (2.08 MiB)
      activity-log.css
      activity-log.js
      activity-log-rtl.css
      activity-log.asset.php


WARNING in webpack performance recommendations: 
You can limit the size of your bundles by using import() or require.ensure to lazy load some parts of your application.
For more info visit https://webpack.js.org/guides/code-splitting/

webpack 5.105.4 compiled with 3 warnings in 11618 ms
[PASS] build (14052ms, exit=0)

[RUN] lint-js — npm run lint:js

> flavor-agent@0.1.0 lint:js
> wp-scripts lint-js src/

Warning: Legacy eslintrc configuration detected. ESLint v10 no longer supports eslintrc files. Please migrate to eslint.config.js (flat config). See https://eslint.org/docs/latest/use/configure/migration-guide for details.
node:internal/modules/cjs/loader:1479
  throw err;
  ^

Error: Cannot find module 'eslint/package.json'
Require stack:
- C:\Users\htper\flavor-agent\node_modules\resolve-bin\index.js
- C:\Users\htper\flavor-agent\node_modules\@wordpress\scripts\scripts\lint-js.js
    at Module._resolveFilename (node:internal/modules/cjs/loader:1476:15)
    at wrapResolveFilename (node:internal/modules/cjs/loader:1049:27)
    at resolveForCJSWithHooks (node:internal/modules/cjs/loader:1094:12)
    at require.resolve (node:internal/modules/helpers:171:31)
    at requireResolve (C:\Users\htper\flavor-agent\node_modules\resolve-bin\index.js:10:27)
    at sync (C:\Users\htper\flavor-agent\node_modules\resolve-bin\index.js:69:13)
    at Object.<anonymous> (C:\Users\htper\flavor-agent\node_modules\@wordpress\scripts\scripts\lint-js.js:63:2)
    at Module._compile (node:internal/modules/cjs/loader:1830:14)
    at Object..js (node:internal/modules/cjs/loader:1961:10)
    at Module.load (node:internal/modules/cjs/loader:1553:32) {
  code: 'MODULE_NOT_FOUND',
  requireStack: [
    'C:\\Users\\htper\\flavor-agent\\node_modules\\resolve-bin\\index.js',
    'C:\\Users\\htper\\flavor-agent\\node_modules\\@wordpress\\scripts\\scripts\\lint-js.js'
  ]
}

Node.js v24.15.0
[FAIL] lint-js (730ms, exit=1)

[RUN] unit — npm run test:unit -- --runInBand

> flavor-agent@0.1.0 test:unit
> wp-scripts test-unit-js --runInBand

PASS src/store/__tests__/store-actions.test.js
PASS src/utils/__tests__/template-actions.test.js
PASS src/store/update-helpers.test.js
PASS src/inspector/__tests__/BlockRecommendationsPanel.test.js
PASS src/context/__tests__/collector.test.js
PASS src/global-styles/__tests__/GlobalStylesRecommender.test.js
PASS src/templates/__tests__/TemplateRecommender.test.js
PASS src/patterns/__tests__/PatternRecommender.test.js
PASS src/inspector/__tests__/NavigationRecommendations.test.js
PASS src/template-parts/__tests__/TemplatePartRecommender.test.js
PASS src/admin/__tests__/activity-log.test.js
PASS src/style-book/__tests__/StyleBookRecommender.test.js
PASS src/utils/__tests__/style-operations.test.js
PASS src/admin/__tests__/settings-page-controller.test.js
PASS src/templates/__tests__/template-recommender-helpers.test.js
PASS src/context/__tests__/theme-tokens.test.js
PASS src/admin/__tests__/activity-log-utils.test.js
PASS src/store/__tests__/activity-history.test.js
PASS src/components/__tests__/AIActivitySection.test.js
PASS src/store/__tests__/template-apply-state.test.js
PASS src/patterns/__tests__/compat.test.js
PASS src/store/__tests__/block-request-state.test.js
PASS src/utils/__tests__/block-operation-catalog.test.js
PASS src/content/__tests__/ContentRecommender.test.js
PASS src/utils/__tests__/block-structural-actions.test.js
PASS src/template-parts/__tests__/template-part-recommender-helpers.test.js
PASS src/patterns/__tests__/InserterBadge.test.js
PASS src/inspector/__tests__/InspectorInjector.test.js
PASS src/inspector/__tests__/SuggestionChips.test.js
PASS src/components/__tests__/UndoToast.test.js
PASS src/components/__tests__/ToastRegion.test.js
PASS src/store/__tests__/toasts.test.js
PASS src/utils/__tests__/recommendation-actionability.test.js
PASS src/utils/__tests__/capability-flags.test.js
PASS src/components/__tests__/ActivitySessionBootstrap.test.js
PASS scripts/__tests__/verify.test.js
PASS src/store/__tests__/activity-undo.test.js
PASS src/components/__tests__/SurfaceComposer.test.js
PASS src/components/__tests__/AIAdvisorySection.test.js
PASS src/store/__tests__/navigation-request-state.test.js
PASS src/store/__tests__/pattern-status.test.js
PASS src/utils/__tests__/visible-patterns.test.js
PASS src/utils/__tests__/editor-entity-contracts.test.js
PASS src/context/__tests__/block-inspector.test.js
PASS src/components/__tests__/SurfaceScopeBar.test.js
PASS src/patterns/__tests__/pattern-insertability.test.js
PASS src/utils/__tests__/structural-identity.test.js
PASS src/patterns/__tests__/recommendation-utils.test.js
PASS src/inspector/__tests__/panel-delegation.test.js
PASS src/utils/__tests__/style-design-semantics.test.js
PASS src/utils/__tests__/block-recommendation-context.test.js
PASS src/components/__tests__/StaleResultBanner.test.js
PASS src/store/__tests__/activity-history-state.test.js
PASS src/utils/__tests__/block-allowed-pattern-context.test.js
PASS src/components/__tests__/AIReviewSection.test.js
PASS src/inspector/__tests__/block-recommendation-request.test.js
PASS src/patterns/__tests__/inserter-badge-state.test.js
PASS src/review/__tests__/notes-adapter.test.js
PASS src/components/__tests__/LinkedEntityText.test.js
PASS src/components/__tests__/SurfacePanelIntro.test.js
PASS src/utils/__tests__/template-part-areas.test.js
PASS src/patterns/__tests__/find-inserter-search-input.test.js
PASS src/components/__tests__/CapabilityNotice.test.js
PASS src/inspector/__tests__/block-review-state.test.js
PASS src/components/__tests__/InlineActionFeedback.test.js
PASS src/style-surfaces/__tests__/presentation.test.js
PASS src/components/__tests__/RecommendationHero.test.js
PASS src/utils/__tests__/live-structure-snapshots.test.js
PASS src/components/__tests__/AIStatusNotice.test.js
PASS src/inspector/suggestion-keys.test.js
PASS src/utils/__tests__/template-types.test.js
PASS src/components/__tests__/RecommendationLane.test.js
PASS src/utils/__tests__/editor-context-metadata.test.js
PASS src/store/__tests__/block-targeting.test.js
PASS src/utils/__tests__/template-operation-sequence.test.js
PASS src/utils/__tests__/structural-equality.test.js
PASS src/utils/__tests__/format-count.test.js

Test Suites: 77 passed, 77 total
Tests:       878 passed, 878 total
Snapshots:   0 total
Time:        16.834 s
Ran all test suites.
[PASS] unit (18299ms, exit=0)

[RUN] lint-php — composer lint:php

FILE: inc\Support\MetricsNormalizer.php
--------------------------------------------------------------------------------
FOUND 1 ERROR AFFECTING 1 LINE
--------------------------------------------------------------------------------
 1 | ERROR | [x] End of line character is invalid; expected "\n" but found
   |       |     "\r\n"
--------------------------------------------------------------------------------
PHPCBF CAN FIX THE 1 MARKED SNIFF VIOLATIONS AUTOMATICALLY
--------------------------------------------------------------------------------


FILE: tests\phpunit\MetricsNormalizerTest.php
--------------------------------------------------------------------------------
FOUND 1 ERROR AFFECTING 1 LINE
--------------------------------------------------------------------------------
 1 | ERROR | [x] End of line character is invalid; expected "\n" but found
   |       |     "\r\n"
--------------------------------------------------------------------------------
PHPCBF CAN FIX THE 1 MARKED SNIFF VIOLATIONS AUTOMATICALLY
--------------------------------------------------------------------------------


FILE: tests\phpunit\SupportToPanelSyncTest.php
--------------------------------------------------------------------------------
FOUND 1 ERROR AFFECTING 1 LINE
--------------------------------------------------------------------------------
 1 | ERROR | [x] End of line character is invalid; expected "\n" but found
   |       |     "\r\n"
--------------------------------------------------------------------------------
PHPCBF CAN FIX THE 1 MARKED SNIFF VIOLATIONS AUTOMATICALLY
--------------------------------------------------------------------------------

Time: 14.87 secs; Memory: 74MB

Script phpcs handling the lint:php event returned with error code 2
[FAIL] lint-php (16322ms, exit=2)

[RUN] test-php — composer test:php
PHPUnit 9.6.34 by Sebastian Bergmann and contributors.

...............................................................  63 / 974 (  6%)
............................................................... 126 / 974 ( 12%)
............................................................... 189 / 974 ( 19%)
............................................................... 252 / 974 ( 25%)
............................................................... 315 / 974 ( 32%)
............................................................... 378 / 974 ( 38%)
............................................................... 441 / 974 ( 45%)
............................................................... 504 / 974 ( 51%)
............................................................... 567 / 974 ( 58%)
............................................................... 630 / 974 ( 64%)
............................................................... 693 / 974 ( 71%)
............................................................... 756 / 974 ( 77%)
............................................................... 819 / 974 ( 84%)
............................................................... 882 / 974 ( 90%)
............................................................... 945 / 974 ( 97%)
.............................                                   974 / 974 (100%)

Time: 00:07.034, Memory: 26.00 MB

OK (974 tests, 4551 assertions)
[flavor-agent] PostContentRenderer: render_block failed for flavor-agent-test/explody - boom
[flavor-agent] PostContentRenderer: render_block failed for flavor-agent-test/voice-sample-explody - voice sample render boom
[flavor-agent] PostVoiceSampleCollector: dropping post 311 due to block render failure marker
[PASS] test-php (8381ms, exit=0)

[RUN] e2e-playground — npm run test:e2e:playground

> flavor-agent@0.1.0 test:e2e:playground
> playwright test


Running 10 tests using 1 worker

[WebServer] lockWholeFile: unlock failed for pid=1 fd=5 path=C:\Users\htper\AppData\Local\Temp\node.exe-playground-cli-site-24744--24744-jODDtS0bv2bO\wordpress/wp-content/database/.htaccess
[WebServer] lockWholeFile: unlock failed for pid=1 fd=5 path=C:\Users\htper\AppData\Local\Temp\node.exe-playground-cli-site-24744--24744-jODDtS0bv2bO\wordpress/wp-content/database/index.php
  ✓   1 tests\e2e\flavor-agent.activity.spec.js:127:1 › AI Activity page loads entries, updates selection, and exposes the filters UI (21.7s)
  ✓   2 tests\e2e\flavor-agent.activity.spec.js:194:1 › AI Activity page renders an inline load error instead of the empty activity copy (4.4s)
  ✓   3 tests\e2e\flavor-agent.settings.spec.js:22:1 › settings page keeps compact help-first IA without changing accordion behavior (3.5s)
  ✓   4 tests\e2e\flavor-agent.smoke.spec.js:2538:1 › content panel renders for a brand-new unsaved post (9.7s)
  ✓   5 tests\e2e\flavor-agent.smoke.spec.js:2561:1 › block and pattern surfaces explain unavailable providers in native UI (12.8s)
  ✓   6 tests\e2e\flavor-agent.smoke.spec.js:2649:1 › navigation surface smoke renders advisory recommendations for a selected navigation block (11.7s)
  ✓   7 tests\e2e\flavor-agent.smoke.spec.js:2731:1 › pattern surface smoke uses the inserter search to fetch recommendations (11.8s)
  ✓   8 tests\e2e\flavor-agent.smoke.spec.js:3535:1 › template surface keeps stale results visible but disables review and apply until refresh (16.6s)
  ✓   9 tests\e2e\flavor-agent.smoke.spec.js:3705:1 › template surface keeps advisory-only suggestions visible without executable controls (15.2s)
  ✓  10 tests\e2e\flavor-agent.smoke.spec.js:3815:1 › template surface explains unavailable plugin backends (19.4s)

  10 passed (2.2m)
[PASS] e2e-playground (135712ms, exit=0)

[RUN] e2e-wp70 — npm run test:e2e:wp70

> flavor-agent@0.1.0 test:e2e:wp70
> playwright test -c playwright.wp70.config.js

Error: Command failed: node C:\Users\htper\flavor-agent\scripts\docker-compose.js up -d --build
Sending build context to Docker daemon  3.226MB
Step 1/7 : ARG WORDPRESS_BASE_IMAGE=wordpress:php8.2-apache
Step 2/7 : FROM ${WORDPRESS_BASE_IMAGE}
beta-7.0-RC2-php8.2-apache: Pulling from library/wordpress
a912c6f26be3: Download complete 
c139571ff158: Pull complete 
0c21e9ce3f2a: Download complete 
3531af2bc2a9: Pull complete 
c8742a8b5110: Download complete 
606c3726da5b: Download complete 
ed2d43d4b55b: Pull complete 
c9b8b189dc69: Pull complete 
e8a8cf04a812: Pull complete 
71195e2a9321: Pull complete 
4f28510ba763: Extracting 1 s
4f4fb700ef54: Already exists 
05efc06ff0ce: Download complete 
16ff35669217: Download complete 
164cc7096095: Pull complete 
d0290e85f148: Pull complete 
c4def3af76bf: Download complete 
84a036c91981: Download complete 
a86e378d265e: Download complete 
782c88202ec5: Download complete 
89521cc6076e: Download complete 
ac26cf47bd88: Pull complete 
5b94d1751a12: Download complete 
540cd2e0df84: Download complete 
23ea7b1648c9: Download complete 
3f86d2ccca62: Download complete 

time="2026-05-03T22:57:20-05:00" level=warning msg="Docker Compose requires buildx plugin to be installed"
 Image flavor-agent-wp70-wordpress Building 
 Image flavor-agent-wp70-wordpress Building 
unexpected EOF

   at ..\..\scripts\wp70-e2e.js:89

  87 |
  88 |  if ( result.status !== 0 && ! options.allowFailure ) {
> 89 |          throw new Error(
     |                ^
  90 |                  [
  91 |                          `Command failed: ${ command } ${ args.join( ' ' ) }`,
  92 |                          result.stdout?.trim() || '',
    at runCommand (C:\Users\htper\flavor-agent\scripts\wp70-e2e.js:89:9)
    at runDockerCompose (C:\Users\htper\flavor-agent\scripts\wp70-e2e.js:105:9)
    at bootstrapWp70Harness (C:\Users\htper\flavor-agent\scripts\wp70-e2e.js:237:2)
    at globalSetup (C:\Users\htper\flavor-agent\tests\e2e\wp70.global-setup.js:4:8)

[FAIL] e2e-wp70 (31061ms, exit=1)
VERIFY_RESULT={"status":"fail","summaryPath":"output\\verify\\summary.json","counts":{"total":8,"passed":4,"failed":3,"skipped":1}}PS C:\Users\htper\flavor-agent> npm run verify

> flavor-agent@0.1.0 verify
> node scripts/verify.js


[RUN] build — npm run build

> flavor-agent@0.1.0 build
> wp-scripts build

assets by status 299 KiB [compared for emit]
  assets by path *.css 286 KiB
    assets by chunk 216 KiB (name: activity-log) 2 assets
    assets by chunk 70.1 KiB (name: admin) 2 assets
  assets by path *.php 622 bytes
    asset index.asset.php 304 bytes [emitted] [compared for emit] (name: index)
    asset activity-log.asset.php 234 bytes [emitted] [compared for emit] (name: activity-log)
    asset admin.asset.php 84 bytes [compared for emit] (name: admin)
  asset admin.js 12.7 KiB [compared for emit] [minimized] (name: admin)
assets by status 2.26 MiB [big]
  asset activity-log.js 1.87 MiB [emitted] [minimized] [big] (name: activity-log)
  asset index.js 398 KiB [emitted] [minimized] [big] (name: index)
asset index-rtl.css 51.1 KiB [emitted] (name: index)
asset index.css 51.1 KiB [emitted] (name: index)
Entrypoint index [big] 501 KiB = index.css 51.1 KiB index.js 398 KiB index-rtl.css 51.1 KiB index.asset.php 304 bytes
Entrypoint admin 82.9 KiB = admin.css 35.1 KiB admin.js 12.7 KiB admin-rtl.css 35.1 KiB admin.asset.php 84 bytes
Entrypoint activity-log [big] 2.08 MiB = activity-log.css 108 KiB activity-log.js 1.87 MiB activity-log-rtl.css 108 KiB activity-log.asset.php 234 bytes
orphan modules 8.6 MiB (javascript) 6.41 KiB (runtime) [orphan] 3282 modules
runtime modules 2.74 KiB 9 modules
built modules 4.43 MiB (javascript) 171 KiB (css/mini-extract) [built]
  javascript modules 4.43 MiB
    cacheable modules 52.1 KiB 10 modules
    ./src/index.js + 103 modules 898 KiB [not cacheable] [built] [code generated]
    ./src/admin/activity-log.js + 667 modules 3.51 MiB [not cacheable] [built] [code generated]
    external "React" 42 bytes [built] [code generated]
  css modules 171 KiB
    modules by path ./src/admin/ 120 KiB
      css ./node_modules/css-loader/dist/cjs.js??ruleSet[1].rules[1].use[1]!./node_modules/postcss-loader/dist/cjs.js??ruleSet[1].rules[1].use[2]!./src/admin/wpds-runtime.css 12.4 KiB [built] [code generated]
      + 4 modules
    modules by path ./src/*.css 51.1 KiB
      css ./node_modules/css-loader/dist/cjs.js??ruleSet[1].rules[1].use[1]!./node_modules/postcss-loader/dist/cjs.js??ruleSet[1].rules[1].use[2]!./src/tokens.css 2.74 KiB [built] [code generated]
      css ./node_modules/css-loader/dist/cjs.js??ruleSet[1].rules[1].use[1]!./node_modules/postcss-loader/dist/cjs.js??ruleSet[1].rules[1].use[2]!./src/editor.css 48.3 KiB [built] [code generated]

WARNING in asset size limit: The following asset(s) exceed the recommended size limit (244 KiB).
This can impact web performance.
Assets: 
  index.js (398 KiB)
  activity-log.js (1.87 MiB)

WARNING in entrypoint size limit: The following entrypoint(s) combined asset size exceeds the recommended limit (244 KiB). This can impact web performance.
Entrypoints:
  index (501 KiB)
      index.css
      index.js
      index-rtl.css
      index.asset.php
  activity-log (2.08 MiB)
      activity-log.css
      activity-log.js
      activity-log-rtl.css
      activity-log.asset.php


WARNING in webpack performance recommendations: 
You can limit the size of your bundles by using import() or require.ensure to lazy load some parts of your application.
For more info visit https://webpack.js.org/guides/code-splitting/

webpack 5.105.4 compiled with 3 warnings in 11618 ms
[PASS] build (14052ms, exit=0)

[RUN] lint-js — npm run lint:js

> flavor-agent@0.1.0 lint:js
> wp-scripts lint-js src/

Warning: Legacy eslintrc configuration detected. ESLint v10 no longer supports eslintrc files. Please migrate to eslint.config.js (flat config). See https://eslint.org/docs/latest/use/configure/migration-guide for details.
node:internal/modules/cjs/loader:1479
  throw err;
  ^

Error: Cannot find module 'eslint/package.json'
Require stack:
- C:\Users\htper\flavor-agent\node_modules\resolve-bin\index.js
- C:\Users\htper\flavor-agent\node_modules\@wordpress\scripts\scripts\lint-js.js
    at Module._resolveFilename (node:internal/modules/cjs/loader:1476:15)
    at wrapResolveFilename (node:internal/modules/cjs/loader:1049:27)
    at resolveForCJSWithHooks (node:internal/modules/cjs/loader:1094:12)
    at require.resolve (node:internal/modules/helpers:171:31)
    at requireResolve (C:\Users\htper\flavor-agent\node_modules\resolve-bin\index.js:10:27)
    at sync (C:\Users\htper\flavor-agent\node_modules\resolve-bin\index.js:69:13)
    at Object.<anonymous> (C:\Users\htper\flavor-agent\node_modules\@wordpress\scripts\scripts\lint-js.js:63:2)
    at Module._compile (node:internal/modules/cjs/loader:1830:14)
    at Object..js (node:internal/modules/cjs/loader:1961:10)
    at Module.load (node:internal/modules/cjs/loader:1553:32) {
  code: 'MODULE_NOT_FOUND',
  requireStack: [
    'C:\\Users\\htper\\flavor-agent\\node_modules\\resolve-bin\\index.js',
    'C:\\Users\\htper\\flavor-agent\\node_modules\\@wordpress\\scripts\\scripts\\lint-js.js'
  ]
}

Node.js v24.15.0
[FAIL] lint-js (730ms, exit=1)

[RUN] unit — npm run test:unit -- --runInBand

> flavor-agent@0.1.0 test:unit
> wp-scripts test-unit-js --runInBand

PASS src/store/__tests__/store-actions.test.js
PASS src/utils/__tests__/template-actions.test.js
PASS src/store/update-helpers.test.js
PASS src/inspector/__tests__/BlockRecommendationsPanel.test.js
PASS src/context/__tests__/collector.test.js
PASS src/global-styles/__tests__/GlobalStylesRecommender.test.js
PASS src/templates/__tests__/TemplateRecommender.test.js
PASS src/patterns/__tests__/PatternRecommender.test.js
PASS src/inspector/__tests__/NavigationRecommendations.test.js
PASS src/template-parts/__tests__/TemplatePartRecommender.test.js
PASS src/admin/__tests__/activity-log.test.js
PASS src/style-book/__tests__/StyleBookRecommender.test.js
PASS src/utils/__tests__/style-operations.test.js
PASS src/admin/__tests__/settings-page-controller.test.js
PASS src/templates/__tests__/template-recommender-helpers.test.js
PASS src/context/__tests__/theme-tokens.test.js
PASS src/admin/__tests__/activity-log-utils.test.js
PASS src/store/__tests__/activity-history.test.js
PASS src/components/__tests__/AIActivitySection.test.js
PASS src/store/__tests__/template-apply-state.test.js
PASS src/patterns/__tests__/compat.test.js
PASS src/store/__tests__/block-request-state.test.js
PASS src/utils/__tests__/block-operation-catalog.test.js
PASS src/content/__tests__/ContentRecommender.test.js
PASS src/utils/__tests__/block-structural-actions.test.js
PASS src/template-parts/__tests__/template-part-recommender-helpers.test.js
PASS src/patterns/__tests__/InserterBadge.test.js
PASS src/inspector/__tests__/InspectorInjector.test.js
PASS src/inspector/__tests__/SuggestionChips.test.js
PASS src/components/__tests__/UndoToast.test.js
PASS src/components/__tests__/ToastRegion.test.js
PASS src/store/__tests__/toasts.test.js
PASS src/utils/__tests__/recommendation-actionability.test.js
PASS src/utils/__tests__/capability-flags.test.js
PASS src/components/__tests__/ActivitySessionBootstrap.test.js
PASS scripts/__tests__/verify.test.js
PASS src/store/__tests__/activity-undo.test.js
PASS src/components/__tests__/SurfaceComposer.test.js
PASS src/components/__tests__/AIAdvisorySection.test.js
PASS src/store/__tests__/navigation-request-state.test.js
PASS src/store/__tests__/pattern-status.test.js
PASS src/utils/__tests__/visible-patterns.test.js
PASS src/utils/__tests__/editor-entity-contracts.test.js
PASS src/context/__tests__/block-inspector.test.js
PASS src/components/__tests__/SurfaceScopeBar.test.js
PASS src/patterns/__tests__/pattern-insertability.test.js
PASS src/utils/__tests__/structural-identity.test.js
PASS src/patterns/__tests__/recommendation-utils.test.js
PASS src/inspector/__tests__/panel-delegation.test.js
PASS src/utils/__tests__/style-design-semantics.test.js
PASS src/utils/__tests__/block-recommendation-context.test.js
PASS src/components/__tests__/StaleResultBanner.test.js
PASS src/store/__tests__/activity-history-state.test.js
PASS src/utils/__tests__/block-allowed-pattern-context.test.js
PASS src/components/__tests__/AIReviewSection.test.js
PASS src/inspector/__tests__/block-recommendation-request.test.js
PASS src/patterns/__tests__/inserter-badge-state.test.js
PASS src/review/__tests__/notes-adapter.test.js
PASS src/components/__tests__/LinkedEntityText.test.js
PASS src/components/__tests__/SurfacePanelIntro.test.js
PASS src/utils/__tests__/template-part-areas.test.js
PASS src/patterns/__tests__/find-inserter-search-input.test.js
PASS src/components/__tests__/CapabilityNotice.test.js
PASS src/inspector/__tests__/block-review-state.test.js
PASS src/components/__tests__/InlineActionFeedback.test.js
PASS src/style-surfaces/__tests__/presentation.test.js
PASS src/components/__tests__/RecommendationHero.test.js
PASS src/utils/__tests__/live-structure-snapshots.test.js
PASS src/components/__tests__/AIStatusNotice.test.js
PASS src/inspector/suggestion-keys.test.js
PASS src/utils/__tests__/template-types.test.js
PASS src/components/__tests__/RecommendationLane.test.js
PASS src/utils/__tests__/editor-context-metadata.test.js
PASS src/store/__tests__/block-targeting.test.js
PASS src/utils/__tests__/template-operation-sequence.test.js
PASS src/utils/__tests__/structural-equality.test.js
PASS src/utils/__tests__/format-count.test.js

Test Suites: 77 passed, 77 total
Tests:       878 passed, 878 total
Snapshots:   0 total
Time:        16.834 s
Ran all test suites.
[PASS] unit (18299ms, exit=0)

[RUN] lint-php — composer lint:php

FILE: inc\Support\MetricsNormalizer.php
--------------------------------------------------------------------------------
FOUND 1 ERROR AFFECTING 1 LINE
--------------------------------------------------------------------------------
 1 | ERROR | [x] End of line character is invalid; expected "\n" but found
   |       |     "\r\n"
--------------------------------------------------------------------------------
PHPCBF CAN FIX THE 1 MARKED SNIFF VIOLATIONS AUTOMATICALLY
--------------------------------------------------------------------------------


FILE: tests\phpunit\MetricsNormalizerTest.php
--------------------------------------------------------------------------------
FOUND 1 ERROR AFFECTING 1 LINE
--------------------------------------------------------------------------------
 1 | ERROR | [x] End of line character is invalid; expected "\n" but found
   |       |     "\r\n"
--------------------------------------------------------------------------------
PHPCBF CAN FIX THE 1 MARKED SNIFF VIOLATIONS AUTOMATICALLY
--------------------------------------------------------------------------------


FILE: tests\phpunit\SupportToPanelSyncTest.php
--------------------------------------------------------------------------------
FOUND 1 ERROR AFFECTING 1 LINE
--------------------------------------------------------------------------------
 1 | ERROR | [x] End of line character is invalid; expected "\n" but found
   |       |     "\r\n"
--------------------------------------------------------------------------------
PHPCBF CAN FIX THE 1 MARKED SNIFF VIOLATIONS AUTOMATICALLY
--------------------------------------------------------------------------------

Time: 14.87 secs; Memory: 74MB

Script phpcs handling the lint:php event returned with error code 2
[FAIL] lint-php (16322ms, exit=2)

[RUN] test-php — composer test:php
PHPUnit 9.6.34 by Sebastian Bergmann and contributors.

...............................................................  63 / 974 (  6%)
............................................................... 126 / 974 ( 12%)
............................................................... 189 / 974 ( 19%)
............................................................... 252 / 974 ( 25%)
............................................................... 315 / 974 ( 32%)
............................................................... 378 / 974 ( 38%)
............................................................... 441 / 974 ( 45%)
............................................................... 504 / 974 ( 51%)
............................................................... 567 / 974 ( 58%)
............................................................... 630 / 974 ( 64%)
............................................................... 693 / 974 ( 71%)
............................................................... 756 / 974 ( 77%)
............................................................... 819 / 974 ( 84%)
............................................................... 882 / 974 ( 90%)
............................................................... 945 / 974 ( 97%)
.............................                                   974 / 974 (100%)

Time: 00:07.034, Memory: 26.00 MB

OK (974 tests, 4551 assertions)
[flavor-agent] PostContentRenderer: render_block failed for flavor-agent-test/explody - boom
[flavor-agent] PostContentRenderer: render_block failed for flavor-agent-test/voice-sample-explody - voice sample render boom
[flavor-agent] PostVoiceSampleCollector: dropping post 311 due to block render failure marker
[PASS] test-php (8381ms, exit=0)

[RUN] e2e-playground — npm run test:e2e:playground

> flavor-agent@0.1.0 test:e2e:playground
> playwright test


Running 10 tests using 1 worker

[WebServer] lockWholeFile: unlock failed for pid=1 fd=5 path=C:\Users\htper\AppData\Local\Temp\node.exe-playground-cli-site-24744--24744-jODDtS0bv2bO\wordpress/wp-content/database/.htaccess
[WebServer] lockWholeFile: unlock failed for pid=1 fd=5 path=C:\Users\htper\AppData\Local\Temp\node.exe-playground-cli-site-24744--24744-jODDtS0bv2bO\wordpress/wp-content/database/index.php
  ✓   1 tests\e2e\flavor-agent.activity.spec.js:127:1 › AI Activity page loads entries, updates selection, and exposes the filters UI (21.7s)
  ✓   2 tests\e2e\flavor-agent.activity.spec.js:194:1 › AI Activity page renders an inline load error instead of the empty activity copy (4.4s)
  ✓   3 tests\e2e\flavor-agent.settings.spec.js:22:1 › settings page keeps compact help-first IA without changing accordion behavior (3.5s)
  ✓   4 tests\e2e\flavor-agent.smoke.spec.js:2538:1 › content panel renders for a brand-new unsaved post (9.7s)
  ✓   5 tests\e2e\flavor-agent.smoke.spec.js:2561:1 › block and pattern surfaces explain unavailable providers in native UI (12.8s)
  ✓   6 tests\e2e\flavor-agent.smoke.spec.js:2649:1 › navigation surface smoke renders advisory recommendations for a selected navigation block (11.7s)
  ✓   7 tests\e2e\flavor-agent.smoke.spec.js:2731:1 › pattern surface smoke uses the inserter search to fetch recommendations (11.8s)
  ✓   8 tests\e2e\flavor-agent.smoke.spec.js:3535:1 › template surface keeps stale results visible but disables review and apply until refresh (16.6s)
  ✓   9 tests\e2e\flavor-agent.smoke.spec.js:3705:1 › template surface keeps advisory-only suggestions visible without executable controls (15.2s)
  ✓  10 tests\e2e\flavor-agent.smoke.spec.js:3815:1 › template surface explains unavailable plugin backends (19.4s)

  10 passed (2.2m)
[PASS] e2e-playground (135712ms, exit=0)

[RUN] e2e-wp70 — npm run test:e2e:wp70

> flavor-agent@0.1.0 test:e2e:wp70
> playwright test -c playwright.wp70.config.js

Error: Command failed: node C:\Users\htper\flavor-agent\scripts\docker-compose.js up -d --build
Sending build context to Docker daemon  3.226MB
Step 1/7 : ARG WORDPRESS_BASE_IMAGE=wordpress:php8.2-apache
Step 2/7 : FROM ${WORDPRESS_BASE_IMAGE}
beta-7.0-RC2-php8.2-apache: Pulling from library/wordpress
a912c6f26be3: Download complete 
c139571ff158: Pull complete 
0c21e9ce3f2a: Download complete 
3531af2bc2a9: Pull complete 
c8742a8b5110: Download complete 
606c3726da5b: Download complete 
ed2d43d4b55b: Pull complete 
c9b8b189dc69: Pull complete 
e8a8cf04a812: Pull complete 
71195e2a9321: Pull complete 
4f28510ba763: Extracting 1 s
4f4fb700ef54: Already exists 
05efc06ff0ce: Download complete 
16ff35669217: Download complete 
164cc7096095: Pull complete 
d0290e85f148: Pull complete 
c4def3af76bf: Download complete 
84a036c91981: Download complete 
a86e378d265e: Download complete 
782c88202ec5: Download complete 
89521cc6076e: Download complete 
ac26cf47bd88: Pull complete 
5b94d1751a12: Download complete 
540cd2e0df84: Download complete 
23ea7b1648c9: Download complete 
3f86d2ccca62: Download complete 

time="2026-05-03T22:57:20-05:00" level=warning msg="Docker Compose requires buildx plugin to be installed"
 Image flavor-agent-wp70-wordpress Building 
 Image flavor-agent-wp70-wordpress Building 
unexpected EOF

   at ..\..\scripts\wp70-e2e.js:89

  87 |
  88 |  if ( result.status !== 0 && ! options.allowFailure ) {
> 89 |          throw new Error(
     |                ^
  90 |                  [
  91 |                          `Command failed: ${ command } ${ args.join( ' ' ) }`,
  92 |                          result.stdout?.trim() || '',
    at runCommand (C:\Users\htper\flavor-agent\scripts\wp70-e2e.js:89:9)
    at runDockerCompose (C:\Users\htper\flavor-agent\scripts\wp70-e2e.js:105:9)
    at bootstrapWp70Harness (C:\Users\htper\flavor-agent\scripts\wp70-e2e.js:237:2)
    at globalSetup (C:\Users\htper\flavor-agent\tests\e2e\wp70.global-setup.js:4:8)

[FAIL] e2e-wp70 (31061ms, exit=1)
VERIFY_RESULT={"status":"fail","summaryPath":"output\\verify\\summary.json","counts":{"total":8,"passed":4,"failed":3,"skipped":1}}<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\Guidelines\LegacyGuidelinesRepository;
use FlavorAgent\Guidelines\PromptGuidelinesFormatter;
use FlavorAgent\Guidelines\RepositoryResolver;
use FlavorAgent\Support\WordPressAIPolicy;

final class Guidelines {

	public const OPTION_SITE                  = 'flavor_agent_guideline_site';
	public const OPTION_COPY                  = 'flavor_agent_guideline_copy';
	public const OPTION_IMAGES                = 'flavor_agent_guideline_images';
	public const OPTION_ADDITIONAL            = 'flavor_agent_guideline_additional';
	public const OPTION_BLOCKS                = 'flavor_agent_guideline_blocks';
	public const OPTION_MIGRATION_STATUS      = 'flavor_agent_guidelines_migration_status';
	public const MAX_LENGTH                   = 5000;
	public const MIGRATION_STATUS_NOT_STARTED = 'not_started';

	/**
	 * @return array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>}
	 */
	public static function get_all(): array {
		return RepositoryResolver::resolve()->get_all();
	}

	public static function get_guideline( string $category ): string {
		$guidelines = self::get_all();

		return match ( $category ) {
			'site', 'copy', 'images', 'additional' => $guidelines[ $category ],
			default => '',
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_block_guidelines(): array {
		return self::get_all()['blocks'];
	}

	public static function get_block_guideline( string $block_name ): string {
		$guidelines = self::get_block_guidelines();

		return $guidelines[ $block_name ] ?? '';
	}

	public static function has_any(): bool {
		return RepositoryResolver::has_any( self::get_all() );
	}

	/**
	 * @return array{source: string, core_available: bool, legacy_has_data: bool, migration_status: string, migration_completed: bool}
	 */
	public static function storage_status(): array {
		$migration = self::get_migration_status();

		return [
			'source'              => RepositoryResolver::resolve()->source(),
			'core_available'      => RepositoryResolver::core_available(),
			'legacy_has_data'     => RepositoryResolver::has_any( ( new LegacyGuidelinesRepository() )->get_all() ),
			'migration_status'    => $migration['status'],
			'migration_completed' => 'completed' === $migration['status'],
		];
	}

	public static function uses_core_storage(): bool {
		return 'legacy_options' !== self::storage_status()['source'];
	}

	public static function format_prompt_context( string $block_name = '' ): string {
		$upstream = WordPressAIPolicy::upstream_guidelines_for_prompt(
			[ 'site', 'copy', 'images', 'additional' ],
			$block_name
		);

		if ( '' !== $upstream ) {
			return $upstream;
		}

		return PromptGuidelinesFormatter::format( self::get_all(), $block_name );
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function get_migration_status(): array {
		$raw = get_option( self::OPTION_MIGRATION_STATUS, [] );

		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$status = sanitize_key( (string) ( $raw['status'] ?? self::MIGRATION_STATUS_NOT_STARTED ) );

		if ( '' === $status ) {
			$status = self::MIGRATION_STATUS_NOT_STARTED;
		}

		return [
			'status'  => $status,
			'message' => sanitize_text_field( (string) ( $raw['message'] ?? '' ) ),
		];
	}

	public static function record_migration_status( string $status, string $message = '' ): void {
		update_option(
			self::OPTION_MIGRATION_STATUS,
			[
				'status'  => sanitize_key( $status ),
				'message' => sanitize_text_field( $message ),
			],
			false
		);
	}

	/**
	 * @return array{
	 *   guideline_categories: array{
	 *     site: array{guidelines: string},
	 *     copy: array{guidelines: string},
	 *     images: array{guidelines: string},
	 *     additional: array{guidelines: string},
	 *     blocks: array<string, array{guidelines: string}>
	 *   }
	 * }
	 */
	public static function export_payload(): array {
		$guidelines = self::get_all();

		return [
			'guideline_categories' => [
				'site'       => [
					'guidelines' => $guidelines['site'],
				],
				'copy'       => [
					'guidelines' => $guidelines['copy'],
				],
				'images'     => [
					'guidelines' => $guidelines['images'],
				],
				'additional' => [
					'guidelines' => $guidelines['additional'],
				],
				'blocks'     => array_reduce(
					array_keys( $guidelines['blocks'] ),
					static function ( array $carry, string $block_name ) use ( $guidelines ): array {
						$carry[ $block_name ] = [
							'guidelines' => $guidelines['blocks'][ $block_name ],
						];

						return $carry;
					},
					[]
				),
			],
		];
	}

	public static function sanitize_guideline_text( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$sanitized = sanitize_textarea_field( (string) $value );

		$length = function_exists( 'mb_strlen' )
			? mb_strlen( $sanitized, 'UTF-8' )
			: strlen( $sanitized );

		if ( $length > self::MAX_LENGTH ) {
			$sanitized = function_exists( 'mb_substr' )
				? (string) mb_substr( $sanitized, 0, self::MAX_LENGTH, 'UTF-8' )
				: substr( $sanitized, 0, self::MAX_LENGTH );
		}

		return $sanitized;
	}

	/**
	 * @return array<string, string>
	 */
	public static function sanitize_block_guidelines( mixed $value ): array {
		return self::normalize_block_guidelines( self::decode_block_guidelines_value( $value ) );
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_content_block_options(): array {
		$options  = [];
		$registry = \WP_Block_Type_Registry::get_instance();

		$registered_blocks = method_exists( $registry, 'get_all_registered' )
			? $registry->get_all_registered()
			: [];

		foreach ( $registered_blocks as $block_type ) {
			if ( ! self::block_has_content_role( $block_type ) ) {
				continue;
			}

			$label = is_string( $block_type->title ?? null ) && '' !== $block_type->title
				? $block_type->title
				: (string) $block_type->name;

			$options[] = [
				'value' => (string) $block_type->name,
				'label' => $label,
			];
		}

		usort(
			$options,
			static function ( array $left, array $right ): int {
				$label_comparison = strcasecmp( $left['label'], $right['label'] );

				if ( 0 !== $label_comparison ) {
					return $label_comparison;
				}

				return strcmp( $left['value'], $right['value'] );
			}
		);

		return $options;
	}

	private static function block_has_content_role( mixed $block_type ): bool {
		if ( ! is_object( $block_type ) || ! is_array( $block_type->attributes ?? null ) ) {
			return false;
		}

		foreach ( $block_type->attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			if ( isset( $attribute['role'] ) && 'content' === $attribute['role'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, string>
	 */
	private static function normalize_block_guidelines( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$blocks = [];

		foreach ( $value as $block_name => $block_data ) {
			$block_name = is_string( $block_name ) ? $block_name : '';

			if ( '' === $block_name || ! self::is_valid_block_name( $block_name ) ) {
				continue;
			}

			$guidelines = '';

			if ( is_array( $block_data ) ) {
				$guidelines = self::sanitize_guideline_text( $block_data['guidelines'] ?? '' );
			} else {
				$guidelines = self::sanitize_guideline_text( $block_data );
			}

			if ( '' === $guidelines ) {
				continue;
			}

			$blocks[ $block_name ] = $guidelines;
		}

		if ( [] !== $blocks ) {
			ksort( $blocks, SORT_NATURAL | SORT_FLAG_CASE );
		}

		return $blocks;
	}

	private static function decode_block_guidelines_value( mixed $value ): mixed {
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return [];
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return [];
		}

		$decoded = json_decode( $trimmed, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return [];
		}

		return $decoded;
	}

	private static function is_valid_block_name( string $block_name ): bool {
		return 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block_name );
	}
}
