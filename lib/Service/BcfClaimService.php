<?php

/**
 * BCF Claim Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tier-3 Btw-compensatiefonds (BCF) compensable-VAT computation (REQ-BCF-002,
 * REQ-BCF-012). Materialises the per-account compensable-VAT breakdown and
 * quarter total for a BcfClaim from existing GLLine + GLTransaction +
 * BbvAccountMapping data using the real OpenRegister ObjectService API
 * (findAll) — the breakdown rows are not authored by operators; they are
 * computed on demand (design.md D5).
 *
 * Per ADR-031 the equivalent declarative aggregation shape is documented on the
 * BcfClaim schema (x-openregister-aggregations.compensableVatBreakdown); this
 * service is the engine-side fallback for the GLLine -> BbvAccountMapping join +
 * compensablePercentage weighting, which the declarative aggregation engine
 * cannot yet express (REQ-BCF-002). When OR lands stable cross-schema weighted
 * aggregation, the declarative block becomes primary and this service is removed.
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
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\BcfClaimServiceInterface;
use OCP\IAppConfig;

/**
 * Computes a quarter-scoped, per-account BCF compensable-VAT claim from the GL.
 *
 * Reads are scoped to a single administration + claim quarter (REQ-BCF-010,
 * REQ-BCF-012): callers pass the administrationId resolved from the authenticated
 * user's context, never a client-supplied trust boundary. Compensable VAT comes
 * from GLLine rows whose parent GLTransaction belongs to the administration and
 * the claim quarter, restricted to accounts the administration's BbvAccountMapping
 * marks bcfCompensable and weighted by compensablePercentage (REQ-BCF-002).
 *
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 */
class BcfClaimService implements BcfClaimServiceInterface {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param BcfCompensationCalculator $calculator Pure-logic compensable-VAT helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly BcfCompensationCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the compensable-VAT claim for one administration + quarter (REQ-BCF-002).
	 *
	 * Returns the quarter total plus a per-account breakdown (accountNumber,
	 * posted amount, compensablePercentage, weighted compensable amount). Only
	 * compensable accounts contribute; non-compensable accounts and accounts
	 * without a mapping are excluded.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-BCF-010).
	 * @param string $claimQuarter Quarter identifier to claim (e.g. 2026-Q1, REQ-BCF-001).
	 *
	 * @return array{administrationId: string, claimQuarter: string, totalCompensableAmount: float, breakdown: array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
	 */
	public function computeClaim(string $administrationId, string $claimQuarter): array {
		$amountsByAccount = $this->compensableAmountsByAccount(
			administrationId: $administrationId,
			claimQuarter: $claimQuarter
		);

		$mappingsByAccount = $this->mappingsByAccount(administrationId: $administrationId);

		$compensation = $this->calculator->computeCompensation(
			amountsByAccount: $amountsByAccount,
			mappingsByAccount: $mappingsByAccount
		);

		return [
			'administrationId' => $administrationId,
			'claimQuarter' => $claimQuarter,
			'totalCompensableAmount' => $compensation['totalCompensableAmount'],
			'breakdown' => $compensation['breakdown'],
		];

	}//end computeClaim()

	/**
	 * Sum VAT amounts per account for an administration + claim quarter.
	 *
	 * Resolves the administration's GLTransactions for the quarter, then sums the
	 * amounts of their non-eliminated GLLine children grouped by accountNumber.
	 * BCF compensates VAT charged on qualifying expenditure; debit-side VAT lines
	 * are the claimable postings, so credit lines and eliminations are excluded.
	 *
	 * Amounts are summed in integer cents to avoid float drift, then returned as
	 * currency-unit floats (the contract BcfCompensationCalculator expects).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $claimQuarter Claim quarter (maps to GLLine.periodId).
	 *
	 * @return array<string,float> accountNumber => posted amount in currency units.
	 */
	private function compensableAmountsByAccount(string $administrationId, string $claimQuarter): array {
		$scope = new BcfClaimScope(
			register: $this->register(),
			administrationId: $administrationId,
			claimQuarter: $claimQuarter
		);

		// No transactions for this administration+quarter → no lines can be in scope.
		// Guard prevents the GLLine query from matching lines from other administrations
		// when the transactionIds set is empty (CWE-284 / REQ-BCF-012).
		$transactionIds = $this->quarterTransactionIds(scope: $scope);
		if ($transactionIds === []) {
			return [];
		}

		// Return currency-unit floats — the calculator re-converts to cents and
		// applies the compensablePercentage weighting (REQ-BCF-002).
		$byAccount = [];
		foreach ($this->debitCentsByAccount(scope: $scope, transactionIds: $transactionIds) as $account => $cents) {
			$byAccount[$account] = $this->calculator->fromCents(cents: $cents);
		}

		return $byAccount;
	}//end compensableAmountsByAccount()

	/**
	 * The ids of the administration's GLTransactions in the quarter (REQ-BCF-012 scoping).
	 *
	 * @param BcfClaimScope $scope The claim scope.
	 *
	 * @return array<string,true> The transaction id set.
	 */
	private function quarterTransactionIds(BcfClaimScope $scope): array {
		$transactions = $this->objectService
			->setRegister($scope->register)
			->setSchema('GLTransaction')
			->findAll(
				['filters' => ['administrationId' => $scope->administrationId, 'periodId' => $scope->claimQuarter]]
			);

		$transactionIds = [];
		foreach ($transactions as $transaction) {
			$id = ($transaction['id'] ?? ($transaction['@self']['id'] ?? null));
			if ($id !== null) {
				$transactionIds[(string)$id] = true;
			}
		}

		return $transactionIds;

	}//end quarterTransactionIds()

	/**
	 * Sum the quarter's in-scope debit GLLines per account, in cents.
	 *
	 * GLLines are read for the quarter, then each is cross-checked against the
	 * in-scope parent transactions.
	 *
	 * @param BcfClaimScope $scope The claim scope.
	 * @param array<string,true> $transactionIds The in-scope transaction ids.
	 *
	 * @return array<string,int> accountNumber => posted cents.
	 */
	private function debitCentsByAccount(BcfClaimScope $scope, array $transactionIds): array {
		$lines = $this->objectService
			->setRegister($scope->register)
			->setSchema('GLLine')
			->findAll(['filters' => ['periodId' => $scope->claimQuarter]]);

		$centsByAccount = [];
		foreach ($lines as $line) {
			if ($this->lineInScope(line: $line, transactionIds: $transactionIds) === true) {
				$account = (string)($line['accountNumber'] ?? '');
				$centsByAccount[$account] = ($centsByAccount[$account] ?? 0) + $this->calculator->toCents(amount: ($line['amount'] ?? 0));
			}
		}

		return $centsByAccount;

	}//end debitCentsByAccount()

	/**
	 * Decide whether a GLLine counts toward the BCF compensable claim.
	 *
	 * Excludes eliminated lines, lines whose parent transaction is out of the
	 * administration/quarter scope (REQ-BCF-012), credit-side lines (BCF claims
	 * the VAT charged on expenditure, the debit side), and lines without an
	 * account.
	 *
	 * @param array<string,mixed> $line The GLLine record.
	 * @param array<string,bool> $transactionIds In-scope transaction ids (set membership).
	 *
	 * @return bool True when the line should be summed.
	 */
	private function lineInScope(array $line, array $transactionIds): bool {
		if (($line['eliminationFlag'] ?? false) === true) {
			return false;
		}

		if (($line['side'] ?? '') !== 'debit') {
			return false;
		}

		$transactionId = (string)($line['transactionId'] ?? '');
		if ($transactionIds !== [] && isset($transactionIds[$transactionId]) === false) {
			return false;
		}

		return ((string)($line['accountNumber'] ?? '') !== '');
	}//end lineInScope()

	/**
	 * Fetch the administration's BBV account mappings keyed by accountNumber (REQ-BCF-004).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> accountNumber => BbvAccountMapping object.
	 */
	private function mappingsByAccount(string $administrationId): array {
		$mappings = $this->objectService
			->setRegister($this->register())
			->setSchema('BbvAccountMapping')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byNumber = [];
		foreach ($mappings as $mapping) {
			$number = (string)($mapping['accountNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $mapping;
			}
		}

		return $byNumber;
	}//end mappingsByAccount()

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
}//end class
