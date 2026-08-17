<?php

/**
 * Unit tests for WbsoMededelingGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\WbsoMededelingGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WbsoMededelingGuard.
 *
 * Covers REQ-WBSO-004 (administration scoping of the beschikking lookup) and
 * REQ-WBSO-005 (a mededeling may only be submitted when the realised S&O hours
 * do not exceed the covering beschikking's granted ceiling and that beschikking
 * is still in the `granted` state).
 */
class WbsoMededelingGuardTest extends TestCase {

	/**
	 * Administration id used across the fixtures.
	 *
	 * @var string
	 */
	private const ADMIN = 'adm-consultancy-nl';

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
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var WbsoMededelingGuard
	 */
	private WbsoMededelingGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new WbsoMededelingGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildObjectServiceStub(records: [])),
		);

	}//end setUp()

	/**
	 * Point the guard at the given duck-typed ObjectService store.
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param object $store The in-memory ObjectService double.
	 *
	 * @return void
	 */
	private function wireObjectService(object $store): void {
		$this->container->method('get')->willReturn($store);

		$this->guard = new WbsoMededelingGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * Build a beschikking record fixture for the current administration.
	 *
	 * @param int|float $grantedSoHours Granted ceiling in S&O hours.
	 * @param string $state Beschikking lifecycle state.
	 *
	 * @return array<string,mixed>
	 */
	private function decisionRecord(int|float $grantedSoHours, string $state): array {
		return [
			'decisionNumber' => 'WBSO-2026-0001',
			'grantedSoHours' => $grantedSoHours,
			'state' => $state,
			'administrationId' => self::ADMIN,
		];
	}//end beschikkingRecord()

	/**
	 * Build a mededeling object fixture.
	 *
	 * @param int|float $realisedSoHours Realised S&O hours reported.
	 * @param string $administrationId Owning administration id.
	 *
	 * @return array<string,mixed>
	 */
	private function mededelingObject(int|float $realisedSoHours, string $administrationId = self::ADMIN): array {
		return [
			'decisionNumber' => 'WBSO-2026-0001',
			'realisedSoHours' => $realisedSoHours,
			'administrationId' => $administrationId,
		];
	}//end mededelingObject()

	/**
	 * A realisatie below the granted ceiling on a granted beschikking may submit (REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testRealisatieBelowCeilingCanSubmit(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [$this->decisionRecord(grantedSoHours: 1200, state: 'granted')])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmit(mededelingId: 'med-1', object: $this->mededelingObject(realisedSoHours: 980)));

	}//end testRealisatieBelowCeilingCanSubmit()

	/**
	 * A realisatie exactly at the granted ceiling may submit — boundary case (REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testRealisatieAtCeilingCanSubmit(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [$this->decisionRecord(grantedSoHours: 1200.5, state: 'granted')])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmit(mededelingId: 'med-2', object: $this->mededelingObject(realisedSoHours: 1200.5)));

	}//end testRealisatieAtCeilingCanSubmit()

	/**
	 * A realisatie above the granted ceiling cannot submit (REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testRealisatieAboveCeilingCannotSubmit(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [$this->decisionRecord(grantedSoHours: 1200, state: 'granted')])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(mededelingId: 'med-3', object: $this->mededelingObject(realisedSoHours: 1201)));

	}//end testRealisatieAboveCeilingCannotSubmit()

	/**
	 * Submitting against an expired beschikking fails closed (REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testExpiredBeschikkingCannotSubmit(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [$this->decisionRecord(grantedSoHours: 1200, state: 'expired')])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(mededelingId: 'med-4', object: $this->mededelingObject(realisedSoHours: 100)));

	}//end testExpiredBeschikkingCannotSubmit()

	/**
	 * An unresolvable beschikking fails closed (REQ-WBSO-005 / CWE-863).
	 *
	 * @return void
	 */
	public function testUnknownBeschikkingFailsClosed(): void {
		$this->wireObjectService(store: $this->buildObjectServiceStub(records: []));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(mededelingId: 'med-5', object: $this->mededelingObject(realisedSoHours: 1)));

	}//end testUnknownBeschikkingFailsClosed()

	/**
	 * A beschikking belonging to another administration is not honoured (REQ-WBSO-004).
	 *
	 * The stub returns a beschikking owned by a different administration; the
	 * guard's in-loop tenant check must reject it even though the number matches.
	 *
	 * @return void
	 */
	public function testCrossTenantBeschikkingFailsClosed(): void {
		$foreign = [
			'decisionNumber' => 'WBSO-2026-0001',
			'grantedSoHours' => 5000,
			'state' => 'granted',
			'administrationId' => 'adm-other-bv',
		];

		$this->wireObjectService(store: $this->buildObjectServiceStub(records: [$foreign]));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canSubmit(
				mededelingId: 'med-tenant',
				object: $this->mededelingObject(realisedSoHours: 100, administrationId: self::ADMIN)
			)
		);

	}//end testCrossTenantBeschikkingFailsClosed()

	/**
	 * A missing beschikkingNumber fails closed (REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testMissingBeschikkingNumberFailsClosed(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canSubmit(
				mededelingId: 'med-6',
				object: ['realisedSoHours' => 100, 'administrationId' => self::ADMIN]
			)
		);

	}//end testMissingBeschikkingNumberFailsClosed()

	/**
	 * A missing administrationId fails closed (REQ-WBSO-004).
	 *
	 * @return void
	 */
	public function testMissingAdministrationIdFailsClosed(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canSubmit(
				mededelingId: 'med-adm',
				object: ['decisionNumber' => 'WBSO-2026-0001', 'realisedSoHours' => 100]
			)
		);

	}//end testMissingAdministrationIdFailsClosed()

	/**
	 * A null object fails closed (REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testNullObjectFailsClosed(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(mededelingId: 'med-7', object: null));

	}//end testNullObjectFailsClosed()

	/**
	 * A negative realised-hours figure fails closed (REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testNegativeRealisedHoursFailsClosed(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [$this->decisionRecord(grantedSoHours: 1200, state: 'granted')])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(mededelingId: 'med-8', object: $this->mededelingObject(realisedSoHours: -5)));

	}//end testNegativeRealisedHoursFailsClosed()

	/**
	 * An exception in the resolve path fails closed (returns false, logs error).
	 *
	 * @return void
	 */
	public function testSubmitExceptionFailsClosed(): void {
		$this->wireObjectService(store: $this->buildFailingObjectServiceStub());

		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(mededelingId: 'med-9', object: $this->mededelingObject(realisedSoHours: 100)));

	}//end testSubmitExceptionFailsClosed()

	/**
	 * Build an anonymous ObjectService stub returning the given records from findAll().
	 *
	 * @param array<mixed> $records Records to return.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $records): object {
		return new class($records) {
			/**
			 * Records to return from findAll().
			 *
			 * @var array<mixed>
			 */
			private array $records;

			/**
			 * Constructor.
			 *
			 * @param array<mixed> $records Records to return.
			 */
			public function __construct(array $records) {
				$this->records = $records;
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
				return $this;
			}//end setSchema()

			/**
			 * Return all stubbed records.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->records;
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Build an ObjectService store that refuses every read.
	 *
	 * Since the store is injected rather than pulled from the container, an
	 * unavailable OpenRegister is modelled by a store that throws.
	 *
	 * @return object
	 */
	private function buildFailingObjectServiceStub(): object {
		return new class {

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
			 * @param string $schema Schema slug (unused).
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse the read, as an unavailable ObjectService would.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()
		};
	}//end buildFailingObjectServiceStub()
}//end class
