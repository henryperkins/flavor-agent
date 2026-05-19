<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\RankingContract;
use PHPUnit\Framework\TestCase;

final class RankingContractTest extends TestCase {

	public function test_normalize_builds_required_fields_from_defaults(): void {
		$result = RankingContract::normalize(
			[],
			[
				'score'         => 0.82,
				'reason'        => 'Matches user intent',
				'sourceSignals' => [ 'llm_response', 'llm_response', 'vector' ],
				'safetyMode'    => 'validated',
				'freshnessMeta' => [ 'indexStatus' => 'ready' ],
			]
		);

		$this->assertSame( 0.82, $result['score'] );
		$this->assertSame( 'Matches user intent', $result['reason'] );
		$this->assertSame( [ 'llm_response', 'vector' ], $result['sourceSignals'] );
		$this->assertSame( 'validated', $result['safetyMode'] );
		$this->assertSame( [ 'indexStatus' => 'ready' ], $result['freshnessMeta'] );
	}

	public function test_normalize_accepts_operations_and_ranking_hint(): void {
		$result = RankingContract::normalize(
			[
				'operations'  => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'theme/hero',
					],
				],
				'rankingHint' => [
					'summary' => 'Matches slot constraints.',
				],
			],
			[]
		);

		$this->assertSame( 'insert_pattern', $result['operations'][0]['type'] );
		$this->assertSame( 'theme/hero', $result['operations'][0]['patternName'] );
		$this->assertSame( 'Matches slot constraints.', $result['rankingHint']['summary'] );
	}

	public function test_normalize_uses_confidence_when_score_is_malformed(): void {
		$result = RankingContract::normalize(
			[
				'score'      => [ 'bad-input' ],
				'confidence' => 0.8,
			],
			[]
		);

		$this->assertSame( 0.8, $result['score'] );
	}

	public function test_normalize_returns_zero_when_no_numeric_score_candidates_exist(): void {
		$result = RankingContract::normalize(
			[
				'score' => [ 'bad-input' ],
			],
			[]
		);

		$this->assertSame( 0.0, $result['score'] );
	}

	public function test_normalize_accepts_advisory_type_for_navigation_and_block_surfaces(): void {
		$result = RankingContract::normalize(
			[
				'score'        => 1.7,
				'advisoryType' => 'structural_recommendation',
			],
			[]
		);

		$this->assertSame( 1.0, $result['score'] );
		$this->assertSame( 'structural_recommendation', $result['advisoryType'] );
	}

	public function test_normalize_preserves_optional_ranking_context_fields(): void {
		$result = RankingContract::normalize(
			[
				'score'           => 0.5,
				'designPrinciple' => 'Improve hierarchy',
				'risk'            => 'Avoid layout shift',
			],
			[]
		);

		$this->assertSame( 'Improve hierarchy', $result['designPrinciple'] );
		$this->assertSame( 'Avoid layout shift', $result['risk'] );
	}

	public function test_normalize_merges_model_and_deterministic_source_signals_without_dropping_plugin_signals(): void {
		$result = RankingContract::normalize(
			[
				'sourceSignals' => [ 'model_reason' ],
			],
			[
				'sourceSignals' => [ 'llm_response', 'block_surface', 'has_executable_updates' ],
			]
		);

		$this->assertSame(
			[ 'llm_response', 'block_surface', 'has_executable_updates', 'model_reason' ],
			$result['sourceSignals']
		);
	}

	public function test_derive_score_clamps_weighted_signal_sum(): void {
		$result = RankingContract::derive_score(
			0.45,
			[
				'executable'  => 0.35,
				'description' => 0.2,
				'operations'  => 0.1,
			]
		);

		$this->assertSame( 1.0, $result );
	}

	public function test_blend_score_weights_model_and_deterministic_components(): void {
		$result = RankingContract::blend_score(
			[
				'model'         => 0.55,
				'deterministic' => 0.9,
				'context'       => null,
			]
		);

		$this->assertSame( 0.76, $result );
	}

	public function test_blend_score_renormalizes_when_model_is_missing(): void {
		$result = RankingContract::blend_score(
			[
				'model'         => null,
				'deterministic' => 0.7,
				'context'       => null,
			]
		);

		$this->assertSame( 0.7, $result );
	}

	public function test_blend_score_clamps_components(): void {
		$result = RankingContract::blend_score(
			[
				'model'         => 2,
				'deterministic' => -1,
				'context'       => 0.5,
			]
		);

		$this->assertSame( 0.425, $result );
	}
}
