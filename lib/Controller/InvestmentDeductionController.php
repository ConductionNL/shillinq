<?php

/**
 * Shillinq Investment Deduction Controller
 *
 * Recalculates a year's investment deduction claims for an administration.
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
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The recalculate action of the investment deductions page.
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
 */
class InvestmentDeductionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request    The request.
	 * @param AdministrationContextService $context    Who may post in which administration.
	 * @param InvestmentDeductionService   $deductions The calculation.
	 * @param LoggerInterface              $logger     Where a failure is logged.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly InvestmentDeductionService $deductions,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Recalculate the year's claims of the given (or active) administration.
	 * Needs the right to post in it; anyone else gets a masked 404.
	 *
	 * @param int $year The year.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
	 */
	#[NoAdminRequired]
	public function calculate(int $year): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($year < 2000 || $year > 2100) {
			return new JSONResponse(['error' => 'Invalid year'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$administrationId = trim((string)$this->request->getParam('administrationId', ''));
			if ($administrationId === '') {
				$administrationId = (string)($this->context->buildContext()['activeAdministrationId'] ?? '');
			}

			if ($administrationId === '' || $this->context->canPostJournalEntry(administrationId: $administrationId) === false) {
				// Masked: never confirm that an administration exists.
				return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->deductions->calculateYear(administrationId: $administrationId, year: $year), Http::STATUS_OK);
		} catch (InvalidArgumentException $missing) {
			return new JSONResponse(['error' => $missing->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('InvestmentDeductionController: failed to calculate the year', ['year' => $year, 'exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to calculate the investment deductions'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end calculate()
}//end class
