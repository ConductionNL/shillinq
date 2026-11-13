<?php

/**
 * Period Close Assistant Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tier-2 AI close-assistant task detection (REQ-PC-004). Scans the ledger for
 * the incomplete pre-close tasks a guided month-end / quarter-end close must
 * surface, and formats them as non-blocking warning flags (design D3):
 *
 *  - Open AP transactions: unposted (draft) GLTransactions in the period whose
 *    lines carry subLedgerType "ap".
 *  - Open AR transactions: unposted (draft) GLTransactions in the period whose
 *    lines carry subLedgerType "ar".
 *  - Unreconciled bank receipts: bank statements dated in the period that report
 *    movements but have no posted GLTransaction referencing them.
 *  - Outstanding expense claims: ExpenseClaimEntry rows whose approvalState is
 *    pending (awaiting approval / reimbursement).
 *
 * All queries go through the real OpenRegister ObjectService API (find / findAll)
 * and are scoped to a single administration + period. Detection is deterministic
 * and fully unit-testable; the resulting flags are *warnings*, never blockers —
 * operators review and resolve them manually. This service deliberately does not
 * call an LLM: Shillinq ships no ChatService dependency, so the narrative is
 * generated from the deterministic detection summary. When a fleet ChatService
 * lands, generateFlags() is the single seam to enrich the message text.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Detects incomplete pre-close tasks and formats them as close-assistant flags.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
 */
class PeriodCloseAssistantService {

	/**
	 * Flag categories in emission order, with their severity. Unreconciled bank
	 * receipts are informational (design D3 — "should verify"); the aged
	 * suspense worklist is a BLOCKER (payment-control-guards REQ-PCG-002/003:
	 * the close is refused while it is non-empty), so it is an error.
	 *
	 * @var array<string,string>
	 */
	private const FLAG_SEVERITIES = [
		'ap' => 'warning',
		'ar' => 'warning',
		'bank' => 'info',
		'expense-claims' => 'warning',
		'suspense' => 'error',
	];
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param SuspenseAgeingService $suspenseAgeing Aged suspense worklist source (REQ-PCG-002).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SuspenseAgeingService $suspenseAgeing,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Run every detector and return the consolidated flag list (REQ-PC-004).
	 *
	 * @param string $administrationId The administration scope (server-resolved, REQ-PC-008).
	 * @param string $periodId The period to analyse.
	 * @param string $endDate The period end date (ISO date) for date filtering.
	 *
	 * @return array<int,array<string,mixed>> Flag objects {id, severity, message, category, detectedAt}.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
	 */
	public function analyse(string $administrationId, string $periodId, string $endDate = ''): array {
		$detections = [
			'ap' => $this->detectOpenSubLedger(administrationId: $administrationId, periodId: $periodId, subLedgerType: 'ap'),
			'ar' => $this->detectOpenSubLedger(administrationId: $administrationId, periodId: $periodId, subLedgerType: 'ar'),
			'bank' => $this->detectUnreconciledBankReceipts(administrationId: $administrationId, periodId: $periodId, endDate: $endDate),
			'expense-claims' => $this->detectOutstandingExpenseClaims(administrationId: $administrationId),
			'suspense' => $this->detectAgedSuspense(administrationId: $administrationId, endDate: $endDate),
		];

		return $this->generateFlags(detections: $detections);
	}//end analyse()

	/**
	 * Count unposted (draft) transactions carrying the given sub-ledger type (REQ-PC-004).
	 *
	 * Open AP / AR work is represented by GLTransactions still in the draft state
	 * for the period whose GLLines carry the matching subLedgerType. Returns the
	 * distinct transaction count and the total of the matching debit/credit lines.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The period.
	 * @param string $subLedgerType 'ap' or 'ar'.
	 *
	 * @return array{count:int, total:float} Detection summary.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
	 */
	public function detectOpenSubLedger(string $administrationId, string $periodId, string $subLedgerType): array {
		$draftIds = $this->draftTransactionIds(administrationId: $administrationId, periodId: $periodId);
		if ($draftIds === []) {
			return ['count' => 0, 'total' => 0.0];
		}

		$lines = $this->find(schema: 'GLLine', filters: ['periodId' => $periodId, 'subLedgerType' => $subLedgerType]);

		return $this->draftLineSummary(lines: $lines, draftIds: $draftIds);

	}//end detectOpenSubLedger()

