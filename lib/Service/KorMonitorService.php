<?php

/**
 * KOR Monitor Service
 *
 * Tier-2 read-only KOR threshold-bewaking (REQ-KOR-002, REQ-KOR-003). Materialises
 * the running KOR revenue, threshold-benutting, monthly breakdown, linear end-of-year
 * prognose, and the highest reached 80/90/100 % alert-schijf for one administration
 * + calendar year from existing KORRegistration + ARInvoice data via the real
 * OpenRegister ObjectService API (find / findAll). There is NO KORAnnualTurnover
 * record authored by operators here; the rows are computed on demand and the
 * equivalent declarative aggregation shape is documented on the schema
 * (x-openregister-aggregations.korTurnoverByYear) per ADR-031.
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
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes the KOR threshold-status for one administration + year from the AR ledger.
 *
 * Reads are scoped to a single administration (REQ-KOR-002 IDOR safety): callers
 * pass the administrationId resolved from the authenticated user's context, never a
 * client-supplied trust boundary. The KOR revenue is summed over KOR-eligible AR
 * invoices (vrijstellingsGrondslag = KOR_ART25_OB) whose leveringsDatum is in the
 * year; vrijgestelde / intracommunautaire / onroerend-goed revenue is excluded because
 * it never carries that basis. The arithmetic lives in KorThresholdCalculator.
 *
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */
class KorMonitorService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param KorThresholdCalculator $calculator Pure-logic KOR arithmetic helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly KorThresholdCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the KOR threshold-status for one administration + year (REQ-KOR-002, REQ-KOR-003).
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-KOR-002).
	 * @param int $year Calendar year to report.
	 *
	 * @return array{
	 *   administrationId:string, year:int, currentRevenue:float, threshold:float,
	 *   thresholdUtilisation:float, perMonth:array<string,float>, forecastYearEnd:float,
	 *   prognoseStatus:string, severity:?string, trigger:?string, optOutPermitted:bool
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function status(string $administrationId, int $year): array {
		$thresholdCents = $this->resolveDrempelCents(administrationId: $administrationId);
		$invoices = $this->fetchKorInvoices(administrationId: $administrationId, year: $year);

		$revenueCents = $this->calculator->runningOmzetCents(invoices: $invoices, year: $year);
		$perMonth = $this->monthlyBreakdown(invoices: $invoices, year: $year);

		$currentMonth = $this->lastMonthWithOmzet(perMonth: $perMonth);
		$prognoseCents = $this->calculator->prognoseEndOfYearCents(
			lopendeCents: $revenueCents,
			currentMonth: $currentMonth
		);

		$benutting = $this->calculator->benutting(revenueCents: $revenueCents, thresholdCents: $thresholdCents);
		$schijf = $this->calculator->crossedSchijf(previousUtilisation: 0.0, newUtilisation: $benutting);

		return [
			'administrationId' => $administrationId,
			'year' => $year,
			'currentRevenue' => $this->calculator->fromCents(cents: $revenueCents),
			'threshold' => $this->calculator->fromCents(cents: $thresholdCents),
			'thresholdUtilisation' => round($benutting, 4),
			'perMonth' => $perMonth,
			'forecastYearEnd' => $this->calculator->fromCents(cents: $prognoseCents),
			'prognoseStatus' => $this->calculator->prognoseStatus(
				prognoseCents: $prognoseCents,
				thresholdCents: $thresholdCents
			),
			'severity' => ($schijf['severity'] ?? null),
			'trigger' => ($schijf['trigger'] ?? null),
			'optOutPermitted' => $this->resolveOptOutPermitted(administrationId: $administrationId),
		];

	}//end status()

	/**
	 * Resolve whether the administration's ACTIEF KOR-registratie may opt out today (REQ-KOR-007).
	 *
	 * Composes the calculator's lock-in arithmetic with the persisted registration
	 * window so the manifest's KorOpzegging page can gate the lifecycle action.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return bool True when an operator may opt out today.
	 */
	private function resolveOptOutPermitted(string $administrationId): bool {
		try {
			$registrations = $this->objectService
				->setRegister($this->register())
				->setSchema('KORRegistration')
				->findAll(
					['filters' => ['administrationId' => $administrationId, 'status' => 'ACTIEF']]
				);
		} catch (\Throwable $e) {
			return false;
		}

		$today = date('Y-m-d');
		foreach ($registrations as $registration) {
			$vroegste = (string)($registration['earliestTerminationDate'] ?? '');
			$eind = (string)($registration['lockInEndDate'] ?? '');
			if ($this->calculator->isOptOutPermitted(
				today: $today,
				earliestTerminationDate: $vroegste,
				lockInEndDate: $eind
			) === true
			) {
				return true;
			}
		}

		return false;
	}//end resolveOptOutPermitted()

	/**
	 * Fetch the administration's KOR-eligible AR invoices for the year (REQ-KOR-002).
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $year Calendar year.
	 *
	 * @return array<int,array<string,mixed>> KOR-eligible AR-invoice records.
	 */
	private function fetchKorInvoices(string $administrationId, int $year): array {
		$invoices = $this->objectService
			->setRegister($this->register())
			->setSchema('ARInvoice')
			->findAll(
				['filters' => ['administrationId' => $administrationId, 'vrijstellingsGrondslag' => 'KOR_ART25_OB']]
			);

		$eligible = [];
		foreach ($invoices as $invoice) {
			if ($this->calculator->isKorEligible(invoice: $invoice, year: $year) === true) {
				$eligible[] = $invoice;
			}
		}

		return $eligible;
	}//end fetchKorInvoices()

	/**
	 * Build the YYYY-MM => revenue breakdown for the year (REQ-KOR-002).
	 *
	 * @param array<int,array<string,mixed>> $invoices KOR-eligible AR invoices.
	 * @param int $year Calendar year.
	 *
	 * @return array<string,float> Month key (YYYY-MM) => revenue.
	 */
	private function monthlyBreakdown(array $invoices, int $year): array {
		$cents = [];
		foreach ($invoices as $invoice) {
			$leveringsDatum = (string)($invoice['leveringsDatum'] ?? ($invoice['invoiceDate'] ?? ''));
			$month = substr($leveringsDatum, 0, 7);
			if ($month === '' || substr($month, 0, 4) !== (string)$year) {
				continue;
			}

			$cents[$month] = (($cents[$month] ?? 0) + $this->calculator->toCents(amount: ($invoice['amount'] ?? ($invoice['netAmount'] ?? 0))));
		}

		ksort($cents);
		$perMonth = [];
		foreach ($cents as $month => $value) {
			$perMonth[$month] = $this->calculator->fromCents(cents: $value);
		}

		return $perMonth;
	}//end monthlyBreakdown()

	/**
	 * Resolve the latest calendar month that has revenue, for the prognose base (REQ-KOR-002).
	 *
	 * @param array<string,float> $perMonth Month key (YYYY-MM) => revenue.
	 *
	 * @return int Latest month number with revenue (1..12); 1 when none.
	 */
	private function lastMonthWithOmzet(array $perMonth): int {
		$last = 1;
		foreach (array_keys($perMonth) as $month) {
			$num = (int)substr((string)$month, 5, 2);
			if ($num > $last) {
				$last = $num;
			}
		}

		return $last;
	}//end lastMonthWithOmzet()

	/**
	 * Resolve the configured KOR threshold for an administration, in cents (REQ-KOR-002).
	 *
	 * Defaults to EUR 20.000 when no ACTIEF KORRegistration overrides thresholdYear.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return int Threshold in cents.
	 */
	private function resolveDrempelCents(string $administrationId): int {
		$default = $this->calculator->toCents(amount: 20000);

		try {
			$registrations = $this->objectService
				->setRegister($this->register())
				->setSchema('KORRegistration')
				->findAll(
					['filters' => ['administrationId' => $administrationId, 'status' => 'ACTIEF']]
				);
		} catch (\Throwable $e) {
			return $default;
		}

		foreach ($registrations as $registration) {
			$threshold = ($registration['thresholdYear'] ?? null);
			if ($threshold !== null) {
				return $this->calculator->toCents(amount: $threshold);
			}
		}

		return $default;
	}//end resolveDrempelCents()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
