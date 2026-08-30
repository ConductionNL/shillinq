<?php

/**
 * ICP Service
 *
 * Tier-3 intra-community supplies (ICP) computation (REQ-ICP-002, REQ-ICP-003,
 * REQ-ICP-004). Reads IcpSupply / IcpOpgaaf / VatReturn / ViesValidation data for
 * one administration from OpenRegister via the real ObjectService API
 * (find / findAll) and applies the side-effect-free IcpCalculator to produce the
 * ICP ledger (aggregated lines + totals), the periodicity-threshold decision, and
 * the reconciliation outcome against the BTW-aangifte rubriek 3b.
 *
 * Per ADR-031 the equivalent declarative shapes live on the schemas
 * (x-openregister-aggregations.icpLedgerByBuyerSupplyType,
 * x-openregister-calculations.xmlPayload, x-openregister-lifecycle on IcpOpgaaf);
 * this service is the engine-side fallback for the period-window selection and the
 * cross-schema reconciliation join the declarative engine cannot yet express.
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

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes the ICP ledger, periodicity decision, and reconciliation for a period.
 *
 * Reads are scoped to a single administration (REQ-ICP-001, REQ-ICP-004): callers
 * pass the administrationId resolved from the authenticated user's context, never
 * a client-supplied trust boundary. The OpenRegister ObjectService enforces the
 * multitenancy / RBAC boundary on every find.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class IcpService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IcpCalculator $calculator Pure-logic ICP helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IcpCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the ICP ledger (aggregated lines + totals) for one period (REQ-ICP-003).
	 *
	 * Fetches the administration's IcpSupply records whose supplyDate falls in the
	 * requested period, aggregates them by (buyerVatId, supplyType), and sums the
	 * goods / services / triangulation totals. Returns the lines, totals, and the
	 * source supply count.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-ICP-001).
	 * @param string $period Filing period (YYYY-Qn or YYYY-MM).
	 *
	 * @return array{period:string,lines:array<int,array<string,mixed>>,total:float,totalGoods:float,totalServices:float,totalTriangulation:float,supplyCount:int}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function ledger(string $administrationId, string $period): array {
		$supplies = $this->suppliesForPeriod(administrationId: $administrationId, period: $period);
		$lines = $this->calculator->aggregateLines(supplies: $supplies);
		$totals = $this->calculator->totals(lines: $lines);

		return [
			'period' => $period,
			'lines' => $lines,
			'total' => $totals['total'],
			'totalGoods' => $totals['totalGoods'],
			'totalServices' => $totals['totalServices'],
			'totalTriangulation' => $totals['totalTriangulation'],
			'supplyCount' => count($supplies),
		];

	}//end ledger()

	/**
	 * Decide whether a quarter's goods supplies breach the EUR 50,000 threshold (REQ-ICP-002).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $quarter Calendar quarter (YYYY-Qn).
	 *
	 * @return array{quarter:string,breached:bool,goodsCumulative:float}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function periodicityCheck(string $administrationId, string $quarter): array {
		$supplies = $this->suppliesForPeriod(administrationId: $administrationId, period: $quarter);
		$decision = $this->calculator->periodicityBreach(quarterSupplies: $supplies);

		return [
			'quarter' => $quarter,
			'breached' => $decision['breached'],
			'goodsCumulative' => $decision['goodsCumulative'],
		];

	}//end periodicityCheck()

	/**
	 * Reconcile a period's ICP ledger total against the BTW-aangifte rubriek 3b (REQ-ICP-004).
	 *
	 * Reads the period's VatReturn (BTW-aangifte) and compares its rubriek 3b value
	 * (the zero-rated intra-community supplies amount) against the computed ICP
	 * total within the EUR 1 tolerance. When no BTW-aangifte exists for the period,
	 * the outcome is flagged missing so the caller can surface icp.btw.missing.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period Filing period.
	 *
	 * @return array{period:string,icpTotal:float,rubriek3b:float|null,matches:bool,missing:bool,difference:float}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function reconcile(string $administrationId, string $period): array {
		$ledger = $this->ledger(administrationId: $administrationId, period: $period);
		$rubriek3b = $this->rubriek3bForPeriod(administrationId: $administrationId, period: $period);
		$outcome = $this->calculator->reconcile(icpTotal: $ledger['total'], rubriek3b: $rubriek3b);

		return [
			'period' => $period,
			'icpTotal' => $ledger['total'],
			'rubriek3b' => $rubriek3b,
			'matches' => $outcome['matches'],
			'missing' => $outcome['missing'],
			'difference' => $this->calculator->fromCents(cents: $outcome['differenceCents']),
		];

	}//end reconcile()

	/**
	 * Return the administration's IcpSupply records whose supplyDate is in the period.
	 *
	 * Public read accessor reused by IcpFilingService for the audit-trail export
	 * (REQ-ICP-010); the administration scope is server-resolved (REQ-ICP-001).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $period Filing period (YYYY-Qn or YYYY-MM).
	 *
	 * @return array<int,array<string,mixed>> In-period IcpSupply records.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function suppliesInPeriod(string $administrationId, string $period): array {
		return $this->suppliesForPeriod(administrationId: $administrationId, period: $period);
	}//end suppliesInPeriod()

	/**
	 * Fetch the administration's IcpSupply records whose supplyDate is in the period.
	 *
	 * @param string $administrationId Administration scope (REQ-ICP-001).
	 * @param string $period Filing period (YYYY-Qn or YYYY-MM).
	 *
	 * @return array<int,array<string,mixed>> In-period IcpSupply records.
	 */
	private function suppliesForPeriod(string $administrationId, string $period): array {
		$supplies = $this->objectService
			->setRegister($this->register())
			->setSchema('IcpSupply')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$inPeriod = [];
		foreach ($supplies as $supply) {
			if ($this->supplyDateInPeriod(supplyDate: (string)($supply['supplyDate'] ?? ''), period: $period) === true) {
				$inPeriod[] = $supply;
			}
		}

		return $inPeriod;
	}//end suppliesForPeriod()

	/**
	 * Read the BTW-aangifte rubriek 3b value for a period (REQ-ICP-004).
	 *
	 * Resolves the period's VatReturn for the administration and returns its rubriek
	 * 3b amount (the intra-community supplies field). Returns null when no return
	 * exists for the period. Until the VatReturn schema carries a dedicated
	 * rubriek3b field, the return's `amount` is read as the reconciliation proxy and
	 * an explicit `rubriek3b` field is honoured when present.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period Filing period.
	 *
	 * @return float|null The rubriek 3b value, or null when no BTW-aangifte exists.
	 */
	private function rubriek3bForPeriod(string $administrationId, string $period): ?float {
		$returns = $this->objectService
			->setRegister($this->register())
			->setSchema('VatReturn')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($returns as $return) {
			if ($this->vatReturnPeriod(return: $return) !== $period) {
				continue;
			}

			if (array_key_exists('rubriek3b', $return) === true && $return['rubriek3b'] !== null) {
				return (float)$return['rubriek3b'];
			}

			if (array_key_exists('amount', $return) === true && $return['amount'] !== null) {
				return (float)$return['amount'];
			}

			return 0.0;
		}//end foreach

		return null;
	}//end rubriek3bForPeriod()

	/**
	 * Derive a VatReturn's period string from its period fields.
	 *
	 * @param array<string,mixed> $return The VatReturn record.
	 *
	 * @return string The period string (YYYY-Qn or YYYY-MM), or '' when undetermined.
	 */
	private function vatReturnPeriod(array $return): string {
		$year = (string)($return['periodYear'] ?? '');
		if ($year === '') {
			return (string)($return['period'] ?? '');
		}

		$type = (string)($return['periodType'] ?? '');
		if ($type === 'quarter' || $type === 'quarterly') {
			$quarter = (string)($return['periodQuarter'] ?? '');
			if ($quarter !== '') {
				return $year . '-Q' . ltrim($quarter, 'Q');
			}
		}

		if ($type === 'month' || $type === 'monthly') {
			$month = (string)($return['periodMonth'] ?? '');
			if ($month !== '') {
				return $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
			}
		}

		return (string)($return['period'] ?? '');
	}//end vatReturnPeriod()

	/**
	 * Decide whether a supply date falls within a filing period (YYYY-Qn or YYYY-MM).
	 *
	 * @param string $supplyDate Supply date (YYYY-MM-DD).
	 * @param string $period Filing period (YYYY-Qn or YYYY-MM).
	 *
	 * @return bool True when the supply date belongs to the period.
	 */
	private function supplyDateInPeriod(string $supplyDate, string $period): bool {
		if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $supplyDate, $dateParts) !== 1) {
			return false;
		}

		$year = $dateParts[1];
		$month = (int)$dateParts[2];

		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $periodParts) === 1) {
			if ($periodParts[1] !== $year) {
				return false;
			}

			$quarter = (int)$periodParts[2];

			return ((int)ceil($month / 3)) === $quarter;
		}

		if (preg_match('/^(\d{4})-(\d{2})$/', $period, $periodParts) === 1) {
			return ($periodParts[1] === $year && (int)$periodParts[2] === $month);
		}

		return false;
	}//end supplyDateInPeriod()

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
