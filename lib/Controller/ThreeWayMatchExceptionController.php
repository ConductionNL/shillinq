<?php

/**
 * Three-way Match Exception Controller
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 */
class ThreeWayMatchExceptionController extends Controller {

	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Cap on the resolution-notes free-text field — long enough for a
	 * meaningful motivation, short enough that we cannot be used as a
	 * blob store.
	 *
	 * @var int
	 */
	private const NOTES_MAX_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ExceptionResolutionService $exceptions Slice 08 server-authoritative
	 *                                               service.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ExceptionResolutionService $exceptions,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	#[NoAdminRequired]
	public function accept(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$matchId = $this->scopeParam(name: 'matchId');
		if ($matchId === '') {
			return new JSONResponse(['error' => 'matchId is required'], Http::STATUS_BAD_REQUEST);
		}

		$resolutionNotes = $this->notesParam(name: 'resolutionNotes');
		if ($resolutionNotes === '') {
			return new JSONResponse(['error' => 'resolutionNotes is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$match = $this->exceptions->acceptWithMotivation(
				administrationId: $administrationId,
				matchId: $matchId,
				resolutionNotes: $resolutionNotes
			);
		} catch (RuntimeException $exception) {
			return $this->mapRuntimeException(exception: $exception);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ThreeWayMatchExceptionController: accept failed',
				[
					'administrationId' => $administrationId,
					'matchId' => $matchId,
					'exception' => $exception->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not record acceptance'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($match, Http::STATUS_OK);
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
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	#[NoAdminRequired]
	public function dispute(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$matchId = $this->scopeParam(name: 'matchId');
		if ($matchId === '') {
			return new JSONResponse(['error' => 'matchId is required'], Http::STATUS_BAD_REQUEST);
		}

		$disputeReason = $this->notesParam(name: 'disputeReason');
		if ($disputeReason === '') {
			return new JSONResponse(['error' => 'disputeReason is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->exceptions->fileDispute(
				administrationId: $administrationId,
				matchId: $matchId,
				disputeReason: $disputeReason
			);
		} catch (RuntimeException $exception) {
			return $this->mapRuntimeException(exception: $exception);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ThreeWayMatchExceptionController: dispute failed',
				[
					'administrationId' => $administrationId,
					'matchId' => $matchId,
					'exception' => $exception->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not file dispute'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
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
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	#[NoAdminRequired]
	public function reject(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$matchId = $this->scopeParam(name: 'matchId');
		if ($matchId === '') {
			return new JSONResponse(['error' => 'matchId is required'], Http::STATUS_BAD_REQUEST);
		}

		$rejectionReason = $this->notesParam(name: 'rejectionReason');
		if ($rejectionReason === '') {
			return new JSONResponse(['error' => 'rejectionReason is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$match = $this->exceptions->rejectAndBlockPayment(
				administrationId: $administrationId,
				matchId: $matchId,
				rejectionReason: $rejectionReason
			);
		} catch (RuntimeException $exception) {
			return $this->mapRuntimeException(exception: $exception);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ThreeWayMatchExceptionController: reject failed',
				[
					'administrationId' => $administrationId,
					'matchId' => $matchId,
					'exception' => $exception->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not record rejection'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($match, Http::STATUS_OK);
	}//end reject()

	/**
	 * Read and validate a scope parameter, returning '' when blank or
	 * malformed.
	 *
	 * @param string $name Parameter name (body for POST / query for GET).
	 *
	 * @return string The validated value or '' (blank/malformed).
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()

	/**
	 * Read a free-text notes/reason parameter, trimming whitespace and
	 * capping at {@see NOTES_MAX_LENGTH} characters.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string The trimmed value (possibly empty).
	 */
	private function notesParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '') {
			return '';
		}

		if (mb_strlen($value) > self::NOTES_MAX_LENGTH) {
			$value = mb_substr($value, 0, self::NOTES_MAX_LENGTH);
		}

		return $value;
	}//end notesParam()

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
