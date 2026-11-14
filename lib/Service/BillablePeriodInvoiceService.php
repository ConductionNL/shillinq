<?php

/**
 * Invoice Ingest Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Answers BillablePeriodClosedEvent: another app's customer month becomes a
 * draft BillableInvoice for the one CustomerMaster that carries that app's
 * reference (decision 174: a dossiq tenant bills under the customer whose
 * `externalReference` is the tenant id).
 *
 * The invoice goes through the path the time intake already uses: write the
 * source rows, then hand them to the unchanged
 * InvoiceGenerationService::draftInvoice(). Here the source rows are one
 * MeterReading per line, rated by a flat UsageRatePlan found or created for
 * that line's own unit price, and the model is `usage`. A TimeIntakeBatch row
 * keyed `<sourceApp>:<reference>:<period>` is the idempotency ledger, as it is
 * for a time batch: the same lines again answer with the stored invoice,
 * different lines under the same key are refused.
 *
 * Every refusal is written on the event, never thrown, so the asking app can
 * record why no invoice exists. Nothing is written before every check passed.
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
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Event\BillablePeriodClosedEvent;
use OCA\Shillinq\Kernel\Contracts\BillablePeriodInvoiceServiceInterface;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drafts the invoice an BillablePeriodClosedEvent asks for, or refuses it.
 *
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 */
class BillablePeriodInvoiceService implements BillablePeriodInvoiceServiceInterface {

	/**
	 * The only currency this path bills in.
	 *
	 * @var string
	 */
	private const CURRENCY = 'EUR';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param InvoiceGenerationService $invoices The unchanged draftInvoice() machinery.
	 * @param RegisterLocation $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly InvoiceGenerationService $invoices,
		private readonly RegisterLocation $registerLocation,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer the event: accept it with the drafted invoice, or refuse it.
	 *
	 * @param BillablePeriodClosedEvent $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function invoicePeriod(BillablePeriodClosedEvent $event): void {
		try {
			$this->answer(event: $event);
		} catch (InvalidArgumentException $e) {
			$event->refuse(error: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->error('BillablePeriodInvoiceService: drafting failed: ' . $e->getMessage());
			$event->refuse(error: 'Shillinq could not draft the invoice: ' . $e->getMessage());
		}
	}//end invoicePeriod()

	/**
	 * Check, then draft or replay.
	 *
	 * @param BillablePeriodClosedEvent $event The command.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the command is refused.
	 */
	private function answer(BillablePeriodClosedEvent $event): void {
		$run = $this->runFor(event: $event);

		$batch = $this->findBatch(administrationId: $run->administrationId(), batchId: $run->batchId);
		if ($batch !== null) {
			$this->replay(event: $event, batch: $batch, payloadHash: $run->payloadHash);
			return;
		}

		$invoice = $this->draft(run: $run);
		$this->recordBatch(run: $run, invoiceId: $invoice['id']);

		$event->accept(invoiceId: $invoice['id'], invoiceNumber: $invoice['number']);
	}//end answer()

	/**
	 * Check the command and resolve its customer, lines, ledger key and hash.
	 *
	 * @param BillablePeriodClosedEvent $event The command.
	 *
	 * @return BillablePeriodRun
	 *
	 * @throws InvalidArgumentException When the command is refused.
	 */
	private function runFor(BillablePeriodClosedEvent $event): BillablePeriodRun {
		$sourceApp = trim($event->getSourceApp());
		$reference = trim($event->getExternalReference());
		$period    = $event->getPeriod();
		$this->assertAddressed(sourceApp: $sourceApp, reference: $reference);
		$this->assertMonth(period: $period);

		$lines = $this->normaliseLines(lines: $event->getLines());
		return new BillablePeriodRun(
			customer: $this->resolveCustomer(reference: $reference),
			sourceApp: $sourceApp,
			reference: $reference,
			period: $period,
			lines: $lines,
			batchId: $sourceApp . ':' . $reference . ':' . $period,
			payloadHash: hash('sha256', (string)json_encode($lines)),
		);
	}//end runFor()

	/**
	 * Refuse a command without a source app or an external reference.
	 *
	 * @param string $sourceApp The trimmed source app.
	 * @param string $reference The trimmed external reference.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either is empty.
	 */
	private function assertAddressed(string $sourceApp, string $reference): void {
		if ($sourceApp === '' || $reference === '') {
			throw new InvalidArgumentException('The invoice request names no source app or no external reference.');
		}
	}//end assertAddressed()

	/**
	 * Refuse a period that is not a month.
	 *
	 * @param string $period The requested period.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not `YYYY-MM`.
	 */
	private function assertMonth(string $period): void {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			throw new InvalidArgumentException(sprintf('The period "%s" is not a month (YYYY-MM).', $period));
		}
	}//end assertMonth()

