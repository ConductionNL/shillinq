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
use OCA\Shillinq\Kernel\Contracts\DebtorCreditServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Books a credit and records it per debtor.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
 */
class DebtorCreditService implements DebtorCreditServiceInterface {

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

		$amount = $this->creditableAmount(request: $request);
		$accounts = ['credit' => $this->requiredCreditAccount(), 'revenue' => $this->requiredRevenueAccount(request: $request)];

		$journalId = $this->bookCreditKept(request: $request, accounts: $accounts, amount: $amount);
		$this->recordCredit(request: $request, key: $key, amount: $amount, journalId: $journalId);

		$request['state'] = 'credited';
		return $request;
	}//end credit()

	/**
	 * The request's amount, refused when it is not above zero.
	 *
	 * @param array<string, mixed> $request The settled request.
	 *
	 * @return float
	 *
	 * @throws InvalidArgumentException When the amount is not above zero.
	 */
	private function creditableAmount(array $request): float {
		$amount = round((float)($request['amount'] ?? 0), 2);
		if ($amount <= 0.0) {
			throw new InvalidArgumentException('This payment request carries no amount above zero, so there is nothing to keep as credit.');
		}

		return $amount;
	}//end creditableAmount()

	/**
	 * The customer credit account, refused when none is set.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When no customer credit account is set.
	 */
	private function requiredCreditAccount(): string {
		$creditAccount = trim($this->appConfig->getValueString('shillinq', self::CONFIG_KEY, ''));
		if ($creditAccount === '') {
			throw new InvalidArgumentException('No customer credit account is set; add it to paymentCreditAccount and ask again.');
		}

		return $creditAccount;
	}//end requiredCreditAccount()

	/**
	 * The request's revenue account, refused when none is mapped.
	 *
	 * @param array<string, mixed> $request The settled request.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When no revenue account is mapped for the request type.
	 */
	private function requiredRevenueAccount(array $request): string {
		$revenue = $this->revenueAccountFor(request: $request);
		if ($revenue === '') {
			throw new InvalidArgumentException(
				sprintf(
					'No revenue account is mapped for request type "%s", so the income cannot be moved to credit.',
					(string)($request['requestType'] ?? '')
				)
			);
		}

		return $revenue;
	}//end requiredRevenueAccount()

	/**
	 * The request's own revenue account, else the one mapped for its request type.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return string The account, or '' when none is known.
	 */
	private function revenueAccountFor(array $request): string {
		$revenue = trim((string)($request['revenueAccount'] ?? ''));
		if ($revenue === '') {
			$revenue = (string)($this->revenueAccounts->resolve(requestType: (string)($request['requestType'] ?? '')) ?? '');
		}

		return $revenue;
	}//end revenueAccountFor()

	/**
	 * Book the request's income onto the customer credit account.
	 *
	 * @param array<string, mixed>                  $request  The settled request.
	 * @param array{credit: string, revenue: string} $accounts The customer credit and revenue accounts.
	 * @param float                                 $amount   The amount kept as credit.
	 *
	 * @return string The posted JournalEntry id.
	 */
	private function bookCreditKept(array $request, array $accounts, float $amount): string {
		$requestId   = (string)($request['id'] ?? '');
		$description = sprintf('Credit kept from payment request %s', (string)($request['paymentReference'] ?? $requestId));

		return $this->postJournal(
			entry: $this->journalEntry(
				journalNumber: 'CR-' . substr(hash('sha256', $requestId), 0, 12),
				description: $description,
				administrationId: (string)($request['administrationId'] ?? ''),
				lines: [
					['accountNumber' => $accounts['revenue'], 'side' => 'debit', 'amount' => $amount, 'description' => $description],
					['accountNumber' => $accounts['credit'], 'side' => 'credit', 'amount' => $amount, 'description' => $description],
				]
			)
		);
	}//end bookCreditKept()

	/**
	 * Record the open DebtorCredit row for the debtor.
	 *
	 * @param array<string, mixed> $request   The settled request.
	 * @param string               $key       The debtor key.
	 * @param float                $amount    The amount kept as credit.
	 * @param string               $journalId The posted JournalEntry id.
	 *
	 * @return void
	 */
	private function recordCredit(array $request, string $key, float $amount, string $journalId): void {
		$this->save(
			schema: 'DebtorCredit',
			object: [
				'debtorKey' => $key,
				'amount' => $amount,
				'remaining' => $amount,
				'currency' => (string)($request['currency'] ?? 'EUR'),
				'sourcePaymentRequestId' => (string)($request['id'] ?? ''),
				'administrationId' => (string)($request['administrationId'] ?? ''),
				'journalEntryId' => $journalId,
				'state' => 'open',
			]
		);
	}//end recordCredit()

	/**
	 * A draft manual JournalEntry that needs no approval.
	 *
	 * @param string                           $journalNumber    The journal number.
	 * @param string                           $description      The entry and line description.
	 * @param string                           $administrationId The administration.
	 * @param list<array<string, mixed>>       $lines            The debit and credit lines.
	 *
	 * @return array<string, mixed>
	 */
	private function journalEntry(string $journalNumber, string $description, string $administrationId, array $lines): array {
		return [
			'journalNumber' => $journalNumber,
			'entryDate' => gmdate('Y-m-d'),
			'description' => $description,
			'journalType' => 'manual',
			'approvalState' => 'not-required',
			'administrationId' => $administrationId,
			'state' => 'draft',
			'lines' => $lines,
		];
	}//end journalEntry()

	/**
	 * Save a JournalEntry and post it directly.
	 *
	 * @param array<string, mixed> $entry The JournalEntry.
	 *
	 * @return string The JournalEntry id.
	 */
	private function postJournal(array $entry): string {
		$journalId = $this->save(schema: 'JournalEntry', object: $entry);
		$this->transitions->run(objectId: $journalId, action: 'postDirect');

		return $journalId;
	}//end postJournal()

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
		if ($key === '' || $due <= 0.0) {
			return $request;
		}

		return $this->applyCreditFor(request: $request, key: $key, due: $due, actor: $actor);
	}//end applyOpenCredit()

	/**
	 * Use the debtor's open credit when both accounts are known, and settle the request by what was used.
	 *
	 * @param array<string, mixed> $request The request about to be saved.
	 * @param string               $key     The debtor key.
	 * @param float                $due     The request's amount.
	 * @param string               $actor   Who raised it: a user id or `app:<appId>`.
	 *
	 * @return array<string, mixed> The request, with the credit settlement when credit was used.
	 */
	private function applyCreditFor(array $request, string $key, float $due, string $actor): array {
		$accounts = [
			'credit' => trim($this->appConfig->getValueString('shillinq', self::CONFIG_KEY, '')),
			'revenue' => $this->revenueAccountFor(request: $request),
		];
		if ($accounts['credit'] === '' || $accounts['revenue'] === '') {
			return $request;
		}

		$use = $this->useOpenCredits(request: $request, key: $key, due: $due, accounts: $accounts);
		if ($use['used'] <= 0.0) {
			return $request;
		}

		return $this->settleFromCredit(request: $request, used: $use['used'], sources: $use['sources'], actor: $actor);
	}//end applyCreditFor()

	/**
	 * Use open credit, oldest first, until the request is covered.
	 *
	 * @param array<string, mixed>                   $request  The request about to be saved.
	 * @param string                                 $key      The debtor key.
	 * @param float                                  $due      The request's amount.
	 * @param array{credit: string, revenue: string} $accounts The customer credit and revenue accounts.
	 *
	 * @return array{used: float, sources: list<string>} The amount used and the credits it came from.
	 */
	private function useOpenCredits(array $request, string $key, float $due, array $accounts): array {
		$used    = 0.0;
		$sources = [];
		foreach ($this->openCredits(debtorKey: $key, administrationId: (string)($request['administrationId'] ?? '')) as $credit) {
			$take = round(min((float)($credit['remaining'] ?? 0), ($due - $used)), 2);
			if ($take <= 0.0) {
				continue;
			}

			$this->useCredit(request: $request, credit: $credit, take: $take, accounts: $accounts);
			$used      = round(($used + $take), 2);
			$sources[] = (string)$credit['id'];
			if ($used >= $due) {
				break;
			}
		}

		return ['used' => $used, 'sources' => $sources];
	}//end useOpenCredits()

	/**
	 * Book one credit's share against the request's revenue and lower its remaining amount.
	 *
	 * @param array<string, mixed>                   $request  The request about to be saved.
	 * @param array<string, mixed>                   $credit   The DebtorCredit row.
	 * @param float                                  $take     The amount taken from this credit.
	 * @param array{credit: string, revenue: string} $accounts The customer credit and revenue accounts.
	 *
	 * @return void
	 */
	private function useCredit(array $request, array $credit, float $take, array $accounts): void {
		$description = sprintf('Open credit %s used for a new %s request', (string)$credit['id'], (string)($request['requestType'] ?? ''));
		$numberSeed  = (string)$credit['id'] . '|' . (string)($credit['remaining'] ?? '') . '|' . json_encode($request['subject'] ?? []);
		$entry       = $this->journalEntry(
			journalNumber: 'CU-' . substr(hash('sha256', $numberSeed), 0, 12),
			description: $description,
			administrationId: (string)($request['administrationId'] ?? ''),
			lines: [
				['accountNumber' => $accounts['credit'], 'side' => 'debit', 'amount' => $take, 'description' => $description],
				['accountNumber' => $accounts['revenue'], 'side' => 'credit', 'amount' => $take, 'description' => $description],
			]
		);
		$this->postJournal(entry: $entry);

		$this->save(schema: 'DebtorCredit', object: $this->lowerRemaining(credit: $credit, take: $take));
	}//end useCredit()

	/**
	 * Lower a credit's remaining amount; at zero it reads `used`.
	 *
	 * @param array<string, mixed> $credit The DebtorCredit row.
	 * @param float                $take   The amount taken from it.
	 *
	 * @return array<string, mixed>
	 */
	private function lowerRemaining(array $credit, float $take): array {
		$credit['remaining'] = round(((float)$credit['remaining'] - $take), 2);
		if ($credit['remaining'] <= 0.0) {
			$credit['remaining'] = 0.0;
			$credit['state']     = 'used';
		}

		return $credit;
	}//end lowerRemaining()

	/**
	 * Append the credit settlement and stamp the request settled when the credit covers it whole.
	 *
	 * @param array<string, mixed> $request The request about to be saved.
	 * @param float                $used    The credit used.
	 * @param list<string>         $sources The credits it came from.
	 * @param string               $actor   Who raised it: a user id or `app:<appId>`.
	 *
	 * @return array<string, mixed>
	 */
	private function settleFromCredit(array $request, float $used, array $sources, string $actor): array {
		$now         = gmdate('Y-m-d\TH:i:s\Z');
		$settlements = new PaymentSettlementService();
		$settlement  = [
			'method' => 'credit',
			'amount' => $used,
			'reference' => implode(', ', $sources),
			'actor' => $actor,
			'settledAt' => $now,
			'reason' => 'Paid from the debtor\'s open credit',
		];
		$request     = $settlements->append(request: $request, settlement: $settlement);

		return $settlements->stampSettled(request: $request, settledAt: $now, via: 'credit');
	}//end settleFromCredit()

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
			->findAll(
				['filters' => ['debtorKey' => $debtorKey, 'administrationId' => $administrationId, 'state' => 'open']],
				_rbac: false,
				_multitenancy: false
			);

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
