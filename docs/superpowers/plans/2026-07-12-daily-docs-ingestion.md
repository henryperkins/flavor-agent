# Daily WordPress Docs/News Ingestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `wp-dev-docs` Cloudflare AI Search corpus refreshes daily (stale pruning stays weekly on Mondays) and additionally ingests `wordpress.org/news` (month-dated posts) and `make.wordpress.org/ai` (day-dated posts).

**Architecture:** Surgical widening of the existing pipeline — `scripts/update-docs-ai-search.js` gains two trusted roots, a generalized dated-post recency window, an `xpost-` skip, and per-origin sitemap path scoping for the shared `wordpress.org` origin; `.github/workflows/update-docs-ai-search.yml` gets two cron entries with Monday-only `--delete-stale`; `inc/Support/DocsGroundingSourcePolicy.php` gains two non-gating labels. Spec: `docs/superpowers/specs/2026-07-12-daily-docs-ingestion-design.md`.

**Tech Stack:** Node 24 (or 20) plain-JS script, Jest (`scripts/__tests__/`), GitHub Actions, PHP 8.2 + PHPUnit (`tests/phpunit/`), WordPress coding style (tabs).

## Global Constraints

- JS uses **tabs** for indentation and WordPress spacing style (spaces inside parens: `fn( arg )`); match the surrounding code exactly.
- PHP follows WPCS (`composer lint:php` must stay clean); `declare(strict_types=1)` files.
- Do NOT touch `DOC_LAYOUT_VERSION` (no document-layout change) and never pass `--configure` during verification.
- Do NOT reorder or remove these runbook anchor strings (Jest asserts them): `` Endpoint: `https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search` ``, `` CLOUDFLARE_AI_SEARCH_INSTANCE` (default `wp-dev-docs`) ``, `` public Cloudflare AI Search corpus on `wp-dev-docs` ``, `` instance `wp-dev-docs` / `https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/mcp` ``.
- Jest run command (from repo root, Git Bash): `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`. PHPUnit: `vendor/bin/phpunit --filter <TestClass>` (needs `composer install` once).
- Every commit message ends with the trailer: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Line references below are pre-change positions in the current files; verify anchors by content, not line number, when editing.

---

### Task 1: Generalized dated-post recency window + CLI flag

**Files:**
- Modify: `scripts/update-docs-ai-search.js` (const ~line 22, `usage()` ~58–60, `parseArgs()` ~90 and ~155–157, `makeCorePostDate()` ~308–327, `makeCoreRecencyCutoff()`/`withinMakeCoreWindow()` ~329–353, `discoverSourceUrls()` ~659 and ~697, `module.exports` ~2185–2209)
- Test: `scripts/__tests__/update-docs-ai-search.test.js`

**Interfaces:**
- Consumes: existing `DAY_MS`, `normalizeNonNegativeInteger( value, label )`.
- Produces (later tasks rely on): `wordPressNewsPostDate( url ) => number|null` (exported), `withinRecentPostWindow( url, cutoffMs ) => boolean` (exported), `recentPostRecencyCutoff( options ) => number|null` (module-private), `options.recentPostMaxAgeDays` (parseArgs key; `options.makeCoreMaxAgeDays` still honored as fallback input to the cutoff), `makeCorePostDate` now also parses `/ai/YYYY/MM/DD/` URLs.

- [ ] **Step 1: Write the failing tests**

In `scripts/__tests__/update-docs-ai-search.test.js`, add `wordPressNewsPostDate` and `withinRecentPostWindow` to the destructured `require` at the top (keep alphabetical-ish placement near `makeCorePostDate`/`urlMatchesRoots`):

```js
	makeCorePostDate,
	wordPressNewsPostDate,
	withinRecentPostWindow,
```

Replace the existing `makeCorePostDate` test (line ~287) with:

```js
	test( 'makeCorePostDate parses dated Make subsite post URLs and ignores the rest', () => {
		expect(
			makeCorePostDate( 'https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/' )
		).toBe( Date.parse( '2026-05-14T00:00:00Z' ) );
		expect(
			makeCorePostDate( 'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/' )
		).toBe( Date.parse( '2026-07-08T00:00:00Z' ) );
		expect( makeCorePostDate( 'https://make.wordpress.org/core/7-0/' ) ).toBeNull();
		expect( makeCorePostDate( 'https://make.wordpress.org/core/handbook/about/' ) ).toBeNull();
		expect( makeCorePostDate( 'https://make.wordpress.org/ai/handbook/' ) ).toBeNull();
		expect( makeCorePostDate( 'https://developer.wordpress.org/news/2026/05/01/post/' ) ).toBeNull();
	} );
