<?php

/**
 * Unit tests for the PeriodCloseBackfill repair step.
 *
 * Verifies the idempotent forward-looking backfill of `FiscalPeriod`
 * records for every Administration record — the current calendar month
 * plus a twelve-month horizon — per `bookkeeping-period-close`
 * REQ-PC-009 / Task 12.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\PeriodCloseBackfill;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PeriodCloseBackfill.
 *
 * Each test sets up a fake ObjectService that records every
 * setRegister / setSchema / findAll / saveObject call so the test can
 * assert the idempotency contract (re-runs produce zero new saves) and
 * the horizon shape (current month + next twelve months).
 */
class PeriodCloseBackfillTest extends TestCase {

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
	 * Setup test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);

		$this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

	}//end setUp()

	/**
	 * The repair step name is non-empty and human-readable.
	 *
	 * @return void
	 */
	public function testNameIsHumanReadable(): void {
		$step = new PeriodCloseBackfill(
			settingsService: $this->settingsService,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$name = $step->getName();
		$this->assertNotEmpty(actual: $name);
		$this->assertStringContainsString(needle: 'Shillinq', haystack: $name);
		$this->assertStringContainsString(needle: 'FiscalPeriod', haystack: $name);

	}//end testNameIsHumanReadable()

	/**
	 * Run on a fleet with no Administration records — the repair step
	 * emits an "skipped" info line and saves nothing.
	 *
	 * @return void
	 */
	public function testRunNoAdministrations(): void {
		$objectService = $this->makeFakeObjectService(
			administrations: [],
			existingPeriods: []
		);

		$this->output
			->expects($this->atLeastOnce())
			->method('info');

		$step = new PeriodCloseBackfill(
			settingsService: $this->settingsService,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);
		$step->run($this->output);

		$this->assertSame(expected: 0, actual: $objectService->savedCount);

	}//end testRunNoAdministrations()

	/**
	 * Run on a single Administration with no existing FiscalPeriod
	 * records — the repair step backfills the current month + next
	 * twelve months (HORIZON = 12 → 13 records).
	 *
	 * @return void
	 */
	public function testRunSingleAdministrationBackfillsHorizon(): void {
		$objectService = $this->makeFakeObjectService(
			administrations: [
				['administrationId' => 'adm-1'],
			],
			existingPeriods: []
		);

		$step = new PeriodCloseBackfill(
			settingsService: $this->settingsService,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);
		$step->run($this->output);

		// Current month (1) + 12 future months = 13.
		$this->assertSame(expected: 13, actual: $objectService->savedCount);

		// Every saved record is open + has the expected shape.
		foreach ($objectService->saved as $rec) {
			$this->assertSame(expected: 'open', actual: $rec['state']);
			$this->assertSame(expected: 'adm-1', actual: $rec['administrationId']);
			$this->assertSame(expected: [], actual: $rec['reopenedHistory']);
			$this->assertSame(expected: [], actual: $rec['taskChecklistItems']);
			$this->assertSame(expected: [], actual: $rec['aiFlags']);
			$this->assertNotEmpty(actual: $rec['periodId']);
			$this->assertNotEmpty(actual: $rec['name']);
			$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}$/', string: $rec['periodId']);
			$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}$/', string: $rec['startDate']);
			$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}$/', string: $rec['endDate']);
			$this->assertIsInt(actual: $rec['fiscalYear']);
		}

	}//end testRunSingleAdministrationBackfillsHorizon()

	/**
	 * Re-running the repair step against an administration whose
	 * horizon already exists must save zero new records.
	 *
	 * @return void
	 */
	public function testRerunIsIdempotent(): void {
		// Pre-seed every (administrationId, periodId) the step would
		// try to create.
		$existing = [];
		$now = new \DateTimeImmutable('first day of this month');
		for ($i = 0; $i <= 12; $i++) {
			$m = $now->modify('+' . $i . ' month');
			$existing[] = [
				'administrationId' => 'adm-1',
				'periodId' => $m->format('Y-m'),
			];
		}

		$objectService = $this->makeFakeObjectService(
			administrations: [
				['administrationId' => 'adm-1'],
			],
			existingPeriods: $existing
		);

		$step = new PeriodCloseBackfill(
			settingsService: $this->settingsService,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);
		$step->run($this->output);

		$this->assertSame(expected: 0, actual: $objectService->savedCount);

	}//end testRerunIsIdempotent()

	/**
	 * Failures in the underlying ObjectService must NOT propagate —
	 * the repair step logs + warns so the app upgrade can continue.
	 *
	 * @return void
	 */
	public function testRunFailureIsBestEffort(): void {
		// The injected ObjectService is the failure surface now that ADR-084
		// removed the container: refuse on the very first call of the read
		// chain, exactly as an unavailable OpenRegister would.
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService
			->method('setRegister')
			->willThrowException(new \RuntimeException('ObjectService unavailable'));

		$this->output
			->expects($this->atLeastOnce())
			->method('warning');

		$this->logger
			->expects($this->atLeastOnce())
			->method('warning');

		$step = new PeriodCloseBackfill(
			settingsService: $this->settingsService,
			logger: $this->logger,
			objectService: $objectService,
		);

		// Must NOT throw — the repair step swallows the failure.
		$step->run($this->output);

		$this->assertTrue(condition: true);

	}//end testRunFailureIsBestEffort()

	/**
	 * Administration records carrying alternative id field shapes
	 * (`id`, `code`) are still picked up; records with no id at all
	 * are skipped (defensive).
	 *
	 * @return void
	 */
	public function testAdministrationIdFieldFallback(): void {
		$objectService = $this->makeFakeObjectService(
			administrations: [
				['administrationId' => 'adm-primary'],
				['id' => 'adm-id-fallback'],
				['code' => 'adm-code-fallback'],
				['name' => 'no-id-here'],
			],
			existingPeriods: []
		);

		$step = new PeriodCloseBackfill(
			settingsService: $this->settingsService,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);
		$step->run($this->output);

		// 3 valid administrations × 13 periods each = 39 saves.
		$this->assertSame(expected: 39, actual: $objectService->savedCount);

		$ids = array_unique(array_map(static fn ($rec) => $rec['administrationId'], $objectService->saved));
		sort($ids);
		$this->assertSame(
			expected: ['adm-code-fallback', 'adm-id-fallback', 'adm-primary'],
			actual: $ids
		);

	}//end testAdministrationIdFieldFallback()

	/**
	 * Build a fake ObjectService that records every saveObject call
	 * and serves findAll results from canned fixtures.
	 *
	 * @param array<int,array<string,mixed>> $administrations Fixture Administration records.
	 * @param array<int,array<string,string>> $existingPeriods Pre-existing FiscalPeriod tuples
	 *                                                         (administrationId, periodId).
	 *
	 * @return object The fake service.
	 */
	private function makeFakeObjectService(array $administrations, array $existingPeriods): object {
		return new class($administrations, $existingPeriods) {
			/**
			 * Records of saveObject() calls.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Number of saveObject() calls.
			 *
			 * @var integer
			 */
			public int $savedCount = 0;

			/**
			 * Currently selected schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $administrations Administration fixtures.
			 * @param array<int,array<string,string>> $existingPeriods Pre-existing FiscalPeriod tuples.
			 */
			public function __construct(
				private array $administrations,
				private array $existingPeriods,
			) {
			}//end __construct()

			/**
			 * Selects the register slug.
			 *
			 * @param string $register The register slug.
			 *
			 * @return self The service.
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Selects the schema slug.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return self The service.
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Returns the canned fixtures for the selected schema, honouring
			 * OpenRegister's real findAll() paging semantics: `filters` apply
			 * first (a dot-path key matches NOTHING — OR has no nested filter
			 * support), then `offset` skips rows, then `limit` is a literal SQL
			 * LIMIT (limit=0 returns ZERO rows). Modelled faithfully so a caller
			 * passing limit=0 is caught reading nothing.
			 *
			 * @param array<string,mixed> $options The findAll options.
			 *
			 * @return array<int,mixed> The fixtures.
			 */
			public function findAll(array $options = []): array {
				if ($this->schema === 'Administration') {
					$rows = $this->administrations;
				} elseif ($this->schema === 'FiscalPeriod') {
					$rows = $this->existingPeriods;
				} else {
					$rows = [];
				}

				$filters = ($options['filters'] ?? []);
				if ($filters !== []) {
					$rows = array_values(
						array_filter(
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
						)
					);
				}

				$offset = (int)($options['offset'] ?? 0);
				if ($offset > 0) {
					$rows = array_slice($rows, $offset);
				}

				$limit = ($options['limit'] ?? null);
				if ($limit !== null) {
					$rows = array_slice($rows, 0, (int)$limit);
				}

				return array_values($rows);
			}//end findAll()

			/**
			 * Records a saveObject call.
			 *
			 * Mirrors the real OpenRegister ObjectService::saveObject signature,
			 * which the repair step calls with the installer-context bypass flags
			 * `_rbac: false` / `_multitenancy: false` (named args).
			 *
			 * @param array<string,mixed> $object The record.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param bool $_rbac RBAC enforcement flag.
			 * @param bool $_multitenancy Multi-tenancy flag.
			 *
			 * @return array<string,mixed> The recorded record (echo).
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->saved[] = $object;
				$this->savedCount++;
				return $object;
			}//end saveObject()
		};

	}//end makeFakeObjectService()
}//end class
