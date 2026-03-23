<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AzureBackendValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_embedding_validation_returns_remote_error_message(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 404,
			],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Embedding deployment not found',
					],
				]
			),
		];

		$result = EmbeddingClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'missing-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Embedding deployment not found', $result->get_error_message() );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/embeddings',
			WordPressTestState::$last_remote_post['url']
		);
	}

	public function test_responses_validation_uses_responses_endpoint(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'ok',
				]
			),
		];

		$result = ResponsesClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'chat-deployment'
		);

		$this->assertTrue( $result );
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/responses',
			WordPressTestState::$last_remote_post['url']
		);
	}

	public function test_embedding_validation_rejects_responses_payload_shape(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => 'ok',
				]
			),
		];

		$result = EmbeddingClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'embed-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Unexpected Azure OpenAI embeddings validation response format.',
			$result->get_error_message()
		);
	}

	public function test_responses_validation_rejects_embedding_payload_shape(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'data' => [
						[
							'embedding' => [ 0.1, 0.2 ],
						],
					],
				]
			),
		];

		$result = ResponsesClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'chat-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Unexpected Azure OpenAI responses validation response format.',
			$result->get_error_message()
		);
	}

	public function test_qdrant_validation_reports_parse_failures(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => 'not-json',
		];

		$result = QdrantClient::validate_configuration(
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Failed to parse Qdrant validation response.', $result->get_error_message() );
		$this->assertSame(
			'https://example.cloud.qdrant.io:6333/collections',
			WordPressTestState::$last_remote_get['url']
		);
	}

	public function test_qdrant_validation_accepts_expected_collections_payload(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'status' => 'ok',
					'result' => [
						'collections' => [],
					],
				]
			),
		];

		$result = QdrantClient::validate_configuration(
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertTrue( $result );
	}

	public function test_qdrant_validation_rejects_missing_status_flag(): void {
		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'collections' => [],
					],
				]
			),
		];

		$result = QdrantClient::validate_configuration(
			'https://example.cloud.qdrant.io:6333',
			'qdrant-key'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Qdrant validation response did not contain the expected collections list.',
			$result->get_error_message()
		);
	}
}
