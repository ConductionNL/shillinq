<?php

/**
 * Ib47BatchServiceTest
 *
 * The annual IB47 batch, filled from the payment runs the bank confirmed.
 *
 * THE TWO SCENARIOS OF REQ-DPA-021 ARE THE FIRST TWO TESTS, with their own
 * numbers: J. de Boer paid EUR 1,200 and EUR 800 in 2026 and EUR 500 in 2025
 * reads EUR 2,000 for 2026, and a further EUR 300 in December, prepared again,
 * reads EUR 2,300 in the same record. The rest pins the decisions the service
 * makes on its own: what counts as paid, and what it refuses to make up.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Ib47
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Ib47;

use InvalidArgumentException;
use OCA\Shillinq\Service\Ib47\Ib47BatchService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The IB47 batch service.
 *
 * @covers \OCA\Shillinq\Service\Ib47\Ib47BatchService
 * @uses   \OCA\Shillinq\Support\RegisterSlug
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub
 *
 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */
final class Ib47BatchServiceTest extends TestCase {

	private const ADMINISTRATION = 'adm-1';

	/**
	 * The in-memory store behind the object service.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The service under test.
	 *
	 * @var Ib47BatchService
	 */
	private Ib47BatchService $service;

	/**
	 * A store holding J. de Boer and the runs of the first scenario.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new class {
			/**
			 * Rows per schema.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $rows = [];

			/**
			 * The saves, in order.
			 *
			 * @var list<array{schema: string, uuid: ?string, object: array<string, mixed>}>
			 */
			public array $saves = [];

			/**
			 * The active schema.
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * Activate a schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * The rows of the active schema that equal every filter.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				return array_values(
					array: array_filter(
						array: ($this->rows[$this->schema] ?? []),
						callback: static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Store an object under its uuid, a new one when none is given.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param string               $schema The schema.
			 * @param string|null          $uuid   The uuid to update.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $schema = '', ?string $uuid = null): array {
				$this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object];
				$id            = ($uuid ?? 'rec-' . count(value: $this->saves));
				$this->rows[$schema][$id] = (['id' => $id] + $object);
				return $this->rows[$schema][$id];
			}//end saveObject()
		};

		$this->store->rows['Payee']['payee-jdeboer'] = $this->deBoer();
		$this->store->rows['PaymentRun'] = [
			'run-2026-03' => $this->paymentRun(id: 'run-2026-03', date: '2026-03-02', lines: [['payee-jdeboer', 1200.0]]),
			'run-2026-06' => $this->paymentRun(id: 'run-2026-06', date: '2026-06-01', lines: [['payee-jdeboer', 800.0]]),
			'run-2025-12' => $this->paymentRun(id: 'run-2025-12', date: '2025-12-15', lines: [['payee-jdeboer', 500.0]]),
		];

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			callback: static fn (string $app, string $key, string $default = ''): string => $default
		);

		$this->service = new Ib47BatchService(
			objectService: new DuckObjectServiceAdapter(inner: $this->store),
			appConfig: $config
		);
	}//end setUp()

	/**
	 * Scenario: a freelancer's payments land in the 2026 batch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md#scenario-a-freelancers-payments-land-in-the-2026-batch
	 */
	public function testAFreelancersPaymentsLandInThe2026Batch(): void {
		$batch = $this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 2026);

		self::assertSame(expected: [], actual: $batch['incomplete']);
		self::assertCount(expectedCount: 1, haystack: $batch['prepared']);
		self::assertSame(expected: 'payee-jdeboer', actual: $batch['prepared'][0]['payeeId']);
		self::assertSame(expected: 2000.0, actual: $batch['prepared'][0]['paymentsTotal']);
		self::assertTrue(condition: $batch['prepared'][0]['created']);