	/**
	 * The ids of the period's draft GLTransactions, as a set.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId         The period.
	 *
	 * @return array<string,true>
	 */
	private function draftTransactionIds(string $administrationId, string $periodId): array {
		$filters = ['administrationId' => $administrationId, 'periodId' => $periodId, 'state' => 'draft'];
		$draftIds = [];
		foreach ($this->find(schema: 'GLTransaction', filters: $filters) as $transaction) {
			$id = $this->idOf(record: (array)$transaction);
			if ($id !== '') {
				$draftIds[$id] = true;
			}
		}

		return $draftIds;

	}//end draftTransactionIds()

	/**
	 * Distinct draft transactions and the cent total of their matching lines.
	 *
	 * @param array<int,mixed>   $lines    GLLines of the sub-ledger type.
	 * @param array<string,true> $draftIds Draft transaction ids.
	 *
	 * @return array{count:int, total:float}
	 */
	private function draftLineSummary(array $lines, array $draftIds): array {
		$matchedTransactions = [];
		$totalCents = 0;
		foreach ($lines as $line) {
			$line = (array)$line;
			$transactionId = (string)($line['transactionId'] ?? '');
			if (isset($draftIds[$transactionId]) === true) {
				$matchedTransactions[$transactionId] = true;
				$totalCents += (int)round(((float)($line['amount'] ?? 0)) * 100);
			}
		}

		return ['count' => count($matchedTransactions), 'total' => ((float)$totalCents / 100)];

	}//end draftLineSummary()

	/**
	 * Count bank statements in the period that have no posted GL match (REQ-PC-004).
	 *
	 * A bank statement reporting movements (transactionCount > 0) dated on or
	 * before the period end with no posted GLTransaction whose sourceReference
	 * points at it is flagged as unreconciled.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The period.
	 * @param string $endDate The period end date (ISO date); '' to skip date filtering.
	 *
	 * @return array{count:int, total:float} Detection summary (total is the receipt count, no euro total).
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
	 */
	public function detectUnreconciledBankReceipts(string $administrationId, string $periodId, string $endDate = ''): array {
		$statements = $this->statementsWithMovements(
			statements: $this->find(schema: 'BankStatement', filters: ['administrationId' => $administrationId]),
			endDate: $endDate
		);
		$postedReferences = $this->postedReferences(administrationId: $administrationId, periodId: $periodId);
		$unreconciled = count(
			array_filter($statements, fn (array $statement): bool => $this->isUnposted(statement: $statement, postedReferences: $postedReferences))
		);

		return ['count' => $unreconciled, 'total' => (float)$unreconciled];
	}//end detectUnreconciledBankReceipts()

	/**
	 * The statements reporting movements, dated on or before the period end
	 * when one is given.
	 *
	 * @param array<int,mixed> $statements BankStatements of the administration.
	 * @param string           $endDate    The period end date (ISO date); '' to skip date filtering.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function statementsWithMovements(array $statements, string $endDate): array {
		$kept = [];
		foreach ($statements as $statement) {
			$statement = (array)$statement;
			$inPeriod = ($endDate === '' || (string)($statement['statementDate'] ?? '') <= $endDate);
			if ((int)($statement['transactionCount'] ?? 0) > 0 && $inPeriod === true) {
				$kept[] = $statement;
			}
		}

		return $kept;
	}//end statementsWithMovements()

	/**
	 * The sourceReference set of the period's posted GL transactions — the
	 * statements they point at have already been reconciled / posted.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId         The period.
	 *
	 * @return array<string,true>
	 */
	private function postedReferences(string $administrationId, string $periodId): array {
		$filters = ['administrationId' => $administrationId, 'periodId' => $periodId, 'state' => 'posted'];
		$postedReferences = [];
		foreach ($this->find(schema: 'GLTransaction', filters: $filters) as $transaction) {
			$reference = (string)(((array)$transaction)['sourceReference'] ?? '');
			if ($reference !== '') {
				$postedReferences[$reference] = true;
			}
		}

		return $postedReferences;
	}//end postedReferences()

	/**
	 * Whether no posted GL transaction points at a statement.
	 *
	 * @param array<string,mixed> $statement        A BankStatement.
	 * @param array<string,true>  $postedReferences Posted sourceReferences.
	 *
	 * @return boolean
	 */
	private function isUnposted(array $statement, array $postedReferences): bool {
		$statementId = $this->idOf(record: $statement);

		return $statementId === '' || isset($postedReferences[$statementId]) === false;
	}//end isUnposted()

