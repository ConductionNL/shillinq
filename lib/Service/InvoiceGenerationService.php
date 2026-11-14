<?php

/**
 * Invoice Generation Service
 *
 * Orchestrates the time+expense → BillableInvoice draft and post flow for
 * issue #111 (Task 11). Composes RateCardResolver, RetainerResolver,
 * BillingModelEngine, InvoiceDeduplicationService and VATCalculationService
 * using the real OpenRegister ObjectService API (find / findAll / saveObject).
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Exception\UnratedHoursException;
use OCA\Shillinq\Kernel\Contracts\InvoiceGenerationServiceInterface;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drafting, validation, and GL posting of BillableInvoice rows.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class InvoiceGenerationService implements InvoiceGenerationServiceInterface {
	/**
	 * Wire collaborators for invoice drafting and posting.
	 *
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param RateCardResolver $rateCards Rate snapshot lookup.
	 * @param RetainerResolver $retainers Retainer schedule lookup.
	 * @param BillingModelEngine $billingEngine Pure model logic.
	 * @param InvoiceDeduplicationService $deduper Source-id conflict scanner.
	 * @param VATCalculationService $vat VAT totaller.
	 * @param UsageRatingCalculator $usageRating Meter-quantity rating (usage model).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param BilledReadings $billedReadings Refuses an unbillable reading and marks billed ones invoiced.
	 * @param BillableRateService $billableRates Prices each hour from the assignment's rate card.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly RateCardResolver $rateCards,
		private readonly RetainerResolver $retainers,
		private readonly BillingModelEngine $billingEngine,
		private readonly InvoiceDeduplicationService $deduper,
		private readonly VATCalculationService $vat,
		private readonly UsageRatingCalculator $usageRating,
		private readonly ObjectServiceInterface $objectService,
		private readonly BilledReadings $billedReadings,
		private readonly BillableRateService $billableRates,
	) {
	}//end __construct()

	/**
	 * Draft an invoice from a generation request — saves BillableInvoice +
	 * BillableInvoiceLine rows in draft status.
	 *
	 * Every id the request references (timeEntryIds/expenseIds/
	 * meterReadingIds/milestoneId) is resolved through a lookup scoped to
	 * $request->administrationId — a cross-tenant id can never resolve
	 * (REQ-001; see loadTimeEntries()/loadExpenses()/loadMeterReadings()/
	 * loadMilestone()/findScoped()).
	 *
	 * @param InvoiceGenerationRequest $request Validated request.
	 *
	 * @return array<string,mixed> Persisted BillableInvoice (with id).
	 *
	 * @spec openspec/specs/usage-metered-billing/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function draftInvoice(InvoiceGenerationRequest $request): array {
		$this->assertNoInvoicedSources(request: $request);

		$draft = new InvoiceDraft();
		$draft->request       = $request;
		$draft->currency      = $this->invoiceCurrency(request: $request);
		$draft->lineDrafts    = $this->records()->withStatutoryVatRates(lineDrafts: $this->lineDraftsFor(draft: $draft));
		$draft->totals        = $this->vat->calculateVAT($draft->lineDrafts);
		$draft->invoiceNumber = $this->generateInvoiceNumber(administrationId: $request->administrationId, invoiceDate: $request->toDate);
		$draft->invoiceId     = $this->saveDraftHeader(draft: $draft);

		foreach ($draft->lineDrafts as $line) {
			$this->saveObject(schema: 'BillableInvoiceLine', data: $this->records()->linePayload(draft: $draft, line: $line));
		}

		$this->billedReadings->markInvoiced(lineDrafts: $draft->lineDrafts, invoiceId: $draft->invoiceId);
		$this->logDrafted(draft: $draft);

		return $this->fetchInvoice(invoiceId: $draft->invoiceId);
}//end draftInvoice()

	/**
	 * Refuse a request whose time entries or expenses are already invoiced.
	 *
	 * @param InvoiceGenerationRequest $request The request.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a source id is already invoiced.
	 */
	private function assertNoInvoicedSources(InvoiceGenerationRequest $request): void {
		$dedup = $this->deduper->deduplicateSourceIds(
			administrationId: $request->administrationId,
			timeEntryIds: $request->timeEntryIds,
			expenseIds: $request->expenseIds
		);
		if ($dedup['hasConflicts'] === true) {
			throw new RuntimeException(
				sprintf(
					'Conflict: source ids already invoiced (%s)',
					json_encode($dedup['conflicts'])
				)
			);
		}
	}//end assertNoInvoicedSources()

	/**
	 * Resolve the request's time entries and expenses onto the draft and run
	 * its billing model.
	 *
	 * Every referenced id (timeEntryIds/expenseIds/meterReadingIds/
	 * milestoneId) is client-supplied on the request — loadTimeEntries()/
	 * loadExpenses() (and, via driveModel(), loadMeterReadings()/
	 * loadMilestone()) each scope their lookup to the request's
	 * administrationId so a cross-tenant id can never resolve (REQ-001).
	 *
	 * @param InvoiceDraft $draft The draft.
	 *
	 * @return array<int,array<string,mixed>> Line drafts.
	 */
	private function lineDraftsFor(InvoiceDraft $draft): array {
		$request = $draft->request;
		$draft->timeEntries = $this->loadTimeEntries(
			ids: $request->timeEntryIds,
			rateCardId: $request->rateCardId,
			administrationId: $request->administrationId,
			currency: $draft->currency,
			customerId: $request->customerId
		);
		$draft->expenses    = $this->loadExpenses(ids: $request->expenseIds, administrationId: $request->administrationId);
		$draft->hoursLogged = array_sum(array_column($draft->timeEntries, 'hours'));

		return $this->driveModel(draft: $draft);
	}//end lineDraftsFor()

	/**
	 * Save the draft BillableInvoice and answer its id.
	 *
	 * @param InvoiceDraft $draft The totalled draft.
	 *
	 * @return string The saved invoice's id.
	 *
	 * @throws RuntimeException When the save returns no identifier.
	 */
	private function saveDraftHeader(InvoiceDraft $draft): string {
		$persisted = $this->saveObject(schema: 'BillableInvoice', data: $this->records()->invoicePayload(draft: $draft));
		$invoiceId = (string)($persisted['id'] ?? ($persisted['@self']['id'] ?? ''));
		if ($invoiceId === '') {
			throw new RuntimeException('BillableInvoice save did not return an identifier.');
		}

		return $invoiceId;
	}//end saveDraftHeader()

	/**
	 * Log the drafted invoice.
	 *
	 * @param InvoiceDraft $draft The saved draft.
	 *
	 * @return void
	 */
	private function logDrafted(InvoiceDraft $draft): void {
		$this->logger->info(
			sprintf(
				'BillableInvoice %s drafted (%s lines, gross €%.2f)',
				$draft->invoiceNumber,
				count($draft->lineDrafts),
				($draft->totals['grossCents'] / 100)
			)
		);
	}//end logDrafted()

	/**
	 * Validate an invoice for duplicate source ids + billing-model consistency.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return array{valid:bool,errors:array<int,string>}
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function validateInvoice(array $invoice): array {
		$errors = [];
		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		$admin = (string)($invoice['administrationId'] ?? '');
		$timeIds = array_map('strval', (array)($invoice['timeEntryIds'] ?? []));
		$expIds = array_map('strval', (array)($invoice['expenseIds'] ?? []));

		if ($invoiceId !== '') {
			$excludeInvoiceId = $invoiceId;
		} else {
			$excludeInvoiceId = null;
		}

		$dedup = $this->deduper->deduplicateSourceIds(
			administrationId: $admin,
			timeEntryIds: $timeIds,
			expenseIds: $expIds,
			excludeInvoiceId: $excludeInvoiceId
		);
		if ($dedup['hasConflicts'] === true) {
			foreach ($dedup['conflicts'] as $conflict) {
				$errors[] = sprintf(
					'Conflict with invoice %s (%s): %d time + %d expense overlap',
					$conflict['invoiceNumber'],
					$conflict['status'],
					count($conflict['timeEntryIds']),
					count($conflict['expenseIds'])
				);
			}
		}

		// A time and material invoice names no rate card: each hour carries its
		// own rate (REQ-BRPH-005).
		$model = (string)($invoice['billingModel'] ?? '');
		if (in_array($model, ['retainer', 'mixed'], true) === true && empty($invoice['retainerScheduleId']) === true) {
			$errors[] = sprintf('retainerScheduleId is required for %s model.', $model);
		}

		return [
			'valid' => (count($errors) === 0),
			'errors' => $errors,
		];

	}//end validateInvoice()

	/**
	 * Post a draft invoice — transitions to posted, creates a stub Obligation,
	 * and emits a GL JournalEntry covering AR / Revenue / VAT.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return array<string,mixed> Updated invoice (and obligation summary).
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function postInvoice(array $invoice): array {
		$this->assertPostable(invoice: $invoice);

		$obligationId = $this->saveObligation(invoice: $invoice);
		$this->saveJournal(invoice: $invoice);

		$update = array_merge(
			$invoice,
			[
				'status' => 'posted',
				'posted' => true,
				'obligationId' => $obligationId,
			]
		);

		$persisted = $this->saveObject(schema: 'BillableInvoice', data: $update);
		$this->logger->info(
			sprintf(
				'BillableInvoice %s posted (gross €%.2f, obligation %s)',
				(string)($invoice['invoiceNumber'] ?? ''),
				$this->records()->centsOf(invoice: $invoice, field: 'grossAmount') / 100,
				(string)$obligationId
			)
		);

		return $persisted;
	}//end postInvoice()

	/**
	 * Refuse a posted or cancelled invoice, then one that fails validation.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the invoice cannot be posted.
	 */
	private function assertPostable(array $invoice): void {
		$status = (string)($invoice['status'] ?? '');
		if ($status === 'posted') {
			throw new RuntimeException('Invoice is already posted.');
		}

		if ($status === 'cancelled') {
			throw new RuntimeException('Cancelled invoices cannot be posted.');
		}

		$validation = $this->validateInvoice(invoice: $invoice);
		if ($validation['valid'] === false) {
			throw new RuntimeException('Invoice failed validation: ' . implode('; ', $validation['errors']));
		}
	}//end assertPostable()

	/**
	 * Save the stub Obligation (consumed by accounts-receivable-core) and
	 * answer its id; a failed save is logged and answers null.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return string|null The obligation id.
	 */
	private function saveObligation(array $invoice): ?string {
		$invoiceId  = $this->idOf(record: $invoice);
		$obligation = [
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
			'obligationNumber' => 'OBL-' . ((string)($invoice['invoiceNumber'] ?? $invoiceId)),
			'invoiceId' => $invoiceId,
			'customerId' => (string)($invoice['customerId'] ?? ''),
			'amount' => round($this->records()->centsOf(invoice: $invoice, field: 'grossAmount') / 100, 2),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'dueDate' => (string)($invoice['dueDate'] ?? ''),
			'status' => 'open',
		];

		try {
			return $this->idOf(record: $this->saveObject(schema: 'Obligation', data: $obligation));
		} catch (\Throwable $e) {
			$this->logger->warning('Obligation save failed (schema may not be present yet): ' . $e->getMessage());
			return null;
		}
	}//end saveObligation()

	/**
	 * Save the GL journal: debit AR, credit Revenue (by model), credit VAT
	 * Payable; a failed save is logged and posting continues.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return void
	 */
	private function saveJournal(array $invoice): void {
		try {
			$this->saveObject(schema: 'GLTransaction', data: $this->records()->journalFor(invoice: $invoice));
		} catch (\Throwable $e) {
			$this->logger->warning('GL posting failed (continuing): ' . $e->getMessage());
		}
	}//end saveJournal()

	/**
	 * A record's id, from `id` or `@self.id`.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return string
	 */
	private function idOf(array $record): string {
		return (string)($record['id'] ?? ($record['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * The record builder for drafts and posts.
	 *
	 * @return BillableInvoiceRecords
	 */
	private function records(): BillableInvoiceRecords {
		return new BillableInvoiceRecords($this->vat);
	}//end records()

	/**
	 * Sum the net cents of a line-item array.
	 *
	 * @param array<int,array<string,mixed>> $lineItems Line items.
	 *
	 * @return int Cents.
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function calculateNetAmount(array $lineItems): int {
		$total = 0;
		foreach ($lineItems as $line) {
			$total += (int)($line['costAmountCents'] ?? (int)round(((float)($line['costAmount'] ?? 0)) * 100));
		}

		return $total;
	}//end calculateNetAmount()

	/**
	 * Run the request's billing model on the draft's resolved sources.
	 *
	 * @param InvoiceDraft $draft The draft with its time entries and expenses.
	 *
	 * @return array<int,array<string,mixed>> Line drafts.
	 *
	 * @throws RuntimeException When the billing model is not supported.
	 */
	private function driveModel(InvoiceDraft $draft): array {
		return match ($draft->request->billingModel) {
			't_and_m' => $this->billingEngine->calculateTAndM(timeEntries: $draft->timeEntries, expenses: $draft->expenses),
			'fixed_fee' => $this->fixedFeeLines(draft: $draft),
			'milestone' => $this->milestoneLines(draft: $draft),
			'retainer' => $this->retainerLines(draft: $draft),
			'usage' => $this->usageLines(draft: $draft),
			'mixed' => $this->mixedLines(draft: $draft),
			default => throw new RuntimeException(sprintf('Unsupported billing model: %s', $draft->request->billingModel)),
		};

	}//end driveModel()

	/**
	 * Fixed-fee lines: the flat fee, described by the notes when there are
	 * any, plus the expenses.
	 *
	 * @param InvoiceDraft $draft The draft.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fixedFeeLines(InvoiceDraft $draft): array {
		return $this->billingEngine->calculateFixedFee(
			flatFeeCents: (int)$draft->request->fixedFeeCents,
			description: $this->notesOr(request: $draft->request, fallback: 'Fixed-fee engagement'),
			expenses: $draft->expenses,
			timeHourCount: (int)round($draft->hoursLogged)
		);
	}//end fixedFeeLines()

	/**
	 * Milestone lines: the tenant's milestone plus the expenses.
	 *
	 * @param InvoiceDraft $draft The draft.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function milestoneLines(InvoiceDraft $draft): array {
		$request = $draft->request;
		return $this->billingEngine->calculateMilestone(
			milestone: $this->loadMilestone(milestoneId: (string)$request->milestoneId, administrationId: $request->administrationId),
			expenses: $draft->expenses
		);
	}//end milestoneLines()

	/**
	 * Retainer lines: the month's retainer against the hours logged, plus the
	 * expenses.
	 *
	 * @param InvoiceDraft $draft The draft.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function retainerLines(InvoiceDraft $draft): array {
		return $this->billingEngine->calculateRetainer(
			retainer: $this->retainerFor(request: $draft->request),
			retainerMonth: substr($draft->request->toDate, 0, 7),
			hoursLogged: $draft->hoursLogged,
			expenses: $draft->expenses
		);
	}//end retainerLines()

	/**
	 * Usage lines: the tenant's rated meter readings plus the expenses.
	 *
	 * @param InvoiceDraft $draft The draft.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function usageLines(InvoiceDraft $draft): array {
		$request = $draft->request;
		return $this->billingEngine->calculateUsage(
			ratedReadings: $this->loadMeterReadings(
				ids: $request->meterReadingIds,
				defaultRatePlanId: $request->usageRatePlanId,
				administrationId: $request->administrationId
			),
			expenses: $draft->expenses
		);
	}//end usageLines()

	/**
	 * Mixed lines: the retainer, the setup fee (described by the notes when
	 * there are any) and the expenses.
	 *
	 * @param InvoiceDraft $draft The draft.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function mixedLines(InvoiceDraft $draft): array {
		return $this->billingEngine->calculateMixed(
			retainer: $this->retainerFor(request: $draft->request),
			retainerMonth: substr($draft->request->toDate, 0, 7),
			hoursLogged: $draft->hoursLogged,
			setupFeeCents: $draft->request->fixedFeeCents,
			setupFeeDescription: $this->notesOr(request: $draft->request, fallback: 'Setup fee'),
			expenses: $draft->expenses
		);
	}//end mixedLines()

	/**
	 * The tenant's retainer amount for the invoice month.
	 *
	 * @param InvoiceGenerationRequest $request The request.
	 *
	 * @return array<string,mixed>
	 */
	private function retainerFor(InvoiceGenerationRequest $request): array {
		return $this->retainers->resolveRetainerAmount(
			scheduleId: (string)$request->retainerScheduleId,
			invoiceMonth: $request->toDate,
			administrationId: $request->administrationId
		);
	}//end retainerFor()

	/**
	 * The request's notes when they are not empty, else the fallback.
	 *
	 * @param InvoiceGenerationRequest $request  The request.
	 * @param string                   $fallback The default description.
	 *
	 * @return string
	 */
	private function notesOr(InvoiceGenerationRequest $request, string $fallback): string {
		if (empty($request->notes) === false) {
			return (string)$request->notes;
		}

		return $fallback;
	}//end notesOr()

	/**
	 * Load time entries and attach rate snapshots.
	 *
	 * Each hour is priced by its own rate (REQ-BRPH-002, REQ-BRPH-005): the
	 * `RateCard` the request names when it is one of the old cards and prices
	 * the hour, else an explicit `hourlyRateOverride` on the entry, else the
	 * rate card on the person's assignment to the project
	 * ({@see BillableRateService}). An hour
	 * that none of these prices is never billed at a default: the draft is
	 * refused, naming every such hour's person and date.
	 *
	 * Each id is client-supplied on the request; the lookup itself is scoped
	 * to $administrationId (compound id+administrationId filter, matching
	 * GoodsReceiptNoteService's findOne() pattern) so a cross-tenant
	 * timeEntryId can never resolve — it is silently skipped, the same as an
	 * unknown id (REQ-001).
	 *
	 * @param array<int,string> $ids UrenRegistratie ids.
	 * @param string|null $rateCardId An old RateCard to resolve against, when the request names one.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 * @param string $currency The invoice currency, for an override rate.
	 * @param string $customerId The invoice's customer, for the client tier.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws UnratedHoursException When an hour has no rate.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-3.1
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
	 */
	private function loadTimeEntries(
		array $ids,
		?string $rateCardId,
		string $administrationId,
		string $currency = 'EUR',
		string $customerId = ''
	): array {
		if (count($ids) === 0) {
			return [];
		}

		$entries = [];
		$unrated = [];
		foreach ($ids as $id) {
			$loaded = $this->findScoped(schema: 'UrenRegistratie', id: $id, administrationId: $administrationId);
			if ($loaded === null) {
				continue;
			}

			$resourceType = (string)($loaded['resourceType'] ?? ($loaded['role'] ?? 'consultant'));
			$hours = (float)($loaded['hours'] ?? ($loaded['duration'] ?? 0));
			$date = (string)($loaded['date'] ?? ($loaded['workDate'] ?? date('Y-m-d')));

			$rate = $this->hourRate(
				entry: ['hours' => $hours, 'date' => $date, 'resourceType' => $resourceType, 'timeEntryId' => $id] + $loaded,
				rateCardId: $rateCardId,
				scope: ['administrationId' => $administrationId, 'currency' => $currency, 'customerId' => $customerId]
			);
			if (isset($rate['noRate']) === true) {
				$unrated[] = $rate['noRate'];
				continue;
			}

			$entries[] = [
				'timeEntryId' => $id,
				'resourceType' => $resourceType,
				'hours' => $hours,
				'rateCents' => $rate['rateCents'],
				'rateApplied' => $rate,
			];
		}//end foreach

		if ($unrated !== []) {
			throw new UnratedHoursException(hours: $unrated);
		}

		return $entries;
	}//end loadTimeEntries()

	/**
	 * The rate snapshot of one hour, or `noRate` with its person, date and reason.
	 *
	 * @param array<string,mixed> $entry      The time entry, with its `hours`, `date` and `resourceType` resolved.
	 * @param string|null         $rateCardId An old RateCard the request names, or null.
	 * @param array<string,string> $scope     The `administrationId`, the invoice `currency` and its `customerId`.
	 *
	 * @return array<string,mixed> The snapshot (`rateCents`, `currency`, `rateCardVersion`, `effectiveDate`, ...), or `['noRate' => [...]]`.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
	 */
	private function hourRate(array $entry, ?string $rateCardId, array $scope): array {
		$date = (string)$entry['date'];
		if ($rateCardId !== null) {
			$legacy = $this->rateCards->resolveRate(
				rateCardId: $rateCardId,
				resourceType: (string)$entry['resourceType'],
				date: $date,
				administrationId: $scope['administrationId']
			);
			if ($legacy !== null) {
				return $legacy;
			}
		}

		$override = ($entry['hourlyRateOverride'] ?? null);
		if ($override !== null && $override !== '') {
			return [
				'rateCents' => $this->toCents(value: $override),
				'currency' => $scope['currency'],
				'rateCardVersion' => 'override',
				'effectiveDate' => $date,
			];
		}

		$person = trim((string)($entry['personId'] ?? ''));
		$answer = $this->billableRates->resolve(
			hour: [
				'personId' => $person,
				'projectId' => (string)($entry['projectId'] ?? ''),
				'date' => $date,
				'hours' => (float)$entry['hours'],
				'administrationId' => $scope['administrationId'],
				'clientId' => $scope['customerId'],
			]
		);
		if (($answer['status'] ?? '') !== BillableRateService::STATUS_RATED) {
			if ($person === '') {
				$person = 'time entry ' . (string)$entry['timeEntryId'];
			}

			return ['noRate' => ['person' => $person, 'date' => $date, 'reason' => (string)($answer['message'] ?? '')]];
		}

		// A daily rate is billed per hour at the day rate over the working day.
		$hours = (float)$entry['hours'];
		$rateCents = (int)round(((float)$answer['rate'] * 100));
		if ($hours > 0 && ($answer['unit'] ?? 'hourly') !== 'hourly') {
			$rateCents = (int)round(((float)$answer['value'] * 100) / $hours);
		}

		return [
			'rateCents' => $rateCents,
			'currency' => $scope['currency'],
			'rateCardVersion' => (string)($answer['scheduleId'] ?? ''),
			'effectiveDate' => $date,
			'rate' => (float)$answer['rate'],
			'unit' => (string)($answer['unit'] ?? 'hourly'),
			'tier' => (string)($answer['tier'] ?? ''),
			'scheduleId' => (string)($answer['scheduleId'] ?? ''),
			'rateRecordId' => (string)($answer['recordId'] ?? ''),
		];
	}//end hourRate()

	/**
	 * Load expense records into engine input shape.
	 *
	 * Each id is client-supplied on the request; the lookup itself is scoped
	 * to $administrationId (compound id+administrationId filter) so a
	 * cross-tenant expenseId can never resolve — it is silently skipped, the
	 * same as an unknown id (REQ-001).
	 *
	 * @param array<int,string> $ids ExpenseClaimEntry ids.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function loadExpenses(array $ids, string $administrationId): array {
		if (count($ids) === 0) {
			return [];
		}

		$rows = [];
		foreach ($ids as $id) {
			$loaded = $this->findScoped(schema: 'ExpenseClaimEntry', id: $id, administrationId: $administrationId);
			if ($loaded === null) {
				continue;
			}

			$rows[] = [
				'expenseId' => $id,
				'description' => (string)($loaded['description'] ?? ($loaded['category'] ?? 'Expense')),
				'costAmountCents' => $this->toCents(value: $loaded['amount'] ?? ($loaded['costAmount'] ?? 0)),
				'vatRate' => (float)($loaded['vatRate'] ?? BillingModelEngine::DEFAULT_VAT_RATE),
			];
		}//end foreach

		return $rows;
	}//end loadExpenses()

	/**
	 * Load MeterReading records, resolve each reading's UsageRatePlan, and rate
	 * the metered quantity into the engine's rated-reading input shape
	 * (REQ-UMB-003). The plan is the reading's own `ratePlanId`, falling back to
	 * the request-level default. Readings whose plan cannot be resolved are
	 * skipped (never billed at a zero rate).
	 *
	 * Both lookups are client-reachable ids (`ids` from the request's
	 * meterReadingIds, and `defaultRatePlanId` from the request's own
	 * usageRatePlanId) — both are scoped to $administrationId (compound
	 * id+administrationId filter) so a cross-tenant MeterReading or
	 * UsageRatePlan can never resolve; it is silently skipped, the same as an
	 * unknown id (REQ-001).
	 *
	 * @param array<int,string> $ids MeterReading ids.
	 * @param string|null $defaultRatePlanId Fallback UsageRatePlan id.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/usage-metered-billing/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function loadMeterReadings(array $ids, ?string $defaultRatePlanId, string $administrationId): array {
		if (count($ids) === 0) {
			return [];
		}

		$rated = [];
		foreach ($ids as $id) {
			$reading = $this->findScoped(schema: 'MeterReading', id: $id, administrationId: $administrationId);
			if ($reading === null) {
				continue;
			}

			$this->billedReadings->assertBillable(reading: $reading, id: $id);

			$planId = (string)($reading['ratePlanId'] ?? ($defaultRatePlanId ?? ''));
			if ($planId === '') {
				$this->logger->warning(sprintf('MeterReading %s has no ratePlanId; skipping.', $id));
				continue;
			}

			$plan = $this->findScoped(schema: 'UsageRatePlan', id: $planId, administrationId: $administrationId);
			if ($plan === null) {
				$this->logger->warning(sprintf('UsageRatePlan %s not found for MeterReading %s; skipping.', $planId, $id));
				continue;
			}

			$quantity = (float)($reading['quantity'] ?? 0.0);
			$priced = $this->usageRating->rate(quantity: $quantity, plan: $plan);

			$description = (string)($reading['description'] ?? '');
			if ($description === '') {
				$description = sprintf(
					'%s — %s %s',
					(string)($plan['name'] ?? 'Metered usage'),
					rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.'),
					(string)($plan['unit'] ?? ($reading['unit'] ?? 'units'))
				);
			}

			$rated[] = [
				'readingId' => $id,
				'meterId' => (string)($reading['meterId'] ?? ''),
				'resourceType' => (string)($reading['resourceType'] ?? ($plan['resourceType'] ?? '')),
				'unit' => (string)($plan['unit'] ?? ($reading['unit'] ?? '')),
				'description' => $description,
				'billableUnits' => $priced['billableUnits'],
				'unitPriceCents' => $priced['unitPriceCents'],
				'costAmountCents' => $priced['costAmountCents'],
				'vatRate' => $priced['vatRate'],
				'ratingMethod' => $priced['ratingMethod'],
				'ratePlanId' => $planId,
				'periodStart' => (string)($reading['periodStart'] ?? ''),
				'periodEnd' => (string)($reading['periodEnd'] ?? ''),
			];
		}//end foreach

		return $rated;
	}//end loadMeterReadings()

	/**
	 * Load milestone metadata or return a stub on lookup failure.
	 *
	 * $milestoneId is client-supplied on the request; the lookup itself is
	 * scoped to $administrationId (compound id+administrationId filter) so a
	 * cross-tenant milestoneId can never resolve — it falls back to the same
	 * generic stub as an unknown id, never a cross-tenant milestone's own
	 * name/budget (REQ-001).
	 *
	 * @param string $milestoneId Milestone id.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 *
	 * @return array{milestoneId:string,milestoneName:string,milestoneCompletedAt:string,milestoneBudgetCents:int}
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function loadMilestone(string $milestoneId, string $administrationId): array {
		$loaded = $this->findScoped(schema: 'Milestone', id: $milestoneId, administrationId: $administrationId);
		if ($loaded === null) {
			return [
				'milestoneId' => $milestoneId,
				'milestoneName' => 'Milestone',
				'milestoneCompletedAt' => date('Y-m-d'),
				'milestoneBudgetCents' => 0,
			];
		}

		return [
			'milestoneId' => $milestoneId,
			'milestoneName' => (string)($loaded['name'] ?? 'Milestone'),
			'milestoneCompletedAt' => (string)($loaded['completedAt'] ?? date('Y-m-d')),
			'milestoneBudgetCents' => $this->toCents(value: $loaded['budgetAmount'] ?? 0),
		];

	}//end loadMilestone()

	/**
	 * Generate an invoice number scoped to an administration + month.
	 *
	 * @param string $administrationId Tenant id.
	 * @param string $invoiceDate ISO date.
	 *
	 * @return string Invoice number.
	 */
	private function generateInvoiceNumber(string $administrationId, string $invoiceDate): string {
		try {
			$rs = $this->objectService->setRegister($this->register())
				->setSchema('BillableInvoice')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
			$count = count($rs);
		} catch (\Throwable $e) {
			$count = 0;
		}

		$year = substr($invoiceDate, 0, 4);
		return sprintf('BIL-%s-%04d', $year, ($count + 1));
	}//end generateInvoiceNumber()

	/**
	 * Fetch a BillableInvoice by id.
	 *
	 * @param string $invoiceId Invoice id.
	 *
	 * @return array<string,mixed>
	 */
	private function fetchInvoice(string $invoiceId): array {
		$loaded = $this->find(schema: 'BillableInvoice', id: $invoiceId);
		if (is_array($loaded) === true) {
			return $loaded;
		}

		return [];
	}//end fetchInvoice()

	/**
	 * The invoice currency: the customer's `currency`, else the administration's
	 * `functionalCurrency`, else EUR (REQ-SID-004).
	 *
	 * Both lookups are scoped to the request's administration, so another
	 * administration's customer never sets it.
	 *
	 * @param InvoiceGenerationRequest $request The request.
	 *
	 * @return string ISO 4217 code, upper case.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-3.1
	 */
	private function invoiceCurrency(InvoiceGenerationRequest $request): string {
		$customer = $this->findScoped(schema: 'CustomerMaster', id: $request->customerId, administrationId: $request->administrationId);
		$currency = strtoupper(trim((string)($customer['currency'] ?? '')));
		if ($currency !== '') {
			return $currency;
		}

		$administration = $this->find(schema: 'Administration', id: $request->administrationId);
		$currency       = strtoupper(trim((string)($administration['functionalCurrency'] ?? '')));
		if ($currency !== '') {
			return $currency;
		}

		return 'EUR';

	}//end invoiceCurrency()

	/**
	 * Find a single record via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Record id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find(string $schema, string $id): ?array {
		try {
			$rs = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister($this->register())->setSchema($schema),
				id: $id
			);
			// ADR-084: find() is declared `: ?ObjectEntityInterface`, so the old
			// is_array() arm was unreachable by type and this helper returned NULL
			// for every existing record — every lookup read as "not found".
			if ($rs === null) {
				return null;
			}

			return (array)$rs->jsonSerialize();
		} catch (\Throwable $e) {
			return null;
		}

	}//end find()

	/**
	 * Find a single record scoped to the caller's administrationId: the record
	 * is read by its identity (find(), uuid or slug) and refused when its
	 * administrationId is not the caller's, so a cross-tenant id never
	 * resolves (REQ-001). The id is NOT put in a findAll() filter: `filters`
	 * address JSON properties, `id` is an entity column, and OpenRegister
	 * matches no row for it at all, so every lookup here read as absent and a
	 * usage, time or expense invoice drafted with no lines.
	 *
	 * Used for every id draftInvoice() resolves that originates on the
	 * client-supplied request (timeEntryIds/expenseIds/meterReadingIds/
	 * milestoneId, and the UsageRatePlan a MeterReading or the request's
	 * usageRatePlanId points at) — an unresolvable id (unknown OR
	 * cross-tenant) is indistinguishable to the caller, matching this file's
	 * existing "skip / fall back to stub" convention for a missing id.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Record id.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function findScoped(string $schema, string $id, string $administrationId): ?array {
		try {
			$scoped = $this->objectService->setRegister($this->register())->setSchema($schema);
			$record = ObjectIdentifier::findOne(scoped: $scoped, id: $id);
		} catch (\Throwable $e) {
			return null;
		}

		if ($record === null || (string)($record['administrationId'] ?? '') !== $administrationId) {
			return null;
		}

		return $record;
	}//end findScoped()

	/**
	 * Save (create or update) via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed>
	 */
	private function saveObject(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		return $saved->jsonSerialize();
	}//end saveObject()

	/**
	 * Convert a mixed value to integer cents.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return int
	 */
	private function toCents(mixed $value): int {
		if (is_int($value) === true) {
			return $value;
		}

		return (int)round((float)$value * 100);
	}//end toCents()

	/**
	 * Resolve the OpenRegister register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
