<?php

/**
 * Three-way Match Exception Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 08 of the bookkeeping-purchase-order-3way chain — exposes the
 * three resolution dispositions of {@see ExceptionResolutionService} so
 * the crediteuren-administrateur UI can:
 *
 *  - record an accept-with-motivation
 *    (POST /api/three-way-match/exceptions/accept),
 *  - file a dispute and emit a UBL CreditNote request
 *    (POST /api/three-way-match/exceptions/dispute),
 *  - reject and block payment
 *    (POST /api/three-way-match/exceptions/reject).
 *
 * Every endpoint is #[NoAdminRequired] (admin posture is the NC
 * SecurityMiddleware default — controllers without the attribute are
 * admin-only, see nc-security-defaults). A manual user-session guard
 * rejects anonymous callers and the administration scope is validated
 * server-side via {@see AdministrationContextService} so cross-tenant
 * calls are masked as 404 (ADR-005 IDOR-safe). The matchId is also
 * re-loaded server-side inside the service before any write so a forged
 * POST cannot pivot a resolution into another tenant's match record. No
 * stack traces are returned to the client.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\ExceptionResolutionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Three-way-match exception-resolution REST endpoints.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 */
class ThreeWayMatchExceptionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ExceptionResolutionService $exceptions Slice 08 server-authoritative
	 *                                               service.
	 * @param AdministrationContextServiceInterface $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ExceptionResolutionService $exceptions,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Record an accept-with-motivation disposition.
	 *
	 * POST /api/three-way-match/exceptions/accept
	 * Body: administrationId, matchId, resolutionNotes.
	 *
	 * @return JSONResponse 200 with the updated ThreeWayMatch; 400 on
	 *                      validation; 401 anonymous; 404 cross-tenant or
	 *                      missing match; 500 without stack trace.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	#[NoAdminRequired]
	public function accept(): JSONResponse {
		$resolution = $this->resolution(action: 'accept');
		if ($resolution instanceof JSONResponse) {
			return $resolution;
		}

		return $this->resolved(
			resolution: $resolution,
			act: fn (): array => $this->exceptions->acceptWithMotivation(
				administrationId: $resolution->administrationId,
				matchId: $resolution->matchId,
				resolutionNotes: $resolution->notes
			)
		);
	}//end accept()

	/**
	 * File a dispute on an out-of-tolerance match.
	 *
	 * POST /api/three-way-match/exceptions/dispute
	 * Body: administrationId, matchId, disputeReason.
	 *
	 * @return JSONResponse 200 with {match, dispatch}; 400 on validation;
	 *                      401 anonymous; 404 cross-tenant or missing
	 *                      match / invoice; 500 without stack trace.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	#[NoAdminRequired]
	public function dispute(): JSONResponse {
		$resolution = $this->resolution(action: 'dispute');
		if ($resolution instanceof JSONResponse) {
			return $resolution;
		}

		return $this->resolved(
			resolution: $resolution,
			act: fn (): array => $this->exceptions->fileDispute(
				administrationId: $resolution->administrationId,
				matchId: $resolution->matchId,
				disputeReason: $resolution->notes
			)
		);
	}//end dispute()

	/**
	 * Reject and block payment on an out-of-tolerance match.
	 *
	 * POST /api/three-way-match/exceptions/reject
	 * Body: administrationId, matchId, rejectionReason.
	 *
	 * @return JSONResponse 200 with the updated ThreeWayMatch; 400 on
	 *                      validation; 401 anonymous; 404 cross-tenant or
	 *                      missing match; 500 without stack trace.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	#[NoAdminRequired]
	public function reject(): JSONResponse {
		$resolution = $this->resolution(action: 'reject');
		if ($resolution instanceof JSONResponse) {
			return $resolution;
		}

		return $this->resolved(
			resolution: $resolution,
			act: fn (): array => $this->exceptions->rejectAndBlockPayment(
				administrationId: $resolution->administrationId,
				matchId: $resolution->matchId,
				rejectionReason: $resolution->notes
			)
		);
	}//end reject()

	/**
	 * The resolution the caller may record, or the refusal: 401 anonymous, 400
	 * without a well-formed administration, a masked 404 outside it, 400
	 * without a match or a motivation — in that order.
	 *
	 * @param string $action accept, dispute or reject.
	 *
	 * @return MatchResolution|JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function resolution(string $action): MatchResolution|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$resolution = new MatchResolution(request: $this->request, action: $action);
		return $this->administrationRefusal(resolution: $resolution) ?? $this->resolutionRefusal(resolution: $resolution) ?? $resolution;
	}//end resolution()

	/**
	 * The 400 without a well-formed administration, or the masked 404 when the
	 * caller may not access it.
	 *
	 * @param MatchResolution $resolution The resolution.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function administrationRefusal(MatchResolution $resolution): ?JSONResponse {
		if ($resolution->administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $resolution->administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The 400 without a well-formed match or a motivation.
	 *
	 * @param MatchResolution $resolution The resolution.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function resolutionRefusal(MatchResolution $resolution): ?JSONResponse {
		if ($resolution->matchId === '') {
			return new JSONResponse(['error' => 'matchId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($resolution->notes === '') {
			return new JSONResponse(['error' => $resolution->notesField() . ' is required'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end resolutionRefusal()

	/**
	 * Record the resolution: 200 with the service's answer, the mapped refusal,
	 * or a logged 500 without a stack trace.
	 *
	 * @param MatchResolution $resolution The resolution.
	 * @param callable        $act        The service call recording it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	private function resolved(MatchResolution $resolution, callable $act): JSONResponse {
		try {
			$result = $act();
		} catch (RuntimeException $exception) {
			return $this->mapRuntimeException(exception: $exception);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ThreeWayMatchExceptionController: ' . $resolution->action . ' failed',
				$resolution->logContext(exception: $exception)
			);
			return new JSONResponse(['error' => $resolution->failureError()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end resolved()

	/**
	 * Map a service-level RuntimeException to a JSONResponse. Conventions:
	 *  - "not found"  → 404
	 *  - everything else → 400 (validation)
	 *
	 * @param \RuntimeException $exception The exception to map.
	 *
	 * @return JSONResponse
	 */
	private function mapRuntimeException(\RuntimeException $exception): JSONResponse {
		$message = $exception->getMessage();
		if (str_contains($message, 'not found') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end mapRuntimeException()
}//end class
