<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\Repository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_install_creates_the_attestation_table_and_schema_option(): void {
		Repository::install();

		$this->assertArrayHasKey( Repository::table_name(), WordPressTestState::$db_tables );
		$this->assertSame( Repository::SCHEMA_VERSION, WordPressTestState::$options[ Repository::SCHEMA_OPTION ] ?? null );
	}

	public function test_insert_then_find_round_trips(): void {
		Repository::install();
		Repository::insert(
			[
				'attestation_id'      => 'att_1',
				'surface'             => 'global-styles',
				'subject_name'        => 'wp_global_styles:81',
				'subject_scope'       => 'global-styles',
				'after_digest'        => 'a',
				'statement_bytes'     => '{"x":1}',
				'signature_b64'       => 'sig',
				'key_id'              => 'k1',
				'related_activity_id' => 'act_9',
			]
		);

		$row = Repository::find( 'att_1' );

		$this->assertIsArray( $row );
		$this->assertSame( '{"x":1}', $row['statement_bytes'] );
		$this->assertSame( 'att_1', Repository::find_by_related_activity( 'act_9' )['attestation_id'] );
	}

	public function test_find_by_reverts_locates_chained_revert(): void {
		Repository::install();
		Repository::insert(
			[
				'attestation_id'         => 'att_2',
				'surface'                => 'global-styles',
				'subject_name'           => 's',
				'subject_scope'          => 'global-styles',
				'after_digest'           => 'a2',
				'statement_bytes'        => '{}',
				'signature_b64'          => 'x',
				'key_id'                 => 'k1',
				'reverts_attestation_id' => 'att_1',
			]
		);

		$this->assertSame( 'att_2', Repository::find_by_reverts( 'att_1' )['attestation_id'] );
	}

	public function test_repository_exposes_no_update_or_delete(): void {
		$this->assertFalse( method_exists( Repository::class, 'update' ) );
		$this->assertFalse( method_exists( Repository::class, 'delete' ) );
	}

	public function test_find_by_related_activity_excludes_revert_rows(): void {
		Repository::install();

		Repository::insert(
			[
				'attestation_id'      => 'att_apply',
				'surface'             => 'global-styles',
				'subject_name'        => 'wp_global_styles:81',
				'subject_scope'       => 'global-styles',
				'after_digest'        => 'a',
				'statement_bytes'     => '{}',
				'signature_b64'       => 'sig',
				'key_id'              => 'k1',
				'related_activity_id' => 'act_9',
			]
		);
		Repository::insert(
			[
				'attestation_id'         => 'att_revert',
				'surface'                => 'global-styles',
				'subject_name'           => 'wp_global_styles:81',
				'subject_scope'          => 'global-styles',
				'after_digest'           => 'b',
				'statement_bytes'        => '{}',
				'signature_b64'          => 'sig',
				'key_id'                 => 'k1',
				'related_activity_id'    => 'act_9',
				'reverts_attestation_id' => 'att_apply',
			]
		);

		$this->assertSame( 'att_apply', Repository::find_by_related_activity( 'act_9' )['attestation_id'] );
	}
}
