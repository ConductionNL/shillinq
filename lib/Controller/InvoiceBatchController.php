<?php

/**
 * Shillinq Invoice Batch Controller
 *
 * Create a batch: one sales invoice per selected customer from one set of
 * lines, optionally issued and sent (REQ-SIS-004).
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
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceBatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The batch writes invoices in the administration, so the administration is
 * checked before anything is read (ADR-005): another administration is 404.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
class InvoiceBatchController extends Controller {

	/**
	 * The administration id shape.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param InvoiceBatchService          $batches Writes the invoices.
	 * @param AdministrationContextService $context Whether the user may act on the administration.
	 * @param IUserSession                 $session Who asked, to tell them when a send is done.
	 * @param LoggerInterface              $logger  An unexpected failure, never shown in full.
	 */
	public function __construct(
		IRequest $request,
		private readonly InvoiceBatchService $batches,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create one invoice per customer.
	 *
	 * POST /api/ar-invoices/batch with `{administrationId, batchId?,
	 * customerIds, charge: {invoiceDate, dueDate?, lines}, issue?, send?}`.
	 * Submitting the same `batchId` again writes only the invoices that are
	 * still missing.
	 *
	 * @param string               $administrationId The administration that bills.
	 * @param string               $batchId          The batch reference, '' for a new one.
	 * @param array<int, mixed>    $customerIds      The selected CustomerMaster ids, 1 to 200.
	 * @param array<string, mixed> $charge           The invoice date, optional due date and the lines.
	 * @param bool                 $issue            Whether the invoices are issued.
	 * @param bool                 $send             Whether the issued invoices are sent.
	 *
	 * @return JSONResponse 201 with the InvoiceBatch and its outcomes; 400 for
	 *                      a bad request; 404 for an administration the user may not see.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-batch-creates-one-invoice-per-customer-req-sis-004
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `issue` and `send` are fields of the request body, bound by the framework.
	 */
	#[NoAdminRequired]
	public function create(
		string $administrationId = '',
		string $batchId = '',
		array $customerIds = [],
		array $charge = [],
		bool $issue = false,
		bool $send = false,
	): JSONResponse {
		$administrationId = trim($administrationId);
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$batch = $this->batches->create(
				administrationId: $administrationId,
				request: ['batchId' => $batchId, 'customerIds' => $customerIds, 'charge' => $charge, 'issue' => $issue, 'send' => $send],
				requestedBy: (string)$this->session->getUser()?->getUID()
			);
		} catch (OutOfBoundsException $e) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('InvoiceBatchController: batch not created', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The batch could not be created.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($batch, Http::STATUS_CREATED);
	}//end create()
}//end class
