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

	public function test_ranking_hint_component_scores_round_trip_as_bounded_plugin_owned_scores(): void {
		$ranking = RankingContract::normalize(
			[],
			[
				'score'       => 0.82,
				'rankingHint' => [
					'componentScores' => [
						'semantic'  => 1.40,
						'structure' => -0.20,
						'design'    => '0.90',
						'area'      => 'not numeric',
						'override'  => 0.40,
						'blended'   => 0.74,
						'untrusted' => 0.99,
					],
				],
			]
		);

		$this->assertSame(
			[
				'semantic'  => 1.0,
				'structure' => 0.0,
				'design'    => 0.90,
				'area'      => 0.0,
				'override'  => 0.40,
				'blended'   => 0.74,
			],
			$ranking['rankingHint']['componentScores']
		);
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

	public function test_normalize_ignores_plugin_owned_contextual_fields_from_input(): void {
		$result = RankingContract::normalize(
			[
				'score'              => 0.6,
				'modelScore'         => 1,
				'deterministicScore' => 1,
				'contextScore'       => 1,
				'blendedScore'       => 1,
				'contextEvidence'    => [
					'prompt_match' => 1,
				],
				'contextPenalties'   => [
					'stale_docs' => 1,
				],
				'rankingVersion'     => 'contextual-ranking-v1',
			],
			[]
		);

		$this->assertArrayNotHasKey( 'modelScore', $result );
		$this->assertArrayNotHasKey( 'deterministicScore', $result );
		$this->assertArrayNotHasKey( 'contextScore', $result );
		$this->assertArrayNotHasKey( 'blendedScore', $result );
		$this->assertArrayNotHasKey( 'contextEvidence', $result );
		$this->assertArrayNotHasKey( 'contextPenalties', $result );
		$this->assertArrayNotHasKey( 'rankingVersion', $result );
	}

	public function test_normalize_preserves_sanitized_plugin_owned_contextual_defaults(): void {
		$result = RankingContract::normalize(
			[],
			[
				'score'              => 0.7,
				'modelScore'         => 0.9,
				'deterministicScore' => 0.8,
				'contextScore'       => 0.6,
				'blendedScore'       => 0.7,
				'contextEvidence'    => [
					'prompt_match'         => 1.4,
					'unsupported_payload'  => 0.8,
					'supports_fit'         => 'not numeric',
					'operation_fit'        => 0.6,
					'section_role_match'   => 0.5,
					'docs_freshness'       => 0.4,
					'pattern_readiness'    => 0.3,
					'visible_scope_match'  => 0.2,
					'native_preset_fit'    => 0.1,
					'accessibility_fit'    => 0.0,
					'design_semantics_fit' => -1,
				],
				'contextPenalties'   => [
					'stale_docs' => 0.15,
					'bad_key'    => 1,
				],
				'rankingVersion'     => 'contextual-ranking-v1',
			]
		);

		$this->assertSame( 0.9, $result['modelScore'] );
		$this->assertSame( 0.8, $result['deterministicScore'] );
		$this->assertSame( 0.6, $result['contextScore'] );
		$this->assertSame( 0.7, $result['blendedScore'] );
		$this->assertSame( 'contextual-ranking-v1', $result['rankingVersion'] );
		$this->assertSame( 1.0, $result['contextEvidence']['prompt_match'] );
		$this->assertSame( 0.0, $result['contextEvidence']['design_semantics_fit'] );
		$this->assertArrayNotHasKey( 'unsupported_payload', $result['contextEvidence'] );
		$this->assertArrayNotHasKey( 'supports_fit', $result['contextEvidence'] );
		$this->assertSame( [ 'stale_docs' => 0.15 ], $result['contextPenalties'] );
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
