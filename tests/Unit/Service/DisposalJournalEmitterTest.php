<?php

/**
 * Unit tests for the DisposalJournalEmitter (REQ-FA-006).
 *
 * Exercises the closing-journal payload emitted when a `FixedAsset`
 * transitions from `active` to `disposed`:
 *
 *   - disposal at proceeds equal to book value emits a zero-gain
 *     journal (asset & accumulated-dep accounts net to zero,
 *     no P&L hit);
 *   - disposal at proceeds above book value posts a credit gain to
 *     the "boekwinst vaste activa" account;
 *   - disposal at proceeds below book value posts a debit loss to
 *     the "boekverlies vaste activa" account;
 *   - lines always balance (sum of debits == sum of credits, every
 *     amount non-negative, side ∈ {debit, credit}).
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
 * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
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
 * DisposalJournalEmitter unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DisposalJournalEmitterTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var DisposalJournalEmitter
	 */
	private DisposalJournalEmitter $emitter;

	/**
	 * Bootstrap the subject under test before each scenario.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->emitter = new DisposalJournalEmitter(new DepreciationCalculator());

	}//end setUp()

	/**
	 * REQ-FA-006 scenario: disposal at proceeds == book value emits a
	 * zero-gain journal — no P&L hit, asset and accumulated-dep accounts
	 * net to zero, lines balance.
	 *
	 * Linear asset: cost 2400, residual 0, life 48, after 6m → book 2100,
	 * accum 300. Proceeds 2100, gain/loss = 0.
	 *
	 * @return void
	 */
	public function testZeroGainOnProceedsEqualBookValue(): void {
		$asset = $this->linearAsset();
		$disposal = [
			'disposalDate' => '2026-07-01',
			'disposalAccountingTreatment' => 'sale',
			'disposalProceeds' => 2100.0,
		];

		$payload = $this->emitter->emit($asset, $disposal);

		self::assertSame(2100.0, $payload['bookValue']);
		self::assertSame(0.0, $payload['gain']);
		self::assertSame(0.0, $payload['loss']);
		self::assertTrue($this->emitter->linesBalance($payload['lines']));
		// No gain or loss line ships.
		$accounts = array_column($payload['lines'], 'accountNumber');
		self::assertNotContains('8000-fa-gain', $accounts);
		self::assertNotContains('8001-fa-loss', $accounts);

	}//end testZeroGainOnProceedsEqualBookValue()

	/**
	 * REQ-FA-006 scenario: disposal at proceeds above book value posts a
	 * credit to the configured "boekwinst vaste activa" account.
	 *
	 * Linear asset: cost 2400, after 6m → book 2100, accum 300.
	 * Proceeds 2250 → gain 150.
	 *
	 * @return void
	 */
	public function testGainPostedToBoekwinstAccount(): void {
		$asset = $this->linearAsset();
		$disposal = [
			'disposalDate' => '2026-07-01',
			'disposalAccountingTreatment' => 'sale',
			'disposalProceeds' => 2250.0,
		];

		$payload = $this->emitter->emit($asset, $disposal);

		self::assertSame(150.0, $payload['gain']);
		self::assertSame(0.0, $payload['loss']);

		$gainLine = $this->findLine($payload['lines'], '8000-fa-gain');
		self::assertNotNull($gainLine);
		self::assertSame('credit', $gainLine['side']);
		self::assertSame(150.0, $gainLine['amount']);
		self::assertTrue($this->emitter->linesBalance($payload['lines']));

	}//end testGainPostedToBoekwinstAccount()

	/**
	 * REQ-FA-006 scenario: disposal at proceeds below book value posts
	 * a debit to the configured "boekverlies vaste activa" account.
	 *
	 * Linear asset: book 2100. Proceeds 1900 → loss 200.
	 *
	 * @return void
	 */
	public function testLossPostedToBoekverliesAccount(): void {
		$asset = $this->linearAsset();
		$disposal = [
			'disposalDate' => '2026-07-01',
			'disposalAccountingTreatment' => 'sale',
			'disposalProceeds' => 1900.0,
		];

		$payload = $this->emitter->emit($asset, $disposal);

		self::assertSame(0.0, $payload['gain']);
		self::assertSame(200.0, $payload['loss']);

		$lossLine = $this->findLine($payload['lines'], '8001-fa-loss');
		self::assertNotNull($lossLine);
		self::assertSame('debit', $lossLine['side']);
		self::assertSame(200.0, $lossLine['amount']);
		self::assertTrue($this->emitter->linesBalance($payload['lines']));

	}//end testLossPostedToBoekverliesAccount()

	/**
	 * Every emitted line carries side ∈ {debit, credit}, amount >= 0,
	 * and subLedger metadata so the trial-balance reconciliation works.
	 *
	 * @return void
	 */
	public function testLinesCarryRequiredShape(): void {
		$asset = $this->linearAsset();
		$disposal = [
			'disposalDate' => '2026-07-01',
			'disposalAccountingTreatment' => 'sale',
			'disposalProceeds' => 2250.0,
		];

		$payload = $this->emitter->emit($asset, $disposal);

		foreach ($payload['lines'] as $line) {
			self::assertContains($line['side'], ['debit', 'credit']);
			self::assertGreaterThanOrEqual(0.0, $line['amount']);
			self::assertSame('fixed-asset', $line['subLedgerType']);
			self::assertSame('FA-0001', $line['subLedgerRef']);
			self::assertNotEmpty($line['accountNumber']);
		}

	}//end testLinesCarryRequiredShape()

	/**
	 * The disposal header carries the asset reference + treatment
	 * narrative so the audit trail can trace it.
	 *
	 * @return void
	 */
	public function testHeaderCarriesAssetReference(): void {
		$asset = $this->linearAsset();
		$disposal = [
			'disposalDate' => '2026-07-01',
			'disposalAccountingTreatment' => 'scrap',
			'disposalProceeds' => 0.0,
		];

		$payload = $this->emitter->emit($asset, $disposal);

		self::assertSame('disposal', $payload['header']['transactionType']);
		self::assertSame('2026-07-01', $payload['header']['postingDate']);
		self::assertSame('fixed-asset:FA-0001', $payload['header']['sourceReference']);
		self::assertSame('FA-0001', $payload['header']['subLedgerRef']);
		self::assertSame('fixed-asset', $payload['header']['subLedgerType']);
		self::assertStringContainsString('scrap', $payload['header']['description']);

	}//end testHeaderCarriesAssetReference()

	/**
	 * Custom account overrides replace the defaults.
	 *
	 * @return void
	 */
	public function testAccountOverridesReplaceDefaults(): void {
		$asset = $this->linearAsset();
		$disposal = [
			'disposalDate' => '2026-07-01',
			'disposalAccountingTreatment' => 'sale',
			'disposalProceeds' => 2300.0,
		];

		$payload = $this->emitter->emit(
			$asset,
			$disposal,
			[
				'gainAccountNumber' => '7100-realised-gains',
				'lossAccountNumber' => '7101-realised-losses',
				'clearingAccountNumber' => '1110-disposal-suspense',
			]
		);

		$accounts = array_column($payload['lines'], 'accountNumber');
		self::assertContains('7100-realised-gains', $accounts);
		self::assertContains('1110-disposal-suspense', $accounts);
		self::assertNotContains('8000-fa-gain', $accounts);

	}//end testAccountOverridesReplaceDefaults()

	/**
	 * Scrap with zero proceeds still produces a balanced loss-posting
	 * journal (write-off the remaining book value).
	 *
	 * @return void
	 */
	public function testScrapWithZeroProceedsWritesOffRemainingBookValue(): void {
		$asset = $this->linearAsset();
		$disposal = [
			'disposalDate' => '2026-07-01',
			'disposalAccountingTreatment' => 'scrap',
			'disposalProceeds' => 0.0,
		];

		$payload = $this->emitter->emit($asset, $disposal);

		// Book value 2100 → loss 2100; no proceeds line.
		self::assertSame(2100.0, $payload['loss']);
		$accounts = array_column($payload['lines'], 'accountNumber');
		self::assertNotContains('1100-disposal-clearing', $accounts);
		self::assertContains('8001-fa-loss', $accounts);
		self::assertTrue($this->emitter->linesBalance($payload['lines']));

	}//end testScrapWithZeroProceedsWritesOffRemainingBookValue()

	/**
	 * Find a line by accountNumber.
	 *
	 * @param array<int,array<string,mixed>> $lines The journal lines.
	 * @param string $accountNumber The account number to match.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findLine(array $lines, string $accountNumber): ?array {
		foreach ($lines as $line) {
			if (($line['accountNumber'] ?? '') === $accountNumber) {
				return $line;
			}
		}

		return null;
	}//end findLine()

	/**
	 * Reusable linear-asset fixture (spec REQ-FA-003 worked example).
	 *
	 * @return array<string,mixed>
	 */
	private function linearAsset(): array {
		return [
			'assetNumber' => 'FA-0001',
			'name' => 'Dell XPS-15',
			'acquisitionCost' => 2400.0,
			'residualValue' => 0.0,
			'usefulLifeMonths' => 48,
			'depreciationMethod' => 'linear',
			'acquisitionDate' => '2026-01-01',
			'assetAccountNumber' => '0220',
			'accumulatedDepAccountNumber' => '0225',
			'depreciationExpenseAccountNumber' => '4500',
			'currency' => 'EUR',
			'administrationId' => 'adm-1',
		];

	}//end linearAsset()
}//end class
