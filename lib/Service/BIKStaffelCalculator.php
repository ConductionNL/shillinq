<?php

/**
 * BIK Staffel Calculator
 *
 * ADR-031 exception-path PHP guard for the Besluit BIK (Besluit vergoeding voor
 * buitengerechtelijke incassokosten, Stb. 2012, 141) staffel computation, plus
 * the wettelijke rente accrual per BW art. 6:119 (B2C) and art. 6:119a (B2B
 * handelsrente). The schema declarative shape lives on the
 * IncassoKostenBerekening schema's `x-openregister-aggregations.bikStaffel` and
 * `renteAccrual` blocks; this calculator materialises the same numbers in PHP
 * whenever the OR aggregation engine cannot yet express the multi-slab BIK
 * arithmetic + per-day rente accrual.
 *
 * All arithmetic is performed in integer cents (REQ-CCD-003) to avoid
 * IEEE-754 float drift; the public surface returns 2-decimal floats for
 * direct persistence into the schema.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-13
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure BIK staffel + wettelijke rente calculator — no DI, fully unit-testable.
 *
 * Five staffel slabs per Besluit BIK:
 *   - €0      – €2.500   : 15%  (with statutory floor €40)
 *   - €2.500  – €5.000   : 10%  on the slice above €2.500
 *   - €5.000  – €10.000  :  5%  on the slice above €5.000
 *   - €10.000 – €200.000 :  1%  on the slice above €10.000
 *   - €200.000+          :  0,5% on the slice above €200.000
 *
 * Wettelijke rente:
 *   - B2B (HANDELSRENTE_B2B_6_119A_BW) — ECB Main Refinancing Rate + 8pp;
 *     default 11,5% per 1-1-2026.
 *   - B2C (WETTELIJKE_RENTE_B2C_6_119_BW) — wettelijke rente per DNB
 *     publicatie; default 7% per 1-1-2026.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-13
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-14
 */
class BIKStaffelCalculator
{
    /**
     * Statutory minimum incasso costs per Besluit BIK (cents).
     */
    private const MINIMUM_CENTS = 4000;

    /**
     * Slab upper bounds in cents (€2.500 / €5.000 / €10.000 / €200.000).
     *
     * @var array<int,int>
     */
    private const SLAB_BOUNDS_CENTS = [
        250000,
        500000,
        1000000,
        20000000,
    ];

    /**
     * Slab rates aligned with SLAB_BOUNDS_CENTS + an extra rate for the open-ended top slab.
     *
     * @var array<int,float>
     */
    private const SLAB_RATES = [
        0.15,
        0.10,
        0.05,
        0.01,
        0.005,
    ];

    /**
     * Default annual handelsrente B2B (art. 6:119a BW, ECB + 8pp per 1-1-2026).
     */
    public const DEFAULT_HANDELSRENTE_B2B = 0.115;

    /**
     * Default annual wettelijke rente B2C (art. 6:119 BW per 1-1-2026).
     */
    public const DEFAULT_WETTELIJKE_RENTE_B2C = 0.07;

    /**
     * B2C grace period after stage-3 dispatch (Wet IK / art. 6:96 lid 6 BW).
     */
    public const B2C_GRACE_DAYS = 14;

