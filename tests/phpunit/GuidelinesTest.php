<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Guidelines;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\LLM\WritingPrompt;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class GuidelinesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_sanitize_block_guidelines_accepts_json_and_drops_invalid_entries(): void {
		$input = wp_json_encode(
			[
				'core/paragraph' => [
					'guidelines' => ' Use short paragraphs. ',
				],
				'core/list'      => ' Prefer bulleted lists. ',
				'core/image'     => [
					'guidelines' => '',
				],
				'bad block name' => [
					'guidelines' => 'Ignore this entry.',
				],
				'core/quote'     => [
					'guidelines' => '   ',
				],
			]
		);

		$this->assertSame(
			[
				'core/list'      => 'Prefer bulleted lists.',
				'core/paragraph' => 'Use short paragraphs.',
			],
			Guidelines::sanitize_block_guidelines( $input )
		);
	}

	public function test_export_payload_returns_gutenberg_compatible_shape(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE       => 'Marketing site for a design studio.',
			Guidelines::OPTION_COPY       => 'Use active voice.',
			Guidelines::OPTION_IMAGES     => 'Prefer candid team photography.',
			Guidelines::OPTION_ADDITIONAL => 'Avoid lorem ipsum.',
			Guidelines::OPTION_BLOCKS     => [
				'core/paragraph' => 'Keep paragraphs under three sentences.',
				'core/quote'     => 'Pull quotes should stay under 30 words.',
			],
		];

		$this->assertSame(
			[
				'guideline_categories' => [
					'site'       => [
						'guidelines' => 'Marketing site for a design studio.',
					],
					'copy'       => [
						'guidelines' => 'Use active voice.',
					],
					'images'     => [
						'guidelines' => 'Prefer candid team photography.',
					],
					'additional' => [
						'guidelines' => 'Avoid lorem ipsum.',
					],
					'blocks'     => [
						'core/paragraph' => [
							'guidelines' => 'Keep paragraphs under three sentences.',
						],
						'core/quote'     => [
							'guidelines' => 'Pull quotes should stay under 30 words.',
						],
					],
				],
			],
			Guidelines::export_payload()
		);
	}

	public function test_get_all_prefers_core_guidelines_storage_when_available(): void {
		WordPressTestState::$registered_post_types['wp_guideline']      = [
			'show_in_rest' => true,
		];
		WordPressTestState::$registered_taxonomies['wp_guideline_type'] = [
			'object_type' => 'wp_guideline',
		];
		WordPressTestState::$options                                    = [
			Guidelines::OPTION_SITE   => 'Legacy site context.',
			Guidelines::OPTION_BLOCKS => [
				'core/paragraph' => 'Legacy paragraph rule.',
			],
		];
		WordPressTestState::$posts                                      = [
			101 => (object) [
				'ID'            => 101,
				'post_type'     => 'wp_guideline',
				'post_status'   => 'publish',
				'post_date_gmt' => '2026-04-28 10:00:00',
			],
		];
		WordPressTestState::$post_meta                                  = [
			101 => [
				'_guideline_site'                 => 'Core site context.',
				'_guideline_copy'                 => 'Core copy rule.',
				'_guideline_images'               => 'Core image rule.',
				'_guideline_additional'           => 'Core additional rule.',
				'_guideline_block_core_paragraph' => 'Core paragraph rule.',
			],
		];
		WordPressTestState::$object_terms[101]['wp_guideline_type']     = [ 'content' ];

		$this->assertSame(
			[
				'site'       => 'Core site context.',
				'copy'       => 'Core copy rule.',
				'images'     => 'Core image rule.',
				'additional' => 'Core additional rule.',
				'blocks'     => [
					'core/paragraph' => 'Core paragraph rule.',
				],
			],
			Guidelines::get_all()
		);
		$this->assertSame( 'wp_guideline', WordPressTestState::$get_posts_calls[0]['post_type'] ?? '' );
		$this->assertSame(
			[
				[
					'taxonomy' => 'wp_guideline_type',
					'field'    => 'slug',
					'terms'    => 'content',
				],
			],
			WordPressTestState::$get_posts_calls[0]['tax_query'] ?? []
		);
	}

	public function test_storage_status_reports_active_core_repository(): void {
		WordPressTestState::$registered_post_types['wp_guideline'] = [
			'show_in_rest' => true,
		];

		$this->assertSame(
			[
				'source'              => 'core',
				'core_available'      => true,
				'legacy_has_data'     => false,
				'migration_status'    => 'not_started',
				'migration_completed' => false,
			],
			Guidelines::storage_status()
		);
	}

	private function seed_knowledge_guidelines(): void {
		WordPressTestState::$registered_post_types['wp_knowledge'] = [ 'show_in_rest' => true ];
		WordPressTestState::$posts                                 = [
			201 => (object) [
				'ID'           => 201,
				'post_type'    => 'wp_knowledge',
				'post_status'  => 'publish',
				'post_name'    => 'guideline-copy',
				'post_content' => 'Use active voice.',
			],
			202 => (object) [
				'ID'           => 202,
				'post_type'    => 'wp_knowledge',
				'post_status'  => 'publish',
				'post_name'    => 'guideline-block-core_paragraph',
				'post_content' => 'Keep paragraphs concise.',
			],
		];
	}

	public function test_current_knowledge_guidelines_drive_status_export_and_attribution(): void {
		$this->seed_knowledge_guidelines();
		WordPressTestState::$options[ Guidelines::OPTION_COPY ] = 'Old local copy rule.';

		$this->assertSame( 'Use active voice.', Guidelines::get_all()['copy'] );
		$this->assertSame( [ 'core/paragraph' => 'Keep paragraphs concise.' ], Guidelines::get_block_guidelines() );
		$this->assertTrue( Guidelines::has_any() );
		$this->assertSame( 'core', Guidelines::storage_status()['source'] );
		$this->assertTrue( Guidelines::storage_status()['core_available'] );
		$this->assertSame( 'Use active voice.', Guidelines::export_payload()['guideline_categories']['copy']['guidelines'] );

		$before                                       = Guidelines::version_id();
		WordPressTestState::$posts[201]->post_content = 'Use a playful voice.';
		$this->assertNotSame( $before, Guidelines::version_id() );
	}

	public function test_knowledge_guidelines_ignore_drafts_private_rows_and_unrelated_slugs(): void {
		$this->seed_knowledge_guidelines();
		WordPressTestState::$posts[201]->post_status = 'draft';
		WordPressTestState::$posts[202]->post_status = 'private';
		WordPressTestState::$posts[203]              = (object) [
			'ID'           => 203,
			'post_type'    => 'wp_knowledge',
			'post_status'  => 'publish',
			'post_name'    => 'note-copy',
			'post_content' => 'Not editorial guidance.',
		];

		$this->assertSame( '', Guidelines::get_all()['copy'] );
		$this->assertSame( [], Guidelines::get_block_guidelines() );
		$this->assertFalse( Guidelines::has_any() );
		$this->assertTrue( Guidelines::storage_status()['core_available'] );
	}

	public function test_knowledge_guidelines_only_read_guideline_typed_rows_when_taxonomy_exists(): void {
		$this->seed_knowledge_guidelines();
		WordPressTestState::$registered_taxonomies['wp_knowledge_type'] = [ 'object_type' => 'wp_knowledge' ];
		WordPressTestState::$object_terms                               = [
			201 => [ 'wp_knowledge_type' => [ 'note' ] ],
			202 => [ 'wp_knowledge_type' => [ 'guideline' ] ],
		];

		$this->assertSame( '', Guidelines::get_all()['copy'] );
		$this->assertSame( [ 'core/paragraph' => 'Keep paragraphs concise.' ], Guidelines::get_block_guidelines() );
		$this->assertSame(
			[
				[
					'taxonomy' => 'wp_knowledge_type',
					'field'    => 'slug',
					'terms'    => 'guideline',
				],
			],
			WordPressTestState::$get_posts_calls[0]['tax_query'] ?? []
		);
	}

	public function test_knowledge_guidelines_keep_similar_namespaced_blocks_distinct(): void {
		$this->seed_knowledge_guidelines();
		WordPressTestState::$posts[201]->post_name = 'guideline-block-foo_bar-baz';
		WordPressTestState::$posts[202]->post_name = 'guideline-block-foo-bar_baz';

		$this->assertSame(
			[
				'foo-bar/baz' => 'Keep paragraphs concise.',
				'foo/bar-baz' => 'Use active voice.',
			],
			Guidelines::get_block_guidelines()
		);
	}

	public function test_current_knowledge_storage_takes_precedence_over_retired_singleton(): void {
		$this->seed_knowledge_guidelines();
		WordPressTestState::$registered_post_types['wp_guideline'] = [ 'show_in_rest' => true ];
		WordPressTestState::$posts[101]                            = (object) [
			'ID'          => 101,
			'post_type'   => 'wp_guideline',
			'post_status' => 'publish',
		];
		WordPressTestState::$post_meta[101]                        = [ '_guideline_copy' => 'Retired singleton rule.' ];

		$this->assertSame( 'Use active voice.', Guidelines::get_all()['copy'] );
	}

	public function test_empty_knowledge_storage_preserves_legacy_options_fallback(): void {
		WordPressTestState::$registered_post_types['wp_knowledge'] = [ 'show_in_rest' => true ];
		WordPressTestState::$options[ Guidelines::OPTION_COPY ]    = 'Use active voice.';

		$this->assertSame( 'Use active voice.', Guidelines::get_all()['copy'] );
		$this->assertSame( 'legacy_options', Guidelines::storage_status()['source'] );
		$this->assertTrue( Guidelines::storage_status()['core_available'] );
	}

	public function test_storage_status_reports_legacy_fallback_when_core_unavailable(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_COPY => 'Use active voice.',
		];

		$this->assertSame(
			[
				'source'              => 'legacy_options',
				'core_available'      => false,
				'legacy_has_data'     => true,
				'migration_status'    => 'not_started',
				'migration_completed' => false,
			],
			Guidelines::storage_status()
		);
	}

	public function test_block_prompt_does_not_inject_site_or_block_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE       => 'Marketing site for enterprise buyers.',
			Guidelines::OPTION_COPY       => 'Use direct, plain language.',
			Guidelines::OPTION_IMAGES     => 'Prefer documentary photography.',
			Guidelines::OPTION_ADDITIONAL => 'Avoid discount language.',
			Guidelines::OPTION_BLOCKS     => [
				'core/paragraph' => 'Keep paragraphs under three sentences.',
				'core/image'     => 'Always include descriptive alt text.',
			],
		];

		$prompt = Prompt::build_user(
			[
				'block'       => [
					'name'            => 'core/paragraph',
					'title'           => 'Paragraph',
					'inspectorPanels' => [
						'typography' => true,
					],
				],
				'themeTokens' => [],
			]
		);

		$this->assertStringNotContainsString( '## Site Guidelines', $prompt );
		$this->assertStringNotContainsString( 'Marketing site for enterprise buyers.', $prompt );
		$this->assertStringNotContainsString( 'Use direct, plain language.', $prompt );
		$this->assertStringNotContainsString( 'Prefer documentary photography.', $prompt );
		$this->assertStringNotContainsString( 'Avoid discount language.', $prompt );
		$this->assertStringNotContainsString( 'Keep paragraphs under three sentences.', $prompt );
	}

	public function test_writing_prompt_does_not_inject_site_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE => 'Audience is technical operators.',
			Guidelines::OPTION_COPY => 'Use active voice.',
		];

		$prompt = WritingPrompt::build_user(
			[
				'mode'        => 'draft',
				'postContext' => [
					'postType' => 'post',
				],
			],
			'Draft a launch note.'
		);

		$this->assertStringNotContainsString( '## Site Guidelines', $prompt );
		$this->assertStringNotContainsString( 'Audience is technical operators.', $prompt );
		$this->assertStringNotContainsString( 'Use active voice.', $prompt );
		$this->assertStringContainsString( 'Draft a launch note.', $prompt );
	}

	public function test_prompt_context_prefers_upstream_wordpress_ai_guidelines_when_available(): void {
		WordPressTestState::$options                   = [
			Guidelines::OPTION_SITE => 'Legacy site context.',
		];
		WordPressTestState::$wpai_formatted_guidelines = '<guidelines><site>Upstream site policy.</site></guidelines>';

		$prompt_context = Guidelines::format_prompt_context( 'core/paragraph' );

		$this->assertSame( '<guidelines><site>Upstream site policy.</site></guidelines>', $prompt_context );
		$this->assertSame(
			[
				[
					'categories' => [ 'site', 'copy', 'images', 'additional' ],
					'blockName'  => 'core/paragraph',
				],
			],
			WordPressTestState::$wpai_guideline_calls
		);
	}

	public function test_get_content_block_options_returns_only_blocks_with_content_role_attributes(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		$registry->register(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'attributes' => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);
		$registry->register(
			'flavor/card-copy',
			[
				'title'      => 'Card Copy',
				'attributes' => [
					'body' => [
						'role' => 'content',
					],
				],
			]
		);
		$registry->register(
			'core/image',
			[
				'title'      => 'Image',
				'attributes' => [
					'url' => [
						'type' => 'string',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'value' => 'flavor/card-copy',
					'label' => 'Card Copy',
				],
				[
					'value' => 'core/paragraph',
					'label' => 'Paragraph',
				],
			],
			Guidelines::get_content_block_options()
		);
	}

	public function test_version_id_for_is_prefixed_and_deterministic(): void {
		$guidelines = [
			'site'       => 'Calm, precise brand voice.',
			'copy'       => 'Use active voice.',
			'images'     => 'Documentary photography.',
			'additional' => 'Avoid lorem ipsum.',
			'blocks'     => [
				'core/paragraph' => 'Keep paragraphs concise.',
			],
		];

		$id = Guidelines::version_id_for( $guidelines );

		$this->assertStringStartsWith( 'gv1:', $id );
		$this->assertSame( $id, Guidelines::version_id_for( $guidelines ) );
	}

	public function test_version_id_for_ignores_whitespace_and_block_ordering(): void {
		$base = [
			'site'       => 'Calm, precise brand voice.',
			'copy'       => 'Use active voice.',
			'images'     => '',
			'additional' => '',
			'blocks'     => [
				'core/paragraph' => 'Keep paragraphs concise.',
				'core/heading'   => 'Sentence case headings.',
			],
		];

		$cosmetic = [
			'site'       => "  Calm,   precise\n\nbrand voice.  ",
			'copy'       => "Use active voice.\n",
			'images'     => '',
			'additional' => '',
			'blocks'     => [
				'core/heading'   => 'Sentence case headings.',
				'core/paragraph' => '  Keep paragraphs concise. ',
			],
		];

		$this->assertSame(
			Guidelines::version_id_for( $base ),
			Guidelines::version_id_for( $cosmetic )
		);
	}

	public function test_version_id_for_changes_on_semantic_change(): void {
		$base = [
			'site'       => 'Calm, precise brand voice.',
			'copy'       => 'Use active voice.',
			'images'     => '',
			'additional' => '',
			'blocks'     => [],
		];

		$changed         = $base;
		$changed['copy'] = 'Use a playful, bold voice.';

		$this->assertNotSame(
			Guidelines::version_id_for( $base ),
			Guidelines::version_id_for( $changed )
		);
	}

	public function test_version_id_reads_current_guidelines(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_SITE => 'Marketing site for a design studio.',
			Guidelines::OPTION_COPY => 'Use active voice.',
		];

		$this->assertSame(
			Guidelines::version_id_for( Guidelines::get_all() ),
			Guidelines::version_id()
		);
		$this->assertStringStartsWith( 'gv1:', Guidelines::version_id() );
	}
}
