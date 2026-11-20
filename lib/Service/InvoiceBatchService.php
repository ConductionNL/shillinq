<?php

/**
 * Shillinq Invoice Batch Service
 *
 * Creates one sales invoice per selected customer from one set of lines
 * (REQ-SIS-004). The call is checked whole before anything is written; each
 * invoice's due date is the invoice date plus that customer's
 * `paymentTermDays` unless the batch names a due date. Every invoice of a
 * batch carries a number derived from the batch and the customer, so a batch
 * submitted again after a dropped connection finds the invoices it already
 * wrote and writes only the missing ones. The created invoices may be issued
 * at once and handed to the send batch (REQ-SIS-003), which sends each by its
 * customer's delivery method.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\InvoiceBatchServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one invoice per customer of a batch, idempotent per batch and customer.
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-batch-creates-one-invoice-per-customer-req-sis-004
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One call validated whole, then written per customer.
 */
class InvoiceBatchService implements InvoiceBatchServiceInterface {

	/**
	 * The most customers one batch bills.
	 */
	public const MAX_CUSTOMERS = 200;

	/**
	 * The invoice was written by this call.
	 */
	public const OUTCOME_CREATED = 'created';

	/**
	 * The invoice was already written by an earlier submission of the batch.
	 */
	public const OUTCOME_EXISTING = 'existing';

	/**
	 * No invoice was written for the customer, for the reason given.
	 */
	public const OUTCOME_SKIPPED = 'skipped';

	/**
	 * Writing the customer's invoice failed.
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * The payment term when a customer carries none (the schema default).
	 */
	private const DEFAULT_TERM_DAYS = 30;

