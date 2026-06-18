# Gutenberg 23.4 Alignment - 2026-06-18

Status: started / incomplete. This record captures static/code alignment and
docs-grounding evidence for Gutenberg 23.4. It is not browser runtime evidence.

Local runtime note: after the static pass, the Docker-backed local WordPress
stack was updated from Gutenberg `23.3.2` to `23.4.0` with
`wp plugin install gutenberg --version=23.4.0 --force --activate`. WordPress
core reported `7.1-alpha-62511`.

## Source Set

-   Make/Core: https://make.wordpress.org/core/2026/06/17/whats-new-in-gutenberg-23-4-june-17-2026/
-   GitHub release: https://github.com/WordPress/gutenberg/releases/tag/v23.4.0

## Completed Static Alignment

-   `core/loginout` is now parsed as a supported navigation item when it appears
    inside `core/navigation-submenu`, with a stable `Log in/out` label for
    prompt/context inventory.
-   Pattern Inserter user-facing copy now mirrors Gutenberg 23.4's "Pattern"
    wording for the no-patterns notice and successful direct-insert snackbar.
-   The matching Playwright snackbar expectation was updated so browser evidence
    checks the same copy when the harness runs.

## Docs Grounding Smoke

Endpoint:
`https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search`

Request shape:

```json
{
	"messages": [
		{
			"role": "user",
			"content": "Gutenberg 23.4.0 release notes React 19 DataViews entity view config Global Styles Tooltip Loginout navigation submenu"
		}
	],
	"ai_search_options": {
		"retrieval": {
			"max_num_results": 6
		}
	}
}
```

Observed 2026-06-18 result summary:

-   HTTP `200`, six chunks returned.
-   Top 23.4-specific query result was still the 2026-06-03 Gutenberg 23.3
    Make/Core post, retrieved `2026-06-17T04:25:53Z`.
-   Current-cycle sources were present, including the 2026-06-10 Developer Blog
    "What's new for developers? (June 2026)" and developer/reference chunks.
-   The 2026-06-17 Make/Core 23.4 release post did not appear in the top six
    chunks for the exact 23.4 query.

Conclusion: the public endpoint is live and current enough to return recent
Make/Core / Developer Blog material, but the 23.4 release post still needs a
scheduled or manual corpus refresh before treating 23.4-specific grounding as
release evidence.

## Verification Run

-   `vendor/bin/phpunit --filter NavigationParserTest` — pass, 24 tests / 70
    assertions.
-   `npm run test:unit -- --runTestsByPath src/patterns/__tests__/PatternRecommender.test.js --runInBand` — pass, 63 tests.

## Open Runtime Gates

-   Record React / ReactDOM versions from the editor runtime.
-   Enable the React 19 experiment when available and smoke Flavor Agent editor,
    Pattern Inserter, AI Activity/DataViews, Global Styles, and Style Book
    surfaces.
-   Build a Navigation block with a Navigation Submenu containing Login/out and
    verify the parsed recommendation context matches the static test.
-   Re-run the public docs endpoint after the next docs AI Search corpus refresh
    and confirm the 2026-06-17 Gutenberg 23.4 Make/Core post appears for an exact
    23.4 query.
