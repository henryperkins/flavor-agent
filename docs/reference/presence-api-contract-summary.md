Inspected `WordPress/presence-api` `main` at commit `782e282d5e39aa15940143d805cb569f97505923` (`0.1.2`). It is a standalone experimental feature plugin for WordPress `7.0-alpha+`, PHP `7.4+`.

**Core Contract**
Presence is stored in a dedicated `$wpdb->presence` table named `{prefix}presence`, not postmeta/options. Rows are ephemeral and filtered by TTL on read.

Table shape:
```text
id, room, client_id, user_id, data, date_gmt
UNIQUE KEY room_client (room, client_id)
KEY date_gmt
KEY user_id
KEY room_date (room(40), date_gmt)
```

Default TTL is `60` seconds via `WP_PRESENCE_DEFAULT_TTL`, filterable with `wp_presence_default_ttl`. Cleanup runs every minute and deletes expired rows in batches of 1000. Reads are intentionally uncached/stale-sensitive.

**Public PHP API**
Documented public functions:

```php
wp_get_presence( $room, $timeout = WP_PRESENCE_DEFAULT_TTL )
wp_set_presence( $room, $client_id, $state, $user_id = 0 )
wp_remove_presence( $room, $client_id )
wp_remove_user_presence( $user_id )
wp_can_access_presence_room( $room, $user_id = 0 )
wp_presence_post_room( $post )
```

Important behavior:
- `wp_set_presence()` is an atomic upsert keyed by `(room, client_id)`.
- `wp_get_presence()` returns active entries only, ordered newest first, with `data` JSON decoded into arrays.
- Access is currently coarse: `wp_can_access_presence_room()` is just `edit_posts`.
- Post rooms only exist for post types supporting `presence`.
- `post` and `page` are opted in by default; custom types use `add_post_type_support( 'product', 'presence' )`.

**Room Conventions**
Main room formats:
```text
admin/online
postType/{post_type}:{post_id}
```

Examples:
```text
admin/online
postType/post:42
postType/page:15
```

**REST API**
Namespace: `wp-presence/v1`.

Endpoints:

```http
GET /wp-json/wp-presence/v1/presence?room=admin/online&per_page=100&page=1
POST /wp-json/wp-presence/v1/presence
DELETE /wp-json/wp-presence/v1/presence
GET /wp-json/wp-presence/v1/presence/rooms?per_page=50&page=1
```

`GET /presence` returns entries:
```json
{
  "room": "admin/online",
  "client_id": "user-1",
  "user_id": 1,
  "data": { "screen": "dashboard" },
  "date_gmt": "2026-05-02 12:00:00"
}
```

`POST /presence` accepts `room`, `client_id`, and optional `data` object. Constraints:
- authenticated user must have `edit_posts`
- `data` max size: `10240` bytes
- `data` max nesting depth: `3`
- scalar values preserved, strings sanitized
- max active entries per user: `50`, then `429`
- cannot claim another user's existing `(room, client_id)`, returns `409`

`DELETE /presence` accepts `room` and `client_id`. Admins with `manage_options` can delete any entry; other users can delete only their own entry. Missing entries are treated as no-op success.

`GET /presence/rooms` returns:
```json
{
  "room": "admin/online",
  "user_count": 2,
  "users": [
    { "user_id": 1, "display_name": "Admin", "avatar_url": "..." }
  ]
}
```

REST list responses set `X-WP-Total`, `X-WP-TotalPages`, and `Cache-Control: no-store`.

**Heartbeat Contract**
The browser uses Heartbeat as the main live channel.

Admin/front-end ping:
```js
data["presence-ping"] = {
  screen: window.pagenow || "front",
  post_id,
  post_type,
  title
};
```

Editor ping:
```js
data["presence-editor-ping"] = { post_id: 42 };
```

Post-lock bridge also listens to core:
```js
data["wp-refresh-post-lock"] = { post_id: 42 };
```

Server-written client IDs:
```text
user-{user_id}    // admin/online room
editor-{user_id}  // explicit editor heartbeat
lock-{user_id}    // core post-lock bridge
cli-{user_id}     // default WP-CLI client
```

Widget Heartbeat response contracts:
- `presence-online`: user list with `user_id`, `display_name`, `avatar_url`, `screen`, `screen_label`, `date_gmt`
- `presence-active-posts`: post list with `post_id`, `post_title`, `post_type`, `edit_url`, `editors[]`
- editor status is `active` until 30s old, then `idle`

**WP-CLI**
Commands:
```bash
wp presence set <room> [<client_id>] [--data=<json>] [--user=<id>]
wp presence list <room> [--format=table|json|csv]
wp presence summary [--format=table|json|csv]
wp presence cleanup [--yes]
```

**Integration Notes**
Treat this as an ephemeral awareness layer, not a durable source of truth. Use unique `client_id` values for your integration, avoid claiming the plugin's reserved patterns unless intentionally interoperating, and do not put sensitive data in rooms or `data` because authorization is currently only `edit_posts`.

Sources: [README](https://github.com/WordPress/presence-api/blob/782e282d5e39aa15940143d805cb569f97505923/README.md), [bootstrap](https://github.com/WordPress/presence-api/blob/782e282d5e39aa15940143d805cb569f97505923/presence-api.php), [PHP API/storage](https://github.com/WordPress/presence-api/blob/782e282d5e39aa15940143d805cb569f97505923/includes/functions.php), [REST controller](https://github.com/WordPress/presence-api/blob/782e282d5e39aa15940143d805cb569f97505923/includes/class-wp-rest-presence-controller.php), [Heartbeat](https://github.com/WordPress/presence-api/blob/782e282d5e39aa15940143d805cb569f97505923/includes/heartbeat.php), [WP-CLI](https://github.com/WordPress/presence-api/blob/782e282d5e39aa15940143d805cb569f97505923/includes/cli/class-wp-presence-cli-command.php).
