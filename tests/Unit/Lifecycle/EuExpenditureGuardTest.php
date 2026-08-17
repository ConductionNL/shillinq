<?php

/**
 * Unit tests for EuExpenditureGuard.
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
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\EuExpenditureGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/GuardObjectServiceStub.php';

/**
 * Tests for EuExpenditureGuard.
 *
 * Covers REQ-EUF-011 (cost-eligibility on declare) and REQ-EUF-004/005
 * (verplichte bewijsstukken + aanbestedingsdossier on submit).
 */
class EuExpenditureGuardTest extends TestCase {

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
	 * @var EuExpenditureGuard
	 */
	private EuExpenditureGuard $guard;

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

		$this->guard = $this->buildGuard(store: GuardObjectServiceStub::make([]));
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
	 * @return EuExpenditureGuard
	 */
	private function buildGuard(object $store): EuExpenditureGuard {
		return new EuExpenditureGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end buildGuard()

	/**
	 * Rebuild the guard over a schema-aware ObjectService stub.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
	 *
	 * @return void
	 */
	private function wireObjectService(array $recordsBySchema): void {
		$this->guard = $this->buildGuard(store: GuardObjectServiceStub::make($recordsBySchema));
	}//end wireObjectService()

	/**
	 * An eligible, confirmed expenditure may be declared (REQ-EUF-011).
	 *
	 * @return void
	 */
	public function testEligibleConfirmedExpenditureCanDeclare(): void {
		$this->wireObjectService(
			[
				'EuProject' => [['id' => 'eu-1', 'fonds' => 'ERDF']],
				'EligibilityRule' => [['fonds' => 'ERDF', 'state' => 'active', 'applicableCostCategories' => ['externe_dienstverlening']]],
			]
		);

		$object = [
			'id' => 'exp-1',
			'euProjectId' => 'eu-1',
			'costCategory' => 'externe_dienstverlening',
			'eligibilityConfirmed' => true,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canDeclare(euExpenditureId: 'exp-1', object: $object));
	}//end testEligibleConfirmedExpenditureCanDeclare()

	/**
	 * A non-eligible cost-category is blocked at declare (REQ-EUF-011 scenario 2).
	 *
	 * @return void
	 */
	public function testNonEligibleCostCategoryCannotDeclare(): void {
		$this->wireObjectService(
			[
				'EuProject' => [['id' => 'eu-1', 'fonds' => 'ESF+']],
				'EligibilityRule' => [['fonds' => 'ESF+', 'state' => 'active', 'applicableCostCategories' => ['personeel', 'externe_dienstverlening']]],
			]
		);

		// kapitaal is not in the ESF+ applicable categories.
		$object = [
			'id' => 'exp-2',
			'euProjectId' => 'eu-1',
			'costCategory' => 'kapitaal',
			'eligibilityConfirmed' => true,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canDeclare(euExpenditureId: 'exp-2', object: $object));
	}//end testNonEligibleCostCategoryCannotDeclare()

	/**
	 * An unconfirmed-eligibility expenditure cannot declare (REQ-EUF-011).
	 *
	 * @return void
	 */
	public function testUnconfirmedEligibilityCannotDeclare(): void {
		$object = [
			'id' => 'exp-3',
			'euProjectId' => 'eu-1',
			'costCategory' => 'externe_dienstverlening',
			'eligibilityConfirmed' => false,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canDeclare(euExpenditureId: 'exp-3', object: $object));
	}//end testUnconfirmedEligibilityCannotDeclare()

	/**
	 * Submit succeeds when every verplicht bewijsstuk for the cost-category is present (REQ-EUF-004).
	 *
	 * @return void
	 */
	public function testSubmitSucceedsWithCompleteEvidence(): void {
		$this->wireObjectService(
			[
				'EuProject' => [['id' => 'eu-1', 'fonds' => 'ERDF']],
				'EligibilityRule' => [['fonds' => 'ERDF', 'state' => 'active', 'evidenceRequired' => ['personeel' => ['contract', 'salaris_specificatie', 'urenstaat']]]],
				'SupportingDocument' => [
					['documentType' => 'contract'],
					['documentType' => 'salaris_specificatie'],
					['documentType' => 'urenstaat'],
				],
			]
		);

		$object = [
			'id' => 'exp-4',
			'euProjectId' => 'eu-1',
			'costCategory' => 'personeel',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmit(euExpenditureId: 'exp-4', object: $object));
	}//end testSubmitSucceedsWithCompleteEvidence()

	/**
	 * Submit is blocked when a verplicht bewijsstuk is missing (REQ-EUF-004).
	 *
	 * @return void
	 */
	public function testSubmitBlockedOnMissingEvidence(): void {
		$this->wireObjectService(
			[
				'EuProject' => [['id' => 'eu-1', 'fonds' => 'ERDF']],
				'EligibilityRule' => [['fonds' => 'ERDF', 'state' => 'active', 'evidenceRequired' => ['personeel' => ['contract', 'salaris_specificatie', 'urenstaat']]]],
				'SupportingDocument' => [
					['documentType' => 'contract'],
					['documentType' => 'urenstaat'],
				],
			]
		);

		$object = [
			'id' => 'exp-5',
			'euProjectId' => 'eu-1',
			'costCategory' => 'personeel',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(euExpenditureId: 'exp-5', object: $object));
	}//end testSubmitBlockedOnMissingEvidence()

	/**
	 * An aanbestedingsplichtige uitgave is blocked without an aanbestedingsdossier (REQ-EUF-005).
	 *
	 * @return void
	 */
	public function testSubmitBlockedWithoutProcurementDossier(): void {
		$this->wireObjectService(
			[
				'EuProject' => [['id' => 'eu-1', 'fonds' => 'ERDF']],
				'EligibilityRule' => [['fonds' => 'ERDF', 'state' => 'active', 'evidenceRequired' => ['kapitaal' => ['factuur', 'betaalbewijs']]]],
				'SupportingDocument' => [
					['documentType' => 'factuur'],
					['documentType' => 'betaalbewijs'],
				],
			]
		);

		$object = [
			'id' => 'exp-6',
			'euProjectId' => 'eu-1',
			'costCategory' => 'kapitaal',
			'procurementRequired' => true,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmit(euExpenditureId: 'exp-6', object: $object));
	}//end testSubmitBlockedWithoutProcurementDossier()

	/**
	 * An aanbestedingsplichtige uitgave with a complete dossier may submit (REQ-EUF-005).
	 *
	 * @return void
	 */
	public function testSubmitSucceedsWithProcurementDossier(): void {
		$this->wireObjectService(
			[
				'EuProject' => [['id' => 'eu-1', 'fonds' => 'ERDF']],
				'EligibilityRule' => [['fonds' => 'ERDF', 'state' => 'active', 'evidenceRequired' => ['kapitaal' => ['factuur', 'betaalbewijs']]]],
				'SupportingDocument' => [
					['documentType' => 'factuur'],
					['documentType' => 'betaalbewijs'],
					['documentType' => 'aanbestedingsdossier'],
				],
			]
		);

		$object = [
			'id' => 'exp-7',
			'euProjectId' => 'eu-1',
			'costCategory' => 'kapitaal',
			'procurementRequired' => true,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmit(euExpenditureId: 'exp-7', object: $object));
	}//end testSubmitSucceedsWithProcurementDossier()

	/**
	 * An exception in the declare path fails closed (REQ-EUF-011 / CWE-863).
	 *
	 * @return void
	 */
	public function testDeclareExceptionFailsClosed(): void {
		$this->guard = $this->buildGuard(store: $this->buildUnavailableObjectServiceStub());
		$this->logger->expects($this->once())->method('error');

		$object = [
			'id' => 'exp-8',
			'euProjectId' => 'eu-1',
			'costCategory' => 'personeel',
			'eligibilityConfirmed' => true,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canDeclare(euExpenditureId: 'exp-8', object: $object));
	}//end testDeclareExceptionFailsClosed()

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
				throw new \RuntimeException('ObjectService down');
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
				throw new \RuntimeException('ObjectService down');
			}//end find()
		};
	}//end buildUnavailableObjectServiceStub()
}//end class
