<?php

/**
 * Characterisation tests for BankRuleSuggestionService grouping, proposals and ranking.
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
use RuntimeException;

/**
 * Pins the grouping key, the IBAN carry-over, the threshold floor, the
 * proposal shape, the ordering and every ranker fallback.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BankRuleSuggestionGroupingTest extends TestCase {

	/**
	 * A categorisation history row.
	 *
	 * @param string $name The counterparty name.
	 * @param string $iban The counterparty IBAN.
	 * @param string $gl   The GL account.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function row(string $name, string $iban, string $gl): array {
		return ['counterpartyName' => $name, 'counterpartyIban' => $iban, 'targetGlAccount' => $gl];

	}//end row()

	/**
	 * Grouping, carry-over, shapes and order on a mixed history.
	 *
	 * @return void
	 */
	public function testProposalsFromMixedHistory(): void {
		$history = [
			'junk',
			self::row('  Acme  ', '', '4000'),
			self::row('Acme', ' NL01BANK0001 ', '4000'),
			self::row('Acme', '', ' 4000 '),
			self::row('', 'NL02BANK0002', '4100') + ['targetType' => 'payable'],
			self::row('', 'NL02BANK0002', '4100'),
			self::row('Beta', '', '4200'),
			self::row('Beta', '', '4200'),
			self::row('Alpha', '', '4300'),
			self::row('Alpha', '', '4300'),
			self::row('Nobody', '', ''),
			self::row('', '', '4400'),
			self::row('Acme', '', '4500'),
		];
		for ($i = 0; $i < 12; $i++) {
			$history[] = self::row('Zeta', '', '4600');
		}

		$proposals = (new BankRuleSuggestionService($this->createMock(LoggerInterface::class)))->suggestRulesFromHistory($history, 2);

		self::assertSame(
			[
				[
					'ruleName' => 'Zeta → 4600',
					'predicates' => [['op' => 'counterparty-fuzzy', 'name' => 'Zeta', 'threshold' => 0.9]],
					'targetType' => 'gl-transaction',
					'targetGlAccount' => '4600',
					'occurrences' => 12,
					'confidence' => 0.95,
					'source' => 'history',
				],
				[
					'ruleName' => 'Acme → 4000',
					'predicates' => [['op' => 'counterparty-iban', 'iban' => 'NL01BANK0001']],
					'targetType' => 'gl-transaction',
					'targetGlAccount' => '4000',
					'occurrences' => 3,
					'confidence' => 0.75,
					'source' => 'history',
				],
				[
					'ruleName' => 'Alpha → 4300',
					'predicates' => [['op' => 'counterparty-fuzzy', 'name' => 'Alpha', 'threshold' => 0.9]],
					'targetType' => 'gl-transaction',
					'targetGlAccount' => '4300',
					'occurrences' => 2,
					'confidence' => 0.7,
					'source' => 'history',
				],
				[
					'ruleName' => 'Beta → 4200',
					'predicates' => [['op' => 'counterparty-fuzzy', 'name' => 'Beta', 'threshold' => 0.9]],
					'targetType' => 'gl-transaction',
					'targetGlAccount' => '4200',
					'occurrences' => 2,
					'confidence' => 0.7,
					'source' => 'history',
				],
				[
					'ruleName' => 'NL02BANK0002 → 4100',
					'predicates' => [['op' => 'counterparty-iban', 'iban' => 'NL02BANK0002']],
					'targetType' => 'payable',
					'targetGlAccount' => '4100',
					'occurrences' => 2,
					'confidence' => 0.7,
					'source' => 'history',
				],
			],
			$proposals
		);

	}//end testProposalsFromMixedHistory()

	/**
	 * A threshold below one counts as one.
	 *
	 * @return void
	 */
	public function testThresholdFloorIsOne(): void {
		$service = new BankRuleSuggestionService($this->createMock(LoggerInterface::class));

		self::assertCount(1, $service->suggestRulesFromHistory([self::row('Solo', '', '4000')], 0));
		self::assertCount(1, $service->suggestRulesFromHistory([self::row('Solo', '', '4000')], -5));
		self::assertCount(0, $service->suggestRulesFromHistory([self::row('Solo', '', '4000')]));

	}//end testThresholdFloorIsOne()

	/**
	 * Every ranker outcome: no rank method, throwing, malformed, wrong count,
	 * bad item, and a valid re-order.
	 *
	 * @return void
	 */
	public function testRankerOutcomes(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$service = new BankRuleSuggestionService($logger);
		$history = [self::row('A', '', '1'), self::row('B', '', '2')];
		$plain = $service->suggestRulesFromHistory($history, 1);

		$rankers = [
			'none'       => new \stdClass(),
			'throws'     => new class {
				public function rank(array $p): array {
					throw new RuntimeException('model down');
				}
			},
			'not array'  => new class {
				public function rank(array $p): string {
					return 'nope';
				}
			},
			'wrong size' => new class {
				public function rank(array $p): array {
					return [$p[0]];
				}
			},
			'bad item'   => new class {
				public function rank(array $p): array {
					return [$p[1], ['ruleName' => 'x']];
				}
			},
		];
		foreach ($rankers as $label => $ranker) {
			self::assertSame($plain, $service->suggestRulesFromHistory($history, 1, $ranker), $label);
		}

		self::assertSame(
			[
				['BankRuleSuggestionService: AI ranker failed; using deterministic order', ['exception' => 'model down']],
				['BankRuleSuggestionService: AI ranker returned a malformed result; using deterministic order', []],
				['BankRuleSuggestionService: AI ranker returned a malformed result; using deterministic order', []],
			],
			$logged
		);

		$reverse = new class {
			public function rank(array $p): array {
				return array_reverse($p);
			}
		};
		self::assertSame(array_reverse($plain), $service->suggestRulesFromHistory($history, 1, $reverse));

	}//end testRankerOutcomes()
}//end class
