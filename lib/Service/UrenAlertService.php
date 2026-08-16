<?php

/**
 * Uren Alert Service
 *
 * Quarterly + drempel-omslag alert generator per REQ-URC-003. Fires on:
 *  - the four quarter-end dates (31 Mar, 30 Jun, 30 Sep, 31 Dec) — always informative,
 *  - any drempel-omslag from a lower- to a higher-severity status
 *    (OP_KOERS → RISICO, RISICO → KRITIEK, OP_KOERS → KRITIEK).
 *
 * Every alert carries at least three handelingsperspectief (REQ-URC-003): concrete
 * acties the entrepreneur can take — extra acquisitie-uren, revising planned
 * vakantie, fiscaal-verlies EUR context — derived deterministically from the
 * lopende vs prognose vs norm shape.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Generates UrenAlert records from UrencriteriumYear state transitions and quarter-end dates.
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-13
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class UrenAlertService {

	/**
	 * Quarter-end dates (MM-DD) at which an informative alert fires.
	 *
	 * @var array<int, string>
	 */
	public const QUARTER_END_MD = [
		'03-31',
		'06-30',
		'09-30',
		'12-31',
	];

	/**
	 * Severity-rank of drempel-status (lower → less severe).
	 *
	 * @var array<string, int>
	 */
	private const SEVERITY_RANK = [
		'ACHIEVED' => 0,
		'ON_RATE' => 1,
		'RISK' => 2,
		'CRITICAL' => 3,
	];

	/**
	 * Construct the service.
	 *
	 * @param LoggerInterface $logger Diagnostics logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Decide whether the given Y-m-d is a quarter-end date.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-13
	 */
	public function isKwartaalEinde(string $date): bool {
		return in_array(substr($date, 5), self::QUARTER_END_MD, true);
	}//end isKwartaalEinde()

	/**
	 * Whether the transition from old → new drempel-status is an omslag worth alerting on.
	 *
	 * Higher-severity transitions only (BEHAALD → OP_KOERS does NOT alert).
	 *
	 * @param string $oldStatus Previous status.
	 * @param string $newStatus New status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-13
	 */
	public function isOmslag(string $oldStatus, string $newStatus): bool {
		$oldRank = (self::SEVERITY_RANK[$oldStatus] ?? -1);
		$newRank = (self::SEVERITY_RANK[$newStatus] ?? -1);
		if ($oldRank < 0 || $newRank < 0) {
			return false;
		}

		return ($newRank > $oldRank && $newRank >= self::SEVERITY_RANK['RISK']);
	}//end isOmslag()

	/**
	 * Build a quarterly informative alert for an onderneming.
	 *
	 * @param array<string, mixed> $year UrencriteriumYear record.
	 * @param string $date Quarter-end Y-m-d.
	 *
	 * @return array<string, mixed> UrenAlert shape.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-13
	 */
	public function bouwKwartaalAlert(array $year, string $date): array {
		if ($this->isKwartaalEinde(date: $date) === false) {
			$this->logger->debug(
				'UrenAlertService: bouwKwartaalAlert called on non-quarter-end date',
				['date' => $date]
			);
		}

		$alert = $this->seedAlert(
			year: $year,
			type: 'QUARTER_END',
			urgency: 'INFO',
			triggerDate: $date
		);

		$alert['actionPerspective'] = $this->handelingsperspectief(year: $year);
		$this->logger->info(
			'UrenAlertService: quarter-end alert built',
			[
				'enterpriseId' => $alert['enterpriseId'],
				'date' => $date,
				'thresholdStatus' => ($year['thresholdStatus'] ?? null),
			]
		);

		return $alert;
	}//end bouwKwartaalAlert()

	/**
	 * Build an omslag alert (status drop).
	 *
	 * @param array<string, mixed> $year UrencriteriumYear record.
	 * @param string $oldStatus Previous status.
	 * @param string $newStatus New status.
	 *
	 * @return array<string, mixed> UrenAlert shape.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-13
	 */
	public function bouwOmslagAlert(array $year, string $oldStatus, string $newStatus): array {
		if ($this->isOmslag(oldStatus: $oldStatus, newStatus: $newStatus) === false) {
			$this->logger->debug(
				'UrenAlertService: bouwOmslagAlert called for non-escalating transition',
				['oldStatus' => $oldStatus, 'newStatus' => $newStatus]
			);
		}

		if ($newStatus === 'CRITICAL') {
			$type = 'APPORTIONMENT_CRITICAL';
			$urgency = 'CRITICAL';
		} else {
			$type = 'APPORTIONMENT_RISK';
			$urgency = 'WARNING';
		}

		$alert = $this->seedAlert(
			year: $year,
			type: $type,
			urgency: $urgency,
			triggerDate: gmdate('Y-m-d')
		);

		$alert['cause'] = sprintf(
			'Prognose-omslag van %s naar %s',
			$oldStatus,
			$newStatus
		);
		$alert['actionPerspective'] = $this->handelingsperspectief(year: $year);

		return $alert;
	}//end bouwOmslagAlert()

	/**
	 * Generate handelingsperspectief acties from the year-state. Returns ≥3 acties.
	 *
	 * @param array<string, mixed> $year UrencriteriumYear record.
	 *
	 * @return array<int, string> ≥3 acties.
	 */
	public function handelingsperspectief(array $year): array {
		$prognose = (float)($year['forecastYearEnd'] ?? 0);
		$norm = (int)($year['purposeNorm'] ?? 1225);
		$deficit = max(0.0, ($norm - $prognose));

		$acties = [];

		if ($deficit > 0.0) {
			$acties[] = sprintf(
				'Plan %s extra uur in de resterende maanden om de norm te halen',
				rtrim(rtrim(number_format($deficit, 1, '.', ''), '0'), '.')
			);
			$acties[] = 'Heroverweeg geplande vakantie of niet-essentiële vrije dagen in Q4';
			$acties[] = 'Registreer vergeten administratie- of scholing-uren uit Q1-Q3';
		}

		// Acquisitie-suggestie based on prognose distance.
		if ($prognose < $norm) {
			$acties[] = 'Plan minimaal 20 acquisitie-uren om nieuwe opdrachten te genereren';
		}

		// Fiscaal verlies context (informational).
		if ($deficit > 0.0) {
			$acties[] = sprintf(
				'Bij niet behalen norm: zelfstandigenaftrek + startersaftrek vervalt — '
				. 'gederfd fiscaal voordeel circa %.0f EUR (indicatief, art. 3.76 Wet IB 2001)',
				($deficit * 5.0)
			);
		}

		if (count($acties) < 3) {
			// BEHAALD or OP_KOERS still gets informational acties.
			$acties[] = 'Houd urenregistratie sluitend tot 31 december (zorgvuldigheidsbewijs)';
			$acties[] = 'Genereer kwartaal-evidence-export ter borging (REQ-URC-010)';
			$acties[] = 'Plan tijdig de IB-aangifte-integratie voor jaareinde';
		}

		return array_slice($acties, 0, 5);
	}//end handelingsperspectief()

	/**
	 * Build the canonical alert seed (type, urgentie, etc) shared across alert kinds.
	 *
	 * @param array<string, mixed> $year UrencriteriumYear record.
	 * @param string $type Alert type enum.
	 * @param string $urgency Alert urgency enum.
	 * @param string $triggerDate Trigger date Y-m-d.
	 *
	 * @return array<string, mixed> Alert seed.
	 */
	private function seedAlert(array $year, string $type, string $urgency, string $triggerDate): array {
		$norm = (int)($year['purposeNorm'] ?? 1225);
		$prognose = (float)($year['forecastYearEnd'] ?? 0);
		$deficit = max(0.0, ($norm - $prognose));

		return [
			'administrationId' => (string)($year['administrationId'] ?? ''),
			'enterpriseId' => (string)($year['enterpriseId'] ?? ''),
			'type' => $type,
			'urgency' => $urgency,
			'triggerDate' => $triggerDate,
			'currentHours' => (float)($year['currentHours'] ?? 0),
			'norm' => $norm,
			'forecastYearEnd' => $prognose,
			'deficit' => $deficit,
		];

	}//end seedAlert()
}//end class