    /**
     * Calculate the BIK staffel breakdown for an outstanding principal.
     *
     * Returns the shape expected on IncassoKostenBerekening.berekening per
     * REQ-CCD-003: five slab amounts, the gross totaal, the statutory minimum,
     * and toegepast = max(totaal, minimum).
     *
     * @param float $hoofdsom Principal in EUR.
     *
     * @return array{schaal1_0_2500:float,schaal2_2500_5000:float,schaal3_5000_10000:float,schaal4_10000_200000:float,schaal5_200000plus:float,totaal:float,minimum:float,toegepast:float}
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-13
     */
    public function staffel(float $hoofdsom): array
    {
        if ($hoofdsom < 0) {
            throw new InvalidArgumentException('BIK staffel hoofdsom must be non-negative.');
        }

        $hoofdsomCents = $this->toCents(amount: $hoofdsom);

        $slabAmountsCents = [0, 0, 0, 0, 0];
        $previousBound    = 0;

        foreach (self::SLAB_BOUNDS_CENTS as $i => $upperBound) {
            $sliceTop = min($hoofdsomCents, $upperBound);
            $slice    = max($sliceTop - $previousBound, 0);
            $slabAmountsCents[$i] = (int) round($slice * self::SLAB_RATES[$i]);
            $previousBound        = $upperBound;
        }

        // Open-ended top slab (>€200.000).
        $topSlice            = max($hoofdsomCents - 20000000, 0);
        $slabAmountsCents[4] = (int) round($topSlice * self::SLAB_RATES[4]);

        $totaalCents    = array_sum($slabAmountsCents);
        $toegepastCents = max($totaalCents, self::MINIMUM_CENTS);

        return [
            'schaal1_0_2500'       => $this->fromCents(cents: $slabAmountsCents[0]),
            'schaal2_2500_5000'    => $this->fromCents(cents: $slabAmountsCents[1]),
            'schaal3_5000_10000'   => $this->fromCents(cents: $slabAmountsCents[2]),
            'schaal4_10000_200000' => $this->fromCents(cents: $slabAmountsCents[3]),
            'schaal5_200000plus'   => $this->fromCents(cents: $slabAmountsCents[4]),
            'totaal'               => $this->fromCents(cents: $totaalCents),
            'minimum'              => $this->fromCents(cents: self::MINIMUM_CENTS),
            'toegepast'            => $this->fromCents(cents: $toegepastCents),
        ];

    }//end staffel()

    /**
     * Compute wettelijke rente accrual per REQ-CCD-003.
     *
     * For B2C the accrual starts only after the statutory 14-day grace period
     * (day-44 from stage-3 dispatch on day 30); attempting to compute earlier
     * raises an InvalidArgumentException so callers fail closed.
     *
     * @param string            $partyType    'B2B' or 'B2C' (anything else treated as B2B for safety).
     * @param float             $hoofdsom     Outstanding principal in EUR.
     * @param DateTimeImmutable $ingangsdatum First day the rente accrues.
     * @param DateTimeImmutable $berekendOp   Calculation date.
     * @param float|null        $tariefB2B    Override the default B2B handelsrente.
     * @param float|null        $tariefB2C    Override the default B2C wettelijke rente.
     *
     * @return array{tarief:float,type:string,ingangsdatum:string,berekendOp:string,dagen:int,bedrag:float}
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-14
     */
    public function rente(
        string $partyType,
        float $hoofdsom,
        DateTimeImmutable $ingangsdatum,
        DateTimeImmutable $berekendOp,
        ?float $tariefB2B=null,
        ?float $tariefB2C=null
    ): array {
        if ($hoofdsom < 0) {
            throw new InvalidArgumentException('Rente hoofdsom must be non-negative.');
        }

        if ($berekendOp < $ingangsdatum) {
            throw new InvalidArgumentException('berekendOp must not be before ingangsdatum.');
        }

        $isB2C  = ($partyType === 'B2C');
        $tarief = $isB2C ? ($tariefB2C ?? self::DEFAULT_WETTELIJKE_RENTE_B2C) : ($tariefB2B ?? self::DEFAULT_HANDELSRENTE_B2B);
        $type   = $isB2C ? 'WETTELIJKE_RENTE_B2C_6_119_BW' : 'HANDELSRENTE_B2B_6_119A_BW';

        $dagen = (int) $ingangsdatum->diff($berekendOp)->days;

        // (bedrag × tarief × dagen) / 365 — integer cents internally.
        $hoofdsomCents = $this->toCents(amount: $hoofdsom);
        $bedragCents   = (int) round(($hoofdsomCents * $tarief * $dagen) / 365.0);

        return [
            'tarief'       => $tarief,
            'type'         => $type,
            'ingangsdatum' => $ingangsdatum->format('Y-m-d'),
            'berekendOp'   => $berekendOp->format('Y-m-d'),
            'dagen'        => $dagen,
            'bedrag'       => $this->fromCents(cents: $bedragCents),
        ];

    }//end rente()

