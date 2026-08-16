<?php
/**
 * Minimal stand-ins for the Automattic Agents API symbols the Flavor Agent
 * adapter binds to.
 *
 * Deliberately NOT auto-loaded by the PHPUnit suite: the file lives outside the
 * `*Test.php` discovery pattern so a test process can observe a genuinely
 * absent Agents API runtime before any test opts into the stubs by requiring
 * this file.
 *
 * These mirror only the contract surface {@see FlavorAgent\AgentsAPI\Compatibility}
 * checks and {@see FlavorAgent\AgentsAPI\Registration} calls — the real runtime
 * is far larger, and this file must never grow into a re-implementation of it.
 *
 * @see https://github.com/Automattic/agents-api
 */

declare(strict_types=1);

namespace FlavorAgent\Tests\Support {

	final class AgentsApiTestState {

		/**
		 * Registered agent definitions keyed by slug.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		public static array $registered_agents = [];

		/**
		 * When true, `wp_register_agent()` reports rejection by returning null.
		 */
		public static bool $reject_registration = false;

		/**
		 * When true, `wp_register_agent()` throws instead of returning.
		 *
		 * Models a future pre-1.0 upstream that stops converting bad arguments
		 * into a null return. The adapter must absorb it: `wp_agents_api_init`
		 * fires from `init`.
		 */
		public static bool $throw_on_registration = false;

		public static function reset(): void {
			self::$registered_agents     = [];
			self::$reject_registration   = false;
			self::$throw_on_registration = false;
		}
	}
}

namespace {

	use FlavorAgent\Tests\Support\AgentsApiTestState;

	if ( ! defined( 'AGENTS_API_LOADED' ) ) {
		define( 'AGENTS_API_LOADED', true );
	}

	if ( ! class_exists( 'WP_Agent' ) ) {
		class WP_Agent {

			/**
			 * @param array<string, mixed> $args
			 */
			public function __construct( private string $slug, private array $args = [] ) {
			}

			public function get_slug(): string {
				return $this->slug;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_default_config(): array {
				return is_array( $this->args['default_config'] ?? null ) ? $this->args['default_config'] : [];
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_meta(): array {
				return is_array( $this->args['meta'] ?? null ) ? $this->args['meta'] : [];
			}
		}
	}

	if ( ! class_exists( 'WP_Agents_Registry' ) ) {
		class WP_Agents_Registry {

			public static function get_instance(): self {
				return new self();
			}
		}
	}

	if ( ! class_exists( 'WP_Agent_Tool_Policy' ) ) {
		class WP_Agent_Tool_Policy {

			public const MODE_ALLOW = 'allow';
			public const MODE_DENY  = 'deny';
		}
	}

	if ( ! function_exists( 'wp_register_agent' ) ) {
		/**
		 * @param string|WP_Agent      $agent
		 * @param array<string, mixed> $args
		 */
		function wp_register_agent( $agent, array $args = [] ): ?WP_Agent {
			// Upstream refuses registration outside its own window and returns
			// null. Mirrored so a test can prove the adapter never registers
			// from another hook.
			if ( ! doing_action( 'wp_agents_api_init' ) ) {
				return null;
			}

			if ( AgentsApiTestState::$throw_on_registration ) {
				throw new RuntimeException( 'Agents API registration contract moved.' );
			}

			if ( AgentsApiTestState::$reject_registration ) {
				return null;
			}

			$slug = $agent instanceof WP_Agent ? $agent->get_slug() : (string) $agent;

			AgentsApiTestState::$registered_agents[ $slug ] = $args;

			return new WP_Agent( $slug, $args );
		}
	}

	if ( ! function_exists( 'wp_get_agent' ) ) {
		function wp_get_agent( string $slug ): ?WP_Agent {
			$args = AgentsApiTestState::$registered_agents[ $slug ] ?? null;

			return is_array( $args ) ? new WP_Agent( $slug, $args ) : null;
		}
	}
}
