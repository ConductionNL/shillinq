<?php

/**
 * Unit tests for the RetireCostProjectStep repair step.
 *
 * Verifies the field mapping, lifecycle mapping, costsIncurredToDate drop,
 * code minting + collision disambiguation, idempotent re-run behaviour, and
 * fail-safe skip for unmappable source records (per REQ-Rcp-005).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retire-cost-project/tasks.md#phase-4
 * @spec openspec/changes/retire-cost-project/specs/retire-cost-project/spec.md (REQ-Rcp-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RetireCostProjectStep;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RetireCostProjectStep.
 *
 * Each test sets up a controlled fake ObjectService that simulates OR's
 * findAll / saveObject surface, allowing assertions on what was saved, what
 * was skipped, and what was never deleted.
 */
class RetireCostProjectStepTest extends TestCase {

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
	 * Set up test fixtures.
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
	 * The repair step name is non-empty and mentions the migration purpose.
	 *
	 * @return void
	 */
	public function testNameIsHumanReadable(): void {
		$step = $this->makeStep(costProjects: [], existingDimensions: []);

		$name = $step->getName();
		self::assertNotSame('', $name);
		self::assertStringContainsStringIgnoringCase('CostProject', $name);

	}//end testNameIsHumanReadable()

	/**
	 * When there are no CostProject records the step is a no-op.
	 *
	 * @return void
	 */
	public function testEmptyCostProjectTableProducesNoSaves(): void {
		$step = $this->makeStep(costProjects: [], existingDimensions: []);

		$step->run($this->output);

		self::assertSame([], $this->fakeObjectService->saves);

	}//end testEmptyCostProjectTableProducesNoSaves()

	/**
	 * A typical CostProject is correctly mapped to an AnalyticalDimension.
	 *
	 * Verifies the field mapping table from REQ-Rcp-005:
	 *   - projectNumber → projectNumber
	 *   - minted code = cp-<projectNumber>
	 *   - name, description, startDate, endDate → same
	 *   - totalBudget, totalEstimatedCosts → same
	 *   - costCenterCode → parentCode
	 *   - lifecycleState active → active
	 *   - dimensionType = project
	 *   - migratedFrom = source CostProject id
	 *   - costsIncurredToDate is NOT copied
	 *   - externalProjectRef = null
	 *
	 * @return void
	 */
	public function testFieldMappingIsCorrect(): void {
		$source = [
			'id' => 'cp-001',
			'projectNumber' => '2026-001',
			'name' => 'Test Project',
			'description' => 'A test cost project',
			'startDate' => '2026-01-01',
			'endDate' => '2026-12-31',
			'totalBudget' => 5000000,
			'totalEstimatedCosts' => 4500000,
			'costsIncurredToDate' => 1200000,  // MUST be dropped
			'administrationId' => 'adm-1',
			'organizationId' => 'org-1',
			'costCenterCode' => 'CC-002',
			'lifecycleState' => 'active',
		];

		$step = $this->makeStep(costProjects: [$source], existingDimensions: []);
		$step->run($this->output);

		self::assertCount(1, $this->fakeObjectService->saves);
		$saved = $this->fakeObjectService->saves[0];

		self::assertSame('AnalyticalDimension', $saved['schema']);
		$obj = $saved['object'];

		self::assertSame('project', $obj['dimensionType']);
		self::assertSame('cp-2026-001', $obj['code']);
		self::assertSame('string', $obj['dataType']);
		self::assertSame('2026-001', $obj['projectNumber']);
		self::assertSame('Test Project', $obj['name']);
		self::assertSame('A test cost project', $obj['description']);
		self::assertSame('2026-01-01', $obj['startDate']);
		self::assertSame('2026-12-31', $obj['endDate']);
		self::assertSame(5000000, $obj['totalBudget']);
		self::assertSame(4500000, $obj['totalEstimatedCosts']);
		self::assertSame('adm-1', $obj['administrationId']);
		self::assertSame('org-1', $obj['organizationId']);
		self::assertSame('CC-002', $obj['parentCode']);
		self::assertSame('active', $obj['lifecycleState']);
		self::assertSame('cp-001', $obj['migratedFrom']);
		self::assertNull($obj['externalProjectRef']);

		// costsIncurredToDate MUST NOT appear on the migrated object.
		self::assertArrayNotHasKey('costsIncurredToDate', $obj);

	}//end testFieldMappingIsCorrect()