	/**
	 * Read every line, refusing the whole request on one unreadable line.
	 *
	 * @param array<int, array<string, mixed>> $lines The event's lines.
	 *
	 * @return array<int, array{description:string, quantity:float, unitPriceCents:int}>
	 *
	 * @throws InvalidArgumentException When there is no line or a line cannot be priced.
	 */
	private function normaliseLines(array $lines): array {
		if ($lines === []) {
			throw new InvalidArgumentException('The invoice request carries no lines.');
		}

		$normalised = [];
		foreach ($lines as $index => $line) {
			$normalised[] = $this->normaliseLine(index: $index, line: $line);
		}

		return $normalised;
	}//end normaliseLines()

	/**
	 * One line read as description (default "usage"), quantity and unit price in cents.
	 *
	 * @param int                  $index The line's position (0-based).
	 * @param array<string, mixed> $line  The event's line.
	 *
	 * @return array{description:string, quantity:float, unitPriceCents:int}
	 *
	 * @throws InvalidArgumentException When the line cannot be priced in EUR.
	 */
	private function normaliseLine(int $index, array $line): array {
		$this->assertPriced(index: $index, line: $line);

		$description = trim((string)($line['description'] ?? ''));
		if ($description === '') {
			$description = 'usage';
		}

		return [
			'description'    => $description,
			'quantity'       => (float)$line['quantity'],
			'unitPriceCents' => (int)round(((float)$line['unitPrice']) * 100),
		];
	}//end normaliseLine()

	/**
	 * Refuse a line without a readable positive quantity and unit price, or
	 * in another currency than EUR.
	 *
	 * @param int                  $index The line's position (0-based).
	 * @param array<string, mixed> $line  The event's line.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the line cannot be priced in EUR.
	 */
	private function assertPriced(int $index, array $line): void {
		$quantity  = ($line['quantity'] ?? null);
		$unitPrice = ($line['unitPrice'] ?? null);
		$currency  = strtoupper((string)($line['currency'] ?? self::CURRENCY));
		if (is_numeric($quantity) === false || is_numeric($unitPrice) === false || (float)$quantity <= 0.0) {
			throw new InvalidArgumentException(sprintf('Line %d has no readable quantity or unit price.', ($index + 1)));
		}

		if ($currency !== self::CURRENCY) {
			throw new InvalidArgumentException(sprintf('Line %d is in %s; shillinq drafts this invoice in EUR only.', ($index + 1), $currency));
		}
	}//end assertPriced()

	/**
	 * The one customer that carries the reference.
	 *
	 * @param string $reference The asking app's id for the customer.
	 *
	 * @return array<string, mixed> The CustomerMaster row.
	 *
	 * @throws InvalidArgumentException When none or more than one customer carries it.
	 */
	private function resolveCustomer(string $reference): array {
		$rows = $this->findAll(schema: 'CustomerMaster', filters: ['externalReference' => $reference]);
		if ($rows === []) {
			throw new InvalidArgumentException(
				sprintf('No shillinq customer carries the external reference "%s". Set it on the customer this account bills under.', $reference)
			);
		}

		if (count($rows) > 1) {
			throw new InvalidArgumentException(
				sprintf('%d shillinq customers carry the external reference "%s", so the invoice has no single customer.', count($rows), $reference)
			);
		}

		$customer = $rows[0];
		if ((string)($customer['administrationId'] ?? '') === '' || (string)($customer['customerId'] ?? '') === '') {
			throw new InvalidArgumentException(
				sprintf('The customer carrying "%s" has no administration or no customer id.', $reference)
			);
		}

		return $customer;
	}//end resolveCustomer()

