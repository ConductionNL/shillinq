<?php

/**
 * Unit tests for BcfClaimGuard.
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
 * @spec openspec/changes/bookkeeping-bcf-vat-compensation/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BcfClaimGuard;
use OCA\Shillinq\Service\BcfClaimService;
use OCA\Shillinq\Service\BcfCompensationCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BcfClaimGuard.
 *
 * Covers REQ-BCF-003: a draft claim may only transition to submitted when the
 * server-recomputed compensable total is positive AND the claim quarter's
 * fiscal period is closed. The guard fails closed on any error.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BcfClaimGuardTest extends TestCase {

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
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build a guard whose ObjectService stub returns the given per-schema records.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Schema slug => records.
	 *
	 * @return BcfClaimGuard
	 */
	private function guardWith(array $recordsBySchema): BcfClaimGuard {
		$store = $this->buildObjectServiceStub($recordsBySchema);
		$this->container->method('get')->willReturn($store);

		$calculator = new BcfCompensationCalculator();
		$service = new BcfClaimService(
			appConfig: $this->appConfig,
			calculator: $calculator,
			objectService: new DuckObjectServiceAdapter($store),
		);

		return new BcfClaimGuard(
			appConfig: $this->appConfig,
			claimService: $service,
			calculator: $calculator,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end guardWith()

	/**
	 * A non-empty claim with a closed quarter may submit (REQ-BCF-003).
	 *
	 * @return void
	 */
	public function testCanSubmitWhenNonEmptyAndQuarterClosed(): void {
		$guard = $this->guardWith(
			[
				'GLTransaction' => [['id' => 'tx-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1']],
				'GLLine' => [
					['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 100000.0, 'periodId' => '2026-Q1'],
				],
				'BbvAccountMapping' => [
					['administrationId' => 'adm-1', 'accountNumber' => '3610', 'bcfCompensable' => true, 'compensablePercentage' => 100],
				],
				'FiscalPeriod' => [['administrationId' => 'adm-1', 'periodId' => '2026-Q1', 'status' => 'closed']],
			]
		);

		self::assertTrue(
			$guard->canSubmit(
				bcfClaimId: 'claim-1',
				object: ['administrationId' => 'adm-1', 'claimQuarter' => '2026-Q1']
			)
		);

	}//end testCanSubmitWhenNonEmptyAndQuarterClosed()

	/**
	 * An empty claim (no compensable postings) cannot submit even when the quarter is closed (REQ-BCF-003).
	 *
	 * @return void
	 */
	public function testCannotSubmitEmptyClaim(): void {
		$guard = $this->guardWith(
			[
				'GLTransaction' => [['id' => 'tx-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1']],
				'GLLine' => [
					['transactionId' => 'tx-1', 'accountNumber' => '3650', 'side' => 'debit', 'amount' => 50000.0, 'periodId' => '2026-Q1'],
				],
				'BbvAccountMapping' => [
					['administrationId' => 'adm-1', 'accountNumber' => '3650', 'bcfCompensable' => false, 'compensablePercentage' => 0],
				],
				'FiscalPeriod' => [['administrationId' => 'adm-1', 'periodId' => '2026-Q1', 'status' => 'closed']],
			]
		);

		self::assertFalse(
			$guard->canSubmit(
				bcfClaimId: 'claim-1',
				object: ['administrationId' => 'adm-1', 'claimQuarter' => '2026-Q1']
			)
		);

	}//end testCannotSubmitEmptyClaim()

	/**
	 * A non-empty claim for an OPEN quarter cannot submit (REQ-BCF-003 period-lock).
	 *
	 * @return void
	 */
	public function testCannotSubmitWhenQuarterOpen(): void {
		$guard = $this->guardWith(
			[
				'GLTransaction' => [['id' => 'tx-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1']],
				'GLLine' => [
					['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 100000.0, 'periodId' => '2026-Q1'],
				],
				'BbvAccountMapping' => [
					['administrationId' => 'adm-1', 'accountNumber' => '3610', 'bcfCompensable' => true, 'compensablePercentage' => 100],
				],
				// Period exists but is open.
				'FiscalPeriod' => [['administrationId' => 'adm-1', 'periodId' => '2026-Q1', 'status' => 'open']],
			]
		);

		self::assertFalse(
			$guard->canSubmit(
				bcfClaimId: 'claim-1',
				object: ['administrationId' => 'adm-1', 'claimQuarter' => '2026-Q1']
			)
		);

	}//end testCannotSubmitWhenQuarterOpen()

	/**
	 * A missing fiscal period is treated as not closed (fail-closed, REQ-BCF-003).
	 *
	 * @return void
	 */
	public function testCannotSubmitWhenNoFiscalPeriod(): void {
		$guard = $this->guardWith(
			[
				'GLTransaction' => [['id' => 'tx-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q1']],
				'GLLine' => [
					['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 100000.0, 'periodId' => '2026-Q1'],
				],
				'BbvAccountMapping' => [
					['administrationId' => 'adm-1', 'accountNumber' => '3610', 'bcfCompensable' => true, 'compensablePercentage' => 100],
				],
				'FiscalPeriod' => [],
			]
		);

		self::assertFalse(
			$guard->canSubmit(
				bcfClaimId: 'claim-1',
				object: ['administrationId' => 'adm-1', 'claimQuarter' => '2026-Q1']
			)
		);

	}//end testCannotSubmitWhenNoFiscalPeriod()

	/**
	 * A claim object missing administration/quarter cannot submit (REQ-BCF-010).
	 *
	 * @return void
	 */
	public function testCannotSubmitWithoutScope(): void {
		$guard = $this->guardWith(['BcfClaim' => []]);

		self::assertFalse($guard->canSubmit(bcfClaimId: '', object: ['claimQuarter' => '2026-Q1']));

	}//end testCannotSubmitWithoutScope()

	/**
	 * An exception in the submit path fails closed (returns false, logs error).
	 *
	 * @return void
	 */
	public function testSubmitExceptionFailsClosed(): void {
		$store = $this->buildUnavailableObjectServiceStub();
		$this->container->method('get')->willThrowException(new \RuntimeException('ObjectService unavailable'));
		$this->logger->expects($this->once())->method('error');

		$calculator = new BcfCompensationCalculator();
		$service = new BcfClaimService(
			appConfig: $this->appConfig,
			calculator: $calculator,
			objectService: new DuckObjectServiceAdapter($store),
		);
		$guard = new BcfClaimGuard(
			appConfig: $this->appConfig,
			claimService: $service,
			calculator: $calculator,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

		self::assertFalse(
			$guard->canSubmit(
				bcfClaimId: 'claim-1',
				object: ['administrationId' => 'adm-1', 'claimQuarter' => '2026-Q1']
			)
		);

	}//end testSubmitExceptionFailsClosed()

	/**
	 * Build an ObjectService stub that returns per-schema records based on setSchema().
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Schema slug => records to return from findAll().
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<mixed>>
			 */
			private array $recordsBySchema;

			/**
			 * Currently selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;
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
			 * Fluent schema setter — records the active schema.
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
			 * Return the records for the active schema.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->schema] ?? []);
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Build a store that models an unavailable OpenRegister.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself; every
	 * read throws exactly as a downed ObjectService would.
	 *
	 * @return object
	 */
	private function buildUnavailableObjectServiceStub(): object {
		return new class {
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
			 * @param string|int $id Object id.
			 *
			 * @return ?object
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('ObjectService unavailable');
			}//end find()

			/**
			 * Refuse every write.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function saveObject(array $object): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end saveObject()
		};

	}//end buildUnavailableObjectServiceStub()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
