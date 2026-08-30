<?php

/**
 * ICP Calculator
 *
 * Pure-logic helper for the Tier-3 intra-community supplies (ICP) filing
 * (REQ-ICP-002, REQ-ICP-003, REQ-ICP-004, REQ-ICP-005, REQ-ICP-006). Holds the
 * side-effect-free arithmetic and transforms that IcpService applies after
 * fetching IcpSupply / IcpOpgaaf / VatReturn data via the OpenRegister
 * ObjectService: aggregating supplies into ledger lines by (buyerVatId,
 * supplyType), summing the goods/services/triangulation totals, deciding the
 * EUR 50,000 periodicity threshold, reconciling against BTW-aangifte rubriek 3b
 * within EUR 1 tolerance, routing a supply type to its zero-rated GL account, and
 * composing the SBR/Digipoort XBRL instance. All money arithmetic is performed in
 * integer cents to avoid IEEE-754 equality drift, mirroring TrialBalanceCalculator.
 *
 * Per ADR-031 the equivalent declarative shapes are documented on the schemas
 * (x-openregister-aggregations.icpLedgerByBuyerSupplyType on IcpSupply,
 * x-openregister-calculations.xmlPayload on IcpOpgaaf); this helper is the
 * engine-side fallback the aggregation/calculation engine cannot yet express.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free ICP arithmetic and transform helper.
 *
 * No OpenRegister dependency: every method takes plain arrays and returns plain
 * arrays/scalars so the logic is unit-testable in isolation. IcpService wires this
 * helper to live IcpSupply / IcpOpgaaf / VatReturn data.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class IcpCalculator {
	/**
	 * EUR 50,000 goods-supply periodicity threshold, in whole cents (REQ-ICP-002).
	 *
	 * @var int
	 */
	public const PERIODICITY_THRESHOLD_CENTS = 5000000;

	/**
	 * Reconciliation tolerance against rubriek 3b, in whole cents (REQ-ICP-004).
	 *
	 * @var int
	 */
	public const RECONCILIATION_TOLERANCE_CENTS = 100;

	/**
	 * Convert a money amount to integer cents (REQ-ICP-004 precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents (half-even rounding).
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100, 0, PHP_ROUND_HALF_EVEN);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Money amount.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Aggregate supplies into ICP-opgaaf lines by (buyerVatId, supplyType) (REQ-ICP-003, REQ-ICP-006).
	 *
	 * One line per unique (buyerVatId, supplyType) combination; amounts summed with
	 * sign preserved (credit notes are negative). Triangulation (T) is never merged
	 * with goods (L) or services (S), even when the buyer VAT-ID matches, because
	 * supplyType is part of the grouping key. Lines are sorted by buyerVatId
	 * ascending, then by supplyType for stable ordering.
	 *
	 * @param array<int,array<string,mixed>> $supplies IcpSupply records, each carrying
	 *                                                 buyerVatId, supplyType, amountExclVat.
	 *
	 * @return array<int,array{buyerVatId:string,supplyType:string,amountExclVat:float}> Sorted lines.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function aggregateLines(array $supplies): array {
		$byKey = [];
		foreach ($supplies as $supply) {
			$buyer = (string)($supply['buyerVatId'] ?? '');
			$type = (string)($supply['supplyType'] ?? '');
			if ($buyer === '' || $type === '') {
				continue;
			}

			$key = $buyer . '|' . $type;
			if (isset($byKey[$key]) === false) {
				$byKey[$key] = [
					'buyerVatId' => $buyer,
					'supplyType' => $type,
					'cents' => 0,
				];
			}

			$byKey[$key]['cents'] += $this->toCents(amount: ($supply['amountExclVat'] ?? 0));
		}//end foreach

		$lines = array_values($byKey);
		usort(
			$lines,
			static function (array $a, array $b): int {
				$cmp = strcmp($a['buyerVatId'], $b['buyerVatId']);
				if ($cmp !== 0) {
					return $cmp;
				}

				return strcmp($a['supplyType'], $b['supplyType']);
			}
		);

		$result = [];
		foreach ($lines as $line) {
			$result[] = [
				'buyerVatId' => $line['buyerVatId'],
				'supplyType' => $line['supplyType'],
				'amountExclVat' => $this->fromCents(cents: $line['cents']),
			];
		}

		return $result;
	}//end aggregateLines()

	/**
	 * Sum the goods / services / triangulation / grand totals of a line set (REQ-ICP-002).
	 *
	 * The totalGoods value sums L and T lines (goods including triangulation — the
	 * threshold base per Art. 263 §1bis); totalServices sums S lines;
	 * totalTriangulation sums T lines only (reporting clarity, REQ-ICP-006); total
	 * is the grand sum.
	 *
	 * @param array<int,array<string,mixed>> $lines Aggregated lines.
	 *
	 * @return array{total:float,totalGoods:float,totalServices:float,totalTriangulation:float}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function totals(array $lines): array {
		$total = 0;
		$goods = 0;
		$services = 0;
		$triangulation = 0;
		foreach ($lines as $line) {
			$cents = $this->toCents(amount: ($line['amountExclVat'] ?? 0));
			$type = (string)($line['supplyType'] ?? '');
			$total += $cents;
			if ($type === 'L' || $type === 'T') {
				$goods += $cents;
			}

			if ($type === 'S') {
				$services += $cents;
			}

			if ($type === 'T') {
				$triangulation += $cents;
			}
		}//end foreach

		return [
			'total' => $this->fromCents(cents: $total),
			'totalGoods' => $this->fromCents(cents: $goods),
			'totalServices' => $this->fromCents(cents: $services),
			'totalTriangulation' => $this->fromCents(cents: $triangulation),
		];

	}//end totals()

	/**
	 * Decide whether cumulative goods supplies breach the EUR 50,000 threshold (REQ-ICP-002).
	 *
	 * Sums supplyType L and T amounts (goods, including triangulation); compared in
	 * integer cents against PERIODICITY_THRESHOLD_CENTS. The threshold is breached
	 * when the cumulative amount strictly exceeds EUR 50,000.
	 *
	 * @param array<int,array<string,mixed>> $quarterSupplies Supplies in one calendar quarter.
	 *
	 * @return array{breached:bool,goodsCumulative:float} Decision plus the cumulative goods amount.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function periodicityBreach(array $quarterSupplies): array {
		$goods = 0;
		foreach ($quarterSupplies as $supply) {
			$type = (string)($supply['supplyType'] ?? '');
			if ($type === 'L' || $type === 'T') {
				$goods += $this->toCents(amount: ($supply['amountExclVat'] ?? 0));
			}
		}

		return [
			'breached' => ($goods > self::PERIODICITY_THRESHOLD_CENTS),
			'goodsCumulative' => $this->fromCents(cents: $goods),
		];

	}//end periodicityBreach()

	/**
	 * Reconcile an ICP-opgaaf total against the BTW-aangifte rubriek 3b (REQ-ICP-004).
	 *
	 * Returns whether the two amounts match within the EUR 1 tolerance. Compared in
	 * integer cents to avoid float drift. A null rubriek (no BTW-aangifte for the
	 * period) is reported as missing so the caller can surface icp.btw.missing.
	 *
	 * @param float $icpTotal The ICP-opgaaf grand total.
	 * @param float|null $rubriek3b The period's BTW-aangifte rubriek 3b value, or null when absent.
	 *
	 * @return array{matches:bool,missing:bool,differenceCents:int} Reconciliation outcome.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function reconcile(float $icpTotal, ?float $rubriek3b): array {
		if ($rubriek3b === null) {
			return [
				'matches' => false,
				'missing' => true,
				'differenceCents' => 0,
			];
		}

		$diff = abs($this->toCents(amount: $icpTotal) - $this->toCents(amount: $rubriek3b));

		return [
			'matches' => ($diff <= self::RECONCILIATION_TOLERANCE_CENTS),
			'missing' => false,
			'differenceCents' => $diff,
		];

	}//end reconcile()

	/**
	 * Route a supply type to its zero-rated RGS GL account number (Task 22).
	 *
	 * L (goods) → 8190, S (services) → 8195, T (triangulation) → 8196. An unknown
	 * type returns an empty string so the caller can flag the miscoding.
	 *
	 * @param string $supplyType The ICP supply type (L / S / T).
	 *
	 * @return string The zero-rated account number, or '' when the type is unknown.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function accountForSupplyType(string $supplyType): string {
		return match ($supplyType) {
			'L' => '8190',
			'S' => '8195',
			'T' => '8196',
			default => '',
		};

	}//end accountForSupplyType()

	/**
	 * Compose the SBR/Digipoort XBRL instance for an ICP-opgaaf (REQ-ICP-005).
	 *
	 * Engine-side fallback for the declarative x-openregister-calculations.xmlPayload
	 * shape: emits one bd-i:IntracommunautairePrestatieMutatieSpecificatie element
	 * per line with gl-cor:periodIdentifier and bd-i:supplyCode, amounts to two
	 * decimals (half-even). The element values are XML-escaped to keep the instance
	 * well-formed. This is a deterministic, side-effect-free transform; live
	 * NT18-schema validation against bd-rpt-icp-2026.xsd and Digipoort delivery
	 * are performed by the openconnector integration (deferred; needs a live
	 * instance).
	 *
	 * @param string $period Filing period (e.g. "2026-Q2").
	 * @param string $filerVatId The filer's RSIN/BSN-derived VAT-ID.
	 * @param array<int,array<string,mixed>> $lines Aggregated lines.
	 *
	 * @return string A well-formed XBRL-instance XML string.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function composeXbrl(string $period, string $filerVatId, array $lines): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance"'
			. ' xmlns:gl-cor="http://www.xbrl.org/int/gl/cor/2025-12-01"'
			. ' xmlns:bd-i="http://www.nltaxonomie.nl/nt18/bd/bd-instance">' . "\n";
		$xml .= '  <gl-cor:periodIdentifier>' . $this->escape(value: $period) . '</gl-cor:periodIdentifier>' . "\n";
		$xml .= '  <bd-i:filerVatId>' . $this->escape(value: $filerVatId) . '</bd-i:filerVatId>' . "\n";
		foreach ($lines as $line) {
			$buyer = $this->escape(value: (string)($line['buyerVatId'] ?? ''));
			$type = $this->escape(value: (string)($line['supplyType'] ?? ''));
			$amount = number_format(
				round((float)($line['amountExclVat'] ?? 0), 2, PHP_ROUND_HALF_EVEN),
				2,
				'.',
				''
			);
			$xml .= '  <bd-i:IntracommunautairePrestatieMutatieSpecificatie>' . "\n";
			$xml .= '    <bd-i:buyerVatId>' . $buyer . '</bd-i:buyerVatId>' . "\n";
			$xml .= '    <bd-i:supplyCode>' . $type . '</bd-i:supplyCode>' . "\n";
			$xml .= '    <bd-i:amount>' . $amount . '</bd-i:amount>' . "\n";
			$xml .= '  </bd-i:IntracommunautairePrestatieMutatieSpecificatie>' . "\n";
		}

		$xml .= '</xbrli:xbrl>' . "\n";

		return $xml;
	}//end composeXbrl()

	/**
	 * Build the audit-trail supplies CSV for the inspection bundle (REQ-ICP-010).
	 *
	 * One header row plus one row per supply with invoiceRef, buyerVatId,
	 * supplyType, amountExclVat, and viesRequestId. Fields are RFC-4180 quoted.
	 *
	 * @param array<int,array<string,mixed>> $supplies IcpSupply records.
	 * @param array<string,string> $requestIds Map of viesValidationId => VIES requestId.
	 *
	 * @return string The CSV content.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function buildSuppliesCsv(array $supplies, array $requestIds): string {
		$rows = [];
		$rows[] = ['invoiceRef', 'buyerVatId', 'supplyType', 'amountExclVat', 'viesRequestId'];
		foreach ($supplies as $supply) {
			$viesId = (string)($supply['viesValidationId'] ?? '');
			$requestId = ($requestIds[$viesId] ?? '');
			$rows[] = [
				(string)($supply['invoiceId'] ?? ''),
				(string)($supply['buyerVatId'] ?? ''),
				(string)($supply['supplyType'] ?? ''),
				number_format(
					round((float)($supply['amountExclVat'] ?? 0), 2, PHP_ROUND_HALF_EVEN),
					2,
					'.',
					''
				),
				$requestId,
			];
		}//end foreach

		$lines = [];
		foreach ($rows as $row) {
			$quoted = array_map(
				static function (string $field): string {
					return '"' . str_replace('"', '""', $field) . '"';
				},
				$row
			);
			$lines[] = implode(',', $quoted);
		}

		return implode("\n", $lines) . "\n";
	}//end buildSuppliesCsv()

	/**
	 * XML-escape a value for inclusion in an XBRL element body.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The escaped value.
	 */
	private function escape(string $value): string {
		return htmlspecialchars($value, (ENT_XML1 | ENT_QUOTES), 'UTF-8');
	}//end escape()
}//end class
