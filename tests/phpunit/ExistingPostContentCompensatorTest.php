<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Apply\ExistingPostContentCompensator;
use FlavorAgent\Apply\MaterializationWriteContext;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ExistingPostContentCompensatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	public function test_zero_row_no_op_restore_uses_read_back_proof(): void {
		$content = '<!-- wp:paragraph --><p>Unchanged</p><!-- /wp:paragraph -->';

		$result = $this->restore_with_result( 0, $content, $content, $content );

		$this->assertTrue( $result );
	}

	public function test_zero_row_restore_accepts_another_actor_already_restoring_the_exact_before_content(): void {
		$written = '<!-- wp:paragraph --><p>Flavor Agent write</p><!-- /wp:paragraph -->';
		$before  = '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->';

		$result = $this->restore_with_result( 0, $before, $written, $before );

		$this->assertTrue( $result );
	}

	public function test_multi_row_restore_result_remains_a_conflict(): void {
		$content = '<!-- wp:paragraph --><p>Unchanged</p><!-- /wp:paragraph -->';

		$result = $this->restore_with_result( 2, $content, $content, $content );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_recovery_required', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null );
	}

	private function restore_with_result( int $query_result, string $persisted_content, string $written_content, string $before_content ): true|\WP_Error {
		$original_database       = $GLOBALS['wpdb'];
		$database                = new class() extends \wpdb {

			public object $persisted_row;

			public int $query_result = 0;

			public function query( string $query ): int {
				$this->last_query = $query;
				$this->queries[]  = [ $query, 0.0, 'test' ];

				return $this->query_result;
			}

			public function get_row( string $query, string $output = OBJECT ): ?object {
				unset( $query, $output );

				return clone $this->persisted_row;
			}
		};
		$database->query_result  = $query_result;
		$database->persisted_row = new \WP_Post(
			[
				'ID'           => 91,
				'post_type'    => 'wp_template',
				'post_content' => $persisted_content,
			]
		);
		$GLOBALS['wpdb']         = $database;

		try {
			$context = MaterializationWriteContext::capture();
			$this->assertInstanceOf( MaterializationWriteContext::class, $context );
			$result = ExistingPostContentCompensator::restore(
				91,
				'wp_template',
				$written_content,
				$before_content,
				'template',
				$context
			);
		} finally {
			$GLOBALS['wpdb'] = $original_database;
		}

		return $result;
	}
}
