<?php

/**
 * Golden tests for the urencriterium alerts: full alert shapes, the logged context and every action list.
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
 * The alert builders return the same shapes, log the same lines and list the same actions.
 */
final class UrenAlertServiceGoldenTest extends TestCase {

	private const TEKORT = [
		'Plan 75 extra uur in de resterende maanden om de norm te halen',
		'Heroverweeg geplande vakantie of niet-essentiële vrije dagen in Q4',
		'Registreer vergeten administratie- of scholing-uren uit Q1-Q3',
		'Plan minimaal 20 acquisitie-uren om nieuwe opdrachten te genereren',
		'Bij niet behalen norm: zelfstandigenaftrek + startersaftrek vervalt — gederfd fiscaal voordeel circa 375 EUR (indicatief, art. 3.76 Wet IB 2001)',
	];

	private const BEHAALD = [
		'Houd urenregistratie sluitend tot 31 december (zorgvuldigheidsbewijs)',
		'Genereer kwartaal-evidence-export ter borging (REQ-URC-010)',
		'Plan tijdig de IB-aangifte-integratie voor jaareinde',
	];

	/**
	 * A year record.
	 *
	 * @param float $forecast The forecast at year end.
	 *
	 * @return array<string, mixed>
	 */
	private function year(float $forecast): array {
		return [
			'administrationId' => 'adm-1',
			'enterpriseId'     => 'ond-1',
			'purposeNorm'      => 1225,
			'currentHours'     => 700.0,
			'forecastYearEnd'  => $forecast,
			'thresholdStatus'  => 'RISK',
		];

	}//end year()

	/**
	 * The alert fields every builder seeds.
	 *
	 * @param string $type    The alert type.
	 * @param string $urgency The urgency.
	 * @param string $date    The trigger date.
	 *
	 * @return array<string, mixed>
	 */
	private function seeded(string $type, string $urgency, string $date): array {
		return [
			'administrationId' => 'adm-1',
			'enterpriseId'     => 'ond-1',
			'type'             => $type,
			'urgency'          => $urgency,
			'triggerDate'      => $date,
			'currentHours'     => 700.0,
			'norm'             => 1225,
			'forecastYearEnd'  => 1150.0,
			'deficit'          => 75.0,
		];

	}//end seeded()

	/**
	 * The quarter-end alert and its log lines, on and off a quarter end.
	 *
	 * @return void
	 */
	public function testKwartaalAlertGolden(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'UrenAlertService: bouwKwartaalAlert called on non-quarter-end date',
			['date' => '2026-09-29']
		);
		$infos = [];
		$logger->expects($this->exactly(2))->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$infos): void {
				$infos[] = [$message, $context];
			}
		);
		$service = new UrenAlertService(logger: $logger);

		$alert = $service->bouwKwartaalAlert(year: $this->year(forecast: 1150.0), date: '2026-09-30');
		$service->bouwKwartaalAlert(year: $this->year(forecast: 1150.0), date: '2026-09-29');

		$this->assertSame($this->seeded(type: 'QUARTER_END', urgency: 'INFO', date: '2026-09-30') + ['actionPerspective' => self::TEKORT], $alert);
		$context = ['enterpriseId' => 'ond-1', 'date' => '2026-09-30', 'thresholdStatus' => 'RISK'];
		$this->assertSame(['UrenAlertService: quarter-end alert built', $context], $infos[0]);
		$this->assertSame(['UrenAlertService: quarter-end alert built', ['date' => '2026-09-29'] + $context], [$infos[1][0], ['date' => $infos[1][1]['date']] + $infos[1][1]]);
		$this->assertSame(['enterpriseId', 'date', 'thresholdStatus'], array_keys($infos[1][1]));

	}//end testKwartaalAlertGolden()

	/**
	 * The omslag alerts for risk and critical, and the debug line on a non-escalating call.
	 *
	 * @return void
	 */
	public function testOmslagAlertGolden(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'UrenAlertService: bouwOmslagAlert called for non-escalating transition',
			['oldStatus' => 'CRITICAL', 'newStatus' => 'RISK']
		);
		$service = new UrenAlertService(logger: $logger);
		$today   = gmdate('Y-m-d');

		$this->assertSame(
			$this->seeded(type: 'APPORTIONMENT_CRITICAL', urgency: 'CRITICAL', date: $today)
			+ ['cause' => 'Prognose-omslag van RISK naar CRITICAL', 'actionPerspective' => self::TEKORT],
			$service->bouwOmslagAlert(year: $this->year(forecast: 1150.0), oldStatus: 'RISK', newStatus: 'CRITICAL')
		);
		$this->assertSame(
			$this->seeded(type: 'APPORTIONMENT_RISK', urgency: 'WARNING', date: $today)
			+ ['cause' => 'Prognose-omslag van CRITICAL naar RISK', 'actionPerspective' => self::TEKORT],
			$service->bouwOmslagAlert(year: $this->year(forecast: 1150.0), oldStatus: 'CRITICAL', newStatus: 'RISK')
		);

	}//end testOmslagAlertGolden()

	/**
	 * Every action list: a deficit, a fractional deficit, the norm reached, and no forecast.
	 *
	 * @return void
	 */
	public function testHandelingsperspectiefGolden(): void {
		$service = new UrenAlertService(logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(self::TEKORT, $service->handelingsperspectief(year: $this->year(forecast: 1150.0)));
		$this->assertSame(self::BEHAALD, $service->handelingsperspectief(year: $this->year(forecast: 1225.0)));
		$this->assertSame(self::BEHAALD, $service->handelingsperspectief(year: $this->year(forecast: 1400.0)));
		$this->assertSame(
			[
				'Plan 0.5 extra uur in de resterende maanden om de norm te halen',
				self::TEKORT[1],
				self::TEKORT[2],
				self::TEKORT[3],
				'Bij niet behalen norm: zelfstandigenaftrek + startersaftrek vervalt — gederfd fiscaal voordeel circa 2 EUR (indicatief, art. 3.76 Wet IB 2001)',
			],
			$service->handelingsperspectief(year: $this->year(forecast: 1224.5))
		);
		$this->assertSame(
			[
				'Plan 1225 extra uur in de resterende maanden om de norm te halen',
				self::TEKORT[1],
				self::TEKORT[2],
				self::TEKORT[3],
				'Bij niet behalen norm: zelfstandigenaftrek + startersaftrek vervalt — gederfd fiscaal voordeel circa 6125 EUR (indicatief, art. 3.76 Wet IB 2001)',
			],
			$service->handelingsperspectief(year: [])
		);

	}//end testHandelingsperspectiefGolden()
}//end class
