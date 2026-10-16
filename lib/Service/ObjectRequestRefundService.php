<?php

/**
 * Object Request Refund Service
 *
 * Finance's half of a refund another app asked for. The refund command
 * (ObjectRequestCommandService::refund()) leaves the request in
 * `refund_requested` with a refund entry in state `requested`. A finance user
 * then approves it, which reverses the income into the refunds payable
 * account (`paymentRefundAccount`), and after paying it by bank marks it paid
 * with the bank reference, which clears refunds payable against the bank's
 * ledger account and moves the request to `refunded`. The asking app reads
 * `refunded` from the object event.
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
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
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
use Throwable;

/**
 * Approves and pays a requested refund, with its two bookings.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
 */
class ObjectRequestRefundService {

	/**
	 * App config key: the refunds payable account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'paymentRefundAccount';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface        $objectService   OpenRegister's object service (ADR-083).
	 * @param IAppConfig                    $appConfig       The register slug and the refunds payable account.
	 * @param ObjectTransitionRunner        $transitions     Posts the journal entries.
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
	 * Approve the open refund: reverse the income into refunds payable.
	 *
	 * @param string $paymentRequestId The request's uuid.
	 *
	 * @return array<string, mixed> The request, its refund now `approved`.
	 *
	 * @throws InvalidArgumentException When there is no requested refund, or an account is missing.
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function approve(string $paymentRequestId): array {
		$request = $this->find(id: $paymentRequestId);
		$index = $this->refundIn(request: $request, state: 'requested');
		$refundAccount = $this->refundAccount();
		$revenue = trim((string)($request['revenueAccount'] ?? ''));
		if ($revenue === '') {
			$revenue = (string)($this->revenueAccounts->resolve(requestType: (string)($request['requestType'] ?? '')) ?? '');
		}

		if ($revenue === '') {
			throw new InvalidArgumentException(
				sprintf(
					'No revenue account is mapped for request type "%s", so the income cannot be reversed.',
					(string)($request['requestType'] ?? '')
				)
			);
		}

		$this->book(
			request: $request,
			prefix: 'RF',
			debit: $revenue,
			credit: $refundAccount,
			amount: (float)$request['refunds'][$index]['amount'],
			description: sprintf('Refund approved for payment request %s', $this->label(request: $request))
		);

		$request['refunds'][$index]['state'] = 'approved';
		$this->save(schema: 'PaymentRequest', object: $request);

		return $request;
	}//end approve()

	/**
	 * Mark the approved refund paid: clear refunds payable against the bank.
	 *
	 * @param string $paymentRequestId The request's uuid.
	 * @param string $bankReference    The reference of the bank payment.
	 * @param string $bankAccount      The ledger account of the bank it was paid from.
	 *
	 * @return array<string, mixed> The request, now `refunded`.
	 *
	 * @throws InvalidArgumentException When there is no approved refund, or a value is missing.
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function markPaid(string $paymentRequestId, string $bankReference, string $bankAccount): array {
		$bankReference = trim($bankReference);
		$bankAccount = trim($bankAccount);
		if ($bankReference === '' || $bankAccount === '') {
			throw new InvalidArgumentException('A paid refund needs the bank reference and the bank account it was paid from.');
		}

		$request = $this->find(id: $paymentRequestId);
		$index = $this->refundIn(request: $request, state: 'approved');
		$this->book(
			request: $request,
			prefix: 'RP',
			debit: $this->refundAccount(),
			credit: $bankAccount,
			amount: (float)$request['refunds'][$index]['amount'],
			description: sprintf('Refund paid for payment request %s (%s)', $this->label(request: $request), $bankReference)
		);

		$request['refunds'][$index]['state'] = 'paid';
		$request['refunds'][$index]['bankReference'] = $bankReference;
		$request['state'] = 'refunded';
		$this->save(schema: 'PaymentRequest', object: $request);

		return $request;
	}//end markPaid()

	/**
	 * The position of the newest refund, which must be in the given state.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string               $state   The state the refund must be in.
	 *
	 * @return int The position in `refunds`.
	 *
	 * @throws InvalidArgumentException When the request does not wait for that step.
	 */
	private function refundIn(array $request, string $state): int {
		$refunds = $request['refunds'] ?? [];
		if (($request['state'] ?? '') !== 'refund_requested' || is_array($refunds) === false || $refunds === []) {
			throw new InvalidArgumentException('This payment request is not waiting for a refund.');
		}

		$index = (count($refunds) - 1);
		if (($refunds[$index]['state'] ?? '') !== $state) {
			throw new InvalidArgumentException(
				sprintf('The refund is %s, so it cannot take this step; it must be %s first.', (string)($refunds[$index]['state'] ?? 'unknown'), $state)
			);
		}

		if ((float)($refunds[$index]['amount'] ?? 0) <= 0.0) {
			throw new InvalidArgumentException('The refund carries no amount above zero, so there is nothing to book.');
		}

		return $index;
	}//end refundIn()

