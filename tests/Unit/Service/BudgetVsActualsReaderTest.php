<?php

/**
 * Unit tests for BudgetVsActualsReader.
 *
 * Covers `budget-core-schema` task group 8 (REQ-BCS-008): the GL-derived
 * actuals index (dual-keyed transaction references, signed EUR-cents
 * bucketing), LedgerGroup membership resolution (ranges + explicit
 * include/exclude), and the ≤5-`findAll()`-call query budget regardless of
 * scope.
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
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Tests\Unit\Service\Support\CallCountingObjectServiceDecorator;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the reader's index-building and query-budget behaviour.
 */
final class BudgetVsActualsReaderTest extends TestCase {

	/**
	 * Build the reader over a seeded fixture store, returning both the
	 * reader and the call-counting decorator so tests can assert on
	 * `$decorator->findAllCalls`.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return array{0: BudgetVsActualsReader, 1: CallCountingObjectServiceDecorator}
	 */
	private function buildReader(array $data): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$decorator = new CallCountingObjectServiceDecorator(new FilteredObjectServiceStub($data));

		$reader = new BudgetVsActualsReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
		);

		return [$reader, $decorator];
	}//end buildReader()

	/**
	 * A fixture: one administration, one Account (accountNumber 1000), one
	 * posted GLTransaction in Jan 2027 with one GLLine debiting 80.00 EUR to
	 * account 1000.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function fixture(): array {
		return [
			'Account' => [
				['accountNumber' => '1000', 'administrationId' => 'adm-1'],
			],
			'GLTransaction' => [
				[
					'id' => 'tx-1',
					'transactionNumber' => 'GL-2027-0001',
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => '2027-01-15',
				],
			],
			'GLLine' => [
				[
					'transactionId' => 'tx-1',
					'accountNumber' => '1000',
					'side' => 'debit',
					'amount' => 80.00,
				],
			],
			'LedgerGroup' => [
				[
					'id' => 'lg-1',
					'@self' => ['slug' => 'ledger-group-neto'],
					'administrationId' => 'adm-1',
					'code' => 'NETO',
					'name' => 'Netto-omzet',
					'order' => 10,
					'parentLedgerGroupId' => null,
					'accountRanges' => [['from' => '1000', 'to' => '1099']],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
			],
			'BudgetLine' => [
				[
					'id' => 'bl-1',
					'annualBudgetId' => 'ab-1',
					'ledgerGroupId' => 'lg-1',
					'source' => 'manual',
					'month01Amount' => 100000,
				],
			],
		];
	}//end fixture()

	/**
	 * GL activity for account 1000 in January 2027 is bucketed as 8000 cents
	 * (80.00 EUR), keyed by month `2027-01`.
	 *
	 * @return void
	 */
	public function testActualsAreBucketedByAccountAndMonthInCents(): void {
		[$reader] = $this->buildReader($this->fixture());

		$context = $reader->loadContext('adm-1', ['ab-1']);

		$this->assertSame(8000, $context['actualsByAccountMonth']['1000']['2027-01']);

	}//end testActualsAreBucketedByAccountAndMonthInCents()

	/**
	 * A GL line whose transaction is keyed by `transactionNumber` (not the
	 * object id) still resolves — the dual-key precedent
	 * (`BbvProgrammeBudgetReader::transactionRefs()`).
	 *
	 * @return void
	 */
	public function testLineReferencingTransactionByNumberStillResolves(): void {
		$data = $this->fixture();
		$data['GLLine'][0]['transactionId'] = 'GL-2027-0001';

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		$this->assertSame(8000, $context['actualsByAccountMonth']['1000']['2027-01']);

	}//end testLineReferencingTransactionByNumberStillResolves()

	/**
	 * A credit line reduces the bucket rather than adding to it.
	 *
	 * @return void
	 */
	public function testCreditLineIsSubtracted(): void {
		$data = $this->fixture();
		$data['GLLine'][] = [
			'transactionId' => 'tx-1',
			'accountNumber' => '1000',
			'side' => 'credit',
			'amount' => 20.00,
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		// 80.00 debit - 20.00 credit = 60.00 EUR = 6000 cents.
		$this->assertSame(6000, $context['actualsByAccountMonth']['1000']['2027-01']);

	}//end testCreditLineIsSubtracted()

	/**
	 * A GL line whose transaction is not posted (absent from the posted
	 * index) is skipped, not counted as zero-month activity.
	 *
	 * @return void
	 */
	public function testLineOnUnpostedTransactionIsSkipped(): void {
		$data = $this->fixture();
		$data['GLTransaction'][0]['state'] = 'draft';

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		$this->assertArrayNotHasKey('1000', $context['actualsByAccountMonth']);

	}//end testLineOnUnpostedTransactionIsSkipped()

	/**
	 * A range-based LedgerGroup resolves its members correctly, honouring an
	 * explicit exclude (REQ-BCS-004 scenario).
	 *
	 * @return void
	 */
	public function testLedgerGroupResolvesRangeMembersHonouringExclude(): void {
		$data = [
			'Account' => [
				['accountNumber' => '1000', 'administrationId' => 'adm-1'],
				['accountNumber' => '1050', 'administrationId' => 'adm-1'],
				['accountNumber' => '1099', 'administrationId' => 'adm-1'],
				['accountNumber' => '2000', 'administrationId' => 'adm-1'],
			],
			'GLTransaction' => [],
			'GLLine' => [],
			'LedgerGroup' => [
				[
					'id' => 'lg-1',
					'@self' => ['slug' => 'lg-slug-1'],
					'administrationId' => 'adm-1',
					'parentLedgerGroupId' => null,
					'accountRanges' => [['from' => '1000', 'to' => '1099']],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => ['1050'],
				],
			],
			'BudgetLine' => [],
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		$members = $context['ledgerGroupEntries'][0]['memberAccountNumbers'];
		sort($members);
		$this->assertSame(['1000', '1099'], $members);

	}//end testLedgerGroupResolvesRangeMembersHonouringExclude()

	/**
	 * A nested LedgerGroup's parent is resolved by slug — the
	 * `parentLedgerGroupId` seed convention (design.md §3c) — and appears
	 * among the parent's children in `ledgerGroupChildrenByIndex`.
	 *
	 * @return void
	 */
	public function testNestedLedgerGroupResolvesUnderItsParentBySlug(): void {
		$data = [
			'Account' => [],
			'GLTransaction' => [],
			'GLLine' => [],
			'LedgerGroup' => [
				[
					'id' => 'lg-parent',
					'@self' => ['slug' => 'ledger-group-omzet'],
					'administrationId' => 'adm-1',
					'parentLedgerGroupId' => null,
					'accountRanges' => [],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
				[
					'id' => 'lg-child',
					'@self' => ['slug' => 'ledger-group-neto'],
					'administrationId' => 'adm-1',
					'parentLedgerGroupId' => 'ledger-group-omzet',
					'accountRanges' => [],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
			],
			'BudgetLine' => [],
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		$parentIndex = $context['ledgerGroupKeyToIndex']['ledger-group-omzet'];
		$childIndex = $context['ledgerGroupKeyToIndex']['ledger-group-neto'];
		$this->assertSame([$childIndex], $context['ledgerGroupChildrenByIndex'][$parentIndex]);

	}//end testNestedLedgerGroupResolvesUnderItsParentBySlug()

	/**
	 * BudgetLines are loaded via the `{annualBudgetId: {in: [...]}}` filter
	 * (`SpendAnalyticsService.php:183` precedent) — a fixture with two
	 * AnnualBudgets returns only the requested one's lines.
	 *
	 * @return void
	 */
	public function testBudgetLinesAreLoadedByInFilter(): void {
		$data = $this->fixture();
		$data['BudgetLine'][] = [
			'id' => 'bl-2',
			'annualBudgetId' => 'ab-OTHER',
			'ledgerGroupId' => 'lg-1',
			'source' => 'manual',
			'month01Amount' => 999900,
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', ['ab-1']);

		$this->assertCount(1, $context['budgetLines']);
		$this->assertSame('bl-1', $context['budgetLines'][0]['id']);

	}//end testBudgetLinesAreLoadedByInFilter()

	/**
	 * The full read (LedgerGroups + BudgetLines requested) issues at most 5
	 * `findAll()` calls, independent of the number of accounts, GL lines,
	 * LedgerGroups or BudgetLines in the fixture (REQ-BCS-008).
	 *
	 * @return void
	 */
	public function testFullReadStaysWithinFiveFindAllCalls(): void {
		[$reader, $decorator] = $this->buildReader($this->fixture());

		$reader->loadContext('adm-1', ['ab-1'], true);

		$this->assertLessThanOrEqual(5, $decorator->findAllCalls);
		$this->assertSame(5, $decorator->findAllCalls);

	}//end testFullReadStaysWithinFiveFindAllCalls()

	/**
	 * Skipping LedgerGroups (`$includeLedgerGroups = false`) drops the query
	 * budget to at most 4 calls.
	 *
	 * @return void
	 */
	public function testSkippingLedgerGroupsStaysWithinFourFindAllCalls(): void {
		[$reader, $decorator] = $this->buildReader($this->fixture());

		$reader->loadContext('adm-1', ['ab-1'], false);

		$this->assertSame(4, $decorator->findAllCalls);

	}//end testSkippingLedgerGroupsStaysWithinFourFindAllCalls()

	/**
	 * Doubling the number of accounts, GL lines and LedgerGroups in the
	 * fixture does not add any further `findAll()` calls — the bound is on
	 * CALL COUNT, not data volume.
	 *
	 * @return void
	 */
	public function testCallCountDoesNotScaleWithDataVolume(): void {
		$data = $this->fixture();
		for ($i = 0; $i < 20; $i++) {
			$data['Account'][] = ['accountNumber' => (string)(2000 + $i), 'administrationId' => 'adm-1'];
			$data['GLTransaction'][] = [
				'id' => 'tx-extra-' . $i,
				'transactionNumber' => 'GL-EXTRA-' . $i,
				'administrationId' => 'adm-1',
				'state' => 'posted',
				'postingDate' => '2027-02-01',
			];
			$data['GLLine'][] = [
				'transactionId' => 'tx-extra-' . $i,
				'accountNumber' => (string)(2000 + $i),
				'side' => 'debit',
				'amount' => 10.00,
			];
		}

		[$reader, $decorator] = $this->buildReader($data);
		$reader->loadContext('adm-1', ['ab-1'], true);

		$this->assertSame(5, $decorator->findAllCalls);

	}//end testCallCountDoesNotScaleWithDataVolume()
}//end class
