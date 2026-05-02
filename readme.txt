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

* Text generation for recommendations is configured through `Settings > Connectors` and the WordPress AI Client.
* Pattern embeddings and Qdrant vector search are configured in `Settings > Flavor Agent`.
* Pattern sync runs only after an embeddings backend and Qdrant are configured, or when an administrator explicitly starts a sync.
* WordPress developer-doc grounding can use a built-in public Cloudflare AI Search endpoint during user-triggered recommendation requests. The built-in endpoint is not prewarmed on activation. Background docs prewarm requires saved Cloudflare override credentials or an explicit developer opt-in filter.
* Optional GitHub core-roadmap guidance is off by default and requires a developer opt-in filter.

= External services =

Flavor Agent can send data to the services below after an administrator configures the related backend or an authorized user explicitly requests a recommendation, validation, sync, or docs search. Browser code calls only this site's WordPress REST API; third-party calls are made server-side through WordPress HTTP APIs or the WordPress AI Client / Connectors runtime.

* OpenAI — Used for connector-backed text generation when the OpenAI connector is selected, and for OpenAI Native embeddings when configured for pattern recommendations. Text-generation prompts can include editor context, post/page content, selected block data, templates, navigation, style context, guidelines, and cached docs snippets. Embedding requests can include pattern text, pattern metadata, and pattern-search queries. OpenAI Terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/
* Azure OpenAI — Used only for plugin-owned embeddings when Azure endpoint, key, and deployment are saved in `Settings > Flavor Agent`. Embedding requests can include validation probe text, pattern text, pattern metadata, and pattern-search queries. Azure legal terms: https://azure.microsoft.com/support/legal/ Microsoft Privacy Statement: https://privacy.microsoft.com/privacystatement
* Connector-backed chat providers such as Anthropic — Used through `Settings > Connectors` when the corresponding connector is installed, configured, and selected. Flavor Agent sends the same recommendation prompt data described for text generation above and does not fall back to unselected providers. Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms Privacy: https://www.anthropic.com/privacy
* Qdrant — Used for vector-backed pattern recommendations after Qdrant URL and API key are saved in `Settings > Flavor Agent`. Requests can include vectors, collection metadata, and point payloads with pattern names, titles, descriptions, categories, block/template metadata, inferred traits, synced-pattern identifiers/status, and pattern content. Qdrant Terms: https://qdrant.tech/legal/terms_and_conditions/ Privacy: https://qdrant.tech/legal/privacy-policy/
* Cloudflare AI Search — Used for trusted `developer.wordpress.org` grounding. Requests send docs-search queries derived from recommendation context, such as block names, template/navigation/style context, and the user's recommendation prompt. The built-in public endpoint is used only during user-triggered recommendation/docs-grounding flows unless a developer explicitly opts into public background prewarm; saved Cloudflare override credentials can also validate and prewarm docs. Cloudflare Terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/
* GitHub — Optional core-roadmap guidance fetches a public GitHub project page only when the `flavor_agent_enable_core_roadmap_guidance` filter is explicitly enabled. The request is a GET for the public roadmap page and does not include post content or editor prompts. GitHub Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

The canonical disclosure inventory is maintained in `docs/reference/external-service-disclosure.md` in the plugin repository.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/flavor-agent` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open `Settings > Connectors` to configure the shared text-generation provider used by recommendation surfaces.
4. Optional: open `Settings > Flavor Agent` to configure plugin-owned embeddings and Qdrant for pattern recommendations, docs grounding limits/overrides, and guidelines.
5. Optional: run `Sync Pattern Catalog` only after embeddings and Qdrant are configured.

== Frequently Asked Questions ==

= Does Flavor Agent contact external services on activation? =

No. Activation installs local activity storage and schedules only jobs whose backends are configured or explicitly enabled. The built-in Cloudflare docs endpoint is not prewarmed on activation.

= Does Flavor Agent automatically edit or publish content? =

No. Content recommendations are generated text and editorial guidance only. Review the output and copy anything useful into the editor manually.

= Where do I configure AI providers? =

Use `Settings > Connectors` for text-generation providers. Use `Settings > Flavor Agent` for plugin-owned embeddings, Qdrant, docs grounding options, and guidelines.

= What data is sent to AI providers? =

Only data needed for the requested surface is sent after setup or explicit user action. This can include prompts, post/page content, selected block context, template/navigation/style context, guidelines, docs snippets, pattern text, and pattern metadata. See the External services section above for service-specific details.

== Changelog ==

= 0.1.0 =
* Initial release.
