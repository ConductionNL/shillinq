<?php

/**
 * Unit tests for the SeedsOpenFiscalPeriods repair helper.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Shillinq\Tests\Unit\Repair\Support;

use OCA\Shillinq\Repair\Support\SeedsOpenFiscalPeriods;
use PHPUnit\Framework\TestCase;

/**
 * A repair step stand-in exposing the trait's helpers.
 */
final class OpenFiscalPeriodSeeder {
	use SeedsOpenFiscalPeriods;

	/**
	 * Run the existence check through the trait.
	 *
	 * @param object $objectService    The object service.
	 * @param string $periodId         The periodId.
	 * @param string $administrationId The administration scope.
	 *
	 * @return bool
	 */
	public function exists(object $objectService, string $periodId, string $administrationId): bool {
		return $this->fiscalPeriodExists(
			objectService: $objectService,
			registerSlug: 'shillinq',
			periodId: $periodId,
			administrationId: $administrationId
		);
	}//end exists()

	/**
	 * Build a record through the trait.
	 *
	 * @param array{periodId:string,name:string,startDate:string,endDate:string,fiscalYear:int} $period           The period.
	 * @param string                                                                            $administrationId The scope.
	 *
	 * @return array<string,mixed>
	 */
	public function record(array $period, string $administrationId): array {
		return $this->openFiscalPeriodRecord(period: $period, administrationId: $administrationId);
	}//end record()

	/**
	 * Name a month through the trait.
	 *
	 * @param int $month The month.
	 *
	 * @return string
	 */
	public function month(int $month): string {
		return $this->monthName(month: $month);
	}//end month()
}//end class

/**
 * @covers \OCA\Shillinq\Repair\Support\SeedsOpenFiscalPeriods
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
 */
final class SeedsOpenFiscalPeriodsTest extends TestCase {

	/**
	 * A recording stand-in for OpenRegister's fluent object service.
	 *
	 * @param array<int,mixed> $result What findAll returns.
	 *
	 * @return object
	 */
	private function service(array $result): object {
		return new class($result) {
			/**
			 * Calls seen, in order.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,mixed> $result What findAll returns.
			 */
			public function __construct(private array $result) {
			}

			/**
			 * Record the register.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->calls[] = ['setRegister', $register];
				return $this;
			}

			/**
			 * Record the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->calls[] = ['setSchema', $schema];
				return $this;
			}

			/**
			 * Record the query.
			 *
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config): array {
				$this->calls[] = ['findAll', $config];
				return $this->result;
			}
		};
	}//end service()

	/**
	 * A scoped lookup filters on periodId and administrationId and finds a match.
	 *
	 * @return void
	 */
	public function testAScopedLookupFiltersOnBothFields(): void {
		$service = $this->service(result: [['id' => 'p1']]);

		$this->assertTrue((new OpenFiscalPeriodSeeder())->exists($service, '2026-03', 'adm-1'));
		$this->assertSame(
			[
				['setRegister', 'shillinq'],
				['setSchema', 'FiscalPeriod'],
				['findAll', ['filters' => ['periodId' => '2026-03', 'administrationId' => 'adm-1'], 'limit' => 1]],
			],
			$service->calls
		);
	}//end testAScopedLookupFiltersOnBothFields()

	/**
	 * An unscoped lookup filters on periodId alone and reports no match on an empty result.
	 *
	 * @return void
	 */
	public function testAnUnscopedLookupFiltersOnPeriodIdAlone(): void {
		$service = $this->service(result: []);

		$this->assertFalse((new OpenFiscalPeriodSeeder())->exists($service, '2026-Q1', ''));
		$this->assertSame(
			['findAll', ['filters' => ['periodId' => '2026-Q1'], 'limit' => 1]],
			$service->calls[2]
		);
	}//end testAnUnscopedLookupFiltersOnPeriodIdAlone()

	/**
	 * The record is open, scoped and carries empty history lists.
	 *
	 * @return void
	 */
	public function testTheRecordIsOpenAndScoped(): void {
		$record = (new OpenFiscalPeriodSeeder())->record(
			['periodId' => '2026-02', 'name' => 'February 2026', 'startDate' => '2026-02-01', 'endDate' => '2026-02-28', 'fiscalYear' => 2026],
			'adm-9'
		);

		$this->assertSame(
			[
				'periodId' => '2026-02',
				'name' => 'February 2026',
				'administrationId' => 'adm-9',
				'startDate' => '2026-02-01',
				'endDate' => '2026-02-28',
				'fiscalYear' => 2026,
				'state' => 'open',
				'reopenedHistory' => [],
				'taskChecklistItems' => [],
				'aiFlags' => [],
			],
			$record
		);
	}//end testTheRecordIsOpenAndScoped()

	/**
	 * Months are named in English from January to December.
	 *
	 * @return void
	 */
	public function testMonthsAreNamedInEnglish(): void {
		$seeder = new OpenFiscalPeriodSeeder();

		$this->assertSame('January', $seeder->month(1));
		$this->assertSame('September', $seeder->month(9));
		$this->assertSame('December', $seeder->month(12));
	}//end testMonthsAreNamedInEnglish()
}//end class