```

Add three new tests directly after it:

```js
	test( 'wordPressNewsPostDate dates month-dated News posts at month start and ignores the rest', () => {
		expect(
			wordPressNewsPostDate( 'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/' )
		).toBe( Date.parse( '2026-07-01T00:00:00Z' ) );
		expect( wordPressNewsPostDate( 'https://wordpress.org/news/' ) ).toBeNull();
		expect( wordPressNewsPostDate( 'https://wordpress.org/news/category/releases/' ) ).toBeNull();
		expect( wordPressNewsPostDate( 'https://make.wordpress.org/core/2026/05/14/post/' ) ).toBeNull();
	} );

	test( 'withinRecentPostWindow gates dated make and news posts and passes undated docs', () => {
		const cutoff = Date.parse( '2026-01-01T00:00:00Z' );
		expect( withinRecentPostWindow( 'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/', cutoff ) ).toBe( true );
		expect( withinRecentPostWindow( 'https://make.wordpress.org/core/2025/01/15/old-dev-note/', cutoff ) ).toBe( false );
		expect( withinRecentPostWindow( 'https://make.wordpress.org/ai/handbook/', cutoff ) ).toBe( false );
		expect( withinRecentPostWindow( 'https://wordpress.org/news/2026/06/open-web-merch/', cutoff ) ).toBe( true );
		expect( withinRecentPostWindow( 'https://wordpress.org/news/2025/11/old-post/', cutoff ) ).toBe( false );
		expect( withinRecentPostWindow( 'https://developer.wordpress.org/reference/functions/register_block_type/', cutoff ) ).toBe( true );
		expect( withinRecentPostWindow( 'https://wordpress.org/news/2025/11/old-post/', null ) ).toBe( true );
	} );

	test( 'parseArgs accepts the recent-post window flag and its make-core alias', () => {
		expect( parseArgs( [] ).recentPostMaxAgeDays ).toBe( 180 );
		expect( parseArgs( [ '--recent-post-max-age-days=45' ] ).recentPostMaxAgeDays ).toBe( 45 );
		expect( parseArgs( [ '--make-core-max-age-days=90' ] ).recentPostMaxAgeDays ).toBe( 90 );
		expect( () => parseArgs( [ '--recent-post-max-age-days=x' ] ) ).toThrow(
			'recent-post-max-age-days must be a non-negative integer'
		);
	} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: FAIL — `wordPressNewsPostDate` / `withinRecentPostWindow` are `undefined` (TypeError on destructure use), `recentPostMaxAgeDays` is `undefined`, and the `/ai/` `makeCorePostDate` expectation returns `null`.

- [ ] **Step 3: Implement in `scripts/update-docs-ai-search.js`**

3a. Rename the default const (line ~22):

```js
const RECENT_POST_DEFAULT_MAX_AGE_DAYS = 180;
```

(Remove `MAKE_CORE_DEFAULT_MAX_AGE_DAYS`; it has exactly two other references — parseArgs default ~line 90 and the cutoff fallback ~line 333 — both rewritten below.)

3b. In `usage()`, replace the `--make-core-max-age-days` help block (lines ~58–60) with:

```
  --recent-post-max-age-days=<n>  Only ingest dated posts (make.wordpress.org/core and
                         /ai by /YYYY/MM/DD/ permalink date, wordpress.org/news by
                         /YYYY/MM/) published within this many days. 0 ingests every
                         matched post. Default: 180.
                         --make-core-max-age-days is a deprecated alias.
```

3c. In `parseArgs()` defaults, replace `makeCoreMaxAgeDays: MAKE_CORE_DEFAULT_MAX_AGE_DAYS,` with:

```js
		recentPostMaxAgeDays: RECENT_POST_DEFAULT_MAX_AGE_DAYS,
```

3d. In the `parseArgs()` switch, replace the `make-core-max-age-days` case with:

```js
			case 'recent-post-max-age-days':
			case 'make-core-max-age-days':
				options.recentPostMaxAgeDays = normalizeNonNegativeInteger( value, key );
				break;
```

3e. Generalize `makeCorePostDate` (comment + regex only; name and export stay):

```js
// Make subsite posts use dated permalinks (/core/YYYY/MM/DD/slug/, /ai/YYYY/MM/DD/slug/).
// Parse that date so discovery can keep the current release cycle and drop the long-tail
// archive. Returns the UTC publish-day timestamp in ms, or null for non-make or undated URLs.
function makeCorePostDate( url ) {
	let parsed;
	try {
		parsed = new URL( url );
	} catch {
		return null;
	}
	if ( parsed.hostname.toLowerCase() !== 'make.wordpress.org' ) {
		return null;
	}
	const match = parsed.pathname.match( /^\/(?:core|ai)\/(\d{4})\/(\d{2})\/(\d{2})\// );
	if ( ! match ) {
		return null;
	}
	const timestamp = Date.parse( `${ match[ 1 ] }-${ match[ 2 ] }-${ match[ 3 ] }T00:00:00Z` );
	return Number.isFinite( timestamp ) ? timestamp : null;
}
```

3f. Add `wordPressNewsPostDate` directly below it:

```js
// wordpress.org/news posts use month-dated permalinks (/news/YYYY/MM/slug/). Date them
// at the first of the month UTC so the shared recency window can bound the two-decade
// News archive the same way dated Make posts are bounded.
function wordPressNewsPostDate( url ) {
	let parsed;
	try {
		parsed = new URL( url );
	} catch {
		return null;
	}
	if ( parsed.hostname.toLowerCase() !== 'wordpress.org' ) {
		return null;
	}
	const match = parsed.pathname.match( /^\/news\/(\d{4})\/(\d{2})\/[^/]/ );
	if ( ! match ) {
		return null;
	}
	const timestamp = Date.parse( `${ match[ 1 ] }-${ match[ 2 ] }-01T00:00:00Z` );
	return Number.isFinite( timestamp ) ? timestamp : null;
}
```

3g. Replace `makeCoreRecencyCutoff` and `withinMakeCoreWindow` (lines ~329–353) with:

```js
function recentPostRecencyCutoff( options ) {
	const now = Number.isFinite( options.now ) ? options.now : Date.now();
	const maxAgeDays = [ options.recentPostMaxAgeDays, options.makeCoreMaxAgeDays ].find(
		( value ) => Number.isFinite( value )
	);
	const resolved = maxAgeDays === undefined ? RECENT_POST_DEFAULT_MAX_AGE_DAYS : maxAgeDays;
	return resolved > 0 ? now - resolved * DAY_MS : null;
}

// developer.wordpress.org reference/handbook URLs are undated and always pass; bulk-
// discovered dated-permalink posts (make.wordpress.org subsites, wordpress.org/news)
// are gated to the shared recency window. A null cutoff (max-age-days=0) disables the
// gate. Explicit --source-url entries bypass this gate entirely (see discoverSourceUrls).
function withinRecentPostWindow( url, cutoffMs ) {
	let host;
	try {
		host = new URL( url ).hostname.toLowerCase();
	} catch {
		return false;
	}
	if ( cutoffMs === null ) {
		return true;
	}
	if ( host === 'make.wordpress.org' ) {
		const published = makeCorePostDate( url );
		return published !== null && published >= cutoffMs;
	}
	if ( host === 'wordpress.org' ) {
		const published = wordPressNewsPostDate( url );
		return published !== null && published >= cutoffMs;
	}
	return true;
}
```

3h. In `discoverSourceUrls()`: rename local `const makeCoreCutoff = makeCoreRecencyCutoff( options );` (~line 659) to `const recentPostCutoff = recentPostRecencyCutoff( options );` and change the filter call (~line 697) from `withinMakeCoreWindow( normalized, makeCoreCutoff )` to `withinRecentPostWindow( normalized, recentPostCutoff )`.

3i. In `module.exports`, add (alphabetically near existing entries):

```js
	withinRecentPostWindow,
	wordPressNewsPostDate,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: PASS, including the untouched `discoverSourceUrls keeps recent Make/Core posts…` test (it passes `makeCoreMaxAgeDays: 180` directly — the cutoff fallback keeps it working).

- [ ] **Step 5: Commit**

```bash
git add scripts/update-docs-ai-search.js scripts/__tests__/update-docs-ai-search.test.js
git commit -m "feat(docs-ingest): generalize dated-post recency window across make subsites and News

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Trusted roots + document gate for News and make/ai

**Files:**
- Modify: `scripts/update-docs-ai-search.js` (`sourceRootsForRelease()` ~215–230, `isTrustedPath()` ~264–283, `isCorpusDocumentUrl()` ~355–374)
- Test: `scripts/__tests__/update-docs-ai-search.test.js`

**Interfaces:**
- Consumes: Task 1's `withinRecentPostWindow` (already wired into discovery).
- Produces: `sourceRootsForRelease()` returns 8 roots ending with `'https://make.wordpress.org/ai/'`, `'https://wordpress.org/news/'`; `isTrustedPath`/`isCorpusDocumentUrl` accept the new scopes (Tasks 3, 6, 7 rely on these roots existing).

- [ ] **Step 1: Write the failing tests**

Update the roots test (~line 498):

```js
	test( 'sourceRootsForRelease returns every trusted root, not just the first', () => {
		expect( sourceRootsForRelease() ).toEqual( [
			'https://developer.wordpress.org/block-editor/',
			'https://developer.wordpress.org/rest-api/',
			'https://developer.wordpress.org/themes/',
			'https://developer.wordpress.org/reference/',
			'https://developer.wordpress.org/news/',
			'https://make.wordpress.org/core/',
			'https://make.wordpress.org/ai/',
			'https://wordpress.org/news/',
		] );
	} );
```

Update the `isCorpusDocumentUrl` test (~line 353) — replace the whole test with:

```js
	test( 'isCorpusDocumentUrl drops release-cycle index, archive, and xpost pages', () => {
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/2026/05/01/post/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/' ) ).toBe( true );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/block-editor/' ) ).toBe( true );

		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/all-posts/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://developer.wordpress.org/news/tag/block-editor/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/tag/dev-notes-7-0/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/handbook/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/ai/2026/06/29/xpost-wordpress-credits-updates/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://make.wordpress.org/core/2026/06/01/xpost-editor-updates/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/2026/07/' ) ).toBe( false );
		expect( isCorpusDocumentUrl( 'https://wordpress.org/news/category/releases/' ) ).toBe( false );
	} );
```

Add a make/ai discovery test after the existing `discoverSourceUrls keeps recent Make/Core posts…` test (mirror its mock pattern exactly):

```js
	test( 'discoverSourceUrls keeps recent make/ai posts and drops xposts and handbook pages', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://make.wordpress.org/robots.txt' ) {
				return mockTextResponse(
					'Sitemap: https://make.wordpress.org/wp-sitemap.xml',
					href,
					'text/plain'
				);
			}
			if ( href === 'https://make.wordpress.org/wp-sitemap.xml' ) {
				return mockTextResponse(
					[
						'<sitemapindex>',
						'<sitemap><loc>https://make.wordpress.org/wp-sitemap-posts-post-1.xml</loc></sitemap>',
						'</sitemapindex>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://make.wordpress.org/wp-sitemap-posts-post-1.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/</loc></url>',
						'<url><loc>https://make.wordpress.org/ai/2026/06/29/xpost-wordpress-credits-updates/</loc></url>',
						'<url><loc>https://make.wordpress.org/ai/handbook/</loc></url>',
						'<url><loc>https://make.wordpress.org/ai/2025/01/05/old-ai-post/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if (
				href === 'https://make.wordpress.org/ai/wp-sitemap.xml' ||
				href === 'https://make.wordpress.org/ai/sitemap.xml'
			) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://make.wordpress.org/ai/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				recentPostMaxAgeDays: 180,
				now: Date.parse( '2026-07-12T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/',
		] );
	} );
```

Add a News discovery test after it (robots advertises the Jetpack sitemap; speculative root-relative seeds return empty indexes):

```js
	test( 'discoverSourceUrls keeps recent month-dated News posts and drops archives', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://wordpress.org/robots.txt' ) {
				return mockTextResponse(
					'Sitemap: https://wordpress.org/news/sitemap.xml',
					href,
					'text/plain'
				);
			}
			if ( href === 'https://wordpress.org/news/sitemap.xml' ) {
				return mockTextResponse(
					[
						'<sitemapindex>',
						'<sitemap><loc>https://wordpress.org/news/sitemap-2.xml</loc></sitemap>',
						'</sitemapindex>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://wordpress.org/news/sitemap-2.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/</loc></url>',
						'<url><loc>https://wordpress.org/news/2020/08/older-post/</loc></url>',
						'<url><loc>https://wordpress.org/news/category/releases/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://wordpress.org/news/wp-sitemap.xml' ) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://wordpress.org/news/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				recentPostMaxAgeDays: 180,
				now: Date.parse( '2026-07-12T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/',
		] );
	} );
```

Note: the root-relative seed candidates are `https://wordpress.org/news/wp-sitemap.xml` and `https://wordpress.org/news/sitemap.xml`; the latter collides with the robots-advertised URL and is deduped by the `Set`, so the mock's second seed branch may never fire — that is fine (`jest.fn` mocks don't require every branch to be hit).

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: FAIL — roots list mismatch (6 vs 8), `isCorpusDocumentUrl` returns `true` for xpost URLs and `false`-mismatches on the new positive cases, and both new discovery tests return `[]` (roots rejected by `isTrustedPath`).

