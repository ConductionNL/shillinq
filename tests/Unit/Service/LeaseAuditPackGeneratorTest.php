<?php

/**
 * Unit tests for LeaseAuditPackGenerator (skeleton).
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeaseAuditPackGenerator;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the audit-pack index manifest the Phase-2 docudesk pipeline will turn
 * into a ZIP. The skeleton covers administration-scoped reads, the
 * deterministic file layout, and the IBR evidence FK list extraction.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseAuditPackGeneratorTest extends TestCase {

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
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the generator against an in-memory OR stub seeded with the lease.
	 *
	 * @param array<int,array<string,mixed>> $leases LeaseContract records.
	 * @param array<int,array<string,mixed>> $events LeaseReassessmentEvent records.
	 *
	 * @return LeaseAuditPackGenerator
	 */
	private function buildService(array $leases, array $events = []): LeaseAuditPackGenerator {
		$stub = new class($leases, $events) {

			/**
			 * LeaseContract records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $leases;

			/**
			 * LeaseReassessmentEvent records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $events;

			/**
			 * Last schema set.
			 *
			 * @var string
			 */
			public string $lastSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $leases Leases.
			 * @param array<int,array<string,mixed>> $events Events.
			 */
			public function __construct(array $leases, array $events) {
				$this->leases = $leases;
				$this->events = $events;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->lastSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return records matching the administration filter.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$admin = ($params['filters']['administrationId'] ?? null);
				if ($this->lastSchema === 'LeaseReassessmentEvent') {
					$sourceLease = ($params['filters']['sourceLease'] ?? null);
					return array_values(
						array_filter(
							$this->events,
							static fn (array $event): bool => ($event['administrationId'] ?? null) === $admin
								&& ($sourceLease === null || ($event['sourceLease'] ?? null) === $sourceLease)
						)
					);
				}

				if ($admin === null) {
					return $this->leases;
				}

				return array_values(
					array_filter(
						$this->leases,
						static fn (array $lease): bool => ($lease['administrationId'] ?? null) === $admin
					)
				);
			}//end findAll()

			/**
			 * Capture saves (unused by the audit-pack skeleton).
			 *
			 * @param array<string,mixed> $object The row.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturn($stub);

		$calculator = new LeaseAmortizationCalculator();
		$logger = $this->createMock(LoggerInterface::class);

		return new LeaseAuditPackGenerator(
			appConfig: $this->appConfig,
			scheduleService: new LeasePaymentScheduleService(
				appConfig: $this->appConfig,
				calculator: $calculator,
				logger: $logger,
				objectService: new DuckObjectServiceAdapter($stub),
			),
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * The skeleton emits a deterministic contents index for the auditor pack.
	 *
	 * @return void
	 */
	public function testGenerateBuildsContentsIndex(): void {
		$lease = [
			'@self' => ['slug' => 'lease-v', 'id' => 'lease-v'],
			'leaseNumber' => 'VH-2024-001',
			'assetClass' => 'vehicle',
			'classification' => 'IFRS16-capitalised',
			'status' => 'active',
			'commencementDate' => '2024-01-01',
			'endDate' => '2026-12-31',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'paymentCurrency' => 'EUR',
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
			'ibrEvidenceDocuments' => ['doc-1', ['id' => 'doc-2']],
		];

		$events = [
			[
				'administrationId' => 'adm-1',
				'sourceLease' => 'lease-v',
				'eventType' => 'indexation-remeasurement',
			],
		];

		$pack = $this->buildService([$lease], $events)
			->generate('lease-v', 'adm-1', 'operator-1');

		self::assertIsArray($pack);
		self::assertSame('lease-v', $pack['sourceLease']);
		self::assertSame('operator-1', $pack['operatorId']);
		self::assertSame('pending-pdf-pipeline', $pack['status']);
		self::assertSame(['doc-1', 'doc-2'], $pack['ibrEvidence']);
		self::assertNotEmpty($pack['paymentSchedule']);
		self::assertSame(1, count($pack['reassessmentEvents']));

		$paths = array_column($pack['contents'], 'path');
		self::assertContains('index.md', $paths);
		self::assertContains('lease-contract.pdf', $paths);
		self::assertContains('schedule/lease-v-schedule.csv', $paths);
		self::assertContains('disclosure/disclosure.csv', $paths);
		self::assertContains('ibr-evidence/', $paths);
		self::assertContains('reassessments/', $paths);

		self::assertStringContainsString('/shillinq/audit-packs/', $pack['downloadPath']);
		self::assertStringEndsWith('/lease-v.zip', $pack['downloadPath']);

	}//end testGenerateBuildsContentsIndex()

	/**
	 * Out-of-scope lease returns null (ADR-005 IDOR safety).
	 *
	 * @return void
	 */
	public function testOutOfScopeLeaseReturnsNull(): void {
		$lease = [
			'@self' => ['slug' => 'lease-v', 'id' => 'lease-v'],
			'leaseNumber' => 'VH-2024-001',
			'administrationId' => 'adm-other',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
		];

		$pack = $this->buildService([$lease])->generate('lease-v', 'adm-1', 'operator-1');

		self::assertNull($pack);

	}//end testOutOfScopeLeaseReturnsNull()
}//end class
