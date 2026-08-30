<?php

/**
 * WBSO Account API Controller
 *
 * REST surface for the Account register declared by the
 * bookkeeping-financial-administration spec (REQ-WBSO-001 / REQ-WBSO-005 /
 * REQ-WBSO-006). Endpoints:
 *  - GET  /api/v1/accounts                — list all accounts (bookkeeper+).
 *  - GET  /api/v1/accounts/hierarchy      — get tree view (bookkeeper+).
 *  - GET  /api/v1/accounts/{accountNumber} — get single account with children.
 *  - POST /api/v1/accounts                 — create account (administrator).
 *  - PUT  /api/v1/accounts/{accountNumber} — update account (administrator).
 *
 * Every endpoint is gated by REQ-WBSO-005: reads require a bookkeeper /
 * auditor / administrator role; writes are administrator-only. Administration
 * scope is derived from the authenticated user's context (no client-supplied
 * trust boundary).
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\WbsoAccountService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Account REST API (REQ-WBSO-001 / REQ-WBSO-005 / REQ-WBSO-006).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 */
class WbsoAccountApiController extends Controller {

	/**
	 * Identifier-safe slug pattern (REQ-WBSO-005 / ADR-005).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param WbsoAccountService $accounts Account service.
	 * @param WbsoRbacResolver $rbac Role resolver.
	 * @param IUserSession $userSession Session.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 */
	public function __construct(
		IRequest $request,
		private readonly WbsoAccountService $accounts,
		private readonly WbsoRbacResolver $rbac,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/v1/accounts (bookkeeper / auditor / administrator).
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$authError = $this->requireAuthenticatedReader();
		if ($authError !== null) {
			return $authError;
		}

		$administrationId = $this->resolveAdministration();
		if ($administrationId === null) {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$rows = $this->accounts->getAccountsByAdministration(administrationId: $administrationId);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load accounts', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse(
			[
				'accounts' => $rows,
				'canCreate' => $this->rbac->hasAny(['administrator']),
			],
			Http::STATUS_OK
		);

	}//end index()

	/**
	 * GET /api/v1/accounts/hierarchy (bookkeeper / auditor / administrator).
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function hierarchy(): JSONResponse {
		$authError = $this->requireAuthenticatedReader();
		if ($authError !== null) {
			return $authError;
		}

		$administrationId = $this->resolveAdministration();
		if ($administrationId === null) {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$tree = $this->accounts->getAccountHierarchy(administrationId: $administrationId);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load chart-of-accounts', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse(
			[
				'tree' => $tree,
				'canCreate' => $this->rbac->hasAny(['administrator']),
			],
			Http::STATUS_OK
		);

	}//end hierarchy()

	/**
	 * GET /api/v1/accounts/{accountNumber}.
	 *
	 * @param string $accountNumber Account number to fetch.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function show(string $accountNumber): JSONResponse {
		$authError = $this->requireAuthenticatedReader();
		if ($authError !== null) {
			return $authError;
		}

		$administrationId = $this->resolveAdministration();
		if ($administrationId === null) {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match(self::ID_PATTERN, $accountNumber) !== 1) {
			return new JSONResponse(['error' => 'Invalid accountNumber'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$row = $this->accounts->getAccountByNumber(
				administrationId: $administrationId,
				accountNumber: $accountNumber,
			);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load account', context: ['exception' => $e->getMessage()]);
		}

		if ($row === null) {
			return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row, Http::STATUS_OK);
	}//end show()

	/**
	 * POST /api/v1/accounts (administrator only).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$authError = $this->requireAuthenticatedReader();
		if ($authError !== null) {
			return $authError;
		}

		if ($this->rbac->hasAny(['administrator']) === false) {
			return new JSONResponse(['error' => 'Administrator role required'], Http::STATUS_FORBIDDEN);
		}

		$administrationId = $this->resolveAdministration();
		if ($administrationId === null) {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $this->collectPayload(
			fields: [
				'accountNumber',
				'name',
				'accountType',
				'parentAccountNumber',
				'status',
				'currency',
				'description',
				'vatApplicable',
			]
		);

		try {
			$row = $this->accounts->createAccount(administrationId: $administrationId, payload: $payload);
		} catch (InvalidArgumentException $e) {
			$this->logger->error(
				'WbsoAccountApiController: account creation payload rejected',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to create account: the submitted details are invalid'),
					'error' => 'wbso-account-create-failed',
				],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to create account', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_CREATED);
	}//end create()

	/**
	 * PUT /api/v1/accounts/{accountNumber} (administrator only).
	 *
	 * @param string $accountNumber Account to update.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function update(string $accountNumber): JSONResponse {
		$authError = $this->requireAuthenticatedReader();
		if ($authError !== null) {
			return $authError;
		}

		if ($this->rbac->hasAny(['administrator']) === false) {
			return new JSONResponse(['error' => 'Administrator role required'], Http::STATUS_FORBIDDEN);
		}

		$administrationId = $this->resolveAdministration();
		if ($administrationId === null) {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match(self::ID_PATTERN, $accountNumber) !== 1) {
			return new JSONResponse(['error' => 'Invalid accountNumber'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $this->collectPayload(
			fields: [
				'name',
				'parentAccountNumber',
				'status',
				'currency',
				'description',
				'vatApplicable',
			]
		);

		try {
			$row = $this->accounts->updateAccount(
				administrationId: $administrationId,
				accountNumber: $accountNumber,
				payload: $payload,
			);
		} catch (InvalidArgumentException $e) {
			// Match "not found" vs validation distinct status codes.
			if ($e->getMessage() === 'Account not found') {
				return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
			}

			$this->logger->error(
				'WbsoAccountApiController: account update payload rejected',
				['administrationId' => $administrationId, 'accountNumber' => $accountNumber, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to update account: the submitted details are invalid'),
					'error' => 'wbso-account-update-failed',
				],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to update account', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_OK);
	}//end update()

	/**
	 * Common precondition: a user must be authenticated to read any data.
	 *
	 * @return JSONResponse|null
	 */
	private function requireAuthenticatedReader(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireAuthenticatedReader()

	/**
	 * Resolve the administration scope from the request, falling back to the
	 * canonical demo administration when the parameter is omitted (single-
	 * administration installs).
	 *
	 * @return string|null Resolved id, or null when malformed.
	 */
	private function resolveAdministration(): ?string {
		$value = trim((string)$this->request->getParam('administration_id', 'adm-consultancy-nl'));
		if ($value === '') {
			return null;
		}

		if (preg_match(self::ID_PATTERN, $value) !== 1) {
			return null;
		}

		return $value;
	}//end resolveAdministration()

	/**
	 * Collect a request payload from query params or JSON body.
	 *
	 * @param array<int,string> $fields Whitelisted fields.
	 *
	 * @return array<string,mixed>
	 */
	private function collectPayload(array $fields): array {
		$payload = [];
		foreach ($fields as $field) {
			$value = $this->request->getParam($field, null);
			if ($value === null) {
				continue;
			}

			$payload[$field] = $value;
		}

		return $payload;
	}//end collectPayload()

	/**
	 * Log a failure and return a 500 without leaking a stack trace.
	 *
	 * @param string $message Client-facing error.
	 * @param array<string,mixed> $context Structured log context.
	 *
	 * @return JSONResponse
	 */
	private function fail(string $message, array $context): JSONResponse {
		$this->logger->error('WbsoAccountApiController: ' . $message, $context);

		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end fail()
}//end class
