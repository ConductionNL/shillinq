<?php

/**
 * Debtor Credit Service
 *
 * Keeps the money of a settled object request as credit for the payer, when
 * the app the request stands on asks for credit instead of a refund. The
 * income is reversed into the customer credit account at once, and a
 * DebtorCredit records how much is open for the payer: their customer record,
 * or without one their email address, so the next request can find it.
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
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Books a credit and records it per debtor.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
 */
class DebtorCreditService {

	/**
	 * App config key: the customer credit account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'paymentCreditAccount';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig The register slug and the credit account.
	 * @param ObjectTransitionRunner $transitions Posts the credit's journal entry.
	 * @param PaymentRevenueAccountResolver $revenueAccounts The revenue account of a request type.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ObjectTransitionRunner $transitions,
		private readonly PaymentRevenueAccountResolver $revenueAccounts,
	) {
	}//end __construct()

	/**
	 * Whose credit it is: the customer record, else the email in lower case.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return string The key, or '' when the debtor cannot be recognised again.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
	 */
	public static function debtorKey(array $request): string {
		$debtor = $request['debtor'] ?? [];
		if (is_array($debtor) === false) {
			return '';
		}

		$customer = trim((string)($debtor['customerMasterId'] ?? ($request['customerId'] ?? '')));
		if ($customer !== '') {
			return $customer;
		}

		$email = strtolower(trim((string)($debtor['email'] ?? '')));
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return '';
		}

