<?php

/**
 * Revenue Cutoff Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
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
		$run = new CutoffRun();
		$run->administrationId = $administrationId;
		$run->periodEnd = $periodEnd;
		$run->billedByContract = $billedByContract;
		$contracts = $this->fetchContracts(administrationId: $administrationId);
		$run->recognised = $this->recognisedByContract(administrationId: $administrationId, periodEnd: $periodEnd);
		$run->allocated = $this->allocatedByContract(administrationId: $administrationId);
		$contractIds = array_unique(array_merge(array_keys($contracts), array_keys($run->recognised)));
		sort($contractIds);
		$rows = array_map(fn (int|string $contractId): array => $this->cutoffRow(contractId: $contractId, run: $run), $contractIds);

		return ['data' => $rows, 'total' => count($rows)];

	}//end compute()

	/**
	 * One contract's cut-off row.
	 *
	 * @param int|string $contractId Contract id (an int for a numeric contract number).
	 * @param CutoffRun  $run        The run.
	 *
	 * @return array<string,mixed>
	 */
	private function cutoffRow(int|string $contractId, CutoffRun $run): array {
		return ['contractId' => (string)$contractId, 'periodEnd' => $run->periodEnd]
			+ $this->cutoffFigures(contractId: $contractId, run: $run)
			+ ['administrationId' => $run->administrationId];

	}//end cutoffRow()

	/**
	 * A contract's allocated price, recognised and billed amounts, remaining
	 * amount and contract asset / liability split.
	 *
	 * @param int|string $contractId Contract id.
	 * @param CutoffRun  $run        The run.
	 *
	 * @return array<string,float>
	 */
	private function cutoffFigures(int|string $contractId, CutoffRun $run): array {
		$cumulativeRecognised = (float)($run->recognised[$contractId]['cumulative'] ?? 0.0);
		$cumulativeBilled = (float)($run->billedByContract[$contractId] ?? 0.0);
		$allocatedPrice = (float)($run->allocated[$contractId] ?? 0.0);
		$split = $this->calculator->contractAssetLiability(cumulativeRecognised: $cumulativeRecognised, cumulativeBilled: $cumulativeBilled);

		return [
			'transactionPriceAllocated' => $allocatedPrice,
			'cumulativeRecognised' => $cumulativeRecognised,
			'periodRecognised' => (float)($run->recognised[$contractId]['period'] ?? 0.0),
			'cumulativeBilled' => $cumulativeBilled,
			'remainingAmount' => $this->calculator->remainingAmount(transactionPriceAllocated: $allocatedPrice, cumulativeRecognised: $cumulativeRecognised),
			'contractAsset' => $split['asset'],
			'contractLiability' => $split['liability'],
		];

	}//end cutoffFigures()

	/**
	 * Fetch the administration's contracts keyed by contractNumber (REQ-IFRS15-007).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> contractNumber => Contract object.
	 */
	private function fetchContracts(string $administrationId): array {
		$byNumber = [];
		foreach ($this->find(schema: 'RevenueContract', administrationId: $administrationId) as $contract) {
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
		$byContract = [];
		foreach ($this->countedEvents(administrationId: $administrationId, periodEnd: $periodEnd) as $event) {
			$contractId = (string)$event['contractId'];
			$cents = $this->calculator->toCents(amount: ($event['recognisedAmount'] ?? 0));
			$byContract[$contractId] ??= ['cumulativeCents' => 0, 'periodCents' => 0];
			$byContract[$contractId]['cumulativeCents'] += $cents;
			if ((string)($event['periodEnd'] ?? '') === $periodEnd) {
				$byContract[$contractId]['periodCents'] += $cents;
			}
		}

		return $this->recognisedAmounts(byContract: $byContract);
	}//end recognisedByContract()

	/**
	 * The administration's recognition events that count towards the cut-off.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodEnd        Period-end date of the cut-off.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function countedEvents(string $administrationId, string $periodEnd): array {
		return array_filter(
			$this->find(schema: 'RevenueRecognitionEvent', administrationId: $administrationId),
			fn (array $event): bool => $this->isCounted(event: $event, periodEnd: $periodEnd)
		);
	}//end countedEvents()

	/**
	 * Whether an event counts towards the cut-off: it names a contract and its
	 * periodEnd is absent or not after the cut-off date.
	 *
	 * @param array<string,mixed> $event     A RevenueRecognitionEvent.
	 * @param string              $periodEnd Period-end date of the cut-off.
	 *
	 * @return boolean
	 */
	private function isCounted(array $event, string $periodEnd): bool {
		$end = (string)($event['periodEnd'] ?? '');

		return (string)($event['contractId'] ?? '') !== '' && ($end === '' || ($end > $periodEnd) === false);
	}//end isCounted()

	/**
	 * Cent sums per contract as money.
	 *
	 * @param array<string,array{cumulativeCents:int,periodCents:int}> $byContract Cent sums.
	 *
	 * @return array<string,array{cumulative:float,period:float}>
	 */
	private function recognisedAmounts(array $byContract): array {
		$result = [];
		foreach ($byContract as $contractId => $amounts) {
			$result[$contractId] = [
				'cumulative' => $this->calculator->fromCents(cents: $amounts['cumulativeCents']),
				'period' => $this->calculator->fromCents(cents: $amounts['periodCents']),
			];
		}

		return $result;
	}//end recognisedAmounts()

	/**
	 * Sum the allocated transaction price per contract from PriceAllocation rows (REQ-IFRS15-004).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,float> contractId => total allocated price.
	 */
	private function allocatedByContract(string $administrationId): array {
		$byContract = [];
		foreach ($this->find(schema: 'PriceAllocation', administrationId: $administrationId) as $allocation) {
			$contractId = (string)($allocation['contractId'] ?? '');
			if ($contractId !== '') {
				$cents = $this->calculator->toCents(amount: ($allocation['allocatedAmount'] ?? 0));
				$byContract[$contractId] = (($byContract[$contractId] ?? 0) + $cents);
			}
		}

		return array_map(fn (int $cents): float => $this->calculator->fromCents(cents: $cents), $byContract);
	}//end allocatedByContract()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
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

	/**
	 * Every record of a schema in one administration.
	 *
	 * @param string $schema           Schema slug.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,mixed>
	 */
	private function find(string $schema, string $administrationId): array {
		return $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => ['administrationId' => $administrationId]]);
	}//end find()
}//end class
