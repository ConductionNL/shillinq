<?php

/**
 * ImportPeriod: the fiscal year holding the migration date decides, and a
 * date no year holds counts as open.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Service\Import\ImportPeriod;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;

/**
 * Whether the migration date falls in an open fiscal year.
 *
 * @covers \OCA\Shillinq\Service\Import\ImportPeriod
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub
 */
final class ImportPeriodTest extends TestCase {

	/**
	 * The period check over these fiscal years.
	 *
	 * @param list<array<string,mixed>> $years The FiscalYear rows.
	 *
	 * @return ImportPeriod
	 */
	private function period(array $years): ImportPeriod {
		return new ImportPeriod(
			objectService: new InMemoryObjectServiceStub(['FiscalYear' => $years]),
			registerLocation: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq'])
		);

	}//end period()

	/**
	 * A year before the date is passed over; the year holding it decides by its state.
	 *
	 * @return void
	 */
	public function testTheYearHoldingTheDateDecides(): void {
		$years = [
			['id' => 'fy-2025', 'administrationId' => 'adm-1', 'startDate' => '2025-01-01', 'endDate' => '2025-12-31', 'state' => 'open'],
			['id' => 'fy-2026', 'administrationId' => 'adm-1', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'closed'],
		];

		$this->assertFalse($this->period($years)->isOpen('adm-1', '2026-03-01'));
		$this->assertTrue($this->period($years)->isOpen('adm-1', '2025-06-30'));

	}//end testTheYearHoldingTheDateDecides()

	/**
	 * A reopened year is open, and a date no year holds counts as open.
	 *
	 * @return void
	 */
	public function testAReopenedYearOrNoYearAtAllIsOpen(): void {
		$years = [['id' => 'fy-2026', 'administrationId' => 'adm-1', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'reopened']];

		$this->assertTrue($this->period($years)->isOpen('adm-1', '2026-12-31'));
		$this->assertTrue($this->period($years)->isOpen('adm-1', '2027-01-01'));
		$this->assertTrue($this->period([])->isOpen('adm-1', '2026-01-01'));

	}//end testAReopenedYearOrNoYearAtAllIsOpen()
}//end class
