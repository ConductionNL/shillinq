<?php

/**
 * VAT Return Checks Controller
 *
 * The checks tab of a VAT return (REQ-TVRB-001):
 *
 *   GET /api/vat-returns/{returnId}/checks — run the checks on the return
 *
 * Authenticated with #[NoAdminRequired] and authorised on the return's own
 * administration through AdministrationContextService::canAccess(): a return
 * of an administration the caller is not a member of answers 404, the same
 * as one that does not exist, before the books are read.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Standards\Checks\VatReturnChecks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Run the checks of a VAT return for its checks tab.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
 */
class VatReturnChecksController extends Controller {

	/**
	 * The shape of an object id.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request    The request.
	 * @param VatReturnChecks              $checks     The checks.
	 * @param VATReturnService             $vatReturns The VAT return service.
	 * @param IUserSession                 $session    The user session.
	 * @param AdministrationContextService $context    The caller's administrations.
	 * @param IL10N                        $l10n       Labels in the reader's language.
	 * @param LoggerInterface              $logger     The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly VatReturnChecks $checks,
		private readonly VATReturnService $vatReturns,
		private readonly IUserSession $session,
		private readonly AdministrationContextService $context,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Run the checks on a return.
	 *
	 * @param string $returnId The return.
	 *
	 * @return JSONResponse 200 with `data`: per check its id, title, severity, passed, status and
	 *                      message, and `blocked`: whether a blocking check fails; 400 for a malformed
	 *                      id; 401 without a user; 404 for a return that does not exist or the caller
	 *                      may not see; 500 when the return cannot be read.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
	 */
	#[NoAdminRequired]
	public function index(string $returnId): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $returnId) !== 1) {
			return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$vatReturn = $this->vatReturns->findReturn(returnId: $returnId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VatReturnChecksController: the return could not be read',
				['returnId' => $returnId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to load VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($vatReturn === null
			|| $this->context->canAccess(administrationId: (string)($vatReturn['administrationId'] ?? '')) === false
		) {
			return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
		}

		$rows    = [];
		$blocked = false;
		foreach ($this->checks->run(return: $vatReturn) as $result) {
			$blocking = ($result['severity'] === VatReturnChecks::BLOCKING);
			if ($blocking === true && $result['passed'] === false) {
				$blocked = true;
			}

			$severityLabel = $this->l10n->t('Warning');
			if ($blocking === true) {
				$severityLabel = $this->l10n->t('Blocks filing');
			}

			$status = $this->l10n->t('Failed');
			if ($result['passed'] === true) {
				$status = $this->l10n->t('Passed');
			}

			$rows[] = [
				'id' => $result['id'],
				'title' => $this->checks->title(id: $result['id']),
				'severity' => $result['severity'],
				'severityLabel' => $severityLabel,
				'passed' => $result['passed'],
				'status' => $status,
				'message' => $result['message'],
			];
		}

		return new JSONResponse(['data' => $rows, 'blocked' => $blocked], Http::STATUS_OK);
	}//end index()
}//end class
