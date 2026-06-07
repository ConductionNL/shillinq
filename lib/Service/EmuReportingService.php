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
 * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Read-only ADR-031 exception service for the EMU reporting pipeline.
 *
 * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
 */
class EmuReportingService
{

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
        ['48', 'eliminatie-afschrijving', 'saldo-verhogend'],
        ['460', 'eliminatie-voorzieningdotatie', 'saldo-verhogend'],
        ['49', 'eliminatie-onttrekking-reserve', 'saldo-neutraal'],
        ['010', 'toevoeging-bruto-investering', 'saldo-verlagend'],
        ['020', 'toevoeging-bruto-investering', 'saldo-verlagend'],
        ['210', 'toevoeging-aflossing', 'saldo-verlagend'],
        ['220', 'toevoeging-aflossing', 'saldo-verlagend'],
        ['230', 'toevoeging-aflossing', 'saldo-verlagend'],
        ['931', 'eliminatie-boekwinst-desinvestering', 'saldo-verlagend'],
        ['939', 'eliminatie-boekwinst-desinvestering', 'saldo-verhogend'],
    ];

    /**
     * Eurostat ESA2010 instrument categories that count toward the bruto
     * EMU-schuld (AF.2 deposits, AF.3 securities, AF.4 loans). AF.7 derivatives
     * and "overig" are excluded per REQ-EMU-004.
     *
     * @var array<int,string>
     */
    private const EMU_SCHULD_CATEGORIES = ['AF.2-deposits', 'AF.3-securities', 'AF.4-loans'];

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
     * @param array<string,mixed> $glLine   GL line: accountNumber, amount, (factuurmoment, betaalmoment).
     * @param string              $reportId The owning EMUReport id.
     *
     * @return array<string,mixed>|null A partial EMUAdjustment object array, or null when no rule applies.
     *
     * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
     */
    public function classifyAdjustment(array $glLine, string $reportId): ?array
    {
        $account = (string) ($glLine['accountNumber'] ?? ($glLine['grootboekrekening'] ?? ''));
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

        $type     = $matched[1];
        $richting = $matched[2];

        // Regel 7: transaction-moment correction when invoice and payment dates differ.
        $factuur = (string) ($glLine['factuurmoment'] ?? '');
        $betaal  = (string) ($glLine['betaalmoment'] ?? '');
        if ($factuur !== '' && $betaal !== '' && substr($factuur, 0, 10) !== substr($betaal, 0, 10)) {
            $type = 'correctie-transactiemoment';
        }

        return [
            'reportId'        => $reportId,
            'type'            => $type,
            'richting'        => $richting,
            'bedrag'          => abs((float) ($glLine['amount'] ?? 0)),
            'bron'            => [
                'grootboekrekening' => $account,
                'omschrijving'      => (string) ($glLine['description'] ?? ''),
                'taakveld'          => (string) ($glLine['taakveld'] ?? ''),
            ],
            'regel'           => 'Wet Hof art. 3: '.$type,
            'overridden'      => false,
            'consolidatieEMU' => 'extern',
            'currency'        => (string) ($glLine['currency'] ?? 'EUR'),
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
     * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
     */
    public function netAdjustmentEffect(array $adjustments): float
    {
        $cents = 0;
        foreach ($adjustments as $adj) {
            $bedragCents = (int) round((float) ($adj['bedrag'] ?? 0) * 100);
            $richting    = (string) ($adj['richting'] ?? 'saldo-neutraal');
            if ($richting === 'saldo-verhogend') {
                $cents += $bedragCents;
            } else if ($richting === 'saldo-verlagend') {
                $cents -= $bedragCents;
            }
        }

        return (float) ($cents / 100);

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
     * @return array{bruto:float,perCategorie:array<string,float>} Total + breakdown.
     *
     * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
     */
    public function computeBrutoSchuld(array $debtPositions): array
    {
        $perCategorieCents = [];
        $brutoCents        = 0;
        foreach ($debtPositions as $pos) {
            $categorie = (string) ($pos['categorieEurostat'] ?? 'overig');
            $telt      = (bool) ($pos['teltMeeInEmuSchuld'] ?? false);
            if ($telt === false || in_array($categorie, self::EMU_SCHULD_CATEGORIES, true) === false) {
                continue;
            }

            $cents = (int) round((float) ($pos['uitstaandeSchuld'] ?? 0) * 100);
            $perCategorieCents[$categorie] = ($perCategorieCents[$categorie] ?? 0) + $cents;
            $brutoCents += $cents;
        }//end foreach

        $perCategorie = [];
        foreach ($perCategorieCents as $cat => $c) {
            $perCategorie[$cat] = (float) ($c / 100);
        }

        return [
            'bruto'        => (float) ($brutoCents / 100),
            'perCategorie' => $perCategorie,
        ];

    }//end computeBrutoSchuld()

    /**
     * Compute budget variance for a report (REQ-EMU-007).
     *
     * The afwijking is berekend − begroot; the afwijkingPercentage is
     * afwijking / |begroot| × 100 (0.0 when begroot is zero to avoid division
     * by zero).
     *
     * @param float $berekend Computed EMU-saldo (EUR).
     * @param float $begroot  Budgeted EMU-saldo (EUR).
     *
     * @return array{afwijking:float,afwijkingPercentage:float} Variance result.
     *
     * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
     */
    public function computeVariance(float $berekend, float $begroot): array
    {
        $afwijking  = ($berekend - $begroot);
        $percentage = 0.0;
        if (abs($begroot) >= 0.005) {
            $percentage = round((($afwijking / abs($begroot)) * 100), 1);
        }

        return [
            'afwijking'           => round($afwijking, 2),
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
     * @param int                            $limit       Maximum contributors to return.
     *
     * @return array<int,array<string,mixed>> The top contributors.
     *
     * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
     */
    public function topContributors(array $adjustments, int $limit=3): array
    {
        usort(
            $adjustments,
            static function (array $a, array $b): int {
                return ((float) ($b['bedrag'] ?? 0) <=> (float) ($a['bedrag'] ?? 0));
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
     * @param float $cumulatiefSaldo  Cumulative EMU-saldo to date (EUR; negative = tekort).
     * @param float $referentiewaarde Individual EMU referentiewaarde (EUR; magnitude of permitted tekort).
     * @param float $drempel          Alert fraction (default 0.80).
     *
     * @return bool True when the alert threshold is reached or exceeded.
     *
     * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
     */
    public function shouldAlertReferentiewaarde(float $cumulatiefSaldo, float $referentiewaarde, float $drempel=0.80): bool
    {
        if ($referentiewaarde <= 0.0) {
            return false;
        }

        $utilization = (abs($cumulatiefSaldo) / abs($referentiewaarde));
        return $utilization >= $drempel;

    }//end shouldAlertReferentiewaarde()

    /**
     * Run the year-end BBV ↔ EMU reconciliation control (REQ-EMU-009).
     *
     * The control verifies that the BBV jaarrekening saldo baten/lasten plus the
     * net of all adjustments equals the sum of the four quarterly EMU-saldo
     * values, within a tolerance (default EUR 1.00, i.e. cent-rounding). Returns
     * the outcome string and the residual difference.
     *
     * @param float                          $bbvSaldoBatenLasten BBV jaarrekening saldo (EUR).
     * @param array<int,array<string,mixed>> $adjustments         All EMUAdjustment object arrays for the year.
     * @param array<int,float>               $kwartaalSaldi       The four quarterly EMU-saldo values (EUR).
     * @param float                          $tolerantie          Allowed residual (EUR).
     *
     * @return array{controle:string,verschil:float,totaleAdjustments:float} Reconciliation result.
     *
     * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
     */
    public function reconcile(
        float $bbvSaldoBatenLasten,
        array $adjustments,
        array $kwartaalSaldi,
        float $tolerantie=1.00
    ): array {
        $totaleAdjustments = $this->netAdjustmentEffect(adjustments: $adjustments);
        $somKwartalen      = 0.0;
        foreach ($kwartaalSaldi as $s) {
            $somKwartalen += (float) $s;
        }

        $verwacht = ($bbvSaldoBatenLasten + $totaleAdjustments);
        $verschil = round(($somKwartalen - $verwacht), 2);

        $controle = 'geslaagd';
        if (abs($verschil) > $tolerantie) {
            $controle = 'mislukt';
        }

        if ($controle === 'mislukt') {
            $this->logger->warning(
                'EmuReportingService: reconciliation failed',
                ['verschil' => $verschil, 'bbv' => $bbvSaldoBatenLasten, 'adjustments' => $totaleAdjustments]
            );
        }

        return [
            'controle'          => $controle,
            'verschil'          => $verschil,
            'totaleAdjustments' => round($totaleAdjustments, 2),
        ];

    }//end reconcile()
}//end class
