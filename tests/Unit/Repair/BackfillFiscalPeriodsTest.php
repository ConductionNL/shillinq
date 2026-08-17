<?php

/**
 * Unit tests for the BackfillFiscalPeriods repair step.
 *
 * Verifies the idempotent backfill of `FiscalPeriod` records from
 * distinct historical `(administrationId, periodId)` tuples on
 * `GLLine` (per `add-shillinq-period-close` REQ-PC-001 / Task 11).
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
 * @spec openspec/changes/add-shillinq-period-close/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\BackfillFiscalPeriods;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BackfillFiscalPeriods.
 *
 * Each test sets up a fake ObjectService that records every \\setRegister /
 * setSchema / findAll / saveObject call so the test can assert the
 * idempotency contract (re-runs produce zero new saves) and the
 * periodId-derivation logic (quarter / month / week / fiscal-year).
 */
class BackfillFiscalPeriodsTest extends TestCase {

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
	 * The repair step name is non-empty and human-readable.
	 *
	 * @return void
	 */
	public function testNameIsHumanReadable(): void {
		$step = $this->makeStep(glLines: [], existingPeriods: []);

		$name = $step->getName();
		self::assertNotSame('', $name);
		self::assertStringContainsString('FiscalPeriod', $name);

	}//end testNameIsHumanReadable()

	/**
	 * Run on an empty GLLine table is a no-op.
	 *
	 * @return void
	 */
	public function testEmptyGlLineTableProducesNoSaves(): void {
		$step = $this->makeStep(glLines: [], existingPeriods: []);

		$step->run($this->output);

		self::assertSame([], $this->fakeObjectService->saves);

	}//end testEmptyGlLineTableProducesNoSaves()

	/**
	 * A fresh repair step against historical GLLine rows materialises
	 * one FiscalPeriod per distinct (administrationId, periodId).
	 *
	 * @return void
	 */
	public function testBackfillCreatesOneRecordPerDistinctTuple(): void {
		$glLines = [
			['periodId' => '2026-Q1', 'administrationId' => 'adm-1'],
			['periodId' => '2026-Q1', 'administrationId' => 'adm-1'], // dup → 1 save
			['periodId' => '2026-Q2', 'administrationId' => 'adm-1'],
			['periodId' => '2026-Q1', 'administrationId' => 'adm-2'], // diff admin → 1 save
			['periodId' => '', 'administrationId' => 'adm-1'], // empty period → skipped
		];
		$step = $this->makeStep(glLines: $glLines, existingPeriods: []);

		$step->run($this->output);

		self::assertCount(3, $this->fakeObjectService->saves);

		$tuples = array_map(
			static fn (array $s): string => ($s['object']['administrationId'] . '|' . $s['object']['periodId']),
			$this->fakeObjectService->saves
		);
		sort($tuples);
		self::assertSame(
			['adm-1|2026-Q1', 'adm-1|2026-Q2', 'adm-2|2026-Q1'],
			$tuples
		);

	}//end testBackfillCreatesOneRecordPerDistinctTuple()

	/**
	 * Backfill is idempotent — running again with the FiscalPeriod
	 * records already present produces zero saves.
	 *
	 * @return void
	 */
	public function testBackfillIsIdempotent(): void {
		$glLines = [
			['periodId' => '2026-Q1', 'administrationId' => 'adm-1'],
			['periodId' => '2026-Q2', 'administrationId' => 'adm-1'],
		];
		$existing = [
			['periodId' => '2026-Q1', 'administrationId' => 'adm-1'],
			['periodId' => '2026-Q2', 'administrationId' => 'adm-1'],
		];
		$step = $this->makeStep(glLines: $glLines, existingPeriods: $existing);

		$step->run($this->output);

		self::assertSame([], $this->fakeObjectService->saves);

	}//end testBackfillIsIdempotent()

	/**
	 * The derived `name`, `startDate`, `endDate`, `fiscalYear` reflect
	 * the periodId shape — calendar quarter, month, ISO week, fiscal
	 * year, or year-only fallback.
	 *
	 * @return void
	 */
	public function testDerivedFieldsMatchPeriodIdShape(): void {
		$glLines = [
			['periodId' => '2026-Q1', 'administrationId' => 'adm-1'],
			['periodId' => '2026-M03', 'administrationId' => 'adm-1'],
			['periodId' => '2026-W12', 'administrationId' => 'adm-1'],
			['periodId' => 'FY2025', 'administrationId' => 'adm-1'],
			['periodId' => '2027-foo', 'administrationId' => 'adm-1'],
		];
		$step = $this->makeStep(glLines: $glLines, existingPeriods: []);

		$step->run($this->output);

		$byId = [];
		foreach ($this->fakeObjectService->saves as $save) {
			$byId[$save['object']['periodId']] = $save['object'];
		}

		self::assertSame('Q1 2026', $byId['2026-Q1']['name']);
		self::assertSame('2026-01-01', $byId['2026-Q1']['startDate']);
		self::assertSame('2026-03-31', $byId['2026-Q1']['endDate']);
		self::assertSame(2026, $byId['2026-Q1']['fiscalYear']);

		self::assertSame('March 2026', $byId['2026-M03']['name']);
		self::assertSame('2026-03-01', $byId['2026-M03']['startDate']);
		self::assertSame('2026-03-31', $byId['2026-M03']['endDate']);

		self::assertStringStartsWith('Week 12', $byId['2026-W12']['name']);
		self::assertSame(2026, $byId['2026-W12']['fiscalYear']);

		self::assertSame('Fiscal year 2025', $byId['FY2025']['name']);
		self::assertSame('2025-01-01', $byId['FY2025']['startDate']);
		self::assertSame('2025-12-31', $byId['FY2025']['endDate']);
		self::assertSame(2025, $byId['FY2025']['fiscalYear']);

		self::assertSame('2027-foo', $byId['2027-foo']['name']);
		self::assertSame(2027, $byId['2027-foo']['fiscalYear']);

	}//end testDerivedFieldsMatchPeriodIdShape()