	/**
	 * The shape of a batch reference and an administration id.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService         OpenRegister's object service.
	 * @param AdministrationContextService $administrationContext Whether the user may act on the administration.
	 * @param InvoiceBatchSendService      $sendBatches           Queues the send of the created invoices.
	 * @param IAppConfig                   $appConfig             The register slug.
	 * @param IL10N                        $l10n                  The reasons.
	 * @param LoggerInterface              $logger                An invoice that could not be written.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
		private readonly InvoiceBatchSendService $sendBatches,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create one invoice per customer of the batch.
	 *
	 * The request: `batchId` (optional; the caller sends the same one again to
	 * retry), `customerIds` (1 to 200), `charge` (`invoiceDate`, optional
	 * `dueDate`, `lines` of `description`, `quantity`, `unitPrice`, `vatRate`),
	 * `issue` and `send`. Sending needs issuing.
	 *
	 * @param string               $administrationId The administration that bills.
	 * @param array<string, mixed> $request          The batch as the caller sent it.
	 * @param string               $requestedBy      The signed-in user, notified when a send is done.
	 *
	 * @return array<string, mixed> The InvoiceBatch as stored, with one outcome per customer.
	 *
	 * @throws OutOfBoundsException     When the user may not act on the administration (shown as not found).
	 * @throws InvalidArgumentException When the batch cannot be created as a whole.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	public function create(string $administrationId, array $request, string $requestedBy = ''): array {
		$this->assertAccessible(administrationId: $administrationId);

		$charge = self::normaliseCharge(charge: $request['charge'] ?? null);
		$customerIds = self::customerIds(request: $request);
		[$issue, $send] = self::issueAndSend(request: $request);

		$batch = $this->batchFor(
			administrationId: $administrationId,
			batchId: self::batchId(request: $request),
			fields: ['charge' => $charge, 'customerIds' => $customerIds, 'issue' => $issue, 'send' => $send, 'requestedBy' => $requestedBy]
		);

		$outcomes = [];
		foreach ($customerIds as $customerId) {
			$outcomes[] = $this->createOne(batch: $batch, customerId: $customerId);
		}

		$batch = $this->finish(batch: $batch, outcomes: $outcomes);
		if ($send === true && $batch['invoiceIds'] !== []) {
			$batch = $this->queueSending(batch: $batch, administrationId: $administrationId, requestedBy: $requestedBy);
		}

		return $batch;
	}//end create()

	/**
	 * Refuse an administration id that is malformed or not one of the caller's.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return void
	 *
	 * @throws OutOfBoundsException When the caller may not work in it.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private function assertAccessible(string $administrationId): void {
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1
			|| $this->administrationContext->canAccess(administrationId: $administrationId) === false
		) {
			throw new OutOfBoundsException('Administration not found');
		}
	}//end assertAccessible()

	/**
	 * Whether the batch issues and sends its invoices; sending needs issuing.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array{0: bool, 1: bool} Issue, send.
	 *
	 * @throws InvalidArgumentException When it asks to send without issuing.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private static function issueAndSend(array $request): array {
		$issue = (($request['issue'] ?? false) === true);
		$send = (($request['send'] ?? false) === true);
		if ($send === true && $issue === false) {
			throw new InvalidArgumentException('Only issued invoices can be sent: issue the invoices to send them.');
		}

		return [$issue, $send];
	}//end issueAndSend()

	/**
	 * Record the outcomes on the batch and save it as done.
	 *
	 * @param array<string, mixed>             $batch    The batch.
	 * @param list<array<string, mixed>>       $outcomes One outcome per customer.
	 *
	 * @return array<string, mixed> The saved batch.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private function finish(array $batch, array $outcomes): array {
		$batch['outcomes'] = $outcomes;
		$batch['invoiceIds'] = self::invoiceIdsOf(outcomes: $outcomes);
		$batch['state'] = 'done';

		return $this->save(batch: $batch);
	}//end finish()

	/**
	 * Queue the batch's invoices for sending and record the send batch on it.
	 *
	 * @param array<string, mixed> $batch            The saved batch.
	 * @param string               $administrationId The administration.
	 * @param string               $requestedBy      Who asked.
	 *
	 * @return array<string, mixed> The saved batch.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private function queueSending(array $batch, string $administrationId, string $requestedBy): array {
		$sendBatch = $this->sendBatches->queue(
			administrationId: $administrationId,
			invoiceIds: $batch['invoiceIds'],
			options: ['requestedBy' => $requestedBy]
		);
		$batch['sendBatchId'] = ObjectIdentifier::resolve(saved: $sendBatch);

		return $this->save(batch: $batch);
	}//end queueSending()

	/**
	 * The charge checked whole: an invoice date, an optional due date, and at
	 * least one line with a description and a price.
	 *
	 * @param mixed $charge The charge as given.
	 *
	 * @return array{invoiceDate: string, dueDate: string, lines: list<array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When the charge cannot be billed.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	public static function normaliseCharge(mixed $charge): array {
		if (is_array($charge) === false) {
			throw new InvalidArgumentException('A batch needs a charge: an invoice date and at least one line.');
		}

		[$invoiceDate, $dueDate] = self::chargeDates(charge: $charge);

		return ['invoiceDate' => $invoiceDate, 'dueDate' => $dueDate, 'lines' => self::chargeLines(charge: $charge)];
	}//end normaliseCharge()

	/**
	 * The charge's invoice date and optional due date, checked.
	 *
	 * @param array<string, mixed> $charge The charge.
	 *
	 * @return array{0: string, 1: string} Invoice date, due date ('' when none).
	 *
	 * @throws InvalidArgumentException When a date is invalid or the due date is before the invoice date.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private static function chargeDates(array $charge): array {
		$invoiceDate = trim((string)($charge['invoiceDate'] ?? ''));
		if (self::isDate(value: $invoiceDate) === false) {
			throw new InvalidArgumentException('Enter a valid invoice date.');
		}

		$dueDate = trim((string)($charge['dueDate'] ?? ''));
		if ($dueDate !== '' && (self::isDate(value: $dueDate) === false || $dueDate < $invoiceDate)) {
			throw new InvalidArgumentException('The due date is a date on or after the invoice date.');
		}

		return [$invoiceDate, $dueDate];
	}//end chargeDates()

	/**
	 * The charge's lines, numbered from 1; at least one.
	 *
	 * @param array<string, mixed> $charge The charge.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws InvalidArgumentException When no line can be billed.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private static function chargeLines(array $charge): array {
		$lines = [];
		foreach ((array)($charge['lines'] ?? []) as $line) {
			if (is_array($line) === true) {
				$lines[] = self::line(line: $line, lineId: (string)(count($lines) + 1));
			}
		}

		if ($lines === []) {
			throw new InvalidArgumentException('Add at least one line with a description and a price.');
		}

		return $lines;
	}//end chargeLines()

	/**
	 * The due date of one customer's invoice: the batch's own, else the invoice
	 * date plus the customer's payment term.
	 *
	 * @param array<string, mixed> $charge   The normalised charge.
	 * @param array<string, mixed> $customer The CustomerMaster.
	 *
	 * @return string The due date (Y-m-d).
	 *
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-customer-carries-a-payment-term-and-a-delivery-method-req-sis-005
	 */
	public static function dueDateFor(array $charge, array $customer): string {
		if ((string)($charge['dueDate'] ?? '') !== '') {
			return (string)$charge['dueDate'];
		}

		$days = $customer['paymentTermDays'] ?? null;
		if (is_numeric($days) === false || (int)$days < 0 || (int)$days > 365) {
			$days = self::DEFAULT_TERM_DAYS;
		}

		return (new DateTimeImmutable((string)$charge['invoiceDate'] . 'T00:00:00Z'))
			->modify('+' . (int)$days . ' days')
			->format('Y-m-d');
	}//end dueDateFor()

