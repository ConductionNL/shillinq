<?php

/**
 * Unit tests for SubsidieVerantwoordingGuard.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\SubsidieVerantwoordingGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-SUBV-003: approval of a SubsidieVerantwoording is blocked while a
 * large grant has no approved/conditional AuditorStatement.
 */
class SubsidieVerantwoordingGuardTest extends TestCase {
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
	 * @var SubsidieVerantwoordingGuard
	 */
	private SubsidieVerantwoordingGuard $guard;

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

		// Default register slug; auditor_threshold unset -> default 25k.
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return $default;
			}
		);

		$this->guard = new SubsidieVerantwoordingGuard(
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

		$this->guard = new SubsidieVerantwoordingGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * A grant below the auditor threshold may approve without any AuditorStatement (REQ-SUBV-003).
	 *
	 * @return void
	 */
	public function testSmallGrantApprovesWithoutAuditorStatement(): void {
		$object = ['grantId' => 'SUB-1', 'awardedAmount' => 10000.0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApprove(accountabilityId: 'sv-1', object: $object));

	}//end testSmallGrantApprovesWithoutAuditorStatement()

	/**
	 * A large grant with a pending AuditorStatement is BLOCKED from approval (REQ-SUBV-003).
	 *
	 * @return void
	 */
	public function testLargeGrantBlockedByPendingAuditorStatement(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [['statementId' => 'AS-1', 'status' => 'pending']])
		);

		$object = ['grantId' => 'SUB-2', 'awardedAmount' => 50000.0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApprove(accountabilityId: 'sv-2', object: $object));

	}//end testLargeGrantBlockedByPendingAuditorStatement()

	/**
	 * A large grant with an approved AuditorStatement may approve (REQ-SUBV-003).
	 *
	 * @return void
	 */
	public function testLargeGrantWithApprovedAuditorStatementApproves(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [['statementId' => 'AS-1', 'status' => 'approved']])
		);

		$object = ['grantId' => 'SUB-3', 'awardedAmount' => 50000.0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApprove(accountabilityId: 'sv-3', object: $object));

	}//end testLargeGrantWithApprovedAuditorStatementApproves()

	/**
	 * A large grant with a conditional AuditorStatement may approve (REQ-SUBV-005).
	 *
	 * @return void
	 */
	public function testLargeGrantWithConditionalAuditorStatementApproves(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [['statementId' => 'AS-1', 'status' => 'conditional']])
		);

		$object = ['grantId' => 'SUB-4', 'awardedAmount' => 30000.0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApprove(accountabilityId: 'sv-4', object: $object));

	}//end testLargeGrantWithConditionalAuditorStatementApproves()

	/**
	 * A large grant with a rejected AuditorStatement is blocked (REQ-SUBV-003).
	 *
	 * @return void
	 */
	public function testLargeGrantWithRejectedAuditorStatementBlocked(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [['statementId' => 'AS-1', 'status' => 'rejected']])
		);

		$object = ['grantId' => 'SUB-5', 'awardedAmount' => 99000.0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApprove(accountabilityId: 'sv-5', object: $object));

	}//end testLargeGrantWithRejectedAuditorStatementBlocked()

	/**
	 * A large grant with no grantId fails closed (REQ-SUBV-003 / CWE-863).
	 *
	 * @return void
	 */
	public function testLargeGrantWithoutGrantIdFailsClosed(): void {
		$object = ['grantId' => '', 'awardedAmount' => 50000.0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApprove(accountabilityId: 'sv-6', object: $object));

	}//end testLargeGrantWithoutGrantIdFailsClosed()

	/**
	 * An exception while resolving auditor statements fails closed (CWE-863).
	 *
	 * @return void
	 */
	public function testExceptionFailsClosed(): void {
		$this->wireObjectService(store: $this->buildFailingObjectServiceStub());

		$this->logger->expects($this->once())->method('error');

		$object = ['grantId' => 'SUB-7', 'awardedAmount' => 50000.0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApprove(accountabilityId: 'sv-7', object: $object));

	}//end testExceptionFailsClosed()

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
