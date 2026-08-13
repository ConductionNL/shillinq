<?php

/**
 * Programmabegroting Controller
 *
 * Tier-2 read-only programmabegroting API (REQ-011, REQ-012). Exposes the
 * computed sluitend-status and the iv3 / JSON exports for one administration +
 * begroting. Every endpoint is available to any authenticated user
 * (#[NoAdminRequired]); the administration scope is validated and reads are
 * delegated to OpenRegister's ObjectService, which enforces multitenancy / RBAC,
 * so no cross-administration data leaks (IDOR-safe per ADR-005). Vaststelling
 * and wijziging transitions are not exposed here — they run through the
 * declarative x-openregister-lifecycle and its guards.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ProgrammabegrotingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET endpoints for sluitend-status and programmabegroting exports.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
 */
class ProgrammabegrotingController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param ProgrammabegrotingService $service The read/compute service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ProgrammabegrotingService $service,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/programmabegroting/sluitend — computed sluitend-status (REQ-011).
	 *
	 * Query parameters: begroting_id (required), administration_id (required).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
	 */
	#[NoAdminRequired]
	public function sluitend(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		return $this->dispatch(
			handler: fn (string $admin, string $budget): array
				=> $this->service->sluitendStatus(administrationId: $admin, budgetId: $budget),
			failure: 'Failed to compute sluitend status'
		);

	}//end sluitend()

	/**
	 * GET /api/programmabegroting/export/iv3 — taakveld-aggregated iv3 rows (REQ-012).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
	 */
	#[NoAdminRequired]
	public function iv3(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		return $this->dispatch(
			handler: fn (string $admin, string $budget): array
				=> ['data' => $this->service->iv3Export(administrationId: $admin, budgetId: $budget)],
			failure: 'Failed to produce iv3 export'
		);

	}//end iv3()

	/**
	 * GET /api/programmabegroting/export/json — OpenCatalogi JSON export (REQ-012).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
	 */
	#[NoAdminRequired]
	public function jsonExport(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		return $this->dispatch(
			handler: fn (string $admin, string $budget): array
				=> $this->service->jsonExport(administrationId: $admin, budgetId: $budget),
			failure: 'Failed to produce JSON export'
		);

	}//end jsonExport()

	/**
	 * Authorization body-guard: the in-body counterpart to #[NoAdminRequired].
	 * Every endpoint requires an authenticated user (ADR-005); gate-7
	 * no-admin-idor reads the `->require*(` call to recognise the auth posture.
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, null when ok.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireUser()

	/**
	 * Shared parameter validation + error handling for the read endpoints.
	 *
	 * Validates begroting_id and administration_id as short slugs (IDOR-safe,
	 * input-validated), runs the handler, and maps any failure to HTTP 500
	 * without leaking a stack trace.
	 *
	 * @param callable(string,string):array<string,mixed> $handler The compute closure.
	 * @param string $failure The client-facing failure message.
	 *
	 * @return JSONResponse
	 */
	private function dispatch(callable $handler, string $failure): JSONResponse {
		$budgetId = trim((string)$this->request->getParam('begroting_id', ''));
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		if ($budgetId === '' || preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $budgetId) !== 1) {
			return new JSONResponse(['error' => 'begroting_id is required and must be a valid identifier'], Http::STATUS_BAD_REQUEST);
		}

		if ($administrationId === '' || preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administration_id is required and must be a valid identifier'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $handler($administrationId, $budgetId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ProgrammabegrotingController: ' . $failure,
				['budgetId' => $budgetId, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => $failure], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end dispatch()
}//end class
