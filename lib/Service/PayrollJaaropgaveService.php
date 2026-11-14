<?php

/**
 * Payroll Jaaropgave (Annual Statement) Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
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
		$stroken = $this->findPayStrokenForYear(administrationId: $administrationId, employeeId: $employeeId, year: $year);

		if ($stroken === []) {
			$this->logZeroPayslips(administrationId: $administrationId, employeeId: $employeeId, year: $year);
		}

		$totals = $this->yearTotals(stroken: $stroken);

		return [
			'employeeId' => $employeeId,
			'year' => $year,
			'aantalPerioden' => count($stroken),
		] + $this->jtdAmounts(totals: $totals) + $this->snapshotAndStatus(totals: $totals, administrationId: $administrationId);

	}//end bouwJaaropgave()

	/**
	 * The jaaropgave's year-to-date snapshot, its consistency with the payslips, and its concept status.
	 *
	 * @param array<string, int|float> $totals           The year's totals.
	 * @param string                   $administrationId The administration.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshotAndStatus(array $totals, string $administrationId): array {
		return [
			'ytdSnapshot' => [
				'fiscaalloon_ytd' => $totals['ytdFiscal'],
				'vakantiegeld_reservering_ytd' => $totals['ytdVak'],
			],
			'cumulatievenConsistent' => ($this->calculator->toCents(amount: $totals['ytdFiscal']) === $totals['fiscal']),
			'administrationId' => $administrationId,
			'status' => 'CONCEPT',
		];

	}//end snapshotAndStatus()

	/**
	 * An employee with no payslips for the year still yields a jaaropgave —
	 * an all-zero one, which is indistinguishable from a correctly-zero year.
	 * Record it so the difference is auditable. Identifiers only: never the
	 * BSN or any special-category field.
	 *
	 * @param string $administrationId The administration.
	 * @param string $employeeId       The employee.
	 * @param int    $year             The calendar year.
	 *
	 * @return void
	 */
	private function logZeroPayslips(string $administrationId, string $employeeId, int $year): void {
		$this->logger->warning(
			'Jaaropgave built from zero payslips — the annual statement will be all-zero.',
			[
				'administrationId' => $administrationId,
				'employeeId' => $employeeId,
				'year' => $year,
			]
		);

	}//end logZeroPayslips()

	/**
	 * The year's totals in cents, with the last cumulative year-to-date snapshot.
	 *
	 * @param array<int, array<string, mixed>> $stroken The year's payslips.
	 *
	 * @return array<string, int|float>
	 */
	private function yearTotals(array $stroken): array {
		$totals = [
			'fiscal' => 0, 'payrollTax' => 0, 'svWg' => 0, 'zvwWg' => 0, 'pensWn' => 0, 'pensWg' => 0, 'vakUitb' => 0, 'net' => 0,
			'ytdFiscal' => 0.0, 'ytdVak' => 0.0,
		];
		foreach ($stroken as $s) {
			$totals = $this->withStrook(totals: $totals, strook: $s);
		}

		return $totals;

	}//end yearTotals()

	/**
	 * Add one payslip's amounts to the totals and take its cumulative snapshot.
	 *
	 * @param array<string, int|float> $totals The totals so far.
	 * @param array<string, mixed>     $strook One payslip.
	 *
	 * @return array<string, int|float>
	 */
	private function withStrook(array $totals, array $strook): array {
		$totals['fiscal']     += $this->calculator->toCents(amount: ($strook['fiscalPay'] ?? 0));
		$totals['payrollTax'] += $this->calculator->toCents(amount: ($strook['payrollTax'] ?? 0));
		$totals['svWg']       += $this->calculator->toCents(amount: ($strook['employerSocialInsurancePremiums']['totaal_werkgever'] ?? 0));
		$totals['zvwWg']      += $this->calculator->toCents(amount: ($strook['zvw']['afgedragen_wg'] ?? 0));
		$totals['pensWn']     += $this->calculator->toCents(amount: ($strook['pension']['premie_wn_aandeel'] ?? 0));
		$totals['pensWg']     += $this->calculator->toCents(amount: ($strook['pension']['premie_wg_aandeel'] ?? 0));
		$totals['vakUitb']    += $this->calculator->toCents(amount: ($strook['grossComponents']['vakantietoeslag_uitbetaling'] ?? 0));
		$totals['net']        += $this->calculator->toCents(amount: ($strook['netPaid'] ?? 0));

		return self::withSnapshot(totals: $totals, strook: $strook);

	}//end withStrook()

	/**
	 * Take the payslip's cumulative year-to-date snapshot when it carries one.
	 *
	 * @param array<string, int|float> $totals The totals so far.
	 * @param array<string, mixed>     $strook One payslip.
	 *
	 * @return array<string, int|float>
	 */
	private static function withSnapshot(array $totals, array $strook): array {
		$cu = ($strook['cumulatieven'] ?? []);
		if (is_array($cu) === true) {
			$totals['ytdFiscal'] = (float)($cu['fiscaalloon_ytd'] ?? $totals['ytdFiscal']);
			$totals['ytdVak']    = (float)($cu['vakantiegeld_reservering_ytd'] ?? $totals['ytdVak']);
		}

		return $totals;

	}//end withSnapshot()

	/**
	 * The year-to-date amounts of the jaaropgave, in euros.
	 *
	 * @param array<string, int|float> $totals The year's totals in cents.
	 *
	 * @return array<string, float>
	 */
	private function jtdAmounts(array $totals): array {
		return [
			'fiscaalLoonJTD' => $this->calculator->fromCents(cents: $totals['fiscal']),
			'loonheffingJTD' => $this->calculator->fromCents(cents: $totals['payrollTax']),
			'premiesSVWgJTD' => $this->calculator->fromCents(cents: $totals['svWg']),
			'zvwWgJTD' => $this->calculator->fromCents(cents: $totals['zvwWg']),
			'pensioenWnJTD' => $this->calculator->fromCents(cents: $totals['pensWn']),
			'pensioenWgJTD' => $this->calculator->fromCents(cents: $totals['pensWg']),
			'vakantieUitbJTD' => $this->calculator->fromCents(cents: $totals['vakUitb']),
			'nettoUitbetaaldJTD' => $this->calculator->fromCents(cents: $totals['net']),
		];

	}//end jtdAmounts()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
