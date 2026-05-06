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
* One embedding model is configured in `Settings > Flavor Agent` for Flavor Agent semantic features. Pattern storage is configured separately: Qdrant uses that embedding model plus Qdrant, while Cloudflare AI Search reuses the same Cloudflare credentials and only needs a private pattern index name.
* Pattern sync runs only after the selected pattern backend is configured; administrators can also start a sync manually from `Settings > Flavor Agent`.
* WordPress developer-doc grounding uses Flavor Agent's built-in public Cloudflare AI Search endpoint during user-triggered recommendation requests. Site owners do not enter Cloudflare credentials for developer docs.
* Optional GitHub core-roadmap guidance is off by default and requires a developer opt-in filter.

= External services =

Flavor Agent can send data to the services below after an administrator configures the related backend or an authorized user explicitly requests a recommendation, validation, sync, or docs search. Browser code calls only this site's WordPress REST API; third-party calls are made server-side through WordPress HTTP APIs or the WordPress AI Client / Connectors runtime.

* OpenAI — Used for text generation when the WordPress AI Client routes to the OpenAI connector. Text-generation prompts can include editor context, post/page content, selected block data, templates, navigation, style context, guidelines, and cached docs snippets. OpenAI Terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/
* Cloudflare Workers AI — Used for the Flavor Agent embedding model when account ID, API token, and embedding model are saved in `Settings > Flavor Agent`. Embedding requests can include validation probe text, pattern index probe text, pattern text, pattern metadata included in embedding text, and Qdrant pattern-search query text when Qdrant pattern storage is used. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
* Connector-backed chat providers such as Anthropic — Used through `Settings > Connectors` when the WordPress AI Client routes text generation to that connector. Flavor Agent sends the same recommendation prompt data described for text generation above. Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms Privacy: https://www.anthropic.com/privacy
* Qdrant — Used for vector-backed pattern recommendations after Qdrant URL and API key are saved in `Settings > Flavor Agent` and the Qdrant pattern backend is selected. Requests can include vectors, collection metadata, and point payloads with pattern names, titles, descriptions, categories, block/template metadata, inferred traits, synced-pattern identifiers/status, and pattern content. Qdrant Terms: https://qdrant.tech/legal/terms_and_conditions/ Privacy: https://qdrant.tech/legal/privacy-policy/
* Cloudflare AI Search for private pattern retrieval — Used when the Cloudflare AI Search pattern backend is selected, the Cloudflare Workers AI Embedding Model credentials are saved, and a private pattern index name is saved in `Settings > Flavor Agent`. Requests can include validation search probes, pattern item uploads with title, description, categories, block/template metadata, inferred traits, public-safe pattern content, synced identifiers/status, recommendation query text, and visible pattern names as search filters. Sync uploads only public-safe registered and published synced patterns; recommendation requests re-check current synced-pattern status/readability before ranking or returning results. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
* Cloudflare AI Search for WordPress developer docs — Used for trusted `developer.wordpress.org` grounding through Flavor Agent's built-in public endpoint. Requests send docs-search queries derived from recommendation context, such as block names, template/navigation/style context, and the user's recommendation prompt. Site owners do not enter Cloudflare credentials for developer docs. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
* GitHub — Optional core-roadmap guidance fetches a public GitHub project page only when the `flavor_agent_enable_core_roadmap_guidance` filter is explicitly enabled. The request is a GET for the public roadmap page and does not include post content or editor prompts. GitHub Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

The canonical disclosure inventory is maintained in `docs/reference/external-service-disclosure.md` in the plugin repository.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/flavor-agent` directory, or install the plugin through the WordPress plugins screen.
2. Install and activate the WordPress AI plugin (`ai`).
3. Activate Flavor Agent through the `Plugins` screen in WordPress.
4. Open `Settings > Connectors` to configure the shared text-generation provider used by recommendation surfaces.
5. Optional: open `Settings > Flavor Agent` to configure the embedding model, pattern storage, developer-doc limits, and guidelines.
6. Optional: run `Sync Pattern Catalog` only after the selected pattern backend is configured.

== Frequently Asked Questions ==

= Does Flavor Agent contact external services on activation? =

No. Activation installs local activity storage and schedules only jobs whose backends are configured or explicitly enabled. The built-in Cloudflare docs endpoint is not prewarmed on activation.

= Does Flavor Agent automatically edit or publish content? =

No. Content recommendations are generated text and editorial guidance only. Review the output and copy anything useful into the editor manually.

= Where do I configure AI providers? =

Use `Settings > Connectors` for text-generation providers. Use `Settings > Flavor Agent` for one embedding model, pattern storage, developer-doc limits, and guidelines.

= How do external integrations call recommendation surfaces? =

Use the WordPress Abilities API. The legacy private `POST /wp-json/flavor-agent/v1/recommend-*` endpoints were removed before the 0.2.0 contract. Call `POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-*/run` with the Flavor Agent payload wrapped in `{ "input": { ... } }`.

= What data is sent to AI providers? =

Only data needed for the requested surface is sent after setup or explicit user action. This can include prompts, post/page content, selected block context, template/navigation/style context, guidelines, docs snippets, pattern text, and pattern metadata. See the External services section above for service-specific details.

== Changelog ==

= 0.2.0 =
* Breaking: removed private `POST /wp-json/flavor-agent/v1/recommend-*` endpoints. Recommendation integrations now use `POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-*/run`.
* Added explicit dependency on the WordPress AI plugin (`ai`) for recommendation UI and feature registration.
* Added dedicated MCP Adapter server support for direct Flavor Agent recommendation tools when the MCP Adapter is active.
* Documented the per-post `edit_post` permission check for post-scoped recommendation requests.

= 0.1.0 =
* Initial release.
