<?php

/**
 * Shillinq Self-billed Invoice Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\EInvoice\SelfBilledInvoiceReview;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Rejects a received self-billed invoice (REQ-SEIX-007).
 *
 * Authenticated (#[NoAdminRequired]) and authorised per administration in
 * {@see SelfBilledInvoiceReview::reject()} through
 * AdministrationContextService::canAccess(), answering 404 for another
 * administration's invoice (ADR-005).
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 */
class SelfBilledInvoiceController extends Controller {
	/**
	 * The identifier pattern of the invoice number and administration id.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request     The request.
	 * @param SelfBilledInvoiceReview $review      The review rules.
	 * @param IUserSession            $userSession The user session.
	 * @param LoggerInterface         $logger      Logger; no stack traces reach the client.
	 */
	public function __construct(
		IRequest $request,
		private readonly SelfBilledInvoiceReview $review,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Reject a draft self-billed invoice with a reason.
	 *
	 * POST /api/ar-invoices/{invoiceNumber}/reject-self-billed?administrationId=...
	 * Body: { reason }
	 *
	 * @param string $invoiceNumber The invoice number (path parameter).
	 *
	 * @return JSONResponse 200 with the invoice; 400 for a refusal; 401 anonymous;
	 *                      404 for a missing or inaccessible invoice; 500 otherwise.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
	 */
	#[NoAdminRequired]
	public function reject(string $invoiceNumber): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if (preg_match(self::ID_PATTERN, $invoiceNumber) !== 1 || preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'Invalid invoice number or administration'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$invoice = $this->review->reject(
				administrationId: $administrationId,
				invoiceNumber: $invoiceNumber,
				reason: (string)$this->request->getParam('reason', '')
			);
		} catch (RuntimeException $e) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($e->getMessage() === 'ARInvoice not found') {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(['error' => $e->getMessage()], $status);
		} catch (Throwable $e) {
			$this->logger->error('SelfBilledInvoiceController: reject failed', ['invoiceNumber' => $invoiceNumber, 'exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Could not reject the invoice'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($invoice, Http::STATUS_OK);
	}//end reject()
}//end class
