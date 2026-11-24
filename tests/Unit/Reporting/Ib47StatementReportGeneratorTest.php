<?php

/**
 * Ib47StatementReportGeneratorTest
 *
 * The IB47 statement file names what the prepared batch holds.
 *
 * The first test runs the batch service and the generator over the same
 * store, so "the statement file names that amount" is asserted on the file a
 * prepare actually produced, not on a record typed into the test.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Reporting
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

namespace OCA\Shillinq\Tests\Unit\Reporting;

use InvalidArgumentException;
use OCA\Shillinq\Reporting\Generator\Ib47StatementReportGenerator;
use OCA\Shillinq\Service\Ib47\Ib47BatchService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The IB47 statement generator.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\Ib47StatementReportGenerator
 * @uses   \OCA\Shillinq\Service\Ib47\Ib47BatchService
 * @uses   \OCA\Shillinq\Kernel\Model\GeneratedFile
 * @uses   \OCA\Shillinq\Support\RegisterSlug
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub
 *
 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */
final class Ib47StatementReportGeneratorTest extends TestCase {

	/**
	 * The in-memory store behind the object service.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The object service over the store.
	 *
	 * @var DuckObjectServiceAdapter
	 */
	private DuckObjectServiceAdapter $objects;

	/**
	 * App config answering the default register.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $config;

	/**
	 * An empty store.
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
		$this->objects = new DuckObjectServiceAdapter(inner: $this->store);
		$config        = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			callback: static fn (string $app, string $key, string $default = ''): string => $default
		);
		$this->config = $config;
	}//end setUp()

	/**
	 * Scenario: a freelancer's payments land in the 2026 batch, and the
	 * statement file names that amount.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md#scenario-a-freelancers-payments-land-in-the-2026-batch
	 */
	public function testTheStatementNamesWhatThePrepareWrote(): void {
		$this->store->rows['Payee']['payee-jdeboer'] = [
			'id' => 'payee-jdeboer',
			'name' => 'J. de Boer',
			'administrationId' => 'adm-1',
			'ib47Recipient' => true,
			'bsn' => '123456782',
			'ib47PaymentTypeCode' => '1',
			'address' => ['street' => 'Voorbeeldstraat', 'houseNumber' => '1', 'postcode' => '1234AB', 'city' => 'Amsterdam'],
		];
		foreach ([['2026-03-02', 1200.0], ['2026-06-01', 800.0], ['2025-12-15', 500.0]] as $index => [$date, $amount]) {
			$this->store->rows['PaymentRun']['run-' . $index] = [
				'id' => 'run-' . $index,
				'administrationId' => 'adm-1',
				'lifecycleState' => 'reconciled',
				'executionDate' => $date,
				'paymentLines' => [
					['payeeId' => 'payee-jdeboer', 'amount' => $amount, 'creditorIban' => 'NL00TEST0000000000', 'apTransactionRef' => 'ap-' . $index],
				],
			];
		}

		(new Ib47BatchService(objectService: $this->objects, appConfig: $this->config))->prepare(administrationId: 'adm-1', taxYear: 2026);
		$file = $this->generator()->generate(context: ['period' => '2026', 'administrationId' => 'adm-1'], format: 'csv');

		self::assertSame(expected: 'ib47-2026-adm-1.csv', actual: $file->fileName);
		self::assertSame(expected: 'text/csv', actual: $file->mimeType);
		self::assertSame(
			expected: "Belastingjaar,\"Naam ontvanger\",BSN,Adres,\"Soort betaling\",\"Totaal betalingen (EUR)\"\n"
				. "2026,\"J. de Boer\",123456782,\"Voorbeeldstraat 1, 1234AB Amsterdam\",1,2000.00\n",
			actual: $file->content
		);
	}//end testTheStatementNamesWhatThePrepareWrote()

	/**
	 * Dry runs, other years and other administrations stay out; recipients are sorted by name.
	 *
	 * @return void
	 */
	public function testOnlyTheYearsFinalRecordsOfTheAdministrationAreWritten(): void {
		$this->store->rows['IB47Record'] = [
			'r1' => $this->record(name: 'Z. Zwart', total: 10.5),
			'r2' => $this->record(name: 'A. Aalbers', total: 300.0),
			'r3' => (['isDryRun' => true] + $this->record(name: 'Dry Run', total: 1.0)),
			'r4' => (['taxYear' => 2025] + $this->record(name: 'Vorig Jaar', total: 1.0)),
			'r5' => (['administrationId' => 'adm-2'] + $this->record(name: 'Ander', total: 1.0)),
		];

		$file  = $this->generator()->generate(context: ['period' => '2026-Q4', 'administrationId' => 'adm-1'], format: 'csv');
		$lines = explode(separator: "\n", string: trim(string: $file->content));

		self::assertCount(expectedCount: 3, haystack: $lines);
		self::assertStringStartsWith(prefix: '2026,"A. Aalbers"', string: $lines[1]);
		self::assertStringEndsWith(suffix: ',300.00', string: $lines[1]);
		self::assertStringStartsWith(prefix: '2026,"Z. Zwart"', string: $lines[2]);
		self::assertStringEndsWith(suffix: ',10.50', string: $lines[2]);
	}//end testOnlyTheYearsFinalRecordsOfTheAdministrationAreWritten()

	/**
	 * A period without a year is refused.
	 *
	 * @return void
	 */
	public function testAPeriodWithoutAYearIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->generator()->generate(context: ['period' => 'Q4', 'administrationId' => 'adm-1'], format: 'csv');
	}//end testAPeriodWithoutAYearIsRefused()

	/**
	 * A final 2026 record of adm-1.
	 *
	 * @param string $name  The recipient.
	 * @param float  $total The total.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $name, float $total): array {
		return [
			'taxYear' => 2026,
			'administrationId' => 'adm-1',
			'recipientName' => $name,
			'recipientBSN' => '123456782',
			'recipientAddress' => 'Straat 1, Stad',
			'paymentTypeCode' => '1',
			'paymentsTotal' => $total,
			'isDryRun' => false,
		];
	}//end record()

	/**
	 * The generator over the store.
	 *
	 * @return Ib47StatementReportGenerator
	 */
	private function generator(): Ib47StatementReportGenerator {
		return new Ib47StatementReportGenerator(objectService: $this->objects, appConfig: $this->config);
	}//end generator()
}//end class