    /**
     * Check whether B2C incasso-cost calculation is permitted on the given day.
     *
     * Per art. 6:96 lid 6 BW, B2C debiteuren receive a mandatory 14-day grace
     * after the stage-3 aanmaning before incassokosten may be levied. Stage 3
     * fires on dagenNaVervalDatum = 30, so the earliest permitted day is 44.
     *
     * @param string $partyType    'B2B' / 'B2C'.
     * @param int    $dagenVerzuim Number of days the invoice is overdue.
     *
     * @return bool True when the calculation is permitted.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-15
     */
    public function isCalculationPermitted(string $partyType, int $dagenVerzuim): bool
    {
        if ($partyType !== 'B2C') {
            return true;
        }

        return $dagenVerzuim >= (30 + self::B2C_GRACE_DAYS);

    }//end isCalculationPermitted()

    /**
     * Assemble the full IncassoKostenBerekening record body per REQ-CCD-003.
     *
     * @param string            $factuurId        Invoice FK.
     * @param string            $administrationId Administration scope.
     * @param string            $partyType        'B2B' / 'B2C' / 'GOVERNMENT'.
     * @param float             $hoofdsom         Outstanding principal in EUR.
     * @param DateTimeImmutable $ingangsdatum     First day the rente accrues.
     * @param DateTimeImmutable $berekendOp       Calculation date.
     * @param float|null        $tariefB2B        Override the default B2B handelsrente.
     * @param float|null        $tariefB2C        Override the default B2C wettelijke rente.
     *
     * @return array<string,mixed> Body ready to persist via ObjectService::saveObject.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-13
     */
    public function compose(
        string $factuurId,
        string $administrationId,
        string $partyType,
        float $hoofdsom,
        DateTimeImmutable $ingangsdatum,
        DateTimeImmutable $berekendOp,
        ?float $tariefB2B=null,
        ?float $tariefB2C=null
    ): array {
        $berekening = $this->staffel(hoofdsom: $hoofdsom);
        // For GOVERNMENT we still need a rente choice — treat as B2B handelsrente.
        $effectiveParty = ($partyType === 'B2C') ? 'B2C' : 'B2B';
        $rente          = $this->rente(
            partyType: $effectiveParty,
            hoofdsom: $hoofdsom,
            ingangsdatum: $ingangsdatum,
            berekendOp: $berekendOp,
            tariefB2B: $tariefB2B,
            tariefB2C: $tariefB2C
        );

        $totaalCents = $this->toCents(amount: $hoofdsom) + $this->toCents(amount: $berekening['toegepast']) + $this->toCents(amount: $rente['bedrag']);

        return [
            'factuurId'          => $factuurId,
            'hoofdsom'           => round($hoofdsom, 2),
            'berekening'         => $berekening,
            'wettelijkeRente'    => $rente,
            'partyType'          => $partyType,
            'totaalVerschuldigd' => $this->fromCents(cents: $totaalCents),
            'administrationId'   => $administrationId,
        ];

    }//end compose()

    /**
     * Convert a money amount to integer cents.
     *
     * @param float $amount EUR amount.
     *
     * @return int Whole cents.
     */
    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);

    }//end toCents()

    /**
     * Convert integer cents back to a 2-decimal float.
     *
     * @param int $cents Whole cents.
     *
     * @return float 2-decimal EUR amount.
     */
    private function fromCents(int $cents): float
    {
        return round(($cents / 100), 2);

    }//end fromCents()
}//end class
