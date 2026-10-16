<?php

/**
 * Portal Contribution Decline Controller
 *
 * Receives portaliq's server-to-server forward of the `decline` action ("I will
 * not pay") that the parent manifest declares (PortalContributionProvider). A
 * guardian answers the one reminder of a voluntary school contribution with it,
 * and the contribution closes without dunning (decision D28).
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
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Portal\PortalAssertionVerifier;
use OCA\Shillinq\Service\ContributionDeclineService;
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
 * Receives the forwarded `decline` action for a guardian's own voluntary
 * contribution.
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 */
class PortalContributionDeclineController extends Controller {
	/**
	 * The audiences a debtor signs in with. Any other audience is refused
	 * before any OpenRegister read.
	 *
	 * @var array<int, string>
	 */
	private const DECLINING_AUDIENCES = ['parent', 'customer'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalAssertionVerifier $verifier Verifies the X-Portal-Subject assertion.
	 * @param ContributionDeclineService $declineService Closes the guardian's own voluntary contribution.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly ContributionDeclineService $declineService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Decline the guardian's own open voluntary contribution.
	 *
	 * Portaliq forwards `POST /apps/shillinq/api/portal/contributions/decline`
	 * with `{"invoiceId": "<uuid>"}` and the signed assertion header.
	 *
	 * Response contract: 200 `{status: declined}` (also for a repeat); 401
	 * missing or invalid assertion; 403 wrong audience, or the invoice is not an
	 * open voluntary contribution the guardian owns (one body for every reason,
	 * no existence oracle); 502 on an OpenRegister failure, never with internals.
	 *
	 * Rate limit: a human clicks this, and each call can write.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function decline(): JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::DECLINING_AUDIENCES, true) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$invoiceId = $this->request->getParam('invoiceId');
		if (is_string($invoiceId) === false) {
			$invoiceId = '';
		}

		try {
			$result = $this->declineService->decline(claims: $claims, target: $invoiceId);
		} catch (Throwable $e) {
			// Never leak internals from a #[PublicPage] endpoint (ADR-005).
			$this->logger->error('Shillinq: portal contribution decline failed unexpectedly', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY);
		}

		return match ($result) {
			ContributionDeclineService::DECLINED => new JSONResponse(['status' => 'declined']),
			ContributionDeclineService::DOWNSTREAM_ERROR => new JSONResponse(['error' => 'downstream_error'], Http::STATUS_BAD_GATEWAY),
			default => new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN),
		};
	}//end decline()
}//end class
