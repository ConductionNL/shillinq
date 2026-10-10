<?php

/**
 * Invoice Ingest Service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Event\BillablePeriodClosedEvent;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drafts the invoice an BillablePeriodClosedEvent asks for, or refuses it.
 *
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 */
class BillablePeriodInvoiceService {

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
	 * @param SettingsService $settings Resolves the register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly InvoiceGenerationService $invoices,
		private readonly SettingsService $settings,
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
		$sourceApp = trim($event->getSourceApp());
		$reference = trim($event->getExternalReference());
		$period    = $event->getPeriod();
		if ($sourceApp === '' || $reference === '') {
			throw new InvalidArgumentException('The invoice request names no source app or no external reference.');
		}

		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			throw new InvalidArgumentException(sprintf('The period "%s" is not a month (YYYY-MM).', $period));
		}

		$lines    = $this->normaliseLines(lines: $event->getLines());
		$customer = $this->resolveCustomer(reference: $reference);
		$adminId  = (string)$customer['administrationId'];
		$batchId  = $sourceApp . ':' . $reference . ':' . $period;
		$hash     = hash('sha256', (string)json_encode($lines));

		$batch = $this->findBatch(administrationId: $adminId, batchId: $batchId);
		if ($batch !== null) {
			$this->replay(event: $event, batch: $batch, payloadHash: $hash);
			return;
		}

		$invoice = $this->draft(
			customer: $customer,
			sourceApp: $sourceApp,
			reference: $reference,
			period: $period,
			lines: $lines
		);
		$this->recordBatch(
			customer: $customer,
			batchId: $batchId,
			sourceApp: $sourceApp,
			period: $period,
			lineCount: count($lines),
			invoiceId: $invoice['id'],
			payloadHash: $hash
		);

		$event->accept(invoiceId: $invoice['id'], invoiceNumber: $invoice['number']);
	}//end answer()

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
			$quantity  = ($line['quantity'] ?? null);
			$unitPrice = ($line['unitPrice'] ?? null);
			$currency  = strtoupper((string)($line['currency'] ?? self::CURRENCY));
			if (is_numeric($quantity) === false || is_numeric($unitPrice) === false || (float)$quantity <= 0.0) {
				throw new InvalidArgumentException(sprintf('Line %d has no readable quantity or unit price.', ($index + 1)));
			}

			if ($currency !== self::CURRENCY) {
				throw new InvalidArgumentException(sprintf('Line %d is in %s; shillinq drafts this invoice in EUR only.', ($index + 1), $currency));
			}

			$description = trim((string)($line['description'] ?? ''));
			if ($description === '') {
				$description = 'usage';
			}

			$normalised[] = [
				'description'    => $description,
				'quantity'       => (float)$quantity,
				'unitPriceCents' => (int)round(((float)$unitPrice) * 100),
			];
		}//end foreach

		return $normalised;
	}//end normaliseLines()

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
	 * @param array<string, mixed> $customer The CustomerMaster row.
	 * @param string $sourceApp The asking app.
	 * @param string $reference The asking app's id for the customer.
	 * @param string $period `YYYY-MM`.
	 * @param array<int, array{description:string, quantity:float, unitPriceCents:int}> $lines The lines.
	 *
	 * @return array{id:string, number:string}
	 */
	private function draft(array $customer, string $sourceApp, string $reference, string $period, array $lines): array {
		$adminId     = (string)$customer['administrationId'];
		$periodStart = $period . '-01';
		$periodEnd   = date('Y-m-t', (int)strtotime($periodStart));
		$readingIds  = [];
		foreach ($lines as $line) {
			$planId = $this->ratePlanFor(administrationId: $adminId, sourceApp: $sourceApp, line: $line);
			$saved  = $this->saveObject(
				schema: 'MeterReading',
				data: [
					'administrationId' => $adminId,
					'meterId'          => $sourceApp . ':' . $reference,
					'customerId'       => (string)$customer['customerId'],
					'resourceType'     => $line['description'],
					'quantity'         => $line['quantity'],
					'unit'             => 'unit',
					'ratePlanId'       => $planId,
					'ratedAmount'      => round(($line['quantity'] * $line['unitPriceCents']) / 100, 2),
					'periodStart'      => $periodStart,
					'periodEnd'        => $periodEnd,
					'description'      => $line['description'],
					'status'           => 'rated',
				]
			);
			$readingIds[] = $this->idOf(row: $saved);
		}//end foreach

		$invoice = $this->invoices->draftInvoice(
			request: new InvoiceGenerationRequest(
				administrationId: $adminId,
				billingModel: 'usage',
				customerId: (string)$customer['customerId'],
				fromDate: $periodStart,
				toDate: $periodEnd,
				notes: sprintf('%s, account %s, %s', $sourceApp, $reference, $period),
				meterReadingIds: $readingIds,
			)
		);

		return ['id' => $this->idOf(row: $invoice), 'number' => (string)($invoice['invoiceNumber'] ?? '')];
	}//end draft()

	/**
	 * Find or create the flat plan that prices one line at its own unit price.
	 *
	 * @param string $administrationId The customer's administration.
	 * @param string $sourceApp The asking app.
	 * @param array{description:string, quantity:float, unitPriceCents:int} $line The line.
	 *
	 * @return string The UsageRatePlan id.
	 */
	private function ratePlanFor(string $administrationId, string $sourceApp, array $line): string {
		$filters = [
			'administrationId' => $administrationId,
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
					'name'     => sprintf('%s %s', $sourceApp, $line['description']),
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
	 * @param array<string, mixed> $customer The CustomerMaster row.
	 * @param string $batchId `<sourceApp>:<reference>:<period>`.
	 * @param string $sourceApp The asking app.
	 * @param string $period `YYYY-MM`.
	 * @param int $lineCount Number of lines.
	 * @param string $invoiceId The drafted invoice.
	 * @param string $payloadHash The hash of the lines.
	 *
	 * @return void
	 */
	private function recordBatch(
		array $customer,
		string $batchId,
		string $sourceApp,
		string $period,
		int $lineCount,
		string $invoiceId,
		string $payloadHash,
	): void {
		$periodStart = $period . '-01';
		$this->saveObject(
			schema: 'TimeIntakeBatch',
			data: [
				'administrationId' => (string)$customer['administrationId'],
				'batchId'          => $batchId,
				'sourceApp'        => $sourceApp,
				'organisationRef'  => (string)$customer['customerId'],
				'currency'         => self::CURRENCY,
				'periodStart'      => $periodStart,
				'periodEnd'        => date('Y-m-t', (int)strtotime($periodStart)),
				'entryCount'       => $lineCount,
				'status'           => 'invoiced',
				'invoiceId'        => $invoiceId,
				'receivedAt'       => date('c'),
				'payloadHash'      => $payloadHash,
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
			$found = $this->scoped(schema: $schema)->find($id);
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
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
