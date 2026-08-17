<?php

/**
 * Expense Claim Guard
 *
 * ADR-031 exception-path lifecycle guard for ExpenseClaimEntry transitions.
 * Validates that all line items have cost centres set and that at least one
 * expense item is attached before submission or posting. Referenced from
 * shillinq_register.json ExpenseClaimEntry lifecycle transitions.
 *
 * ADR-031 exception reason: cross-schema membership checks (all child receipts
 * have costCentreCode set, claim has at least one child) are not yet expressible
 * in the declarative lifecycle DSL. Replace with declarative conditions when the
 * engine supports cross-schema existence and completeness checks.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for ExpenseClaimEntry submit and post transitions.
 *
 * Referenced from shillinq_register.json ExpenseClaimEntry lifecycle:
 * - transitions.submit.requires → requireCostCentresAndItems
 * - transitions.post.requires   → requireOpenPeriodAndCostCentres
 *
 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
 */
class ExpenseClaimGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Precondition for the submit transition.
	 *
	 * Validates:
	 * 1. The claim has at least one linked expense item (receipt, mileage, or per-diem).
	 * 2. All linked items have costCentreCode set.
	 *
	 * Fail-closed: returns false on any exception (denies transition) per REQ-EC-007 / CWE-863.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array loaded by OR.
	 *
	 * @return bool True when the claim may be submitted.
	 *
	 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
	 */
	public function requireCostCentresAndItems(array $claim): bool {
		try {
			$receiptIds = (array)($claim['receiptIds'] ?? []);
			$mileageIds = (array)($claim['mileageIds'] ?? []);
			$perDiemIds = (array)($claim['perDiemIds'] ?? []);
			$allItemIds = array_merge($receiptIds, $mileageIds, $perDiemIds);

			if (count($allItemIds) === 0) {
				$this->logger->info(
					'ExpenseClaimGuard: claim has no line items — denying submit',
					['claimId' => ($claim['id'] ?? 'unknown')]
				);
				return false;
			}

			return $this->allItemsHaveCostCentres(
				claimId: (string)($claim['id'] ?? ''),
				receiptIds: $receiptIds,
				mileageIds: $mileageIds,
				perDiemIds: $perDiemIds,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseClaimGuard: requireCostCentresAndItems failed — denying submit (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireCostCentresAndItems()

	/**
	 * Precondition for the post transition.
	 *
	 * Validates:
	 * 1. All linked items have costCentreCode set.
	 * 2. The FiscalYear covering fromDate is in state 'open' per REQ-PC-004.
	 *    If no FiscalYear record exists (T1 state before fiscal-year register is
	 *    seeded), the guard permits posting with a debug log.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array loaded by OR.
	 *
	 * @return bool True when the claim may be posted.
	 *
	 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
	 */
	public function requireOpenPeriodAndCostCentres(array $claim): bool {
		try {
			$receiptIds = (array)($claim['receiptIds'] ?? []);
			$mileageIds = (array)($claim['mileageIds'] ?? []);
			$perDiemIds = (array)($claim['perDiemIds'] ?? []);

			if ($this->allItemsHaveCostCentres(
				claimId: (string)($claim['id'] ?? ''),
				receiptIds: $receiptIds,
				mileageIds: $mileageIds,
				perDiemIds: $perDiemIds,
			) === false
			) {
				return false;
			}

			return $this->isFiscalPeriodOpen(claim: $claim);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExpenseClaimGuard: requireOpenPeriodAndCostCentres failed — denying post (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireOpenPeriodAndCostCentres()

	/**
	 * Verify that all linked expense items have a non-empty costCentreCode.
	 *
	 * Iterates Receipt, MileageEntry, and PerDiem records by ID and verifies
	 * that each has a non-null, non-empty costCentreCode field. Returns false
	 * on the first item missing a cost centre.
	 *
	 * @param string $claimId Claim ID for log context.
	 * @param array<string> $receiptIds Receipt record IDs.
	 * @param array<string> $mileageIds MileageEntry record IDs.
	 * @param array<string> $perDiemIds PerDiem record IDs.
	 *
	 * @return bool True when all items have costCentreCode.
	 *
	 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
	 */
	private function allItemsHaveCostCentres(
		string $claimId,
		array $receiptIds,
		array $mileageIds,
		array $perDiemIds,
	): bool {
		$register = $this->getRegisterSlug();

		$checks = [
			['schema' => 'Receipt', 'ids' => $receiptIds],
			['schema' => 'MileageEntry', 'ids' => $mileageIds],
			['schema' => 'PerDiem', 'ids' => $perDiemIds],
		];

		foreach ($checks as $check) {
			foreach ($check['ids'] as $itemId) {
				$item = $this->objectService
					->setRegister(register: $register)
					->setSchema(schema: $check['schema'])
					->find(id: $itemId);

				// ADR-084: find() returns an ObjectEntityInterface, which extends
				// JsonSerializable ONLY and does not implement ArrayAccess. The
				// previous `$item['costCentreCode']` raised
				// `Error: Cannot use object of type … as array` -- which `??`
				// cannot suppress -- and requireOpenPeriodAndCostCentres()'s
				// catch (\Throwable) turned that into `return false`, so the
				// guard DENIED EVERY claim carrying a linked item. getObject()
				// is declared on the contract and returns the body as an array.
				$costCentreCode = '';
				if ($item !== null) {
					$costCentreCode = (string)($item->getObject()['costCentreCode'] ?? '');
				}

				if ($item === null || trim(string: $costCentreCode) === '') {
					$this->logger->info(
						'ExpenseClaimGuard: item missing costCentreCode — denying transition',
						['claimId' => $claimId, 'schema' => $check['schema'], 'itemId' => $itemId]
					);
					return false;
				}
			}
		}

		return true;
	}//end allItemsHaveCostCentres()

	/**
	 * Check that the FiscalYear covering the claim's fromDate is in state 'open'.
	 *
	 * Returns true (permit posting) when:
	 * - The FiscalYear register does not exist yet (T1 state before fiscal-year seeding).
	 * - A FiscalYear record covering fromDate exists and its state is 'open'.
	 *
	 * Returns false when a FiscalYear record covering fromDate is found but is NOT 'open'.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 *
	 * @return bool True when the fiscal period is open (or not yet seeded).
	 *
	 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
	 */
	private function isFiscalPeriodOpen(array $claim): bool {
		$fromDate = (string)($claim['fromDate'] ?? '');
		$adminId = (string)($claim['administrationId'] ?? '');

		try {
			$register = $this->getRegisterSlug();

			$years = $this->objectService
				->setRegister(register: $register)
				->setSchema(schema: 'FiscalYear')
				->findAll(
					[
						'filters' => [
							'administrationId' => $adminId,
							'startDate' => ['lte' => $fromDate],
							'endDate' => ['gte' => $fromDate],
						],
					]
				);
		} catch (\Throwable) {
			// FiscalYear register not yet available (T1 state) — permit posting.
			$this->logger->debug(
				'ExpenseClaimGuard: FiscalYear register not present (T1 state) — posting permitted',
				['claimId' => ($claim['id'] ?? 'unknown')]
			);
			return true;
		}//end try

		if (count($years) === 0) {
			// No FiscalYear covering fromDate — permit posting with a warning.
			$this->logger->warning(
				'ExpenseClaimGuard: no FiscalYear covers claim fromDate — permitting post without period check',
				['claimId' => ($claim['id'] ?? 'unknown'), 'fromDate' => $fromDate]
			);
			return true;
		}

		$year = reset(array: $years);
		return ($year['state'] ?? '') === 'open';
	}//end isFiscalPeriodOpen()
}//end class
