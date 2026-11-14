<?php

/**
 * Capture Booking Service
 *
 * Books the capture of a provider payment that settled an invoice: debit the
 * provider clearing account, credit the debtors control account (design D3,
 * posting 1). Without it a paid invoice leaves debtors standing in the ledger
 * and the clearing account never holds what a payout later takes out of it.
 *
 * One journal entry per provider payment, numbered `CA-<payment id>`, so a
 * replayed capture or a run of `occ shillinq:payments:book-captures` never
 * books a capture twice.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts clearing-to-debtors for a captured, invoice-backed payment request.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 */
class CaptureBookingService {

	/**
	 * App-config key of the debtors control account; the one the AR posting reads.
	 *
	 * @var string
	 */
	public const CFG_DEBTORS_ACCOUNT = 'ledger_ar_control_account';

	/**
	 * The debtors control account when none is configured (RGS 1100 Debiteuren).
	 *
	 * @var string
	 */
	public const DEFAULT_DEBTORS_ACCOUNT = '1100';

	/**
	 * Journal number prefix of a capture booking.
	 *
	 * @var string
	 */
	public const JOURNAL_PREFIX = 'CA-';

	/**
	 * The most captured requests read by the listing.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService OpenRegister's object service.
	 * @param IAppConfig                      $appConfig     App config for the accounts.
	 * @param ObjectTransitionRunnerInterface $transitions   Posts the journal entry.
	 * @param LoggerInterface                 $logger        Logger.
	 * @param PaymentRevenueAccountResolver|null $revenueAccounts Resolves the clearing account; built from app config when absent.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly LoggerInterface $logger,
		private readonly ?PaymentRevenueAccountResolver $revenueAccounts = null,
	) {
	}//end __construct()

	/**
	 * The journal entry that books one capture, or null when there is nothing to book.
	 *
	 * @param array<string, mixed> $request The captured PaymentRequest.
	 * @param array<string, mixed> $invoice The ARInvoice it paid.
	 *
	 * @return array<string, mixed>|null A draft JournalEntry: debit clearing, credit debtors, for the captured amount.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
	 */
	public function journal(array $request, array $invoice): ?array {
		$paymentId = $this->paymentId(request: $request);
		$amount = round((float)($request['amount'] ?? 0), 2);
		if ($paymentId === '' || $amount <= 0.0) {
			return null;
		}

		$number = (string)($invoice['invoiceNumber'] ?? ($request['invoiceReference'] ?? ''));
		$memo = sprintf('Capture %s of invoice %s', $paymentId, $number);
		$capturedAt = (string)($request['capturedAt'] ?? '');
		$administrationId = (string)($request['administrationId'] ?? ($invoice['administrationId'] ?? ''));

		return [
			'journalNumber' => self::JOURNAL_PREFIX . $paymentId,
			'entryDate' => substr($capturedAt, 0, 10),
			'description' => $memo,
			'journalType' => 'manual',
			'approvalState' => 'not-required',
			'administrationId' => $administrationId,
			'state' => 'draft',
			'lines' => [
				['accountNumber' => $this->clearingAccount(), 'side' => 'debit', 'amount' => $amount, 'description' => $memo],
				['accountNumber' => $this->debtorsAccount(), 'side' => 'credit', 'amount' => $amount, 'description' => $memo],
			],
		];
	}//end journal()

