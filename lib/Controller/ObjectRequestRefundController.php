<?php

/**
 * Object Request Refund Controller
 *
 * The two finance actions on a refund another app asked for: approve it and
 * mark it paid. Both are #[NoAdminRequired] and gated inside on the
 * `payment.administer` action, as the other payment request actions are:
 * seeing a request is not the same right as moving its money.
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
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Service\ObjectRequestRefundService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Approves and marks paid a requested refund.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
 */
class ObjectRequestRefundController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                     $appName    The app id.
	 * @param IRequest                   $request    The request.
	 * @param PaymentActionAuthorizer    $authorizer The payment action matrix.
	 * @param ObjectRequestRefundService $refunds    Books and records the refund steps.
	 * @param LoggerInterface            $logger     Logs a step that failed for another reason.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly ObjectRequestRefundService $refunds,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Approve the refund: the income is reversed into refunds payable.
	 *
	 * @param string $id The payment request id.
	 *
	 * @return JSONResponse The request, or the refusal.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_ADMINISTER) === false) {
			return new JSONResponse(['error' => 'Approving a refund needs the payment.administer action.'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(step: fn (): array => $this->refunds->approve(paymentRequestId: $id));
	}//end approve()

	/**
	 * Mark the refund paid by bank, with the bank reference.
	 *
	 * @param string $id            The payment request id.
	 * @param string $bankReference The reference of the bank payment.
	 * @param string $bankAccount   The ledger account of the bank it was paid from.
	 *
	 * @return JSONResponse The request, or the refusal.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	#[NoAdminRequired]
	public function markPaid(string $id, string $bankReference = '', string $bankAccount = ''): JSONResponse {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_ADMINISTER) === false) {
			return new JSONResponse(['error' => 'Marking a refund paid needs the payment.administer action.'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(
			step: fn (): array => $this->refunds->markPaid(paymentRequestId: $id, bankReference: $bankReference, bankAccount: $bankAccount)
		);
	}//end markPaid()

	/**
	 * Run one refund step and answer it.
	 *
	 * @param callable(): array<string, mixed> $step The step.
	 *
	 * @return JSONResponse The request, a 400 with the reason, or a 500.
	 */
	private function answer(callable $step): JSONResponse {
		try {
			$request = $step();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: a refund step could not be carried out', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The refund step could not be carried out.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['state' => (string)($request['state'] ?? ''), 'refunds' => ($request['refunds'] ?? [])]);
	}//end answer()
}//end class