	/**
	 * Lifecycle state mapping table per REQ-Rcp-005.
	 *
	 * draft, active, on-hold → active
	 * closed, archived        → archived
	 *
	 * @dataProvider lifecycleStateProvider
	 *
	 * @param string $sourceState The CostProject.lifecycleState input.
	 * @param string $expectedState The expected AnalyticalDimension.lifecycleState.
	 *
	 * @return void
	 */
	public function testLifecycleStateMapping(string $sourceState, string $expectedState): void {
		$source = [
			'id' => 'cp-lc-' . $sourceState,
			'projectNumber' => 'LC-' . $sourceState,
			'name' => 'LC Test',
			'administrationId' => 'adm-1',
			'lifecycleState' => $sourceState,
		];

		$step = $this->makeStep(costProjects: [$source], existingDimensions: []);
		$step->run($this->output);

		self::assertCount(1, $this->fakeObjectService->saves);
		self::assertSame($expectedState, $this->fakeObjectService->saves[0]['object']['lifecycleState']);

	}//end testLifecycleStateMapping()

	/**
	 * Lifecycle state mapping data provider.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function lifecycleStateProvider(): array {
		return [
			'draft → active' => ['draft',    'active'],
			'active → active' => ['active',   'active'],
			'on-hold → active' => ['on-hold',  'active'],
			'closed → archived' => ['closed',   'archived'],
			'archived → archived' => ['archived', 'archived'],
		];

	}//end lifecycleStateProvider()

	/**
	 * costsIncurredToDate is never copied to the migrated AnalyticalDimension
	 * (it is re-derived from GL as spentToDate per REQ-Rcp-005).
	 *
	 * @return void
	 */
	public function testCostsIncurredToDateIsDropped(): void {
		$source = [
			'id' => 'cp-drop',
			'projectNumber' => 'DROP-001',
			'name' => 'Drop Test',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'active',
			'costsIncurredToDate' => 9999999,
		];

		$step = $this->makeStep(costProjects: [$source], existingDimensions: []);
		$step->run($this->output);

		self::assertCount(1, $this->fakeObjectService->saves);
		self::assertArrayNotHasKey('costsIncurredToDate', $this->fakeObjectService->saves[0]['object']);

	}//end testCostsIncurredToDateIsDropped()

	/**
	 * The minted code is "cp-<projectNumber>".
	 *
	 * @return void
	 */
	public function testCodeIsMintedWithCpPrefix(): void {
		$source = [
			'id' => 'cp-mint',
			'projectNumber' => '2026-MINT',
			'name' => 'Mint Test',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'active',
		];

		$step = $this->makeStep(costProjects: [$source], existingDimensions: []);
		$step->run($this->output);

		self::assertCount(1, $this->fakeObjectService->saves);
		self::assertSame('cp-2026-mint', $this->fakeObjectService->saves[0]['object']['code']);

	}//end testCodeIsMintedWithCpPrefix()

	/**
	 * When the minted code "cp-<projectNumber>" is already taken, the step
	 * appends "-2", "-3", etc. until a free slot is found. The collision is
	 * reported but never silently overwritten.
	 *
	 * @return void
	 */
	public function testCodeCollisionGetsDisambiguatingSuffix(): void {
		$source = [
			'id' => 'cp-coll',
			'projectNumber' => 'COLL-001',
			'name' => 'Collision Test',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'active',
		];

		// Simulate "cp-coll-001" already existing — step must try "cp-coll-001-2".
		$existingDimensions = [
			['code' => 'cp-coll-001', 'administrationId' => 'adm-1', 'migratedFrom' => null],
		];

		$step = $this->makeStep(costProjects: [$source], existingDimensions: $existingDimensions);
		$step->run($this->output);

		self::assertCount(1, $this->fakeObjectService->saves);
		$saved = $this->fakeObjectService->saves[0];
		// Original code taken; disambiguated to -2.
		self::assertSame('cp-coll-001-2', $saved['object']['code']);
		// migratedFrom marker is preserved.
		self::assertSame('cp-coll', $saved['object']['migratedFrom']);

	}//end testCodeCollisionGetsDisambiguatingSuffix()

	/**
	 * A CostProject already migrated (its id appears as migratedFrom on an
	 * existing AnalyticalDimension) is skipped — no duplicate is created.
	 *
	 * @return void
	 */
	public function testIdempotentReRunSkipsAlreadyMigratedRecords(): void {
		$source = [
			'id' => 'cp-idem',
			'projectNumber' => 'IDEM-001',
			'name' => 'Idempotent Test',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'active',
		];

		// Existing AnalyticalDimension carrying the migratedFrom marker.
		$existingDimensions = [
			['code' => 'cp-idem-001', 'administrationId' => 'adm-1', 'migratedFrom' => 'cp-idem'],
		];

		$step = $this->makeStep(costProjects: [$source], existingDimensions: $existingDimensions);
		$step->run($this->output);

		// No new saves — already migrated.
		self::assertSame([], $this->fakeObjectService->saves);

	}//end testIdempotentReRunSkipsAlreadyMigratedRecords()

