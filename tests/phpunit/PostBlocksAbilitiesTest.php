<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\PostBlocksAbilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class PostBlocksAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressTestState::reset();
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	private function seed_post( int $id, string $content ): void {
		WordPressTestState::$posts[ $id ] = new \WP_Post(
			[
				'ID'           => $id,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Doc ' . $id,
				'post_content' => $content,
			]
		);
	}

	public function test_missing_post_id_is_rejected(): void {
		$result = PostBlocksAbilities::recommend_post_blocks( [ 'prompt' => 'hi' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	public function test_unknown_post_propagates_collector_error(): void {
		$result = PostBlocksAbilities::recommend_post_blocks( [ 'postId' => 987654 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flavor_agent_apply_target_unavailable', $result->get_error_code() );
	}

	public function test_resolve_signature_only_returns_signatures_without_calling_the_model(): void {
		$this->seed_post( 50, $this->paragraph( 'Hello world' ) );

		$result = PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => 50,
				'prompt'               => 'add a call to action',
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['reviewContextSignature'] ?? '' );
		$this->assertNotEmpty( $result['resolvedContextSignature'] ?? '' );
		$this->assertArrayHasKey( 'docsGrounding', $result );
		$this->assertArrayHasKey( 'docsGroundingFingerprint', $result );
	}

	public function test_review_signature_is_stable_for_identical_documents(): void {
		$this->seed_post( 51, $this->paragraph( 'Same content' ) );

		$first = PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => 51,
				'prompt'               => 'improve structure',
				'resolveSignatureOnly' => true,
			]
		);
		$second = PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => 51,
				'prompt'               => 'improve structure',
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['reviewContextSignature'], $second['reviewContextSignature'] );
		$this->assertSame( $first['resolvedContextSignature'], $second['resolvedContextSignature'] );
	}

	public function test_resolved_signature_changes_when_document_content_changes(): void {
		$this->seed_post( 52, $this->paragraph( 'Version A' ) );

		$baseline = PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => 52,
				'resolveSignatureOnly' => true,
			]
		);

		$this->seed_post( 52, $this->paragraph( 'Version B' ) . $this->paragraph( 'Extra' ) );

		$changed = PostBlocksAbilities::recommend_post_blocks(
			[
				'postId'               => 52,
				'resolveSignatureOnly' => true,
			]
		);

		$this->assertIsArray( $baseline );
		$this->assertIsArray( $changed );
		// Content drift is caught by baselineContentHash at apply time (mirroring
		// the template-part precedent), not the review-context signature, which
		// stays scoped to identity + candidate patterns + theme tokens + docs.
		$this->assertNotSame( $baseline['resolvedContextSignature'], $changed['resolvedContextSignature'] );
	}

	public function test_full_generation_requires_a_configured_text_provider(): void {
		$this->seed_post( 53, $this->paragraph( 'Needs a provider' ) );

		$result = PostBlocksAbilities::recommend_post_blocks(
			[
				'postId' => 53,
				'prompt' => 'add a section',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text_generation_provider', $result->get_error_code() );
	}
}
