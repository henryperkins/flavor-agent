<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Serializer;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ActivitySerializerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_normalize_entry_trims_scalars_and_normalizes_undo_state(): void {
		$entry = Serializer::normalize_entry(
			[
				'id'              => ' activity-1 ',
				'schemaVersion'   => -7,
				'type'            => ' apply_style_suggestion ',
				'surface'         => ' style-book ',
				'target'          => (object) [
					'globalStylesId' => ' wp-global-styles-theme ',
					'blockName'      => 'core/paragraph',
					'nested'         => (object) [
						'enabled' => true,
					],
				],
				'suggestion'      => ' Tighten paragraph rhythm ',
				'suggestionKey'   => ' ',
				'timestamp'       => '2026-03-24T10:00:00Z',
				'executionResult' => ' reviewed ',
				'undo'            => [
					'status'    => 'failed',
					'error'     => ' Apply failed ',
					'updatedAt' => '2026-03-24T10:05:00Z',
					'undoneAt'  => '2026-03-24T10:07:00Z',
				],
			]
		);

		$this->assertSame( 'activity-1', $entry['id'] );
		$this->assertSame( 1, $entry['schemaVersion'] );
		$this->assertSame( 'apply_style_suggestion', $entry['type'] );
		$this->assertSame( 'style-book', $entry['surface'] );
		$this->assertSame( 'Tighten paragraph rhythm', $entry['suggestion'] );
		$this->assertNull( $entry['suggestionKey'] );
		$this->assertSame( 'reviewed', $entry['executionResult'] );
		$this->assertSame( '2026-03-24T10:00:00+00:00', $entry['timestamp'] );
		$this->assertSame(
			[
				'globalStylesId' => ' wp-global-styles-theme ',
				'blockName'      => 'core/paragraph',
				'nested'         => [
					'enabled' => true,
				],
			],
			$entry['target']
		);
		$this->assertSame(
			[
				'canUndo'   => false,
				'status'    => 'failed',
				'error'     => 'Apply failed',
				'updatedAt' => '2026-03-24T10:05:00+00:00',
				'undoneAt'  => null,
			],
			$entry['undo']
		);
	}

	public function test_derive_entity_uses_surface_specific_refs(): void {
		$this->assertSame(
			[
				'type' => 'template',
				'ref'  => 'theme//home',
			],
			Serializer::derive_entity(
				[
					'surface' => 'template',
					'target'  => [
						'templateRef' => 'theme//home',
					],
				]
			)
		);
		$this->assertSame(
			[
				'type' => 'template-part',
				'ref'  => 'wp_template_part:theme//header',
			],
			Serializer::derive_entity(
				[
					'surface'  => 'template-part',
					'target'   => [],
					'document' => [
						'scopeKey' => 'wp_template_part:theme//header',
					],
				]
			)
		);
		$this->assertSame(
			[
				'type' => 'style-book',
				'ref'  => 'wp_global_styles:theme:block:core/paragraph',
			],
			Serializer::derive_entity(
				[
					'surface'  => 'style-book',
					'target'   => [
						'blockName' => 'core/paragraph',
					],
					'document' => [
						'scopeKey' => 'wp_global_styles:theme',
					],
				]
			)
		);
		$this->assertSame(
			[
				'type' => 'navigation',
				'ref'  => 'post:42:client:block-1',
			],
			Serializer::derive_entity(
				[
					'surface'  => 'navigation',
					'target'   => [
						'clientId' => 'block-1',
					],
					'document' => [
						'scopeKey' => 'post:42',
					],
				]
			)
		);
		$this->assertSame(
			[
				'type' => 'block',
				'ref'  => 'post:42:path:0.2:block:core/group',
			],
			Serializer::derive_entity(
				[
					'surface'  => 'block',
					'target'   => [
						'blockName' => 'core/group',
						'blockPath' => [ 0, 2 ],
					],
					'document' => [
						'scopeKey' => 'post:42',
					],
				]
			)
		);
	}

	public function test_hydrate_row_decodes_json_and_adds_server_persistence_metadata(): void {
		$entry = Serializer::hydrate_row(
			[
				'activity_id'      => 'activity-1',
				'schema_version'   => '2',
				'activity_type'    => 'apply_template_suggestion',
				'surface'          => 'template',
				'target_json'      => '{"templateRef":"theme//home"}',
				'suggestion'       => 'Clarify hierarchy',
				'suggestion_key'   => '',
				'before_state'     => '{"operations":[]}',
				'after_state'      => '{"operations":[{"type":"insert_pattern"}]}',
				'request_json'     => '{"prompt":"Make the page editorial."}',
				'document_json'    => '{"scopeKey":"wp_template:theme//home"}',
				'execution_result' => 'applied',
				'undo_state'       => '{"status":"undone","undoneAt":"2026-03-24T10:07:00Z"}',
				'user_id'          => '7',
				'created_at'       => '2026-03-24 10:00:00',
			]
		);

		$this->assertSame( 'activity-1', $entry['id'] );
		$this->assertSame( 2, $entry['schemaVersion'] );
		$this->assertSame( [ 'templateRef' => 'theme//home' ], $entry['target'] );
		$this->assertSame( [ 'prompt' => 'Make the page editorial.' ], $entry['request'] );
		$this->assertSame( '2026-03-24T10:00:00+00:00', $entry['timestamp'] );
		$this->assertSame(
			[
				'canUndo'   => false,
				'status'    => 'undone',
				'error'     => null,
				'updatedAt' => '2026-03-24T10:00:00+00:00',
				'undoneAt'  => '2026-03-24T10:07:00+00:00',
			],
			$entry['undo']
		);
		$this->assertSame( 7, $entry['userId'] );
		$this->assertSame( 'User #7', $entry['userLabel'] );
		$this->assertSame( [ 'status' => 'server' ], $entry['persistence'] );
	}

	public function test_json_helpers_fail_closed_to_array_payloads(): void {
		$this->assertSame( [], Serializer::decode_json( '' ) );
		$this->assertSame( [], Serializer::decode_json( 'not-json' ) );
		$this->assertSame(
			'{"keep":"value","drop":null}',
			Serializer::encode_json(
				[
					'keep' => 'value',
					'drop' => fopen( 'php://memory', 'r' ),
				]
			)
		);
	}
}
