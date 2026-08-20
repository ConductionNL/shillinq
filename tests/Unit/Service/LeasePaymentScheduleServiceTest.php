<?php

/**
 * Unit tests for LeasePaymentScheduleService.
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests schedule generation and the administration-scope (IDOR) guard (REQ-LA-002,
 * ADR-005): a capitalised lease writes one row per period; an out-of-scope or
 * exempt lease writes none.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeasePaymentScheduleServiceTest extends TestCase {

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
	 * Captured saveObject() calls from the stub.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service with an ObjectService stub over the given leases.
	 *
	 * @param array<int,array<string,mixed>> $leases LeaseContract records.
	 *
	 * @return LeasePaymentScheduleService
	 */
	private function buildService(array $leases): LeasePaymentScheduleService {
		$saved = &$this->saved;
		$stub = new class($leases, $saved) {

			/**
			 * Lease records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $leases;

			/**
			 * Reference to the captured saveObject payloads.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $leases Lease records.
			 * @param array<int,array<string,mixed>> $saved Capture sink (by reference).
			 */
			public function __construct(array $leases, array &$saved) {
				$this->leases = & $leases;
				$this->saved = & $saved;
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
			 * Active schema, remembered so a later saveObject() can report it.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return leases matching the administration filter.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$admin = ($params['filters']['administrationId'] ?? null);
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
			 * Capture a saved object together with the schema it went to.
			 *
			 * The schema may arrive as an explicit argument or through a
			 * preceding setSchema() call — the real ObjectService honours both,
			 * so the capture falls back to the active schema.
			 *
			 * @param array<string,mixed> $object The object payload.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved[] = [
					'object' => $object,
					'schema' => ($schema !== '') ? $schema : $this->schema,
				];
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturn($stub);

		return new LeasePaymentScheduleService(
			appConfig: $this->appConfig,
			calculator: new LeaseAmortizationCalculator(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * A capitalised lease writes one schedule row per period (REQ-LA-002).
	 *
	 * @return void
	 */
	public function testGeneratesOneRowPerPeriod(): void {
		$lease = $this->capitalisedLease();
		$count = $this->buildService([$lease])->generateSchedule('lease-1', 'adm-1');

		self::assertSame(36, $count);
		self::assertCount(36, $this->saved);
		self::assertSame('LeasePaymentSchedule', $this->saved[0]['schema']);
		self::assertSame('lease-1', $this->saved[0]['object']['leaseContract']);
		self::assertSame('adm-1', $this->saved[0]['object']['administrationId']);
		self::assertNull($this->saved[0]['object']['postedToGl']);

	}//end testGeneratesOneRowPerPeriod()

	/**
	 * A lease from another administration is invisible — IDOR-safe (ADR-005).
	 *
	 * @return void
	 */
	public function testOutOfScopeLeaseWritesNothing(): void {
		$lease = $this->capitalisedLease();
		// Same lease id but the caller passes a different administration scope.
		$count = $this->buildService([$lease])->generateSchedule('lease-1', 'adm-OTHER');

		self::assertSame(0, $count);
		self::assertCount(0, $this->saved);

	}//end testOutOfScopeLeaseWritesNothing()

	/**
	 * An exempt lease carries no schedule (REQ-LE-003).
	 *
	 * @return void
	 */
	public function testExemptLeaseWritesNothing(): void {
		$lease = $this->capitalisedLease();
		$lease['classification'] = 'short-term-exempt';

		$count = $this->buildService([$lease])->generateSchedule('lease-1', 'adm-1');
		self::assertSame(0, $count);
		self::assertCount(0, $this->saved);

	}//end testExemptLeaseWritesNothing()

	/**
	 * Regenerating from a later sequence writes only the forward rows (REQ-LA-002).
	 *
	 * @return void
	 */
	public function testRegenerateFromSequence(): void {
		$lease = $this->capitalisedLease();
		$count = $this->buildService([$lease])->generateSchedule('lease-1', 'adm-1', 25);

		// Periods 25..36 = 12 rows.
		self::assertSame(12, $count);
		self::assertSame(25, $this->saved[0]['object']['periodSequence']);

	}//end testRegenerateFromSequence()

	/**
	 * The read-only buildSchedule preview returns rows without persisting (REQ-LA-002).
	 *
	 * @return void
	 */
	public function testBuildScheduleDoesNotPersist(): void {
		$lease = $this->capitalisedLease();
		$rows = $this->buildService([$lease])->buildSchedule('lease-1', 'adm-1');

		self::assertCount(36, $rows);
		self::assertCount(0, $this->saved);

	}//end testBuildScheduleDoesNotPersist()

	/**
	 * A capitalised lease fixture with a slug id.
	 *
	 * @return array<string,mixed>
	 */
	private function capitalisedLease(): array {
		return [
			'@self' => ['slug' => 'lease-1'],
			'assetClass' => 'vehicle',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

	}//end capitalisedLease()
}//end class
