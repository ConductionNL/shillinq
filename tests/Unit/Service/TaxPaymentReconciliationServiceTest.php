<?php

/**
 * Unit tests for TaxPaymentReconciliationService.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TaxPaymentReconciliationService;
use OCA\Shillinq\Service\TaxReportCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests Vpb payment reconciliation against a stubbed GL (REQ-VPB-008).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxPaymentReconciliationServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The service under test.
	 *
	 * @var TaxPaymentReconciliationService
	 */
	private TaxPaymentReconciliationService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->service = $this->buildService($this->buildObjectServiceStub([]));

	}//end setUp()

	/**
	 * Build the subject around a seeded in-memory ObjectService store.
	 *
	 * The store used to reach the subject through the container; ADR-084 injects
	 * it as a contract-typed constructor argument instead, so each test rebuilds
	 * the subject with its own store.
	 *
	 * @param object $store The seeded in-memory ObjectService double.
	 *
	 * @return TaxPaymentReconciliationService
	 */
	private function buildService(object $store): TaxPaymentReconciliationService {
		return new TaxPaymentReconciliationService(
			appConfig: $this->appConfig,
			calculator: new TaxReportCalculator(),
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildService()

	/**
	 * A payment that matches a GL posting reports matched with zero variance (REQ-VPB-008).
	 *
	 * @return void
	 */
	public function testMatchedPaymentZeroVariance(): void {
		$records = [
			'TaxPaymentTracking' => [
				[
					'@self' => ['slug' => 'pay-1'],
					'administrationId' => 'adm-1',
					'linkedGLAccount' => '1200',
					'amount' => 15000.0,
					'paymentDate' => '2025-04-20T00:00:00+00:00',
				],
			],
			'GLLine' => [
				['accountNumber' => '1200', 'amount' => 15000.0, 'periodId' => '2025-Q2'],
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($records));

		$result = $this->service->reconcile(administrationId: 'adm-1', paymentId: 'pay-1');

		self::assertTrue($result['matched']);
		self::assertSame(0.0, $result['variance']);
		self::assertSame(1, $result['glLineCount']);

	}//end testMatchedPaymentZeroVariance()

	/**
	 * A divergent GL amount is reported as a non-zero variance, not matched (REQ-VPB-008).
	 *
	 * @return void
	 */
	public function testDivergentPaymentReportsVariance(): void {
		$records = [
			'TaxPaymentTracking' => [
				[
					'@self' => ['slug' => 'pay-1'],
					'administrationId' => 'adm-1',
					'linkedGLAccount' => '1200',
					'amount' => 15000.0,
					'paymentDate' => '2025-04-20T00:00:00+00:00',
				],
			],
			'GLLine' => [
				['accountNumber' => '1200', 'amount' => 12000.0, 'periodId' => '2025-Q2'],
			],
		];

		$this->service = $this->buildService($this->buildObjectServiceStub($records));

		$result = $this->service->reconcile(administrationId: 'adm-1', paymentId: 'pay-1');

		self::assertFalse($result['matched']);
		// GL 12000 - payment 15000 = -3000.
		self::assertSame(-3000.0, $result['variance']);

	}//end testDivergentPaymentReportsVariance()

	/**
	 * An unknown payment id reports unmatched with zeroed amounts.
	 *
	 * @return void
	 */
	public function testUnknownPaymentReturnsUnmatched(): void {
		$records = ['TaxPaymentTracking' => [], 'GLLine' => []];
		$this->service = $this->buildService($this->buildObjectServiceStub($records));

		$result = $this->service->reconcile(administrationId: 'adm-1', paymentId: 'missing');

		self::assertFalse($result['matched']);
		self::assertSame(0, $result['glLineCount']);

	}//end testUnknownPaymentReturnsUnmatched()

	/**
	 * Build a fluent ObjectService stub returning records by schema (honours accountNumber filter).
	 *
	 * @param array<string,array<int,array<string,mixed>>> $recordsBySchema Records by schema.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Records keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $recordsBySchema Records by schema.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records, honouring an accountNumber filter for GLLine.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$records = ($this->recordsBySchema[$this->currentSchema] ?? []);
				$account = ($params['filters']['accountNumber'] ?? null);
				if ($account === null) {
					return $records;
				}

				return array_values(
					array_filter(
						$records,
						static function (array $r) use ($account): bool {
							return (string)($r['accountNumber'] ?? '') === (string)$account;
						}
					)
				);

			}//end findAll()
		};

	}//end buildObjectServiceStub()
}//end class