	/**
	 * Answer a repeat from the ledger.
	 *
	 * @param BillablePeriodClosedEvent $event The command.
	 * @param array<string, mixed> $batch The stored ledger row.
	 * @param string $payloadHash The hash of this request's lines.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the same month arrives with different lines, or never got an invoice.
	 */
	private function replay(BillablePeriodClosedEvent $event, array $batch, string $payloadHash): void {
		if ((string)($batch['payloadHash'] ?? '') !== $payloadHash) {
			throw new InvalidArgumentException(
				sprintf('This month (%s) was drafted before with different lines. Credit or change that invoice instead.', (string)$batch['batchId'])
			);
		}

		$invoiceId = (string)($batch['invoiceId'] ?? '');
		if ($invoiceId === '') {
			throw new InvalidArgumentException(sprintf('This month (%s) is on record without an invoice.', (string)$batch['batchId']));
		}

		$invoice = $this->find(schema: 'BillableInvoice', id: $invoiceId);
		$event->acceptDuplicate(
			invoiceId: $invoiceId,
			invoiceNumber: (string)($invoice['invoiceNumber'] ?? '')
		);
	}//end replay()

	/**
	 * Write the meter readings and draft the invoice.
	 *
	 * @param BillablePeriodRun $run The month being drafted.
	 *
	 * @return array{id:string, number:string}
	 */
	private function draft(BillablePeriodRun $run): array {
		$readingIds = [];
		foreach ($run->lines as $line) {
			$readingIds[] = $this->writeReading(run: $run, line: $line);
		}

		$invoice = $this->invoices->draftInvoice(
			request: new InvoiceGenerationRequest(
				administrationId: $run->administrationId(),
				billingModel: 'usage',
				customerId: (string)$run->customer['customerId'],
				fromDate: $run->periodStart(),
				toDate: $run->periodEnd(),
				notes: sprintf('%s, account %s, %s', $run->sourceApp, $run->reference, $run->period),
				meterReadingIds: $readingIds,
			)
		);

		return ['id' => $this->idOf(row: $invoice), 'number' => (string)($invoice['invoiceNumber'] ?? '')];
	}//end draft()

	/**
	 * Price one line on its flat plan and write it as a rated meter reading.
	 *
	 * @param BillablePeriodRun                                             $run  The month being drafted.
	 * @param array{description:string, quantity:float, unitPriceCents:int} $line The line.
	 *
	 * @return string The MeterReading id.
	 */
	private function writeReading(BillablePeriodRun $run, array $line): string {
		$planId = $this->ratePlanFor(run: $run, line: $line);
		$saved  = $this->saveObject(
			schema: 'MeterReading',
			data: [
				'administrationId' => $run->administrationId(),
				'meterId'          => $run->sourceApp . ':' . $run->reference,
				'customerId'       => (string)$run->customer['customerId'],
				'resourceType'     => $line['description'],
				'quantity'         => $line['quantity'],
				'unit'             => 'unit',
				'ratePlanId'       => $planId,
				'ratedAmount'      => round(($line['quantity'] * $line['unitPriceCents']) / 100, 2),
				'periodStart'      => $run->periodStart(),
				'periodEnd'        => $run->periodEnd(),
				'description'      => $line['description'],
				'status'           => 'rated',
			]
		);

		return $this->idOf(row: $saved);
	}//end writeReading()