	/**
	 * Count expense claims awaiting approval / reimbursement (REQ-PC-004).
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array{count:int, total:float} Detection summary (total is the claimed euro amount).
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
	 */
	public function detectOutstandingExpenseClaims(string $administrationId): array {
		$claims = $this->find(schema: 'ExpenseClaimEntry', filters: ['administrationId' => $administrationId, 'approvalState' => 'pending']);
		$count = 0;
		$totalCents = 0;
		foreach ($claims as $claim) {
			$claim = (array)$claim;
			$count++;
			$totalCents += (int)round(((float)($claim['totalAmount'] ?? 0)) * 100);
		}

		return ['count' => $count, 'total' => ((float)$totalCents / 100)];
	}//end detectOutstandingExpenseClaims()

	/**
	 * Summarise the aged bank-reconciliation suspense worklist (REQ-PCG-002).
	 *
	 * Surfaces unmatched / routed-to-suspense bank items and their oldest age so
	 * the operator sees, before attempting to close, why the close will be
	 * blocked (REQ-PCG-003). Delegates the ageing to SuspenseAgeingService.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $endDate The period end date (ISO date) to age against; '' means today.
	 *
	 * @return array{count:int, total:float, oldest:int} Detection summary (total is the euro amount).
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md (REQ-PCG-002)
	 */
	public function detectAgedSuspense(string $administrationId, string $endDate = ''): array {
		$ageing = $this->suspenseAgeing->agedUnmatchedItems(administrationId: $administrationId, asOf: $endDate);

		return [
			'count' => (int)$ageing['count'],
			'total' => ((float)$ageing['totalAmountCents'] / 100),
			'oldest' => (int)$ageing['oldestDaysOutstanding'],
		];

	}//end detectAgedSuspense()

	/**
	 * Format the detection summary into close-assistant flags (REQ-PC-004).
	 *
	 * Categories with zero detections produce no flag (a clean period yields an
	 * empty flag list). Each flag is a warning except unreconciled bank receipts,
	 * which are surfaced as informational (design D3 — "should verify").
	 *
	 * @param array<string,array{count:int,total:float}> $detections Per-category detection summaries.
	 *
	 * @return array<int,array<string,mixed>> The flag objects.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
	 */
	public function generateFlags(array $detections): array {
		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$flags = [];
		foreach (self::FLAG_SEVERITIES as $category => $severity) {
			$detection = ($detections[$category] ?? ['count' => 0, 'total' => 0.0]);
			if ($detection['count'] > 0) {
				$message = $this->flagMessage(category: $category, detection: $detection);
				$flags[] = ['id' => 'flag-' . $category, 'severity' => $severity, 'category' => $category, 'message' => $message, 'detectedAt' => $now];
			}
		}

		return $flags;
	}//end generateFlags()

	/**
	 * The operator-facing text of one category's flag.
	 *
	 * @param string                                       $category  The flag category.
	 * @param array{count:int,total:float,oldest?:int}     $detection The category's detection summary.
	 *
	 * @return string
	 */
	private function flagMessage(string $category, array $detection): string {
		$count = (string)$detection['count'];
		$total = $this->euro(amount: $detection['total']);

		return match ($category) {
			'ap' => $count . ' outstanding AP transaction(s) totalling ' . $total . ' require payment or accrual before period close',
			'ar' => $count . ' outstanding AR transaction(s) totalling ' . $total . ' are not yet collected or provisioned',
			'bank' => $count . ' bank statement(s) in this period have no matching GL posting',
			'expense-claims' => $count . ' expense claim(s) totalling ' . $total . ' are awaiting approval or reimbursement',
			default => $count . ' unmatched bank/suspense item(s) totalling ' . $total . ' remain (oldest '
				. ((int)($detection['oldest'] ?? 0)) . ' day(s) outstanding) — the period cannot be closed until they are resolved',
		};
	}//end flagMessage()

	/**
	 * Format a euro amount for a flag message.
	 *
	 * @param float $amount The amount.
	 *
	 * @return string The formatted amount, e.g. "€5,200.00".
	 */
	private function euro(float $amount): string {
		return '€' . number_format($amount, 2, '.', ',');
	}//end euro()

	/**
	 * Extract the record id from an OpenRegister object's shape (top-level or @self).
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return string The id, or '' when absent.
	 */
	private function idOf(array $record): string {
		$id = ($record['id'] ?? ($record['@self']['id'] ?? null));
		if ($id === null) {
			return '';
		}

		return (string)$id;
	}//end idOf()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Query one schema of the configured register.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return array<int,mixed>
	 */
	private function find(string $schema, array $filters): array {
		return $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);
	}//end find()
}//end class
