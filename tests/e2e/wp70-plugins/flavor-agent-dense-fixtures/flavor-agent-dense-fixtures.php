<?php
/**
 * Plugin Name: Flavor Agent Dense Fixtures
 * Description: Registers a dense block registry for helper-ability smoke coverage.
 * Version: 0.1.0
 * Author: Flavor Agent
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		for ( $index = 1; $index <= 12; $index++ ) {
			$slug = sprintf( 'fixture-%02d', $index );

			register_block_type(
				'flavor-agent-dense/' . $slug,
				[
					'api_version'     => 3,
					'title'           => sprintf( 'Dense Fixture %02d', $index ),
					'category'        => 'design',
					'attributes'      => [
						'content' => [
							'type' => 'string',
						],
					],
					'supports'        => [
						'color'      => [
							'background' => true,
							'text'       => true,
						],
						'typography' => [
							'fontSize' => true,
						],
					],
					'variations'      => [
						[
							'name'  => 'feature',
							'title' => 'Feature',
						],
						[
							'name'  => 'compact',
							'title' => 'Compact',
						],
						[
							'name'  => 'stacked',
							'title' => 'Stacked',
						],
					],
					'render_callback' => static fn(): string => '',
				]
			);
		}
	}
);
