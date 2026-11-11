<?php

/**
 * WBSO Transaction API Controller
 *
 * REST surface for the Transaction register declared by the
 * bookkeeping-financial-administration spec (REQ-WBSO-002 / REQ-WBSO-005 /
 * REQ-WBSO-008). Endpoints:
 *  - GET  /api/v1/transactions                — list (filters: date, status, type).
 *  - GET  /api/v1/transactions/{id}           — fetch one.
 *  - POST /api/v1/transactions                — create draft (bookkeeper / admin).
 *  - POST /api/v1/transactions/{id}/post      — post a draft (bookkeeper / admin).
 *  - POST /api/v1/transactions/{id}/reverse   — reverse a posted (admin only).
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-27
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCA\Shillinq\Service\WbsoTransactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Transaction REST API (REQ-WBSO-002 / REQ-WBSO-005 / REQ-WBSO-008).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-27
 */
class WbsoTransactionApiController extends Controller {
	/**
	 * Identifier-safe slug pattern.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Roles that may create and post transactions.
	 *
	 * @var array<int,string>
	 */
	private const BOOKKEEPER_ROLES = ['bookkeeper', 'administrator'];

	/**
	 * Refusal for a caller without a bookkeeper role.
	 */
	private const BOOKKEEPER_REFUSAL = 'Bookkeeper or administrator role required';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param WbsoTransactionService $transactions Transaction service.
	 * @param WbsoRbacResolver $rbac Role resolver.
	 * @param IUserSession $userSession Session.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 * @param AdministrationContextService $administrationContext Membership check on the requested administration.
	 */
	public function __construct(
		IRequest $request,
		private readonly WbsoTransactionService $transactions,
		private readonly WbsoRbacResolver $rbac,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly AdministrationContextService $administrationContext,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/v1/transactions (bookkeeper / auditor / admin).
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
			$rows = $this->transactions->listTransactions(administrationId: $administrationId, filters: $this->listFilters());
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load transactions', context: ['exception' => $e->getMessage()]);
		}

