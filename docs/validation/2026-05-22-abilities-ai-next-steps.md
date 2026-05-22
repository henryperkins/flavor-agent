# Abilities And AI Integration Next Steps

1. **Run a focused compatibility audit**
   Check current trunk/Gutenberg/AI plugin behavior for:
   `wp-abilities/v1`, `@wordpress/core-abilities`, `flavor-agent/recommend-block`, `flavor-agent/recommend-content`, and `wp_ai_client_prompt()` provider resolution.

2. **Keep this repo note current**
   Track `#64606` and `#64657` as upstream context/discovery changes, and `#64872` as the provider/generation surface to watch.

3. **Verify permission boundaries**
   Confirm tests still prove:
   `edit_posts` is required globally, positive post IDs require `edit_post`, recommendation abilities stay POST-oriented, and content/block recommendations do not leak data through helper abilities.

   Verification evidence on 2026-05-22:
   - `vendor/bin/phpunit tests/phpunit/RegistrationTest.php tests/phpunit/ContentAbilitiesTest.php tests/phpunit/BlockAbilitiesTest.php tests/phpunit/FeatureBootstrapTest.php tests/phpunit/InfraAbilitiesTest.php` passed: 38 tests, 790 assertions.
   - `npx wp-scripts test-unit-js src/store/__tests__/abilities-client.test.js --runInBand` passed: 14 tests.

4. **Decide whether to integrate Core get abilities**
   Not required now, but useful later: block/content recommendations could optionally use Core post/page abilities as a context source for external-agent workflows. I would not replace the current editor payload path.

5. **Keep provider abstraction stable**
   Watch `wp-ai/v1` for changes that could affect `WordPressAIClient::chat()` and `Provider::chat_configuration()`. That is the most likely place where block and content recommendations could need code changes.

Start with a read-only audit plus a short validation doc, then only patch code if the current local runtime shows a contract drift.
