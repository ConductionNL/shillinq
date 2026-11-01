<?php

/**
 * Time Intake Service
 *
 * External-integration ingress adapter (ADR-031 documented exception) for
 * the shillinq half of the pipelinq time-billing handoff
 * (time-expense-invoice-intake). Validates a batch of externally-approved
 * time entries, materialises each into a UrenRegistratie row stamped with
 * externalId/sourceApp/sourceBatchId, and delegates the actual invoice
 * construction (line aggregation, totals, VAT, rate snapshotting) to the
 * existing, UNMODIFIED InvoiceGenerationService::draftInvoice() — this class
 * does not reimplement any of that logic.
 *
 * Idempotency (design D2): keyed on (administrationId, batchId). A replay of
 * an identical payload short-circuits to the stored invoice (duplicated:
 * true); a batchId reused with a materially different payload (detected via
 * a sha256 payloadHash) is a conflict. A per-entry externalId is additionally
 * checked against every OTHER batch for the administration so the same
 * approved time cannot be billed twice under a new batchId.
 *
 * Rate resolution: InvoiceGenerationRequest requires a non-null rateCardId
 * for billingModel=t_and_m (existing, unmodified validation in
 * InvoiceGenerationRequest::assertValid()) — the batch cannot use
 * InvoiceGenerationService's per-entry hourlyRateOverride path for T&M. Per
 * entry, a rate is resolved from either an inline `hourlyRate` or an
 * existing `rateRef` (RateCard id); when the request does not supply its own
 * top-level rateCardId, the FIRST entry's resolved rate is used to
 * find-or-create one RateCard for the whole batch (RateCardResolver's
 * existing `id`-based fallback lookup then resolves it for every entry via
 * the real, unmodified RateCardResolver). This is a genuine, pre-existing
 * limitation of the already-shipped invoice-from-time-and-expense machinery
 * (its RateRecord-based per-resource lookup targets a differently-shaped
 * canonical RateRecord schema than the one actually registered) — out of
 * scope to fix here. Every entry's own resolved rate is still preserved
 * verbatim on UrenRegistratie.recognisedRate for full per-entry audit
 * traceability, even when the aggregate invoice uses one shared card.
 *
 * Per the orchestrator's binding rulings for this slice:
 *  - expenses[] is accepted-and-ignored (time-only invoice; see design.md D3
 *    — a future capability wires ExpenseClaimEntry creation).
 *  - An entry with no inline hourlyRate and an unresolvable rateRef hard-
 *    fails the WHOLE batch with 422 (no partial materialisation — every
 *    entry is validated before any UrenRegistratie row is written).
 *  - organisationRef MUST resolve to a real CustomerMaster row scoped to the
 *    administration, else 422.
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
 * @spec openspec/specs/time-expense-invoice-intake/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Validates, materialises, and delegates a pipelinq time-billing batch.
 *
 * @spec openspec/specs/time-expense-invoice-intake/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; deferred pending a dedicated refactor.
 */
class TimeIntakeService {
	/**
	 * Only billing model accepted by this slice.
	 *
	 * @var string
	 */
	private const ALLOWED_BILLING_MODEL = 't_and_m';

