<?php

/**
 * Shillinq Known Cost Controller
 *
 * The operator-triggered regeneration of known-cost BudgetLines for an
 * administration (budget-known-costs design.md §12: "this change ships an
 * operator-triggered run only").
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/specs/budget-known-costs/spec.md#requirement-req-bkc-004-budgetlinederivation-must-make-regeneration-idempotent
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\KnownCostBudgetWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs KnownCostBudgetWriter for one administration on an operator's request.
 *
 * The run writes BudgetLine and BudgetLineDerivation rows, so the caller must
 * be allowed to change that administration's books; anyone else is told the
 * administration does not exist.
 *
 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/design.md
 */
class KnownCostController extends Controller {

	/**
	 * Construct the controller.
	 *
	 * @param IRequest                              $request The request.
	 * @param AdministrationContextServiceInterface $context Who may act on which administration.
	 * @param KnownCostBudgetWriter                 $writer  The regeneration run.
	 * @param LoggerInterface                       $logger  The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextServiceInterface $context,
		private readonly KnownCostBudgetWriter $writer,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Regenerate the known-cost BudgetLines of an administration.
	 *
	 * The administration is the `administrationId` parameter, else the
	 * caller's active one. Returns the run summary: created, updated and
	 * overridden counts and the fiscal years skipped for want of a default
	 * AnnualBudget.
	 *
	 * @return JSONResponse 200 with the summary; 401 without a user; 404 for an
	 *                      administration the caller may not change; 500 when the run failed.
	 *
	 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/specs/budget-known-costs/spec.md#requirement-req-bkc-004-budgetlinederivation-must-make-regeneration-idempotent
	 */
	#[NoAdminRequired]
	public function regenerate(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '') {
			$administrationId = (string)($this->context->buildContext()['activeAdministrationId'] ?? '');
		}

		if ($administrationId === '' || $this->context->canPostJournalEntry(administrationId: $administrationId) === false) {
			// Masked: never confirm that an administration exists.
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse($this->writer->run(administrationId: $administrationId), Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error('KnownCostController: the known-cost regeneration failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to regenerate the known-cost budget lines'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end regenerate()
}//end class
