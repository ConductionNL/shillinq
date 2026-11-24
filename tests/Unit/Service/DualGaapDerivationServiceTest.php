<?php

/**
 * Unit tests for DualGaapDerivationService.
 *
 * REQ-DGAAP-011: posting a transaction marked as divergent derives the entry
 * under the other framework from the configured adjustment rule and records
 * the difference on the period's reconciliation bridge. OpenRegister is
 * doubled at the object-service boundary, recording every save.
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
 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\DualGaapDerivationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The credit loss scenario: EUR 4,000 under Dutch GAAP, EUR 5,200 under IFRS 9.
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
	 * @param bool $failSave Whether every save throws.
	 *
	 * @return DualGaapDerivationService
	 */
	private function service(bool $failSave = false): DualGaapDerivationService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $value) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$value;
				return $objects;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use (&$schema): ?ObjectEntityInterface {
				foreach (($this->rows[$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === (string)$id) {
						$entity = $this->createMock(ObjectEntityInterface::class);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $query) use (&$schema): array {
				$filters = array_map('strval', $query['filters']);
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => array_intersect_assoc($filters, array_map('strval', array_filter($row, 'is_scalar'))) === $filters
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

		return new DualGaapDerivationService($appConfig, $this->createMock(LoggerInterface::class), $objects);
	}//end service()

	/**
	 * The provision posted under Dutch GAAP.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function transaction(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'tx-1',
				'periodId' => '2026',
				'description' => 'Provision doubtful receivables 2026',
				'administrationId' => 'adm-1',
				'divergenceReasonCode' => 'ECL_IFRS9',
				'adjustmentCalculationId' => 'calc-ecl',
			],
			$overrides
		);
	}//end transaction()

	/**
	 * Seed the calculation and the two posted lines.
	 *
	 * @param array<string, mixed> $outputs The calculation outputs.
	 *
	 * @return void
	 */
	private function seed(array $outputs = ['ifrsAmount' => 5200]): void {
		$this->rows['StandardSpecificCalculation'] = [
			['id' => 'calc-ecl', 'standardCode' => 'IFRS-9', 'calculationMethod' => 'expected_credit_loss_stages', 'outputs' => $outputs],
		];
		$this->rows['GLLine'] = [
			['transactionId' => 'tx-1', 'accountNumber' => '4910', 'side' => 'debit', 'amount' => 4000],
			['transactionId' => 'tx-1', 'accountNumber' => '1310', 'side' => 'credit', 'amount' => 4000],
		];
	}//end seed()

	/**
	 * The saved objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedOf(string $schema): array {
		$ofSchema = array_filter($this->saved, static fn (array $save): bool => $save[0] === $schema);

		return array_values(array_map(static fn (array $save): array => $save[1], $ofSchema));
	}//end savedOf()

	/**
	 * The scenario: the IFRS entry of EUR 5,200 is derived and the bridge shows EUR 1,200.
	 *
	 * @return void
	 */
	public function testAnExpectedCreditLossDerivesTheIfrsEntryAndABridgeOf1200(): void {
		$this->seed();

		$result = $this->service()->deriveOnPost($this->transaction());

		$this->assertTrue($result['derived']);
		$this->assertSame(120000, $result['differenceCents']);
		$dual = $this->savedOf('DualTransaction');
		$this->assertCount(1, $dual);
		$this->assertSame('tx-1', $dual[0]['baseTransactionId']);
		$this->assertSame('ECL_IFRS9', $dual[0]['divergenceReasonCode']);
		$this->assertSame(1200.0, $dual[0]['divergenceAmount']);
		$this->assertSame(
			[['accountNumber' => '4910', 'side' => 'debit', 'amount' => 4000.0], ['accountNumber' => '1310', 'side' => 'credit', 'amount' => 4000.0]],
			$dual[0]['rjJournalEntries']
		);
		$this->assertSame(
			[['accountNumber' => '4910', 'side' => 'debit', 'amount' => 5200.0], ['accountNumber' => '1310', 'side' => 'credit', 'amount' => 5200.0]],
			$dual[0]['ifrsJournalEntries']
		);

		$bridge = $this->savedOf('ReconciliationBridge');
		$this->assertCount(1, $bridge);
		$this->assertSame('2026', $bridge[0]['period']);
		$this->assertSame('net_result', $bridge[0]['metric']);
		$this->assertSame('NL-GAAP-RJ', $bridge[0]['fromFramework']);
		$this->assertSame('IFRS-EU', $bridge[0]['toFramework']);
		$this->assertSame(1200.0, $bridge[0]['adjustments'][0]['amount']);
		$this->assertSame('IFRS-9', $bridge[0]['adjustments'][0]['standardReference']);
		$this->assertSame('tx-1', $bridge[0]['adjustments'][0]['sourceTransactionId']);
		$this->assertSame(1200.0, $bridge[0]['closingBalanceIfrs']);
	}//end testAnExpectedCreditLossDerivesTheIfrsEntryAndABridgeOf1200()

	/**
	 * An existing bridge of the period gets the line appended and its closing balance recomputed.
	 *
	 * @return void
	 */
	public function testTheLineIsAppendedToThePeriodsExistingBridge(): void {
		$this->seed();
		$this->rows['ReconciliationBridge'] = [[
			'id' => 'bridge-2026',
			'administrationId' => 'adm-1',
			'period' => '2026',
			'metric' => 'net_result',
			'fromFramework' => 'NL-GAAP-RJ',
			'toFramework' => 'IFRS-EU',
			'openingBalanceRj' => 100000,
			'adjustments' => [['description' => 'Lease', 'amount' => -300]],
			'closingBalanceIfrs' => 99700,
		]];

		$this->service()->deriveOnPost($this->transaction());

		$bridge = $this->savedOf('ReconciliationBridge');
		$this->assertSame('bridge-2026', $bridge[0]['id']);
		$this->assertCount(2, $bridge[0]['adjustments']);
		$this->assertSame(100900.0, $bridge[0]['closingBalanceIfrs']);
	}//end testTheLineIsAppendedToThePeriodsExistingBridge()

	/**
	 * An entry booked under IFRS derives the Dutch GAAP entry from `rjAmount`; the bridge still runs RJ to IFRS.
	 *
	 * @return void
	 */
	public function testAnEntryBookedUnderIfrsDerivesTheDutchGaapEntry(): void {
		$this->seed(['rjAmount' => 5200]);

		$result = $this->service()->deriveOnPost($this->transaction(['reportingFramework' => 'IFRS-EU']));

		$this->assertTrue($result['derived']);
		$this->assertSame(-120000, $result['differenceCents']);
		$dual = $this->savedOf('DualTransaction')[0];
		$this->assertSame(5200.0, $dual['rjJournalEntries'][0]['amount']);
		$this->assertSame(4000.0, $dual['ifrsJournalEntries'][0]['amount']);
		$this->assertSame(-1200.0, $this->savedOf('ReconciliationBridge')[0]['adjustments'][0]['amount']);
	}//end testAnEntryBookedUnderIfrsDerivesTheDutchGaapEntry()

	/**
	 * Without the mark, an existing derivation, a missing rule or rule amount, nothing is written.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhenThereIsNothingToDerive(): void {
		$this->seed();
		$service = $this->service();

		$cases = [
			'The transaction is not marked as divergent.' => ['divergenceReasonCode' => 'NONE'],
			'The adjustment calculation does not exist.' => ['adjustmentCalculationId' => 'calc-x'],
			'The adjustment calculation gives no amount under the other framework.' => ['reportingFramework' => 'IFRS-EU'],
		];
		foreach ($cases as $message => $overrides) {
			$this->assertSame($message, $service->deriveOnPost($this->transaction($overrides))['message']);
		}

		$this->assertFalse($service->deriveOnPost($this->transaction(['adjustmentCalculationId' => '']))['derived']);

		$this->rows['DualTransaction'] = [['id' => 'dual-1', 'baseTransactionId' => 'tx-1']];
		$this->assertSame('This transaction is already derived.', $service->deriveOnPost($this->transaction())['message']);
		$this->assertSame([], $this->saved);
	}//end testNothingIsWrittenWhenThereIsNothingToDerive()

	/**
	 * A transaction without lines or amount is refused; a failing store is reported, not thrown.
	 *
	 * @return void
	 */
	public function testNoLinesOrAFailingStoreDeriveNothing(): void {
		$this->seed();
		$this->rows['GLLine'] = [];
		$this->assertSame('The transaction has no amount to derive from.', $this->service()->deriveOnPost($this->transaction())['message']);

		$lines = [['accountNumber' => '4910', 'side' => 'debit', 'amount' => 10], ['accountNumber' => '1310', 'side' => 'credit', 'amount' => 10]];
		$result = $this->service(failSave: true)->deriveOnPost($this->transaction(['lines' => $lines]));
		$this->assertFalse($result['derived']);
		$this->assertSame('The other framework\'s entry could not be stored.', $result['message']);
	}//end testNoLinesOrAFailingStoreDeriveNothing()

	/**
	 * Scaling keeps each side at the target total; the rounding rest lands on the largest line.
	 *
	 * @return void
	 */
	public function testScaledLinesStayBalancedToTheCent(): void {
		$lines = [
			['accountNumber' => 'A', 'side' => 'debit', 'amount' => 33.33],
			['accountNumber' => 'B', 'side' => 'debit', 'amount' => 33.33],
			['accountNumber' => 'C', 'side' => 'debit', 'amount' => 33.34],
			['accountNumber' => 'D', 'side' => 'credit', 'amount' => 100],
			['accountNumber' => 'E', 'side' => 'memo', 'amount' => 5],
		];

		$scaled = DualGaapDerivationService::scaleLines($lines, 10000, 20001);

		$this->assertCount(4, $scaled);
		$debitLines = array_filter($scaled, static fn (array $l): bool => $l['side'] === 'debit');
		$debit = array_sum(array_map(static fn (array $l): int => (int)round($l['amount'] * 100), $debitLines));
		$this->assertSame(20001, $debit);
		$this->assertSame(200.01, $scaled[3]['amount']);
		$this->assertSame(66.66, $scaled[0]['amount']);
		$this->assertSame(66.69, $scaled[2]['amount']);
	}//end testScaledLinesStayBalancedToTheCent()

	/**
	 * The framework defaults to Dutch GAAP; non-numeric outputs give no amount.
	 *
	 * @return void
	 */
	public function testFrameworkAndAmountReading(): void {
		$this->assertSame('NL-GAAP-RJ', DualGaapDerivationService::frameworkOf([]));
		$this->assertSame('IFRS-EU', DualGaapDerivationService::frameworkOf(['reportingFramework' => 'IFRS-EU']));
		$this->assertSame(520000, DualGaapDerivationService::otherAmountCents(['outputs' => ['ifrsAmount' => '5200.00']], 'NL-GAAP-RJ'));
		$this->assertNull(DualGaapDerivationService::otherAmountCents(['outputs' => ['ifrsAmount' => 'n/a']], 'NL-GAAP-RJ'));
		$this->assertNull(DualGaapDerivationService::otherAmountCents([], 'IFRS-EU'));
	}//end testFrameworkAndAmountReading()
}//end class
