# Plugin Check Report

**Plugin:** Flavor Agent
**Generated at:** 2026-04-04 00:09:29

## `tests/phpunit/bootstrap.php`

| Line | Column | Type    | Code                                                                  | Message                                                                                                                                                              | Docs                                                                                                                    |
| ---- | ------ | ------- | --------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| 0    | 0      | ERROR   | missing_direct_file_access_protection                                 | PHP file should prevent direct access. Add a check like: if ( ! defined( 'ABSPATH' ) ) exit;                                                                         | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#direct-file-access)                         |
| 213  | 52     | ERROR   | WordPress.Security.EscapeOutput.ExceptionNotEscaped                   | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '"Unknown AI client method {$name}."'. | [Docs](https://developer.wordpress.org/apis/security/escaping/)                                                         |
| 219  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_ai_client_prompt&quot;.                      |                                                                                                                         |
| 666  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_option&quot;.                               |                                                                                                                         |
| 672  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_is_connector_registered&quot;.               |                                                                                                                         |
| 675  | 46     | ERROR   | WordPress.Security.EscapeOutput.ExceptionNotEscaped                   | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$error_message'.                      | [Docs](https://developer.wordpress.org/apis/security/escaping/)                                                         |
| 683  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_get_connector&quot;.                         |                                                                                                                         |
| 686  | 46     | ERROR   | WordPress.Security.EscapeOutput.ExceptionNotEscaped                   | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$error_message'.                      | [Docs](https://developer.wordpress.org/apis/security/escaping/)                                                         |
| 696  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_get_connectors&quot;.                        |                                                                                                                         |
| 699  | 46     | ERROR   | WordPress.Security.EscapeOutput.ExceptionNotEscaped                   | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$error_message'.                      | [Docs](https://developer.wordpress.org/apis/security/escaping/)                                                         |
| 707  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_parse_args&quot;.                            |                                                                                                                         |
| 721  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;home_url&quot;.                                 |                                                                                                                         |
| 733  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;untrailingslashit&quot;.                        |                                                                                                                         |
| 739  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_current_blog_id&quot;.                      |                                                                                                                         |
| 745  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_get_environment_type&quot;.                  |                                                                                                                         |
| 751  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_transient&quot;.                            |                                                                                                                         |
| 759  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;set_transient&quot;.                            |                                                                                                                         |
| 767  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;delete_transient&quot;.                         |                                                                                                                         |
| 775  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;current_user_can&quot;.                         |                                                                                                                         |
| 804  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_current_user_id&quot;.                      |                                                                                                                         |
| 810  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;\_\_&quot;.                                     |                                                                                                                         |
| 816  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;esc_html&quot;.                                 |                                                                                                                         |
| 822  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;esc_attr&quot;.                                 |                                                                                                                         |
| 828  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;esc_html\_\_&quot;.                             |                                                                                                                         |
| 829  | 34     | ERROR   | WordPress.WP.I18n.NonSingularStringLiteralText                        | The $text parameter must be a single text string literal. Found: $text                                                                                               | [Docs](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#basic-strings) |
| 829  | 41     | ERROR   | WordPress.WP.I18n.NonSingularStringLiteralDomain                      | The $domain parameter must be a single text string literal. Found: $domain                                                                                           | [Docs](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#basic-strings) |
| 834  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;selected&quot;.                                 |                                                                                                                         |
| 838  | 22     | ERROR   | WordPress.Security.EscapeOutput.OutputNotEscaped                      | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$result'.                             | [Docs](https://developer.wordpress.org/apis/security/escaping/#escaping-functions)                                      |
| 846  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;is_wp_error&quot;.                              |                                                                                                                         |
| 852  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;sanitize_key&quot;.                             |                                                                                                                         |
| 858  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;sanitize_text_field&quot;.                      |                                                                                                                         |
| 870  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;sanitize_url&quot;.                             |                                                                                                                         |
| 876  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;sanitize_textarea_field&quot;.                  |                                                                                                                         |
| 888  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;admin_url&quot;.                                |                                                                                                                         |
| 896  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_get_global_settings&quot;.                   |                                                                                                                         |
| 902  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_get_global_styles&quot;.                     |                                                                                                                         |
| 908  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_block_templates&quot;.                      |                                                                                                                         |
| 938  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_block_template&quot;.                       |                                                                                                                         |
| 950  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_json_encode&quot;.                           |                                                                                                                         |
| 956  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_register_ability&quot;.                      |                                                                                                                         |
| 962  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_register_ability_category&quot;.             |                                                                                                                         |
| 968  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_remote_post&quot;.                           |                                                                                                                         |
| 988  | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_remote_get&quot;.                            |                                                                                                                         |
| 1008 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_remote_request&quot;.                        |                                                                                                                         |
| 1020 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_remote_retrieve_body&quot;.                  |                                                                                                                         |
| 1026 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_remote_retrieve_response_code&quot;.         |                                                                                                                         |
| 1036 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_remote_retrieve_header&quot;.                |                                                                                                                         |
| 1054 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_strip_all_tags&quot;.                        |                                                                                                                         |
| 1055 | 11     | ERROR   | WordPress.WP.AlternativeFunctions.strip_tags_strip_tags               | strip_tags() is discouraged. Use the more comprehensive wp_strip_all_tags() instead.                                                                                 |                                                                                                                         |
| 1060 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_unslash&quot;.                               |                                                                                                                         |
| 1070 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;add_settings_error&quot;.                       |                                                                                                                         |
| 1081 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_settings_errors&quot;.                      |                                                                                                                         |
| 1082 | 18     | WARNING | WordPress.Security.NonceVerification.Recommended                      | Processing form data without nonce verification.                                                                                                                     |                                                                                                                         |
| 1108 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;settings_errors&quot;.                          |                                                                                                                         |
| 1109 | 37     | WARNING | WordPress.Security.NonceVerification.Recommended                      | Processing form data without nonce verification.                                                                                                                     |                                                                                                                         |
| 1128 | 21     | ERROR   | WordPress.Security.EscapeOutput.OutputNotEscaped                      | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'htmlspecialchars'.                    | [Docs](https://developer.wordpress.org/apis/security/escaping/#escaping-functions)                                      |
| 1129 | 21     | ERROR   | WordPress.Security.EscapeOutput.OutputNotEscaped                      | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'htmlspecialchars'.                    | [Docs](https://developer.wordpress.org/apis/security/escaping/#escaping-functions)                                      |
| 1136 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;settings_fields&quot;.                          |                                                                                                                         |
| 1139 | 17     | ERROR   | WordPress.Security.EscapeOutput.OutputNotEscaped                      | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'htmlspecialchars'.                    | [Docs](https://developer.wordpress.org/apis/security/escaping/#escaping-functions)                                      |
| 1145 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;do_settings_sections&quot;.                     |                                                                                                                         |
| 1151 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;submit_button&quot;.                            |                                                                                                                         |
| 1154 | 17     | ERROR   | WordPress.Security.EscapeOutput.OutputNotEscaped                      | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'htmlspecialchars'.                    | [Docs](https://developer.wordpress.org/apis/security/escaping/#escaping-functions)                                      |
| 1160 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;get_post&quot;.                                 |                                                                                                                         |
| 1172 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;parse_blocks&quot;.                             |                                                                                                                         |
| 1243 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;update_option&quot;.                            |                                                                                                                         |
| 1252 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_schedule_event&quot;.                        |                                                                                                                         |
| 1265 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_schedule_single_event&quot;.                 |                                                                                                                         |
| 1277 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_next_scheduled&quot;.                        |                                                                                                                         |
| 1287 | 9      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_clear_scheduled_hook&quot;.                  |                                                                                                                         |

## `dist/flavor-agent-0.1.0.zip`

| Line | Column | Type  | Code             | Message                             | Docs |
| ---- | ------ | ----- | ---------------- | ----------------------------------- | ---- |
| 0    | 0      | ERROR | compressed_files | Compressed files are not permitted. |      |

## `output/playwright/flavor-agent.smoke-block-i-e1d2c-d-undoes-AI-recommendations/trace.zip`

| Line | Column | Type  | Code             | Message                             | Docs |
| ---- | ------ | ----- | ---------------- | ----------------------------------- | ---- |
| 0    | 0      | ERROR | compressed_files | Compressed files are not permitted. |      |

## `.dockerignore`

| Line | Column | Type  | Code         | Message                         | Docs |
| ---- | ------ | ----- | ------------ | ------------------------------- | ---- |
| 0    | 0      | ERROR | hidden_files | Hidden files are not permitted. |      |

## `.npmrc`

| Line | Column | Type  | Code         | Message                         | Docs |
| ---- | ------ | ----- | ------------ | ------------------------------- | ---- |
| 0    | 0      | ERROR | hidden_files | Hidden files are not permitted. |      |

## `.env.example`

| Line | Column | Type  | Code         | Message                         | Docs |
| ---- | ------ | ----- | ------------ | ------------------------------- | ---- |
| 0    | 0      | ERROR | hidden_files | Hidden files are not permitted. |      |

## `output/playwright-wp70/.last-run.json`

| Line | Column | Type  | Code         | Message                         | Docs |
| ---- | ------ | ----- | ------------ | ------------------------------- | ---- |
| 0    | 0      | ERROR | hidden_files | Hidden files are not permitted. |      |

## `.mcp.json`

| Line | Column | Type  | Code         | Message                         | Docs |
| ---- | ------ | ----- | ------------ | ------------------------------- | ---- |
| 0    | 0      | ERROR | hidden_files | Hidden files are not permitted. |      |

## `.gitignore`

| Line | Column | Type  | Code         | Message                         | Docs |
| ---- | ------ | ----- | ------------ | ------------------------------- | ---- |
| 0    | 0      | ERROR | hidden_files | Hidden files are not permitted. |      |

## `.nvmrc`

| Line | Column | Type  | Code         | Message                         | Docs |
| ---- | ------ | ----- | ------------ | ------------------------------- | ---- |
| 0    | 0      | ERROR | hidden_files | Hidden files are not permitted. |      |

## `phpunit.xml.dist`

| Line | Column | Type  | Code                 | Message                              | Docs |
| ---- | ------ | ----- | -------------------- | ------------------------------------ | ---- |
| 0    | 0      | ERROR | application_detected | Application files are not permitted. |      |

## `phpcs.xml.dist`

| Line | Column | Type  | Code                 | Message                              | Docs |
| ---- | ------ | ----- | -------------------- | ------------------------------------ | ---- |
| 0    | 0      | ERROR | application_detected | Application files are not permitted. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181024.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650172654.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650169490.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181556.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181065.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650171601.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650180919.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181462.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650170566.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650175750.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650172636.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650180993.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181423.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181222.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181365.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181164.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181197.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181130.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181435.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181495.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181038.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181244.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650180141.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650180061.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650174716.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181298.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181093.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181398.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650180943.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650173683.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650169523.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `output/playwright/.playwright-artifacts-0/traces/resources/page@0287b5469dc2b4466dc0006d50f23be5-1774650181350.jpeg`

| Line | Column | Type  | Code              | Message                                                              | Docs |
| ---- | ------ | ----- | ----------------- | -------------------------------------------------------------------- | ---- |
| 0    | 0      | ERROR | badly_named_files | File and folder names must not contain spaces or special characters. |      |

## `flavor-agent.php`

| Line | Column | Type    | Code                                                                  | Message                                                                                                                | Docs                                                                                                            |
| ---- | ------ | ------- | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| 0    | 0      | ERROR   | plugin_header_no_license                                              | Missing "License" in Plugin Header. Please update your Plugin Header with a valid GPLv2 (or later) compatible license. | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#no-gpl-compatible-license-declared) |
| 74   | 6      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$option_name&quot;. |                                                                                                                 |

## `inc/Settings.php`

| Line | Column | Type    | Code                                                         | Message                                                                                                                                           | Docs                                                                                            |
| ---- | ------ | ------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 0    | 0      | ERROR   | missing_direct_file_access_protection                        | PHP file should prevent direct access. Add a check like: if ( ! defined( 'ABSPATH' ) ) exit;                                                      | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#direct-file-access) |
| 1540 | 18     | WARNING | WordPress.Security.NonceVerification.Missing                 | Processing form data without nonce verification.                                                                                                  |                                                                                                 |
| 1540 | 18     | WARNING | WordPress.Security.ValidatedSanitizedInput.MissingUnslash    | $\_POST[&#039;option_page&#039;] not unslashed before sanitization. Use wp_unslash() or similar                                                   |                                                                                                 |
| 1540 | 18     | WARNING | WordPress.Security.ValidatedSanitizedInput.InputNotSanitized | Detected usage of a non-sanitized input variable: $\_POST[&#039;option_page&#039;]                                                                |                                                                                                 |
| 1552 | 15     | WARNING | WordPress.Security.NonceVerification.Missing                 | Processing form data without nonce verification.                                                                                                  |                                                                                                 |
| 1552 | 15     | WARNING | WordPress.Security.ValidatedSanitizedInput.MissingUnslash    | $\_POST[Provider::OPTION_NAME] not unslashed before sanitization. Use wp_unslash() or similar                                                     |                                                                                                 |
| 1552 | 15     | WARNING | WordPress.Security.ValidatedSanitizedInput.InputNotSanitized | Detected usage of a non-sanitized input variable: $\_POST[Provider::OPTION_NAME]                                                                  |                                                                                                 |
| 1566 | 12     | WARNING | WordPress.Security.NonceVerification.Missing                 | Processing form data without nonce verification.                                                                                                  |                                                                                                 |
| 1566 | 12     | WARNING | WordPress.Security.ValidatedSanitizedInput.MissingUnslash    | $_POST[$option_name] not unslashed before sanitization. Use wp_unslash() or similar                                                               |                                                                                                 |
| 1566 | 12     | WARNING | WordPress.Security.ValidatedSanitizedInput.InputNotSanitized | Detected usage of a non-sanitized input variable: $_POST[$option_name]                                                                            |                                                                                                 |
| 1578 | 12     | WARNING | WordPress.Security.NonceVerification.Missing                 | Processing form data without nonce verification.                                                                                                  |                                                                                                 |
| 1578 | 12     | WARNING | WordPress.Security.ValidatedSanitizedInput.MissingUnslash    | $_POST[$option_name] not unslashed before sanitization. Use wp_unslash() or similar                                                               |                                                                                                 |
| 1578 | 12     | WARNING | WordPress.Security.ValidatedSanitizedInput.InputNotSanitized | Detected usage of a non-sanitized input variable: $_POST[$option_name]                                                                            |                                                                                                 |
| 1711 | 25     | ERROR   | WordPress.Security.EscapeOutput.OutputNotEscaped             | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$state['warmed']'. | [Docs](https://developer.wordpress.org/apis/security/escaping/#escaping-functions)              |
| 1712 | 25     | ERROR   | WordPress.Security.EscapeOutput.OutputNotEscaped             | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$state['failed']'. | [Docs](https://developer.wordpress.org/apis/security/escaping/#escaping-functions)              |

## `inc/Cloudflare/AISearchClient.php`

| Line | Column | Type  | Code                                                  | Message                                                                                                            | Docs |
| ---- | ------ | ----- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ---- |
| 1290 | 12     | ERROR | WordPress.WP.AlternativeFunctions.parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead. |      |
| 1476 | 11     | ERROR | WordPress.WP.AlternativeFunctions.parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead. |      |

## `inc/AzureOpenAI/ConfigurationValidator.php`

| Line | Column | Type  | Code                                                  | Message                                                                                                            | Docs |
| ---- | ------ | ----- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ---- |
| 119  | 21     | ERROR | WordPress.WP.AlternativeFunctions.parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead. |      |

## `readme.txt`

| Line | Column | Type  | Code             | Message                               | Docs |
| ---- | ------ | ----- | ---------------- | ------------------------------------- | ---- |
| 0    | 0      | ERROR | no_plugin_readme | The plugin readme.txt does not exist. |      |

## `tests/e2e/playground-mu-plugin/flavor-agent-loader.php`

| Line | Column | Type    | Code                                                                  | Message                                                                                                                | Docs                                                                                            |
| ---- | ------ | ------- | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 0    | 0      | ERROR   | missing_direct_file_access_protection                                 | PHP file should prevent direct access. Add a check like: if ( ! defined( 'ABSPATH' ) ) exit;                           | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#direct-file-access) |
| 32   | 1      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$plugin_main&quot;. |                                                                                                 |

## `tests/phpunit/support/editor-surface-capabilities-bootstrap.php`

| Line | Column | Type    | Code                                                                  | Message                                                                                                                                                     | Docs                                                                                            |
| ---- | ------ | ------- | --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 0    | 0      | ERROR   | missing_direct_file_access_protection                                 | PHP file should prevent direct access. Add a check like: if ( ! defined( 'ABSPATH' ) ) exit;                                                                | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#direct-file-access) |
| 11   | 5      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;plugin_dir_path&quot;.                 |                                                                                                 |
| 17   | 5      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;plugin_dir_url&quot;.                  |                                                                                                 |
| 23   | 5      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;register_activation_hook&quot;.        |                                                                                                 |
| 27   | 5      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;register_deactivation_hook&quot;.      |                                                                                                 |
| 31   | 5      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;add_action&quot;.                      |                                                                                                 |
| 35   | 5      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;add_filter&quot;.                      |                                                                                                 |
| 39   | 5      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound | Functions declared in the global namespace by a theme/plugin should start with the theme/plugin prefix. Found: &quot;register_block_pattern_category&quot;. |                                                                                                 |

## `inc/Admin/ActivityPage.php`

| Line | Column | Type  | Code                                  | Message                                                                                      | Docs                                                                                            |
| ---- | ------ | ----- | ------------------------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 0    | 0      | ERROR | missing_direct_file_access_protection | PHP file should prevent direct access. Add a check like: if ( ! defined( 'ABSPATH' ) ) exit; | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#direct-file-access) |

## `.cursor`

| Line | Column | Type    | Code                     | Message                                                                                                      | Docs |
| ---- | ------ | ------- | ------------------------ | ------------------------------------------------------------------------------------------------------------ | ---- |
| 0    | 0      | WARNING | ai_instruction_directory | AI instruction directory ".cursor" detected. These directories should not be included in production plugins. |      |

## `.claude`

| Line | Column | Type    | Code                     | Message                                                                                                      | Docs |
| ---- | ------ | ------- | ------------------------ | ------------------------------------------------------------------------------------------------------------ | ---- |
| 0    | 0      | WARNING | ai_instruction_directory | AI instruction directory ".claude" detected. These directories should not be included in production plugins. |      |

## `.github`

| Line | Column | Type    | Code             | Message                                                                                                    | Docs |
| ---- | ------ | ------- | ---------------- | ---------------------------------------------------------------------------------------------------------- | ---- |
| 0    | 0      | WARNING | github_directory | GitHub workflow directory ".github" detected. This directory should not be included in production plugins. |      |

## `STATUS.md`

| Line | Column | Type    | Code                     | Message                                                                                                                        | Docs |
| ---- | ------ | ------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------ | ---- |
| 0    | 0      | WARNING | unexpected_markdown_file | Unexpected markdown file "STATUS.md" detected in plugin root. Only specific markdown files are expected in production plugins. |      |

## `CLAUDE.md`

| Line | Column | Type    | Code                     | Message                                                                                                                        | Docs |
| ---- | ------ | ------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------ | ---- |
| 0    | 0      | WARNING | unexpected_markdown_file | Unexpected markdown file "CLAUDE.md" detected in plugin root. Only specific markdown files are expected in production plugins. |      |

## `inc/Activity/Repository.php`

| Line | Column | Type    | Code                                               | Message                                                                                                             | Docs |
| ---- | ------ | ------- | -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ---- |
| 84   | 13     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 84   | 13     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 149  | 22     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 222  | 17     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 222  | 17     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 222  | 18     | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 220.                       |      |
| 367  | 20     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 367  | 20     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 418  | 23     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 418  | 23     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 482  | 16     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 482  | 16     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 515  | 30     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 515  | 30     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 565  | 17     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 565  | 17     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 703  | 17     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 703  | 17     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |
| 703  | 18     | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 700.                       |      |
| 1857 | 23     | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery       | Use of a direct database call is discouraged.                                                                       |      |
| 1857 | 23     | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching         | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |      |

## `uninstall.php`

| Line | Column | Type    | Code                                                                  | Message                                                                                                                | Docs |
| ---- | ------ | ------- | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ---- |
| 33   | 6      | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$option_name&quot;. |      |
