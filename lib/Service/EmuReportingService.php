<?php

/**
 * EMU Reporting Service.
 *
 * ADR-031 exception: pure-PHP pipeline logic for the EMU-saldo & EMU-schuld
 * reporting capability that OpenRegister's declarative calculation/aggregation
 * engine cannot yet express — Wet Hof art. 3 macro-rule classification of GL
 * lines into EMUAdjustment records (REQ-EMU-002), bruto EMU-schuld per ESA2010
 * (REQ-EMU-004), budget variance + top-3 contributor toelichting (REQ-EMU-007),
 * referentiewaarde alert (REQ-EMU-008), and the year-end BBV reconciliation
 * control (REQ-EMU-009). All methods are read-only, array-in/array-out, with no
 * persistence — the caller (lifecycle transition / scheduler) writes the result
 * back via OpenRegister's saveObject. Remove individual methods when OR lands
 * the matching declarative primitive.
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
 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Read-only ADR-031 exception service for the EMU reporting pipeline.
 *
 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class EmuReportingService {

	/**
	 * Maps a GL account-number prefix to a Wet Hof art. 3 adjustment type and
	 * direction. First matching prefix wins (longest-prefix order preserved).
	 *
	 * Each entry: [prefix, type, richting]. Driven by the Besluit BBV
	 * grootboekindeling ranges cited in tasks.md Task 6.
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private const MACRO_RULES = [
		['48', 'elimination-depreciation', 'balance-increasing'],
		['460', 'elimination-provision-contribution', 'balance-increasing'],
		['49', 'elimination-withdrawal-reserve', 'balance-neutral'],
		['010', 'addition-gross-investment', 'balance-decreasing'],
		['020', 'addition-gross-investment', 'balance-decreasing'],
		['210', 'addition-repayment', 'balance-decreasing'],
		['220', 'addition-repayment', 'balance-decreasing'],
		['230', 'addition-repayment', 'balance-decreasing'],
		['931', 'elimination-book-profit-divestment', 'balance-decreasing'],
		['939', 'elimination-book-profit-divestment', 'balance-increasing'],
	];

	/**
	 * Eurostat ESA2010 instrument categories that count toward the bruto
	 * EMU-schuld (AF.2 deposits, AF.3 securities, AF.4 loans). AF.7 derivatives
	 * and "other" are excluded per REQ-EMU-004.
	 *
	 * @var array<int,string>
	 */
	private const EMU_SCHULD_CATEGORIES = ['off_2-deposits', 'off_3-securities', 'off_4-loans'];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Classify a GL line into a Wet Hof art. 3 macro-rule adjustment (REQ-EMU-002).
	 *
	 * Returns null when the line's account does not match any macro-rule prefix
	 * (i.e. it is an ordinary cash transaction, not an accrual→kas correction).
	 * When the line's factuurmoment differs from its betaalmoment, the type is
	 * overridden to `correctie-transactiemoment` (Task 6, Regel 7).
	 *
	 * @param array<string,mixed> $glLine GL line: accountNumber, amount, (factuurmoment, betaalmoment).
	 * @param string $reportId The owning EMUReport id.
	 *
	 * @return array<string,mixed>|null A partial EMUAdjustment object array, or null when no rule applies.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function classifyAdjustment(array $glLine, string $reportId): ?array {
		$account = (string)($glLine['accountNumber'] ?? ($glLine['generalLedgerAccount'] ?? ''));
		if ($account === '') {
			return null;
		}

		$matched = null;
		foreach (self::MACRO_RULES as $rule) {
			if (str_starts_with($account, $rule[0]) === true) {
				$matched = $rule;
				break;
			}
		}

		if ($matched === null) {
			return null;
		}

		$type = $matched[1];
		$direction = $matched[2];

		// Regel 7: transaction-moment correction when invoice and payment dates differ.
		$invoice = (string)($glLine['invoiceMoment'] ?? '');
		$payment = (string)($glLine['paymentMoment'] ?? '');
		if ($invoice !== '' && $payment !== '' && substr($invoice, 0, 10) !== substr($payment, 0, 10)) {
			$type = 'correction-transaction-moment';
		}

		return [
			'reportId' => $reportId,
			'type' => $type,
			'direction' => $direction,
			'amount' => abs((float)($glLine['amount'] ?? 0)),
			'source' => [
				'generalLedgerAccount' => $account,
				'description' => (string)($glLine['description'] ?? ''),
				'taskField' => (string)($glLine['taskField'] ?? ''),
			],
			'rule' => 'Wet Hof art. 3: ' . $type,
			'overridden' => false,
			'consolidationEMU' => 'extern',
			'currency' => (string)($glLine['currency'] ?? 'EUR'),
		];

	}//end classifyAdjustment()

	/**
	 * Compute the net EMU-saldo effect of a set of adjustments (REQ-EMU-002).
	 *
	 * A saldo-verhogend adjustment adds, saldo-verlagend subtracts, and
	 * saldo-neutraal contributes nothing. Integer-cents arithmetic avoids
	 * IEEE-754 drift, then converts back
	 * to euro for the caller.
	 *
	 * @param array<int,array<string,mixed>> $adjustments EMUAdjustment object arrays.
	 *
	 * @return float Net effect in euro (positive = saldo-verhogend net).
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function netAdjustmentEffect(array $adjustments): float {
		$cents = 0;
		foreach ($adjustments as $adj) {
			$amountCents = (int)round((float)($adj['amount'] ?? 0) * 100);
			$direction = (string)($adj['direction'] ?? 'balance-neutral');
			if ($direction === 'balance-increasing') {
				$cents += $amountCents;
			} elseif ($direction === 'balance-decreasing') {
				$cents -= $amountCents;
			}
		}

		return (float)($cents / 100);
	}//end netAdjustmentEffect()

	/**
	 * Compute bruto EMU-schuld per ESA2010 (REQ-EMU-004).
	 *
	 * Sums uitstaandeSchuld over AF.2/AF.3/AF.4 positions flagged
	 * teltMeeInEmuSchuld; AF.7 derivatives are excluded. Returns the total and a
	 * per-categorie breakdown, both in euro.
	 *
	 * @param array<int,array<string,mixed>> $debtPositions DebtPosition object arrays.
	 *
	 * @return array{gross:float,perCategory:array<string,float>} Total + breakdown.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function computeBrutoSchuld(array $debtPositions): array {
		$perCategoryCents = [];
		$grossCents = 0;
		foreach ($debtPositions as $pos) {
			$category = (string)($pos['categoryEurostat'] ?? 'other');
			$telt = (bool)($pos['teltMeeInEmuDebt'] ?? false);
			if ($telt === false || in_array($category, self::EMU_SCHULD_CATEGORIES, true) === false) {
				continue;
			}

			$cents = (int)round((float)($pos['outstandingDebt'] ?? 0) * 100);
			$perCategoryCents[$category] = ($perCategoryCents[$category] ?? 0) + $cents;
			$grossCents += $cents;
		}//end foreach

		$perCategory = [];
		foreach ($perCategoryCents as $cat => $c) {
			$perCategory[$cat] = (float)($c / 100);
		}

		return [
			'gross' => (float)($grossCents / 100),
			'perCategory' => $perCategory,
		];

	}//end computeBrutoSchuld()

	/**
	 * Compute budget variance for a report (REQ-EMU-007).
	 *
	 * The afwijking is berekend − begroot; the afwijkingPercentage is
	 * afwijking / |begroot| × 100 (0.0 when begroot is zero to avoid division
	 * by zero).
	 *
	 * @param float $calculated Computed EMU-saldo (EUR).
	 * @param float $begroot Budgeted EMU-saldo (EUR).
	 *
	 * @return array{afwijking:float,afwijkingPercentage:float} Variance result.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function computeVariance(float $calculated, float $begroot): array {
		$deviation = ($calculated - $begroot);
		$percentage = 0.0;
		if (abs($begroot) >= 0.005) {
			$percentage = round((($deviation / abs($begroot)) * 100), 1);
		}

		return [
			'afwijking' => round($deviation, 2),
			'afwijkingPercentage' => $percentage,
		];

	}//end computeVariance()

	/**
	 * Identify the top-N adjustments contributing to a variance (REQ-EMU-007).
	 *
	 * Sorted by absolute bedrag descending; returns up to $limit entries. Used to
	 * auto-seed the EMUReport.toelichting with the largest contributors.
	 *
	 * @param array<int,array<string,mixed>> $adjustments EMUAdjustment object arrays.
	 * @param int $limit Maximum contributors to return.
	 *
	 * @return array<int,array<string,mixed>> The top contributors.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function topContributors(array $adjustments, int $limit = 3): array {
		usort(
			$adjustments,
			static function (array $a, array $b): int {
				return ((float)($b['amount'] ?? 0) <=> (float)($a['amount'] ?? 0));
			}
		);

		return array_slice($adjustments, 0, max(0, $limit));
	}//end topContributors()

	/**
	 * Evaluate the individual referentiewaarde alert (REQ-EMU-008).
	 *
	 * Returns true when the cumulative EMU-saldo (absolute, treating a tekort as a
	 * positive magnitude against the norm) reaches the given fraction (default
	 * 0.80 = 80%) of the wettelijke referentiewaarde. A non-positive norm yields
	 * false (no norm set).
	 *
	 * @param float $cumulatiefBalance Cumulative EMU-saldo to date (EUR; negative = tekort).
	 * @param float $referentiewaarde Individual EMU referentiewaarde (EUR; magnitude of permitted tekort).
	 * @param float $threshold Alert fraction (default 0.80).
	 *
	 * @return bool True when the alert threshold is reached or exceeded.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function shouldAlertReferentiewaarde(float $cumulatiefBalance, float $referentiewaarde, float $threshold = 0.80): bool {
		if ($referentiewaarde <= 0.0) {
			return false;
		}

		$utilization = (abs($cumulatiefBalance) / abs($referentiewaarde));
		return $utilization >= $threshold;
	}//end shouldAlertReferentiewaarde()

	/**
	 * Run the year-end BBV ↔ EMU reconciliation control (REQ-EMU-009).
	 *
	 * The control verifies that the BBV jaarrekening saldo baten/lasten plus the
	 * net of all adjustments equals the sum of the four quarterly EMU-saldo
	 * values, within a tolerance (default EUR 1.00, i.e. cent-rounding). Returns
	 * the outcome string and the residual difference.
	 *
	 * @param float $bbvNetResult BBV jaarrekening saldo (EUR).
	 * @param array<int,array<string,mixed>> $adjustments All EMUAdjustment object arrays for the year.
	 * @param array<int,float> $quarterSaldi The four quarterly EMU-saldo values (EUR).
	 * @param float $tolerance Allowed residual (EUR).
	 *
	 * @return array{controle:string,verschil:float,totaleAdjustments:float} Reconciliation result.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function reconcile(
		float $bbvNetResult,
		array $adjustments,
		array $quarterSaldi,
		float $tolerance = 1.00,
	): array {
		$totaleAdjustments = $this->netAdjustmentEffect(adjustments: $adjustments);
		$somKwartalen = 0.0;
		foreach ($quarterSaldi as $s) {
			$somKwartalen += (float)$s;
		}

		$expected = ($bbvNetResult + $totaleAdjustments);
		$verschil = round(($somKwartalen - $expected), 2);

		$controle = 'succeeded';
		if (abs($verschil) > $tolerance) {
			$controle = 'failed';
		}

		if ($controle === 'failed') {
			$this->logger->warning(
				'EmuReportingService: reconciliation failed',
				['verschil' => $verschil, 'bbv' => $bbvNetResult, 'adjustments' => $totaleAdjustments]
			);
		}

		return [
			'controle' => $controle,
			'verschil' => $verschil,
			'totaleAdjustments' => round($totaleAdjustments, 2),
		];

	}//end reconcile()

	/**
	 * Multi-period trend comparison Q vs Q-1..Q-3 (REQ-EMU-007, Task 18).
	 *
	 * Returns the per-quarter delta from the prior quarter and a four-quarter
	 * cumulative saldo. The first quarter has delta=0.0 (no prior). All values
	 * are in euro, computed via integer-cent arithmetic to avoid IEEE-754 drift.
	 *
	 * @param array<int,float> $quarterSaldi Up to 4 chronological quarterly EMU-saldo values (EUR).
	 *
	 * @return array{cumulatief:float,deltas:array<int,float>} Cumulative + per-Q delta.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function computeTrend(array $quarterSaldi): array {
		$deltasCents = [];
		$cumulatiefCents = 0;
		$previousCents = null;
		foreach ($quarterSaldi as $balance) {
			$cents = (int)round((float)$balance * 100);
			$cumulatiefCents += $cents;
			if ($previousCents === null) {
				$deltasCents[] = 0;
			} else {
				$deltasCents[] = ($cents - $previousCents);
			}

			$previousCents = $cents;
		}

		$deltas = [];
		foreach ($deltasCents as $d) {
			$deltas[] = (float)($d / 100);
		}

		return [
			'cumulatief' => (float)($cumulatiefCents / 100),
			'deltas' => $deltas,
		];

	}//end computeTrend()

	/**
	 * Sector macro-ruimte alert (REQ-EMU-008, Task 20).
	 *
	 * Fires when the cumulative sector EMU-tekort consumes at least the given
	 * fraction of the published BOFv sectornorm. Distinct from
	 * `shouldAlertReferentiewaarde` which checks the individuele norm; this is
	 * the geconsolideerde sector check used on consolidatie-overzichten. Returns
	 * false on a non-positive sector norm (no norm set).
	 *
	 * @param float $sectorCumulatief Cumulative tekort across the sector (EUR; magnitude).
	 * @param float $sectorMacroNorm Published macro-norm for the sector (EUR; magnitude).
	 * @param float $threshold Alert fraction (default 0.80).
	 *
	 * @return bool True when the macro-ruimte alert threshold is reached.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function shouldAlertSectorMacro(float $sectorCumulatief, float $sectorMacroNorm, float $threshold = 0.80): bool {
		if ($sectorMacroNorm <= 0.0) {
			return false;
		}

		return (abs($sectorCumulatief) / abs($sectorMacroNorm)) >= $threshold;
	}//end shouldAlertSectorMacro()

	/**
	 * Render the 10 verplichte CBS-tussenregels from a set of adjustments (REQ-EMU-003, Task 29).
	 *
	 * Maps the eight EMUAdjustment macro types and the BBV saldo + computed
	 * EMU-saldo to the 10-row CBS kwartaal-EMU template. Returns a
	 * regelnummer-keyed array; each row carries label + amount (EUR, signed).
	 * Pure function — caller is responsible for exporting (XBRL/CSV/Excel).
	 *
	 * @param float $bbvNetResult BBV saldo baten/lasten (EUR).
	 * @param array<int,array<string,mixed>> $adjustments EMUAdjustment object arrays.
	 * @param float $emuBalanceCalculated Computed EMU-saldo (EUR).
	 *
	 * @return array<int,array{rule:int,label:string,amount:float}> The 10 CBS-tussenregels.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function renderCbsTussenregels(float $bbvNetResult, array $adjustments, float $emuBalanceCalculated): array {
		$sumByType = $this->sumByType(adjustments: $adjustments);

		return [
			['rule' => 1, 'label' => 'Saldo van baten en lasten BBV', 'amount' => round($bbvNetResult, 2)],
			['rule' => 2, 'label' => 'Mutatie reserves', 'amount' => round(($sumByType['elimination-withdrawal-reserve'] ?? 0.0), 2)],
			['rule' => 3, 'label' => 'Bruto investeringen MVA', 'amount' => round(-1.0 * ($sumByType['addition-gross-investment'] ?? 0.0), 2)],
			['rule' => 4, 'label' => 'Bijdragen van derden in investeringen', 'amount' => 0.0],
			['rule' => 5, 'label' => 'Desinvesteringen', 'amount' => round(($sumByType['elimination-book-profit-divestment'] ?? 0.0), 2)],
			['rule' => 6, 'label' => 'Afschrijvingen', 'amount' => round(($sumByType['elimination-depreciation'] ?? 0.0), 2)],
			[
				'rule' => 7,
				'label' => 'Dotaties voorzieningen ten laste exploitatie',
				'amount' => round(($sumByType['elimination-provision-contribution'] ?? 0.0), 2),
			],
			['rule' => 8, 'label' => 'Onttrekkingen voorzieningen via exploitatie', 'amount' => 0.0],
			[
				'rule' => 9,
				'label' => 'Boekwinst / verlies desinvesteringen',
				'amount' => round(($sumByType['elimination-book-profit-divestment'] ?? 0.0), 2),
			],
			['rule' => 10, 'label' => 'EMU-saldo', 'amount' => round($emuBalanceCalculated, 2)],
		];

	}//end renderCbsTussenregels()

	/**
	 * Sum adjustment bedrag by type, signed by richting. Internal helper.
	 *
	 * @param array<int,array<string,mixed>> $adjustments EMUAdjustment object arrays.
	 *
	 * @return array<string,float> Map of type → signed sum in euro.
	 */
	private function sumByType(array $adjustments): array {
		$centsByType = [];
		foreach ($adjustments as $adj) {
			$type = (string)($adj['type'] ?? '');
			$direction = (string)($adj['direction'] ?? 'balance-neutral');
			$amountCents = (int)round((float)($adj['amount'] ?? 0) * 100);
			if ($direction === 'balance-decreasing') {
				$amountCents = -1 * $amountCents;
			} elseif ($direction === 'balance-neutral') {
				$amountCents = 0;
			}

			$centsByType[$type] = ($centsByType[$type] ?? 0) + $amountCents;
		}

		$result = [];
		foreach ($centsByType as $type => $c) {
			$result[$type] = (float)($c / 100);
		}

		return $result;
	}//end sumByType()

	/**
	 * Cascading IV3 lookup for a CashFlowItem (Task 14, REQ-EMU-010).
	 *
	 * Resolves the IV3 (hoofdstuk-functie-categorie) classification for a
	 * cash-flow item. Lookup priority: (1) explicit `iv3` already on the item,
	 * (2) `taakveld` map provided by the caller (typically the IV3-reporting
	 * cross-app), (3) `accountNumber` prefix map provided by the caller. Returns
	 * null when no source resolves — caller decides whether to fail validation.
	 *
	 * @param array<string,mixed> $item CashFlowItem object array.
	 * @param array<string,array<string,string>> $taskFieldMap Map taakveld → iv3
	 *                                                        (hoofdstuk/functie/categorie).
	 * @param array<string,array<string,string>> $accountMap Map account-prefix →
	 *                                                       iv3.
	 *
	 * @return array<string,string>|null Resolved iv3 object or null.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function mapIv3Classification(array $item, array $taskFieldMap = [], array $accountMap = []): ?array {
		$explicit = $item['iv3'] ?? null;
		if (is_array($explicit) === true && empty($explicit) === false) {
			return $explicit;
		}

		$taskField = (string)($item['taskField'] ?? '');
		if ($taskField !== '' && isset($taskFieldMap[$taskField]) === true) {
			return $taskFieldMap[$taskField];
		}

		$account = (string)($item['accountNumber'] ?? '');
		if ($account !== '') {
			// Longest-prefix wins.
			$bestKey = null;
			foreach (array_keys($accountMap) as $prefix) {
				if (str_starts_with($account, (string)$prefix) === false) {
					continue;
				}

				if ($bestKey === null || strlen((string)$prefix) > strlen($bestKey)) {
					$bestKey = (string)$prefix;
				}
			}

			if ($bestKey !== null) {
				return $accountMap[$bestKey];
			}
		}

		return null;
	}//end mapIv3Classification()

	/**
	 * Resolve the consolidatieEMU flag for an intercompany counterparty (Task 25, REQ-EMU-005).
	 *
	 * Pulls the counterparty's sector + S.1313 flag from the GR-registry hint
	 * (typically supplied by bookkeeping-verbonden-partijen). When the
	 * counterparty has an explicit `wetFidoExemption=true` flag the rule
	 * returns "extern" so the transaction is NOT eliminated; otherwise an
	 * S.1313 counterparty resolves to "intern-S1313" and everything else to
	 * "extern".
	 *
	 * @param array<string,mixed> $counterparty Counterparty hint: sector / wetFidoExemption / consolidatieEMU.
	 *
	 * @return string One of "extern" / "intern-S1313" / "internal-entity".
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function resolveConsolidatieEmu(array $counterparty): string {
		// Explicit override always wins.
		$explicit = (string)($counterparty['consolidationEMU'] ?? '');
		if (in_array($explicit, ['extern', 'intern-S1313', 'internal-entity'], true) === true) {
			return $explicit;
		}

		if ((bool)($counterparty['wetFidoExemption'] ?? false) === true) {
			return 'extern';
		}

		$sector = (string)($counterparty['sector'] ?? ($counterparty['kind'] ?? ''));
		if ($sector === 'S.1313' || str_contains($sector, 'S1313') === true) {
			return 'intern-S1313';
		}

		if ((bool)($counterparty['sameLegalEntity'] ?? false) === true) {
			return 'internal-entity';
		}

		return 'extern';
	}//end resolveConsolidatieEmu()
}//end class
