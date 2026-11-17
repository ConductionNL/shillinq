<?php

/**
 * Point Of Sale Payment Controller
 *
 * Take payment now on an open invoice (REQ-RPPO-005): open to any signed-in
 * user at the route and refused inside PointOfSalePaymentService on the
 * caller's action, administration and invoice before anything is written.
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\Payout\PointOfSalePaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Takes an open invoice's payment on the caller's registered phone, or as a QR checkout.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 */
class PointOfSalePaymentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName  The app id.
	 * @param IRequest                  $request  The request.
	 * @param PointOfSalePaymentService $payments Takes the payment and refuses what may not be taken.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PointOfSalePaymentService $payments,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Take payment now on an open invoice.
	 *
	 * @param string $id The invoice id.
	 *
	 * @return JSONResponse The mode (device or qr), the pending payment request and the checkout URL, or the refusal.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
	 */
	#[NoAdminRequired]
	public function takePayment(string $id): JSONResponse {
		try {
			$payment = $this->payments->takePayment(invoiceId: $id);
		} catch (FinancialDocumentActionRefusedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getStatus());
		}

		return new JSONResponse(
			data: ['mode' => $payment['mode'], 'request' => $payment['request'], 'checkoutUrl' => $payment['checkoutUrl']],
			statusCode: Http::STATUS_CREATED
		);
	}//end takePayment()
}//end class
