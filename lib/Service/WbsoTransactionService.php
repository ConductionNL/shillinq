<?php

/**
 * WBSO Transaction Service
 *
 * Server-side helpers for the Transaction register declared by the
 * bookkeeping-financial-administration spec (REQ-WBSO-002 / REQ-WBSO-008).
 * Wraps OpenRegister's ObjectService and enforces:
 *  - state-machine transitions (draft → posted; posted → reversed);
 *  - amount + fiscal-year validation;
 *  - reversal sibling creation with audit-trail linkage.
 *
 * GL line-item posting itself is deferred to tier-2
 * `bookkeeping-general-ledger` (REQ-WBSO-008 Posting); this service only
 * manages the Transaction-level state machine.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-24
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Transaction-register helper service (REQ-WBSO-002 / REQ-WBSO-008).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-24
 */
class WbsoTransactionService {

	/**
	 * Allowed transactionType values.
	 *
	 * @var array<int,string>
	 */
	public const ALLOWED_TYPES = ['invoice', 'receipt', 'journal-entry', 'credit-note', 'debit-note'];

	/**
	 * Allowed status values.
	 *
	 * @var array<int,string>
	 */
	public const ALLOWED_STATES = ['draft', 'posted', 'reversed'];

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config (register slug).
	 * @param IUserSession $userSession Authenticated session (used to record createdBy).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * List transactions for an administration, optionally filtered.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $filters Optional filters: status, type, dateFrom, dateTo.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function listTransactions(string $administrationId, array $filters = []): array {
		$transactions = $this->objectService
			->setRegister($this->register())
			->setSchema('Transaction')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		return array_values(
			array_filter(
				$transactions,
				static function (array $row) use ($filters): bool {
					if (isset($filters['status']) === true && $filters['status'] !== ''
						&& (string)($row['status'] ?? '') !== (string)$filters['status']
					) {
						return false;
					}

					if (isset($filters['type']) === true && $filters['type'] !== ''
						&& (string)($row['transactionType'] ?? '') !== (string)$filters['type']
					) {
						return false;
					}

					$date = (string)($row['transactionDate'] ?? '');
					if (isset($filters['dateFrom']) === true && $filters['dateFrom'] !== '' && $date < (string)$filters['dateFrom']) {
						return false;
					}

					if (isset($filters['dateTo']) === true && $filters['dateTo'] !== '' && $date > (string)$filters['dateTo']) {
						return false;
					}

					return true;
				}
			)
		);

	}//end listTransactions()

	/**
	 * Fetch a single transaction by id within an administration scope.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Transaction id or transactionNumber.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getTransaction(string $administrationId, string $transactionId): ?array {
		$transactions = $this->listTransactions(administrationId: $administrationId);
		foreach ($transactions as $row) {
			$id = (string)($row['id'] ?? '');
			$number = (string)($row['transactionNumber'] ?? '');
			if ($id === $transactionId || $number === $transactionId) {
				return $row;
			}
		}

		return null;
	}//end getTransaction()

	/**
	 * Create a draft transaction (REQ-WBSO-002).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $payload Transaction fields.
	 *
	 * @return array<string,mixed> Persisted record.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 */
	public function createTransaction(string $administrationId, array $payload): array {
		$payload['administrationId'] = $administrationId;
		$payload['status'] = 'draft';
		$payload['createdAt'] = $payload['createdAt'] ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$payload['createdBy'] = $payload['createdBy'] ?? $this->currentUserId();

		$this->validateTransactionPayload(payload: $payload);


		// ADR-084: saveObject() returns an ObjectEntityInterface, not the array
		// this method declares — returning it raised a TypeError on every call.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Transaction')
			->saveObject($payload)
			->jsonSerialize();

	}//end createTransaction()

