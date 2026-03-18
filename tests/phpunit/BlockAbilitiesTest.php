<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\BlockAbilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BlockAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->register_paragraph_block();
	}

	public function test_prepare_recommend_block_input_normalizes_editor_context_payload(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'prompt'        => "Add more contrast\n<script>alert('x')</script>",
				'editorContext' => (object) [
					'block'               => (object) [
						'name'                => 'core/paragraph',
						'currentAttributes'   => (object) [
							'content'   => '<strong>Hello</strong>',
							'className' => 'is-style-outline',
						],
						'isInsideContentOnly' => true,
						'editingMode'         => 'disabled',
						'blockVisibility'     => (object) [
							'viewport' => (object) [
								'mobile'  => false,
								'desktop' => true,
							],
						],
						'title'               => 'Paragraph <em>Block</em>',
						'inspectorPanels'     => (object) [
							'color' => [ 'color.background', 'color.text' ],
						],
						'styles'              => [
							(object) [
								'name'      => 'outline',
								'label'     => 'Outline',
								'isDefault' => false,
							],
						],
						'activeStyle'         => 'outline',
						'childCount'          => 2,
						'variations'          => [
							(object) [
								'name'  => 'intro',
								'title' => 'Intro',
							],
						],
						'contentAttributes'   => (object) [
							'content' => (object) [
								'type' => 'string',
								'role' => 'content',
							],
						],
						'configAttributes'    => (object) [
							'dropCap' => (object) [
								'type' => 'boolean',
							],
						],
						'structuralIdentity'  => (object) [
							'role'             => 'footer-paragraph',
							'job'              => 'Paragraph block in the "footer" template part.',
							'location'         => 'footer',
							'templateArea'     => 'footer',
							'templatePartSlug' => 'footer',
							'position'         => (object) [
								'depth'               => 3,
								'siblingIndex'        => 1,
								'siblingCount'        => 2,
								'sameTypeIndex'       => 1,
								'sameTypeCount'       => 1,
								'typeOrderInLocation' => 1,
							],
							'evidence'         => [ 'ancestor-template-part' ],
						],
					],
					'siblingsBefore'      => [ 'core/heading', '', 'core/image', 'core/heading' ],
					'siblingsAfter'       => (object) [ 'core/buttons', '' ],
					'structuralAncestors' => [
						(object) [
							'block'        => 'core/template-part',
							'role'         => 'footer-slot',
							'job'          => 'Footer template-part slot.',
							'location'     => 'footer',
							'templateArea' => 'footer',
						],
					],
					'structuralBranch'    => [
						(object) [
							'block'    => 'core/template-part',
							'role'     => 'footer-slot',
							'children' => [
								(object) [
									'block'      => 'core/paragraph',
									'role'       => 'footer-paragraph',
									'isSelected' => true,
								],
							],
						],
					],
					'themeTokens'         => (object) [
						'colors'            => [ 'accent: #f00', '', 'accent: #f00' ],
						'fontSizes'         => [ 'small: 12px' ],
						'layout'            => (object) [
							'content' => '640px',
							'wide'    => '1200px',
						],
						'enabledFeatures'   => (object) [
							'lineHeight' => true,
						],
						'blockPseudoStyles' => (object) [
							'core/button' => (object) [
								':hover' => (object) [
									'color' => (object) [
										'text' => 'var(--wp--preset--color--base)',
									],
								],
							],
						],
					],
				],
			]
		);

		$this->assertSame(
			sanitize_textarea_field( "Add more contrast\n<script>alert('x')</script>" ),
			$prepared['prompt']
		);
		$this->assertSame( 'core/paragraph', $prepared['context']['block']['name'] );
		$this->assertTrue( $prepared['context']['block']['isInsideContentOnly'] );
		$this->assertSame( 'disabled', $prepared['context']['block']['editingMode'] );
		$this->assertSame( 'Paragraph Block', $prepared['context']['block']['title'] );
		$this->assertSame( [ 'core/heading', 'core/image' ], $prepared['context']['siblingsBefore'] );
		$this->assertSame( [ 'core/buttons' ], $prepared['context']['siblingsAfter'] );
		$this->assertSame(
			[
				'viewport' => [
					'mobile'  => false,
					'desktop' => true,
				],
			],
			$prepared['context']['block']['currentAttributes']['metadata']['blockVisibility']
		);
			$this->assertSame( 'outline', $prepared['context']['block']['activeStyle'] );
			$this->assertSame( 2, $prepared['context']['block']['childCount'] );
			$this->assertSame(
				[ 'color' => [ 'color.background', 'color.text' ] ],
				$prepared['context']['block']['inspectorPanels']
			);
			$this->assertSame( [ 'accent: #f00' ], $prepared['context']['themeTokens']['colors'] );
			$this->assertSame( 'footer-paragraph', $prepared['context']['block']['structuralIdentity']['role'] );
			$this->assertSame( 'footer-slot', $prepared['context']['structuralAncestors'][0]['role'] );
			$this->assertSame( 'footer-slot', $prepared['context']['structuralBranch'][0]['role'] );
	}

	public function test_prepare_recommend_block_input_normalizes_selected_block_payload(): void {
		$prepared = $this->invoke_prepare_recommend_block_input(
			[
				'selectedBlock' => [
					'blockName'           => 'core/paragraph',
					'attributes'          => [
						'content'   => 'Hello world',
						'className' => 'is-style-outline',
					],
					'editingMode'         => 'default',
					'isInsideContentOnly' => false,
					'blockVisibility'     => [
						'viewport' => [
							'mobile' => false,
						],
					],
				],
			]
		);

		$this->assertSame( '', $prepared['prompt'] );
		$this->assertSame( 'core/paragraph', $prepared['context']['block']['name'] );
		$this->assertSame(
			[
				'viewport' => [
					'mobile' => false,
				],
			],
			$prepared['context']['block']['currentAttributes']['metadata']['blockVisibility']
		);
		$this->assertSame( 'outline', $prepared['context']['block']['activeStyle'] );
		$this->assertSame( [ 'accent: #f00' ], $prepared['context']['themeTokens']['colors'] );
		$this->assertSame( [ 'small: 12px' ], $prepared['context']['themeTokens']['fontSizes'] );
	}

	/**
	 * @return array{context: array, prompt: string}
	 */
	private function invoke_prepare_recommend_block_input( array $input ): array {
		$method = new ReflectionMethod( BlockAbilities::class, 'prepare_recommend_block_input' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $input );

		$this->assertIsArray( $result );

		return $result;
	}

	private function register_paragraph_block(): void {
		WordPressTestState::$global_settings = [
			'color'      => [
				'palette' => [
					[
						'slug'  => 'accent',
						'color' => '#f00',
					],
				],
			],
			'typography' => [
				'fontSizes' => [
					[
						'slug' => 'small',
						'size' => '12px',
					],
				],
			],
		];

		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'supports'   => [
					'color'      => [
						'background' => true,
						'text'       => true,
					],
					'typography' => [
						'fontSize' => true,
					],
				],
				'attributes' => [
					'content'   => [
						'type' => 'string',
						'role' => 'content',
					],
					'dropCap'   => [
						'type' => 'boolean',
					],
					'metadata'  => [
						'type' => 'object',
					],
					'className' => [
						'type' => 'string',
					],
				],
				'styles'     => [
					[
						'name'      => 'default',
						'label'     => 'Default',
						'isDefault' => true,
					],
					[
						'name'      => 'outline',
						'label'     => 'Outline',
						'isDefault' => false,
					],
				],
				'variations' => [
					[
						'name'        => 'intro',
						'title'       => 'Intro',
						'description' => 'Intro paragraph',
						'scope'       => [ 'inserter' ],
					],
				],
				'apiVersion' => 3,
			]
		);
	}
}
