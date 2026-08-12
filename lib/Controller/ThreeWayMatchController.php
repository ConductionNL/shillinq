<?php

/**
 * Three-Way Match Controller
 *
 * Server-authoritative API for the 3-way matching engine (slice 06 of the
 * bookkeeping-purchase-order-3way chain). Exposes a single trigger endpoint
 * that evaluates a SupplierInvoice against its PO/GRN candidates and
 * writes the ThreeWayMatch record.
 *
 * Every endpoint is #[NoAdminRequired] (admin posture is the NC
 * SecurityMiddleware default — controllers without the attribute are
 * admin-only, see [[nc-security-defaults]]); a manual user-session guard
 * rejects anonymous callers and the administration scope is validated via
 * AdministrationContextService so cross-tenant access is masked as 404
 * (ADR-005 IDOR-safe). No stack traces are returned to the client.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Three-way-match trigger endpoint.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 */
class ThreeWayMatchController extends Controller {

	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ThreeWayMatchingEngine $matchingEngine The matching engine (server-authoritative).
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User-session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ThreeWayMatchingEngine $matchingEngine,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Trigger a 3-way match evaluation for a SupplierInvoice.
	 *
	 * POST /api/three-way-matches/evaluate
	 * Body: administrationId, invoiceId.
	 *
	 * Server-authoritative: the matching engine derives its own scope
	 * from the persisted SupplierInvoice, but the controller asserts the
	 * caller can see the requested administration so cross-tenant
	 * triggers are masked as 404 before the engine runs.
	 *
	 * @return JSONResponse 200 with the persisted ThreeWayMatch; 400 on
	 *                      validation; 401 anonymous; 404 cross-tenant or
	 *                      missing invoice; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	#[NoAdminRequired]
	public function evaluate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Supplier invoice not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = $this->scopeParam(name: 'invoiceId');
		if ($invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$match = $this->matchingEngine->evaluateMatch(
				administrationId: $administrationId,
				invoiceId:        $invoiceId
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ThreeWayMatchController: failed to evaluate match',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not evaluate three-way match'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($match, Http::STATUS_OK);
	}//end evaluate()

	/**
	 * Read + validate a scope parameter; '' when blank/malformed.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()

	/**
	 * Map a service-level RuntimeException to a JSONResponse.
	 *
	 * @param \RuntimeException $exception The exception.
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
