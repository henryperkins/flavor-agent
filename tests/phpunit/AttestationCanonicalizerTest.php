<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class AttestationCanonicalizerTest extends TestCase {

	public function test_digest_is_stable_under_key_order(): void {
		$a = [
			'styles'   => [
				'color' => [
					'text'       => 'x',
					'background' => 'y',
				],
			],
			'settings' => [],
		];
		$b = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'y',
					'text'       => 'x',
				],
			],
		];

		$this->assertSame( Canonicalizer::digest( $a ), Canonicalizer::digest( $b ) );
	}

	public function test_preset_reference_forms_canonicalize_equal(): void {
		$ref = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'var:preset|color|parchment-100',
				],
			],
		];
		$css = [
			'settings' => [],
			'styles'   => [
				'color' => [
					'background' => 'var(--wp--preset--color--parchment-100)',
				],
			],
		];

		$this->assertSame( Canonicalizer::digest( $ref ), Canonicalizer::digest( $css ) );
	}

	public function test_subject_digest_branch_scopes_to_block(): void {
		$config = [
			'settings' => [],
			'styles'   => [
				'blocks' => [
					'core/button'  => [
						'color' => [
							'text' => 'z',
						],
					],
					'core/heading' => [
						'color' => [
							'text' => 'q',
						],
					],
				],
			],
		];

		$full   = Canonicalizer::subject_digest( $config, 'global-styles' );
		$branch = Canonicalizer::subject_digest( $config, 'style-book-branch', 'core/button' );

		$this->assertNotSame( $full, $branch );
	}
}
