<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\DesignSemantics;
use PHPUnit\Framework\TestCase;

final class DesignSemanticsTest extends TestCase {

	public function test_normalizes_and_caps_shared_design_semantics(): void {
		$normalized = DesignSemantics::normalize(
			[
				'surface'             => 'block',
				'sectionRole'         => 'hero',
				'visualDensity'       => 'dense',
				'contrastContext'     => 'dark-parent',
				'layoutRhythm'        => 'grid',
				'typographyRole'      => 'heading',
				'existingDesignScore' => 1.5,
				'mainDesignIssue'     => 'contrast',
				'tokenAffinity'       => [
					'color'    => [ 'contrast', 'base', 'contrast', 'accent', 'muted', 'primary', 'secondary' ],
					'spacing'  => [ 'large' ],
					'fontSize' => [ 'heading' ],
				],
				'negativeSignals'     => [ 'a', 'b', 'c', 'd', 'e', 'f', 'g' ],
				'unknown'             => '<script>alert(1)</script>',
				'block'               => [
					'name'        => 'core/group',
					'unsupported' => [ 'drop' ],
					'visible'     => true,
				],
			],
			'block'
		);

		$this->assertSame( 'block', $normalized['surface'] );
		$this->assertSame( 'hero', $normalized['sectionRole'] );
		$this->assertSame( 1.0, $normalized['existingDesignScore'] );
		$this->assertArrayNotHasKey( 'unknown', $normalized );
		$this->assertCount( 6, $normalized['tokenAffinity']['color'] );
		$this->assertCount( 6, $normalized['negativeSignals'] );
		$this->assertSame( 'core/group', $normalized['block']['name'] );
		$this->assertTrue( $normalized['block']['visible'] );
		$this->assertArrayNotHasKey( 'unsupported', $normalized['block'] );
	}

	public function test_formats_prompt_lines_without_raw_json_dump(): void {
		$lines = DesignSemantics::format_prompt_lines(
			[
				'surface'         => 'template-part',
				'sectionRole'     => 'footer',
				'visualDensity'   => 'balanced',
				'contrastContext' => 'dark-parent',
				'layoutRhythm'    => 'constrained',
				'typographyRole'  => 'body',
				'mainDesignIssue' => 'contrast',
				'negativeSignals' => [ 'parent-already-supplies-contrast' ],
				'templatePart'    => [
					'area' => 'footer',
				],
			]
		);

		$this->assertContains( 'Role: footer', $lines );
		$this->assertContains( 'Contrast: dark-parent', $lines );
		$this->assertContains( 'Main issue: contrast', $lines );
		$this->assertContains( 'Negative signals: parent-already-supplies-contrast', $lines );
		$this->assertContains( 'Template part: area=footer', $lines );
	}

	public function test_normalizes_header_template_part_section_role(): void {
		$normalized = DesignSemantics::normalize(
			[
				'surface'         => 'template-part',
				'sectionRole'     => 'header',
				'visualDensity'   => 'balanced',
				'contrastContext' => 'unknown',
				'layoutRhythm'    => 'stacked',
				'typographyRole'  => 'navigation',
				'templatePart'    => [
					'ref'  => 'theme//header',
					'slug' => 'header',
					'area' => 'header',
				],
			],
			'template-part'
		);

		$this->assertSame( 'header', $normalized['sectionRole'] );
		$this->assertContains(
			'Role: header',
			DesignSemantics::format_prompt_lines( $normalized )
		);
	}

	public function test_formats_prompt_lines_under_estimated_token_cap(): void {
		$lines = DesignSemantics::format_prompt_lines(
			[
				'surface'         => 'block',
				'sectionRole'     => 'hero',
				'visualDensity'   => 'dense',
				'contrastContext' => 'image-overlay',
				'layoutRhythm'    => 'grid',
				'typographyRole'  => 'heading',
				'mainDesignIssue' => 'accessibility',
				'negativeSignals' => [
					'parent-already-supplies-contrast',
					'no-typography-support',
					'content-only-context',
					'locked-editing-mode',
				],
				'block'           => [
					'name'        => 'core/group',
					'role'        => 'hero-card',
					'parentBlock' => 'core/cover',
				],
			],
			12
		);

		$text = implode( "\n", $lines );

		$this->assertLessThanOrEqual(
			12,
			(int) ceil( strlen( $text ) / 4 )
		);
	}

	public function test_normalizes_malformed_enum_values_without_warnings_or_fatals(): void {
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);

		try {
			$normalized = DesignSemantics::normalize(
				[
					'surface'         => [ 'template' ],
					'sectionRole'     => [ 'footer' ],
					'visualDensity'   => new \stdClass(),
					'contrastContext' => [ 'dark-parent' ],
					'layoutRhythm'    => new \stdClass(),
					'typographyRole'  => [ 'body' ],
					'mainDesignIssue' => new \stdClass(),
				],
				'template'
			);
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'template', $normalized['surface'] );
		$this->assertSame( 'unknown', $normalized['sectionRole'] );
		$this->assertSame( 'unknown', $normalized['visualDensity'] );
		$this->assertSame( 'unknown', $normalized['contrastContext'] );
		$this->assertSame( 'unknown', $normalized['layoutRhythm'] );
		$this->assertSame( 'unknown', $normalized['typographyRole'] );
		$this->assertSame( 'unknown', $normalized['mainDesignIssue'] );
	}
}
