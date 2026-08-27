<?php

/**
 * Unit tests for BudgetProjectionReader.
 *
 * Covers `budget-projection-engine` task group 4 (REQ-BPE-003, REQ-BPE-009):
 * the dual-keyed transaction join, `postingDate`-based (never `periodId`)
 * month bucketing, the per-account window-shortening rule, and the
 * ≤4-`findAll()`-call query budget regardless of scope — proven with the
 * same {@see CallCountingObjectServiceDecorator} the sibling
 * `BudgetVsActualsReaderTest` uses (measured, not asserted).
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
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetProjectionReader;
use OCA\Shillinq\Tests\Unit\Service\Support\CallCountingObjectServiceDecorator;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the reader's month-bucketing, window-resolution and query-budget behaviour.
 */
final class BudgetProjectionReaderTest extends TestCase {

	/**
	 * Build the reader over a seeded fixture store, returning both the
	 * reader and the call-counting decorator so tests can assert on
	 * `$decorator->findAllCalls`.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return array{0: BudgetProjectionReader, 1: CallCountingObjectServiceDecorator}
	 */
	private function buildReader(array $data): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$decorator = new CallCountingObjectServiceDecorator(new FilteredObjectServiceStub($data));

		$reader = new BudgetProjectionReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
		);

		return [$reader, $decorator];

	}//end buildReader()

	/**
	 * One administration, one `revenue` account (1000) with 4 months of
	 * posted GL activity (Jan-Apr 2027), one `LedgerGroup` covering it.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function fixture(): array {
		return [
			'Account' => [
				['accountNumber' => '1000', 'administrationId' => 'adm-1', 'accountType' => 'revenue'],
			],
			'GLTransaction' => [
				[
					'id' => 'tx-1',
					'transactionNumber' => 'GL-2027-0001',
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => '2027-01-15',
				],
				[
					'id' => 'tx-2',
					'transactionNumber' => 'GL-2027-0002',
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => '2027-02-10',
				],
				[
					'id' => 'tx-3',
					'transactionNumber' => 'GL-2027-0003',
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => '2027-03-05',
				],
				[
					'id' => 'tx-4',
					'transactionNumber' => 'GL-2027-0004',
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => '2027-04-20',
				],
			],
			'GLLine' => [
				['transactionId' => 'tx-1', 'accountNumber' => '1000', 'side' => 'credit', 'amount' => 100.00],
				['transactionId' => 'tx-2', 'accountNumber' => '1000', 'side' => 'credit', 'amount' => 100.00],
				['transactionId' => 'tx-3', 'accountNumber' => '1000', 'side' => 'credit', 'amount' => 100.00],
				['transactionId' => 'tx-4', 'accountNumber' => '1000', 'side' => 'credit', 'amount' => 100.00],
			],
			'LedgerGroup' => [
				[
					'id' => 'lg-1',
					'@self' => ['slug' => 'ledger-group-omzet'],
					'administrationId' => 'adm-1',
					'parentLedgerGroupId' => null,
					'accountRanges' => [['from' => '1000', 'to' => '1099']],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
			],
		];

	}//end fixture()

	/**
	 * The full read (LedgerGroups requested) issues at most 4 `findAll()`
	 * calls, and exactly 4 for this fixture (REQ-BPE-009).
	 *
	 * @return void
	 */
	public function testQueryCountIndependentOfAccountAndMonthCount(): void {
		[$reader, $decorator] = $this->buildReader($this->fixture());

		$reader->loadContext('adm-1', true);

		$this->assertLessThanOrEqual(4, $decorator->findAllCalls);
		$this->assertSame(4, $decorator->findAllCalls);

	}//end testQueryCountIndependentOfAccountAndMonthCount()

	/**
	 * Skipping LedgerGroups drops the query budget to 3 calls.
	 *
	 * @return void
	 */
	public function testSkippingLedgerGroupsStaysWithinThreeFindAllCalls(): void {
		[$reader, $decorator] = $this->buildReader($this->fixture());

		$reader->loadContext('adm-1', false);

		$this->assertSame(3, $decorator->findAllCalls);

	}//end testSkippingLedgerGroupsStaysWithinThreeFindAllCalls()

	/**
	 * Doubling the number of accounts, transactions and GL lines in the
	 * fixture (50 accounts across 12 months, plus one LedgerGroup) does not
	 * add any further `findAll()` calls — the bound is on CALL COUNT, not
	 * data volume or month count (REQ-BPE-009's own scenario).
	 *
	 * @return void
	 */
	public function testCallCountDoesNotScaleWithAccountOrMonthCount(): void {
		$data = [
			'Account' => [],
			'GLTransaction' => [],
			'GLLine' => [],
			'LedgerGroup' => [
				[
					'id' => 'lg-1',
					'@self' => ['slug' => 'ledger-group-omzet'],
					'administrationId' => 'adm-1',
					'parentLedgerGroupId' => null,
					'accountRanges' => [['from' => '1000', 'to' => '9999']],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
			],
		];

		for ($a = 0; $a < 50; $a++) {
			$accountNumber = (string)(1000 + $a);
			$data['Account'][] = ['accountNumber' => $accountNumber, 'administrationId' => 'adm-1', 'accountType' => 'expenses'];
			for ($m = 1; $m <= 12; $m++) {
				$txId = 'tx-' . $a . '-' . $m;
				$data['GLTransaction'][] = [
					'id' => $txId,
					'transactionNumber' => 'GL-' . $a . '-' . $m,
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => sprintf('2027-%02d-01', $m),
				];
				$data['GLLine'][] = ['transactionId' => $txId, 'accountNumber' => $accountNumber, 'side' => 'debit', 'amount' => 10.00];
			}
		}

		[$reader, $decorator] = $this->buildReader($data);
		$reader->loadContext('adm-1', true);

		$this->assertSame(4, $decorator->findAllCalls);

	}//end testCallCountDoesNotScaleWithAccountOrMonthCount()

	/**
	 * A newly opened account has a shorter-than-12 window, not a padded
	 * one: 4 months of real GL activity yields exactly 4 window values
	 * (REQ-BPE-003).
	 *
	 * @return void
	 */
	public function testWindowShortensToEarliestPostedTransaction(): void {
		[$reader] = $this->buildReader($this->fixture());

		$context = $reader->loadContext('adm-1', false);

		$window = $context['windowByAccount']['1000'];
		$this->assertSame(['2027-01', '2027-02', '2027-03', '2027-04'], $window['months']);
		$this->assertCount(4, $window['values']);
		$this->assertSame('2027-04', $context['lastActualMonthByAccount']['1000']);

	}//end testWindowShortensToEarliestPostedTransaction()

	/**
	 * Monthly bucketing uses `postingDate`, not `GLLine.periodId` — a
	 * transaction posted 2026-03-15 with a QUARTERLY `periodId` on its line
	 * is still bucketed under `2026-03` (REQ-BPE-003).
	 *
	 * @return void
	 */
	public function testBucketsByPostingDateNotPeriodId(): void {
		$data = [
			'Account' => [
				['accountNumber' => '2000', 'administrationId' => 'adm-1', 'accountType' => 'expenses'],
			],
			'GLTransaction' => [
				[
					'id' => 'tx-q1',
					'transactionNumber' => 'GL-Q1',
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => '2026-03-15',
				],
			],
			'GLLine' => [
				[
					'transactionId' => 'tx-q1',
					'accountNumber' => '2000',
					'side' => 'debit',
					'amount' => 50.00,
					'periodId' => '2026-Q1',
				],
			],
			'LedgerGroup' => [],
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', false);

		$window = $context['windowByAccount']['2000'];
		$this->assertSame(['2026-03'], $window['months']);
		$this->assertSame([5000], $window['values']);

	}//end testBucketsByPostingDateNotPeriodId()

	/**
	 * A GL line whose `transactionId` matches only the transaction's
	 * `transactionNumber` (not its object id) is still joined — the
	 * dual-keyed precedent (`BbvProgrammeBudgetReader::transactionRefs()`).
	 *
	 * @return void
	 */
	public function testDualKeyedTransactionJoin(): void {
		$data = $this->fixture();
		foreach ($data['GLLine'] as $index => $line) {
			// Rewrite every line to reference the transaction by its
			// human NUMBER instead of its object id.
			$txNumber = 'GL-2027-000' . ($index + 1);
			$data['GLLine'][$index]['transactionId'] = $txNumber;
		}

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', false);

		$window = $context['windowByAccount']['1000'];
		$this->assertSame(['2027-01', '2027-02', '2027-03', '2027-04'], $window['months']);
		// The fixture's lines are `credit` (a revenue account's normal
		// side, {@see testCreditLinesAreBucketedNegative()}) — negative
		// net-movement cents, unrelated to the dual-keying under test here.
		$this->assertSame([-10000, -10000, -10000, -10000], $window['values']);

	}//end testDualKeyedTransactionJoin()

	/**
	 * A credit line's amount is bucketed as a NEGATIVE net-movement cent
	 * value, so a `revenue` account's stream of credits reads as negative
	 * netMovement — the same signed convention `TrialBalanceLine` exposes
	 * (`design.md` §1), carried straight through without a per-type flip.
	 *
	 * @return void
	 */
	public function testCreditLinesAreBucketedNegative(): void {
		[$reader] = $this->buildReader($this->fixture());

		$context = $reader->loadContext('adm-1', false);

		$this->assertSame([-10000, -10000, -10000, -10000], $context['windowByAccount']['1000']['values']);

	}//end testCreditLinesAreBucketedNegative()

	/**
	 * A `LedgerGroup` requested with `includeLedgerGroups: true` resolves
	 * its range-based membership against the loaded accounts.
	 *
	 * @return void
	 */
	public function testLedgerGroupResolvesRangeMembers(): void {
		[$reader] = $this->buildReader($this->fixture());

		$context = $reader->loadContext('adm-1', true);

		$index = $context['ledgerGroupKeyToIndex']['ledger-group-omzet'];
		$this->assertSame(['1000'], $context['ledgerGroupEntries'][$index]['memberAccountNumbers']);

	}//end testLedgerGroupResolvesRangeMembers()
}//end class