		self::assertCount(expectedCount: 1, haystack: $this->store->saves);
		$saved = $this->store->saves[0];
		self::assertSame(expected: 'IB47Record', actual: $saved['schema']);
		self::assertNull(actual: $saved['uuid']);
		self::assertSame(
			expected: [
				'taxYear' => 2026,
				'clientId' => self::ADMINISTRATION,
				'administrationId' => self::ADMINISTRATION,
				'payeeId' => 'payee-jdeboer',
				'recipientName' => 'J. de Boer',
				'recipientBSN' => '123456782',
				'recipientAddress' => 'Voorbeeldstraat 1, 1234AB Amsterdam',
				'paymentsTotal' => 2000.0,
				'paymentTypeCode' => '1',
				'isDryRun' => false,
			],
			actual: $saved['object']
		);
	}//end testAFreelancersPaymentsLandInThe2026Batch()

	/**
	 * Scenario: a second prepare writes no duplicate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md#scenario-a-second-prepare-writes-no-duplicate
	 */
	public function testASecondPrepareWritesNoDuplicate(): void {
		$first = $this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 2026);
		$recordId = $first['prepared'][0]['recordId'];

		$this->store->rows['PaymentRun']['run-2026-12'] = $this->paymentRun(id: 'run-2026-12', date: '2026-12-18', lines: [['payee-jdeboer', 300.0]]);
		$second = $this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 2026);

		self::assertSame(expected: $recordId, actual: $second['prepared'][0]['recordId']);
		self::assertFalse(condition: $second['prepared'][0]['created']);
		self::assertSame(expected: 2300.0, actual: $second['prepared'][0]['paymentsTotal']);
		self::assertSame(expected: $recordId, actual: $this->store->saves[1]['uuid']);
		self::assertCount(expectedCount: 1, haystack: $this->store->rows['IB47Record']);
		self::assertSame(expected: 2300.0, actual: $this->store->rows['IB47Record'][$recordId]['paymentsTotal']);
	}//end testASecondPrepareWritesNoDuplicate()

	/**
	 * Only a run the bank confirmed is paid: an approved or exported run is not,
	 * and a line's own execution date decides its year over the run's.
	 *
	 * @return void
	 */
	public function testOnlyAConfirmedRunCountsAndTheLineDateDecidesTheYear(): void {
		$exported = $this->paymentRun(id: 'run-exported', date: '2026-04-01', lines: [['payee-jdeboer', 999.0]]);
		$exported['lifecycleState'] = 'exported';
		$this->store->rows['PaymentRun']['run-exported'] = $exported;
		$late = $this->paymentRun(id: 'run-late', date: '2026-12-30', lines: [['payee-jdeboer', 50.0]]);
		$late['paymentLines'][0]['requestedExecutionDate'] = '2027-01-04';
		$this->store->rows['PaymentRun']['run-late'] = $late;

		$batch = $this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 2026);

		self::assertSame(expected: 2000.0, actual: $batch['prepared'][0]['paymentsTotal']);
	}//end testOnlyAConfirmedRunCountsAndTheLineDateDecidesTheYear()

	/**
	 * A payee who is not marked, and a payment of another administration, are
	 * not on the statement.
	 *
	 * @return void
	 */
	public function testUnmarkedPayeesAndOtherAdministrationsStayOut(): void {
		$supplier = (['id' => 'payee-supplier', 'ib47Recipient' => false, 'name' => 'Leverancier B.V.'] + $this->deBoer());
		$this->store->rows['Payee']['payee-supplier'] = $supplier;
		$supplierRun = $this->paymentRun(id: 'run-supplier', date: '2026-05-01', lines: [['payee-supplier', 4000.0]]);
		$this->store->rows['PaymentRun']['run-supplier'] = $supplierRun;
		$other = $this->paymentRun(id: 'run-other', date: '2026-05-01', lines: [['payee-jdeboer', 700.0]]);
		$other['administrationId'] = 'adm-2';
		$this->store->rows['PaymentRun']['run-other'] = $other;

		$batch = $this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 2026);

		self::assertSame(expected: ['payee-jdeboer'], actual: array_column(array: $batch['prepared'], column_key: 'payeeId'));
		self::assertSame(expected: 2000.0, actual: $batch['prepared'][0]['paymentsTotal']);
	}//end testUnmarkedPayeesAndOtherAdministrationsStayOut()

	/**
	 * A recipient without a BSN or payment type code is named as incomplete and
	 * no record is written for it.
	 *
	 * @return void
	 */
	public function testAnIncompleteRecipientIsNamedAndNotWritten(): void {
		$payee = $this->deBoer();
		unset($payee['bsn'], $payee['ib47PaymentTypeCode']);
		$this->store->rows['Payee']['payee-jdeboer'] = $payee;

		$batch = $this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 2026);

		self::assertSame(expected: [], actual: $batch['prepared']);
		self::assertSame(
			expected: [['payeeId' => 'payee-jdeboer', 'name' => 'J. de Boer', 'missing' => ['bsn', 'ib47PaymentTypeCode']]],
			actual: $batch['incomplete']
		);
		self::assertSame(expected: [], actual: $this->store->saves);
	}//end testAnIncompleteRecipientIsNamedAndNotWritten()

	/**
	 * A recipient with nothing paid in the year gets no record.
	 *
	 * @return void
	 */
	public function testNothingPaidWritesNothing(): void {
		$batch = $this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 2024);

		self::assertSame(expected: [], actual: $batch['prepared']);
		self::assertSame(expected: [], actual: $this->store->saves);
	}//end testNothingPaidWritesNothing()

	/**
	 * A year out of range and an empty administration are refused before any read.
	 *
	 * @return void
	 */
	public function testAnUnusableRequestIsRefused(): void {
		try {
			$this->service->prepare(administrationId: ' ', taxYear: 2026);
			self::fail(message: 'An empty administration was accepted.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString(needle: 'administration', haystack: $exception->getMessage());
		}

		$this->expectException(exception: InvalidArgumentException::class);
		$this->service->prepare(administrationId: self::ADMINISTRATION, taxYear: 1999);
	}//end testAnUnusableRequestIsRefused()

	/**
	 * J. de Boer, marked as an IB47 recipient with everything the statement needs.
	 *
	 * @return array<string, mixed>
	 */
	private function deBoer(): array {
		return [
			'id' => 'payee-jdeboer',
			'name' => 'J. de Boer',
			'administrationId' => self::ADMINISTRATION,
			'ib47Recipient' => true,
			'bsn' => '123456782',
			'ib47PaymentTypeCode' => '1',
			'address' => ['street' => 'Voorbeeldstraat', 'houseNumber' => '1', 'postcode' => '1234AB', 'city' => 'Amsterdam'],
		];
	}//end deBoer()

	/**
	 * A payment run the bank confirmed.
	 *
	 * @param string                          $id    The run id.
	 * @param string                          $date  The execution date.
	 * @param list<array{0: string, 1: float}> $lines Payee and amount per line.
	 *
	 * @return array<string, mixed>
	 */
	private function paymentRun(string $id, string $date, array $lines): array {
		return [
			'id' => $id,
			'administrationId' => self::ADMINISTRATION,
			'lifecycleState' => 'reconciled',
			'executionDate' => $date,
			'paymentLines' => array_map(
				callback: static fn (array $line): array => [
					'payeeId' => $line[0],
					'amount' => $line[1],
					'creditorIban' => 'NL00TEST0000000000',
					'apTransactionRef' => 'ap-' . $line[0],
				],
				array: $lines
			),
		];
	}//end paymentRun()
}//end class
