<?php

/**
 * Unit tests for UrenAlertService.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\UrenAlertService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-003: alert-trigger quarterly + drempel-omslag with handelingsperspectief.
 */
final class UrenAlertServiceTest extends TestCase {

	/**
	 * Build a service.
	 *
	 * @return UrenAlertService
	 */
	private function build(): UrenAlertService {
		return new UrenAlertService(logger: $this->createMock(LoggerInterface::class));
	}//end build()

	/**
	 * Quarter-end dates are detected.
	 *
	 * @return void
	 */
	public function testIsKwartaalEindeRecognisesAllFour(): void {
		$service = $this->build();
		self::assertTrue($service->isKwartaalEinde(date: '2026-03-31'));
		self::assertTrue($service->isKwartaalEinde(date: '2026-06-30'));
		self::assertTrue($service->isKwartaalEinde(date: '2026-09-30'));
		self::assertTrue($service->isKwartaalEinde(date: '2026-12-31'));
		self::assertFalse($service->isKwartaalEinde(date: '2026-04-15'));

	}//end testIsKwartaalEindeRecognisesAllFour()

	/**
	 * Omslag from OP_KOERS to RISICO triggers, BEHAALD → OP_KOERS does not.
	 *
	 * @return void
	 */
	public function testIsOmslagOnlyOnHigherSeverity(): void {
		$service = $this->build();

		self::assertTrue($service->isOmslag(oldStatus: 'ON_RATE', newStatus: 'RISK'));
		self::assertTrue($service->isOmslag(oldStatus: 'RISK', newStatus: 'CRITICAL'));
		self::assertTrue($service->isOmslag(oldStatus: 'ON_RATE', newStatus: 'CRITICAL'));

		// Going safer is NOT an omslag worth alerting on.
		self::assertFalse($service->isOmslag(oldStatus: 'CRITICAL', newStatus: 'RISK'));
		self::assertFalse($service->isOmslag(oldStatus: 'ACHIEVED', newStatus: 'ON_RATE'));

		// Unknown statuses don't alert.
		self::assertFalse($service->isOmslag(oldStatus: 'UNKNOWN', newStatus: 'CRITICAL'));

	}//end testIsOmslagOnlyOnHigherSeverity()

	/**
	 * Kwartaal-alert sets type, urgentie INFO, ≥3 acties.
	 *
	 * @return void
	 */
	public function testKwartaalAlertShape(): void {
		$alert = $this->build()->bouwKwartaalAlert(
			year: [
				'administrationId' => 'adm-1',
				'enterpriseId' => 'ond-1',
				'purposeNorm' => 1225,
				'currentHours' => 700.0,
				'forecastYearEnd' => 1150.0,
				'thresholdStatus' => 'RISK',
			],
			date: '2026-09-30'
		);

		self::assertSame('QUARTER_END', $alert['type']);
		self::assertSame('INFO', $alert['urgency']);
		self::assertSame('2026-09-30', $alert['triggerDate']);
		self::assertSame(75.0, $alert['deficit']);
		self::assertGreaterThanOrEqual(3, count($alert['actionPerspective']));

	}//end testKwartaalAlertShape()

	/**
	 * Omslag to KRITIEK builds an OMSLAG_KRITIEK alert with urgentie KRITIEK.
	 *
	 * @return void
	 */
	public function testOmslagToKritiek(): void {
		$alert = $this->build()->bouwOmslagAlert(
			year: [
				'administrationId' => 'adm-1',
				'enterpriseId' => 'ond-1',
				'purposeNorm' => 1225,
				'currentHours' => 600.0,
				'forecastYearEnd' => 900.0,
				'thresholdStatus' => 'CRITICAL',
			],
			oldStatus: 'RISK',
			newStatus: 'CRITICAL'
		);

		self::assertSame('APPORTIONMENT_CRITICAL', $alert['type']);
		self::assertSame('CRITICAL', $alert['urgency']);
		self::assertStringContainsString('RISK', $alert['cause']);
		self::assertStringContainsString('CRITICAL', $alert['cause']);
		self::assertGreaterThanOrEqual(3, count($alert['actionPerspective']));

	}//end testOmslagToKritiek()

	/**
	 * Handelingsperspectief always returns ≥3 acties even when BEHAALD.
	 *
	 * @return void
	 */
	public function testHandelingsperspectiefMinimumWhenBehaald(): void {
		$acties = $this->build()->handelingsperspectief(
			year: [
				'purposeNorm' => 1225,
				'currentHours' => 1250.0,
				'forecastYearEnd' => 1400.0,
				'thresholdStatus' => 'ACHIEVED',
			]
		);

		self::assertGreaterThanOrEqual(3, count($acties));

	}//end testHandelingsperspectiefMinimumWhenBehaald()

	/**
	 * Handelingsperspectief mentions fiscal verlies EUR when there is a tekort.
	 *
	 * @return void
	 */
	public function testHandelingsperspectiefMentionsFiscaalVerliesOnTekort(): void {
		$acties = $this->build()->handelingsperspectief(
			year: [
				'purposeNorm' => 1225,
				'currentHours' => 400.0,
				'forecastYearEnd' => 800.0,
			]
		);

		$joined = implode(' || ', $acties);
		self::assertStringContainsString('EUR', $joined);
		self::assertStringContainsString('acquisitie', strtolower($joined));

	}//end testHandelingsperspectiefMentionsFiscaalVerliesOnTekort()

}//end class