	/**
	 * The invoice number of one customer's invoice in a batch: the batch
	 * reference and a fixed part of the customer id, the same on every retry.
	 *
	 * @param string $batchId    The batch reference.
	 * @param string $customerId The CustomerMaster id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	public static function invoiceNumberFor(string $batchId, string $customerId): string {
		return $batchId . '-' . strtoupper(substr(hash('sha256', $customerId), 0, 8));
	}//end invoiceNumberFor()

	/**
	 * The ARInvoice one customer is billed with.
	 *
	 * @param array<string, mixed> $batch    The InvoiceBatch, with its normalised charge.
	 * @param array<string, mixed> $customer The CustomerMaster.
	 * @param string               $number   The invoice number.
	 *
	 * @return array<string, mixed> The ARInvoice payload.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	public static function buildInvoice(array $batch, array $customer, string $number): array {
		$charge = (array)$batch['charge'];
		$lifecycleState = 'draft';
		if (($batch['issue'] ?? false) === true) {
			$lifecycleState = 'issued';
		}

		return array_merge(
			[
				'invoiceNumber' => $number,
				'administrationId' => (string)$batch['administrationId'],
				'periodId' => substr((string)$charge['invoiceDate'], 0, 7),
				'customerId' => self::customerKey(customer: $customer),
				'invoiceDate' => (string)$charge['invoiceDate'],
				'dueDate' => self::dueDateFor(charge: $charge, customer: $customer),
				'currency' => 'EUR',
				'lifecycleState' => $lifecycleState,
				'invoiceType' => 'standard',
				'invoiceLines' => array_values((array)$charge['lines']),
			],
			self::totals(lines: (array)$charge['lines'])
		);
	}//end buildInvoice()

	/**
	 * Net, VAT and gross of the lines, VAT rounded once per rate.
	 *
	 * @param array<int, array<string, mixed>> $lines The normalised lines.
	 *
	 * @return array{netAmount: float, vatAmount: float, grossAmount: float}
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	public static function totals(array $lines): array {
		$netCents = 0;
		$perRate = [];
		foreach ($lines as $line) {
			$cents = (int)round(((float)$line['netAmount']) * 100);
			$netCents += $cents;
			$key = (string)$line['vatRate'];
			$perRate[$key] = (($perRate[$key] ?? 0) + $cents);
		}

		$vatCents = 0;
		foreach ($perRate as $rate => $cents) {
			$vatCents += (int)round($cents * (float)$rate / 100);
		}

		return [
			'netAmount' => round($netCents / 100, 2),
			'vatAmount' => round($vatCents / 100, 2),
			'grossAmount' => round(($netCents + $vatCents) / 100, 2),
		];
	}//end totals()

	/**
	 * Write one customer's invoice, or find the one an earlier submission wrote.
	 *
	 * @param array<string, mixed> $batch      The InvoiceBatch.
	 * @param string               $customerId The CustomerMaster id.
	 *
	 * @return array{customerId: string, invoiceId: string, status: string, reason: string}
	 */
	private function createOne(array $batch, string $customerId): array {
		$administrationId = (string)$batch['administrationId'];
		$customer = $this->customer(administrationId: $administrationId, customerId: $customerId);
		if ($customer === null) {
			return self::outcome(
				customerId: $customerId,
				invoiceId: '',
				status: self::OUTCOME_SKIPPED,
				reason: $this->l10n->t('The customer was not found in this administration.')
			);
		}

		$number = self::invoiceNumberFor(batchId: (string)$batch['batchId'], customerId: $customerId);

		try {
			$existing = $this->invoiceByNumber(administrationId: $administrationId, number: $number);
			if ($existing !== null) {
				return self::outcome(customerId: $customerId, invoiceId: $existing, status: self::OUTCOME_EXISTING, reason: '');
			}

			$invoiceId = $this->writeInvoice(batch: $batch, customer: $customer, number: $number);
		} catch (Throwable $e) {
			$this->logger->warning('InvoiceBatchService: invoice not written', ['customerId' => $customerId, 'exception' => $e->getMessage()]);
			return self::outcome(
				customerId: $customerId,
				invoiceId: '',
				status: self::OUTCOME_FAILED,
				reason: $this->l10n->t('The invoice could not be written.')
			);
		}//end try

		return self::outcome(customerId: $customerId, invoiceId: $invoiceId, status: self::OUTCOME_CREATED, reason: '');
	}//end createOne()

