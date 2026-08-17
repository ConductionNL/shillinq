<?php

/**
 * Unit tests for IntercompanyMatchingService.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IntercompanyMatchingCalculator;
use OCA\Shillinq\Service\IntercompanyMatchingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the orchestrating intercompany matching service.
 *
 * Covers detection classification (REQ-ICE-002), per-relation matching with
 * elimination/mismatch routing (REQ-ICE-003/006/005) and roll-forward consistency
 * (REQ-ICE-008). The real calculator is used (pure logic); the ObjectService is a
 * recording stub so persisted side-effects can be asserted.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntercompanyMatchingServiceTest extends TestCase {

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
	 * Build the service with a given ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub to inject via the container.
	 *
	 * @return IntercompanyMatchingService
	 */
	private function service(object $objectService): IntercompanyMatchingService {
		$this->container->method('get')->willReturn($objectService);
		return new IntercompanyMatchingService(
			$this->appConfig,
			new IntercompanyMatchingCalculator(),
			$this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);

	}//end service()

	/**
	 * Explicit marking yields explicitly-marked / high confidence (REQ-ICE-002).
	 *
	 * @return void
	 */
	public function testClassifyDetectionExplicit(): void {
		$svc = $this->service($this->buildStub());
		$result = $svc->classifyDetection(
			['glAccount' => '1300', 'description' => 'Holding BV invoice'],
			['8200'],
			['Holding BV' => 'ent-h'],
			true
		);
		self::assertSame('explicitly-marked', $result['detectionMethod']);
		self::assertSame('high', $result['detectionConfidence']);
		self::assertSame('ent-h', $result['counterpartyEntityId']);

	}//end testClassifyDetectionExplicit()

	/**
	 * A line on a registered IC account is account-based / high (REQ-ICE-002).
	 *
	 * @return void
	 */
	public function testClassifyDetectionAccountBased(): void {
		$svc = $this->service($this->buildStub());
		$result = $svc->classifyDetection(
			['glAccount' => '8200', 'description' => 'Sale to Holding BV'],
			['8200', '4400'],
			['Holding BV' => 'ent-h'],
			false
		);
		self::assertSame('account-based', $result['detectionMethod']);
		self::assertSame('high', $result['detectionConfidence']);
		self::assertSame('ent-h', $result['counterpartyEntityId']);

	}//end testClassifyDetectionAccountBased()

	/**
	 * A non-IC account but a name match is label-based / medium (REQ-ICE-002).
	 *
	 * @return void
	 */
	public function testClassifyDetectionLabelBased(): void {
		$svc = $this->service($this->buildStub());
		$result = $svc->classifyDetection(
			['glAccount' => '1300', 'description' => 'Payment from Holding BV'],
			['8200'],
			['Holding BV' => 'ent-h'],
			false
		);
		self::assertSame('label-based', $result['detectionMethod']);
		self::assertSame('medium', $result['detectionConfidence']);
		self::assertSame('ent-h', $result['counterpartyEntityId']);

	}//end testClassifyDetectionLabelBased()

	/**
	 * No account and no name match is label-based / low with null counterparty.
	 *
	 * @return void
	 */
	public function testClassifyDetectionLow(): void {
		$svc = $this->service($this->buildStub());
		$result = $svc->classifyDetection(
			['glAccount' => '1300', 'description' => 'External customer'],
			['8200'],
			['Holding BV' => 'ent-h'],
			false
		);
		self::assertSame('label-based', $result['detectionMethod']);
		self::assertSame('low', $result['detectionConfidence']);
		self::assertNull($result['counterpartyEntityId']);

	}//end testClassifyDetectionLow()

	/**
	 * A perfect match persists a match plus a balanced elimination (REQ-ICE-003/006).
	 *
	 * @return void
	 */
	public function testMatchRelationPeriodPerfectGeneratesElimination(): void {
		$stub = $this->buildStub(
			relations: [
				[
					'relationId' => 'rel1',
					'administrationId' => 'adm1',
					'entityAId' => 'entA',
					'entityBId' => 'entB',
					'defaultAccountA' => '8200',
					'defaultAccountB' => '4400',
					'toleranceAbsolute' => 10.0,
					'toleranceRelative' => 0.5,
				],
			],
			transactions: [
				['id' => 't1', 'sourceAdministrationId' => 'entA', 'debitAmount' => 100000.0, 'creditAmount' => 0.0, 'currency' => 'EUR'],
				['id' => 't2', 'sourceAdministrationId' => 'entB', 'debitAmount' => 100000.0, 'creditAmount' => 0.0, 'currency' => 'EUR'],
			]
		);

		$svc = $this->service($stub);
		$match = $svc->matchRelationPeriod('rel1', 'period-1');

		self::assertSame('perfect-match', $match['matchStatus']);
		self::assertSame(100000.0, $match['totalAmountA']);
		self::assertSame(100000.0, $match['totalAmountB']);
		self::assertContains('IntercompanyMatch', $stub->savedSchemas);
		self::assertContains('EliminationJournal', $stub->savedSchemas);
		self::assertNotContains('IntercompanyMismatch', $stub->savedSchemas);

	}//end testMatchRelationPeriodPerfectGeneratesElimination()

	/**
	 * An outside-tolerance match raises a mismatch and no elimination (REQ-ICE-005).
	 *
	 * @return void
	 */
	public function testMatchRelationPeriodOutsideRaisesMismatch(): void {
		$stub = $this->buildStub(
			relations: [
				[
					'relationId' => 'rel1',
					'administrationId' => 'adm1',
					'entityAId' => 'entA',
					'entityBId' => 'entB',
					'defaultAccountA' => '8200',
					'defaultAccountB' => '4400',
					'toleranceAbsolute' => 10.0,
					'toleranceRelative' => 0.5,
				],
			],
			transactions: [
				['id' => 't1', 'sourceAdministrationId' => 'entA', 'debitAmount' => 100000.0, 'creditAmount' => 0.0, 'currency' => 'EUR'],
				['id' => 't2', 'sourceAdministrationId' => 'entB', 'debitAmount' => 75000.0, 'creditAmount' => 0.0, 'currency' => 'EUR'],
			]
		);

		$svc = $this->service($stub);
		$match = $svc->matchRelationPeriod('rel1', 'period-1');

		self::assertSame('outside-tolerance', $match['matchStatus']);
		self::assertContains('IntercompanyMismatch', $stub->savedSchemas);
		self::assertNotContains('EliminationJournal', $stub->savedSchemas);

	}//end testMatchRelationPeriodOutsideRaisesMismatch()

	/**
	 * Roll-forward is consistent when prior closing equals current opening (REQ-ICE-008).
	 *
	 * @return void
	 */
	public function testRollForwardConsistent(): void {
		$stub = $this->buildStub();
		$stub->matchesByPeriod = [
			'q1' => [['totalAmountA' => 15000.0, 'totalAmountB' => 0.0]],
			'q2' => [['totalAmountA' => 15000.0, 'totalAmountB' => 0.0]],
		];
		$svc = $this->service($stub);
		self::assertTrue($svc->isRollForwardConsistent('rel1', 'q1', 'q2'));

	}//end testRollForwardConsistent()

	/**
	 * A backdated change breaks roll-forward consistency (REQ-ICE-008).
	 *
	 * @return void
	 */
	public function testRollForwardInconsistent(): void {
		$stub = $this->buildStub();
		$stub->matchesByPeriod = [
			'q1' => [['totalAmountA' => 15000.0, 'totalAmountB' => 0.0]],
			'q2' => [['totalAmountA' => 12000.0, 'totalAmountB' => 0.0]],
		];
		$svc = $this->service($stub);
		self::assertFalse($svc->isRollForwardConsistent('rel1', 'q1', 'q2'));

	}//end testRollForwardInconsistent()

	/**
	 * With no prior period the roll-forward is trivially consistent.
	 *
	 * @return void
	 */
	public function testRollForwardNoPriorPeriod(): void {
		$stub = $this->buildStub();
		$stub->matchesByPeriod = ['q2' => [['totalAmountA' => 12000.0, 'totalAmountB' => 0.0]]];
		$svc = $this->service($stub);
		self::assertTrue($svc->isRollForwardConsistent('rel1', 'q1', 'q2'));

	}//end testRollForwardNoPriorPeriod()

	/**
	 * Build a recording ObjectService stub.
	 *
	 * @param array<mixed> $relations IntercompanyRelation rows for findAll.
	 * @param array<mixed> $transactions IntercompanyTransaction rows for findAll.
	 *
	 * @return object
	 */
	private function buildStub(array $relations = [], array $transactions = []): object {
		return new class($relations, $transactions) {
			/**
			 * Relation rows.
			 *
			 * @var array<mixed>
			 */
			public array $relations;

			/**
			 * Transaction rows.
			 *
			 * @var array<mixed>
			 */
			public array $transactions;

			/**
			 * Match rows keyed by periodId (for roll-forward tests).
			 *
			 * @var array<string,array<mixed>>
			 */
			public array $matchesByPeriod = [];

			/**
			 * Schemas that received a saveObject call, in order.
			 *
			 * @var array<int,string>
			 */
			public array $savedSchemas = [];

			/**
			 * The schema last selected via setSchema().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * The last filters passed to findAll().
			 *
			 * @var array<string,mixed>
			 */
			private array $filters = [];

			/**
			 * Constructor.
			 *
			 * @param array<mixed> $relations Relation rows.
			 * @param array<mixed> $transactions Transaction rows.
			 */
			public function __construct(array $relations, array $transactions) {
				$this->relations = $relations;
				$this->transactions = $transactions;
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
			 * Fluent schema setter recording the selected schema.
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
			 * Return rows matching the selected schema.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				$this->filters = ($params['filters'] ?? []);
				if ($this->schema === 'IntercompanyRelation') {
					return $this->relations;
				}

				if ($this->schema === 'IntercompanyTransaction') {
					return $this->transactions;
				}

				if ($this->schema === 'IntercompanyMatch') {
					$period = (string)($this->filters['periodId'] ?? '');
					return ($this->matchesByPeriod[$period] ?? []);
				}

				return [];
			}//end findAll()

			/**
			 * Record the saved schema and echo the object back.
			 *
			 * @param array<string,mixed> $object The object being saved.
			 * @param string|null $register The register slug (unused).
			 * @param string|null $schema The schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				$this->savedSchemas[] = ($schema ?? $this->schema);
				return $object;
			}//end saveObject()
		};
	}//end buildStub()
}//end class