	/**
	 * The names of the fields parseAndAssertStructure() returns, in its order.
	 *
	 * @var array<int,string>
	 */
	private const BATCH_FIELDS = [
		'batchId',
		'organisationRef',
		'billingModel',
		'currency',
		'periodStart',
		'periodEnd',
		'requestRateCardId',
		'projectRef',
		'notes',
		'entries',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 * @param InvoiceGenerationService $invoices Existing, unmodified draftInvoice() machinery.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly InvoiceGenerationService $invoices,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Ingest one batch (POST /apps/shillinq/api/billing/time-intake).
	 *
	 * @param string $administrationId Server-resolved tenant scope (never client-supplied).
	 * @param string $personId Server-resolved authenticated user id — stamped as
	 *                         the UrenRegistratie.personId of every materialised
	 *                         entry (the batch carries no per-entry person
	 *                         identity).
	 * @param array<string,mixed> $body Decoded JSON request body.
	 *
	 * @throws InvalidArgumentException On structural/malformed input (maps to HTTP 400).
	 * @throws RuntimeException On a "Conflict: ..." message (maps to HTTP 409) or any other
	 *                          semantic validation failure (maps to HTTP 422).
	 *
	 * @return array{invoiceId:string,invoiceNumber:string,status:string,lines:int,duplicated:bool}
	 *
	 * @spec openspec/specs/time-expense-invoice-intake/spec.md
	 */
	public function ingest(string $administrationId, string $personId, array $body): array {
		$batch = $this->intakeBatch(body: $body, scope: ['administrationId' => $administrationId, 'personId' => $personId]);

		// The expenses[] array is accepted-and-ignored for this slice (D3,
		// design.md) — a time-only invoice is drafted; expense
		// materialisation belongs to a separate, not-yet-wired
		// ExpenseClaimEntry capability. The count is intentionally not
		// persisted anywhere: nothing downstream reads it.
		unset($body['expenses']);

		$existingBatch = $this->findBatch(administrationId: $administrationId, batchId: $batch['batchId']);
		if ($existingBatch !== null) {
			return $this->replayOrConflict(batch: $batch, existingBatch: $existingBatch);
		}

		$this->assertBillable(batch: $batch);
		$this->assertUniqueEntries(batch: $batch);
		$reusableRows = $this->sameBatchRows(batch: $batch);
		$batch['rates']      = $this->resolveEntryRates(batch: $batch);
		$batch['rateCardId'] = $this->batchRateCardId(batch: $batch);
		$timeEntryIds        = $this->materialiseEntries(batch: $batch, reusableRows: $reusableRows);

		$invoice   = $this->draftBatchInvoice(batch: $batch, timeEntryIds: $timeEntryIds);
		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		$this->recordBatch(batch: $batch, invoiceId: $invoiceId);

		return [
			'invoiceId' => $invoiceId,
			'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
			'status' => 'draft',
			'lines' => count($batch['entries']),
			'duplicated' => false,
		];

	}//end ingest()

	/**
	 * The validated batch as named fields, with the caller's scope and the
	 * payload hash.
	 *
	 * @param array<string,mixed> $body  Decoded JSON request body.
	 * @param array<string,string> $scope administrationId and personId.
	 *
	 * @throws InvalidArgumentException On structural/malformed input.
	 *
	 * @return array<string,mixed>
	 */
	private function intakeBatch(array $body, array $scope): array {
		$batch = array_merge($scope, array_combine(self::BATCH_FIELDS, $this->parseAndAssertStructure(body: $body)));
		$batch['payloadHash'] = $this->computePayloadHash(
			organisationRef: $batch['organisationRef'],
			billingModel: $batch['billingModel'],
			currency: $batch['currency'],
			periodStart: $batch['periodStart'],
			periodEnd: $batch['periodEnd'],
			entries: $batch['entries']
		);

		return $batch;

	}//end intakeBatch()

	/**
	 * Answer a batchId that was already ingested: the stored response for the
	 * same payload, a conflict for a different one.
	 *
	 * @param array<string,mixed> $batch         The incoming batch.
	 * @param array<string,mixed> $existingBatch The stored TimeIntakeBatch.
	 *
	 * @throws RuntimeException "Conflict: ..." when the payload differs.
	 *
	 * @return array{invoiceId:string,invoiceNumber:string,status:string,lines:int,duplicated:bool}
	 */
	private function replayOrConflict(array $batch, array $existingBatch): array {
		if ((string)($existingBatch['payloadHash'] ?? '') === $batch['payloadHash']) {
			return $this->replayResponse(batch: $existingBatch);
		}

		throw new RuntimeException(sprintf('Conflict: batchId "%s" was already ingested with a different payload.', $batch['batchId']));

	}//end replayOrConflict()

	/**
	 * Refuse a batch that is not time and materials, or whose organisation is
	 * not a customer of the administration.
	 *
	 * @param array<string,mixed> $batch The batch.
	 *
	 * @throws RuntimeException On either refusal.
	 *
	 * @return void
	 */
	private function assertBillable(array $batch): void {
		if ($batch['billingModel'] !== self::ALLOWED_BILLING_MODEL) {
			throw new RuntimeException(
				sprintf('billingModel must be "%s" (got "%s").', self::ALLOWED_BILLING_MODEL, $batch['billingModel'])
			);
		}

		if ($this->resolveCustomer(organisationRef: $batch['organisationRef'], administrationId: $batch['administrationId']) === null) {
			throw new RuntimeException(
				sprintf('organisationRef "%s" does not resolve to a known customer for this administration.', $batch['organisationRef'])
			);
		}

	}//end assertBillable()

	/**
	 * Cross-batch externalId dedup (Risk 1 / D2): look up every entry's
	 * existing UrenRegistratie row (if any) up front, before writing
	 * anything. A row belonging to a DIFFERENT batchId is a genuine
	 * cross-batch double-bill attempt (422). A row belonging to THIS
	 * SAME batchId means a prior attempt at this exact batchId partially
	 * materialised before failing (Risk 3) — it is reused, not
	 * duplicated, in the materialisation pass.
	 *
	 * @param array<string,mixed> $batch The batch.
	 *
	 * @throws RuntimeException When an entry was billed under another batch.
	 *
	 * @return array<string,array<string,mixed>> externalId => the row to reuse.
	 */
	private function sameBatchRows(array $batch): array {
		$rows = [];
		foreach ($batch['entries'] as $entry) {
			$existingRow = $this->findHoursRegistrationByExternalId(administrationId: $batch['administrationId'], externalId: $entry['externalId']);
			if ($existingRow === null) {
				continue;
			}

			if ((string)($existingRow['sourceBatchId'] ?? '') !== $batch['batchId']) {
				throw new RuntimeException(
					sprintf('externalId "%s" was already billed under a different batch.', $entry['externalId'])
				);
			}

			$rows[$entry['externalId']] = $existingRow;
		}

		return $rows;

	}//end sameBatchRows()

	/**
	 * Refuse a batch that carries the same externalId twice: both entries
	 * would be written as hours rows and billed.
	 *
	 * @param array<string,mixed> $batch The batch.
	 *
	 * @throws RuntimeException Naming the repeated externalId.
	 *
	 * @return void
	 */
	private function assertUniqueEntries(array $batch): void {
		$seen = [];
		foreach ($batch['entries'] as $entry) {
			if (isset($seen[$entry['externalId']]) === true) {
				throw new RuntimeException(
					sprintf('externalId "%s" appears more than once in this batch.', $entry['externalId'])
				);
			}

			$seen[$entry['externalId']] = true;
		}

	}//end assertUniqueEntries()

	/**
	 * Resolve every entry's rate + validate minutes BEFORE any write
	 * (hard-fail the whole batch — no partial materialisation).
	 *
	 * @param array<string,mixed> $batch The batch.
	 *
	 * @throws RuntimeException On non-positive minutes or an unresolvable rate.
	 *
	 * @return array<string,float> externalId => resolved rate.
	 */
	private function resolveEntryRates(array $batch): array {
		$rates = [];
		foreach ($batch['entries'] as $entry) {
			$minutes = $entry['minutes'];
			if (is_numeric($minutes) === false || (float)$minutes <= 0.0) {
				throw new RuntimeException(sprintf('entry "%s" has a non-positive minutes value.', $entry['externalId']));
			}

			$rate = $this->resolveEntryRate(entry: $entry, administrationId: $batch['administrationId']);
			if ($rate === null) {
				throw new RuntimeException(
					sprintf('entry "%s" has an unresolvable rateRef and no inline hourlyRate.', $entry['externalId'])
				);
			}

			$rates[$entry['externalId']] = $rate;
		}

		return $rates;

	}//end resolveEntryRates()

	/**
	 * The batch's rate card id.
	 *
	 * @param array<string,mixed> $batch The batch, with its resolved rates.
	 *
	 * @throws RuntimeException When no rate card resolves.
	 *
	 * @return string
	 */
	private function batchRateCardId(array $batch): string {
		$rateCardId = $this->resolveBatchRateCardId(
			administrationId: $batch['administrationId'],
			requestRateCardId: $batch['requestRateCardId'],
			entries: $batch['entries'],
			resolvedRates: $batch['rates'],
			periodStart: $batch['periodStart'],
			periodEnd: $batch['periodEnd']
		);
		if ($rateCardId === null) {
			throw new RuntimeException('rateCardId could not be resolved for this batch.');
		}

		return $rateCardId;

	}//end batchRateCardId()

	/**
	 * Materialise (or reuse, per the same-batch-retry case) one UrenRegistratie
	 * row per entry.
	 *
	 * @param array<string,mixed>               $batch        The batch, with its resolved rates.
	 * @param array<string,array<string,mixed>> $reusableRows externalId => same-batch row.
	 *
	 * @throws RuntimeException When a saved row has no id.
	 *
	 * @return array<int,string> The time entry ids, in entry order.
	 */
	private function materialiseEntries(array $batch, array $reusableRows): array {
		$timeEntryIds = [];
		foreach ($batch['entries'] as $entry) {
			$reusedId = $this->reusableRowId(row: ($reusableRows[$entry['externalId']] ?? null));
			if ($reusedId !== '') {
				$timeEntryIds[] = $reusedId;
				continue;
			}

			$saved = $this->saveObject(schema: 'UrenRegistratie', data: $this->hoursRow(batch: $batch, entry: $entry));
			$id    = (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
			if ($id === '') {
				throw new RuntimeException('Failed to materialise a UrenRegistratie row.');
			}

			$timeEntryIds[] = $id;
		}

		return $timeEntryIds;

	}//end materialiseEntries()

	/**
	 * The id of a same-batch row to reuse, or '' when there is none.
	 *
	 * @param array<string,mixed>|null $row The row found for the entry.
	 *
	 * @return string
	 */
	private function reusableRowId(?array $row): string {
		if ($row === null) {
			return '';
		}

		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));

	}//end reusableRowId()

