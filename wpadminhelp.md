Use the **`WP_Screen` contextual help API**. The current WordPress way is to call **`get_current_screen()->add_help_tab()`** for the target admin screen, and optionally **`set_help_sidebar()`** for the right-hand help column. The older **`add_contextual_help()`** API is deprecated since WordPress 3.3.0. ([WordPress Developer Resources][1])

Here is the usual pattern for **your own plugin/admin page**:

```php
<?php
/**
 * Plugin Name: My Admin Help Tabs
 */

add_action( 'admin_menu', 'myplugin_register_settings_page' );

function myplugin_register_settings_page() {
	$hook_suffix = add_options_page(
		__( 'My Plugin Settings', 'myplugin' ),
		__( 'My Plugin', 'myplugin' ),
		'manage_options',
		'myplugin-settings',
		'myplugin_render_settings_page'
	);

	// Attach help tabs only when this page loads.
	add_action( "load-$hook_suffix", 'myplugin_add_help_tabs' );
}

function myplugin_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p>Your settings form goes here.</p>
	</div>
	<?php
}

function myplugin_add_help_tabs() {
	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	$screen->add_help_tab( array(
		'id'       => 'myplugin_overview',
		'title'    => __( 'Overview', 'myplugin' ),
		'content'  =>
			'<p>' . esc_html__( 'This screen lets you configure My Plugin.', 'myplugin' ) . '</p>' .
			'<p>' . esc_html__( 'Use these settings to control syncing, defaults, and API behavior.', 'myplugin' ) . '</p>',
		'priority' => 10,
	) );

	$screen->add_help_tab( array(
		'id'       => 'myplugin_troubleshooting',
		'title'    => __( 'Troubleshooting', 'myplugin' ),
		'content'  =>
			'<p>' . esc_html__( 'If settings are not saving, verify your permissions and nonce handling.', 'myplugin' ) . '</p>' .
			'<p>' . esc_html__( 'If API calls fail, confirm your credentials and endpoint URL.', 'myplugin' ) . '</p>',
		'priority' => 20,
	) );

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'Need more help?', 'myplugin' ) . '</strong></p>' .
		'<p><a href="https://example.com/docs" target="_blank" rel="noopener">Documentation</a></p>' .
		'<p><a href="https://example.com/support" target="_blank" rel="noopener">Support</a></p>'
	);
}
```

Why this works:

* `add_options_page()` returns a **hook suffix** for your page.
* WordPress provides a dynamic **`load-{$page_hook}`** action that fires before that admin screen loads.
* `WP_Screen::add_help_tab()` is designed to be called on that screen load flow, or via `get_current_screen()`. ([WordPress Developer Resources][2])

The tab arguments WordPress expects are:

* `id` — required, unique, HTML-safe, and no spaces
* `title` — required
* `content` — optional HTML/text
* `callback` — optional callable to render content dynamically
* `priority` — optional sort order, default `10` ([WordPress Developer Resources][1])

If you want to add help to an **existing core/admin screen** instead of your own page, hook `current_screen`, inspect the passed `WP_Screen`, and only add tabs when its screen ID matches the page you care about. The `current_screen` hook fires after the current screen has been set and passes the `WP_Screen` object. ([WordPress Developer Resources][3])

Example for an existing admin screen:

```php
add_action( 'current_screen', 'myplugin_add_help_to_existing_screen' );

function myplugin_add_help_to_existing_screen( $screen ) {
	if ( ! $screen || $screen->id !== 'settings_page_myplugin-settings' ) {
		return;
	}

	$screen->add_help_tab( array(
		'id'      => 'myplugin_existing_help',
		'title'   => __( 'Quick Start', 'myplugin' ),
		'content' => '<p>' . esc_html__( 'Start here to configure the plugin.', 'myplugin' ) . '</p>',
	) );
}
```

If you want to **replace or remove** native help tabs, WordPress also provides:

* `remove_help_tab( $id )` to remove one tab
* `remove_help_tabs()` to remove them all ([WordPress Developer Resources][4])

A few practical tips:

* Keep help registration inside the target screen’s hook so you do not accidentally add tabs to every admin page. WordPress explicitly notes you may need to filter by screen to avoid global tabs. ([WordPress Developer Resources][1])
* Use translated strings with `__()`, `esc_html__()`, etc., since this is UI text.
* Use `content` for simple static docs; use `callback` when the help content should be generated dynamically. ([WordPress Developer Resources][1])

And one thing to avoid: do **not** build new help tabs with `add_contextual_help()` or rely on the old `contextual_help` hook for new work, because both are deprecated in favor of the `WP_Screen` methods. ([WordPress Developer Resources][5])

If you want, I can turn this into a drop-in snippet for a specific admin page slug or custom post type screen.

[1]: https://developer.wordpress.org/reference/classes/wp_screen/add_help_tab/ "WP_Screen::add_help_tab() – Method | Developer.WordPress.org"
[2]: https://developer.wordpress.org/reference/functions/add_options_page/?utm_source=chatgpt.com "add_options_page () – Function | Developer.WordPress.org"
[3]: https://developer.wordpress.org/reference/hooks/current_screen/ "current_screen – Hook | Developer.WordPress.org"
[4]: https://developer.wordpress.org/reference/classes/wp_screen/remove_help_tab/ "WP_Screen::remove_help_tab() – Method | Developer.WordPress.org"
[5]: https://developer.wordpress.org/reference/functions/add_contextual_help/ "add_contextual_help() – Function | Developer.WordPress.org"
