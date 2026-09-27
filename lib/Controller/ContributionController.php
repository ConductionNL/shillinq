<?php

/**
 * Contribution Controller
 *
 * The HTTP door to the school contribution raise. An owning app (learniq's fee
 * page, portaliq's activity roster) posts one chargeable and up to 200
 * guardians, and gets back, per guardian, the invoice and the payment request
 * it now owns a reference to. The same call exists in process on
 * ContributionRaiseService (contract.md).
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Service\ContributionRaiseService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises school contributions in bulk.
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 */
class ContributionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param ContributionRaiseService $raiser The raise.
	 * @param PaymentActionAuthorizer $authorizer The payment action matrix.
	 * @param IUserSession $userSession The calling user.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ContributionRaiseService $raiser,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Raise one invoice and one payment request per guardian for a chargeable.
	 *
	 * @return JSONResponse 200 with a result per recipient; 400 for a call that
	 *                      cannot be raised; 401 without a session; 403 without
	 *                      the payment.request action.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-002)
	 *
	 * @e2e exclude API-only endpoint, no screen in this change
	 */
	#[NoAdminRequired]
	public function raise(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_REQUEST) === false) {
			return new JSONResponse(
				['error' => 'Raising school contributions needs the payment.request action.'],
				Http::STATUS_FORBIDDEN
			);
		}

		$payload = [];
		foreach ($this->request->getParams() as $key => $value) {
			// Route parameters and framework keys start with an underscore.
			if (is_string($key) === true && str_starts_with($key, '_') === false) {
				$payload[$key] = $value;
			}
		}

		try {
			$result = $this->raiser->raise(payload: $payload);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $e) {
			if (str_starts_with($e->getMessage(), '403') === true) {
				return new JSONResponse(['error' => trim(substr($e->getMessage(), 3))], Http::STATUS_FORBIDDEN);
			}

			$this->logger->error('Shillinq: the contribution raise failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The contributions could not be raised.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: the contribution raise failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The contributions could not be raised.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end raise()
}//end class