	/**
	 * The UrenRegistratie row for one entry.
	 *
	 * @param array<string,mixed> $batch The batch, with its resolved rates.
	 * @param array<string,mixed> $entry The normalised entry.
	 *
	 * @return array<string,mixed>
	 */
	private function hoursRow(array $batch, array $entry): array {
		$description = $entry['description'];
		if ($description === '') {
			$description = sprintf('Imported from pipelinq (%s)', $entry['externalId']);
		}

		return [
			'administrationId' => $batch['administrationId'],
			'personId' => $batch['personId'],
			'date' => $entry['date'],
			'hours' => round(((float)$entry['minutes']) / 60, 4),
			'description' => $description,
			'projectId' => ($entry['projectRef'] ?? $batch['projectRef']),
			'externalId' => $entry['externalId'],
			'sourceApp' => 'pipelinq',
			'sourceBatchId' => $batch['batchId'],
			'recognisedRate' => $batch['rates'][$entry['externalId']],
		];

	}//end hoursRow()

	/**
	 * Draft the invoice for the materialised entries through the existing,
	 * unmodified draftInvoice() machinery.
	 *
	 * @param array<string,mixed> $batch        The batch, with its rate card id.
	 * @param array<int,string>   $timeEntryIds The time entry ids.
	 *
	 * @throws RuntimeException When drafting fails.
	 *
	 * @return array<string,mixed> The drafted invoice.
	 */
	private function draftBatchInvoice(array $batch, array $timeEntryIds): array {
		$genRequest = new InvoiceGenerationRequest(
			administrationId: $batch['administrationId'],
			billingModel: self::ALLOWED_BILLING_MODEL,
			customerId: $batch['organisationRef'],
			fromDate: $batch['periodStart'],
			toDate: $batch['periodEnd'],
			timeEntryIds: $timeEntryIds,
			expenseIds: [],
			rateCardId: $batch['rateCardId'],
			projectId: $batch['projectRef'],
			notes: $batch['notes'],
		);

		try {
			return $this->invoices->draftInvoice(request: $genRequest);
		} catch (\Throwable $e) {
			$this->logger->error('TimeIntakeService.ingest: draftInvoice failed: ' . $e->getMessage());
			throw new RuntimeException('Invoice drafting failed: ' . $e->getMessage());
		}

	}//end draftBatchInvoice()

