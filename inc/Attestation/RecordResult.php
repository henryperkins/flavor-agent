<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Typed result for best-effort attestation recording.
 */
final class RecordResult {

	public const STATUS_RECORDED       = 'recorded';
	public const STATUS_NOT_CONFIGURED = 'not_configured';
	public const STATUS_FAILED         = 'failed';

	private function __construct(
		private readonly string $status,
		private readonly ?string $attestation_id = null,
		private readonly ?string $error_code = null
	) {}

	public static function recorded( string $attestation_id ): self {
		return new self( self::STATUS_RECORDED, $attestation_id );
	}

	public static function not_configured(): self {
		return new self( self::STATUS_NOT_CONFIGURED );
	}

	public static function failed( string $error_code ): self {
		return new self( self::STATUS_FAILED, null, $error_code );
	}

	public function status(): string {
		return $this->status;
	}

	public function attestation_id(): ?string {
		return $this->attestation_id;
	}

	public function error_code(): ?string {
		return $this->error_code;
	}
}
