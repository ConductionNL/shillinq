<?php

/**
 * Aansluiting Service
 *
 * The Aansluiting (tie-out) framework's orchestrator (REQ-AANS-002,
 * REQ-AANS-004, REQ-AANS-006). Resolves an Aansluiting definition's source A
 * / source B totals by dispatching on `aansluitingType` to one of two
 * resolvers implemented in this change:
 *
 *  - `btw-ledger-aangifte`: reuses VATReturnService::computeCurrentDeclarations()
 *    (the live BTW-grootboek re-derivation) against
 *    VATReturnService::fetchFiledDeclarations() (the as-filed aangifte) — the
 *    exact diff btw-suppletie-detection's VatSuppletieDetectionService already
 *    computes. This service does not duplicate that diff logic for its own
 *    sake; it calls the same two VATReturnService methods and, when a
 *    VatCorrection already exists for the same VATReturn, cross-references it
 *    via `relatedVatCorrectionId` rather than creating a competing correction
 *    record (REQ-AANS-007 — integrate, don't duplicate).
 *  - `subledger-gl-control`: compares the GL control account's all-time
 *    cumulative balance (summed directly from GLLine, mirroring
 *    TrialBalanceService's own account-balance query but without a single
 *    period's movement scoping — a balance-sheet control account's tie-out
 *    target is its life-to-date balance, not one period's movement) against
 *    the sum of open ARInvoice/APTransaction records for the same
 *    administration. This is the exact comparison
 *    PeriodCloseAssistantService::detectOpenSubLedger() never makes — that
 *    method only counts draft/unposted GLTransactions, it never sums a
 *    control-account balance against a subledger total.
 *
 * Per ADR-031 this is deliberately imperative: both resolvers diff two
 * independently-queried data sources, apply an operator-overridable business
 * decision (tolerance -> status), and persist a derived record with a
 * bucket-level drill-down — cross-schema compilation logic, not a single
 * declarative aggregation (see design.md Decision — Declarative-vs-imperative,
 * ADR-031). The declarative shapes are documented on the AansluitingResult
 * schema's x-openregister-aggregations for the status-count rollups only;
 * the source-total resolution itself is this class, the same engine-side
 * fallback pattern TrialBalanceService, FluxService, and IcpService already
 * use.
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
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\AansluitingResolutionGuard;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes, explains, resolves, and reopens AansluitingResult records.
 *
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class AansluitingService {
	/**
	 * ARInvoice lifecycleState values counted as "open" (not yet settled)
	 * for the subledger-gl-control AR resolver (REQ-AANS-005).
	 *
	 * @var array<int,string>
	 */
	private const OPEN_AR_STATES = ['issued', 'overdue', 'disputed'];

	/**
	 * APTransaction state values counted as "open" (not yet settled) for the
	 * subledger-gl-control AP resolver (REQ-AANS-005).
	 *
	 * @var array<int,string>
	 */
	private const OPEN_AP_STATES = ['received', 'issued', 'partially-paid', 'overdue', 'disputed'];

	/**
	 * VATReturn statusCode values considered "filed" — only a filed return
	 * has a meaningful as-filed snapshot to tie out against (mirrors
	 * VatSuppletieDetectionService::detect()'s draft-return guard).
	 *
	 * @var array<int,string>
	 */
	private const FILED_VAT_RETURN_STATUSES = ['submitted', 'verified', 'filed'];

	/**
	 * Construct the service with OpenRegister's ObjectService injected
	 * (ADR-083 rule 1) and direct dependencies on the two services whose
	 * computation this class reuses rather than duplicating (REQ-AANS-002).
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param AansluitingCalculator $calculator Pure-logic tolerance/diff helper.
	 * @param VATReturnService $vatReturnService The BTW ledger-derivation engine this service diffs against.
	 * @param AansluitingResolutionGuard $resolutionGuard The ADR-031 exception guard for explained -> resolved.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly AansluitingCalculator $calculator,
		private readonly VATReturnService $vatReturnService,
		private readonly AansluitingResolutionGuard $resolutionGuard,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute (or recompute) the AansluitingResult for one Aansluiting
	 * definition + fiscal period (REQ-AANS-004).
	 *
	 * Resolves source A / source B per the definition's aansluitingType,
	 * computes the signed difference and tolerance decision, and persists an
	 * AansluitingResult. Idempotent per (aansluitingId, periodId): a result
	 * already `explained` or `resolved` is left untouched by recompute — the
	 * caller must `reopen()` it first (REQ-AANS-006, avoids silently
	 * clobbering an operator's explanation).
	 *
	 * @param string $reconciliationId The Aansluiting definition id.
	 * @param string $periodId The fiscal period to compute (e.g. '2026-Q2').
	 *
	 * @return array<string,mixed> The (created or updated) AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function compute(string $reconciliationId, string $periodId): array {
		$definition = $this->fetchReconciliation(reconciliationId: $reconciliationId);
		$existing = $this->fetchExistingResult(reconciliationId: $reconciliationId, periodId: $periodId);

		$existingStatus = 'open';
		if ($existing !== null) {
			$existingStatus = (string)($existing['status'] ?? 'open');
		}

		if ($existing !== null && $existingStatus !== 'open') {
			$this->logger->info(
				'AansluitingService: skipping recompute of a non-open result; reopen() first',
				['reconciliationId' => $reconciliationId, 'periodId' => $periodId, 'status' => $existingStatus]
			);

			return $existing;
		}

		$administrationId = (string)($definition['administrationId'] ?? '');
		$relationship = (string)($definition['expectedRelationship'] ?? 'equal');
		$toleranceCents = (int)($definition['toleranceCents'] ?? 100);
		$reconciliationType = (string)($definition['reconciliationType'] ?? '');

		$resolved = match ($reconciliationType) {
			'vat-ledger-return' => $this->resolveVatLedgerTaxReturn(administrationId: $administrationId, periodId: $periodId),
			'subledger-gl-control' => $this->resolveSubledgerGlControl(definition: $definition, administrationId: $administrationId),
			default => throw new RuntimeException(sprintf('Unsupported aansluitingType "%s"', $reconciliationType)),
		};

		$differenceCents = $this->calculator->differenceCents(
			sourceATotal: $resolved['sourceATotal'],
			sourceBTotal: $resolved['sourceBTotal'],
			relationship: $relationship
		);
		$withinTolerance = $this->calculator->isWithinTolerance(differenceCents: $differenceCents, toleranceCents: $toleranceCents);

		$totalRow = [
			'bucketKey' => 'TOTAL',
			'sourceAAmount' => $resolved['sourceATotal'],
			'sourceBAmount' => $resolved['sourceBTotal'],
			'deltaAmount' => $this->calculator->fromCents(cents: $differenceCents),
		];

		$result = [
			'reconciliationId' => $reconciliationId,
			'periodId' => $periodId,
			'computedAt' => $this->now(),
			'sourceATotal' => $resolved['sourceATotal'],
			'sourceBTotal' => $resolved['sourceBTotal'],
			'differenceCents' => $differenceCents,
			'withinTolerance' => $withinTolerance,
			'status' => 'open',
			'lineDeltas' => array_merge([$totalRow], $resolved['lineDeltas']),
			'explainedBy' => ($existing['explainedBy'] ?? null),
			'explainedAt' => ($existing['explainedAt'] ?? null),
			'explanationReasonCode' => ($existing['explanationReasonCode'] ?? null),
			'explanationReasonText' => ($existing['explanationReasonText'] ?? null),
			'resolvedBy' => null,
			'resolvedAt' => null,
			'relatedVatCorrectionId' => ($resolved['relatedVatCorrectionId'] ?? null),
			'administrationId' => $administrationId,
		];

		if ($withinTolerance === true) {
			// REQ-AANS-004: a within-tolerance difference auto-resolves;
			// nothing for an operator to explain.
			$result['status'] = 'resolved';
			$result['resolvedBy'] = 'system';
			$result['resolvedAt'] = $this->now();
		}

		if ($existing !== null) {
			$result['id'] = ($existing['id'] ?? ($existing['@self']['id'] ?? null));
		}

		return $this->saveObject(schema: 'AansluitingResult', data: $result);
	}//end compute()

	/**
	 * Record an operator's explanation for an open AansluitingResult and
	 * transition it to `explained` (REQ-AANS-006).
	 *
	 * @param string $resultId The AansluitingResult id.
	 * @param string $reasonCode One of timing/error/adjustment/other.
	 * @param string $reasonText Free-text operator explanation (required, non-empty).
	 * @param string $actor The acting user id (audit-trailed).
	 *
	 * @return array<string,mixed> The updated AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function explain(string $resultId, string $reasonCode, string $reasonText, string $actor): array {
		if (trim($reasonText) === '') {
			throw new RuntimeException('explanationReasonText is required to explain an AansluitingResult.');
		}

		$result = $this->fetchResult(resultId: $resultId);
		if ((string)($result['status'] ?? '') !== 'open') {
			throw new RuntimeException(
				sprintf('AansluitingResult %s is not open; only an open result can be explained.', $resultId)
			);
		}

		$result['status'] = 'explained';
		$result['explainedBy'] = $actor;
		$result['explainedAt'] = $this->now();
		$result['explanationReasonCode'] = $reasonCode;
		$result['explanationReasonText'] = $reasonText;

		return $this->saveObject(schema: 'AansluitingResult', data: $result);
	}//end explain()

	/**
	 * Confirm an explained AansluitingResult is settled and transition it to
	 * `resolved` (REQ-AANS-006). Gated by AansluitingResolutionGuard::canResolve
	 * (ADR-031 exception — requires a non-empty explanationReasonText).
	 *
	 * @param string $resultId The AansluitingResult id.
	 * @param string $actor The acting user id (audit-trailed).
	 *
	 * @return array<string,mixed> The updated AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function resolve(string $resultId, string $actor): array {
		$result = $this->fetchResult(resultId: $resultId);
		if ((string)($result['status'] ?? '') !== 'explained') {
			throw new RuntimeException(
				sprintf('AansluitingResult %s is not explained; only an explained result can be resolved.', $resultId)
			);
		}

		if ($this->resolutionGuard->canResolve(result: $result) === false) {
			throw new RuntimeException(
				sprintf('AansluitingResult %s does not satisfy the resolution guard.', $resultId)
			);
		}

		$result['status'] = 'resolved';
		$result['resolvedBy'] = $actor;
		$result['resolvedAt'] = $this->now();

		return $this->saveObject(schema: 'AansluitingResult', data: $result);
	}//end resolve()

	/**
	 * Reopen an explained or resolved AansluitingResult (REQ-AANS-006), e.g.
	 * because a later recompute would find fresh drift.
	 *
	 * @param string $resultId The AansluitingResult id.
	 * @param string $actor The acting user id (audit-trailed).
	 * @param string $reason Free-text reason for reopening (audit-trailed).
	 *
	 * @return array<string,mixed> The updated AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function reopen(string $resultId, string $actor, string $reason): array {
		$result = $this->fetchResult(resultId: $resultId);
		$status = (string)($result['status'] ?? '');
		if ($status !== 'explained' && $status !== 'resolved') {
			throw new RuntimeException(
				sprintf('AansluitingResult %s is already open.', $resultId)
			);
		}

		$result['status'] = 'open';
		$result['resolvedBy'] = null;
		$result['resolvedAt'] = null;
		$result['notes'] = sprintf('Reopened by %s: %s', $actor, $reason);

		return $this->saveObject(schema: 'AansluitingResult', data: $result);
	}//end reopen()

	/**
	 * Resolve source A (BTW-grootboek current) / source B (aangifte as
	 * filed) for a btw-ledger-aangifte Aansluiting (REQ-AANS-002).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Fiscal period (e.g. '2026-Q2').
	 *
	 * @return array{sourceATotal:float,sourceBTotal:float,lineDeltas:array<int,array<string,mixed>>,relatedVatCorrectionId:?string}
	 */
	private function resolveVatLedgerTaxReturn(string $administrationId, string $periodId): array {
		$vatReturn = $this->findFiledVatReturn(administrationId: $administrationId, periodId: $periodId);
		if ($vatReturn === null) {
			throw new RuntimeException(
				sprintf('No filed VATReturn found for administration %s, period %s.', $administrationId, $periodId)
			);
		}

		$returnId = (string)($vatReturn['id'] ?? ($vatReturn['@self']['id'] ?? ''));
		$startDate = (string)($vatReturn['startDate'] ?? '');
		$endDate = (string)($vatReturn['endDate'] ?? '');

		$current = $this->vatReturnService->computeCurrentDeclarations(
			administrationId: $administrationId,
			startDate: $startDate,
			endDate: $endDate
		);
		$filed = $this->vatReturnService->fetchFiledDeclarations(returnId: $returnId);

		$currentByKey = [];
		foreach ($current as $bucket) {
			$currentByKey[$this->rubriekKey(bucket: $bucket)] = (float)$bucket['totalVATAmount'];
		}

		$filedByKey = [];
		foreach ($filed as $bucket) {
			$filedByKey[$this->rubriekKey(bucket: $bucket)] = (float)$bucket['totalVATAmount'];
		}

		$lineDeltas = $this->calculator->diffBuckets(bucketsA: $currentByKey, bucketsB: $filedByKey, relationship: 'equal');

		return [
			'sourceATotal' => array_sum($currentByKey),
			'sourceBTotal' => array_sum($filedByKey),
			'lineDeltas' => $lineDeltas,
			'relatedVatCorrectionId' => $this->findRelatedVatCorrection(originalVatReturnId: $returnId),
		];

	}//end resolveBtwLedgerAangifte()

	/**
	 * Resolve source A (GL control account cumulative balance) / source B
	 * (open subledger total) for a subledger-gl-control Aansluiting
	 * (REQ-AANS-005) — the comparison
	 * PeriodCloseAssistantService::detectOpenSubLedger() never makes.
	 *
	 * @param array<string,mixed> $definition The Aansluiting definition record.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array{sourceATotal:float,sourceBTotal:float,lineDeltas:array<int,array<string,mixed>>,relatedVatCorrectionId:?string}
	 */
	private function resolveSubledgerGlControl(array $definition, string $administrationId): array {
		$controlAccountNumber = (string)($definition['controlAccountNumber'] ?? '');
		$subLedgerType = (string)($definition['subLedgerType'] ?? '');

		$sourceATotal = $this->controlAccountBalance(
			administrationId: $administrationId,
			accountNumber: $controlAccountNumber
		);

		$openItems = [];
		if ($subLedgerType === 'ar') {
			$openItems = $this->openArInvoices(administrationId: $administrationId);
		} elseif ($subLedgerType === 'ap') {
			$openItems = $this->openApTransactions(administrationId: $administrationId);
		}

		$sourceBTotal = 0.0;
		$itemBuckets = [];
		foreach ($openItems as $item) {
			$itemId = (string)($item['itemId'] ?? '');
			if ($itemId === '') {
				continue;
			}

			$sourceBTotal += $item['amount'];
			$itemBuckets[$itemId] = $item['amount'];
		}

		// Item-level rows only decompose sourceB (the GL control account
		// does not decompose per open item), so bucketsA is empty here — the
		// TOTAL row (added by compute()) carries the sourceA/sourceB summary.
		$lineDeltas = $this->calculator->diffBuckets(
			bucketsA: [],
			bucketsB: $itemBuckets,
			relationship: (string)($definition['expectedRelationship'] ?? 'equal')
		);

		return [
			'sourceATotal' => $sourceATotal,
			'sourceBTotal' => $sourceBTotal,
			'lineDeltas' => $lineDeltas,
			'relatedVatCorrectionId' => null,
		];

	}//end resolveSubledgerGlControl()

	/**
	 * Sum a GL account's all-time cumulative balance (debit - credit, whole
	 * cents) across every non-eliminated GLLine for the administration. A
	 * balance-sheet control account's tie-out target is its life-to-date
	 * balance, not a single period's movement (contrast
	 * TrialBalanceService::compute(), which is deliberately period-scoped).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $accountNumber The GL control account number.
	 *
	 * @return float The cumulative balance in EUR (debit-positive convention).
	 */
	private function controlAccountBalance(string $administrationId, string $accountNumber): float {
		if ($accountNumber === '') {
			return 0.0;
		}

		$register = $this->register();

		$transactions = $this->objectService
			->setRegister($register)
			->setSchema('GLTransaction')
			->findAll(['filters' => ['administrationId' => $administrationId]]);
		$transactionIds = [];
		foreach ($transactions as $transaction) {
			$id = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
			if ($id !== '') {
				$transactionIds[$id] = true;
			}
		}

		$lines = $this->objectService
			->setRegister($register)
			->setSchema('GLLine')
			->findAll(['filters' => ['accountNumber' => $accountNumber]]);

		$balanceCents = 0;
		foreach ($lines as $line) {
			if (($line['eliminationFlag'] ?? false) === true) {
				continue;
			}

			$transactionId = (string)($line['transactionId'] ?? '');
			if ($transactionIds !== [] && isset($transactionIds[$transactionId]) === false) {
				continue;
			}

			$cents = $this->calculator->toCents(amount: ($line['amount'] ?? 0));
			if (($line['side'] ?? '') === 'debit') {
				$balanceCents += $cents;
			} else {
				$balanceCents -= $cents;
			}
		}

		return $this->calculator->fromCents(cents: $balanceCents);
	}//end controlAccountBalance()

	/**
	 * Fetch every ARInvoice for the administration in an "open" lifecycle
	 * state (REQ-AANS-005).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array{itemId:string,amount:float}>
	 */
	private function openArInvoices(string $administrationId): array {
		$invoices = $this->objectService
			->setRegister($this->register())
			->setSchema('ARInvoice')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$open = [];
		foreach ($invoices as $invoice) {
			$state = (string)($invoice['lifecycleState'] ?? '');
			if (in_array($state, self::OPEN_AR_STATES, true) === false) {
				continue;
			}

			$itemId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ($invoice['@self']['slug'] ?? '')));
			$amount = (float)($invoice['grossAmount'] ?? (((float)($invoice['netAmount'] ?? 0)) + ((float)($invoice['vatAmount'] ?? 0))));

			$open[] = ['itemId' => $itemId, 'amount' => $amount];
		}

		return $open;
	}//end openArInvoices()

	/**
	 * Fetch every APTransaction for the administration in an "open"
	 * lifecycle state (REQ-AANS-005).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array{itemId:string,amount:float}>
	 */
	private function openApTransactions(string $administrationId): array {
		$transactions = $this->objectService
			->setRegister($this->register())
			->setSchema('APTransaction')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$open = [];
		foreach ($transactions as $transaction) {
			$state = (string)($transaction['state'] ?? '');
			if (in_array($state, self::OPEN_AP_STATES, true) === false) {
				continue;
			}

			$itemId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ($transaction['@self']['slug'] ?? '')));
			$amount = (float)($transaction['totalAmount'] ?? 0);

			$open[] = ['itemId' => $itemId, 'amount' => $amount];
		}

		return $open;
	}//end openApTransactions()

	/**
	 * Find the filed VATReturn matching an administration + period string
	 * (e.g. '2026-Q2' or '2026-06'), mirroring IcpService's own period-string
	 * derivation for VatReturn lookups.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Fiscal period string.
	 *
	 * @return array<string,mixed>|null The VATReturn record, or null when none is filed for the period.
	 */
	private function findFiledVatReturn(string $administrationId, string $periodId): ?array {
		$returns = $this->objectService
			->setRegister($this->register())
			->setSchema('BtwAangifte')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($returns as $return) {
			$status = (string)($return['statusCode'] ?? '');
			if (in_array($status, self::FILED_VAT_RETURN_STATUSES, true) === false) {
				continue;
			}

			if ($this->vatReturnPeriodString(vatReturn: $return) === $periodId) {
				return $return;
			}
		}

		return null;
	}//end findFiledVatReturn()

	/**
	 * Derive a VATReturn's period string from its period/periodYear/periodNumber fields.
	 *
	 * @param array<string,mixed> $vatReturn The VATReturn record.
	 *
	 * @return string The period string (YYYY-Qn or YYYY-MM), or '' when undetermined.
	 */
	private function vatReturnPeriodString(array $vatReturn): string {
		$year = (string)($vatReturn['periodYear'] ?? '');
		$number = (string)($vatReturn['periodNumber'] ?? '');
		if ($year === '' || $number === '') {
			return '';
		}

		if ((string)($vatReturn['period'] ?? '') === 'month') {
			return $year . '-' . str_pad($number, 2, '0', STR_PAD_LEFT);
		}

		return $year . '-Q' . $number;
	}//end vatReturnPeriodString()

	/**
	 * Look up an existing VatCorrection created by btw-suppletie-detection
	 * for the same VATReturn, so the AansluitingResult can cross-reference it
	 * instead of duplicating the correction workflow (REQ-AANS-007).
	 *
	 * @param string $originalVatReturnId The VATReturn id.
	 *
	 * @return string|null The VatCorrection id, or null when none exists.
	 */
	private function findRelatedVatCorrection(string $originalVatReturnId): ?string {
		if ($originalVatReturnId === '') {
			return null;
		}

		$corrections = $this->objectService
			->setRegister($this->register())
			->setSchema('VatCorrection')
			->findAll(['filters' => ['originalVatReturnId' => $originalVatReturnId]]);

		foreach ($corrections as $correction) {
			return (string)($correction['id'] ?? ($correction['@self']['id'] ?? ''));
		}

		return null;
	}//end findRelatedVatCorrection()

	/**
	 * Build the type:taxRate rubriek bucket key, matching
	 * VatSuppletieDetectionService's bucketKey() convention.
	 *
	 * @param array<string,mixed> $bucket A VATReturnService declaration bucket.
	 *
	 * @return string
	 */
	private function rubriekKey(array $bucket): string {
		return ((string)$bucket['type']) . ':' . number_format((float)$bucket['taxRate'], 2, '.', '');
	}//end rubriekKey()

	/**
	 * Fetch an Aansluiting definition by id.
	 *
	 * @param string $reconciliationId Aansluiting id.
	 *
	 * @return array<string,mixed>
	 */
	private function fetchReconciliation(string $reconciliationId): array {
		$definition = $this->objectService
			->setRegister($this->register())
			->setSchema('Aansluiting')
			->find($reconciliationId);

		if ($definition === null) {
			throw new RuntimeException(sprintf('Aansluiting %s not found', $reconciliationId));
		}

		return $definition->jsonSerialize();
	}//end fetchAansluiting()

	/**
	 * Fetch an AansluitingResult by id.
	 *
	 * @param string $resultId AansluitingResult id.
	 *
	 * @return array<string,mixed>
	 */
	private function fetchResult(string $resultId): array {
		$result = $this->objectService
			->setRegister($this->register())
			->setSchema('AansluitingResult')
			->find($resultId);

		if ($result === null) {
			throw new RuntimeException(sprintf('AansluitingResult %s not found', $resultId));
		}

		return $result->jsonSerialize();
	}//end fetchResult()

	/**
	 * Find an already-computed AansluitingResult for (aansluitingId, periodId), if any.
	 *
	 * @param string $reconciliationId Aansluiting id.
	 * @param string $periodId Fiscal period.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchExistingResult(string $reconciliationId, string $periodId): ?array {
		$results = $this->objectService
			->setRegister($this->register())
			->setSchema('AansluitingResult')
			->findAll(['filters' => ['reconciliationId' => $reconciliationId, 'periodId' => $periodId]]);

		foreach ($results as $result) {
			return $result;
		}

		return null;
	}//end fetchExistingResult()

	/**
	 * Persist a record via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed> The saved record (with id).
	 */
	private function saveObject(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		return $saved->jsonSerialize();
	}//end saveObject()

	/**
	 * Current UTC timestamp, ISO 8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
