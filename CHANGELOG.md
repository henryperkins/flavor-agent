# Changelog

## 0.2.0

- Breaking: removed private `POST /wp-json/flavor-agent/v1/recommend-*` endpoints. Use `POST /wp-json/wp-abilities/v1/abilities/flavor-agent/recommend-*/run` with `{ "input": { ... } }`.
- Added an explicit WordPress plugin dependency on `ai`, the WordPress AI plugin.
- Registered Flavor Agent as a downstream AI plugin feature through `wpai_default_feature_classes`.
- Kept recommendation ability execution on POST by leaving WordPress-format `readonly` annotations unset.
- Added dedicated MCP Adapter server support at `/wp-json/mcp/flavor-agent` for the seven recommendation tools.
- Documented post-scoped permission behavior: positive post IDs require `edit_post` for that post.

## 0.1.0

- Initial release.