	/**
	 * Find or create the flat plan that prices one line at its own unit price.
	 *
	 * @param BillablePeriodRun                                             $run  The month being drafted.
	 * @param array{description:string, quantity:float, unitPriceCents:int} $line The line.
	 *
	 * @return string The UsageRatePlan id.
	 */
	private function ratePlanFor(BillablePeriodRun $run, array $line): string {
		$filters = [
			'administrationId' => $run->administrationId(),
			'resourceType'     => $line['description'],
			'ratingMethod'     => 'flat',
			'unitPriceCents'   => $line['unitPriceCents'],
		];
		$found   = $this->findAll(schema: 'UsageRatePlan', filters: $filters);
		if ($found !== []) {
			return $this->idOf(row: $found[0]);
		}

		$plan = $this->saveObject(
			schema: 'UsageRatePlan',
			data: array_merge(
				$filters,
				[
					'name'     => sprintf('%s %s', $run->sourceApp, $line['description']),
					'unit'     => 'unit',
					'currency' => self::CURRENCY,
				]
			)
		);

		return $this->idOf(row: $plan);
	}//end ratePlanFor()

	/**
	 * Record the drafted month in the ledger.
	 *
	 * @param BillablePeriodRun $run       The month being drafted.
	 * @param string            $invoiceId The drafted invoice.
	 *
	 * @return void
	 */
	private function recordBatch(BillablePeriodRun $run, string $invoiceId): void {
		$this->saveObject(
			schema: 'TimeIntakeBatch',
			data: [
				'administrationId' => $run->administrationId(),
				'batchId'          => $run->batchId,
				'sourceApp'        => $run->sourceApp,
				'organisationRef'  => (string)$run->customer['customerId'],
				'currency'         => self::CURRENCY,
				'periodStart'      => $run->periodStart(),
				'periodEnd'        => $run->periodEnd(),
				'entryCount'       => count($run->lines),
				'status'           => 'invoiced',
				'invoiceId'        => $invoiceId,
				'receivedAt'       => date('c'),
				'payloadHash'      => $run->payloadHash,
			]
		);
	}//end recordBatch()

	/**
	 * The ledger row for this month, if any.
	 *
	 * @param string $administrationId The customer's administration.
	 * @param string $batchId The ledger key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findBatch(string $administrationId, string $batchId): ?array {
		$rows = $this->findAll(schema: 'TimeIntakeBatch', filters: ['administrationId' => $administrationId, 'batchId' => $batchId]);

		return ($rows[0] ?? null);
	}//end findBatch()

	/**
	 * The id of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id.
	 *
	 * @throws InvalidArgumentException When the store returned no id.
	 */
	private function idOf(array $row): string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($id === '') {
			throw new InvalidArgumentException('OpenRegister stored a row without returning its id, so the invoice was not drafted.');
		}

		return $id;
	}//end idOf()

	/**
	 * Find one row by id.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Row id.
	 *
	 * @return array<string, mixed>
	 */
	private function find(string $schema, string $id): array {
		try {
			$found = ObjectIdentifier::findScoped(
				scoped: $this->scoped(schema: $schema),
				id: $id
			);
		} catch (Throwable $e) {
			return [];
		}

		if ($found === null) {
			return [];
		}

		return (array)$found->jsonSerialize();
	}//end find()

	/**
	 * Find rows by property filters, as plain arrays.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		$plain = [];
		foreach ($this->scoped(schema: $schema)->findAll(['filters' => $filters]) as $row) {
			if (is_array($row) === false) {
				$row = (array)$row->jsonSerialize();
			}

			$plain[] = $row;
		}

		return $plain;
	}//end findAll()

	/**
	 * Create a row.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $data Row body.
	 *
	 * @return array<string, mixed>
	 */
	private function saveObject(string $schema, array $data): array {
		return (array)$this->scoped(schema: $schema)->saveObject($data)->jsonSerialize();
	}//end saveObject()

	/**
	 * The object service pointed at shillinq's register and one schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
