<?php

/**
 * Payroll Jaaropgave (Annual Statement) Service
 *
 * Aggregates all LoonStrook records for a (werknemer, jaar) into a per-employee
 * annual statement (Jaaropgave) per REQ-PAY-013. The payload contains the YTD
 * sums of fiscaalLoon, loonheffing, premies SV werkgever, ZVW werkgever,
 * pensioenpremie (werknemer aandeel) and uitgekeerd vakantietoeslag, plus a
 * consistency flag that asserts the period totals match the cumulatieven
 * snapshots stored on the strook (design.md D3). The persistence layer is
 * the regular OpenRegister ObjectService API (saveObject), scoped to the
 * administrationId resolved server-side from the caller's context — never a
 * client-supplied trust boundary (ADR-022).
 *
 * The PDF rendering and Digipoort SBR-submission stay with the bookkeeping
 * template engine and the bookkeeping-loonaangifte-sbr app respectively; this
 * service produces the canonical, machine-verifiable payload they consume.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Aggregates the yearly Jaaropgave per employee from the period loonstroken.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * @SuppressWarnings(PHPMD.ShortVariable) Pre-existing debt (issue #506):
 *     not in the project's curated idiomatic-abbreviation allowlist;
 *     deferred pending a dedicated rename pass.
 */
class PayrollJaaropgaveService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param PayrollCalculator $calculator Cents arithmetic helper (no IO).
	 * @param LoggerInterface $logger Logger (no BSN / special-category data).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly PayrollCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build a Jaaropgave payload for an employee and calendar year (REQ-PAY-013).
	 *
	 * Reads every LoonStrook for the (administration, werknemer, jaar) tuple
	 * and sums the relevant amounts in integer cents (no float drift). The
	 * cumulatieven snapshot of the last period of the year is captured as the
	 * "ytdSnapshot" and compared to the period-by-period sum; consistent is
	 * false when they disagree, which the dashboard surfaces as a warning.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $employeeId Employee id.
	 * @param int $year Calendar year.
	 *
	 * @return array<string,mixed> The Jaaropgave payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function bouwJaaropgave(string $administrationId, string $employeeId, int $year): array {
		$stroken = $this->findPayStrokenForYear(
			administrationId: $administrationId,
			employeeId: $employeeId,
			year: $year
		);

		if ($stroken === []) {
			// An employee with no payslips for the year still yields a jaaropgave —
			// an all-zero one, which is indistinguishable from a correctly-zero year.
			// Record it so the difference is auditable. Identifiers only: never the
			// BSN or any special-category field.
			$this->logger->warning(
				'Jaaropgave built from zero payslips — the annual statement will be all-zero.',
				[
					'administrationId' => $administrationId,
					'employeeId' => $employeeId,
					'year' => $year,
				]
			);
		}

		$fiscalC = 0;
		$payrollTaxC = 0;
		$svWgC = 0;
		$zvwWgC = 0;
		$pensWnC = 0;
		$pensWgC = 0;
		$vakUitbC = 0;
		$netC = 0;
		$ytdFiscal = 0.0;
		$ytdVak = 0.0;
		foreach ($stroken as $s) {
			$fiscalC += $this->calculator->toCents(amount: ($s['fiscalPay'] ?? 0));
			$payrollTaxC += $this->calculator->toCents(amount: ($s['payrollTax'] ?? 0));
			$svWgC += $this->calculator->toCents(amount: ($s['employerSocialInsurancePremiums']['totaal_werkgever'] ?? 0));
			$zvwWgC += $this->calculator->toCents(amount: ($s['zvw']['afgedragen_wg'] ?? 0));
			$pensWnC += $this->calculator->toCents(amount: ($s['pension']['premie_wn_aandeel'] ?? 0));
			$pensWgC += $this->calculator->toCents(amount: ($s['pension']['premie_wg_aandeel'] ?? 0));
			$vakUitbC += $this->calculator->toCents(amount: ($s['grossComponents']['vakantietoeslag_uitbetaling'] ?? 0));
			$netC += $this->calculator->toCents(amount: ($s['netPaid'] ?? 0));

			$cu = ($s['cumulatieven'] ?? []);
			if (is_array($cu) === true) {
				$ytdFiscal = (float)($cu['fiscaalloon_ytd'] ?? $ytdFiscal);
				$ytdVak = (float)($cu['vakantiegeld_reservering_ytd'] ?? $ytdVak);
			}
		}//end foreach

		$fiscalPay = $this->calculator->fromCents(cents: $fiscalC);
		$cumulatievenMatch = ($this->calculator->toCents(amount: $ytdFiscal) === $fiscalC);

		return [
			'employeeId' => $employeeId,
			'year' => $year,
			'aantalPerioden' => count($stroken),
			'fiscaalLoonJTD' => $fiscalPay,
			'loonheffingJTD' => $this->calculator->fromCents(cents: $payrollTaxC),
			'premiesSVWgJTD' => $this->calculator->fromCents(cents: $svWgC),
			'zvwWgJTD' => $this->calculator->fromCents(cents: $zvwWgC),
			'pensioenWnJTD' => $this->calculator->fromCents(cents: $pensWnC),
			'pensioenWgJTD' => $this->calculator->fromCents(cents: $pensWgC),
			'vakantieUitbJTD' => $this->calculator->fromCents(cents: $vakUitbC),
			'nettoUitbetaaldJTD' => $this->calculator->fromCents(cents: $netC),
			'ytdSnapshot' => [
				'fiscaalloon_ytd' => $ytdFiscal,
				'vakantiegeld_reservering_ytd' => $ytdVak,
			],
			'cumulatievenConsistent' => $cumulatievenMatch,
			'administrationId' => $administrationId,
			'status' => 'CONCEPT',
		];

	}//end bouwJaaropgave()

	/*
	 * NO JAAROPGAVE PERSISTENCE HERE.
	 *
	 * `persistJaaropgave()` stood here with no caller — the service builds a
	 * Jaaropgave and returns it; nothing handed it back for a save, so no
	 * Jaaropgave object was ever written through it. Its cumulatieven guard
	 * was, for the same reason, never executed. Like the payroll balance
	 * guard, that invariant belongs on the Jaaropgave schema in OpenRegister
	 * where it binds every writer, not on a method with no callers.
	 */

	/**
	 * Read every LoonStrook for the calendar year, administration-scoped.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $employeeId Employee id.
	 * @param int $year Calendar year.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findPayStrokenForYear(string $administrationId, string $employeeId, int $year): array {
		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema('LoonStrook')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'employeeId' => $employeeId,
					],
				]
			);

		$out = [];
		foreach ($results as $r) {
			$row = (array)$r;
			$perYear = $this->extractYearFromPeriodId(periodId: (string)($row['periodId'] ?? ''));
			if ($perYear !== null && $perYear !== $year) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end findLoonStrokenVoorJaar()

	/**
	 * Best-effort year extractor from a periodeId of shape lp-YYYY-...
	 *
	 * @param string $periodId Period id.
	 *
	 * @return int|null Year or null when not encoded.
	 */
	private function extractYearFromPeriodId(string $periodId): ?int {
		if (preg_match('/(?<year>20[0-9]{2})/', $periodId, $m) === 1) {
			return (int)$m['year'];
		}

		return null;
	}//end extractJaarFromPeriodeId()

	/**
	 * Lazily fetch OpenRegister's ObjectService.
	 *
	 * @return object The ObjectService.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug.
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
