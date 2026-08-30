<?php

/**
 * Unit tests for the RematerialiseConvertedCalculations repair step.
 *
 * Verifies the backfill re-saves every existing object on each of the 17
 * schemas `revive-declarative-calc-layer` converted to JSON-AST +
 * `materialise: true` (Task 4.3), so their newly-materialised derived
 * fields get computed exactly as OR's `RematerialiseCalculationsCommand`
 * would for pre-existing objects.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/revive-declarative-calc-layer/tasks.md#4-verification
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RematerialiseConvertedCalculations;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RematerialiseConvertedCalculations.
 *
 * Wires a fake ObjectService whose findAll() returns fixture rows per schema
 * and records every saveObject() call, so tests can assert exactly which
 * schemas/objects get re-saved.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class RematerialiseConvertedCalculationsTest extends TestCase {

	/**
	 * The 17 schema slugs the repair step targets — mirrored here (not
	 * imported from the class) so a change to the private SCHEMAS constant
	 * is caught by this test rather than silently drifting.
	 *
	 * @var array<int,string>
	 */
	private const EXPECTED_SCHEMAS = [
		'BankConnection',
		'Account',
		'RetentionRule',
		'kernGegevensConfig',
		'FixedAsset',
		'RateSchedule',
		'MileageEntry',
		'PerDiem',
		'RepaymentInstallment',
		'WinstToerekening',
		'ZzpDeduction',
		'SisaReport',
		'InventoryReorderRule',
		'Project',
		'ProjectAssignment',
		'VatReturn',
		'InnovatieboxElection',
	];

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Repair-step output mock.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Fake ObjectService captured per-test (records every saveObject call).
	 *
	 * @var object
	 */
	private object $fakeObjectService;

	/**
	 * Setup test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);

		$this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

	}//end setUp()

	/**
	 * The repair step name is human-readable and references the change.
	 *
	 * @return void
	 */
	public function testNameIsHumanReadable(): void {
		$step = $this->makeStep(rowsBySchema: []);

		$name = $step->getName();
		self::assertNotSame('', $name);
		self::assertStringContainsString('rematerialise', strtolower($name));

	}//end testNameIsHumanReadable()

	/**
	 * With no existing objects on any schema, run() is a no-op — zero saves.
	 *
	 * @return void
	 */
	public function testNoExistingObjectsProducesNoSaves(): void {
		$step = $this->makeStep(rowsBySchema: []);

		$step->run($this->output);

		self::assertSame([], $this->fakeObjectService->saves);

	}//end testNoExistingObjectsProducesNoSaves()

	/**
	 * Every existing object on every targeted schema is re-saved exactly
	 * once, carrying its own id (so the resave is an UPDATE, not a CREATE).
	 *
	 * @return void
	 */
	public function testEveryExistingObjectIsResaved(): void {
		$rowsBySchema = [
			'FixedAsset' => [
				['id' => 'fa-1', 'acquisitionCost' => 12000],
				['id' => 'fa-2', 'acquisitionCost' => 5000],
			],
			'RateSchedule' => [
				['id' => 'rs-1', 'status' => 'active'],
			],
		];
		$step = $this->makeStep(rowsBySchema: $rowsBySchema);

		$step->run($this->output);

		self::assertCount(3, $this->fakeObjectService->saves);

		$ids = array_map(
			static fn (array $s): string => $s['object']['id'],
			$this->fakeObjectService->saves
		);
		sort($ids);
		self::assertSame(['fa-1', 'fa-2', 'rs-1'], $ids);

		foreach ($this->fakeObjectService->saves as $save) {
			self::assertSame('shillinq', $save['register']);
		}

	}//end testEveryExistingObjectIsResaved()

	/**
	 * Every one of the 17 Bucket-1/2 schemas is visited (findAll called on
	 * each) even when most carry no objects — the repair step does not stop
	 * early after the first empty schema.
	 *
	 * @return void
	 */
	public function testEveryTargetedSchemaIsVisited(): void {
		$rowsBySchema = ['InnovatieboxElection' => [['id' => 'ie-1']]];
		$step = $this->makeStep(rowsBySchema: $rowsBySchema);

		$step->run($this->output);

		sort($this->fakeObjectService->schemasQueried);
		$expected = self::EXPECTED_SCHEMAS;
		sort($expected);
		self::assertSame($expected, $this->fakeObjectService->schemasQueried);

	}//end testEveryTargetedSchemaIsVisited()

	/**
	 * An object without an `id` or `uuid` is skipped (would otherwise risk
	 * an unintended CREATE via saveObject).
	 *
	 * @return void
	 */
	public function testObjectWithoutIdIsSkipped(): void {
		$rowsBySchema = ['Project' => [['label' => 'no id here']]];
		$step = $this->makeStep(rowsBySchema: $rowsBySchema);

		$step->run($this->output);

		self::assertSame([], $this->fakeObjectService->saves);

	}//end testObjectWithoutIdIsSkipped()

	/**
	 * A saveObject failure on one object is logged as a warning and does
	 * not stop the rest of the backfill (best-effort per object).
	 *
	 * @return void
	 */
	public function testSaveFailureIsBestEffort(): void {
		$rowsBySchema = [
			'ZzpDeduction' => [
				['id' => 'zd-fail'],
				['id' => 'zd-ok'],
			],
		];
		$step = $this->makeStep(rowsBySchema: $rowsBySchema, failIds: ['zd-fail']);

		$this->output->expects(self::atLeastOnce())->method('warning');

		$step->run($this->output);

		self::assertCount(1, $this->fakeObjectService->saves);
		self::assertSame('zd-ok', $this->fakeObjectService->saves[0]['object']['id']);

	}//end testSaveFailureIsBestEffort()

	/**
	 * A findAll failure on one schema is logged and the remaining schemas
	 * still get processed.
	 *
	 * @return void
	 */
	public function testFindAllFailureOnOneSchemaDoesNotBlockOthers(): void {
		$rowsBySchema = ['Project' => [['id' => 'p-1']]];
		$step = $this->makeStep(rowsBySchema: $rowsBySchema, failFindAllSchemas: ['FixedAsset']);

		$this->output->expects(self::atLeastOnce())->method('warning');

		$step->run($this->output);

		self::assertCount(1, $this->fakeObjectService->saves);
		self::assertSame('p-1', $this->fakeObjectService->saves[0]['object']['id']);

	}//end testFindAllFailureOnOneSchemaDoesNotBlockOthers()

	/**
	 * Build a RematerialiseConvertedCalculations step wired to a fake
	 * ObjectService that returns the given per-schema fixture rows and
	 * records every saveObject call.
	 *
	 * @param array<string,list<array<string,mixed>>> $rowsBySchema Fixture rows keyed by schema.
	 * @param list<string> $failIds Object ids whose saveObject() throws.
	 * @param list<string> $failFindAllSchemas Schemas whose findAll() throws.
	 *
	 * @return RematerialiseConvertedCalculations The repair step under test.
	 */
	private function makeStep(
		array $rowsBySchema,
		array $failIds = [],
		array $failFindAllSchemas = [],
	): RematerialiseConvertedCalculations {
		$this->fakeObjectService = new class($rowsBySchema, $failIds, $failFindAllSchemas) {

			/**
			 * Fixture rows keyed by schema.
			 *
			 * @var array<string,list<array<string,mixed>>>
			 */
			public array $rowsBySchema;

			/**
			 * Object ids whose saveObject() throws.
			 *
			 * @var list<string>
			 */
			public array $failIds;

			/**
			 * Schemas whose findAll() throws.
			 *
			 * @var list<string>
			 */
			public array $failFindAllSchemas;

			/**
			 * The current schema selected by setSchema().
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * Every schema setSchema() was called with, in call order.
			 *
			 * @var list<string>
			 */
			public array $schemasQueried = [];

			/**
			 * Recorded saveObject calls.
			 *
			 * @var list<array{object:array<string,mixed>,register:string,schema:string}>
			 */
			public array $saves = [];

			/**
			 * Build the fake, capturing the fixture data for later lookups.
			 *
			 * @param array<string,list<array<string,mixed>>> $rowsBySchema Fixture rows keyed by schema.
			 * @param list<string> $failIds Object ids whose save fails.
			 * @param list<string> $failFindAllSchemas Schemas whose findAll fails.
			 */
			public function __construct(array $rowsBySchema, array $failIds, array $failFindAllSchemas) {
				$this->rowsBySchema = $rowsBySchema;
				$this->failIds = $failIds;
				$this->failFindAllSchemas = $failFindAllSchemas;
			}//end __construct()

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $registerSlug The register slug (unused).
			 *
			 * @return self Fluent return.
			 */
			public function setRegister(string $registerSlug): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return self Fluent return.
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;

				return $this;
			}//end setSchema()

			/**
			 * Mimics OpenRegister's real findAll() paging semantics: `offset`
			 * skips rows and `limit` is a literal SQL LIMIT (limit=0 returns
			 * ZERO rows), so a caller passing limit=0 is caught reading nothing.
			 *
			 * @param array<string,mixed> $config The findAll options (offset / limit).
			 * @param bool $_rbac RBAC enforcement flag (unused).
			 * @param bool $_multitenancy Multi-tenancy flag (unused).
			 *
			 * @return list<array<string,mixed>> Matching fixture rows.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				// Recorded here rather than in setSchema(): the assertion is
				// that findAll() was called on every targeted schema, and the
				// write path re-selects the schema on the same chain.
				$this->schemasQueried[] = $this->schema;

				if (in_array($this->schema, $this->failFindAllSchemas, true) === true) {
					throw new \RuntimeException('findAll failed for ' . $this->schema);
				}

				$rows = ($this->rowsBySchema[$this->schema] ?? []);

				$offset = (int)($config['offset'] ?? 0);
				if ($offset > 0) {
					$rows = array_slice($rows, $offset);
				}

				$limit = ($config['limit'] ?? null);
				if ($limit !== null) {
					$rows = array_slice($rows, 0, (int)$limit);
				}

				return array_values($rows);
			}//end findAll()

			/**
			 * Records the save instead of persisting; throws for ids
			 * flagged in failIds to exercise the best-effort catch path.
			 *
			 * @param array<string,mixed> $object The record being saved.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param bool $_rbac RBAC enforcement flag.
			 * @param bool $_multitenancy Multi-tenancy flag.
			 *
			 * @return void
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
				mixed $currentUser = null,
			): void {
				$id = (string)($object['id'] ?? '');
				if (in_array($id, $this->failIds, true) === true) {
					throw new \RuntimeException('saveObject failed for ' . $id);
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
			}//end saveObject()
		};

		$this->container
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->fakeObjectService);

		return new RematerialiseConvertedCalculations(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
			objectService: new DuckObjectServiceAdapter($this->fakeObjectService),
		);

	}//end makeStep()
}//end class
