<?php

/**
 * VAT Fiscal Year Correction Controller
 *
 * "Check fiscal year" for an administration whose fiscal year is not the
 * calendar year (REQ-TVRB-002):
 *
 *   POST /api/fiscal-years/{fiscalYearId}/vat-corrections/check
 *
 * Authenticated with #[NoAdminRequired] and authorised on the fiscal year's
 * own administration through AdministrationContextService::canAccess(): a
 * fiscal year of an administration the caller is not a member of answers
 * 404, the same as one that does not exist, before any return is read.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Vat\VatFiscalYearCorrectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Check the VAT returns of a broken fiscal year for corrections.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
 */
class VatFiscalYearCorrectionController extends Controller {

	/**
	 * The shape of an object id.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request     The request.
	 * @param VatFiscalYearCorrectionService $corrections The fiscal year check.
	 * @param IUserSession                   $session     The user session.
	 * @param AdministrationContextService   $context     The caller's administrations.
	 * @param LoggerInterface                $logger      The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly VatFiscalYearCorrectionService $corrections,
		private readonly IUserSession $session,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check every accepted return of the fiscal year and total the corrections.
	 *
	 * @param string $fiscalYearId The fiscal year.
	 *
	 * @return JSONResponse 200 with `data`: the fiscal year, per return whether it drifted and its
	 *                      correction, the total and whether it exceeds the threshold; 400 for a
	 *                      malformed id; 401 without a user; 404 for a fiscal year that does not exist
	 *                      or the caller may not see; 409 for a calendar fiscal year.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function check(string $fiscalYearId): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $fiscalYearId) !== 1) {
			return new JSONResponse(['error' => 'fiscalYearId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$fiscalYear = $this->corrections->findFiscalYear(fiscalYearId: $fiscalYearId);
			if ($fiscalYear === null
				|| $this->context->canAccess(administrationId: (string)($fiscalYear['administrationId'] ?? '')) === false
			) {
				return new JSONResponse(['error' => 'Fiscal year not found'], Http::STATUS_NOT_FOUND);
			}

			$result = $this->corrections->check(fiscalYear: $fiscalYear);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VatFiscalYearCorrectionController: the fiscal year check failed',
				['fiscalYearId' => $fiscalYearId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to check the fiscal year for corrections'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(['data' => $result], Http::STATUS_OK);
	}//end check()
}//end class
