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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drafting, validation, and GL posting of BillableInvoice rows.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class InvoiceGenerationService {
	/**
	 * Wire collaborators for invoice drafting and posting.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param RateCardResolver $rateCards Rate snapshot lookup.
	 * @param RetainerResolver $retainers Retainer schedule lookup.
	 * @param BillingModelEngine $billingEngine Pure model logic.
	 * @param InvoiceDeduplicationService $deduper Source-id conflict scanner.
	 * @param VATCalculationService $vat VAT totaller.
	 * @param UsageRatingCalculator $usageRating Meter-quantity rating (usage model).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly RateCardResolver $rateCards,
		private readonly RetainerResolver $retainers,
		private readonly BillingModelEngine $billingEngine,
		private readonly InvoiceDeduplicationService $deduper,
		private readonly VATCalculationService $vat,
		private readonly UsageRatingCalculator $usageRating,
	) {
	}//end __construct()

	/**
	 * Draft an invoice from a generation request — saves BillableInvoice +
	 * BillableInvoiceLine rows in draft status.
	 *
	 * @param InvoiceGenerationRequest $request Validated request.
	 *
	 * @return array<string,mixed> Persisted BillableInvoice (with id).
	 *
	 * @spec openspec/specs/usage-metered-billing/spec.md
	 */
	public function draftInvoice(InvoiceGenerationRequest $request): array {
		// 1. Deduplicate source IDs.
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

		// 2. Resolve time entries → BillingModelEngine input shape.
		$timeEntries = $this->loadTimeEntries(ids: $request->timeEntryIds, rateCardId: $request->rateCardId);
		$expenses = $this->loadExpenses(ids: $request->expenseIds);
		$hoursLogged = array_sum(array_column($timeEntries, 'hours'));

		// 3. Drive the model.
		$lineDrafts = $this->driveModel(request: $request, timeEntries: $timeEntries, expenses: $expenses, hoursLogged: $hoursLogged);

		// 4. Total with VAT — clamp any per-line rate to the four statutory
		// Dutch rates (21 / 9 / 6 / 0) before totalling so a stray rate
		// from a fixture / migration doesn't slip through (REQ-ITE-009).
		foreach ($lineDrafts as &$_line) {
			$_rate = (float)($_line['vatRate'] ?? BillingModelEngine::DEFAULT_VAT_RATE);
			if ($this->vat->isValidRate($_rate) === false) {
				$_line['vatRate'] = BillingModelEngine::DEFAULT_VAT_RATE;
			}
		}

		unset($_line);

		$totals = $this->vat->calculateVAT($lineDrafts);
		$invoiceNumber = $this->generateInvoiceNumber(administrationId: $request->administrationId, invoiceDate: $request->toDate);
		$dueDate = $this->addDays(isoDate: $request->toDate, days: 30);

		$invoice = [
			'administrationId' => $request->administrationId,
			'invoiceNumber' => $invoiceNumber,
			'billingModel' => $request->billingModel,
			'customerId' => $request->customerId,
			'projectId' => $request->projectId,
			'invoiceDate' => $request->toDate,
			'dueDate' => $dueDate,
			'timeEntryIds' => $request->timeEntryIds,
			'expenseIds' => $request->expenseIds,
			'meterReadingIds' => $request->meterReadingIds,
			'rateCardId' => $request->rateCardId,
			'retainerScheduleId' => $request->retainerScheduleId,
			'lineItemsByModel' => $this->summariseLineItemsByModel(lineDrafts: $lineDrafts),
			'summary' => [
				'netAmount' => round($totals['netCents'] / 100, 2),
				'vatAmount' => round($totals['vatCents'] / 100, 2),
				'grossAmount' => round($totals['grossCents'] / 100, 2),
				'currency' => 'EUR',
				'breakdown' => $this->breakdownInEuros(breakdown: $totals['breakdown']),
			],
			'netAmount' => round($totals['netCents'] / 100, 2),
			'vatAmount' => round($totals['vatCents'] / 100, 2),
			'grossAmount' => round($totals['grossCents'] / 100, 2),
			'currency' => 'EUR',
			'paymentTerms' => 'net 30',
			'status' => 'draft',
			'posted' => false,
			'obligationId' => null,
			'notes' => $request->notes,
		];

		$persisted = $this->saveObject(schema: 'BillableInvoice', data: $invoice);
		$invoiceId = (string)($persisted['id'] ?? ($persisted['@self']['id'] ?? ''));
		if ($invoiceId === '') {
			throw new RuntimeException('BillableInvoice save did not return an identifier.');
		}

		// 5. Persist lines.
		foreach ($lineDrafts as $line) {
			if (isset($line['billableUnits']) === true) {
				$billableUnits = (float)$line['billableUnits'];
			} else {
				$billableUnits = null;
			}

			$vatAmount = round(
				$this->vat->vatOnNet(
					netCents: (int)$line['costAmountCents'],
					rate: (float)$line['vatRate']
				) / 100,
				2
			);

			$linePayload = [
				'administrationId' => $request->administrationId,
				'invoiceId' => $invoiceId,
				'lineNumber' => (int)$line['lineNumber'],
				'sourceType' => (string)$line['sourceType'],
				'sourceId' => $line['sourceId'] ?? null,
				'description' => (string)$line['description'],
				'billableUnits' => $billableUnits,
				'rateApplied' => $line['rateApplied'] ?? null,
				'markup' => (float)($line['markup'] ?? 0),
				'costAmount' => round(((int)$line['costAmountCents']) / 100, 2),
				'vatRate' => (float)$line['vatRate'],
				'vatAmount' => $vatAmount,
				'modelSpecificFields' => $line['modelSpecificFields'] ?? null,
			];
			$this->saveObject(schema: 'BillableInvoiceLine', data: $linePayload);
		}//end foreach

		$this->logger->info(
			sprintf(
				'BillableInvoice %s drafted (%s lines, gross €%.2f)',
				$invoiceNumber,
				count($lineDrafts),
				($totals['grossCents'] / 100)
			)
		);

		return $this->fetchInvoice(invoiceId: $invoiceId);
	}//end draftInvoice()

	/**
	 * Validate an invoice for duplicate source ids + billing-model consistency.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return array{valid:bool,errors:array<int,string>}
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

		$model = (string)($invoice['billingModel'] ?? '');
		if ($model === 't_and_m' && empty($invoice['rateCardId']) === true) {
			$errors[] = 'rateCardId is required for t_and_m model.';
		}

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
	 */
	public function postInvoice(array $invoice): array {
		$status = (string)($invoice['status'] ?? '');
		if ($status === 'posted') {
			throw new RuntimeException('Invoice is already posted.');
		}

		if ($status === 'cancelled') {
			throw new RuntimeException('Cancelled invoices cannot be posted.');
		}

		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		$admin = (string)($invoice['administrationId'] ?? '');

		$validation = $this->validateInvoice(invoice: $invoice);
		if ($validation['valid'] === false) {
			throw new RuntimeException('Invoice failed validation: ' . implode('; ', $validation['errors']));
		}

		$netCents = (int)round(((float)($invoice['netAmount'] ?? 0)) * 100);
		$vatCents = (int)round(((float)($invoice['vatAmount'] ?? 0)) * 100);
		$grossCents = (int)round(((float)($invoice['grossAmount'] ?? 0)) * 100);

		// Create Obligation stub (consumed by accounts-receivable-core).
		$obligation = [
			'administrationId' => $admin,
			'obligationNumber' => 'OBL-' . ((string)($invoice['invoiceNumber'] ?? $invoiceId)),
			'invoiceId' => $invoiceId,
			'customerId' => (string)($invoice['customerId'] ?? ''),
			'amount' => round($grossCents / 100, 2),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'dueDate' => (string)($invoice['dueDate'] ?? ''),
			'status' => 'open',
		];

		$obligationId = null;
		try {
			$persistedObl = $this->saveObject(schema: 'Obligation', data: $obligation);
			$obligationId = (string)($persistedObl['id'] ?? ($persistedObl['@self']['id'] ?? ''));
		} catch (\Throwable $e) {
			$this->logger->warning('Obligation save failed (schema may not be present yet): ' . $e->getMessage());
		}

		// GL posting: Debit AR, Credit Revenue (by model), Credit VAT Payable.
		$revenueAccount = $this->revenueAccountFor(billingModel: (string)($invoice['billingModel'] ?? ''));
		$journal = [
			'administrationId' => $admin,
			'description' => sprintf('Invoice %s', (string)($invoice['invoiceNumber'] ?? '')),
			'journalDate' => (string)($invoice['invoiceDate'] ?? ''),
			'isBalanced' => true,
			'invoiceId' => $invoiceId,
			'postings' => [
				['accountNumber' => '1130', 'debitCents' => $grossCents, 'creditCents' => 0, 'description' => 'Accounts Receivable'],
				['accountNumber' => $revenueAccount, 'debitCents' => 0, 'creditCents' => $netCents, 'description' => 'Revenue'],
				['accountNumber' => '1150', 'debitCents' => 0, 'creditCents' => $vatCents, 'description' => 'VAT Payable'],
			],
		];

		try {
			$this->saveObject(schema: 'GLTransaction', data: $journal);
		} catch (\Throwable $e) {
			$this->logger->warning('GL posting failed (continuing): ' . $e->getMessage());
		}

		// Patch the invoice.
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
				$grossCents / 100,
				(string)$obligationId
			)
		);

		return $persisted;
	}//end postInvoice()

	/**
	 * Sum the net cents of a line-item array.
	 *
	 * @param array<int,array<string,mixed>> $lineItems Line items.
	 *
	 * @return int Cents.
	 */
	public function calculateNetAmount(array $lineItems): int {
		$total = 0;
		foreach ($lineItems as $line) {
			$total += (int)($line['costAmountCents'] ?? (int)round(((float)($line['costAmount'] ?? 0)) * 100));
		}

		return $total;
	}//end calculateNetAmount()

	/**
	 * Map billing model → revenue account number.
	 *
	 * @param string $billingModel Billing model.
	 *
	 * @return string GL account number.
	 */
	private function revenueAccountFor(string $billingModel): string {
		return match ($billingModel) {
			't_and_m' => '4100',
			'fixed_fee', 'milestone' => '4200',
			'retainer' => '4300',
			'mixed' => '4400',
			'usage' => '4500',
			default => '4000',
		};

	}//end revenueAccountFor()

	/**
	 * Decide which BillingModelEngine method to invoke.
	 *
	 * @param InvoiceGenerationRequest $request Request.
	 * @param array<int,array<string,mixed>> $timeEntries Resolved time entries.
	 * @param array<int,array<string,mixed>> $expenses Resolved expenses.
	 * @param float $hoursLogged Total hours.
	 *
	 * @return array<int,array<string,mixed>> Line drafts.
	 */
	private function driveModel(
		InvoiceGenerationRequest $request,
		array $timeEntries,
		array $expenses,
		float $hoursLogged,
	): array {
		switch ($request->billingModel) {
			case 't_and_m':
				return $this->billingEngine->calculateTAndM(timeEntries: $timeEntries, expenses: $expenses);
			case 'fixed_fee':
				if (empty($request->notes) === false) {
					$fixedFeeDescription = $request->notes;
				} else {
					$fixedFeeDescription = 'Fixed-fee engagement';
				}
				return $this->billingEngine->calculateFixedFee(
					flatFeeCents: (int)$request->fixedFeeCents,
					description: $fixedFeeDescription,
					expenses: $expenses,
					timeHourCount: (int)round($hoursLogged)
				);

			case 'milestone':
				return $this->billingEngine->calculateMilestone(
					milestone: $this->loadMilestone(milestoneId: (string)$request->milestoneId),
					expenses: $expenses
				);

			case 'retainer':
				return $this->billingEngine->calculateRetainer(
					retainer: $this->retainers->resolveRetainerAmount(
						scheduleId: (string)$request->retainerScheduleId,
						invoiceMonth: $request->toDate
					),
					retainerMonth: substr($request->toDate, 0, 7),
					hoursLogged: $hoursLogged,
					expenses: $expenses
				);

			case 'usage':
				return $this->billingEngine->calculateUsage(
					ratedReadings: $this->loadMeterReadings(
						ids: $request->meterReadingIds,
						defaultRatePlanId: $request->usageRatePlanId
					),
					expenses: $expenses
				);

			case 'mixed':
				if (empty($request->notes) === false) {
					$setupFeeDescription = $request->notes;
				} else {
					$setupFeeDescription = 'Setup fee';
				}
				return $this->billingEngine->calculateMixed(
					retainer: $this->retainers->resolveRetainerAmount(
						scheduleId: (string)$request->retainerScheduleId,
						invoiceMonth: $request->toDate
					),
					retainerMonth: substr($request->toDate, 0, 7),
					hoursLogged: $hoursLogged,
					setupFeeCents: $request->fixedFeeCents,
					setupFeeDescription: $setupFeeDescription,
					expenses: $expenses
				);

			default:
				throw new RuntimeException(sprintf('Unsupported billing model: %s', $request->billingModel));
		}//end switch

	}//end driveModel()

	/**
	 * Load time entries and attach rate snapshots.
	 *
	 * @param array<int,string> $ids UrenRegistratie ids.
	 * @param string|null $rateCardId Rate card to resolve against.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function loadTimeEntries(array $ids, ?string $rateCardId): array {
		if (count($ids) === 0) {
			return [];
		}

		$entries = [];
		foreach ($ids as $id) {
			$loaded = $this->find(schema: 'UrenRegistratie', id: $id);
			if ($loaded === null) {
				continue;
			}

			$resourceType = (string)($loaded['resourceType'] ?? ($loaded['role'] ?? 'consultant'));
			$hours = (float)($loaded['hours'] ?? ($loaded['duration'] ?? 0));
			$date = (string)($loaded['date'] ?? ($loaded['workDate'] ?? date('Y-m-d')));

			if ($rateCardId !== null) {
				$rate = $this->rateCards->resolveRate(
					rateCardId: $rateCardId,
					resourceType: $resourceType,
					date: $date
				);
			} else {
				$rate = [
					'rateCents' => $this->toCents(value: $loaded['hourlyRateOverride'] ?? 10000),
					'currency' => 'EUR',
					'rateCardVersion' => 'override',
					'effectiveDate' => $date,
				];
			}

			$entries[] = [
				'timeEntryId' => $id,
				'resourceType' => $resourceType,
				'hours' => $hours,
				'rateCents' => $rate['rateCents'],
				'rateApplied' => $rate,
			];
		}//end foreach

		return $entries;
	}//end loadTimeEntries()

	/**
	 * Load expense records into engine input shape.
	 *
	 * @param array<int,string> $ids ExpenseClaimEntry ids.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function loadExpenses(array $ids): array {
		if (count($ids) === 0) {
			return [];
		}

		$rows = [];
		foreach ($ids as $id) {
			$loaded = $this->find(schema: 'ExpenseClaimEntry', id: $id);
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
	 * @param array<int,string> $ids MeterReading ids.
	 * @param string|null $defaultRatePlanId Fallback UsageRatePlan id.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/usage-metered-billing/spec.md
	 */
	private function loadMeterReadings(array $ids, ?string $defaultRatePlanId): array {
		if (count($ids) === 0) {
			return [];
		}

		$rated = [];
		foreach ($ids as $id) {
			$reading = $this->find(schema: 'MeterReading', id: $id);
			if ($reading === null) {
				continue;
			}

			$planId = (string)($reading['ratePlanId'] ?? ($defaultRatePlanId ?? ''));
			if ($planId === '') {
				$this->logger->warning(sprintf('MeterReading %s has no ratePlanId; skipping.', $id));
				continue;
			}

			$plan = $this->find(schema: 'UsageRatePlan', id: $planId);
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
	 * @param string $milestoneId Milestone id.
	 *
	 * @return array{milestoneId:string,milestoneName:string,milestoneCompletedAt:string,milestoneBudgetCents:int}
	 */
	private function loadMilestone(string $milestoneId): array {
		$loaded = $this->find(schema: 'Milestone', id: $milestoneId);
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
	 * Summarise per-source-type counts + totals.
	 *
	 * @param array<int,array<string,mixed>> $lineDrafts Line item drafts.
	 *
	 * @return array<string,array{count:int,totalCents:int}>
	 */
	private function summariseLineItemsByModel(array $lineDrafts): array {
		$summary = [];
		foreach ($lineDrafts as $line) {
			$type = (string)($line['sourceType'] ?? 'manual');
			if (isset($summary[$type]) === false) {
				$summary[$type] = ['count' => 0, 'totalCents' => 0];
			}

			$summary[$type]['count']++;
			$summary[$type]['totalCents'] += (int)($line['costAmountCents'] ?? 0);
		}

		return $summary;
	}//end summariseLineItemsByModel()

	/**
	 * Format the per-rate breakdown in euros for the summary block.
	 *
	 * @param array<int,array{rate:float,netCents:int,vatCents:int,grossCents:int}> $breakdown Cents.
	 *
	 * @return array<int,array{rate:float,net:float,vat:float,gross:float}>
	 */
	private function breakdownInEuros(array $breakdown): array {
		$rows = [];
		foreach ($breakdown as $group) {
			$rows[] = [
				'rate' => (float)$group['rate'],
				'net' => round($group['netCents'] / 100, 2),
				'vat' => round($group['vatCents'] / 100, 2),
				'gross' => round($group['grossCents'] / 100, 2),
			];
		}

		return $rows;
	}//end breakdownInEuros()

	/**
	 * Generate an invoice number scoped to an administration + month.
	 *
	 * @param string $administrationId Tenant id.
	 * @param string $invoiceDate ISO date.
	 *
	 * @return string Invoice number.
	 */
	private function generateInvoiceNumber(string $administrationId, string $invoiceDate): string {
		$existing = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		try {
			$rs = $existing->setRegister($this->register())
				->setSchema('BillableInvoice')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
			if (is_array($rs) === true) {
				$count = count($rs);
			} else {
				$count = 0;
			}
		} catch (\Throwable $e) {
			$count = 0;
		}

		$year = substr($invoiceDate, 0, 4);
		return sprintf('BIL-%s-%04d', $year, ($count + 1));
	}//end generateInvoiceNumber()

	/**
	 * Add days to an ISO date.
	 *
	 * @param string $isoDate Source date.
	 * @param int $days Days to add.
	 *
	 * @return string New ISO date.
	 */
	private function addDays(string $isoDate, int $days): string {
		$ts = strtotime($isoDate);
		if ($ts === false) {
			return $isoDate;
		}

		return date('Y-m-d', ($ts + ($days * 86400)));
	}//end addDays()

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
	 * Find a single record via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Record id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find(string $schema, string $id): ?array {
		try {
			$svc = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rs = $svc->setRegister($this->register())->setSchema($schema)->find($id);
			if (is_array($rs) === true) {
				return $rs;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}

	}//end find()

	/**
	 * Save (create or update) via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed>
	 */
	private function saveObject(string $schema, array $data): array {
		$svc = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$saved = $svc
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		if (is_array($saved) === false) {
			throw new RuntimeException(sprintf('ObjectService::saveObject(%s) did not return an array', $schema));
		}

		return $saved;
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
