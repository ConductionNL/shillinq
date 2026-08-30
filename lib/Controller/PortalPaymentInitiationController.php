<?php

/**
 * Shillinq Portal Payment Initiation Controller (portal-payment-initiation, A6 receiver).
 *
 * The receiving end of portaliq's server-to-server `pay` action forward
 * (ADR-046 contract v2, A6 — see `PortalContributionProvider`'s `customer`
 * manifest). The route is `#[PublicPage]` + `#[NoCSRFRequired]` because the
 * caller is portaliq's backend, not a browser: the `X-Portal-Subject`
 * assertion IS the authentication, verified by `PortalAssertionVerifier`
 * before anything else happens. There is deliberately NO Nextcloud-session
 * fallback — a logged-in admin without a valid assertion gets the same 401 as
 * anyone else, so there is exactly one auth path (ADR-005).
 *
 * Fail-closed ordering (mirrors the fleet-reference A6 receiver):
 *   verify (401) -> audience check (403) -> delegate to the session service,
 *   which derives ownership from the VERIFIED claims only, resolves the
 *   invoice, mints/reuses a PaymentRequest, drives the provider, and returns
 *   one of four uniform outcomes this controller maps to a response.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Portal\PortalAssertionVerifier;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives portaliq's forwarded `pay` action and mints an iDEAL checkout
 * session for the asserted subject's own invoice.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 */
class PortalPaymentInitiationController extends Controller {
	/**
	 * The audience this receiver serves — any other audience is refused
	 * before any OpenRegister read (REQ-SPPI-002).
	 */
	private const AUDIENCE_CUSTOMER = 'customer';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param PortalAssertionVerifier $verifier Verifies the X-Portal-Subject assertion.
	 * @param PortalPaymentSessionService $sessionService Resolves ownership + mints the payment session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalPaymentSessionService $sessionService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Initiate (or reuse) an iDEAL payment session for the subject's own
	 * open AR invoice.
	 *
	 * Declared in `PortalContributionProvider` as endpoint-forward action
	 * `pay`; portaliq forwards `POST /apps/shillinq/api/portal/payments/initiate`
	 * with the portal client's JSON body `{"invoiceId": "<uuid-or-slug>"}` and
	 * the signed assertion header.
	 *
	 * Response contract: 200 `{checkoutUrl}` on success; 401 missing/invalid
	 * assertion; 403 wrong audience OR the target is foreign-owned,
	 * non-payable, non-existent or malformed (identical response — no
	 * existence oracle, REQ-SPPI-003); 503 `{status: "deferred"}` when the
	 * bound provider is dormant; 502 on a downstream/OpenRegister/PSP
	 * failure, never leaking raw exception text.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 * Rate limit: citizen-facing, and it starts a payment. Tighter than the
	 * receivers because a human clicks this, and each call creates a payment
	 * intent at the provider — real work, and real cost, per request.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function initiate(): JSONResponse {
		// 1. Verify — the assertion is the ONLY credential (fail-closed 401).
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// 2. Audience gate — before any OpenRegister read (REQ-SPPI-002).
		if ((string)($claims['audience'] ?? '') !== self::AUDIENCE_CUSTOMER) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$invoiceId = $this->request->getParam('invoiceId');
		if (is_string($invoiceId) === false) {
			$invoiceId = '';
		}

		try {
			$result = $this->sessionService->initiate(claims: $claims, target: $invoiceId);
		} catch (Throwable $e) {
			// Never leak internals from a #[PublicPage] endpoint (ADR-005).
			$this->logger->error(
				'Shillinq: portal payment initiation controller caught an unexpected failure',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY);
		}

		return match ($result->status) {
			'ok' => new JSONResponse(['checkoutUrl' => $result->checkoutUrl]),
			'deferred' => new JSONResponse(['status' => 'deferred'], Http::STATUS_SERVICE_UNAVAILABLE),
			'downstream_error' => new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY),
			default => new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN),
		};
	}//end initiate()
}//end class
