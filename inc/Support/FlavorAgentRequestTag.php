<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FlavorAgentRequestTag {

	private string $surface;

	private string $ability_name;

	private string $scope_key;

	/**
	 * @var array<string, mixed>
	 */
	private array $document_ref;

	private string $request_token;

	/**
	 * @var self|null
	 */
	private static ?self $current = null;

	/**
	 * @param array<string, mixed> $document_ref
	 */
	public function __construct(
		string $surface,
		string $ability_name,
		string $scope_key,
		array $document_ref,
		string $request_token
	) {
		$this->surface       = $surface;
		$this->ability_name  = $ability_name;
		$this->scope_key     = $scope_key;
		$this->document_ref  = $document_ref;
		$this->request_token = $request_token;
	}

	public static function start( self $tag ): void {
		self::$current = $tag;
	}

	public static function current(): ?self {
		return self::$current;
	}

	public static function finish(): void {
		self::$current = null;
	}

	public function surface(): string {
		return $this->surface;
	}

	public function ability_name(): string {
		return $this->ability_name;
	}

	public function scope_key(): string {
		return $this->scope_key;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function document_ref(): array {
		return $this->document_ref;
	}

	public function request_token(): string {
		return $this->request_token;
	}
}