	/**
	 * Book one capture, unless it is already booked.
	 *
	 * Fail-soft: a capture that cannot be booked is logged and answers null; the
	 * invoice it paid stays paid.
	 *
	 * @param string               $registerSlug The register.
	 * @param array<string, mixed> $request      The captured PaymentRequest.
	 * @param array<string, mixed> $invoice      The ARInvoice it paid.
	 *
	 * @return string|null The journal entry id, or null when nothing was booked.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
	 */
	public function book(string $registerSlug, array $request, array $invoice): ?string {
		$journal = $this->journal(request: $request, invoice: $invoice);
		if ($journal === null) {
			return null;
		}

		try {
			$existing = $this->bookedJournalId(registerSlug: $registerSlug, journalNumber: (string)$journal['journalNumber']);
			if ($existing !== null) {
				return $existing;
			}

			$saved = $this->objectService->saveObject(object: $journal, register: $registerSlug, schema: 'JournalEntry', _rbac: false);
			$journalId = ObjectIdentifier::resolve(saved: $saved);
			if ($journalId === '') {
				$this->logger->warning('CaptureBookingService: the capture journal entry was saved without an id');
				return null;
			}

			$this->transitions->run(objectId: $journalId, action: 'postDirect');
		} catch (Throwable $e) {
			$this->logger->error(
				'CaptureBookingService: the capture of a paid invoice could not be booked',
				['journalNumber' => $journal['journalNumber'], 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

		return $journalId;
	}//end book()

	/**
	 * Captured, invoice-backed requests whose capture has no booking yet.
	 *
	 * For invoices paid through a provider before captures were booked.
	 *
	 * @param string $registerSlug The register.
	 *
	 * @return list<array{requestId: string, paymentId: string, invoiceReference: string, amount: float, capturedAt: string}>
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
	 */
	public function unbooked(string $registerSlug): array {
		$rows = $this->objectService
			->setRegister($registerSlug)
			->setSchema('PaymentRequest')
			->findAll(['filters' => ['state' => 'captured'], 'limit' => self::READ_LIMIT]);

		$unbooked = [];
		foreach ($rows as $row) {
			$request = ObjectIdentifier::recordWithId(candidate: $row);
			if ($request === null || (string)($request['state'] ?? '') !== 'captured') {
				continue;
			}

			$invoiceReference = trim((string)($request['invoiceReference'] ?? ''));
			$paymentId = $this->paymentId(request: $request);
			$amount = round((float)($request['amount'] ?? 0), 2);
			if ($invoiceReference === '' || $paymentId === '' || $amount <= 0.0) {
				continue;
			}

			if ($this->bookedJournalId(registerSlug: $registerSlug, journalNumber: self::JOURNAL_PREFIX . $paymentId) !== null) {
				continue;
			}

			$unbooked[] = [
				'requestId' => (string)($request['id'] ?? ''),
				'paymentId' => $paymentId,
				'invoiceReference' => $invoiceReference,
				'amount' => $amount,
				'capturedAt' => substr((string)($request['capturedAt'] ?? ''), 0, 10),
			];
		}//end foreach

		usort($unbooked, static fn (array $a, array $b): int => [$a['capturedAt'], $a['paymentId']] <=> [$b['capturedAt'], $b['paymentId']]);

		return $unbooked;
	}//end unbooked()

	/**
	 * Book the capture of one listed request, reading the request and its invoice.
	 *
	 * @param string $registerSlug The register.
	 * @param string $requestId    The PaymentRequest id.
	 *
	 * @return string|null The journal entry id, or null when nothing was booked.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
	 */
	public function bookRequest(string $registerSlug, string $requestId): ?string {
		$request = ObjectIdentifier::findOne(
			scoped: $this->objectService->setRegister($registerSlug)->setSchema('PaymentRequest'),
			id: $requestId,
			scope: ['_rbac' => false]
		);
		if ($request === null || trim((string)($request['invoiceReference'] ?? '')) === '') {
			return null;
		}

		$invoice = ObjectIdentifier::findOne(
			scoped: $this->objectService->setRegister($registerSlug)->setSchema('ARInvoice'),
			id: trim((string)$request['invoiceReference']),
			scope: ['_rbac' => false]
		);

		return $this->book(registerSlug: $registerSlug, request: $request, invoice: ($invoice ?? []));
	}//end bookRequest()

	/**
	 * The id of the journal entry already carrying a journal number.
	 *
	 * @param string $registerSlug  The register.
	 * @param string $journalNumber The journal number.
	 *
	 * @return string|null
	 */
	private function bookedJournalId(string $registerSlug, string $journalNumber): ?string {
		$rows = $this->objectService
			->setRegister($registerSlug)
			->setSchema('JournalEntry')
			->findAll(['filters' => ['journalNumber' => $journalNumber], 'limit' => 5]);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			// The filter is re-checked: a filter the query layer ignores must not mark every capture booked.
			if ($record !== null && (string)($record['journalNumber'] ?? '') === $journalNumber) {
				return (string)($record['id'] ?? '');
			}
		}

		return null;
	}//end bookedJournalId()

	/**
	 * The provider's id of the captured payment.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 *
	 * @return string
	 */
	private function paymentId(array $request): string {
		$id = trim((string)($request['providerPaymentId'] ?? ''));
		if ($id === '') {
			$id = trim((string)($request['paymentIntentId'] ?? ''));
		}

		return $id;
	}//end paymentId()

	/**
	 * The provider clearing account the capture is booked to.
	 *
	 * @return string
	 */
	private function clearingAccount(): string {
		$resolver = ($this->revenueAccounts ?? new PaymentRevenueAccountResolver(appConfig: $this->appConfig));
		return ($resolver->resolve(requestType: 'clearing') ?? 'clearing');
	}//end clearingAccount()

	/**
	 * The debtors control account.
	 *
	 * @return string
	 */
	private function debtorsAccount(): string {
		$configured = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::CFG_DEBTORS_ACCOUNT, ''));
		if ($configured !== '') {
			return $configured;
		}

		return self::DEFAULT_DEBTORS_ACCOUNT;
	}//end debtorsAccount()
}//end class