	/**
	 * Post a draft transaction (REQ-WBSO-008 Posting).
	 *
	 * GL line-item posting is deferred to tier-2; this method only flips the
	 * state to `posted` so the audit trail and immutability rules engage.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Transaction to post.
	 *
	 * @return array<string,mixed> The posted record.
	 *
	 * @throws InvalidArgumentException|RuntimeException
	 */
	public function postTransaction(string $administrationId, string $transactionId): array {
		$existing = $this->getTransaction(administrationId: $administrationId, transactionId: $transactionId);
		if ($existing === null) {
			throw new InvalidArgumentException('Transaction not found');
		}

		if ((string)($existing['status'] ?? '') !== 'draft') {
			throw new RuntimeException('Transaction must be in draft state to post');
		}

		$existing['status'] = 'posted';
		$existing['postedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);


		// ADR-084: see createTransaction() — the contract returns an entity.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Transaction')
			->saveObject($existing)
			->jsonSerialize();

	}//end postTransaction()

	/**
	 * Reverse a posted transaction (REQ-WBSO-008 Reversal).
	 *
	 * Creates a new Transaction record with `status=reversed`, the same
	 * amount (the audit trail records that this is a counter-entry), a
	 * description prefixed by `"Reversal of "`, and `reversalOfTransactionId`
	 * pointing to the original. The original record remains untouched in
	 * `posted` per REQ-WBSO-004 (immutability).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Original transaction id.
	 * @param string $reason Free-text reason captured at reverse-time.
	 *
	 * @return array<string,mixed> The newly-created reversal record.
	 *
	 * @throws InvalidArgumentException|RuntimeException
	 */
	public function reverseTransaction(string $administrationId, string $transactionId, string $reason): array {
		$existing = $this->getTransaction(administrationId: $administrationId, transactionId: $transactionId);
		if ($existing === null) {
			throw new InvalidArgumentException('Transaction not found');
		}

		if ((string)($existing['status'] ?? '') !== 'posted') {
			throw new RuntimeException('Only posted transactions can be reversed');
		}

		if (trim($reason) === '') {
			throw new InvalidArgumentException('Reversal reason is required');
		}

		$reversal = [
			'transactionNumber' => (string)$existing['transactionNumber'] . '-REV',
			'transactionType' => 'credit-note',
			'transactionDate' => (new DateTimeImmutable())->format('Y-m-d'),
			'amount' => (float)($existing['amount'] ?? 0.0),
			'description' => 'Reversal of ' . ((string)($existing['description'] ?? '')),
			'status' => 'reversed',
			'administrationId' => $administrationId,
			'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'createdBy' => $this->currentUserId(),
			'reversalOfTransactionId' => (string)($existing['id'] ?? $existing['transactionNumber']),
			'reversalReason' => $reason,
		];


		// ADR-084: see createTransaction() — the contract returns an entity.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Transaction')
			->saveObject($reversal)
			->jsonSerialize();

	}//end reverseTransaction()

	/**
	 * Validate the create payload (REQ-WBSO-002).
	 *
	 * @param array<string,mixed> $payload Transaction fields.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 */
	public function validateTransactionPayload(array $payload): void {
		$required = ['transactionNumber', 'transactionType', 'transactionDate', 'amount', 'description', 'administrationId'];
		foreach ($required as $field) {
			if (isset($payload[$field]) === false || $payload[$field] === '') {
				throw new InvalidArgumentException(sprintf('%s is required', $field));
			}
		}

		if (in_array((string)$payload['transactionType'], self::ALLOWED_TYPES, true) === false) {
			throw new InvalidArgumentException('transactionType must be one of: ' . implode(', ', self::ALLOWED_TYPES));
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$payload['transactionDate']) !== 1) {
			throw new InvalidArgumentException('transactionDate must be ISO-8601 (YYYY-MM-DD)');
		}

		$amount = $payload['amount'];
		if (is_numeric($amount) === false || (float)$amount < 0.0) {
			throw new InvalidArgumentException('amount must be a non-negative number');
		}

		// Enforce two-decimal precision.
		if (round((float)$amount, 2) !== (float)$amount) {
			throw new InvalidArgumentException('amount must be rounded to two decimals');
		}

	}//end validateTransactionPayload()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Resolve the active Nextcloud user id, or 'system' when unauthenticated.
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserId()
}//end class
