<?php

/**
 * Unit test proving a metered MeterReading rates and lands as a
 * BillableInvoiceLine on a BillableInvoice through the EXISTING
 * InvoiceGenerationService pipeline — the 'usage' billing model (REQ-UMB-003).
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
 * @spec openspec/changes/ar-billing-completeness/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\BillingModelEngine;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RetainerResolver;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Service\VATCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Drives InvoiceGenerationService::draftInvoice() with billingModel='usage'.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MeteredInvoiceGenerationTest extends TestCase {

	/**
	 * In-memory fake ObjectService supporting the fluent find/findAll/saveObject
	 * shape InvoiceGenerationService consumes.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Subject under test.
	 *
	 * @var InvoiceGenerationService
	 */
	private InvoiceGenerationService $service;

	/**
	 * Set up fixtures — one UsageRatePlan (graduated) and one MeterReading.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new class {

			/**
			 * Records keyed by schema; also the store find/findAll read from.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $store = [];

			/**
			 * Save log keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var integer
			 */
			private int $seq = 0;

			/**
			 * Fluent register selector.
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema selector.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find one record by id under the current schema.
			 *
			 * @param string $id Record id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				foreach (($this->store[$this->schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Return rows for the current schema, applying equality filters.
			 *
			 * @param array<string,mixed> $options Query options.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $options): array {
				$rows = $this->store[$this->schema] ?? [];
				$filters = $options['filters'] ?? [];
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
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
			 * Persist a record (assigning an id when absent) under the current schema.
			 *
			 * @param array<string,mixed> $data Record payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				if (isset($data['id']) === false || (string)$data['id'] === '') {
					$this->seq++;
					$data['id'] = sprintf('%s-%d', strtolower($this->schema), $this->seq);
				}

				$this->store[$this->schema][] = $data;
				$this->saved[$this->schema][] = $data;
				return $data;
			}//end saveObject()
		};

		$this->objectService->store = [
			'UsageRatePlan' => [
				[
					'id' => 'urp-api-standard',
					'administrationId' => 'adm-holding-nl',
					'name' => 'API Calls — Standard',
					'resourceType' => 'api_calls',
					'unit' => 'calls',
					'ratingMethod' => 'graduated',
					'vatRate' => 21,
					'tiers' => [
						['upTo' => 1000, 'unitPriceCents' => 5],
						['upTo' => 10000, 'unitPriceCents' => 3],
						['upTo' => null, 'unitPriceCents' => 2],
					],
				],
			],
			'MeterReading' => [
				[
					'id' => 'mr-1',
					'administrationId' => 'adm-holding-nl',
					'meterId' => 'meter-api-cust-42',
					'customerId' => 'cust-42',
					'resourceType' => 'api_calls',
					'quantity' => 12500,
					'unit' => 'calls',
					'ratePlanId' => 'urp-api-standard',
					'periodStart' => '2026-03-01',
					'periodEnd' => '2026-03-31',
				],
			],
			'BillableInvoice' => [],
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);

		$logger = new NullLogger();

		$this->service = new InvoiceGenerationService(
			$appConfig,
			$logger,
			new RateCardResolver($container, $appConfig, $logger),
			new RetainerResolver($container, $appConfig, $logger),
			new BillingModelEngine(),
			new InvoiceDeduplicationService($container, $appConfig, $logger),
			new VATCalculationService(),
			new UsageRatingCalculator(),
			objectService: new DuckObjectServiceAdapter($this->objectService),
		);
	}//end setUp()

	/**
	 * REQ-UMB-003: a 12500-call reading rates to €370.00 net (graduated) and
	 * lands as a single 'usage' BillableInvoiceLine on a drafted BillableInvoice.
	 *
	 * @return void
	 */
	public function testMeteredReadingRatesAndLandsOnInvoice(): void {
		$request = new InvoiceGenerationRequest(
			administrationId: 'adm-holding-nl',
			billingModel: 'usage',
			customerId: 'cust-42',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			meterReadingIds: ['mr-1'],
			usageRatePlanId: 'urp-api-standard'
		);

		$invoice = $this->service->draftInvoice($request);

		// The invoice net is the rated usage cost: 1000*5 + 9000*3 + 2500*2 = 37000 cents.
		self::assertSame(370.00, $invoice['netAmount']);
		self::assertSame('usage', $invoice['billingModel']);

		// Exactly one BillableInvoiceLine was persisted, of sourceType 'usage'.
		$lines = $this->objectService->saved['BillableInvoiceLine'] ?? [];
		self::assertCount(1, $lines);
		self::assertSame('usage', $lines[0]['sourceType']);
		self::assertSame(370.00, $lines[0]['costAmount']);
		self::assertSame(12500.0, $lines[0]['billableUnits']);
		self::assertSame('mr-1', $lines[0]['sourceId']);
		// VAT at 21% of €370 = €77.70.
		self::assertSame(77.70, $lines[0]['vatAmount']);
	}//end testMeteredReadingRatesAndLandsOnInvoice()
}//end class