	/**
	 * Store the TimeIntakeBatch ledger row that makes a replay idempotent.
	 *
	 * @param array<string,mixed> $batch     The batch.
	 * @param string              $invoiceId The drafted invoice id.
	 *
	 * @return void
	 */
	private function recordBatch(array $batch, string $invoiceId): void {
		$this->saveObject(
			schema: 'TimeIntakeBatch',
			data: [
				'administrationId' => $batch['administrationId'],
				'batchId' => $batch['batchId'],
				'sourceApp' => 'pipelinq',
				'organisationRef' => $batch['organisationRef'],
				'projectId' => $batch['projectRef'],
				'currency' => $batch['currency'],
				'periodStart' => $batch['periodStart'],
				'periodEnd' => $batch['periodEnd'],
				'entryCount' => count($batch['entries']),
				'status' => 'invoiced',
				'invoiceId' => $invoiceId,
				'receivedAt' => date('c'),
				'payloadHash' => $batch['payloadHash'],
			]
		);

	}//end recordBatch()

	/**
	 * Structurally validate + normalise the request body (HTTP 400 on failure).
	 *
	 * The returned tuple is, in order: batchId, organisationRef, billingModel,
	 * currency, periodStart, periodEnd, requestRateCardId, projectRef, notes,
	 * entries.
	 *
	 * @param array<string,mixed> $body Decoded JSON body.
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return array{0:string,1:string,2:string,3:string,4:string,5:string,6:?string,7:?string,8:?string,9:array<int,array<string,mixed>>}
	 */
	private function parseAndAssertStructure(array $body): array {
		$batchId = trim((string)($body['batchId'] ?? ''));
		if ($batchId === '') {
			throw new InvalidArgumentException('batchId is required.');
		}

		$organisationRef = trim((string)($body['organisationRef'] ?? ''));
		if ($organisationRef === '') {
			throw new InvalidArgumentException('organisationRef is required.');
		}

		$entriesRaw = ($body['entries'] ?? null);
		if (is_array($entriesRaw) === false || count($entriesRaw) === 0) {
			throw new InvalidArgumentException('entries must be a non-empty array.');
		}

		$period = [];
		if (is_array($body['period'] ?? null) === true) {
			$period = $body['period'];
		}

		$periodStart = (string)($period['start'] ?? '');
		$periodEnd = (string)($period['end'] ?? '');
		if ($this->isValidIsoDate(value: $periodStart) === false || $this->isValidIsoDate(value: $periodEnd) === false) {
			throw new InvalidArgumentException('period.start and period.end must be valid ISO dates.');
		}

		$entries = [];
		foreach ($entriesRaw as $entry) {
			if (is_array($entry) === false) {
				throw new InvalidArgumentException('Each entry must be an object.');
			}

			$externalId = trim((string)($entry['externalId'] ?? ''));
			$date = (string)($entry['date'] ?? '');
			if ($externalId === '' || $this->isValidIsoDate(value: $date) === false) {
				throw new InvalidArgumentException('Each entry requires a non-empty externalId and a valid ISO date.');
			}

			if (isset($entry['rateRef']) === true) {
				$rateRef = (string)$entry['rateRef'];
			} else {
				$rateRef = null;
			}

			if (isset($entry['projectRef']) === true) {
				$entryProjectRef = (string)$entry['projectRef'];
			} else {
				$entryProjectRef = null;
			}

			$entries[] = [
				'externalId' => $externalId,
				'date' => $date,
				'minutes' => ($entry['minutes'] ?? null),
				'description' => (string)($entry['description'] ?? ''),
				'hourlyRate' => ($entry['hourlyRate'] ?? null),
				'rateRef' => $rateRef,
				'projectRef' => $entryProjectRef,
			];
		}//end foreach

		$billingModel = (string)($body['billingModel'] ?? '');
		$currency = (string)($body['currency'] ?? 'EUR');

		if (isset($body['rateCardId']) === true && $body['rateCardId'] !== '') {
			$requestRateCardId = (string)$body['rateCardId'];
		} else {
			$requestRateCardId = null;
		}

		if (isset($body['projectRef']) === true) {
			$projectRef = (string)$body['projectRef'];
		} else {
			$projectRef = null;
		}

		if (isset($body['notes']) === true) {
			$notes = (string)$body['notes'];
		} else {
			$notes = null;
		}

		return [
			$batchId,
			$organisationRef,
			$billingModel,
			$currency,
			$periodStart,
			$periodEnd,
			$requestRateCardId,
			$projectRef,
			$notes,
			$entries,
		];

	}//end parseAndAssertStructure()

