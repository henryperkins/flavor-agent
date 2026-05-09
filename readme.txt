=== Flavor Agent ===
Contributors: hperkins
Tags: ai, blocks, patterns, editor
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted Gutenberg and wp-admin recommendations for blocks, patterns, content, templates, navigation, styles, and activity.

== Description ==

Flavor Agent integrates AI-assisted editorial and design guidance directly into the WordPress editor and admin. It can surface contextual recommendations for blocks, patterns, content, templates, template parts, navigation, Global Styles, and Style Book while keeping setup inside standard WordPress admin screens.

Results are recommendations, generated text, or review-first suggestions. Flavor Agent does not publish content, contact visitors, or automatically rewrite posts on activation. Content recommendations are editorial-only: copy any generated text into the editor yourself. Structural, style, and template changes remain review-first where supported.

= Setup ownership =

* Recommendation surfaces require the WordPress AI plugin (`ai`) to be installed and active because Flavor Agent registers as a downstream AI feature and ability provider.
* Text generation for recommendations is configured through `Settings > Connectors` and the WordPress AI Client.
* One effective embedding model is configured in `Settings > Flavor Agent` for Flavor Agent semantic features. Pattern storage is configured separately: Qdrant uses that embedding model plus Qdrant, while Cloudflare AI Search reuses the same Cloudflare credentials and a normalized AI Search embedding model. Flavor Agent schedules creation or adoption of the deterministic managed `flavor-agent-patterns-{site_hash}` AI Search pattern instance, validates schema, owner marker, and normalized embedding model in the provisioning callback, then records the validated signature before pattern sync can use the index.
* Pattern sync runs only after the selected pattern backend is configured; administrators can also start a sync manually from `Settings > Flavor Agent`.
* WordPress developer-doc grounding uses Flavor Agent's built-in public Cloudflare AI Search endpoint during user-triggered recommendation requests. Site owners do not enter Cloudflare credentials for developer docs.
* Optional GitHub core-roadmap guidance is off by default and requires a developer opt-in filter.

= External services =

Flavor Agent can send data to the services below after an administrator configures the related backend or an authorized user explicitly requests a recommendation, validation, sync, or docs search. Browser code calls only this site's WordPress REST API; third-party calls are made server-side through WordPress HTTP APIs or the WordPress AI Client / Connectors runtime.

* OpenAI — Used for text generation when the WordPress AI Client routes to the OpenAI connector. Text-generation prompts can include editor context, post/page content, selected block data, templates, navigation, style context, guidelines, and cached docs snippets. OpenAI Terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/
* Cloudflare Workers AI — Used for the Flavor Agent embedding model when account ID and API token are saved in `Settings > Flavor Agent`; a blank model field uses the default Workers AI embedding model. Embedding requests can include validation probe text, pattern index probe text, pattern text, pattern metadata included in embedding text, and Qdrant pattern-search query text when Qdrant pattern storage is used. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
* Connector-backed chat providers such as Anthropic — Used through `Settings > Connectors` when the WordPress AI Client routes text generation to that connector. Flavor Agent sends the same recommendation prompt data described for text generation above. Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms Privacy: https://www.anthropic.com/privacy
* Qdrant — Used for vector-backed pattern recommendations after Qdrant URL and API key are saved in `Settings > Flavor Agent` and the Qdrant pattern backend is selected. Requests can include vectors, collection metadata, and point payloads with pattern names, titles, descriptions, categories, block/template metadata, inferred traits, synced-pattern identifiers/status, and pattern content. Qdrant Terms: https://qdrant.tech/legal/terms_and_conditions/ Privacy: https://qdrant.tech/legal/privacy-policy/
* Cloudflare AI Search for private pattern retrieval — Used when the Cloudflare AI Search pattern backend is selected and the Cloudflare Workers AI Embedding Model account ID and API token are saved in `Settings > Flavor Agent`; blank or unsupported model values use `@cf/qwen/qwen3-embedding-0.6b` for the private AI Search pattern index. When adopting an existing deterministic `flavor-agent-patterns-{site_hash}` AI Search pattern instance in the `patterns` namespace, Flavor Agent validates its metadata schema, owner marker, and normalized AI Search embedding model; when creating a new managed instance, it sends the expected schema/model, writes and validates the owner marker, and records the validated signature. Requests can include managed-instance list/create calls, owner-marker reads/uploads, pattern item uploads with title, description, categories, block/template metadata, inferred traits, public-safe pattern content, synced identifier when available, recommendation query text, and visible pattern names as nested AI Search retrieval filters. Sync uploads only public-safe registered patterns and published user `wp_block` patterns across synced, partial, and unsynced states, deletes only stale remote items previously recorded by Flavor Agent, and preserves unknown remote items and the owner marker; recommendation requests re-check current synced-pattern status/readability before ranking or returning results. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
* Cloudflare AI Search for WordPress developer docs — Used for trusted `developer.wordpress.org` grounding through Flavor Agent's built-in public endpoint. Requests send docs-search queries derived from recommendation context, such as block names, template/navigation/style context, and the user's recommendation prompt, or administrator-supplied queries from the docs search ability. Site owners do not enter Cloudflare credentials for developer docs. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
* GitHub — Optional core-roadmap guidance fetches a public GitHub project page only when the `flavor_agent_enable_core_roadmap_guidance` filter is explicitly enabled. The request is a GET for the public roadmap page and does not include post content or editor prompts. GitHub Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

The canonical disclosure inventory is maintained in `docs/reference/external-service-disclosure.md` in the plugin repository.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/flavor-agent` directory, or install the plugin through the WordPress plugins screen.
2. Install and activate the WordPress AI plugin (`ai`).
3. Activate Flavor Agent through the `Plugins` screen in WordPress.
4. Open `Settings > Connectors` to configure the shared text-generation provider used by recommendation surfaces.
5. Optional: open `Settings > Flavor Agent` to configure the embedding model, pattern storage, developer-doc limits, guidelines, and experimental features.
6. Optional: run `Sync Pattern Catalog` only after the selected pattern backend is configured.

== Frequently Asked Questions ==

= Does Flavor Agent contact external services on activation? =

No external requests are made on activation. Activation installs the server-backed activity table and may schedule gated WP-Cron hooks, but docs prewarm and optional roadmap guidance no-op unless their explicit opt-in filters are enabled, and pattern sync runs only after the selected pattern backend is configured.

= Does Flavor Agent automatically edit or publish content? =

No. Content recommendations are generated text and editorial guidance only. Review the output and copy anything useful into the editor manually.

= Where do I configure AI providers? =

Use `Settings > Connectors` for text-generation providers. Use `Settings > Flavor Agent` for one embedding model, pattern storage, developer-doc limits, guidelines, and experimental features such as block structural actions.

= How do external integrations call recommendation surfaces? =

Use the WordPress Abilities API. Recommendation integrations call `POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-*/run` with the Flavor Agent payload wrapped in `{ "input": { ... } }`; browser clients can use the equivalent site-local apiFetch path `/wp-abilities/v1/abilities/{ability}/run`.

= What data is sent to AI providers? =

Only data needed for the requested surface is sent after setup or explicit user action. This can include prompts, post/page content, selected block context, template/navigation/style context, guidelines, docs snippets, pattern text, and pattern metadata. See the External services section above for service-specific details.

== Development ==

Source code and build tooling are maintained at https://github.com/henryperkins/flavor-agent. The submitted plugin zip contains compiled editor/admin assets in `build/`; those assets are built from the repository source with `npm ci`, `composer install`, and `npm run build`.

== Changelog ==

= 0.1.0 =
* Initial release.
