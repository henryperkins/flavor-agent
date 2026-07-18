<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Attestation\StatementBuilder;
use PHPUnit\Framework\TestCase;

final class AttestationStatementBuilderTest extends TestCase {

	public function test_subject_digest_equals_after_digest(): void {
		$statement = json_decode( StatementBuilder::build( $this->params() ), true );

		$this->assertSame( $statement['subject'][0]['digest']['sha256'], $statement['predicate']['after']['sha256'] );
	}

	public function test_statement_freezes_the_owned_governance_lane(): void {
		$statement = json_decode( StatementBuilder::build( $this->params() ), true );

		$this->assertSame(
			[
				'approvalSurface' => 'settings-ai-activity',
				'claim'           => 'governed-change',
				'executor'        => 'bounded-server-style-apply',
				'lane'            => 'external-style-apply-v1',
			],
			$statement['predicate']['governance']
		);
	}

	public function test_excludes_non_allowlisted_fields(): void {
		$params                    = $this->params();
		$params['prompt']          = 'SECRET PROMPT';
		$params['displayName']     = 'Henry Perkins';
		$params['providerPayload'] = [ 'k' => 'v' ];

		$bytes = StatementBuilder::build( $params );

		$this->assertStringNotContainsString( 'SECRET PROMPT', $bytes );
		$this->assertStringNotContainsString( 'Henry Perkins', $bytes );
		$this->assertStringNotContainsString( 'providerPayload', $bytes );
	}

	public function test_excludes_the_executor_only_style_before_value(): void {
		$params                                 = $this->params();
		$params['operations'][0]['beforeValue'] = 'SECRET PREVIOUS VALUE';

		$bytes = StatementBuilder::build( $params );

		$this->assertStringNotContainsString( 'beforeValue', $bytes );
		$this->assertStringNotContainsString( 'SECRET PREVIOUS VALUE', $bytes );
	}

	public function test_rejects_non_allowlisted_operation_fields(): void {
		$params                                     = $this->params();
		$params['operations'][0]['providerPayload'] = [ 'secret' => 'DO NOT PUBLISH' ];

		$this->expectException( \InvalidArgumentException::class );

		StatementBuilder::build( $params );
	}

	public function test_structural_operations_exclude_nested_target_attributes_and_labels(): void {
		$params = $this->structural_params();

		$bytes     = StatementBuilder::build( $params );
		$statement = json_decode( $bytes, true );

		$this->assertSame(
			[
				'childCount' => 0,
				'name'       => 'core/paragraph',
			],
			$statement['predicate']['operations'][0]['expectedTarget']
		);
		$this->assertStringNotContainsString( 'SECRET BLOCK ATTRIBUTE', $bytes );
		$this->assertStringNotContainsString( 'Private editor label', $bytes );
	}

	public function test_rejects_an_operation_shape_from_another_lane(): void {
		$params                  = $this->structural_params();
		$params['operations'][0] = [
			'type'       => 'set_styles',
			'blockName'  => '',
			'path'       => [ 'color', 'text' ],
			'value'      => '#111111',
			'valueType'  => 'freeform',
			'presetType' => '',
			'presetSlug' => '',
			'cssVar'     => '',
		];

		$this->expectException( \InvalidArgumentException::class );

		StatementBuilder::build( $params );
	}

	/**
	 * @dataProvider public_operation_shapes
	 *
	 * @param array<string, mixed> $operation
	 * @param array<string, mixed> $expected
	 */
	public function test_projects_each_executor_operation_shape(
		string $surface,
		string $lane,
		string $executor,
		array $operation,
		array $expected
	): void {
		$params                   = $this->params();
		$params['surface']        = $surface;
		$params['scope']          = $surface;
		$params['governanceLane'] = $lane;
		$params['executor']       = $executor;
		$params['operations']     = [ $operation ];

		$statement = json_decode( StatementBuilder::build( $params ), true );

		$this->assertSame( [ $expected ], $statement['predicate']['operations'] );
	}

