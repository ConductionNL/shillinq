<?php

/**
 * Unit tests for DualGaapDerivationService.
 *
 * REQ-DGAAP-011: posting a divergent transaction derives the entry under the
 * other framework from its adjustment rule and records the difference on the
 * period's reconciliation bridge. OpenRegister is doubled at the
 * object-service boundary, recording every save.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\DualGaapDerivationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The scenario: a receivables provision of 4,000 under Dutch GAAP, an IFRS 9 rule giving 5,200.
 */
class DualGaapDerivationServiceTest extends TestCase {

	/**
	 * Saved objects, in order, as [schema, data].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Stored rows by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The service over an in-memory object service.
	 *
	 * @param bool                 $failSave Whether every save throws.
	 * @param LoggerInterface|null $logger   A logger double.
	 *
	 * @return DualGaapDerivationService
	 */
	private function service(bool $failSave = false, ?LoggerInterface $logger = null): DualGaapDerivationService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $value) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$value;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $query) use (&$schema): array {
				$filters = array_map('strval', $query['filters']);
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => array_intersect_assoc($filters, array_map(static fn ($v) => is_scalar($v) ? (string)$v : '', $row)) === $filters
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$schema, $failSave): ObjectEntityInterface {
				if ($failSave === true) {
					throw new \RuntimeException('store down');
				}

				$object['id'] = ($object['id'] ?? ($schema . '-' . count($this->saved)));
				$this->saved[] = [$schema, $object];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		return new DualGaapDerivationService($appConfig, ($logger ?? $this->createMock(LoggerInterface::class)), $objects);
	}//end service()

	/**
	 * The posted provision.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function transaction(array $overrides = []): array {
		return array_merge(['id' => 'tx-1', 'state' => 'posted', 'sourceReference' => 'PROV-DEB-2026', 'periodId' => '2026-12', 'postingDate' => '2026-12-31', 'description' => 'Voorziening debiteuren', 'administrationId' => 'adm-1'], $overrides);
	}//end transaction()

	/**
	 * Store the provision's lines and an IFRS 9 rule.
	 *
	 * @param array<string, mixed> $outputs The rule's outputs.
	 *
	 * @return void
	 */
	private function givenTheProvision(array $outputs = ['amount' => 5200]): void {
		$this->rows['GLLine'] = [
			['id' => 'l1', 'transactionId' => 'tx-1', 'accountNumber' => '4950', 'side' => 'debit', 'amount' => 4000],
			['id' => 'l2', 'transactionId' => 'tx-1', 'accountNumber' => '1310', 'side' => 'credit', 'amount' => 4000],
		];
		$this->rows['StandardSpecificCalculation'] = [
			['id' => 'calc-ecl', 'standardCode' => 'IFRS-9', 'contractOrPositionReference' => 'PROV-DEB-2026', 'calculationMethod' => 'expected_credit_loss_stages', 'outputs' => $outputs, 'administrationId' => 'adm-1'],
		];
	}//end givenTheProvision()

	/**
	 * The saved objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedOf(string $schema): array {
		return array_values(array_map(static fn (array $save): array => $save[1], array_filter($this->saved, static fn (array $save): bool => $save[0] === $schema)));
	}//end savedOf()

	/**
	 * The scenario: the IFRS entry of 5,200 is derived and the bridge shows 1,200.
	 *
	 * @return void
	 */
	public function testAnExpectedCreditLossDerivesTheIfrsEntryAndTheBridgeDifference(): void {
		$this->givenTheProvision();

		$result = $this->service()->deriveForTransaction($this->transaction());

		$this->assertTrue($result['derived']);
		$this->assertSame(120000, $result['differenceCents']);
		$dual = $this->savedOf('DualTransaction');
		$this->assertCount(1, $dual);
		$this->assertSame('tx-1', $dual[0]['baseTransactionId']);
		$this->assertSame([['account' => '4950', 'debit' => 4000.0, 'credit' => 0.0], ['account' => '1310', 'debit' => 0.0, 'credit' => 4000.0]], $dual[0]['rjJournalEntries']);
		$this->assertSame([['account' => '4950', 'debit' => 5200.0, 'credit' => 0.0], ['account' => '1310', 'debit' => 0.0, 'credit' => 5200.0]], $dual[0]['ifrsJournalEntries']);
		$this->assertSame(1200.0, $dual[0]['divergenceAmount']);
		$this->assertSame('ECL_IFRS9', $dual[0]['divergenceReasonCode']);
		$this->assertSame('temporary', $dual[0]['divergenceClassification']);
		$this->assertSame('open', $dual[0]['state']);

		$bridge = $this->savedOf('ReconciliationBridge');
		$this->assertCount(1, $bridge);
		$this->assertSame('2026', $bridge[0]['period']);
		$this->assertSame('NL-GAAP-RJ', $bridge[0]['fromFramework']);
		$this->assertSame('IFRS-EU', $bridge[0]['toFramework']);
		$this->assertSame('adm-1', $bridge[0]['administrationId']);
		$this->assertCount(1, $bridge[0]['adjustments']);
		$this->assertSame(1200.0, $bridge[0]['adjustments'][0]['amount']);
		$this->assertSame('IFRS-9', $bridge[0]['adjustments'][0]['standardReference']);
		$this->assertSame('calc-ecl', $bridge[0]['adjustments'][0]['standardCalculationId']);
		$this->assertSame($dual[0]['id'], $bridge[0]['adjustments'][0]['dualTransactionId']);
		$this->assertSame(1200.0, $bridge[0]['totalTemporaryDifferences']);
		$this->assertSame(0.0, $bridge[0]['totalPermanentDifferences']);
	}//end testAnExpectedCreditLossDerivesTheIfrsEntryAndTheBridgeDifference()

	/**
	 * An existing bridge for the period gets the line added, its totals recomputed.
	 *
	 * @return void
	 */
	public function testTheLineIsAddedToThePeriodsExistingBridge(): void {
		$this->givenTheProvision(outputs: ['amount' => 5200, 'classification' => 'permanent']);
		$this->rows['ReconciliationBridge'] = [
			['id' => 'br-2026', 'period' => '2026', 'fromFramework' => 'NL-GAAP-RJ', 'toFramework' => 'IFRS-EU', 'metric' => 'net_result', 'administrationId' => 'adm-1', 'adjustments' => [['amount' => 300, 'classification' => 'temporary'], ['amount' => 50, 'classification' => 'permanent']]],
			['id' => 'br-other', 'period' => '2026', 'fromFramework' => 'NL-GAAP-RJ', 'toFramework' => 'IFRS-EU', 'metric' => 'net_result', 'administrationId' => 'adm-2', 'adjustments' => []],
		];

		$this->service()->deriveForTransaction($this->transaction());

		$bridge = $this->savedOf('ReconciliationBridge');
		$this->assertCount(1, $bridge);
		$this->assertSame('br-2026', $bridge[0]['id']);
		$this->assertCount(3, $bridge[0]['adjustments']);
		$this->assertSame(300.0, $bridge[0]['totalTemporaryDifferences']);
		$this->assertSame(1250.0, $bridge[0]['totalPermanentDifferences']);
		$this->assertSame('permanent', $this->savedOf('DualTransaction')[0]['divergenceClassification']);
	}//end testTheLineIsAddedToThePeriodsExistingBridge()

	/**
	 * Posted under IFRS, the rule gives the Dutch GAAP amount and the difference keeps its sign.
	 *
	 * @return void
	 */
	public function testPostedUnderIfrsDerivesTheDutchGaapEntry(): void {
		$this->givenTheProvision(outputs: ['amount' => 4000]);
		foreach ($this->rows['GLLine'] as $index => $line) {
			$this->rows['GLLine'][$index]['amount'] = 5200;
		}

		$this->rows['FrameworkElection'] = [['id' => 'el-1', 'state' => 'active', 'primaryFramework' => 'IFRS-EU', 'administrationId' => 'adm-1']];

		$result = $this->service()->deriveForTransaction($this->transaction());

		$dual = $this->savedOf('DualTransaction')[0];
		$this->assertSame(120000, $result['differenceCents']);
		$this->assertSame(4000.0, $dual['rjJournalEntries'][0]['debit']);
		$this->assertSame(5200.0, $dual['ifrsJournalEntries'][0]['debit']);
		$this->assertSame(1200.0, $dual['divergenceAmount']);
	}//end testPostedUnderIfrsDerivesTheDutchGaapEntry()

	/**
	 * Another administration's election does not decide the framework.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnAdministrationsElectionCounts(): void {
		$this->givenTheProvision();
		$this->rows['FrameworkElection'] = [['id' => 'el-2', 'state' => 'active', 'primaryFramework' => 'IFRS-EU', 'administrationId' => 'adm-2']];

		$result = $this->service()->deriveForTransaction($this->transaction());

		$this->assertSame(120000, $result['differenceCents']);
		$this->assertSame(4000.0, $this->savedOf('DualTransaction')[0]['rjJournalEntries'][0]['debit']);
	}//end testOnlyTheOwnAdministrationsElectionCounts()

	/**
	 * A factor rule scales the posted amount.
	 *
	 * @return void
	 */
	public function testAFactorRuleScalesThePostedAmount(): void {
		$this->givenTheProvision(outputs: ['factor' => 1.3]);

		$result = $this->service()->deriveForTransaction($this->transaction());

		$this->assertSame(120000, $result['differenceCents']);
		$this->assertSame(5200.0, $this->savedOf('DualTransaction')[0]['ifrsJournalEntries'][1]['credit']);
	}//end testAFactorRuleScalesThePostedAmount()

	/**
	 * Without a rule, an unposted or already derived transaction, nothing is written.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhenThereIsNothingToDerive(): void {
		$service = $this->service();
		$this->assertSame('No adjustment rule for this transaction: it is not divergent.', $service->deriveForTransaction($this->transaction())['message']);

		$this->givenTheProvision();
		$this->assertSame('The transaction is not posted.', $service->deriveForTransaction($this->transaction(['state' => 'draft']))['message']);
		$this->assertFalse($service->deriveForTransaction($this->transaction(['sourceReference' => '']))['derived']);
		$this->assertSame('No adjustment rule for this transaction: it is not divergent.', $service->deriveForTransaction($this->transaction(['administrationId' => 'adm-2']))['message']);

		$this->rows['DualTransaction'] = [['id' => 'dt-1', 'baseTransactionId' => 'tx-1']];
		$this->assertSame('This transaction is already derived.', $service->deriveForTransaction($this->transaction())['message']);

		$this->assertSame([], $this->saved);
	}//end testNothingIsWrittenWhenThereIsNothingToDerive()

	/**
	 * A rule without an amount or factor, or a transaction without debits, writes nothing.
	 *
	 * @return void
	 */
	public function testARuleWithoutAnAmountWritesNothing(): void {
		$this->givenTheProvision(outputs: ['ecl_stage_1' => 15000]);
		$this->assertSame('The adjustment rule gives no amount for the other framework.', $this->service()->deriveForTransaction($this->transaction())['message']);

		$this->rows['GLLine'] = [];
		$this->assertSame('The transaction has no debit lines to derive.', $this->service()->deriveForTransaction($this->transaction())['message']);
		$this->assertSame([], $this->saved);
	}//end testARuleWithoutAnAmountWritesNothing()

	/**
	 * A store failure is logged and reported, not thrown.
	 *
	 * @return void
	 */
	public function testAStoreFailureIsReportedNotThrown(): void {
		$this->givenTheProvision();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$result = $this->service(failSave: true, logger: $logger)->deriveForTransaction($this->transaction());

		$this->assertFalse($result['derived']);
		$this->assertSame('The derived entry could not be written.', $result['message']);
	}//end testAStoreFailureIsReportedNotThrown()

	/**
	 * Scaling keeps both sides on the target total, the rounding cents on the last line.
	 *
	 * @return void
	 */
	public function testScaledEntriesBalanceAfterRounding(): void {
		$lines = [
			['accountNumber' => 'A', 'side' => 'debit', 'amount' => 1],
			['accountNumber' => 'B', 'side' => 'debit', 'amount' => 1],
			['accountNumber' => 'C', 'side' => 'debit', 'amount' => 1],
			['accountNumber' => 'D', 'side' => 'credit', 'amount' => 3],
			['accountNumber' => 'E', 'side' => 'memo', 'amount' => 9],
		];

		$entries = DualGaapDerivationService::scaledEntries(lines: $lines, postedCents: 300, targetCents: 100);

		$this->assertSame([0.33, 0.33, 0.34], array_column(array_slice($entries, 0, 3), 'debit'));
		$this->assertSame(1.0, $entries[3]['credit']);
		$this->assertCount(4, $entries);
	}//end testScaledEntriesBalanceAfterRounding()

	/**
	 * The rule amount: an explicit amount wins over a factor; neither gives null.
	 *
	 * @return void
	 */
	public function testRuleCents(): void {
		$this->assertSame(520000, DualGaapDerivationService::ruleCents(outputs: ['amount' => '5200', 'factor' => 2], postedCents: 400000));
		$this->assertSame(520000, DualGaapDerivationService::ruleCents(outputs: ['otherFrameworkAmount' => -5200], postedCents: 400000));
		$this->assertSame(800000, DualGaapDerivationService::ruleCents(outputs: ['factor' => 2], postedCents: 400000));
		$this->assertNull(DualGaapDerivationService::ruleCents(outputs: ['amount' => 'n/a'], postedCents: 400000));
	}//end testRuleCents()
}//end class
