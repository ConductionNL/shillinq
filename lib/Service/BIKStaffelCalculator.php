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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
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
 * Five staffel slabs per Besluit BIK, bounded by a statutory €40 minimum and
 * a €6.775 maximum (the cap is reached at a €1.000.000 principal):
 *   - €0      – €2.500   : 15%  (with statutory floor €40)
 *   - €2.500  – €5.000   : 10%  on the slice above €2.500
 *   - €5.000  – €10.000  :  5%  on the slice above €5.000
 *   - €10.000 – €200.000 :  1%  on the slice above €10.000
 *   - €200.000+          :  0,5% on the slice above €200.000, capped at €6.775
 *
 * BTW-over-incassokosten (art. 2 lid 2 Besluit BIK): when the creditor cannot
 * offset the VAT charged on the collection service and declares this in the
 * aanmaning, the staffel amount is increased by the VAT percentage (21%).
 *
 * Wettelijke rente is resolved from a maintained, date-keyed rate table (the
 * rates change ~biannually), so an accrual window that crosses a rate boundary
 * is split and each sub-period accrues at its own rate:
 *   - B2B (HANDELSRENTE_B2B_6_119A_BW) — ECB Main Refinancing Rate + 8pp.
 *   - B2C (WETTELIJKE_RENTE_B2C_6_119_BW) — wettelijke rente per AMvB.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pre-existing debt (issue
 *     #506): changing this public signature would ripple to callers;
 *     deferred.
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class BIKStaffelCalculator {
	/**
	 * Statutory minimum incasso costs per Besluit BIK (cents).
	 */
	private const MINIMUM_CENTS = 4000;

	/**
	 * Statutory maximum incasso costs per Besluit BIK (cents, €6.775).
	 *
	 * Reached at a €1.000.000 principal; the 0,5% top slab never lifts the
	 * fee above this ceiling.
	 */
	private const MAXIMUM_CENTS = 677500;

	/**
	 * Default BTW percentage applied under art. 2 lid 2 Besluit BIK.
	 */
	public const DEFAULT_BTW_PERCENTAGE = 0.21;

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
	 * Wettelijke rente B2C history (art. 6:119 BW), effectiveFrom => annual rate.
	 *
	 * Source: AMvB per 1-1-2026 + wettelijke-rente.com rente-tabel.
	 *
	 * @var array<string,float>
	 */
	private const WETTELIJKE_RENTE_B2C_TABLE = [
		'2023-01-01' => 0.04,
		'2024-01-01' => 0.07,
		'2025-01-01' => 0.06,
		'2026-01-01' => 0.04,
	];

	/**
	 * Handelsrente B2B history (art. 6:119a BW), effectiveFrom => annual rate.
	 *
	 * ECB Main Refinancing Rate + 8pp, set half-yearly. Source: Wieringa
	 * Advocaten 2026-01-05 + wettelijke-rente.com handelsrente-tabel.
	 *
	 * @var array<string,float>
	 */
	private const HANDELSRENTE_B2B_TABLE = [
		'2024-01-01' => 0.1250,
		'2024-07-01' => 0.1225,
		'2025-01-01' => 0.1115,
		'2025-07-01' => 0.1015,
		'2026-01-01' => 0.1015,
	];

	/**
	 * Default annual handelsrente B2B (art. 6:119a BW) — current table head per 1-1-2026.
	 */
	public const DEFAULT_HANDELSRENTE_B2B = 0.1015;

	/**
	 * Default annual wettelijke rente B2C (art. 6:119 BW) — current table head per 1-1-2026.
	 */
	public const DEFAULT_WETTELIJKE_RENTE_B2C = 0.04;

	/**
	 * B2C grace period after stage-3 dispatch (Wet IK / art. 6:96 lid 6 BW).
	 */
	public const B2C_GRACE_DAYS = 14;

	/**
	 * Calculate the BIK staffel breakdown for an outstanding principal.
	 *
	 * Returns the shape expected on IncassoKostenBerekening.calculation per
	 * REQ-CCD-003: five slab amounts, the gross total, the statutory minimum
	 * and maximum, applied = min(max(total, minimum), maximum), and the
	 * BTW-over-incassokosten surcharge (art. 2 lid 2 Besluit BIK) when the
	 * creditor cannot offset VAT.
	 *
	 * @param float $principal Principal in EUR.
	 * @param bool $vatOffsettable True when the creditor CAN offset VAT (no surcharge). Default true.
	 * @param float $vatPercentage VAT rate applied when !vatOffsettable (default 21%).
	 *
	 * @return array{scale1_0_2500:float,scale2_2500_5000:float,scale3_5000_10000:float,scale4_10000_200000:float,scale5_200000plus:float,total:float,minimum:float,maximum:float,applied:float,vatOffsettable:bool,vatPercentage:float,vatAmount:float,appliedInclVat:float}
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function staffel(
		float $principal,
		bool $vatOffsettable = true,
		float $vatPercentage = self::DEFAULT_BTW_PERCENTAGE,
	): array {
		if ($principal < 0) {
			throw new InvalidArgumentException('BIK staffel principal must be non-negative.');
		}

		if ($vatPercentage < 0) {
			throw new InvalidArgumentException('BTW percentage must be non-negative.');
		}

		$hoofdsomCents = $this->toCents(amount: $principal);

		$slabAmountsCents = [0, 0, 0, 0, 0];
		$previousBound = 0;

		foreach (self::SLAB_BOUNDS_CENTS as $i => $upperBound) {
			$sliceTop = min($hoofdsomCents, $upperBound);
			$slice = max($sliceTop - $previousBound, 0);
			$slabAmountsCents[$i] = (int)round($slice * self::SLAB_RATES[$i]);
			$previousBound = $upperBound;
		}

		// Open-ended top slab (>€200.000).
		$topSlice = max($hoofdsomCents - 20000000, 0);
		$slabAmountsCents[4] = (int)round($topSlice * self::SLAB_RATES[4]);

		$totaalCents = array_sum($slabAmountsCents);

		// Statutory floor (€40) then statutory ceiling (€6.775).
		$toegepastCents = max($totaalCents, self::MINIMUM_CENTS);
		$toegepastCents = min($toegepastCents, self::MAXIMUM_CENTS);

		// BTW-over-incassokosten (art. 2 lid 2 Besluit BIK) on the normed fee.
		$btwCents = 0;
		$appliedBtwPercentage = 0.0;
		if ($vatOffsettable === false) {
			$btwCents = (int)round($toegepastCents * $vatPercentage);
			$appliedBtwPercentage = $vatPercentage;
		}

		return [
			'scale1_0_2500' => $this->fromCents(cents: $slabAmountsCents[0]),
			'scale2_2500_5000' => $this->fromCents(cents: $slabAmountsCents[1]),
			'scale3_5000_10000' => $this->fromCents(cents: $slabAmountsCents[2]),
			'scale4_10000_200000' => $this->fromCents(cents: $slabAmountsCents[3]),
			'scale5_200000plus' => $this->fromCents(cents: $slabAmountsCents[4]),
			'total' => $this->fromCents(cents: $totaalCents),
			'minimum' => $this->fromCents(cents: self::MINIMUM_CENTS),
			'maximum' => $this->fromCents(cents: self::MAXIMUM_CENTS),
			'applied' => $this->fromCents(cents: $toegepastCents),
			'vatOffsettable' => $vatOffsettable,
			'vatPercentage' => $appliedBtwPercentage,
			'vatAmount' => $this->fromCents(cents: $btwCents),
			'appliedInclVat' => $this->fromCents(cents: ($toegepastCents + $btwCents)),
		];

	}//end staffel()

	/**
	 * Compute wettelijke rente accrual per REQ-CCD-003.
	 *
	 * The rate is resolved from the maintained date-keyed table, so an accrual
	 * window that crosses a statutory rate boundary is split into sub-periods
	 * that each accrue at their own rate. A caller MAY pass an explicit
	 * override rate (e.g. a contractually agreed B2B rate per art. 6:119a
	 * lid 3 BW); an override forces a single flat period.
	 *
	 * @param string $partyType 'B2B' or 'B2C' (anything else treated as B2B for safety).
	 * @param float $principal Outstanding principal in EUR.
	 * @param DateTimeImmutable $effectiveDate First day the rente accrues.
	 * @param DateTimeImmutable $calculatedOn Calculation date.
	 * @param float|null $rateB2B Override the B2B handelsrente (flat, skips the table).
	 * @param float|null $rateB2C Override the B2C wettelijke rente (flat, skips the table).
	 *
	 * @return array{rate:float,type:string,ingangsdatum:string,calculatedOn:string,days:int,amount:float,periods:array<int,array{van:string,tot:string,days:int,rate:float,amount:float}>}
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function rente(
		string $partyType,
		float $principal,
		DateTimeImmutable $effectiveDate,
		DateTimeImmutable $calculatedOn,
		?float $rateB2B = null,
		?float $rateB2C = null,
	): array {
		if ($principal < 0) {
			throw new InvalidArgumentException('Rente principal must be non-negative.');
		}

		if ($calculatedOn < $effectiveDate) {
			throw new InvalidArgumentException('calculatedOn must not be before ingangsdatum.');
		}

		$isB2C = ($partyType === 'B2C');
		if ($isB2C === true) {
			$type = 'STATUTORY_INTEREST_B2_C_6_119_BW';
			$table = self::WETTELIJKE_RENTE_B2C_TABLE;
			$override = $rateB2C;
		} else {
			$type = 'COMMERCIAL_INTEREST_B2_B_6_119_A_BW';
			$table = self::HANDELSRENTE_B2B_TABLE;
			$override = $rateB2B;
		}

		$hoofdsomCents = $this->toCents(amount: $principal);

		if ($override !== null) {
			// Explicit override — single flat period, table bypassed.
			$segments = [
				[
					'van' => $effectiveDate,
					'tot' => $calculatedOn,
					'rate' => $override,
				],
			];
		} else {
			$segments = $this->splitByRateBoundaries(
				table: $table,
				from: $effectiveDate,
				to: $calculatedOn
			);
		}

		$periods = [];
		$totaalCents = 0;
		$totaalDagen = 0;
		foreach ($segments as $segment) {
			$days = (int)$segment['van']->diff($segment['tot'])->days;
			$segmentCents = (int)round((($hoofdsomCents * $segment['rate'] * $days) / 365.0));
			$totaalCents += $segmentCents;
			$totaalDagen += $days;
			$periods[] = [
				'van' => $segment['van']->format('Y-m-d'),
				'tot' => $segment['tot']->format('Y-m-d'),
				'days' => $days,
				'rate' => $segment['rate'],
				'amount' => $this->fromCents(cents: $segmentCents),
			];
		}

		// Headline rate = the rate in force on calculatedOn (the current rate).
		$lastSegment = end($segments);
		$headlineTarief = (float)$lastSegment['rate'];

		return [
			'rate' => $headlineTarief,
			'type' => $type,
			'ingangsdatum' => $effectiveDate->format('Y-m-d'),
			'calculatedOn' => $calculatedOn->format('Y-m-d'),
			'days' => $totaalDagen,
			'amount' => $this->fromCents(cents: $totaalCents),
			'periods' => $periods,
		];

	}//end rente()

	/**
	 * Resolve the statutory rate in force on a given date from a rate table.
	 *
	 * Picks the latest effectiveFrom entry that is on or before $on. Dates
	 * before the earliest table entry fall back to the earliest rate (the
	 * table is authoritative for the accrual window in practice).
	 *
	 * @param array<string,float> $table Rate table (effectiveFrom => rate).
	 * @param DateTimeImmutable $on Date to resolve.
	 *
	 * @return float Annual rate as a decimal.
	 */
	public function resolveRateOn(array $table, DateTimeImmutable $on): float {
		$onStr = $on->format('Y-m-d');
		$resolved = null;
		foreach ($table as $effectiveFrom => $rate) {
			if ($effectiveFrom <= $onStr) {
				$resolved = $rate;
			}
		}

		if ($resolved === null) {
			$resolved = (float)reset($table);
		}

		return (float)$resolved;
	}//end resolveRateOn()

	/**
	 * Split an accrual window at every statutory rate boundary it crosses.
	 *
	 * @param array<string,float> $table Rate table (effectiveFrom => rate).
	 * @param DateTimeImmutable $from Start of accrual (inclusive).
	 * @param DateTimeImmutable $to End of accrual (exclusive of the day).
	 *
	 * @return array<int,array{van:DateTimeImmutable,tot:DateTimeImmutable,rate:float}>
	 */
	private function splitByRateBoundaries(array $table, DateTimeImmutable $from, DateTimeImmutable $to): array {
		$fromStr = $from->format('Y-m-d');
		$toStr = $to->format('Y-m-d');

		// Boundaries strictly inside (from, to) become cut points.
		$cutPoints = [];
		foreach (array_keys($table) as $effectiveFrom) {
			if ($effectiveFrom > $fromStr && $effectiveFrom < $toStr) {
				$cutPoints[] = new DateTimeImmutable($effectiveFrom);
			}
		}

		$segments = [];
		$cursor = $from;
		foreach ($cutPoints as $cut) {
			$segments[] = [
				'van' => $cursor,
				'tot' => $cut,
				'rate' => $this->resolveRateOn(table: $table, on: $cursor),
			];
			$cursor = $cut;
		}

		$segments[] = [
			'van' => $cursor,
			'tot' => $to,
			'rate' => $this->resolveRateOn(table: $table, on: $cursor),
		];

		return $segments;
	}//end splitByRateBoundaries()

	/**
	 * Check whether B2C incasso-cost calculation is permitted on the given day.
	 *
	 * Per art. 6:96 lid 6 BW, B2C debiteuren receive a mandatory 14-day grace
	 * after the stage-3 aanmaning before incassokosten may be levied. Stage 3
	 * fires on daysAfterExpiryDate = 30, so the earliest permitted day is 44.
	 *
	 * @param string $partyType 'B2B' / 'B2C'.
	 * @param int $daysInArrears Number of days the invoice is overdue.
	 *
	 * @return bool True when the calculation is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function isCalculationPermitted(string $partyType, int $daysInArrears): bool {
		if ($partyType !== 'B2C') {
			return true;
		}

		return $daysInArrears >= (30 + self::B2C_GRACE_DAYS);
	}//end isCalculationPermitted()

	/**
	 * Assemble the full IncassoKostenBerekening record body per REQ-CCD-003.
	 *
	 * @param string $invoiceId Invoice FK.
	 * @param string $administrationId Administration scope.
	 * @param string $partyType 'B2B' / 'B2C' / 'GOVERNMENT'.
	 * @param float $principal Outstanding principal in EUR.
	 * @param DateTimeImmutable $effectiveDate First day the rente accrues.
	 * @param DateTimeImmutable $calculatedOn Calculation date.
	 * @param float|null $rateB2B Override the B2B handelsrente.
	 * @param float|null $rateB2C Override the B2C wettelijke rente.
	 * @param bool $vatOffsettable True when the creditor CAN offset VAT (no surcharge).
	 * @param float $vatPercentage VAT rate applied when !vatOffsettable (default 21%).
	 *
	 * @return array<string,mixed> Body ready to persist via ObjectService::saveObject.
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function compose(
		string $invoiceId,
		string $administrationId,
		string $partyType,
		float $principal,
		DateTimeImmutable $effectiveDate,
		DateTimeImmutable $calculatedOn,
		?float $rateB2B = null,
		?float $rateB2C = null,
		bool $vatOffsettable = true,
		float $vatPercentage = self::DEFAULT_BTW_PERCENTAGE,
	): array {
		$calculation = $this->staffel(
			principal: $principal,
			vatOffsettable: $vatOffsettable,
			vatPercentage: $vatPercentage
		);

		// For GOVERNMENT we still need a rente choice — treat as B2B handelsrente.
		if ($partyType === 'B2C') {
			$effectiveParty = 'B2C';
		} else {
			$effectiveParty = 'B2B';
		}

		// Named-argument LABELS carry no `$`, so a variable rename does not touch
		// them — and a named argument needs the parameter to exist by that exact
		// name. rente() already declares effectiveDate/rateB2B/rateB2C.
		$rente = $this->rente(
			partyType: $effectiveParty,
			principal: $principal,
			effectiveDate: $effectiveDate,
			calculatedOn: $calculatedOn,
			rateB2B: $rateB2B,
			rateB2C: $rateB2C
		);

		$hoofdsomCents = $this->toCents(amount: $principal);
		// Incassokosten owed = the normed fee INCLUDING any BTW surcharge.
		$incassoCents = $this->toCents(amount: $calculation['appliedInclVat']);
		$renteCents = $this->toCents(amount: $rente['amount']);
		$totaalCents = ($hoofdsomCents + $incassoCents + $renteCents);

		return [
			'invoiceId' => $invoiceId,
			'principal' => round($principal, 2),
			'calculation' => $calculation,
			'statutoryRente' => $rente,
			'partyType' => $partyType,
			'totalDue' => $this->fromCents(cents: $totaalCents),
			'administrationId' => $administrationId,
		];

	}//end compose()

	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param float $amount EUR amount.
	 *
	 * @return int Whole cents.
	 */
	private function toCents(float $amount): int {
		return (int)round($amount * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a 2-decimal float.
	 *
	 * @param int $cents Whole cents.
	 *
	 * @return float 2-decimal EUR amount.
	 */
	private function fromCents(int $cents): float {
		return round(($cents / 100), 2);
	}//end fromCents()
}//end class