- [ ] **Step 3: Implement in `scripts/update-docs-ai-search.js`**

3a. `sourceRootsForRelease()` — extend the array (and the comment's first sentence to mention Make subsites generally):

```js
	return [
		'https://developer.wordpress.org/block-editor/',
		'https://developer.wordpress.org/rest-api/',
		'https://developer.wordpress.org/themes/',
		'https://developer.wordpress.org/reference/',
		'https://developer.wordpress.org/news/',
		'https://make.wordpress.org/core/',
		'https://make.wordpress.org/ai/',
		'https://wordpress.org/news/',
	].map( ( value ) => normalizeTrustedUrl( value ) ).filter( Boolean );
```

3b. `isTrustedPath()` — replace the `make.wordpress.org` branch and add a `wordpress.org` branch before the final `return false;`:

```js
	if ( host === 'make.wordpress.org' ) {
		return [ '/core/', '/ai/' ].some(
			( prefix ) => pathName === prefix.slice( 0, -1 ) || pathName.startsWith( prefix )
		);
	}

	if ( host === 'wordpress.org' ) {
		return pathName === '/news' || pathName.startsWith( '/news/' );
	}
```

3c. `isCorpusDocumentUrl()` — replace the `make.wordpress.org` branch and add the News branch before the final `return true;`:

```js
	if (
		host === 'make.wordpress.org' &&
		( pathName === '/core' || pathName.startsWith( '/core/' ) || pathName === '/ai' || pathName.startsWith( '/ai/' ) )
	) {
		return /^\/(?:core|ai)\/\d{4}\/\d{2}\/\d{2}\/(?!xpost-)[a-z0-9][^/]*$/.test( pathName );
	}

	if ( host === 'wordpress.org' && ( pathName === '/news' || pathName.startsWith( '/news/' ) ) ) {
		return /^\/news\/\d{4}\/\d{2}\/[a-z0-9][^/]*$/.test( pathName );
	}
```

(`pathName` is already trailing-slash-stripped at the top of the function, so `/news/2026/07/` month archives reduce to `/news/2026/07` and fail the slug requirement.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: PASS (all tests, including untouched Make/Core discovery tests).

- [ ] **Step 5: Commit**

```bash
git add scripts/update-docs-ai-search.js scripts/__tests__/update-docs-ai-search.test.js
git commit -m "feat(docs-ingest): ingest wordpress.org/news and make.wordpress.org/ai, skip xpost stubs

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Sitemap path scoping for the shared wordpress.org origin

**Files:**
- Modify: `scripts/update-docs-ai-search.js` (`sitemapUrlWithinOrigins()` ~376–395 and its three call sites in `discoverSourceUrls()` ~634, ~647, ~685; `module.exports`)
- Test: `scripts/__tests__/update-docs-ai-search.test.js`

**Interfaces:**
- Consumes: Task 2's `'https://wordpress.org/news/'` trusted root.
- Produces: `sitemapPathPrefixesForRoots( roots ) => Map<origin, string[]>` (exported), `sitemapUrlWithinOrigins( value, allowedOrigins, pathPrefixes = null )` (third optional param; existing two-arg callers/tests unaffected).

- [ ] **Step 1: Write the failing tests**

Add `sitemapPathPrefixesForRoots` to the test file's `require` destructure. Then add after the existing `sitemapUrlWithinOrigins only accepts trusted https origins` test (~line 198):

```js
	test( 'sitemapUrlWithinOrigins scopes wordpress.org sitemaps to trusted path prefixes', () => {
		const roots = [ 'https://wordpress.org/news/', 'https://developer.wordpress.org/block-editor/' ];
		const allowedOrigins = new Set( roots.map( ( root ) => new URL( root ).origin ) );
		const pathPrefixes = sitemapPathPrefixesForRoots( roots );

		expect( pathPrefixes.get( 'https://wordpress.org' ) ).toEqual( [ '/news/' ] );
		expect( pathPrefixes.has( 'https://developer.wordpress.org' ) ).toBe( false );

		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/news/sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( 'https://wordpress.org/news/sitemap.xml' );
		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( '' );
		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/news-sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( '' );
		expect(
			sitemapUrlWithinOrigins( 'https://wordpress.org/plugins/sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( '' );
		expect(
			sitemapUrlWithinOrigins( 'https://developer.wordpress.org/wp-sitemap.xml', allowedOrigins, pathPrefixes )
		).toBe( 'https://developer.wordpress.org/wp-sitemap.xml' );
	} );

	test( 'discoverSourceUrls never crawls wordpress.org sitemaps outside /news/', async () => {
		global.fetch = jest.fn( ( url ) => {
			const href = String( url );
			if ( href === 'https://wordpress.org/robots.txt' ) {
				return mockTextResponse(
					[
						'Sitemap: https://wordpress.org/sitemap.xml',
						'Sitemap: https://wordpress.org/news-sitemap.xml',
						'Sitemap: https://wordpress.org/plugins/sitemap.xml',
						'Sitemap: https://wordpress.org/news/sitemap.xml',
					].join( '\n' ),
					href,
					'text/plain'
				);
			}
			if ( href === 'https://wordpress.org/news/sitemap.xml' ) {
				return mockTextResponse(
					[
						'<urlset>',
						'<url><loc>https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/</loc></url>',
						'</urlset>',
					].join( '' ),
					href
				);
			}
			if ( href === 'https://wordpress.org/news/wp-sitemap.xml' ) {
				return mockTextResponse( '<sitemapindex></sitemapindex>', href );
			}
			throw new Error( `Unexpected fetch: ${ href }` );
		} );

		const { urls } = await discoverSourceUrls(
			[ 'https://wordpress.org/news/' ],
			{
				sourceUrls: [],
				sourceFile: '',
				limit: 0,
				recentPostMaxAgeDays: 180,
				now: Date.parse( '2026-07-12T00:00:00Z' ),
			}
		);

		expect( urls ).toEqual( [
			'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/',
		] );
		const fetched = global.fetch.mock.calls.map( ( call ) => String( call[ 0 ] ) );
		expect( fetched ).not.toContain( 'https://wordpress.org/sitemap.xml' );
		expect( fetched ).not.toContain( 'https://wordpress.org/news-sitemap.xml' );
		expect( fetched ).not.toContain( 'https://wordpress.org/plugins/sitemap.xml' );
	} );
```

(The strict `throw new Error( 'Unexpected fetch…' )` fallback means the test fails loudly if scoping regresses.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: FAIL — `sitemapPathPrefixesForRoots` is not a function; the discovery test throws `Unexpected fetch: https://wordpress.org/sitemap.xml`.

- [ ] **Step 3: Implement in `scripts/update-docs-ai-search.js`**

3a. Add above `sitemapUrlWithinOrigins`:

```js
// Origins whose robots.txt advertises sitemaps far outside the trusted roots (the
// shared wordpress.org origin also hosts /plugins/, /themes/, and the giant root
// sitemap). For these origins only, sitemap crawling is additionally scoped to the
// trusted roots' path prefixes; every other origin keeps origin-wide discovery so
// developer.wordpress.org's root wp-sitemap.xml continues to work.
const SITEMAP_PATH_SCOPED_ORIGINS = new Set( [ 'https://wordpress.org' ] );

function sitemapPathPrefixesForRoots( roots ) {
	const prefixes = new Map();
	for ( const root of roots ) {
		let url;
		try {
			url = new URL( root );
		} catch {
			continue;
		}
		if ( ! SITEMAP_PATH_SCOPED_ORIGINS.has( url.origin ) ) {
			continue;
		}
		const prefix = url.pathname.endsWith( '/' ) ? url.pathname : `${ url.pathname }/`;
		const list = prefixes.get( url.origin ) || [];
		if ( ! list.includes( prefix ) ) {
			list.push( prefix );
		}
		prefixes.set( url.origin, list );
	}
	return prefixes;
}
```

3b. Extend `sitemapUrlWithinOrigins` with the optional third parameter — final body:

```js
function sitemapUrlWithinOrigins( value, allowedOrigins, pathPrefixes = null ) {
	const raw = String( value || '' ).trim();
	if ( ! raw ) {
		return '';
	}
	let url;
	try {
		url = new URL( raw );
	} catch {
		return '';
	}
	if ( url.protocol !== 'https:' ) {
		return '';
	}
	if ( ! allowedOrigins.has( url.origin ) ) {
		return '';
	}
	const prefixes = pathPrefixes && pathPrefixes.get( url.origin );
	if ( prefixes && ! prefixes.some( ( prefix ) => url.pathname.startsWith( prefix ) ) ) {
		return '';
	}
	return url.toString();
}
```

3c. In `discoverSourceUrls()`, after `const allowedOrigins = …` (~line 629) add:

```js
	const sitemapPathPrefixes = sitemapPathPrefixesForRoots( roots );
```

and pass `sitemapPathPrefixes` as the third argument at all three `sitemapUrlWithinOrigins(…)` call sites (robots discovery ~634, root seeding ~647, nested sitemaps ~685).

3d. Add `sitemapPathPrefixesForRoots,` to `module.exports`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: PASS — including the pre-existing two-arg `sitemapUrlWithinOrigins` test and Task 2's News discovery test (its robots mock only advertises `/news/sitemap.xml`, which still qualifies).

- [ ] **Step 5: Commit**

```bash
git add scripts/update-docs-ai-search.js scripts/__tests__/update-docs-ai-search.test.js
git commit -m "feat(docs-ingest): scope wordpress.org sitemap crawling to /news/

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Two-cron workflow — daily ingest, Monday prune

**Files:**
- Modify: `.github/workflows/update-docs-ai-search.yml` (`on.schedule` lines 3–5, `permissions` block ~37–38, `INPUT_DELETE_STALE` line 53)
- Test: `scripts/__tests__/update-docs-ai-search.test.js` (test `workflow enables stale deletion for scheduled runs…` ~line 708)

**Interfaces:**
- Consumes: nothing from earlier tasks (independent).
- Produces: the schedule/deletion contract Task 6's runbook text describes.

- [ ] **Step 1: Update the failing workflow test**

Replace the test at ~line 708 with:

```js
	test( 'workflow enables stale deletion only for the Monday scheduled run', () => {
		const workflow = fs.readFileSync(
			path.resolve( __dirname, '../../.github/workflows/update-docs-ai-search.yml' ),
			'utf8'
		);

		expect( workflow ).toContain(
			"INPUT_DELETE_STALE: ${{ github.event_name == 'schedule' && github.event.schedule == '17 5 * * 1' && 'true' || github.event.inputs.delete_stale || 'false' }}"
		);
		expect( workflow ).toContain( "- cron: '17 5 * * 0,2-6'" );
		expect( workflow ).toContain( "- cron: '17 5 * * 1'" );
		expect( workflow ).toMatch( /concurrency:\s*\n\s*group: update-docs-ai-search\s*\n\s*cancel-in-progress: false/ );
		expect( workflow ).toMatch( /delete_stale:[\s\S]*?default: false/ );
	} );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand -t 'Monday scheduled run'`
Expected: FAIL — the workflow still contains the old single-cron expression.

- [ ] **Step 3: Edit the workflow**

3a. Replace the `schedule:` block:

```yaml
on:
  schedule:
    # Daily incremental ingest (every day except Monday) — no stale deletion.
    - cron: '17 5 * * 0,2-6'
    # Monday run — the weekly stale-deletion pass.
    - cron: '17 5 * * 1'
```

3b. After the `permissions:` block, add:

```yaml
concurrency:
  group: update-docs-ai-search
  cancel-in-progress: false
```

3c. Replace the `INPUT_DELETE_STALE` line with:

```yaml
      INPUT_DELETE_STALE: ${{ github.event_name == 'schedule' && github.event.schedule == '17 5 * * 1' && 'true' || github.event.inputs.delete_stale || 'false' }}
```

(GitHub expression semantics: `&&`/`||` return operand values and `&&` binds tighter — non-Monday schedules resolve to `'false'` because `inputs.delete_stale` is empty on schedule events; workflow_dispatch keeps the opt-in input.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: PASS (also re-runs the untouched `workflow fallback corpus…` and `workflow requires explicit opt-in…` tests against the edited file).

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/update-docs-ai-search.yml scripts/__tests__/update-docs-ai-search.test.js
git commit -m "feat(docs-ingest): run corpus ingest daily with Monday-only stale pruning

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Runtime source labels for the new scopes

**Files:**
- Modify: `inc/Support/DocsGroundingSourcePolicy.php`
- Test: `tests/phpunit/DocsGroundingSourcePolicyTest.php`

**Interfaces:**
- Consumes: nothing (labels are derived from chunk URLs at runtime).
- Produces: `DocsGroundingSourcePolicy::SOURCE_MAKE_AI = 'make-ai'`, `DocsGroundingSourcePolicy::SOURCE_WORDPRESS_NEWS = 'wordpress-news'`. No other PHP or JS consumer maps label→display text (verified: `FormatsDocsGuidance` only special-cases `roadmap`; `src/` has zero occurrences of label strings), so no further display-string changes exist.

- [ ] **Step 1: Write the failing test**

In `tests/phpunit/DocsGroundingSourcePolicyTest.php`, add these assertions to the existing test method, right after the `make-core` assertion (mirror the existing `assertSame` style):

```php
		$this->assertSame(
			'make-ai',
			DocsGroundingSourcePolicy::label_for_url( 'https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/' )
		);
		$this->assertSame(
			'wordpress-news',
			DocsGroundingSourcePolicy::label_for_url( 'https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/' )
		);
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::label_for_url( 'https://wordpress.org/plugins/whatever/' ),
			'non-news wordpress.org paths keep the neutral label'
		);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DocsGroundingSourcePolicyTest`
Expected: FAIL — `'make-core'` returned for the `/ai/` URL and `'developer-docs'` for the News URL.

- [ ] **Step 3: Implement in `inc/Support/DocsGroundingSourcePolicy.php`**

3a. Add two constants after `SOURCE_MAKE_CORE` (align the `=` per WPCS):

```php
	public const SOURCE_MAKE_AI        = 'make-ai';
	public const SOURCE_WORDPRESS_NEWS = 'wordpress-news';
```

3b. Replace the `make.wordpress.org` branch in `label_for_url()` and add the News branch before the `developer.wordpress.org` check:

```php
		if ( 'make.wordpress.org' === $host ) {
			if ( '/ai' === $path || str_starts_with( $path, '/ai/' ) ) {
				return self::SOURCE_MAKE_AI;
			}

			return self::SOURCE_MAKE_CORE;
		}

		if ( 'wordpress.org' === $host && ( '/news' === $path || str_starts_with( $path, '/news/' ) ) ) {
			return self::SOURCE_WORDPRESS_NEWS;
		}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter DocsGroundingSourcePolicyTest`
Expected: PASS. Then run `vendor/bin/phpunit --filter AISearchClientTest` — expected PASS (existing make-core/developer-docs labeling assertions are unaffected). Then `composer lint:php` — expected clean.

- [ ] **Step 5: Commit**

```bash
git add inc/Support/DocsGroundingSourcePolicy.php tests/phpunit/DocsGroundingSourcePolicyTest.php
git commit -m "feat(docs-grounding): label make/ai and WordPress News chunks distinctly

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Contract docs — runbook + CLAUDE.md

**Files:**
- Modify: `docs/reference/developer-docs-public-corpus-runbook.md`, `CLAUDE.md`
- Test: `scripts/__tests__/update-docs-ai-search.test.js` (extend `corpus runbook documents the updater defaults` ~line 736)

**Interfaces:**
- Consumes: Tasks 1–5 behavior (documents it).
- Produces: the updated corpus contract; preserves all four anchor strings from Global Constraints.

- [ ] **Step 1: Extend the runbook drift-guard test**

Inside the existing `corpus runbook documents the updater defaults` test, add at the end:

```js
		expect( runbook ).toContain( 'https://wordpress.org/news/' );
		expect( runbook ).toContain( 'https://make.wordpress.org/ai/' );
		expect( runbook ).toContain( '--recent-post-max-age-days' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand -t 'runbook documents'`
Expected: FAIL — the runbook does not yet mention the new scopes or flag.

- [ ] **Step 3: Edit `docs/reference/developer-docs-public-corpus-runbook.md`**

3a. **Required source scopes** (list ending at `make.wordpress.org/core/`): append two bullets:

```markdown
- `https://make.wordpress.org/ai/` (dated `/ai/YYYY/MM/DD/` posts, bounded to the shared recency window; `xpost-*` cross-post stubs and `/ai/handbook/` pages are excluded)
- `https://wordpress.org/news/` (month-dated `/news/YYYY/MM/` posts, bounded to the shared recency window; sitemap discovery for the shared wordpress.org origin is scoped to `/news/` so the root, plugins, and themes sitemaps are never crawled)
```

3b. **Discovery paragraph** (the paragraph beginning "The updater discovers Make/Core posts…"): replace with:

```markdown
The updater discovers Make subsite posts from the `make.wordpress.org` subsite sitemaps (`/core/wp-sitemap.xml`, `/ai/wp-sitemap.xml`, since the network-root `robots.txt` does not advertise them) and WordPress News posts from the robots-advertised Jetpack sitemap at `wordpress.org/news/sitemap.xml`. It keeps only dated posts whose permalink date falls within `--recent-post-max-age-days` (default 180; `--make-core-max-age-days` remains a deprecated alias). Make posts are day-dated (`/YYYY/MM/DD/`); News posts are month-dated (`/news/YYYY/MM/`) and are dated at month start, so they can enter the corpus up to a month later than their nominal age. That captures the active cycle's dev notes, Field Guide, RC, Gutenberg-release, AI-team, and News posts without dragging in the long-tail archives, and it self-maintains across release cycles without per-release tag edits. Make-subsite `xpost-*` stubs are skipped. Keep the stable `developer.wordpress.org` scopes unless `DocsGroundingSourcePolicy` changes.
```

3c. **Source Update Workflow paragraph:** change the sentence `Scheduled runs pass `--delete-stale` by default; manual dispatch remains opt-in through the `delete_stale` input.` to:

```markdown
The workflow is scheduled daily: only the Monday run passes `--delete-stale`, the other daily runs ingest without pruning, and manual dispatch remains opt-in through the `delete_stale` input.
```

3d. In the stale-deletion paragraph (the one beginning "Make/Core recency is tunable…"), replace its first sentence with:

```markdown
Dated-post recency is tunable with `--recent-post-max-age-days=<n>` (default 180; `0` ingests every matched dated post; `--make-core-max-age-days` is a deprecated alias).
```

and change "the weekly schedule passes it automatically" to "the Monday schedule passes it automatically".

3e. **Source Eligibility:** extend the release-cycle scope list with `- `make.wordpress.org/ai/`` and `- `wordpress.org/news/``, and after the freshness sentence for make-core/developer-blog add:

```markdown
`make-ai` and `wordpress-news` chunks count as current when published within 21 days of validation, with the same WordPress 7.0 release-date rule as `make-core`.
```

3f. **Refresh cadence:** replace the three bullets with:

```markdown
- Daily incremental ingest via the scheduled workflow (every day except Monday) — no stale deletion.
- Weekly stale-deletion pass on the Monday scheduled run.
- If a Make/Core Field Guide, dev-note batch, RC post, or Gutenberg release post lands and the daily run failed, dispatch the workflow manually within 48 hours.
```

3g. **Validation:** in the "Confirm at least one…" bullet, keep the requirement unchanged but append: `Record any `make-ai` or `wordpress-news` chunks in the evidence; they are not required for validation to pass.`

- [ ] **Step 4: Edit `CLAUDE.md`**

In the MCP Tooling paragraph, change "consult it for Gutenberg, block editor, REST API, theme/theme.json, code-reference, Developer Blog, and current release-cycle decisions covered by the managed corpus" to "consult it for Gutenberg, block editor, REST API, theme/theme.json, code-reference, Developer Blog, WordPress News, Make/AI updates, and current release-cycle decisions covered by the managed corpus".

- [ ] **Step 5: Run tests + docs guard to verify they pass**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand`
Expected: PASS (drift-guard assertions now find the new strings; the four anchor strings still present).
Run: `npm run check:docs`
Expected: exit 0. If it flags the runbook/CLAUDE.md as stale-tracked, follow the tool's output to refresh the freshness record it maintains — do not silence it.

- [ ] **Step 6: Commit**

```bash
git add docs/reference/developer-docs-public-corpus-runbook.md CLAUDE.md scripts/__tests__/update-docs-ai-search.test.js
git commit -m "docs(corpus): document daily ingest cadence and News/make-ai scopes

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Full verification + live dry-run smoke

**Files:**
- No source changes. Read: `output/verify/summary.json`, `output/docs-ai-search/` artifacts.

**Interfaces:**
- Consumes: everything above.
- Produces: verification evidence; the spec's extraction-quality checkpoint.

- [ ] **Step 1: Full unit + PHP suites**

Run: `npx jest scripts/__tests__/update-docs-ai-search.test.js --runInBand` → PASS.
Run: `vendor/bin/phpunit` → PASS (full PHP suite).

- [ ] **Step 2: Aggregate verify**

Run: `node scripts/verify.js --skip-e2e` (append `--skip=lint-plugin` only if WP-CLI/Docker Plugin Check is unavailable in this environment).
Expected: `VERIFY_RESULT={"status":"pass",…}`. Inspect `output/verify/summary.json` — every non-skipped step `"status": "pass"`. E2E is intentionally skipped: no editor-behavior change (record this as the harness waiver per the cross-surface gates).

- [ ] **Step 3: Targeted live dry-run smoke (network, no Cloudflare writes)**

Run:

```bash
node scripts/update-docs-ai-search.js --dry-run \
  --source-url=https://wordpress.org/news/2026/07/wordpress-7-0-1-maintenance-release/ \
  --source-url=https://make.wordpress.org/ai/2026/07/08/whats-new-in-ai-1-1-0/
```

Expected: both URLs accepted (targeted runs bypass discovery and the recency gate), `Prepared 2 Markdown documents`, zero build errors in the console summary and in `output/docs-ai-search/` artifacts. **Extraction checkpoint (from the spec):** confirm the run reports non-trivial extracted content for both pages (no "empty document"/build-error entries). If either page extracts poorly, stop and add a host-specific extraction branch before shipping — that is a new task, not a silent fix.

- [ ] **Step 4: Bounded discovery smoke**

Run: `node scripts/update-docs-ai-search.js --dry-run --limit=40`
Expected: discovery output includes URLs from `wordpress.org/news/` and `make.wordpress.org/ai/` alongside the existing scopes; zero non-404 discovery errors; no fetches of `wordpress.org/sitemap.xml`, `/plugins/`, or `/themes/` sitemaps in the console output.

- [ ] **Step 5: Record and hand off rollout**

The first real write is operator-gated (repo secrets): dispatch **Update Developer Docs AI Search** manually from the Actions tab (or `gh workflow run update-docs-ai-search.yml`) with `delete_stale` left false, then verify the run summary artifact and one live public-endpoint search for a fresh News post. Subsequent daily/Monday crons need no action. Note the rollout status in `STATUS.md` if the working log is being kept current.

---

## Plan Self-Review (completed at authoring time)

- **Spec coverage:** roots/window/xpost (Tasks 1–2), sitemap scoping (Task 3), two-cron + concurrency + Monday-only deletion (Task 4), labels (Task 5), runbook/CLAUDE.md + 21-day windows + unchanged validation minimums (Task 6), dry-run extraction checkpoint + manual-dispatch rollout + verify gates (Task 7). `published_at` needs no change (extracted from page HTML generically). "Display strings" resolved: no label→display mapping exists anywhere (verified), so Task 5's constants + tests are complete.
- **Placeholder scan:** none; every code step carries the code.
- **Type consistency:** `recentPostMaxAgeDays` (options key), `recentPostRecencyCutoff`, `withinRecentPostWindow`, `wordPressNewsPostDate`, `sitemapPathPrefixesForRoots` used identically across tasks; `makeCorePostDate` name intentionally retained (exported, extended to `/ai/`).