		return $this->listing(rows: $rows);

	}//end index()

	/**
	 * GET /api/v1/transactions/{id}.
	 *
	 * @param string $id Transaction id or transactionNumber.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$administrationId = $this->guard()->read();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return $this->invalidId();
		}

		try {
			$row = $this->transactions->getTransaction(administrationId: $administrationId, transactionId: $id);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load transaction', context: ['exception' => $e->getMessage()]);
		}

		return $this->rowOrNotFound(row: $row);
	}//end show()

	/**
	 * POST /api/v1/transactions (bookkeeper or admin).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$administrationId = $this->guard()->book(roles: self::BOOKKEEPER_ROLES, refusal: self::BOOKKEEPER_REFUSAL);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$row = $this->transactions->createTransaction(administrationId: $administrationId, payload: $this->transactionPayload());
		} catch (InvalidArgumentException $e) {
			return $this->invalidTransaction(failure: $e);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to create transaction', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_CREATED);
	}//end create()

	/**
	 * POST /api/v1/transactions/{id}/post (bookkeeper or admin).
	 *
	 * @param string $id Transaction id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function post(string $id): JSONResponse {
		$administrationId = $this->guard()->book(roles: self::BOOKKEEPER_ROLES, refusal: self::BOOKKEEPER_REFUSAL);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return $this->invalidId();
		}

		return $this->postTransaction(administrationId: $administrationId, transactionId: $id);
	}//end post()

	/**
	 * POST /api/v1/transactions/{id}/reverse (admin only).
	 *
	 * @param string $id Transaction id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function reverse(string $id): JSONResponse {
		$administrationId = $this->guard()->book(roles: ['administrator'], refusal: 'Administrator role required');
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return $this->invalidId();
		}

		return $this->reverseTransaction(administrationId: $administrationId, transactionId: $id);
	}//end reverse()

	/**
	 * Logger + 500 response without stack-traces.
	 *
	 * @param string $message Client-facing message.
	 * @param array<string,mixed> $context Structured log context.
	 *
	 * @return JSONResponse
	 */
	private function fail(string $message, array $context): JSONResponse {
		$this->logger->error('WbsoTransactionApiController: ' . $message, $context);

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
	 * The listing filters from the request.
	 *
	 * @return array<string,string>
	 */
	private function listFilters(): array {
		return [
			'status' => (string)$this->request->getParam('status', ''),
			'type' => (string)$this->request->getParam('type', ''),
			'dateFrom' => (string)$this->request->getParam('dateFrom', ''),
			'dateTo' => (string)$this->request->getParam('dateTo', ''),
		];

	}//end listFilters()

	/**
	 * The transaction to create, from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function transactionPayload(): array {
		return [
			'transactionNumber' => (string)$this->request->getParam('transactionNumber', ''),
			'transactionType' => (string)$this->request->getParam('transactionType', ''),
			'transactionDate' => (string)$this->request->getParam('transactionDate', ''),
			'amount' => $this->request->getParam('amount', 0),
			'description' => (string)$this->request->getParam('description', ''),
		];

	}//end transactionPayload()

	/**
	 * Answer a transaction the service rejected as invalid with a 400.
	 *
	 * @param InvalidArgumentException $failure The rejection.
	 *
	 * @return JSONResponse
	 */
	private function invalidTransaction(InvalidArgumentException $failure): JSONResponse {
		$this->logger->error('WbsoTransactionApiController.create failed', ['exception' => $failure]);

		return new JSONResponse(
			['message' => $this->l10n->t('Invalid transaction data'), 'error' => 'wbso-transaction-invalid-input'],
			Http::STATUS_BAD_REQUEST,
		);

	}//end invalidTransaction()

	/**
	 * Post a draft transaction: 404 when the service does not know it, 409
	 * when its state does not allow posting.
	 *
	 * @param string $administrationId The administration.
	 * @param string $transactionId The transaction to post.
	 *
	 * @return JSONResponse
	 */
	private function postTransaction(string $administrationId, string $transactionId): JSONResponse {
		try {
			$row = $this->transactions->postTransaction(administrationId: $administrationId, transactionId: $transactionId);
		} catch (InvalidArgumentException $e) {
			$this->logger->error('WbsoTransactionApiController.post failed', ['exception' => $e]);

			return $this->transactionNotFound();
		} catch (RuntimeException $e) {
			return $this->postConflict(failure: $e);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to post transaction', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_OK);

	}//end postTransaction()

	/**
	 * Answer a post the transaction's state does not allow with a 409.
	 *
	 * @param RuntimeException $failure The rejection.
	 *
	 * @return JSONResponse
	 */
	private function postConflict(RuntimeException $failure): JSONResponse {
		$this->logger->error('WbsoTransactionApiController.post failed', ['exception' => $failure]);

		return new JSONResponse(
			['message' => $this->l10n->t('Unable to post transaction'), 'error' => 'wbso-transaction-conflict'],
			Http::STATUS_CONFLICT,
		);

	}//end postConflict()

	/**
	 * Answer a reversal the transaction's state does not allow with a 409.
	 *
	 * @param RuntimeException $failure The rejection.
	 *
	 * @return JSONResponse
	 */
	private function reversalConflict(RuntimeException $failure): JSONResponse {
		$this->logger->error('WbsoTransactionApiController.reverse failed', ['exception' => $failure]);

		return new JSONResponse(
			['message' => $this->l10n->t('Unable to reverse transaction'), 'error' => 'wbso-transaction-conflict'],
			Http::STATUS_CONFLICT,
		);

	}//end reversalConflict()

	/**
	 * Reverse a posted transaction with the request's reason.
	 *
	 * @param string $administrationId The administration.
	 * @param string $transactionId The transaction to reverse.
	 *
	 * @return JSONResponse
	 */
	private function reverseTransaction(string $administrationId, string $transactionId): JSONResponse {
		try {
			$row = $this->reversal(administrationId: $administrationId, transactionId: $transactionId);
		} catch (InvalidArgumentException $e) {
			return $this->reversalRejected(failure: $e);
		} catch (RuntimeException $e) {
			return $this->reversalConflict(failure: $e);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to reverse transaction', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_CREATED);

	}//end reverseTransaction()

	/**
	 * Hand the reversal, with the request's trimmed reason, to the service.
	 *
	 * @param string $administrationId The administration.
	 * @param string $transactionId The transaction to reverse.
	 *
	 * @return array<string,mixed> The reversing transaction.
	 */
	private function reversal(string $administrationId, string $transactionId): array {
		return $this->transactions->reverseTransaction(
			administrationId: $administrationId,
			transactionId: $transactionId,
			reason: trim((string)$this->request->getParam('reason', '')),
		);

	}//end reversal()

	/**
	 * The listing response, with whether the caller may create.
	 *
	 * @param array<int,mixed> $rows The transactions.
	 *
	 * @return JSONResponse
	 */
	private function listing(array $rows): JSONResponse {
		return new JSONResponse(
			[
				'transactions' => $rows,
				'canCreate' => $this->rbac->hasAny(self::BOOKKEEPER_ROLES),
			],
			Http::STATUS_OK
		);

	}//end listing()

	/**
	 * Answer a reversal the service rejected: 404 for an unknown transaction,
	 * 400 for an invalid request.
	 *
	 * @param InvalidArgumentException $failure The rejection.
	 *
	 * @return JSONResponse
	 */
	private function reversalRejected(InvalidArgumentException $failure): JSONResponse {
		$this->logger->error('WbsoTransactionApiController.reverse failed', ['exception' => $failure]);

		if ($failure->getMessage() === 'Transaction not found') {
			return $this->transactionNotFound();
		}

		return new JSONResponse(
			['message' => $this->l10n->t('Invalid reversal request'), 'error' => 'wbso-transaction-invalid-input'],
			Http::STATUS_BAD_REQUEST,
		);

	}//end reversalRejected()

	/**
	 * The 404 for a transaction the service does not know.
	 *
	 * @return JSONResponse
	 */
	private function transactionNotFound(): JSONResponse {
		return new JSONResponse(
			['message' => $this->l10n->t('Transaction not found'), 'error' => 'wbso-transaction-not-found'],
			Http::STATUS_NOT_FOUND,
		);

	}//end transactionNotFound()

	/**
	 * The 400 for a malformed transaction id.
	 *
	 * @return JSONResponse
	 */
	private function invalidId(): JSONResponse {
		return new JSONResponse(['error' => 'Invalid transaction id'], Http::STATUS_BAD_REQUEST);

	}//end invalidId()

	/**
	 * The transaction, or a 404 when there is none.
	 *
	 * @param array<string,mixed>|null $row The transaction.
	 *
	 * @return JSONResponse
	 */
	private function rowOrNotFound(?array $row): JSONResponse {
		if ($row === null) {
			return new JSONResponse(['error' => 'Transaction not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row, Http::STATUS_OK);

	}//end rowOrNotFound()

}//end class