	/**
	 * Write one customer's invoice and answer its id.
	 *
	 * @param array<string, mixed> $batch    The batch.
	 * @param array<string, mixed> $customer The customer.
	 * @param string               $number   The invoice number.
	 *
	 * @return string The new invoice's id.
	 *
	 * @throws InvalidArgumentException When OpenRegister answers without an id.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private function writeInvoice(array $batch, array $customer, string $number): string {
		$saved = $this->scoped(schema: 'ARInvoice')->saveObject(
			self::buildInvoice(batch: $batch, customer: $customer, number: $number)
		);
		$invoiceId = ObjectIdentifier::resolve(saved: $saved);
		if ($invoiceId === '') {
			throw new InvalidArgumentException('OpenRegister answered without an id.');
		}

		return $invoiceId;
	}//end writeInvoice()

	/**
	 * The stored create batch with this reference, or a new one.
	 *
	 * A batch reference that belongs to a send batch, or to a create batch of
	 * other lines, is refused: a retry resubmits the same batch.
	 *
	 * @param string               $administrationId The administration.
	 * @param string               $batchId          The batch reference.
	 * @param array<string, mixed> $fields           The charge, customers, issue, send and requester.
	 *
	 * @return array<string, mixed> The InvoiceBatch as stored.
	 *
	 * @throws InvalidArgumentException When the reference is taken by another batch.
	 */
	private function batchFor(string $administrationId, string $batchId, array $fields): array {
		$stored = $this->scoped(schema: 'InvoiceBatch')->findAll(
			['filters' => ['administrationId' => $administrationId, 'batchId' => $batchId], 'limit' => 1]
		);
		$row = ObjectIdentifier::recordWithId(candidate: (array_values((array)$stored)[0] ?? null));
		if ($row !== null) {
			if ((string)($row['kind'] ?? '') !== 'create' || self::canonical(value: $row['charge'] ?? null) !== self::canonical(value: $fields['charge'])) {
				throw new InvalidArgumentException('This batch reference belongs to another batch.');
			}

			return array_merge($row, ['customerIds' => $fields['customerIds'], 'issue' => $fields['issue'], 'send' => $fields['send'], 'state' => 'running']);
		}

		return $this->save(
			batch: [
				'batchId' => $batchId,
				'kind' => 'create',
				'administrationId' => $administrationId,
				'requestedBy' => (string)$fields['requestedBy'],
				'charge' => $fields['charge'],
				'customerIds' => $fields['customerIds'],
				'issue' => $fields['issue'],
				'send' => $fields['send'],
				'invoiceIds' => [],
				'outcomes' => [],
				'state' => 'running',
			]
		);
	}//end batchFor()

