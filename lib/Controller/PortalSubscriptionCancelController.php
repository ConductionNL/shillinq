<?php

/**
 * Portal Subscription Cancel Controller
 *
 * Receives portaliq's server-to-server forward of the `cancel-subscription`
 * action that the customer manifest declares (PortalContributionProvider): a
 * customer ends their own subscription (sales-cancellation REQ-SCX-003).
 *
 * `#[PublicPage]` because the caller is portaliq's backend, not a browser: the
 * `X-Portal-Subject` assertion verified by PortalAssertionVerifier is the only
 * credential, and the audience is gated before any OpenRegister read.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Shillinq\Kernel\Contracts\SubscriptionCancellationServiceInterface;
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
 * POST /api/portal/subscriptions/cancel.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
 */
class PortalSubscriptionCancelController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                                 $request      The request.
	 * @param PortalAssertionVerifierInterface         $verifier     Verifies portaliq's subject assertion.
	 * @param SubscriptionCancellationServiceInterface $cancellation Ends the subscription.
	 * @param LoggerInterface                          $logger       The logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly SubscriptionCancellationServiceInterface $cancellation,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * End the customer's own subscription named by `profileId`.
	 *
	 * 401 without a valid assertion, 403 for another audience or a profile
	 * that is not this customer's running subscription, 502 when OpenRegister
	 * fails, 200 `{status: cancelled}` otherwise.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function cancel(): JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ((string)($claims['audience'] ?? '') !== 'customer') {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->cancellation->cancel(
				claims: $claims,
				profileId: $this->stringParam(key: 'profileId'),
				reasonCode: $this->stringParam(key: 'reasonCode'),
				reasonText: $this->stringParam(key: 'reasonText'),
			);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: portal subscription cancel failed unexpectedly', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY);
		}

		return match ($result) {
			SubscriptionCancellationServiceInterface::CANCELLED => new JSONResponse(['status' => 'cancelled']),
			SubscriptionCancellationServiceInterface::DOWNSTREAM_ERROR => new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY),
			default => new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN),
		};
	}//end cancel()

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
