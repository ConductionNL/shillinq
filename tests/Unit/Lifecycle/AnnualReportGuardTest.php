<?php

/**
 * Unit tests for AnnualReportGuard.
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
 * @spec openspec/changes/bookkeeping-titel-9-jaarrekening/specs/bookkeeping-titel-9-jaarrekening/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\AnnualReportGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AnnualReportGuard.
 *
 * Covers REQ-T9-002/REQ-T9-010 (opmaak requires a balancing balans) and
 * REQ-T9-007/REQ-T9-009 (vaststellen requires an accountantsverklaring when
 * it is wettelijk verplicht).
 */
class AnnualReportGuardTest extends TestCase {

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
	 * @var AnnualReportGuard
	 */
	private AnnualReportGuard $guard;

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

		$this->guard = $this->buildGuard(store: $this->buildObjectServiceStub(records: []));

	}//end setUp()

	/**
	 * Build the guard over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the guard is built — parking it on the
	 * container after the fact leaves the guard reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return AnnualReportGuard
	 */
	private function buildGuard(object $store): AnnualReportGuard {
		return new AnnualReportGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * A jaarrekening whose linked balans balances (activa = passiva) may opmaken (REQ-T9-002).
	 *
	 * @return void
	 */
	public function testCanOpmakenWhenBalansBalances(): void {
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(records: [['reportId' => 'r-1', 'totalAssets' => 845000, 'totalLiabilities' => 845000]])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canOpmaken(annualReportId: 'r-1', object: ['id' => 'r-1']));

	}//end testCanOpmakenWhenBalansBalances()

	/**
	 * A jaarrekening whose balans does not balance cannot opmaken (REQ-T9-002).
	 *
	 * @return void
	 */
	public function testCannotOpmakenWhenBalansUnbalanced(): void {
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(records: [['reportId' => 'r-2', 'totalAssets' => 845000, 'totalLiabilities' => 800000]])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-2', object: ['id' => 'r-2']));

	}//end testCannotOpmakenWhenBalansUnbalanced()

	/**
	 * Balans balance can be derived from rubriek sums when totals are absent (REQ-T9-002).
	 *
	 * @return void
	 */
	public function testCanOpmakenFromRubriekSums(): void {
		$balanceSheet = [
			'reportId' => 'r-3',
			'rubrieken' => [
				['rubrieckCode' => 'B.II', 'side' => 'assets', 'currentYear' => 450000],
				['rubrieckCode' => 'C.IV', 'side' => 'assets', 'currentYear' => 95000],
				['rubrieckCode' => 'A', 'side' => 'liabilities', 'currentYear' => 400000],
				['rubrieckCode' => 'D', 'side' => 'liabilities', 'currentYear' => 145000],
			],
		];

		$this->guard = $this->buildGuard(store: $this->buildObjectServiceStub(records: [$balanceSheet]));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canOpmaken(annualReportId: 'r-3', object: ['id' => 'r-3']));

	}//end testCanOpmakenFromRubriekSums()

	/**
	 * Opmaak is denied when no linked BalanceSheet exists (fail-closed, REQ-T9-002).
	 *
	 * @return void
	 */
	public function testCannotOpmakenWithoutBalanceSheet(): void {
		$this->guard = $this->buildGuard(store: $this->buildObjectServiceStub(records: []));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-4', object: ['id' => 'r-4']));

	}//end testCannotOpmakenWithoutBalanceSheet()

	/**
	 * A zero-total balans cannot opmaken (REQ-T9-002 — a real balans has value).
	 *
	 * @return void
	 */
	public function testZeroTotalBalansCannotOpmaken(): void {
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(records: [['reportId' => 'r-5', 'totalAssets' => 0, 'totalLiabilities' => 0]])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-5', object: ['id' => 'r-5']));

	}//end testZeroTotalBalansCannotOpmaken()

	/**
	 * An exception in the opmaak path fails closed (returns false, logs error).
	 *
	 * @return void
	 */
	public function testOpmaakExceptionFailsClosed(): void {
		$this->guard = $this->buildGuard(store: $this->buildUnavailableObjectServiceStub());

		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-6', object: ['id' => 'r-6']));

	}//end testOpmaakExceptionFailsClosed()

	/**
	 * When no accountantsverklaring is verplicht, vaststellen is allowed (REQ-T9-009).
	 *
	 * @return void
	 */
	public function testCanVaststellenWhenVerklaringNotRequired(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$this->guard->canVaststellen(
				annualReportId: 'r-7',
				object: ['auditorsStatementRequired' => false]
			)
		);

	}//end testCanVaststellenWhenVerklaringNotRequired()

	/**
	 * When a verklaring is verplicht and goedkeurend is attached, vaststellen is allowed (REQ-T9-007).
	 *
	 * @return void
	 */
	public function testCanVaststellenWhenVerklaringAttached(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$this->guard->canVaststellen(
				annualReportId: 'r-8',
				object: [
					'auditorsStatementRequired' => true,
					'auditorsStatementStatus' => 'goedkeurend',
				]
			)
		);

	}//end testCanVaststellenWhenVerklaringAttached()

	/**
	 * When a verklaring is verplicht but none is attached, vaststellen is denied (REQ-T9-007).
	 *
	 * @return void
	 */
	public function testCannotVaststellenWhenVerklaringMissing(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canVaststellen(
				annualReportId: 'r-9',
				object: [
					'auditorsStatementRequired' => true,
					'auditorsStatementStatus' => 'in-afwachting',
				]
			)
		);

	}//end testCannotVaststellenWhenVerklaringMissing()

	/**
	 * A samenstellingsverklaring satisfies a kleine-BV vaststelling (REQ-T9-007).
	 *
	 * @return void
	 */
	public function testSamenstellingVerklaringSatisfiesVaststelling(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$this->guard->canVaststellen(
				annualReportId: 'r-10',
				object: [
					'auditorsStatementRequired' => true,
					'auditorsStatementStatus' => 'samenstelling',
				]
			)
		);

	}//end testSamenstellingVerklaringSatisfiesVaststelling()

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
	 * Build a store that models an unavailable OpenRegister.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself; every
	 * read throws exactly as a downed ObjectService would, which is what the
	 * guard's fail-closed arm is there to catch.
	 *
	 * @return object
	 */
	private function buildUnavailableObjectServiceStub(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()

			/**
			 * Refuse every single-object lookup.
			 *
			 * @param string|int $id Object ID.
			 *
			 * @return object|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('ObjectService unavailable');
			}//end find()
		};
	}//end buildUnavailableObjectServiceStub()
}//end class