	/**
	 * Build the idempotent-replay response from a stored TimeIntakeBatch row.
	 *
	 * @param array<string,mixed> $batch Stored TimeIntakeBatch.
	 *
	 * @return array{invoiceId:string,invoiceNumber:string,status:string,lines:int,duplicated:bool}
	 */
	private function replayResponse(array $batch): array {
		$invoiceId = (string)($batch['invoiceId'] ?? '');
		$invoiceNumber = '';
		if ($invoiceId !== '') {
			$invoice = $this->find(schema: 'BillableInvoice', id: $invoiceId);
			if ($invoice !== null) {
				$invoiceNumber = (string)($invoice['invoiceNumber'] ?? '');
			}
		}

		return [
			'invoiceId' => $invoiceId,
			'invoiceNumber' => $invoiceNumber,
			'status' => 'draft',
			'lines' => (int)($batch['entryCount'] ?? 0),
			'duplicated' => true,
		];

	}//end replayResponse()

	/**
	 * Resolve one entry's rate in euros: an inline hourlyRate wins; otherwise
	 * an existing RateCard referenced by rateRef; otherwise unresolvable.
	 *
	 * @param array<string,mixed> $entry Normalised entry.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return float|null Rate in euros, or null when unresolvable.
	 */
	private function resolveEntryRate(array $entry, string $administrationId): ?float {
		$hourlyRate = $entry['hourlyRate'];
		if (is_numeric($hourlyRate) === true && (float)$hourlyRate > 0.0) {
			return (float)$hourlyRate;
		}

		$rateRef = $entry['rateRef'];
		if (is_string($rateRef) === true && $rateRef !== '') {
			$card = $this->findRateCardById(administrationId: $administrationId, rateCardId: $rateRef);
			if ($card !== null) {
				return (float)($card['hourlyRate'] ?? 0.0);
			}
		}

		return null;
	}//end resolveEntryRate()

