<?php

/**
 * WBSO Account API Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
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
	 * The fields a new account is created from, when sent.
	 *
	 * @var array<int,string>
	 */
	private const CREATE_FIELDS = [
		'accountNumber',
		'name',
		'accountType',
		'parentAccountNumber',
		'status',
		'currency',
		'description',
		'vatApplicable',
	];

	/**
	 * The fields an account update may change, when sent.
	 *
	 * @var array<int,string>
	 */
	private const UPDATE_FIELDS = [
		'name',
		'parentAccountNumber',
		'status',
		'currency',
		'description',
		'vatApplicable',
	];

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param WbsoAccountService $accounts Account service.
	 * @param WbsoRbacResolver $rbac Role resolver.
	 * @param IUserSession $userSession Session.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 * @param AdministrationContextService $administrationContext Membership check on the requested administration.
	 */
	public function __construct(
		IRequest $request,
		private readonly WbsoAccountService $accounts,
		private readonly WbsoRbacResolver $rbac,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly AdministrationContextService $administrationContext,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/v1/accounts (bookkeeper / auditor / administrator).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$administrationId = $this->guard()->read();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$rows = $this->accounts->getAccountsByAdministration(administrationId: $administrationId);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load accounts', context: ['exception' => $e->getMessage()]);
		}

		return $this->withCanCreate(body: ['accounts' => $rows]);

	}//end index()

	/**
	 * GET /api/v1/accounts/hierarchy (bookkeeper / auditor / administrator).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	#[NoAdminRequired]
	public function hierarchy(): JSONResponse {
		$administrationId = $this->guard()->read();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$tree = $this->accounts->getAccountHierarchy(administrationId: $administrationId);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load chart-of-accounts', context: ['exception' => $e->getMessage()]);
		}

		return $this->withCanCreate(body: ['tree' => $tree]);

	}//end hierarchy()

	/**
	 * GET /api/v1/accounts/{accountNumber}.
	 *
	 * @param string $accountNumber Account number to fetch.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	#[NoAdminRequired]
	public function show(string $accountNumber): JSONResponse {
		$administrationId = $this->guard()->read();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $accountNumber) !== 1) {
			return $this->invalidAccountNumber();
		}

		try {
			$row = $this->accountByNumber(administrationId: $administrationId, accountNumber: $accountNumber);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load account', context: ['exception' => $e->getMessage()]);
		}

		return $this->rowOrNotFound(row: $row);
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
		$administrationId = $this->guard()->write(roles: ['administrator'], refusal: 'Administrator role required');
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$payload = $this->collectPayload(fields: self::CREATE_FIELDS);

		try {
			$row = $this->accounts->createAccount(administrationId: $administrationId, payload: $payload);
		} catch (InvalidArgumentException $e) {
			return $this->invalidAccount(administrationId: $administrationId, failure: $e);
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
		$administrationId = $this->guard()->write(roles: ['administrator'], refusal: 'Administrator role required');
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $accountNumber) !== 1) {
			return $this->invalidAccountNumber();
		}

		return $this->updateAccount(administrationId: $administrationId, accountNumber: $accountNumber);
	}//end update()

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

	/**
	 * The access steps of this API.
	 *
	 * @return WbsoActionGuard
	 */
	private function guard(): WbsoActionGuard {
		return new WbsoActionGuard(
			caller: new WbsoCallerCheck(userSession: $this->userSession, rbac: $this->rbac),
			scope: new WbsoAdministrationScope(request: $this->request, administrationContext: $this->administrationContext),
		);

	}//end guard()

	/**
	 * A listing response, with whether the caller may create accounts.
	 *
	 * @param array<string,mixed> $body The listing.
	 *
	 * @return JSONResponse
	 */
	private function withCanCreate(array $body): JSONResponse {
		$body['canCreate'] = $this->rbac->hasAny(['administrator']);
		return new JSONResponse($body, Http::STATUS_OK);

	}//end withCanCreate()

	/**
	 * The 400 for a malformed account number.
	 *
	 * @return JSONResponse
	 */
	private function invalidAccountNumber(): JSONResponse {
		return new JSONResponse(['error' => 'Invalid accountNumber'], Http::STATUS_BAD_REQUEST);

	}//end invalidAccountNumber()

	/**
	 * Answer an account the service rejected as invalid with a 400.
	 *
	 * @param string $administrationId The administration.
	 * @param InvalidArgumentException $failure The rejection.
	 *
	 * @return JSONResponse
	 */
	private function invalidAccount(string $administrationId, InvalidArgumentException $failure): JSONResponse {
		$this->logger->error(
			'WbsoAccountApiController: account creation payload rejected',
			['administrationId' => $administrationId, 'exception' => $failure->getMessage()]
		);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to create account: the submitted details are invalid'),
				'error' => 'wbso-account-create-failed',
			],
			Http::STATUS_BAD_REQUEST,
		);

	}//end invalidAccount()

	/**
	 * Update the account with the fields the request sends: 404 when the
	 * service does not know it, 400 when the update is invalid.
	 *
	 * @param string $administrationId The administration.
	 * @param string $accountNumber The account.
	 *
	 * @return JSONResponse
	 */
	private function updateAccount(string $administrationId, string $accountNumber): JSONResponse {
		$payload = $this->collectPayload(fields: self::UPDATE_FIELDS);

		try {
			$row = $this->accounts->updateAccount(
				administrationId: $administrationId,
				accountNumber: $accountNumber,
				payload: $payload,
			);
		} catch (InvalidArgumentException $e) {
			return $this->rejectedUpdate(context: ['administrationId' => $administrationId, 'accountNumber' => $accountNumber], failure: $e);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to update account', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_OK);

	}//end updateAccount()

	/**
	 * Answer an update the service rejected: 404 for an unknown account,
	 * otherwise a logged 400.
	 *
	 * @param array<string,string> $context The administration and account, for the log.
	 * @param InvalidArgumentException $failure The rejection.
	 *
	 * @return JSONResponse
	 */
	private function rejectedUpdate(array $context, InvalidArgumentException $failure): JSONResponse {
		// Match "not found" vs validation distinct status codes.
		if ($failure->getMessage() === 'Account not found') {
			return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
		}

		return $this->invalidUpdate(context: $context + ['exception' => $failure->getMessage()]);

	}//end rejectedUpdate()

	/**
	 * Answer an update the service rejected as invalid with a logged 400.
	 *
	 * @param array<string,string> $context The log context.
	 *
	 * @return JSONResponse
	 */
	private function invalidUpdate(array $context): JSONResponse {
		$this->logger->error('WbsoAccountApiController: account update payload rejected', $context);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to update account: the submitted details are invalid'),
				'error' => 'wbso-account-update-failed',
			],
			Http::STATUS_BAD_REQUEST,
		);

	}//end invalidUpdate()

	/**
	 * Look the account up by its number.
	 *
	 * @param string $administrationId The administration.
	 * @param string $accountNumber The account number.
	 *
	 * @return array<string,mixed>|null
	 */
	private function accountByNumber(string $administrationId, string $accountNumber): ?array {
		return $this->accounts->getAccountByNumber(
			administrationId: $administrationId,
			accountNumber: $accountNumber,
		);

	}//end accountByNumber()

	/**
	 * The account, or a 404 when there is none.
	 *
	 * @param array<string,mixed>|null $row The account.
	 *
	 * @return JSONResponse
	 */
	private function rowOrNotFound(?array $row): JSONResponse {
		if ($row === null) {
			return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row, Http::STATUS_OK);

	}//end rowOrNotFound()

}//end class
