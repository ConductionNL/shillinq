<?php

/**
 * Unit tests for BankRuleSuggestionService (REQ-BR-012).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bank-rule-automation-ux/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BankRuleSuggestionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A ranker that reverses the deterministic order — used to prove the AI hook
 * is honoured when it works.
 */
final class ReversingRanker {

	/**
	 * @param list<array<string,mixed>> $proposals Proposals to re-rank.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function rank(array $proposals): array {
		return array_reverse($proposals);
	}//end rank()
}//end class

/**
 * A ranker that always throws — used to prove graceful degradation.
 */
final class ThrowingRanker {

	/**
	 * @param list<array<string,mixed>> $proposals Ignored.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function rank(array $proposals): array {
		throw new \RuntimeException('AI provider unavailable');
	}//end rank()
}//end class

/**
 * Proves the learning path SUGGESTS (never auto-applies) after K repeats and
 * degrades gracefully without a working AI provider.
 *
 * @spec openspec/changes/bank-rule-automation-ux/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-012)
 */
final class BankRuleSuggestionServiceTest extends TestCase {

	/**
	 * Subject.
	 *
	 * @var BankRuleSuggestionService
	 */
	private BankRuleSuggestionService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new BankRuleSuggestionService($logger);

	}//end setUp()

	/**
	 * Same counterparty categorised 3× to the same GL (K=3) yields exactly one
	 * proposal; a single categorisation is below K and yields none. Nothing is
	 * persisted — the service has no OpenRegister dependency to write with.
	 *
	 * @return void
	 */
	public function testSuggestsAfterKRepeatsAndNotBelow(): void {
		$history = [
			['counterpartyName' => 'Acme B.V.', 'targetGlAccount' => '4000', 'targetType' => 'gl-transaction'],
			['counterpartyName' => 'Acme B.V.', 'targetGlAccount' => '4000', 'targetType' => 'gl-transaction'],
			['counterpartyName' => 'Acme B.V.', 'targetGlAccount' => '4000', 'targetType' => 'gl-transaction'],
			['counterpartyName' => 'Globex', 'targetGlAccount' => '4500', 'targetType' => 'gl-transaction'],
		];

		$proposals = $this->service->suggestRulesFromHistory(history: $history, k: 3);

		self::assertCount(1, $proposals);
		$p = $proposals[0];
		self::assertSame('4000', $p['targetGlAccount']);
		self::assertSame(3, $p['occurrences']);
		self::assertSame('history', $p['source']);
		self::assertStringContainsString('Acme B.V.', (string)$p['ruleName']);

		// The proposal carries a counterparty-fuzzy predicate (no IBAN in history).
		self::assertSame('counterparty-fuzzy', $p['predicates'][0]['op']);
		self::assertSame('Acme B.V.', $p['predicates'][0]['name']);

	}//end testSuggestsAfterKRepeatsAndNotBelow()

	/**
	 * When history carries an IBAN, the proposal prefers the precise
	 * counterparty-iban predicate.
	 *
	 * @return void
	 */
	public function testPrefersIbanPredicateWhenAvailable(): void {
		$history = array_fill(
			0,
			3,
			[
				'counterpartyName' => 'Acme B.V.',
				'counterpartyIban' => 'NL91ABNA0417164300',
				'targetGlAccount' => '4000',
				'targetType' => 'gl-transaction',
			]
		);

		$proposals = $this->service->suggestRulesFromHistory(history: $history, k: 3);

		self::assertCount(1, $proposals);
		self::assertSame('counterparty-iban', $proposals[0]['predicates'][0]['op']);
		self::assertSame('NL91ABNA0417164300', $proposals[0]['predicates'][0]['iban']);

	}//end testPrefersIbanPredicateWhenAvailable()

	/**
	 * Entries with no GL target (an incomplete categorisation) are ignored.
	 *
	 * @return void
	 */
	public function testIncompleteHistoryIsIgnored(): void {
		$history = [
			['counterpartyName' => 'Acme B.V.', 'targetGlAccount' => '', 'targetType' => 'gl-transaction'],
			['counterpartyName' => '', 'targetGlAccount' => '4000', 'targetType' => 'gl-transaction'],
		];

		self::assertSame([], $this->service->suggestRulesFromHistory(history: $history, k: 1));

	}//end testIncompleteHistoryIsIgnored()

	/**
	 * No AI provider (null): deterministic order — Acme (×4) before Beta (×3).
	 *
	 * @return void
	 */
	public function testDegradesGracefullyWithoutAiProvider(): void {
		$proposals = $this->service->suggestRulesFromHistory(history: $this->twoGroupHistory(), k: 3, aiRanker: null);

		self::assertCount(2, $proposals);
		self::assertStringContainsString('Acme', (string)$proposals[0]['ruleName']);
		self::assertStringContainsString('Beta', (string)$proposals[1]['ruleName']);

	}//end testDegradesGracefullyWithoutAiProvider()

	/**
	 * A ranker that throws must fall back to the deterministic order — the
	 * suggestions are never dropped or errored because AI failed.
	 *
	 * @return void
	 */
	public function testThrowingRankerFallsBackToDeterministicOrder(): void {
		$proposals = $this->service->suggestRulesFromHistory(
			history: $this->twoGroupHistory(),
			k: 3,
			aiRanker: new ThrowingRanker(),
		);

		self::assertCount(2, $proposals);
		self::assertStringContainsString('Acme', (string)$proposals[0]['ruleName']);
		self::assertStringContainsString('Beta', (string)$proposals[1]['ruleName']);

	}//end testThrowingRankerFallsBackToDeterministicOrder()

	/**
	 * A working ranker IS honoured (its ordering is applied) — proving the hook
	 * is real, not a no-op.
	 *
	 * @return void
	 */
	public function testWorkingRankerReordersProposals(): void {
		$proposals = $this->service->suggestRulesFromHistory(
			history: $this->twoGroupHistory(),
			k: 3,
			aiRanker: new ReversingRanker(),
		);

		self::assertCount(2, $proposals);
		// Reversed: Beta first now.
		self::assertStringContainsString('Beta', (string)$proposals[0]['ruleName']);

	}//end testWorkingRankerReordersProposals()

	/**
	 * History yielding two groups: Acme ×4, Beta N.V. ×3.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function twoGroupHistory(): array {
		$acme = array_fill(0, 4, ['counterpartyName' => 'Acme B.V.', 'targetGlAccount' => '4000', 'targetType' => 'gl-transaction']);
		$beta = array_fill(0, 3, ['counterpartyName' => 'Beta N.V.', 'targetGlAccount' => '4500', 'targetType' => 'gl-transaction']);

		return array_merge($acme, $beta);
	}//end twoGroupHistory()
}//end class
