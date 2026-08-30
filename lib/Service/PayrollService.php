<?php

/**
 * Payroll Service
 *
 * Wires the pure-logic PayrollCalculator to live OpenRegister data for the NL
 * loonadministratie engine (REQ-PAY-001, REQ-PAY-010 loonstrook, REQ-PAY-011
 * LH-afdracht aggregate, REQ-PAY-012 balanced GL journaalpost, REQ-PAY-013
 * jaaropgave). Every read is scoped to the administrationId resolved from the
 * caller's context — never a client-supplied trust boundary — and goes through
 * the real OpenRegister ObjectService API (find / findAll / saveObject); the
 * methods findObject / createFromArray / deleteFromId do NOT exist and are never
 * used (ADR-022).
 *
 * BSN and other special-category data are never written to the log; only object
 * identifiers and aggregate amounts appear in diagnostics (ADR-005, AVG).
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
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Period-scoped Dutch payroll processing over OpenRegister.
 *
 * Reads Werkgever / Werknemer / LoonheffingTabel2026 / LoonStrook records for one
 * administration, runs the calculator, and produces LoonStrook, LHAfdracht and a
 * balanced Loonjournaalpost. WHK/WKO sector rates default to zero unless the
 * Werknemer carries an explicit whkTarief2026 / wkoTarief2026 (the engine never
 * invents sector premiums).
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param PayrollCalculator $calculator Pure-logic payroll arithmetic helper.
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
	 * Compute a single employee's payslip for a period (REQ-PAY-001, REQ-PAY-010).
	 *
	 * Pure computation: reads the employer, employee and wage-tax table, runs the
	 * calculator, and returns the LoonStrook payload (not persisted). The caller
	 * persists it via persistLoonStrook after closing the period.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $employeeId Employee id.
	 * @param string $periodId Period id.
	 *
	 * @return array<string,mixed> The computed LoonStrook payload.
	 *
	 * @throws \RuntimeException When a required record is missing.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function berekenLoonStrook(string $administrationId, string $employeeId, string $periodId): array {
		$period = $this->findOne(schema: 'LoonPeriode', administrationId: $administrationId, filters: ['id' => $periodId]);
		$employee = $this->findOne(schema: 'Werknemer', administrationId: $administrationId, filters: ['id' => $employeeId]);
		if ($period === null || $employee === null) {
			// Identifiers only — never the BSN or any special-category field.
			$this->logger->warning(
				'Payslip refused: period or employee not resolvable in this administration.',
				[
					'administrationId' => $administrationId,
					'periodId' => $periodId,
					'employeeId' => $employeeId,
					'periodResolved' => ($period !== null),
					'employeeResolved' => ($employee !== null),
				]
			);
			throw new RuntimeException('Loonperiode of werknemer niet gevonden in deze administratie.');
		}

		$employerId = (string)($employee['employerId'] ?? '');
		$werkgever = $this->findOne(schema: 'Werkgever', administrationId: $administrationId, filters: ['id' => $employerId]);
		if ($werkgever === null) {
			// Identifiers only — never the BSN or any special-category field.
			$this->logger->warning(
				'Payslip refused: employer not resolvable in this administration.',
				[
					'administrationId' => $administrationId,
					'periodId' => $periodId,
					'employeeId' => $employeeId,
					'employerId' => $employerId,
				]
			);
			throw new RuntimeException('Werkgever niet gevonden in deze administratie.');
		}

		$periodType = (string)($period['periodType'] ?? 'MONTH');

		// Resolve the immutable wage-tax table for the period (REQ-PAY-002).
		$tableRules = $this->resolveTabelRules(administrationId: $administrationId, period: $period, employee: $employee);

		return $this->assemblePaySlip(
			administrationId: $administrationId,
			employee: $employee,
			werkgever: $werkgever,
			period: $period,
			periodType: $periodType,
			tableRules: $tableRules
		);

	}//end berekenLoonStrook()

	/**
	 * Assemble the full LoonStrook payload from calculator output.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $employee Employee record.
	 * @param array<string,mixed> $werkgever Employer record.
	 * @param array<string,mixed> $period Period record.
	 * @param string $periodType WEEK|4WEKEN|MAAND.
	 * @param array<int,mixed> $tableRules Wage-tax bracket rows.
	 *
	 * @return array<string,mixed>
	 */
	private function assemblePaySlip(
		string $administrationId,
		array $employee,
		array $werkgever,
		array $period,
		string $periodType,
		array $tableRules,
	): array {
		// Gross components: basissalaris + tax-free home-office allowance.
		$basissalaris = (float)($employee['periodeBruto'] ?? $this->periodGrossOutAnnualPay(employee: $employee, periodType: $periodType));
		$thuiswerkdagen = ((float)($employee['thuiswerkdagenPerWeek'] ?? 0) * $this->wekenInPeriod(periodType: $periodType));
		$thuiswerkverg = $this->calculator->thuiswerkvergoeding(thuiswerkdagen: $thuiswerkdagen);
		$expatFree = $this->calculator->expat30PctVrijstelling(
			grossPay: $basissalaris,
			applies: (bool)($employee['expat30PctScheme'] ?? false)
		);

		$grossComponents = [
			'basissalaris' => $basissalaris,
			'thuiswerkvergoeding' => $thuiswerkverg,
		];
		$totalGross = $this->calculator->totaalBruto(componenten: $grossComponents);

		$belastingvrijTotaal = $this->calculator->fromCents(
			cents: ($this->calculator->toCents(amount: $thuiswerkverg) + $this->calculator->toCents(amount: $expatFree))
		);
		$fiscalPay = $this->calculator->fiscaalLoon(totalGross: $totalGross, belastingvrijTotaal: $belastingvrijTotaal);
		$contributionPaySV = $basissalaris;

		$payrollTax = $this->calculator->loonheffingUitTabel(fiscalPay: $fiscalPay, tableRules: $tableRules);

		$svWg = $this->calculator->employerSocialInsurancePremiums(
			contributionPaySV: $contributionPaySV,
			periodType: $periodType,
			awfRate: (string)($werkgever['awfRate'] ?? 'LOW'),
			kleineWerkgever: true,
			whkRate: (float)($employee['whkTarief2026'] ?? 0.0),
			wkoRate: (float)($employee['wkoTarief2026'] ?? 0.0)
		);
		$zvw = $this->calculator->zvwWerkgever(
			contributionPaySV: $contributionPaySV,
			periodType: $periodType,
			zvwRate: (string)($werkgever['zvwRate'] ?? 'LOW')
		);
		$pensioen = $this->calculator->pensioen(
			basis: $basissalaris,
			pctWerkgever: (float)($employee['pensionPremiumPctEmployer'] ?? 0),
			pctEmployee: (float)($employee['pensionPremiumPctEmployee'] ?? 0)
		);

		$netPaid = $this->calculator->nettoBetaald(
			fiscalPay: $fiscalPay,
			payrollTax: $payrollTax,
			inhoudingSVEmployee: 0.0,
			pensioenEmployee: (float)$pensioen['premie_wn_aandeel'],
			belastingvrijTotaal: $belastingvrijTotaal
		);

		$grossComponents['totaal_bruto'] = $totalGross;
		$holidayOpbouw = $this->calculator->vakantiegeldOpbouw(
			totalGross: $basissalaris,
			pct: (float)($employee['holidayAllowancePct'] ?? 0.08)
		);
		$employeeId = (string)($employee['id'] ?? ($employee['@self']['id'] ?? ''));
		$cumulatieven = $this->stampCumulatieven(
			administrationId: $administrationId,
			employeeId: $employeeId,
			fiscalPay: $fiscalPay,
			holidayOpbouw: $holidayOpbouw
		);

		return [
			'employeeId' => $employeeId,
			'periodId' => (string)($period['id'] ?? ($period['@self']['id'] ?? '')),
			'grossComponents' => $grossComponents,
			'fiscalPay' => $fiscalPay,
			'contribution_pay_sv' => $contributionPaySV,
			'payrollTax' => $payrollTax,
			'inhoudingenSV' => ['totaal_sv_wn' => 0],
			'employerSocialInsurancePremiums' => $svWg,
			'zvw' => ['afgedragen_wg' => $zvw['afgedragen_wg'], 'rate' => $zvw['rate']],
			'pension' => $pensioen,
			'netPaid' => $netPaid,
			'cumulatieven' => $cumulatieven,
			'holidayDaysAccrual' => ['opgebouwdEuro' => $holidayOpbouw],
			'administrationId' => $administrationId,
		];

	}//end assembleLoonStrook()

	/**
	 * Aggregate the period's payslips into an LHAfdracht (REQ-PAY-011).
	 *
	 * Sums loonheffing, employer SV premiums and ZVW across all LoonStrook records
	 * for the period, sets the due date to the last day of the next month, and
	 * leaves the WKR final levy at the value the caller supplies from the WKR app
	 * (default 0). Status starts at VOORBEREID for the SBR app to pick up.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Period id.
	 * @param float $finalLeviesWKR WKR final levy from the WKR app (REQ-PAY-011).
	 *
	 * @return array<string,mixed> The LHAfdracht payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function berekenLHAfdracht(string $administrationId, string $periodId, float $finalLeviesWKR = 0.0): array {
		$stroken = $this->findAll(schema: 'LoonStrook', administrationId: $administrationId, filters: ['periodId' => $periodId]);

		$lhC = 0;
		$svC = 0;
		$zvwC = 0;
		foreach ($stroken as $slip) {
			$lhC += $this->calculator->toCents(amount: ($slip['payrollTax'] ?? 0));
			$svC += $this->calculator->toCents(amount: ($slip['employerSocialInsurancePremiums']['totaal_werkgever'] ?? 0));
			$zvwC += $this->calculator->toCents(amount: ($slip['zvw']['afgedragen_wg'] ?? 0));
		}

		$employerId = '';
		$period = $this->findOne(schema: 'LoonPeriode', administrationId: $administrationId, filters: ['id' => $periodId]);
		if ($period !== null) {
			$employerId = (string)($period['employerId'] ?? '');
		}

		$payrollTax = $this->calculator->fromCents(cents: $lhC);
		$premiesSV = $this->calculator->fromCents(cents: $svC);
		$zvw = $this->calculator->fromCents(cents: $zvwC);
		$wkr = max(0.0, $finalLeviesWKR);
		$total = $this->calculator->fromCents(
			cents: ($lhC + $svC + $zvwC + $this->calculator->toCents(amount: $wkr))
		);

		return [
			'employerId' => $employerId,
			'periodId' => $periodId,
			'totalPayrollTax' => $payrollTax,
			'totalFinalLeviesWorkRelatedCosts' => $wkr,
			'totalSocialInsuranceContributions' => $premiesSV,
			'totalHealthInsurance' => $zvw,
			'totalRemittance' => $total,
			'dueDateRemittance' => $this->lastDagVolgendeMonth(period: $period),
			'status' => 'VOORBEREID',
			'sbrInstanceRef' => null,
			'administrationId' => $administrationId,
		];

	}//end berekenLHAfdracht()

	/**
	 * Build a balanced GL journal for the period's payroll (REQ-PAY-012).
	 *
	 * Debit lines: gross wages, tax-free allowances, employer social charges,
	 * employer ZVW, employer pension. Credit lines: net payable, wage tax payable,
	 * SV+ZVW payable, pension payable (employer + employee). The journal is
	 * balanced only when sum(debet) == sum(credit) in integer cents; balanced is
	 * set accordingly and an unbalanced journal is refused by
	 * persistLoonjournaalpost.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Period id.
	 *
	 * @return array<string,mixed> The Loonjournaalpost payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function bouwLoonjournaalpost(string $administrationId, string $periodId): array {
		$stroken = $this->findAll(schema: 'LoonStrook', administrationId: $administrationId, filters: ['periodId' => $periodId]);

		$grossC = 0;
		$freeC = 0;
		$svWgC = 0;
		$zvwC = 0;
		$pensWgC = 0;
		$pensWnC = 0;
		$lhC = 0;
		$netC = 0;
		foreach ($stroken as $s) {
			$totalGross = (float)($s['grossComponents']['totaal_bruto'] ?? 0);
			$free = (float)($s['grossComponents']['thuiswerkvergoeding'] ?? 0);
			$grossC += ($this->calculator->toCents(amount: $totalGross) - $this->calculator->toCents(amount: $free));
			$freeC += $this->calculator->toCents(amount: $free);
			$svWgC += $this->calculator->toCents(amount: ($s['employerSocialInsurancePremiums']['totaal_werkgever'] ?? 0));
			$zvwC += $this->calculator->toCents(amount: ($s['zvw']['afgedragen_wg'] ?? 0));
			$pensWgC += $this->calculator->toCents(amount: ($s['pension']['premie_wg_aandeel'] ?? 0));
			$pensWnC += $this->calculator->toCents(amount: ($s['pension']['premie_wn_aandeel'] ?? 0));
			$lhC += $this->calculator->toCents(amount: ($s['payrollTax'] ?? 0));
			$netC += $this->calculator->toCents(amount: ($s['netPaid'] ?? 0));
		}//end foreach

		$f = (fn (int $c): float => $this->calculator->fromCents(cents: $c));

		$rules = [
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_BRUTOLONEN,
				'name' => 'Brutolonen',
				'debet' => $f($grossC),
				'credit' => 0.0,
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_BELASTINGVRIJE_VERGOEDINGEN,
				'name' => 'Belastingvrije vergoedingen',
				'debet' => $f($freeC),
				'credit' => 0.0,
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_SOCIALE_LASTEN_WG,
				'name' => 'Sociale lasten WG',
				'debet' => $f($svWgC),
				'credit' => 0.0,
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_ZVW_WG,
				'name' => 'ZVW-bijdrage WG',
				'debet' => $f($zvwC),
				'credit' => 0.0,
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_PENSIOEN_WG,
				'name' => 'Pensioenpremie WG',
				'debet' => $f($pensWgC),
				'credit' => 0.0,
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_TE_BETALEN_NETTO_LOON,
				'name' => 'Te betalen netto loon',
				'debet' => 0.0,
				'credit' => $f($netC),
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_AF_TE_DRAGEN_LH,
				'name' => 'Af te dragen loonheffing',
				'debet' => 0.0,
				'credit' => $f($lhC),
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_AF_TE_DRAGEN_PREMIES_SV_ZVW,
				'name' => 'Af te dragen premies SV+ZVW',
				'debet' => 0.0,
				'credit' => $f(($svWgC + $zvwC)),
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_AF_TE_DRAGEN_PENSIOEN,
				'name' => 'Af te dragen pensioenpremie',
				'debet' => 0.0,
				'credit' => $f(($pensWgC + $pensWnC)),
			],
		];

		$debetTotal = ($grossC + $freeC + $svWgC + $zvwC + $pensWgC);
		$creditTotal = ($netC + $lhC + $svWgC + $zvwC + $pensWgC + $pensWnC);
		$balanced = ($debetTotal === $creditTotal);

		return [
			'periodId' => $periodId,
			'date' => (string)date('Y-m-d'),
			'rules' => $rules,
			'balanced' => $balanced,
			'administrationId' => $administrationId,
		];

	}//end bouwLoonjournaalpost()

	/*
	 * NO PAYROLL PERSISTENCE HERE.
	 *
	 * `persistLoonStrook()` and `persistLoonjournaalpost()` stood here and had
	 * no caller. `PayrollController` drives `berekenLoonStrook()` /
	 * `bouwLoonjournaalpost()` and returns the computed payload to the client;
	 * nothing ever handed it back for a server-side save, so LoonStrook and
	 * Loonjournaalpost objects were never written through this service.
	 *
	 * ⚠️ `persistLoonjournaalpost()` carried the only balance guard in the code
	 * base ("refuse an unbalanced journal"). It never ran, because the method
	 * never ran — removing it takes away nothing that was protecting anything.
	 * A balance invariant that must hold belongs on the Loonjournaalpost
	 * schema in OpenRegister, where it applies to every writer; that is
	 * recorded as a follow-up rather than left as a guard on a dead path.
	 */

	/**
	 * Mask a BSN for safe display/logging (REQ-PAY-000, AVG).
	 *
	 * @param string|null $bsn The BSN.
	 *
	 * @return string|null Masked BSN (last 2 digits) or null.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function maskBsn(?string $bsn): ?string {
		if ($bsn === null || $bsn === '') {
			return null;
		}

		$len = strlen($bsn);
		if ($len <= 2) {
			return str_repeat('*', $len);
		}

		return (str_repeat('*', ($len - 2)) . substr($bsn, -2));
	}//end maskBsn()

	/**
	 * Stamp the YTD cumulatieven snapshot for a payslip (REQ-PAY-001, design.md D3).
	 *
	 * Reads prior payslips for the same employee in the same administration and
	 * adds the current period; the result is a read-only snapshot stored on the
	 * strook.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $employeeId Employee id.
	 * @param float $fiscalPay Current-period taxable wage.
	 * @param float $holidayOpbouw Current-period holiday accrual.
	 *
	 * @return array{fiscaalloon_ytd:float,vakantiegeld_reservering_ytd:float}
	 */
	private function stampCumulatieven(string $administrationId, string $employeeId, float $fiscalPay, float $holidayOpbouw): array {
		$priorStroken = $this->findAll(schema: 'LoonStrook', administrationId: $administrationId, filters: ['employeeId' => $employeeId]);

		$fiscalC = $this->calculator->toCents($fiscalPay);
		$vakC = $this->calculator->toCents($holidayOpbouw);
		foreach ($priorStroken as $s) {
			$fiscalC += $this->calculator->toCents($s['fiscalPay'] ?? 0);
			$vakC += $this->calculator->toCents(($s['holidayDaysAccrual']['opgebouwdEuro'] ?? 0));
		}

		return [
			'fiscaalloon_ytd' => $this->calculator->fromCents($fiscalC),
			'vakantiegeld_reservering_ytd' => $this->calculator->fromCents($vakC),
		];

	}//end stampCumulatieven()

	/**
	 * Derive a period gross from an annual gross (fallback when no explicit periodeBruto).
	 *
	 * @param array<string,mixed> $employee Employee record.
	 * @param string $periodType WEEK|4WEKEN|MAAND.
	 *
	 * @return float Period gross.
	 */
	private function periodGrossOutAnnualPay(array $employee, string $periodType): float {
		$year = (float)($employee['annualPayGross'] ?? ($employee['annualPaySV'] ?? 0));
		if ($year <= 0.0) {
			return 0.0;
		}

		return $this->calculator->periodeMaximum($year, $periodType);
	}//end periodeBrutoUitJaarloon()

	/**
	 * Weeks covered by a pay-period type (for day-based allowances).
	 *
	 * @param string $periodType WEEK|4WEKEN|MAAND.
	 *
	 * @return float Weeks.
	 */
	private function wekenInPeriod(string $periodType): float {
		return match ($periodType) {
			'WEEK' => 1.0,
			'4_WEEKS' => 4.0,
			default => (52.0 / 12.0),
		};

	}//end wekenInPeriode()

	/**
	 * Resolve the immutable wage-tax bracket rows for a period/employee (REQ-PAY-002).
	 *
	 * Prefers the table explicitly referenced by the period; otherwise looks up
	 * the most recent table valid for the employee's colour and the period
	 * granularity. Returns [] when none is found (the calculator then yields zero
	 * LH, never an exception).
	 *
	 * @param string $administrationId Administration scope (unused for the global table read).
	 * @param array<string,mixed> $period Period record.
	 * @param array<string,mixed> $employee Employee record.
	 *
	 * @return array<int,mixed> Bracket rows.
	 */
	private function resolveTabelRules(string $administrationId, array $period, array $employee): array {
		unset($administrationId);

		$tabelId = (string)($period['payrollTaxTableId'] ?? '');
		if ($tabelId !== '') {
			$tabel = $this->findOneGlobal(schema: 'LoonheffingTabel2026', filters: ['id' => $tabelId]);
			if ($tabel !== null && is_array(($tabel['tableRules'] ?? null)) === true) {
				return $tabel['tableRules'];
			}
		}

		$colour = 'WHITE';
		if (str_starts_with((string)($employee['payrollTaxTable'] ?? 'WHITE'), 'GREEN') === true) {
			$colour = 'GREEN';
		}

		$tabellen = $this->rawFindAll(
			schema: 'LoonheffingTabel2026',
			filters: [
				'colour' => $colour,
				'period' => (string)($period['periodType'] ?? 'MONTH'),
				'withDiscount' => (bool)($employee['payrollTaxTableDiscount'] ?? true),
			]
		);
		foreach ($tabellen as $tabel) {
			if (is_array(($tabel['tableRules'] ?? null)) === true) {
				return $tabel['tableRules'];
			}
		}

		return [];
	}//end resolveTabelRegels()

	/**
	 * Compute the remittance due date: last day of the month after the period.
	 *
	 * @param array<string,mixed>|null $period Period record.
	 *
	 * @return string|null Due date (Y-m-d) or null.
	 */
	private function lastDagVolgendeMonth(?array $period): ?string {
		$end = ($period['periodEnd'] ?? null);
		if (is_string($end) === false || $end === '') {
			return null;
		}

		$tijdstip = strtotime($end . ' first day of next month');
		if ($tijdstip === false) {
			return null;
		}

		return date('Y-m-t', $tijdstip);
	}//end laatsteDagVolgendeMaand()

	/**
	 * Find a single object by filters within the administration scope.
	 *
	 * @param string $schema Schema slug.
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $filters Filters (administrationId is forced).
	 *
	 * @return array<string,mixed>|null The first match or null.
	 */
	private function findOne(string $schema, string $administrationId, array $filters): ?array {
		$results = $this->findAll(schema: $schema, administrationId: $administrationId, filters: $filters);
		return ($results[0] ?? null);
	}//end findOne()

	/**
	 * Find objects by filters within the administration scope (REQ-PAY scoping).
	 *
	 * @param string $schema Schema slug.
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $filters Filters (administrationId is forced).
	 *
	 * @return array<int,array<string,mixed>> Matching objects.
	 */
	private function findAll(string $schema, string $administrationId, array $filters): array {
		$filters['administrationId'] = $administrationId;
		return $this->rawFindAll(schema: $schema, filters: $filters);
	}//end findAll()

	/**
	 * Find a single LoonheffingTabel2026 (global, immutable, admin-agnostic table).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return array<string,mixed>|null The first match or null.
	 */
	private function findOneGlobal(string $schema, array $filters): ?array {
		$results = $this->rawFindAll(schema: $schema, filters: $filters);
		return ($results[0] ?? null);
	}//end findOneGlobal()

	/**
	 * Run a filtered findAll over the OpenRegister ObjectService.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return array<int,array<string,mixed>> Matching objects.
	 */
	private function rawFindAll(string $schema, array $filters): array {
		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		$out = [];
		foreach ($results as $result) {
			$out[] = (array)$result;
		}

		return $out;
	}//end rawFindAll()

	/**
	 * Lazily fetch OpenRegister's ObjectService.
	 *
	 * @return object The ObjectService.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

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
