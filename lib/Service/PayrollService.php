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
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param PayrollCalculator $calculator Pure-logic payroll arithmetic helper.
	 * @param LoggerInterface $logger Logger (no BSN / special-category data).
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
	 * @param string $werknemerId Employee id.
	 * @param string $periodeId Period id.
	 *
	 * @return array<string,mixed> The computed LoonStrook payload.
	 *
	 * @throws \RuntimeException When a required record is missing.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function berekenLoonStrook(string $administrationId, string $werknemerId, string $periodeId): array {
		$periode = $this->findOne(schema: 'LoonPeriode', administrationId: $administrationId, filters: ['id' => $periodeId]);
		$werknemer = $this->findOne(schema: 'Werknemer', administrationId: $administrationId, filters: ['id' => $werknemerId]);
		if ($periode === null || $werknemer === null) {
			throw new RuntimeException('Loonperiode of werknemer niet gevonden in deze administratie.');
		}

		$werkgeverId = (string)($werknemer['werkgeverId'] ?? '');
		$werkgever = $this->findOne(schema: 'Werkgever', administrationId: $administrationId, filters: ['id' => $werkgeverId]);
		if ($werkgever === null) {
			throw new RuntimeException('Werkgever niet gevonden in deze administratie.');
		}

		$periodeType = (string)($periode['periodeType'] ?? 'MAAND');

		// Resolve the immutable wage-tax table for the period (REQ-PAY-002).
		$tabelRegels = $this->resolveTabelRegels(administrationId: $administrationId, periode: $periode, werknemer: $werknemer);

		return $this->assembleLoonStrook(
			administrationId: $administrationId,
			werknemer: $werknemer,
			werkgever: $werkgever,
			periode: $periode,
			periodeType: $periodeType,
			tabelRegels: $tabelRegels
		);

	}//end berekenLoonStrook()

	/**
	 * Assemble the full LoonStrook payload from calculator output.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $werknemer Employee record.
	 * @param array<string,mixed> $werkgever Employer record.
	 * @param array<string,mixed> $periode Period record.
	 * @param string $periodeType WEEK|4WEKEN|MAAND.
	 * @param array<int,mixed> $tabelRegels Wage-tax bracket rows.
	 *
	 * @return array<string,mixed>
	 */
	private function assembleLoonStrook(
		string $administrationId,
		array $werknemer,
		array $werkgever,
		array $periode,
		string $periodeType,
		array $tabelRegels,
	): array {
		// Gross components: basissalaris + tax-free home-office allowance.
		$basissalaris = (float)($werknemer['periodeBruto'] ?? $this->periodeBrutoUitJaarloon(werknemer: $werknemer, periodeType: $periodeType));
		$thuiswerkdagen = ((float)($werknemer['thuiswerkdagenPerWeek'] ?? 0) * $this->wekenInPeriode(periodeType: $periodeType));
		$thuiswerkverg = $this->calculator->thuiswerkvergoeding(thuiswerkdagen: $thuiswerkdagen);
		$expatVrij = $this->calculator->expat30PctVrijstelling(
			brutoLoon: $basissalaris,
			applies: (bool)($werknemer['expat30PctScheme'] ?? false)
		);

		$brutoComponenten = [
			'basissalaris' => $basissalaris,
			'thuiswerkvergoeding' => $thuiswerkverg,
		];
		$totaalBruto = $this->calculator->totaalBruto(componenten: $brutoComponenten);

		$belastingvrijTotaal = $this->calculator->fromCents(
			cents: ($this->calculator->toCents(amount: $thuiswerkverg) + $this->calculator->toCents(amount: $expatVrij))
		);
		$fiscaalLoon = $this->calculator->fiscaalLoon(totaalBruto: $totaalBruto, belastingvrijTotaal: $belastingvrijTotaal);
		$premieloonSV = $basissalaris;

		$loonheffing = $this->calculator->loonheffingUitTabel(fiscaalLoon: $fiscaalLoon, tabelRegels: $tabelRegels);

		$svWg = $this->calculator->premiesSVWerkgever(
			premieloonSV: $premieloonSV,
			periodeType: $periodeType,
			awfTarief: (string)($werkgever['awfTarief'] ?? 'LAAG'),
			kleineWerkgever: true,
			whkTarief: (float)($werknemer['whkTarief2026'] ?? 0.0),
			wkoTarief: (float)($werknemer['wkoTarief2026'] ?? 0.0)
		);
		$zvw = $this->calculator->zvwWerkgever(
			premieloonSV: $premieloonSV,
			periodeType: $periodeType,
			zvwTarief: (string)($werkgever['zvwTarief'] ?? 'LAAG')
		);
		$pensioen = $this->calculator->pensioen(
			grondslag: $basissalaris,
			pctWerkgever: (float)($werknemer['pensioenPremiePctWerkgever'] ?? 0),
			pctWerknemer: (float)($werknemer['pensioenPremiePctWerknemer'] ?? 0)
		);

		$nettoBetaald = $this->calculator->nettoBetaald(
			fiscaalLoon: $fiscaalLoon,
			loonheffing: $loonheffing,
			inhoudingSVWerknemer: 0.0,
			pensioenWerknemer: (float)$pensioen['premie_wn_aandeel'],
			belastingvrijTotaal: $belastingvrijTotaal
		);

		$brutoComponenten['totaal_bruto'] = $totaalBruto;
		$vakantieOpbouw = $this->calculator->vakantiegeldOpbouw(
			totaalBruto: $basissalaris,
			pct: (float)($werknemer['vakantiegeldPct'] ?? 0.08)
		);
		$werknemerId = (string)($werknemer['id'] ?? ($werknemer['@self']['id'] ?? ''));
		$cumulatieven = $this->stampCumulatieven(
			administrationId: $administrationId,
			werknemerId: $werknemerId,
			fiscaalLoon: $fiscaalLoon,
			vakantieOpbouw: $vakantieOpbouw
		);

		return [
			'werknemerId' => $werknemerId,
			'periodeId' => (string)($periode['id'] ?? ($periode['@self']['id'] ?? '')),
			'brutoComponenten' => $brutoComponenten,
			'fiscaalLoon' => $fiscaalLoon,
			'premieloon_SV' => $premieloonSV,
			'loonheffing' => $loonheffing,
			'inhoudingenSV' => ['totaal_sv_wn' => 0],
			'premiesSVWerkgever' => $svWg,
			'zvw' => ['afgedragen_wg' => $zvw['afgedragen_wg'], 'tarief' => $zvw['tarief']],
			'pensioen' => $pensioen,
			'nettoBetaald' => $nettoBetaald,
			'cumulatieven' => $cumulatieven,
			'vakantieDagenReservering' => ['opgebouwdEuro' => $vakantieOpbouw],
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
	 * @param string $periodeId Period id.
	 * @param float $eindheffingenWKR WKR final levy from the WKR app (REQ-PAY-011).
	 *
	 * @return array<string,mixed> The LHAfdracht payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function berekenLHAfdracht(string $administrationId, string $periodeId, float $eindheffingenWKR = 0.0): array {
		$stroken = $this->findAll(schema: 'LoonStrook', administrationId: $administrationId, filters: ['periodeId' => $periodeId]);

		$lhC = 0;
		$svC = 0;
		$zvwC = 0;
		foreach ($stroken as $strook) {
			$lhC += $this->calculator->toCents(amount: ($strook['loonheffing'] ?? 0));
			$svC += $this->calculator->toCents(amount: ($strook['premiesSVWerkgever']['totaal_werkgever'] ?? 0));
			$zvwC += $this->calculator->toCents(amount: ($strook['zvw']['afgedragen_wg'] ?? 0));
		}

		$werkgeverId = '';
		$periode = $this->findOne(schema: 'LoonPeriode', administrationId: $administrationId, filters: ['id' => $periodeId]);
		if ($periode !== null) {
			$werkgeverId = (string)($periode['werkgeverId'] ?? '');
		}

		$loonheffing = $this->calculator->fromCents(cents: $lhC);
		$premiesSV = $this->calculator->fromCents(cents: $svC);
		$zvw = $this->calculator->fromCents(cents: $zvwC);
		$wkr = max(0.0, $eindheffingenWKR);
		$totaal = $this->calculator->fromCents(
			cents: ($lhC + $svC + $zvwC + $this->calculator->toCents(amount: $wkr))
		);

		return [
			'werkgeverId' => $werkgeverId,
			'periodeId' => $periodeId,
			'totalPayrollTax' => $loonheffing,
			'totalFinalLeviesWorkRelatedCosts' => $wkr,
			'totalSocialInsuranceContributions' => $premiesSV,
			'totalHealthInsurance' => $zvw,
			'totalRemittance' => $totaal,
			'vervaldagAfdracht' => $this->laatsteDagVolgendeMaand(periode: $periode),
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
	 * @param string $periodeId Period id.
	 *
	 * @return array<string,mixed> The Loonjournaalpost payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function bouwLoonjournaalpost(string $administrationId, string $periodeId): array {
		$stroken = $this->findAll(schema: 'LoonStrook', administrationId: $administrationId, filters: ['periodeId' => $periodeId]);

		$brutoC = 0;
		$vrijC = 0;
		$svWgC = 0;
		$zvwC = 0;
		$pensWgC = 0;
		$pensWnC = 0;
		$lhC = 0;
		$nettoC = 0;
		foreach ($stroken as $s) {
			$totaalBruto = (float)($s['brutoComponenten']['totaal_bruto'] ?? 0);
			$vrij = (float)($s['brutoComponenten']['thuiswerkvergoeding'] ?? 0);
			$brutoC += ($this->calculator->toCents(amount: $totaalBruto) - $this->calculator->toCents(amount: $vrij));
			$vrijC += $this->calculator->toCents(amount: $vrij);
			$svWgC += $this->calculator->toCents(amount: ($s['premiesSVWerkgever']['totaal_werkgever'] ?? 0));
			$zvwC += $this->calculator->toCents(amount: ($s['zvw']['afgedragen_wg'] ?? 0));
			$pensWgC += $this->calculator->toCents(amount: ($s['pensioen']['premie_wg_aandeel'] ?? 0));
			$pensWnC += $this->calculator->toCents(amount: ($s['pensioen']['premie_wn_aandeel'] ?? 0));
			$lhC += $this->calculator->toCents(amount: ($s['loonheffing'] ?? 0));
			$nettoC += $this->calculator->toCents(amount: ($s['nettoBetaald'] ?? 0));
		}//end foreach

		$f = (fn (int $c): float => $this->calculator->fromCents(cents: $c));

		$regels = [
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_BRUTOLONEN,
				'name' => 'Brutolonen',
				'debet' => $f($brutoC),
				'credit' => 0.0,
			],
			[
				'rekening' => PayrollChartOfAccountsMapping::ACC_BELASTINGVRIJE_VERGOEDINGEN,
				'name' => 'Belastingvrije vergoedingen',
				'debet' => $f($vrijC),
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
				'credit' => $f($nettoC),
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

		$debetTotaal = ($brutoC + $vrijC + $svWgC + $zvwC + $pensWgC);
		$creditTotaal = ($nettoC + $lhC + $svWgC + $zvwC + $pensWgC + $pensWnC);
		$balanced = ($debetTotaal === $creditTotaal);

		return [
			'periodeId' => $periodeId,
			'datum' => (string)date('Y-m-d'),
			'regels' => $regels,
			'balanced' => $balanced,
			'administrationId' => $administrationId,
		];

	}//end bouwLoonjournaalpost()

	/**
	 * Persist a computed payslip (REQ-PAY-010), scoped to the administration.
	 *
	 * @param array<string,mixed> $loonStrook The computed LoonStrook payload.
	 *
	 * @return array<string,mixed> The saved object as returned by OpenRegister.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function persistLoonStrook(array $loonStrook): array {
		return (array)$this->objectService()
			->saveObject(object: $loonStrook, register: $this->register(), schema: 'LoonStrook');

	}//end persistLoonStrook()

	/**
	 * Persist a balanced journal; refuse an unbalanced one (REQ-PAY-012).
	 *
	 * @param array<string,mixed> $journaalpost The Loonjournaalpost payload.
	 *
	 * @return array<string,mixed> The saved object.
	 *
	 * @throws \RuntimeException When the journal is not balanced.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function persistLoonjournaalpost(array $journaalpost): array {
		if (($journaalpost['balanced'] ?? false) !== true) {
			$this->logger->error(
				'Shillinq payroll: refusing to post unbalanced loonjournaalpost',
				['periodeId' => ($journaalpost['periodeId'] ?? null)]
			);
			throw new RuntimeException('Loonjournaalpost is niet in balans (debet != credit).');
		}

		return (array)$this->objectService()
			->saveObject(object: $journaalpost, register: $this->register(), schema: 'Loonjournaalpost');

	}//end persistLoonjournaalpost()

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
	 * @param string $werknemerId Employee id.
	 * @param float $fiscaalLoon Current-period taxable wage.
	 * @param float $vakantieOpbouw Current-period holiday accrual.
	 *
	 * @return array{fiscaalloon_ytd:float,vakantiegeld_reservering_ytd:float}
	 */
	private function stampCumulatieven(string $administrationId, string $werknemerId, float $fiscaalLoon, float $vakantieOpbouw): array {
		$priorStroken = $this->findAll(schema: 'LoonStrook', administrationId: $administrationId, filters: ['werknemerId' => $werknemerId]);

		$fiscaalC = $this->calculator->toCents($fiscaalLoon);
		$vakC = $this->calculator->toCents($vakantieOpbouw);
		foreach ($priorStroken as $s) {
			$fiscaalC += $this->calculator->toCents($s['fiscaalLoon'] ?? 0);
			$vakC += $this->calculator->toCents(($s['vakantieDagenReservering']['opgebouwdEuro'] ?? 0));
		}

		return [
			'fiscaalloon_ytd' => $this->calculator->fromCents($fiscaalC),
			'vakantiegeld_reservering_ytd' => $this->calculator->fromCents($vakC),
		];

	}//end stampCumulatieven()

	/**
	 * Derive a period gross from an annual gross (fallback when no explicit periodeBruto).
	 *
	 * @param array<string,mixed> $werknemer Employee record.
	 * @param string $periodeType WEEK|4WEKEN|MAAND.
	 *
	 * @return float Period gross.
	 */
	private function periodeBrutoUitJaarloon(array $werknemer, string $periodeType): float {
		$jaar = (float)($werknemer['jaarloonBruto'] ?? ($werknemer['jaarloonSV'] ?? 0));
		if ($jaar <= 0.0) {
			return 0.0;
		}

		return $this->calculator->periodeMaximum($jaar, $periodeType);
	}//end periodeBrutoUitJaarloon()

	/**
	 * Weeks covered by a pay-period type (for day-based allowances).
	 *
	 * @param string $periodeType WEEK|4WEKEN|MAAND.
	 *
	 * @return float Weeks.
	 */
	private function wekenInPeriode(string $periodeType): float {
		return match ($periodeType) {
			'WEEK' => 1.0,
			'4WEKEN' => 4.0,
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
	 * @param array<string,mixed> $periode Period record.
	 * @param array<string,mixed> $werknemer Employee record.
	 *
	 * @return array<int,mixed> Bracket rows.
	 */
	private function resolveTabelRegels(string $administrationId, array $periode, array $werknemer): array {
		unset($administrationId);

		$tabelId = (string)($periode['loonheffingstabelId'] ?? '');
		if ($tabelId !== '') {
			$tabel = $this->findOneGlobal(schema: 'LoonheffingTabel2026', filters: ['id' => $tabelId]);
			if ($tabel !== null && is_array(($tabel['tabelRegels'] ?? null)) === true) {
				return $tabel['tabelRegels'];
			}
		}

		$kleur = 'WIT';
		if (str_starts_with((string)($werknemer['loonheffingstabel'] ?? 'WIT'), 'GROEN') === true) {
			$kleur = 'GROEN';
		}

		$tabellen = $this->rawFindAll(
			schema: 'LoonheffingTabel2026',
			filters: [
				'kleur' => $kleur,
				'periode' => (string)($periode['periodeType'] ?? 'MAAND'),
				'metKorting' => (bool)($werknemer['loonheffingstabelKorting'] ?? true),
			]
		);
		foreach ($tabellen as $tabel) {
			if (is_array(($tabel['tabelRegels'] ?? null)) === true) {
				return $tabel['tabelRegels'];
			}
		}

		return [];
	}//end resolveTabelRegels()

	/**
	 * Compute the remittance due date: last day of the month after the period.
	 *
	 * @param array<string,mixed>|null $periode Period record.
	 *
	 * @return string|null Due date (Y-m-d) or null.
	 */
	private function laatsteDagVolgendeMaand(?array $periode): ?string {
		$eind = ($periode['periodEnd'] ?? null);
		if (is_string($eind) === false || $eind === '') {
			return null;
		}

		$tijdstip = strtotime($eind . ' first day of next month');
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
