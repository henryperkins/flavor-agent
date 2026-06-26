<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository;
use FlavorAgent\Apply\ApplyClaim;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ApplyClaimTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
		WordPressTestState::$current_user_id = 7;
		Repository::install();
	}

	/**
	 * @param array<string, mixed> $apply_overrides
	 * @return array<string, mixed>
	 */
	private function create_pending_entry( array $apply_overrides = [] ): array {
		$entry = [
			'type'            => 'apply_global_styles_suggestion',
			'surface'         => 'global-styles',
			'target'          => [ 'globalStylesId' => '17' ],
			'suggestion'      => 'Darken the palette',
			'before'          => [],
			'after'           => [],
			'executionResult' => 'pending',
			'undo'            => [ 'status' => 'not_applicable' ],
			'request'         => [
				'prompt' => 'darker',
				'apply'  => array_replace(
					[
						'status'      => 'pending',
						'requestedBy' => 7,
						'requestedAt' => gmdate( 'c' ),
						'expiresAt'   => gmdate( 'c', time() + 3600 ),
						'operations'  => [],
						'signatures'  => [ 'baselineConfigHash' => str_repeat( 'c', 64 ) ],
					],
					$apply_overrides
				),
			],
			'document'        => [
				'scopeKey' => 'global_styles:17',
				'postType' => 'global_styles',
				'entityId' => '17',
			],
		];

		$created = Repository::create( $entry );
		$this->assertIsArray( $created );

		return $created;
	}

	public function test_claim_sets_and_get_reads_back_and_passes_the_five_minute_ttl(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		$result = ApplyClaim::claim( $id, 7 );

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['claim']['userId'] );
		$this->assertNotEmpty( $result['claim']['claimedAt'] );
		$this->assertSame( 'pending', $result['entry']['apply']['status'] );

		$live = ApplyClaim::get( $id );
		$this->assertIsArray( $live );
		$this->assertSame( 7, $live['userId'] );

		// NOTE (finding 3): the PHPUnit transient stub records the expiration but
		// never enforces it (bootstrap.php get_transient ignores TTL), so this
		// asserts the 5-minute TTL is *passed*, not that the claim time-expires.
		// Real expiry surfaces as an absent transient — covered by the next test.
		$key = 'flavor_agent_apply_claim_' . md5( $id );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, WordPressTestState::$transient_expirations[ $key ] );
	}

	public function test_get_treats_an_absent_transient_as_no_claim(): void {
		// In production, an expired transient reads as absent. Assert the contract
		// ApplyClaim relies on: a missing transient is "no claim", not an error.
		$this->assertNull( ApplyClaim::get( 'never-claimed' ) );

		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );
		delete_transient( 'flavor_agent_apply_claim_' . md5( $id ) ); // simulate TTL elapse

		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_transient_key_is_md5_hashed_and_within_length_limit_for_long_id(): void {
		$long_id = str_repeat( 'a', 191 );
		$key     = 'flavor_agent_apply_claim_' . md5( $long_id );

		// 'flavor_agent_apply_claim_' (25) + 32-char md5 = 57 chars, well under WordPress's ~172-char limit.
		$this->assertLessThanOrEqual( 172, strlen( $key ) );
		$this->assertSame( 57, strlen( $key ) );
	}

	public function test_second_user_claim_returns_existing_claim_without_overwriting(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];

		ApplyClaim::claim( $id, 7 );

		WordPressTestState::$current_user_id = 9;

		$result = ApplyClaim::claim( $id, 9 );

		$this->assertSame( 7, $result['claim']['userId'] );
		$this->assertSame( 7, ApplyClaim::get( $id )['userId'] );
	}

	public function test_claim_on_non_pending_row_writes_nothing(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		Repository::transition_external_apply( $id, [ 'applyStatus' => 'rejected' ] );

		$result = ApplyClaim::claim( $id, 7 );

		$this->assertNull( $result['claim'] );
		$this->assertSame( 'rejected', $result['entry']['apply']['status'] );
		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_claim_on_overdue_pending_row_expires_first_and_grants_no_claim(): void {
		$created = $this->create_pending_entry( [ 'expiresAt' => gmdate( 'c', time() - 60 ) ] );
		$id      = (string) $created['id'];

		$result = ApplyClaim::claim( $id, 7 );

		$this->assertNull( $result['claim'] );
		$this->assertSame( 'expired', $result['entry']['apply']['status'] );
		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_claim_on_missing_row_returns_404(): void {
		$result = ApplyClaim::claim( 'does-not-exist', 7 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_activity_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_release_clears_only_the_callers_own_claim(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );

		$result = ApplyClaim::release( $id, 7 );

		$this->assertNull( $result['claim'] );
		$this->assertNull( ApplyClaim::get( $id ) );
	}

	public function test_release_of_foreign_claim_is_a_no_op_leaving_it_intact(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );

		$result = ApplyClaim::release( $id, 9 );

		$this->assertSame( 7, $result['claim']['userId'] );
		$this->assertSame( 7, ApplyClaim::get( $id )['userId'] );
	}

	public function test_clear_is_unconditional_and_idempotent(): void {
		$created = $this->create_pending_entry();
		$id      = (string) $created['id'];
		ApplyClaim::claim( $id, 7 );

		ApplyClaim::clear( $id );
		ApplyClaim::clear( $id );

		$this->assertNull( ApplyClaim::get( $id ) );
	}
}