	/**
	 * A stored value in a form two submissions of one charge compare equal in:
	 * keys sorted, every number a float (storage may hand 250.0 back as 250).
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function canonical(mixed $value): mixed {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			$out[$key] = self::canonical(value: $item);
		}

		ksort($out);

		return $out;
	}//end canonical()

	/**
	 * The batch reference: the caller's, else a new one.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the given reference is malformed.
	 */
	private static function batchId(array $request): string {
		$batchId = trim((string)($request['batchId'] ?? ''));
		if ($batchId === '') {
			return 'BATCH-' . strtoupper(bin2hex(random_bytes(6)));
		}

		if (preg_match(self::ID_PATTERN, $batchId) !== 1) {
			throw new InvalidArgumentException('The batch reference holds letters, digits, dots, dashes and underscores only.');
		}

		return $batchId;
	}//end batchId()

	/**
	 * The selected customers, 1 to 200, unique, in order.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When none or more than 200 are selected.
	 */
	private static function customerIds(array $request): array {
		$ids = InvoiceBatchSendService::invoiceIds(invoiceIds: (array)($request['customerIds'] ?? []));
		if ($ids === []) {
			throw new InvalidArgumentException('Select at least one customer.');
		}

		if (count($ids) > self::MAX_CUSTOMERS) {
			throw new InvalidArgumentException('A batch bills at most 200 customers.');
		}

		return $ids;
	}//end customerIds()

	/**
	 * One normalised invoice line.
	 *
	 * @param array<string, mixed> $line   The line as given.
	 * @param string               $lineId The line number.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the line has no description, no price or a bad figure.
	 */
	private static function line(array $line, string $lineId): array {
		$description = trim((string)($line['description'] ?? ''));
		$quantity = $line['quantity'] ?? 1;
		$price = $line['unitPrice'] ?? null;
		$rate = $line['vatRate'] ?? 21;
		self::assertLine(description: $description, quantity: $quantity, price: $price, rate: $rate);

		$category = 'Z';
		if ((float)$rate > 0) {
			$category = 'S';
		}

		return [
			'lineId' => $lineId,
			'itemName' => $description,
			'quantity' => (float)$quantity,
			'unitCode' => 'C62',
			'netPrice' => (float)$price,
			'netAmount' => round((float)$quantity * (float)$price, 2),
			'vatRate' => (float)$rate,
			'vatCategory' => $category,
		];
	}//end line()

	/**
	 * Refuse a line without numbers, without a description or positive price
	 * and quantity, or with a VAT rate outside 0-100.
	 *
	 * @param string $description The description.
	 * @param mixed  $quantity    The quantity as given.
	 * @param mixed  $price       The unit price as given.
	 * @param mixed  $rate        The VAT rate as given.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the line cannot be billed.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	private static function assertLine(string $description, mixed $quantity, mixed $price, mixed $rate): void {
		if (is_numeric($quantity) === false || is_numeric($price) === false || is_numeric($rate) === false) {
			throw new InvalidArgumentException('Every line needs a number for quantity, price and VAT rate.');
		}

		if ($description === '' || (float)$price <= 0 || (float)$quantity <= 0) {
			throw new InvalidArgumentException('Add at least one line with a description and a price.');
		}

		if ((float)$rate < 0 || (float)$rate > 100) {
			throw new InvalidArgumentException('A VAT rate lies between 0 and 100 percent.');
		}
	}//end assertLine()

	/**
	 * The ids of the invoices the batch holds, written now or before.
	 *
	 * @param array<int, array<string, string>> $outcomes The outcomes.
	 *
	 * @return list<string>
	 */
	private static function invoiceIdsOf(array $outcomes): array {
		$ids = [];
		foreach ($outcomes as $outcome) {
			if ($outcome['invoiceId'] !== '') {
				$ids[] = $outcome['invoiceId'];
			}
		}

		return $ids;
	}//end invoiceIdsOf()