	/**
	 * A CostProject with no projectNumber cannot have a code minted; it is
	 * logged and LEFT IN PLACE — the step does NOT delete it.
	 *
	 * @return void
	 */
	public function testRecordWithNoProjectNumberIsSkippedNotDeleted(): void {
		$unmappable = [
			'id' => 'cp-no-num',
			'name' => 'No Number',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'active',
			// projectNumber intentionally absent.
		];
		$normal = [
			'id' => 'cp-normal',
			'projectNumber' => 'NORMAL-001',
			'name' => 'Normal',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'active',
		];

		$step = $this->makeStep(costProjects: [$unmappable, $normal], existingDimensions: []);
		$step->run($this->output);

		// Only the normal record is saved; the unmappable one is not.
		self::assertCount(1, $this->fakeObjectService->saves);
		self::assertSame('cp-normal-001', $this->fakeObjectService->saves[0]['object']['code']);

		// The fake ObjectService records zero deletes (fail-safe guarantee).
		self::assertSame([], $this->fakeObjectService->deletes);

	}//end testRecordWithNoProjectNumberIsSkippedNotDeleted()

	/**
	 * Multiple CostProject records are all migrated in one run.
	 *
	 * @return void
	 */
	public function testMultipleRecordsAreMigrated(): void {
		$sources = [
			['id' => 'cp-a', 'projectNumber' => 'A-001', 'name' => 'Alpha', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
			['id' => 'cp-b', 'projectNumber' => 'B-001', 'name' => 'Beta',  'administrationId' => 'adm-1', 'lifecycleState' => 'draft'],
			['id' => 'cp-c', 'projectNumber' => 'C-001', 'name' => 'Gamma', 'administrationId' => 'adm-2', 'lifecycleState' => 'archived'],
		];

		$step = $this->makeStep(costProjects: $sources, existingDimensions: []);
		$step->run($this->output);

		self::assertCount(3, $this->fakeObjectService->saves);

		$codes = array_map(
			static fn (array $s): string => $s['object']['code'],
			$this->fakeObjectService->saves
		);
		self::assertContains('cp-a-001', $codes);
		self::assertContains('cp-b-001', $codes);
		self::assertContains('cp-c-001', $codes);

	}//end testMultipleRecordsAreMigrated()

	/**
	 * Build the repair step with a fake ObjectService that simulates the OR
	 * surface needed for migration (findAll by CostProject, findAll by
	 * migratedFrom marker, findAll by code, saveObject).
	 *
	 * @param array<array<string,mixed>> $costProjects CostProject rows returned by findAll.
	 * @param array<array<string,mixed>> $existingDimensions AnalyticalDimension rows already in OR.
	 *
	 * @return RetireCostProjectStep
	 */
	private function makeStep(array $costProjects, array $existingDimensions): RetireCostProjectStep {
		$this->fakeObjectService = new class($costProjects, $existingDimensions) {
			/** @var array<array<string,mixed>> */
			public array $saves = [];
			/** @var array<array<string,mixed>> */
			public array $deletes = [];
			private string $currentSchema = '';

			/**
			 * @param array<array<string,mixed>> $costProjects
			 * @param array<array<string,mixed>> $existingDimensions
			 */
			public function __construct(
				private array $costProjects,
				private array $existingDimensions,
			) {
			}

			/** @return static */
			public function setRegister(string $register): static {
				return $this;
			}

			/** @return static */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}

			/**
			 * Mirrors OpenRegister's real findAll() paging semantics: `filters`
			 * apply first (a dot-path key matches NOTHING — OR has no nested
			 * filter support), then `offset` skips rows, then `limit` is a
			 * literal SQL LIMIT (limit=0 returns ZERO rows). Modelled faithfully
			 * so a caller passing limit=0 is caught reading nothing.
			 *
			 * @param array<string,mixed> $params
			 * @return array<array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->currentSchema === 'CostProject') {
					$rows = $this->costProjects;
				} elseif ($this->currentSchema === 'AnalyticalDimension') {
					$rows = $this->existingDimensions;
				} else {
					$rows = [];
				}

				$filters = ($params['filters'] ?? []);
				if ($filters !== []) {
					$rows = array_values(array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $path => $expected) {
								// OpenRegister does NOT support dot-path filters.
								if (str_contains((string)$path, '.') === true) {
									return false;
								}

								if (($row[$path] ?? null) !== $expected) {
									return false;
								}
							}

							return true;
						}
					));
				}

				$offset = (int)($params['offset'] ?? 0);
				if ($offset > 0) {
					$rows = array_slice($rows, $offset);
				}

				$limit = ($params['limit'] ?? null);
				if ($limit !== null) {
					$rows = array_slice($rows, 0, (int)$limit);
				}

				return array_values($rows);
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac = true, bool $_multitenancy = true): void {
				$this->saves[] = ['object' => $object, 'schema' => $schema];
				// Simulate the saved dimension appearing in existingDimensions for subsequent code-exists checks.
				$this->existingDimensions[] = $object;
			}
		};

		$this->container
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->fakeObjectService);

		return new RetireCostProjectStep(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
		);

	}//end makeStep()

}//end class
