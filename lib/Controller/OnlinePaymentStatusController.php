<?php

/**
 * Online Payment Status Controller
 *
 * Answers whether a payment link can be created on this instance: a live
 * payment adapter is bound. The payment request page reads it to disable Send
 * payment link with the reason when online payment is not set up
 * (receivables-payment-links D5, REQ-RPL-002).
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
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Whether online payment is set up.
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
 */
class OnlinePaymentStatusController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                        $appName        The app id.
	 * @param IRequest                      $request        The request.
	 * @param MolliePaymentAdapterInterface $paymentAdapter The bound payment adapter; dormant means not set up.
	 * @param IL10N                         $l10n           The caller's language.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MolliePaymentAdapterInterface $paymentAdapter,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether a payment link can be created, and why not when it cannot.
	 *
	 * Any signed-in user may ask: the answer names no object and no amount,
	 * only whether this instance has a live payment source. Sending a link
	 * stays gated on `payment.administer` in PaymentRequestActionController.
	 *
	 * @return JSONResponse `{live: bool, reason: string, message: string}`;
	 *                      `reason` is `not-set-up` and `message` the sentence
	 *                      to show when not live, both '' when live.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		if ($this->paymentAdapter->isDormant() === true) {
			return new JSONResponse(
				data: [
					'live'    => false,
					'reason'  => 'not-set-up',
					'message' => $this->l10n->t('Online payment is not set up: configure a payment source in integriq.'),
				]
			);
		}

		return new JSONResponse(data: ['live' => true, 'reason' => '', 'message' => '']);
	}//end status()
}//end class
