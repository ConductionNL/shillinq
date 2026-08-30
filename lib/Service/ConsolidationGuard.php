<?php

/**
 * Consolidation Guard
 *
 * ADR-031 exception — single-method PHP seam for financial-statement lifecycle
 * preconditions that the declarative lifecycle engine cannot express until OR's
 * consolidation and publication extensions are stable. Remove when OR extension
 * lands. Referenced from BalanceSheet, TrialBalance, and ConsolidatedReport
 * x-openregister-lifecycle `requires:` clauses in
 * lib/Settings/shillinq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Guards financial-statement lifecycle transitions.
 *
 * Thin PHP seam per ADR-031 §"PHP guards remain a legitimate seam". Each
 * method is referenced by name from the schema lifecycle `requires:` clauses
 * in shillinq_register.json and returns true when the precondition holds
 * (transition permitted), or throws on hard failure (fail-closed).
 *
 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-9
 */
class ConsolidationGuard {
	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
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
	 * Guard for BalanceSheet and TrialBalance draft→final transition.
	 *
	 * Returns true when the FiscalYear referenced by `fiscalYearId` is closed
	 * (isClosed === true). If the FiscalYear schema is not available (pre-T2),
	 * permits the transition with a debug log (T1 deferral pattern mirrors
	 * AccountBalanceGuard::requireZeroBalance).
	 *
	 * Fail-closed on unexpected exceptions: returns false and logs an error so
	 * no transition fires silently during an infrastructure outage (CWE-285).
	 *
	 * @param array<string, mixed> $statement BalanceSheet or TrialBalance object array.
	 *
	 * @return bool True when fiscal period is closed and transition is permitted.
	 *
	 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-9
	 */
	public function requireFiscalPeriodClosed(array $statement): bool {
		$fiscalYearId = ($statement['fiscalYearId'] ?? null);
		if ($fiscalYearId === null) {
			$this->logger->warning(
				'ConsolidationGuard: fiscalYearId missing — denying finalise (fail-closed)',
				['statement' => ($statement['id'] ?? 'unknown')]
			);
			return false;
		}

		try {
			// ADR-022: the real ObjectService API is find()/findAll().
			// findObject() does not exist — it raised an Error that the
			// \Throwable arm below swallowed into `return false`, so this
			// guard denied EVERY finalise without ever reading a FiscalYear.
			$fiscalYear = $this->objectService->find(
				id: $fiscalYearId,
				register: $this->getRegisterSlug(),
				schema: 'FiscalYear'
			);

			if ($fiscalYear === null) {
				$this->logger->debug(
					'ConsolidationGuard: FiscalYear schema not present (T1/T2 state) — finalise permitted by default',
					['fiscalYearId' => $fiscalYearId]
				);
				return true;
			}

			return (($fiscalYear->jsonSerialize()['isClosed'] ?? false) === true);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: fiscal period check failed — denying finalise (fail-closed)',
				['exception' => $e->getMessage(), 'fiscalYearId' => $fiscalYearId]
			);
			return false;
		}//end try

	}//end requireFiscalPeriodClosed()

	/**
	 * Guard for ConsolidatedReport draft→final transition.
	 *
	 * Returns true when all member administrations in the ConsolidationGroup
	 * have a BalanceSheet with status `final` for the same fiscalYearId. If
	 * the ConsolidationGroup or BalanceSheet schema is not available, permits
	 * the transition with a debug log (T1/T2 deferral).
	 *
	 * Fail-closed on unexpected exceptions.
	 *
	 * @param array<string, mixed> $consolidatedReport ConsolidatedReport object array.
	 *
	 * @return bool True when all member statements are final.
	 *
	 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-12
	 */
	public function requireAllMembersFinalised(array $consolidatedReport): bool {
		$consolidationGroupId = ($consolidatedReport['consolidationGroupId'] ?? null);
		$fiscalYearId = ($consolidatedReport['fiscalYearId'] ?? null);

		if ($consolidationGroupId === null || $fiscalYearId === null) {
			$this->logger->warning(
				'ConsolidationGuard: consolidationGroupId or fiscalYearId missing — denying finalise (fail-closed)',
				['report' => ($consolidatedReport['reportNumber'] ?? 'unknown')]
			);
			return false;
		}

		try {
			// ADR-022: find(), not findObject() — see requireFiscalPeriodClosed().
			$consolidationGroup = $this->objectService->find(
				id: $consolidationGroupId,
				register: $this->getRegisterSlug(),
				schema: 'ConsolidationGroup'
			);

			if ($consolidationGroup === null) {
				$this->logger->debug(
					'ConsolidationGuard: ConsolidationGroup not found — finalise permitted by default',
					['consolidationGroupId' => $consolidationGroupId]
				);
				return true;
			}

			$administrationIds = ($consolidationGroup->jsonSerialize()['administrationIds'] ?? []);
			if (count($administrationIds) === 0) {
				return true;
			}

			foreach ($administrationIds as $administrationId) {
				$balanceSheets = $this->objectService
					->setRegister($this->getRegisterSlug())
					->setSchema('BalanceSheet')
					->findAll(
						[
							'administrationId' => $administrationId,
							'fiscalYearId' => $fiscalYearId,
							'status' => 'final',
							'_limit' => 1,
						]
					);

				if (count($balanceSheets) === 0) {
					$this->logger->warning(
						'ConsolidationGuard: member administration has no final BalanceSheet — denying consolidation',
						['administrationId' => $administrationId, 'fiscalYearId' => $fiscalYearId]
					);
					return false;
				}
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: member finalisation check failed — denying finalise (fail-closed)',
				['exception' => $e->getMessage(), 'consolidationGroupId' => $consolidationGroupId]
			);
			return false;
		}//end try

	}//end requireAllMembersFinalised()

	/**
	 * Guard for final→published transition on BalanceSheet, TrialBalance, ConsolidatedReport.
	 *
	 * Returns true unconditionally (publication approval is a role-based check
	 * handled by the RBAC layer — `controller` role only). This method is the
	 * OR lifecycle hook point; actual role enforcement is via x-openregister-rbac.
	 *
	 * Retained as an explicit seam so the implementing cycle can add operator
	 * sign-off or digital-signature preconditions without schema changes.
	 *
	 * @param array<string, mixed> $statement Financial statement object array.
	 *
	 * @return bool Always true — role check delegated to OR RBAC layer.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-13
	 */
	public function requirePublicationApproval(array $statement): bool {
		// Role-based enforcement (controller only) is declared in x-openregister-rbac.
		// This seam is retained for future operator sign-off or digital-signature
		// preconditions per REQ-FS-007 without requiring schema changes.
		return true;
	}//end requirePublicationApproval()
}//end class