	/**
	 * Every seed record is created in state `open` with empty
	 * audit-history fields.
	 *
	 * @return void
	 */
	public function testSeedRecordsAreCreatedOpenWithEmptyHistory(): void {
		$step = $this->makeStep(
			glLines: [['periodId' => '2026-Q1', 'administrationId' => 'adm-1']],
			existingPeriods: []
		);

		$step->run($this->output);

		$record = $this->fakeObjectService->saves[0]['object'];
		self::assertSame('open', $record['state']);
		self::assertSame([], $record['reopenedHistory']);

	}//end testSeedRecordsAreCreatedOpenWithEmptyHistory()

	/**
	 * Build a BackfillFiscalPeriods step wired to a fake ObjectService
	 * that returns the given GLLine + FiscalPeriod stubs and records
	 * every saveObject call.
	 *
	 * @param list<array<string,mixed>> $glLines The fake GLLine rows.
	 * @param list<array<string,mixed>> $existingPeriods The fake FiscalPeriod rows already in OR.
	 *
	 * @return BackfillFiscalPeriods The repair step under test.
	 */
	private function makeStep(array $glLines, array $existingPeriods): BackfillFiscalPeriods {
		$this->fakeObjectService = new class($glLines, $existingPeriods) {
			/**
			 * The GLLine fixture rows.
			 *
			 * @var list<array<string,mixed>>
			 */
			public array $glLines;

			/**
			 * The existing FiscalPeriod fixture rows.
			 *
			 * @var list<array<string,mixed>>
			 */
			public array $existingPeriods;

			/**
			 * The current schema selected by setSchema().
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * Recorded saveObject calls.
			 *
			 * @var list<array{object:array<string,mixed>,register:string,schema:string}>
			 */
			public array $saves = [];

			/**
			 * @param list<array<string,mixed>> $glLines The fake GLLine rows.
			 * @param list<array<string,mixed>> $existingPeriods The fake FiscalPeriod rows.
			 */
			public function __construct(array $glLines, array $existingPeriods) {
				$this->glLines = $glLines;
				$this->existingPeriods = $existingPeriods;
			}

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $_ The register slug (unused).
			 */
			public function setRegister(string $_): self {
				return $this;
			}

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema slug.
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;

				return $this;
			}

			/**
			 * Mimics OpenRegister's real findAll() paging semantics: `filters`
			 * apply first (a dot-path key matches NOTHING — OR has no nested
			 * filter support), then `offset` skips rows, then `limit` is a
			 * literal SQL LIMIT (so limit=0 returns ZERO rows). Modelling this
			 * faithfully catches a caller passing limit=0 and reading nothing.
			 *
			 * @param array<string,mixed> $options The findAll options (filters / offset / limit).
			 *
			 * @return list<array<string,mixed>> Matching fixture rows.
			 */
			public function findAll(array $options = []): array {
				$filters = (array)($options['filters'] ?? []);
				$rows = $this->schema === 'GLLine' ? $this->glLines : $this->existingPeriods;
				$matched = [];
				foreach ($rows as $row) {
					foreach ($filters as $k => $v) {
						// OpenRegister does NOT support dot-path filters on
						// nested properties: such a filter matches nothing.
						if (str_contains((string)$k, '.') === true) {
							continue 2;
						}

						if (($row[$k] ?? null) !== $v) {
							continue 2;
						}
					}

					$matched[] = $row;
				}

				$offset = (int)($options['offset'] ?? 0);
				if ($offset > 0) {
					$matched = array_slice($matched, $offset);
				}

				$limit = ($options['limit'] ?? null);
				if ($limit !== null) {
					$matched = array_slice($matched, 0, (int)$limit);
				}

				return array_values($matched);
			}

			/**
			 * Records the save instead of persisting.
			 *
			 * Mirrors the real OpenRegister ObjectService::saveObject signature,
			 * which the repair step calls with the installer-context bypass flags
			 * `_rbac: false` / `_multitenancy: false` (named args).
			 *
			 * @param array<string,mixed> $object The record being saved.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param bool $_rbac RBAC enforcement flag.
			 * @param bool $_multitenancy Multi-tenancy flag.
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): void {
				// The schema may arrive positionally or, when the caller reaches
				// this double through the ADR-084 contract adapter, via the
				// preceding setSchema() on the fluent chain.
				if ($schema === '') {
					$schema = $this->schema;
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
			}
		};

		$this->container
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->fakeObjectService);

		return new BackfillFiscalPeriods(
			settingsService: $this->settingsService,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->fakeObjectService),
		);

	}//end makeStep()
}//end class
