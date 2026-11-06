<?php

/**
 * Inbound Payable Controller
 *
 * Finance's half of a payable another app raised: approve, mark paid, reject.
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
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Service\InboundPayableService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Approves, pays and rejects a payable another app raised.
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
 */
class InboundPayableController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName    The app id.
	 * @param IRequest                $request    The request.
	 * @param PaymentActionAuthorizer $authorizer The payment action matrix.
	 * @param InboundPayableService   $payables   Books and records the steps.
	 * @param LoggerInterface         $logger     Logs a step that failed for another reason.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly InboundPayableService $payables,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Approve the payable: the expense is booked against the payables account.
	 *
	 * @param string $id The payable id.
	 *
	 * @return JSONResponse The payable, or the refusal.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_ADMINISTER) === false) {
			return new JSONResponse(['error' => 'Approving a payout needs the payment.administer action.'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(step: fn (): array => $this->payables->approve(payableId: $id));
	}//end approve()

	/**
	 * Mark the payable paid by bank.
	 *
	 * @param string $id            The payable id.
	 * @param string $bankReference The reference of the bank payment.
	 * @param string $bankAccount   The ledger account of the bank it was paid from.
	 * @param string $paidOn        The date it left the bank, today when empty.
	 *
	 * @return JSONResponse The payable, or the refusal.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	#[NoAdminRequired]
	public function markPaid(string $id, string $bankReference = '', string $bankAccount = '', string $paidOn = ''): JSONResponse {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_ADMINISTER) === false) {
			return new JSONResponse(['error' => 'Marking a payout paid needs the payment.administer action.'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(
			step: fn (): array => $this->payables->markPaid(payableId: $id, bankReference: $bankReference, bankAccount: $bankAccount, paidOn: $paidOn)
		);
	}//end markPaid()

	/**
	 * Reject the payable with a reason.
	 *
	 * @param string $id     The payable id.
	 * @param string $reason Why.
	 *
	 * @return JSONResponse The payable, or the refusal.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	#[NoAdminRequired]
	public function reject(string $id, string $reason = ''): JSONResponse {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_ADMINISTER) === false) {
			return new JSONResponse(['error' => 'Rejecting a payout needs the payment.administer action.'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(step: fn (): array => $this->payables->reject(payableId: $id, reason: $reason));
	}//end reject()

	/**
	 * Run one step and answer it.
	 *
	 * @param callable(): array<string, mixed> $step The step.
	 *
	 * @return JSONResponse The payable, a 400 with the reason, or a 500.
	 */
	private function answer(callable $step): JSONResponse {
		try {
			$payable = $step();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: a payout step could not be carried out', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The payout step could not be carried out.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($payable);
	}//end answer()
}//end class