	/**
	 * One outcome row.
	 *
	 * @param string $customerId The customer.
	 * @param string $invoiceId  The invoice, or ''.
	 * @param string $status     created, existing, skipped or failed.
	 * @param string $reason     Why, when nothing was written.
	 *
	 * @return array{customerId: string, invoiceId: string, status: string, reason: string}
	 */
	private static function outcome(string $customerId, string $invoiceId, string $status, string $reason): array {
		return ['customerId' => $customerId, 'invoiceId' => $invoiceId, 'status' => $status, 'reason' => $reason];
	}//end outcome()

	/**
	 * The customer key an invoice names: its customer code, else its object id.
	 *
	 * @param array<string, mixed> $customer The CustomerMaster.
	 *
	 * @return string
	 */
	private static function customerKey(array $customer): string {
		$code = trim((string)($customer['customerId'] ?? ''));
		if ($code !== '') {
			return $code;
		}

		return (string)($customer['id'] ?? '');
	}//end customerKey()

	/**
	 * The administration's customer by id or code, or null.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The CustomerMaster id or customer code.
	 *
	 * @return array<string, mixed>|null
	 */
	private function customer(string $administrationId, string $customerId): ?array {
		try {
			$row = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'CustomerMaster'), id: $customerId, fallbackProperty: 'customerId');
		} catch (Throwable $e) {
			return null;
		}

		if ($row === null || (string)($row['administrationId'] ?? '') !== $administrationId) {
			return null;
		}

		return $row;
	}//end customer()

	/**
	 * The id of the administration's invoice with this number, or null.
	 *
	 * @param string $administrationId The administration.
	 * @param string $number           The invoice number.
	 *
	 * @return string|null
	 */
	private function invoiceByNumber(string $administrationId, string $number): ?string {
		$rows = $this->scoped(schema: 'ARInvoice')->findAll(
			['filters' => ['administrationId' => $administrationId, 'invoiceNumber' => $number], 'limit' => 1]
		);
		$row = ObjectIdentifier::recordWithId(candidate: (array_values((array)$rows)[0] ?? null));
		if ($row === null || (string)($row['id'] ?? '') === '') {
			return null;
		}

		return (string)$row['id'];
	}//end invoiceByNumber()

	/**
	 * Save the batch and return it as stored.
	 *
	 * @param array<string, mixed> $batch The InvoiceBatch.
	 *
	 * @return array<string, mixed>
	 */
	private function save(array $batch): array {
		$uuid = null;
		$id = (string)($batch['id'] ?? ($batch['@self']['id'] ?? ''));
		if ($id !== '') {
			$uuid = $id;
		}

		$saved = $this->scoped(schema: 'InvoiceBatch')->saveObject(object: $batch, options: new ObjectSaveOptions(uuid: $uuid));
		$out = ObjectIdentifier::recordWithId(candidate: $saved);
		if ($out === null) {
			return $batch;
		}

		return $out;
	}//end save()

	/**
	 * The object service on one schema of shillinq's register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			$register = 'shillinq';
		}

		return $this->objectService->setRegister($register)->setSchema($schema);
	}//end scoped()

	/**
	 * Whether a value is a calendar date (YYYY-MM-DD).
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $part) !== 1) {
			return false;
		}

		return checkdate((int)$part[2], (int)$part[3], (int)$part[1]);
	}//end isDate()
}//end class
