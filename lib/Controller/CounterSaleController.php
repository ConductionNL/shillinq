<?php

/**
 * Counter Sale Controller
 *
 * A counter sale (REQ-RPPO-006): open to any signed-in user at the route and
 * refused inside CounterSaleService on the caller's payment action and
 * administration before anything is written.
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
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\Payout\CounterSaleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * Records a counter sale and takes its payment; cancels one the customer walked away from.
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 */
class CounterSaleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string             $appName The app name.
	 * @param IRequest           $request The request.
	 * @param CounterSaleService $sales   Records and cancels counter sales.
	 * @param ITimeFactory       $time    The server clock, for the invoice date.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CounterSaleService $sales,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Record a counter sale and start its payment.
	 *
	 * @param string      $administrationId The administration that sells.
	 * @param mixed       $lines            The lines, `{description, quantity?, unitPrice, vatRate}`.
	 * @param string|null $customerId       A CustomerMaster, or null for the counter-sales debtor.
	 *
	 * @return JSONResponse 201 `{invoice, mode, request, checkoutUrl}`, or the refusal's status with `{error}`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function create(string $administrationId = '', mixed $lines = null, ?string $customerId = null): JSONResponse {
		if (is_array($lines) === false) {
			return new JSONResponse(data: ['error' => 'A counter sale needs its lines.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$sale = $this->sales->start(
				administrationId: $administrationId,
				lines: array_values($lines),
				customerId: $customerId,
				today: $this->time->getDateTime()->format('Y-m-d')
			);
		} catch (FinancialDocumentActionRefusedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getStatus());
		}

		return new JSONResponse(
			data: [
				'invoice' => $sale['invoice'],
				'mode' => $sale['mode'],
				'request' => $sale['request'],
				'checkoutUrl' => $sale['checkoutUrl'],
			],
			statusCode: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Cancel a counter sale the customer walked away from.
	 *
	 * @param string $id The draft invoice of the sale.
	 *
	 * @return JSONResponse 200 `{invoice}`, or the refusal's status with `{error}`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function cancel(string $id): JSONResponse {
		try {
			$invoice = $this->sales->abandon(invoiceId: $id);
		} catch (FinancialDocumentActionRefusedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getStatus());
		}

		return new JSONResponse(data: ['invoice' => $invoice]);
	}//end cancel()
}//end class
