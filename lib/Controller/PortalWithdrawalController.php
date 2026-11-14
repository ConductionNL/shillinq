<?php

/**
 * Portal Withdrawal Controller
 *
 * Receives portaliq's server-to-server forward of the `withdraw` action that
 * the customer manifest declares (PortalContributionProvider): a consumer
 * withdraws from an appointment booked through the widget
 * (sales-cancellation REQ-SCX-004).
 *
 * `#[PublicPage]` because the caller is portaliq's backend, not a browser.
 * The credential is either the `X-Portal-Subject` assertion verified by
 * PortalAssertionVerifier (a signed-in customer, their own appointment only),
 * or, for a guest without a portal account, the signed withdrawal link's
 * token, which opens the one appointment it names until the withdrawal
 * period ends.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Shillinq\Service\PortalWithdrawalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The withdrawal function's forward target.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 */
class PortalWithdrawalController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request     The request.
	 * @param PortalAssertionVerifierInterface $verifier    Verifies portaliq's subject assertion.
	 * @param PortalWithdrawalService          $withdrawals Checks the caller and withdraws.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalWithdrawalService $withdrawals,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Withdraw from the appointment named by `appointmentId` (a signed-in
	 * customer) or by the signed link's `token` (a guest).
	 *
	 * 401 without a valid assertion or token, 403 for another audience, an
	 * appointment that is not the caller's or a link that opens nothing, 409
	 * with `code` and `reason` when the withdrawal period no longer allows it,
	 * 502 when OpenRegister fails, 200 `{status: withdrawn}` otherwise.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function withdraw(): JSONResponse {
		$now = new DateTimeImmutable();
		$assertion = trim((string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($assertion !== '') {
			$claims = $this->verifier->verify($assertion);
			if ($claims === null) {
				return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
			}

			if ((string)($claims['audience'] ?? '') !== 'customer') {
				return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
			}

			$result = $this->withdrawals->withdrawForSubject(claims: $claims, appointmentId: $this->stringParam(key: 'appointmentId'), now: $now);
		} else {
			$token = $this->stringParam(key: 'token');
			if ($token === '') {
				return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
			}

			$result = $this->withdrawals->withdrawWithLink(token: $token, now: $now);
		}

		return match ($result['status']) {
			PortalWithdrawalService::WITHDRAWN => new JSONResponse(['status' => 'withdrawn']),
			PortalWithdrawalService::REFUSED => new JSONResponse(
				['error' => 'not_withdrawable', 'code' => $result['code'], 'reason' => $result['reason']],
				Http::STATUS_CONFLICT
			),
			PortalWithdrawalService::DOWNSTREAM_ERROR => new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY),
			default => new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN),
		};
	}//end withdraw()

	/**
	 * A request parameter as a string, '' when absent or not a string.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string The value.
	 */
	private function stringParam(string $key): string {
		$value = $this->request->getParam($key);
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringParam()
}//end class