	/**
	 * Resolve the single RateCard id passed to InvoiceGenerationRequest.
	 *
	 * When the request supplies its own rateCardId, it is verified to exist
	 * (and be scoped to the administration) and used as-is. Otherwise one
	 * RateCard is found-or-created from the first entry's resolved rate — see
	 * the class docblock for why this is a per-batch, not per-entry, rate.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string|null $requestRateCardId Request-level rateCardId, if supplied.
	 * @param array<int,array<string,mixed>> $entries Normalised entries.
	 * @param array<string,float> $resolvedRates externalId => resolved euro rate.
	 * @param string $periodStart ISO date.
	 * @param string $periodEnd ISO date.
	 *
	 * @return string|null The RateCard id to use, or null when unresolvable.
	 */
	private function resolveBatchRateCardId(
		string $administrationId,
		?string $requestRateCardId,
		array $entries,
		array $resolvedRates,
		string $periodStart,
		string $periodEnd,
	): ?string {
		if ($requestRateCardId !== null) {
			$card = $this->findRateCardById(administrationId: $administrationId, rateCardId: $requestRateCardId);
			if ($card === null) {
				return null;
			}

			return $requestRateCardId;
		}

		$firstExternalId = (string)($entries[0]['externalId'] ?? '');
		$firstRate = ($resolvedRates[$firstExternalId] ?? null);
		if ($firstRate === null) {
			return null;
		}

		$existing = $this->findAll(schema: 'RateCard', filters: ['administrationId' => $administrationId, 'hourlyRate' => $firstRate]);
		foreach ($existing as $card) {
			if (is_array($card) === false) {
				continue;
			}

			$id = (string)($card['id'] ?? ($card['@self']['id'] ?? ''));
			if ($id !== '') {
				return $id;
			}
		}

		$saved = $this->saveObject(
			schema: 'RateCard',
			data: [
				'administrationId' => $administrationId,
				'level' => 'senior',
				'currency' => 'EUR',
				'hourlyRate' => $firstRate,
				'effectiveFrom' => $periodStart,
				'effectiveTo' => $periodEnd,
				'description' => 'Auto-generated by the pipelinq time-intake ingress adapter.',
			]
		);

		$id = (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $id;
	}//end resolveBatchRateCardId()

	/**
	 * Look up an existing RateCard by its real object id, scoped to the administration.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $rateCardId Object id to look up.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findRateCardById(string $administrationId, string $rateCardId): ?array {
		$rows = $this->findAll(schema: 'RateCard', filters: ['id' => $rateCardId, 'administrationId' => $administrationId]);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findRateCardById()

	/**
	 * Look up a CustomerMaster row by customerId, scoped to the administration.
	 *
	 * @param string $organisationRef Requested organisationRef.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<string,mixed>|null
	 */
	private function resolveCustomer(string $organisationRef, string $administrationId): ?array {
		$rows = $this->findAll(schema: 'CustomerMaster', filters: ['customerId' => $organisationRef, 'administrationId' => $administrationId]);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end resolveCustomer()

	/**
	 * Look up an existing UrenRegistratie row by externalId, scoped to the administration.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $externalId Entry's external id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findHoursRegistrationByExternalId(string $administrationId, string $externalId): ?array {
		$rows = $this->findAll(schema: 'UrenRegistratie', filters: ['administrationId' => $administrationId, 'externalId' => $externalId]);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findUrenRegistratieByExternalId()

	/**
	 * Look up an existing TimeIntakeBatch row by (administrationId, batchId).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $batchId Idempotency key.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findBatch(string $administrationId, string $batchId): ?array {
		$rows = $this->findAll(schema: 'TimeIntakeBatch', filters: ['administrationId' => $administrationId, 'batchId' => $batchId]);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findBatch()

	/**
	 * Compute a stable sha256 fingerprint of the normalised request payload.
	 *
	 * @param string $organisationRef Organisation ref.
	 * @param string $billingModel Billing model.
	 * @param string $currency Currency.
	 * @param string $periodStart ISO date.
	 * @param string $periodEnd ISO date.
	 * @param array<int,array<string,mixed>> $entries Normalised entries.
	 *
	 * @return string
	 */
	private function computePayloadHash(
		string $organisationRef,
		string $billingModel,
		string $currency,
		string $periodStart,
		string $periodEnd,
		array $entries,
	): string {
		$normalised = [
			'organisationRef' => $organisationRef,
			'billingModel' => $billingModel,
			'currency' => $currency,
			'periodStart' => $periodStart,
			'periodEnd' => $periodEnd,
			'entries' => array_map(
				static function (array $entry): array {
					return [
						'externalId' => $entry['externalId'],
						'date' => $entry['date'],
						'minutes' => $entry['minutes'],
						'hourlyRate' => $entry['hourlyRate'],
						'rateRef' => $entry['rateRef'],
					];
				},
				$entries
			),
		];

		try {
			$encoded = json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		} catch (\JsonException $e) {
			$encoded = serialize($normalised);
		}

		return hash('sha256', $encoded);
	}//end computePayloadHash()

	/**
	 * Validate an ISO 8601 (`YYYY-MM-DD`) date string.
	 *
	 * @param string $value Candidate date string.
	 *
	 * @return bool
	 */
	private function isValidIsoDate(string $value): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}

		return strtotime($value) !== false;
	}//end isValidIsoDate()

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
			$rs = $this->objectService->setRegister($this->register())->setSchema($schema)->find($id);
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
	 * Find all matching records via the real OR ObjectService API (findAll).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rs = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);
			return $rs;
		} catch (\Throwable $e) {
			$this->logger->error(sprintf('TimeIntakeService findAll(%s) failed: %s', $schema, $e->getMessage()));
			return [];
		}

	}//end findAll()

	/**
	 * Save (create) via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed>
	 */
	private function saveObject(string $schema, array $data): array {
		$saved = $this->objectService->setRegister($this->register())->setSchema($schema)->saveObject($data);

		return $saved->jsonSerialize();
	}//end saveObject()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
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
