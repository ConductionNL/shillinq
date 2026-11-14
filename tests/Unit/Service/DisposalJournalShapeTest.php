<?php

/**
 * Characterisation tests for DisposalJournalEmitter::emit().
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
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DepreciationCalculator;
use OCA\Shillinq\Service\DisposalJournalEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the complete journal (header, every line in order, book value, gain
 * and loss) for a sale at a gain with an authoritative book value and a scrap
 * at a loss.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class DisposalJournalShapeTest extends TestCase {

	/**
	 * The asset under test.
	 *
	 * @return array<string,mixed>
	 */
	private function asset(): array {
		return [
			'assetNumber' => 'FA-7',
			'acquisitionCost' => 1000.0,
			'assetAccountNumber' => '0200',
			'accumulatedDepAccountNumber' => '0209',
			'currency' => 'EUR',
			'administrationId' => 'adm-1',
		];

	}//end asset()

	/**
	 * One expected journal line of asset FA-7.
	 *
	 * @param string $account     The account.
	 * @param string $side        debit or credit.
	 * @param float  $amount      The amount.
	 * @param string $description The description.
	 *
	 * @return array<string,mixed>
	 */
	private function line(string $account, string $side, float $amount, string $description): array {
		return [
			'accountNumber' => $account,
			'side' => $side,
			'amount' => $amount,
			'subLedgerType' => 'fixed-asset',
			'subLedgerRef' => 'FA-7',
			'description' => $description,
		];

	}//end line()

	/**
	 * A sale above an authoritative book value: gross credit, accumulated
	 * depreciation debit, proceeds debit, gain credit.
	 *
	 * @return void
	 */
	public function testSaleAtAGain(): void {
		$journal = (new DisposalJournalEmitter(new DepreciationCalculator()))->emit(
			$this->asset(),
			['disposalDate' => '2026-06-30', 'disposalProceeds' => 700.0],
			['gainAccountNumber' => '8100'],
			400.0
		);

		self::assertSame(
			[
				'header' => [
					'transactionType' => 'disposal',
					'postingDate' => '2026-06-30',
					'description' => 'Fixed asset disposal FA-7 (sale)',
					'sourceReference' => 'fixed-asset:FA-7',
					'subLedgerType' => 'fixed-asset',
					'subLedgerRef' => 'FA-7',
					'currency' => 'EUR',
					'administrationId' => 'adm-1',
				],
				'lines' => [
					$this->line('0200', 'credit', 1000.0, 'Disposal: reverse gross asset value'),
					$this->line('0209', 'debit', 600.0, 'Disposal: reverse accumulated depreciation'),
					$this->line('1100-disposal-clearing', 'debit', 700.0, 'Disposal: receive proceeds (sale)'),
					$this->line('8100', 'credit', 300.0, 'Disposal: boekwinst vaste activa'),
				],
				'bookValue' => 400.0,
				'gain' => 300.0,
				'loss' => 0.0,
			],
			$journal
		);

	}//end testSaleAtAGain()

	/**
	 * A scrap without proceeds at the recomputed book value posts a loss and
	 * no proceeds line.
	 *
	 * @return void
	 */
	public function testScrapAtALoss(): void {
		$calculator = new DepreciationCalculator();
		$disposal = ['disposalDate' => '2026-06-30', 'disposalAccountingTreatment' => 'scrap'];
		$expectedBookValue = $calculator->currentBookValue(asset: $this->asset(), referenceDate: '2026-06-30');

		$journal = (new DisposalJournalEmitter($calculator))->emit($this->asset(), $disposal);

		self::assertSame($expectedBookValue, $journal['bookValue']);
		$sides = array_map(static fn (array $line): string => $line['accountNumber'] . ':' . $line['side'], $journal['lines']);
		$withoutAccumulated = array_values(array_filter($sides, static fn (string $s): bool => $s !== '0209:debit'));
		self::assertSame(['0200:credit', '8001-fa-loss:debit'], $withoutAccumulated);
		self::assertSame('Fixed asset disposal FA-7 (scrap)', $journal['header']['description']);
		self::assertSame(0.0, $journal['gain']);

	}//end testScrapAtALoss()
}//end class