	/**
	 * @return array<string, array{string, string, string, array<string, mixed>, array<string, mixed>}>
	 */
	public static function public_operation_shapes(): array {
		$private_target = [
			'name'       => 'core/paragraph',
			'label'      => 'Private editor label',
			'attributes' => [ 'metadata' => [ 'prompt' => 'SECRET BLOCK ATTRIBUTE' ] ],
			'childCount' => 0,
		];

		return [
			'theme variation'                     => [
				'global-styles',
				'external-style-apply-v1',
				'bounded-server-style-apply',
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 1,
					'variationTitle' => 'Midnight',
				],
				[
					'type'           => 'set_theme_variation',
					'variationIndex' => 1,
					'variationTitle' => 'Midnight',
				],
			],
			'block styles'                        => [
				'style-book',
				'external-style-apply-v1',
				'bounded-server-style-apply',
				[
					'type'        => 'set_block_styles',
					'blockName'   => 'core/paragraph',
					'path'        => [ 'typography', 'fontSize' ],
					'value'       => 'var:preset|font-size|body',
					'valueType'   => 'preset',
					'presetType'  => 'font-size',
					'presetSlug'  => 'body',
					'cssVar'      => 'var(--wp--preset--font-size--body)',
					'beforeValue' => [ 'private' => true ],
				],
				[
					'blockName'  => 'core/paragraph',
					'cssVar'     => 'var(--wp--preset--font-size--body)',
					'path'       => [ 'typography', 'fontSize' ],
					'presetSlug' => 'body',
					'presetType' => 'font-size',
					'type'       => 'set_block_styles',
					'value'      => 'var:preset|font-size|body',
					'valueType'  => 'preset',
				],
			],
			'template insertion'                  => [
				'template',
				'external-template-apply-v1',
				'bounded-server-template-apply',
				[
					'type'        => 'insert_pattern',
					'patternName' => 'twentytwentyfive/hero',
					'placement'   => 'start',
				],
				[
					'patternName' => 'twentytwentyfive/hero',
					'placement'   => 'start',
					'type'        => 'insert_pattern',
				],
			],
			'path-addressed insertion'            => [
				'template',
				'external-template-apply-v1',
				'bounded-server-template-apply',
				[
					'type'           => 'insert_pattern',
					'patternName'    => 'twentytwentyfive/hero',
					'placement'      => 'before_block_path',
					'targetPath'     => [ 0 ],
					'expectedTarget' => $private_target,
				],
				[
					'expectedTarget' => [
						'childCount' => 0,
						'name'       => 'core/paragraph',
					],
					'patternName'    => 'twentytwentyfive/hero',
					'placement'      => 'before_block_path',
					'targetPath'     => [ 0 ],
					'type'           => 'insert_pattern',
				],
			],
			'template-part insertion'             => [
				'template-part',
				'external-template-part-apply-v1',
				'bounded-server-template-part-apply',
				[
					'type'        => 'insert_pattern',
					'patternName' => 'twentytwentyfive/navigation',
					'placement'   => 'end',
				],
				[
					'patternName' => 'twentytwentyfive/navigation',
					'placement'   => 'end',
					'type'        => 'insert_pattern',
				],
			],
			'template-part replacement with slot' => [
				'template-part',
				'external-template-part-apply-v1',
				'bounded-server-template-part-apply',
				[
					'type'              => 'replace_block_with_pattern',
					'patternName'       => 'twentytwentyfive/navigation',
					'expectedBlockName' => 'core/template-part',
					'targetPath'        => [ 0 ],
					'expectedTarget'    => [
						'name'       => 'core/template-part',
						'label'      => 'Private editor label',
						'attributes' => [ 'slug' => 'header' ],
						'childCount' => 0,
						'slot'       => [
							'slug'    => 'header',
							'area'    => 'header',
							'isEmpty' => false,
						],
					],
				],
				[
					'expectedBlockName' => 'core/template-part',
					'expectedTarget'    => [
						'childCount' => 0,
						'name'       => 'core/template-part',
						'slot'       => [
							'area'    => 'header',
							'isEmpty' => false,
							'slug'    => 'header',
						],
					],
					'patternName'       => 'twentytwentyfive/navigation',
					'targetPath'        => [ 0 ],
					'type'              => 'replace_block_with_pattern',
				],
			],
		];
	}

	public function test_canonical_json_is_key_order_stable(): void {
		$this->assertSame(
			StatementBuilder::canonical_json(
				[
					'b' => 1,
					'a' => 2,
				]
			),
			StatementBuilder::canonical_json(
				[
					'a' => 2,
					'b' => 1,
				]
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function params(): array {
		return [
			'attestationId'      => 'att_1',
			'surface'            => 'global-styles',
			'scope'              => 'global-styles',
			'subjectName'        => 'wp_global_styles:81',
			'governanceClaim'    => 'governed-change',
			'governanceLane'     => 'external-style-apply-v1',
			'approvalSurface'    => 'settings-ai-activity',
			'executor'           => 'bounded-server-style-apply',
			'operations'         => [
				[
					'type'       => 'set_styles',
					'blockName'  => '',
					'path'       => [ 'color', 'background' ],
					'value'      => 'var:preset|color|parchment-100',
					'valueType'  => 'preset',
					'presetType' => 'color',
					'presetSlug' => 'parchment-100',
					'cssVar'     => 'var(--wp--preset--color--parchment-100)',
				],
			],
			'beforeDigest'       => 'b',
			'afterDigest'        => 'a',
			'freshnessSignature' => 'f',
			'actorRole'          => 'administrator',
			'proposerVia'        => 'mcp/flavor-agent',
			'decision'           => 'approve',
			'requestedAt'        => '2026-06-22T00:00:00+00:00',
			'decidedAt'          => '2026-06-22T00:01:00+00:00',
			'siteUrl'            => 'https://example.com',
			'keyId'              => 'k1',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function structural_params(): array {
		$params                   = $this->params();
		$params['surface']        = 'template-part';
		$params['scope']          = 'template-part';
		$params['subjectName']    = 'wp_template_part:twentytwentyfive//header';
		$params['governanceLane'] = 'external-template-part-apply-v1';
		$params['executor']       = 'bounded-server-template-part-apply';
		$params['operations']     = [
			[
				'type'              => 'remove_block',
				'expectedBlockName' => 'core/paragraph',
				'targetPath'        => [ 0 ],
				'expectedTarget'    => [
					'name'       => 'core/paragraph',
					'label'      => 'Private editor label',
					'attributes' => [ 'metadata' => [ 'prompt' => 'SECRET BLOCK ATTRIBUTE' ] ],
					'childCount' => 0,
				],
			],
		];

		return $params;
	}
}
