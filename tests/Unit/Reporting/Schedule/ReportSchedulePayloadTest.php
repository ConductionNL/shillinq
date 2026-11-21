<?php

/**
 * The schedule the report dialog posts is one the register accepts.
 *
 * The payload below is the one tests/vitest/reportingDataDelivery.spec.js
 * pins as the output of schedulePayload() for "Budget versus realisatie",
 * so the two tests together prove the dialog writes a valid ReportSchedule.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Schedule;

use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Validates the dialog's schedule payload against the real register fragment.
 */
class ReportSchedulePayloadTest extends TestCase {

	/**
	 * Scenario "The monthly budget report is scheduled": the posted schedule validates.
	 *
	 * @return void
	 */
	public function testTheDialogSchedulePayloadValidates(): void {
		$payload = [
			'administrationId' => 'adm-voorbeeld',
			'name'             => 'Budget versus realisatie',
			'reportType'       => 'budget-vs-actual',
			'format'           => 'pdf',
			'frequency'        => 'monthly',
			'runDay'           => 5,
			'periodRule'       => 'previous-period',
			'recipients'       => ['group:controllers'],
			'folderPath'       => '/Rapportages/Maand',
			'nextRunAt'        => '2026-10-05T00:00:00+00:00',
			'status'           => 'active',
		];

		self::assertSame([], RegisterSchema::errors('ReportSchedule', $payload));

	}//end testTheDialogSchedulePayloadValidates()

	/**
	 * A schedule with an unknown frequency is refused by the register.
	 *
	 * @return void
	 */
	public function testAnUnknownFrequencyIsRefused(): void {
		$payload = [
			'administrationId' => 'adm-voorbeeld',
			'name'             => 'Budget versus realisatie',
			'reportType'       => 'budget-vs-actual',
			'format'           => 'pdf',
			'frequency'        => 'daily',
			'runDay'           => 5,
			'periodRule'       => 'previous-period',
			'recipients'       => ['group:controllers'],
			'folderPath'       => '/Rapportages/Maand',
			'nextRunAt'        => '2026-10-05T00:00:00+00:00',
		];

		self::assertNotSame([], RegisterSchema::errors('ReportSchedule', $payload));

	}//end testAnUnknownFrequencyIsRefused()
}//end class
