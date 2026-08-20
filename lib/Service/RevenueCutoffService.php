<?php

/**
 * Revenue Cutoff Service
 *
 * Tier-2 nightly IFRS 15 revenue cut-off (REQ-IFRS15-007, REQ-IFRS15-008). For each
 * contract in an administration it sums cumulative recognised revenue (from
 * RevenueRecognitionEvent) and cumulative billed amount, derives the contract
 * asset / contract liability per IFRS 15.116-119, and builds the per-period revenue
 * waterfall row (allocated price, period + cumulative recognised, remaining amount)
 * per IFRS 15.120. Reads use the real OpenRegister ObjectService API
 * (find / findAll) — never the non-existent findObject / createFromArray — and are
 * always scoped to a single administration resolved from the authenticated user's
 * context (ADR-005 IDOR-safety, ADR-022 reuse).
 *
 * Per ADR-031 the equivalent declarative shapes are documented on the schemas
 * (ContractAsset / ContractLiability + RevenueWaterfall.x-openregister-aggregations);
 * this service is the engine-side fallback for the cross-schema recognised-vs-billed
 * reconciliation and the prior-period carry that the declarative aggregation engine
 * cannot yet express. The GL posting itself (debit accrued-revenue / credit revenue,
 * reverse-then-post) is the materialisation documented on RevenueRecognitionEvent;
 * this service never relaxes the balanced-posting invariant.
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
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
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
 * Computes per-contract revenue cut-off balances and waterfall rows.
 *
 * Reads are scoped to a single administration (REQ-IFRS15-007): callers pass the
 * administrationId resolved from the authenticated user's context, never a
 * client-supplied trust boundary. The recognised amount comes from
 * RevenueRecognitionEvent rows; the billed amount is supplied per contract by the
 * caller (sourced from the AR/invoice module); the asset/liability split and the
 * remaining-amount waterfall come from RevenueRecognitionCalculator.
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
 */
class RevenueCutoffService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param RevenueRecognitionCalculator $calculator Pure-logic IFRS 15 arithmetic helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly RevenueRecognitionCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the revenue cut-off for one administration (REQ-IFRS15-007, REQ-IFRS15-008).
	 *
	 * Returns a per-contract list of cut-off rows: cumulative recognised, cumulative
	 * billed (from the supplied $billedByContract map), the contract asset /
	 * liability split, the transaction price allocated, the remaining amount, and the
	 * period recognised. The job is idempotent — it derives balances from the current
	 * event + billing snapshot, so re-running on the same snapshot yields identical
	 * rows (REQ-IFRS15-007).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $periodEnd Period-end date the cut-off covers (REQ-IFRS15-008).
	 * @param array<string,float> $billedByContract contractId => cumulative billed amount (from AR module).
	 *
	 * @return array{data: array<int,array<string,mixed>>, total: int}
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
	 */
	public function compute(string $administrationId, string $periodEnd, array $billedByContract = []): array {
		$contracts = $this->fetchContracts(administrationId: $administrationId);
		$recognised = $this->recognisedByContract(administrationId: $administrationId, periodEnd: $periodEnd);
		$allocated = $this->allocatedByContract(administrationId: $administrationId);

		$rows = [];
		$contractIds = array_unique(array_merge(array_keys($contracts), array_keys($recognised)));
		sort($contractIds);
		foreach ($contractIds as $contractId) {
			$cumulativeRecognised = (float)($recognised[$contractId]['cumulative'] ?? 0.0);
			$periodRecognised = (float)($recognised[$contractId]['period'] ?? 0.0);
			$cumulativeBilled = (float)($billedByContract[$contractId] ?? 0.0);
			$allocatedPrice = (float)($allocated[$contractId] ?? 0.0);

			$split = $this->calculator->contractAssetLiability(
				cumulativeRecognised: $cumulativeRecognised,
				cumulativeBilled: $cumulativeBilled
			);
			$remaining = $this->calculator->remainingAmount(
				transactionPriceAllocated: $allocatedPrice,
				cumulativeRecognised: $cumulativeRecognised
			);

			$rows[] = [
				'contractId' => (string)$contractId,
				'periodEnd' => $periodEnd,
				'transactionPriceAllocated' => $allocatedPrice,
				'cumulativeRecognised' => $cumulativeRecognised,
				'periodRecognised' => $periodRecognised,
				'cumulativeBilled' => $cumulativeBilled,
				'remainingAmount' => $remaining,
				'contractAsset' => $split['asset'],
				'contractLiability' => $split['liability'],
				'administrationId' => $administrationId,
			];
		}//end foreach

		return [
			'data' => $rows,
			'total' => count($rows),
		];

	}//end compute()

	/**
	 * Fetch the administration's contracts keyed by contractNumber (REQ-IFRS15-007).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> contractNumber => Contract object.
	 */
	private function fetchContracts(string $administrationId): array {
		$contracts = $this->objectService
			->setRegister($this->register())
			->setSchema('RevenueContract')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byNumber = [];
		foreach ($contracts as $contract) {
			$number = (string)($contract['contractNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $contract;
			}
		}

		return $byNumber;
	}//end fetchContracts()

	/**
	 * Sum recognised revenue per contract: cumulative through period end + this period (REQ-IFRS15-008).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodEnd Period-end date dividing cumulative-prior from this period.
	 *
	 * @return array<string,array{cumulative:float,period:float}> contractId => recognised amounts.
	 */
	private function recognisedByContract(string $administrationId, string $periodEnd): array {
		$events = $this->objectService
			->setRegister($this->register())
			->setSchema('RevenueRecognitionEvent')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byContract = [];
		foreach ($events as $event) {
			$contractId = (string)($event['contractId'] ?? '');
			if ($contractId === '') {
				continue;
			}

			$end = (string)($event['periodEnd'] ?? '');
			// Events with periodEnd after the cut-off date are out of scope.
			if ($end !== '' && $end > $periodEnd) {
				continue;
			}

			if (isset($byContract[$contractId]) === false) {
				$byContract[$contractId] = ['cumulativeCents' => 0, 'periodCents' => 0];
			}

			$cents = $this->calculator->toCents(amount: ($event['recognisedAmount'] ?? 0));
			$byContract[$contractId]['cumulativeCents'] += $cents;
			if ($end === $periodEnd) {
				$byContract[$contractId]['periodCents'] += $cents;
			}
		}//end foreach

		$result = [];
		foreach ($byContract as $contractId => $amounts) {
			$result[$contractId] = [
				'cumulative' => $this->calculator->fromCents(cents: $amounts['cumulativeCents']),
				'period' => $this->calculator->fromCents(cents: $amounts['periodCents']),
			];
		}

		return $result;
	}//end recognisedByContract()

	/**
	 * Sum the allocated transaction price per contract from PriceAllocation rows (REQ-IFRS15-004).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,float> contractId => total allocated price.
	 */
	private function allocatedByContract(string $administrationId): array {
		$allocations = $this->objectService
			->setRegister($this->register())
			->setSchema('PriceAllocation')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byContract = [];
		foreach ($allocations as $allocation) {
			$contractId = (string)($allocation['contractId'] ?? '');
			if ($contractId === '') {
				continue;
			}

			$cents = $this->calculator->toCents(amount: ($allocation['allocatedAmount'] ?? 0));
			$byContract[$contractId] = (($byContract[$contractId] ?? 0) + $cents);
		}

		$result = [];
		foreach ($byContract as $contractId => $cents) {
			$result[$contractId] = $this->calculator->fromCents(cents: $cents);
		}

		return $result;
	}//end allocatedByContract()

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