	/**
	 * The refunds payable account from app config.
	 *
	 * @return string The account number.
	 *
	 * @throws InvalidArgumentException When it is not set.
	 */
	private function refundAccount(): string {
		$account = trim($this->appConfig->getValueString('shillinq', self::CONFIG_KEY, ''));
		if ($account === '') {
			throw new InvalidArgumentException('No refunds payable account is set; add it to ' . self::CONFIG_KEY . ' and try again.');
		}

		return $account;
	}//end refundAccount()

	/**
	 * Post one balanced journal entry for the request.
	 *
	 * @param array<string, mixed> $request     The request.
	 * @param string               $prefix      The journal number prefix.
	 * @param string               $debit       The account debited.
	 * @param string               $credit      The account credited.
	 * @param float                $amount      The amount.
	 * @param string               $description The posting text.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 */
	private function book(array $request, string $prefix, string $debit, string $credit, float $amount, string $description): void {
		$amount = round($amount, 2);
		$journalId = $this->save(
			schema: 'JournalEntry',
			object: [
				'journalNumber' => $prefix . '-' . substr(hash('sha256', (string)($request['id'] ?? '')), 0, 12),
				'entryDate' => gmdate('Y-m-d'),
				'description' => $description,
				'journalType' => 'manual',
				'approvalState' => 'not-required',
				'administrationId' => (string)($request['administrationId'] ?? ''),
				'state' => 'draft',
				'lines' => [
					['accountNumber' => $debit, 'side' => 'debit', 'amount' => $amount, 'description' => $description],
					['accountNumber' => $credit, 'side' => 'credit', 'amount' => $amount, 'description' => $description],
				],
			]
		);
		$this->transitions->run(objectId: $journalId, action: 'postDirect');
	}//end book()

	/**
	 * How the request is named in a posting text.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return string Its payment reference, else its id.
	 */
	private function label(array $request): string {
		$reference = trim((string)($request['paymentReference'] ?? ''));
		if ($reference !== '') {
			return $reference;
		}

		return (string)($request['id'] ?? '');
	}//end label()

	/**
	 * Read one PaymentRequest as the system.
	 *
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed> The request with its id.
	 *
	 * @throws InvalidArgumentException When no request has that id.
	 */
	private function find(string $id): array {
		$record = null;
		if ($id !== '') {
			try {
				$found = $this->objectService
					->setRegister($this->registerSlug())
					->setSchema('PaymentRequest')
					->find($id, _rbac: false, _multitenancy: false);
				$record = ObjectIdentifier::recordWithId(candidate: $found);
			} catch (Throwable $e) {
				$record = null;
			}
		}

		if ($record === null || (string)($record['id'] ?? '') === '') {
			throw new InvalidArgumentException('No payment request with that id exists.');
		}

		return $record;
	}//end find()

	/**
	 * Save one object in shillinq's register and answer its uuid.
	 *
	 * @param string               $schema The schema slug.
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
			_multitenancy: false,
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
