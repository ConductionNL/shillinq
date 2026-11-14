<?php

/**
 * Shillinq Invoice Sending Controller
 *
 * Send by email: one issued sales invoice to its customer, routed by the
 * customer's delivery method.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceSendingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The send mails a real customer, so the administration is checked before
 * anything is read (ADR-005): an invoice of another administration is 404.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */
class InvoiceSendingController extends Controller {

	/**
	 * The administration id shape.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param InvoiceSendingService        $sending The send.
	 * @param AdministrationContextService $context Whether the user may act on the administration.
	 * @param LoggerInterface              $logger  An unexpected failure, never shown in full.
	 */
	public function __construct(
		IRequest $request,
		private readonly InvoiceSendingService $sending,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send one invoice.
	 *
	 * POST /api/ar-invoices/{id}/send with `{administrationId, via?}`; `via`
	 * `email` mails the PDF whatever the customer's delivery method is.
	 *
	 * @param string $id               The ARInvoice id.
	 * @param string $administrationId The administration it belongs to.
	 * @param string $via              `''` for the customer's method, or `email`.
	 *
	 * @return JSONResponse 200 `{status, sending, error}` (status sent, recorded,
	 *                      fallback or failed); 400 for a bad request; 404 for
	 *                      an invoice the user may not see; 409 for an invoice
	 *                      that is not issued.
	 *
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-an-issued-invoice-is-sent-by-email-with-its-pdf-attached-req-sis-001
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-send-follows-the-customers-delivery-method-req-sis-002
	 */
	#[NoAdminRequired]
	public function send(string $id, string $administrationId = '', string $via = ''): JSONResponse {
		$administrationId = trim($administrationId);
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'ARInvoice not found'], Http::STATUS_NOT_FOUND);
		}

		if ($via !== '' && $via !== 'email') {
			return new JSONResponse(['error' => 'via is empty or email'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$outcome = $this->sending->send(administrationId: $administrationId, invoiceId: $id, via: $via);
		} catch (OutOfBoundsException $e) {
			return new JSONResponse(['error' => 'ARInvoice not found'], Http::STATUS_NOT_FOUND);
		} catch (DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (Throwable $e) {
			$this->logger->error('InvoiceSendingController: send failed', ['id' => $id, 'exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The invoice could not be sent.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($outcome);
	}//end send()
}//end class
