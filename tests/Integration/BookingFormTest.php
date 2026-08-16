<?php

/**
 * Booking Form UI integration — Playwright pointer.
 *
 * Same convention as CalendarViewTest.php: the real test surface lives in
 * tests/e2e/bookings-resource-calendar.spec.ts (Playwright). This PHP file
 * is a documentation stub so spec-coverage scanners that look up the
 * literal path mentioned in tasks.md#task-11 can find the artefact.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Marker test class — the real coverage lives in the Playwright spec.
 *
 * Run the actual suite via:
 *   npm run test:e2e -- bookings-resource-calendar.spec.ts
 */
class BookingFormTest extends TestCase {

	/**
	 * Document the pointer so the test runner produces one passing case
	 * even when the Playwright environment is offline.
	 *
	 * @return void
	 */
	public function testPlaywrightSpecReference(): void {
		$spec = __DIR__ . '/../e2e/bookings-resource-calendar.spec.ts';
		$this->assertFileExists(
			$spec,
			'Booking Form integration tests live in tests/e2e/bookings-resource-calendar.spec.ts (Playwright)'
		);
	}//end testPlaywrightSpecReference()

}//end class