		return $email;
	}//end debtorKey()

	/**
	 * Book the request's amount as credit and record it for the debtor.
	 *
	 * @param array<string, mixed> $request The settled request.
	 *
	 * @return array<string, mixed> The request, moved to `credited`.
	 *
	 * @throws InvalidArgumentException When the debtor cannot be recognised, or no account is set.
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
	 */
	public function credit(array $request): array {
		$key = self::debtorKey(request: $request);
		if ($key === '') {
			throw new InvalidArgumentException('This debtor cannot be recognised again, so credit could never be used.');
		}

		$amount = round((float)($request['amount'] ?? 0), 2);
		if ($amount <= 0.0) {
			throw new InvalidArgumentException('This payment request carries no amount above zero, so there is nothing to keep as credit.');
		}

		$creditAccount = trim($this->appConfig->getValueString('shillinq', self::CONFIG_KEY, ''));
		if ($creditAccount === '') {
			throw new InvalidArgumentException('No customer credit account is set; add it to paymentCreditAccount and ask again.');
		}

		$revenue = trim((string)($request['revenueAccount'] ?? ''));
		if ($revenue === '') {
			$revenue = (string)($this->revenueAccounts->resolve(requestType: (string)($request['requestType'] ?? '')) ?? '');
		}

		if ($revenue === '') {
			throw new InvalidArgumentException(
				sprintf(
					'No revenue account is mapped for request type "%s", so the income cannot be moved to credit.',
					(string)($request['requestType'] ?? '')
				)
			);
		}

		$requestId = (string)($request['id'] ?? '');
		$administrationId = (string)($request['administrationId'] ?? '');
		$description = sprintf('Credit kept from payment request %s', (string)($request['paymentReference'] ?? $requestId));

		$journalId = $this->save(
			schema: 'JournalEntry',
			object: [
				'journalNumber' => 'CR-' . substr(hash('sha256', $requestId), 0, 12),
				'entryDate' => gmdate('Y-m-d'),
				'description' => $description,
				'journalType' => 'manual',
				'approvalState' => 'not-required',
				'administrationId' => $administrationId,
				'state' => 'draft',
				'lines' => [
					['accountNumber' => $revenue, 'side' => 'debit', 'amount' => $amount, 'description' => $description],
					['accountNumber' => $creditAccount, 'side' => 'credit', 'amount' => $amount, 'description' => $description],
				],
			]
		);
		$this->transitions->run(objectId: $journalId, action: 'postDirect');

		$this->save(
			schema: 'DebtorCredit',
			object: [
				'debtorKey' => $key,
				'amount' => $amount,
				'remaining' => $amount,
				'currency' => (string)($request['currency'] ?? 'EUR'),
				'sourcePaymentRequestId' => $requestId,
				'administrationId' => $administrationId,
				'journalEntryId' => $journalId,
				'state' => 'open',
			]
		);

		$request['state'] = 'credited';
		return $request;
	}//end credit()

	/**
	 * Pay a new request from the debtor's open credit first, oldest credit first (REQ-ORC-004).
	 *
	 * Called by the leaf after the request is validated and before it is saved.
	 * Each credit used lowers its `remaining` (and reads `used` at zero) and is
	 * booked debit the customer credit account, credit the new request's
	 * revenue account. The request gets one settlement with method `credit` and
	 * is stamped settled when the credit covers it whole. When the credit
	 * cannot be booked (no customer credit account, no revenue account for the
	 * request type) the credit stays open and the request stays payable: the
	 * debtor pays as usual and keeps the credit.
	 *
	 * @param array<string, mixed> $request The request about to be saved.
	 * @param string               $actor   Who raised it: a user id or `app:<appId>`.
	 *
	 * @return array<string, mixed> The request, with the credit settlement when credit was used.
	 *
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-004)
	 */
	public function applyOpenCredit(array $request, string $actor): array {
		$key = self::debtorKey(request: $request);
		$due = round((float)($request['amount'] ?? 0), 2);
		$administrationId = (string)($request['administrationId'] ?? '');
		if ($key === '' || $due <= 0.0) {
			return $request;
		}

		$creditAccount = trim($this->appConfig->getValueString('shillinq', self::CONFIG_KEY, ''));
		$revenue = trim((string)($request['revenueAccount'] ?? ''));
		if ($revenue === '') {
			$revenue = (string)($this->revenueAccounts->resolve(requestType: (string)($request['requestType'] ?? '')) ?? '');
		}

		if ($creditAccount === '' || $revenue === '') {
			return $request;
		}

		$used = 0.0;
		$sources = [];
		foreach ($this->openCredits(debtorKey: $key, administrationId: $administrationId) as $credit) {
			$take = round(min((float)($credit['remaining'] ?? 0), ($due - $used)), 2);
			if ($take <= 0.0) {
				continue;
			}

			$description = sprintf('Open credit %s used for a new %s request', (string)$credit['id'], (string)($request['requestType'] ?? ''));
			$journalId = $this->save(
				schema: 'JournalEntry',
				object: [
					'journalNumber' => 'CU-' . substr(hash('sha256', (string)$credit['id'] . '|' . (string)($credit['remaining'] ?? '') . '|' . json_encode($request['subject'] ?? [])), 0, 12),
					'entryDate' => gmdate('Y-m-d'),
					'description' => $description,
					'journalType' => 'manual',
					'approvalState' => 'not-required',
					'administrationId' => $administrationId,
					'state' => 'draft',
					'lines' => [
						['accountNumber' => $creditAccount, 'side' => 'debit', 'amount' => $take, 'description' => $description],
						['accountNumber' => $revenue, 'side' => 'credit', 'amount' => $take, 'description' => $description],
					],
				]
			);
			$this->transitions->run(objectId: $journalId, action: 'postDirect');

			$credit['remaining'] = round(((float)$credit['remaining'] - $take), 2);
			if ($credit['remaining'] <= 0.0) {
				$credit['remaining'] = 0.0;
				$credit['state'] = 'used';
			}

			$this->save(schema: 'DebtorCredit', object: $credit);
			$used = round(($used + $take), 2);
			$sources[] = (string)$credit['id'];
			if ($used >= $due) {
				break;
			}
		}//end foreach

		if ($used <= 0.0) {
			return $request;
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');
		$settlements = new PaymentSettlementService();
		$request = $settlements->append(
			request: $request,
			settlement: [
				'method' => 'credit',
				'amount' => $used,
				'reference' => implode(', ', $sources),
				'actor' => $actor,
				'settledAt' => $now,
				'reason' => 'Paid from the debtor\'s open credit',
			]
		);

		return $settlements->stampSettled(request: $request, settledAt: $now, via: 'credit');
	}//end applyOpenCredit()

	/**
	 * The debtor's open credit in one administration, oldest first.
	 *
	 * @param string $debtorKey        The debtor key.
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>> The credit rows, without OpenRegister's metadata.
	 */
	private function openCredits(string $debtorKey, string $administrationId): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema('DebtorCredit')
			->findAll(['filters' => ['debtorKey' => $debtorKey, 'administrationId' => $administrationId, 'state' => 'open']], _rbac: false, _multitenancy: false);

		$credits = [];
		foreach ($rows as $position => $row) {
			$credit = ObjectIdentifier::recordWithId(candidate: $row);
			if ($credit === null || (string)($credit['id'] ?? '') === '') {
				continue;
			}

			$created = '';
			if (is_array($credit['@self'] ?? null) === true) {
				$created = (string)($credit['@self']['created'] ?? '');
			}

			unset($credit['@self']);
			$credits[] = ['created' => $created, 'position' => $position, 'credit' => $credit];
		}

		usort(
			$credits,
			static fn (array $left, array $right): int => [$left['created'], $left['position']] <=> [$right['created'], $right['position']]
		);

		return array_column($credits, 'credit');
	}//end openCredits()

	/**
	 * Save one object in shillinq's register and answer its uuid.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return string The uuid.
	 *
	 * @throws RuntimeException When OpenRegister answers without an id.
	 */
	private function save(string $schema, array $object): string {
		$saved = $this->objectService->saveObject(
			object: $object,
			register: $this->registerSlug(),
			schema: $schema,
			_rbac: false,
		);

		$uuid = ObjectIdentifier::resolve(saved: $saved);
		if ($uuid === '') {
			throw new RuntimeException(sprintf('The %s was saved but OpenRegister answered without an id.', $schema));
		}

		return $uuid;
	}//end save()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
