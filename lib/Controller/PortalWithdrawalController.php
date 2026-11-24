<?php

/**
 * Portal Withdrawal Controller
 *
 * Receives portaliq's server-to-server forward of the `withdraw` action that
 * the customer manifest declares (PortalContributionProvider): a consumer
 * withdraws from an appointment they booked online (sales-cancellation
 * REQ-SCX-004). The caller proves entitlement one of two ways: the
 * `X-Portal-Subject` assertion of a signed-in customer naming
 * `appointmentId`, or, for a guest without a portal account, the `token` of
 * the signed link from their confirmation mail (design D4).
 *
 * `#[PublicPage]` because the caller is portaliq's backend, not a browser:
 * the assertion or the signed token is the only credential.
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
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Shillinq\Service\Booking\PortalWithdrawalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * POST /api/portal/withdrawals.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
 */
class PortalWithdrawalController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request     The request.
	 * @param PortalAssertionVerifierInterface $verifier    Verifies portaliq's subject assertion.
	 * @param PortalWithdrawalService          $withdrawals Checks entitlement and withdraws.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalWithdrawalService $withdrawals,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Withdraw from an appointment.
	 *
	 * 200 `{status: withdrawn, acknowledgedAt}`; 409 `{error: not_withdrawable, reason}` when the
	 * period ended, the appointment started or the service is exempt; 401 with neither a valid
	 * assertion nor a valid token; 403 for a wrong audience, another customer's appointment or
	 * one that does not exist (one body, no existence oracle); 502 on an OpenRegister failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function withdraw(): JSONResponse {
		$result = $this->entitledWithdrawal(now: new DateTimeImmutable());
		if ($result === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return match ($result['status']) {
			PortalWithdrawalService::WITHDRAWN => new JSONResponse(
				['status' => 'withdrawn', 'acknowledgedAt' => ($result['acknowledgedAt'] ?? null)]
			),
			PortalWithdrawalService::REFUSED => new JSONResponse(
				['error' => 'not_withdrawable', 'reason' => ($result['reason'] ?? null)],
				Http::STATUS_CONFLICT
			),
			PortalWithdrawalService::ERROR => new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY),
			default => new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN),
		};
	}//end withdraw()

	/**
	 * Withdraw on the credential the request carries: the assertion when present, else the link token.
	 *
	 * @param DateTimeImmutable $now The moment of the request.
	 *
	 * @return array{status: string, reason?: string|null, acknowledgedAt?: string|null}|null Null without a valid credential.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
	 */
	private function entitledWithdrawal(DateTimeImmutable $now): ?array {
		$assertion = trim((string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($assertion !== '') {
			$claims = $this->verifier->verify($assertion);
			if ($claims === null) {
				return null;
			}

			$appointmentId = $this->stringParam(key: 'appointmentId');

			return $this->withdrawals->withdrawForSubject(claims: $claims, appointmentId: $appointmentId, now: $now);
		}

		$token = $this->stringParam(key: 'token');
		if ($token === '') {
			return null;
		}

		return $this->withdrawals->withdrawByLink(token: $token, now: $now);
	}//end entitledWithdrawal()

	/**
	 * A request parameter as a string, '' when absent or not a string.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string
	 */
	private function stringParam(string $key): string {
		$value = $this->request->getParam($key, '');

		return match (is_string($value)) {
			true => $value,
			false => '',
		};
	}//end stringParam()
}//end class
