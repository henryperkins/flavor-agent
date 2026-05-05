<?php

declare(strict_types=1);

namespace FlavorAgent\AI;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FlavorAgentFeature extends Abstract_Feature {

	public static function get_id(): string {
		return 'flavor-agent';
	}

	protected function load_metadata(): array {
		$category = \defined( Experiment_Category::class . '::EDITOR' )
			? \constant( Experiment_Category::class . '::EDITOR' )
			: 'other';

		return [
			'label'       => __( 'Flavor Agent', 'flavor-agent' ),
			'description' => __( 'AI-assisted recommendations for blocks, content, patterns, navigation, styles, templates, and template parts.', 'flavor-agent' ),
			'category'    => $category,
			'stability'   => 'experimental',
		];
	}

	public function register(): void {
		\add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue_editor_assets' ] );
	}

	public static function enqueue_editor_assets(): void {
		if ( \function_exists( 'flavor_agent_enqueue_editor' ) ) {
			\flavor_agent_enqueue_editor();
		}
	}
}
