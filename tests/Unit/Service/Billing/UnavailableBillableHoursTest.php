<?php

/**
 * Unit tests for UnavailableBillableHours.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Billing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Billing;

use OCA\Shillinq\Service\Billing\BillableHoursSource;
use OCA\Shillinq\Service\Billing\UnavailableBillableHours;
use PHPUnit\Framework\TestCase;

/**
 * REQ-STEB-001: without a connected hours app the source says the hours are
 * unavailable, never that there are none, and the app binds it by default.
 *
 * @covers \OCA\Shillinq\Service\Billing\UnavailableBillableHours
 */
final class UnavailableBillableHoursTest extends TestCase {

	public function testTheHoursAreUnavailableWithTheReason(): void {
		$source = new UnavailableBillableHours();

		$this->assertInstanceOf(BillableHoursSource::class, $source);
		$this->assertSame(
			['available' => false, 'reason' => 'no-hours-source', 'hours' => []],
			$source->unbilled(projectId: 'p-1', from: '2026-10-01', to: '2026-10-31')
		);
	}//end testTheHoursAreUnavailableWithTheReason()

	public function testTheAppBindsItAsTheHoursSourceUntilHumaniqIsBound(): void {
		$application = (string)file_get_contents(__DIR__ . '/../../../../lib/AppInfo/Application.php');

		$this->assertStringContainsString('registerServiceAlias(BillableHoursSource::class, UnavailableBillableHours::class)', $application);
	}//end testTheAppBindsItAsTheHoursSourceUntilHumaniqIsBound()
}//end class
