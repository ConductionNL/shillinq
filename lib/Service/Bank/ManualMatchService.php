<?php

/**
 * Pairs an unmatched bank line with invoices, or books it to a ledger account, by hand.
 *
 * Invoices: a credit line (money in) pays open sales invoices, a debit line
 * (money out) pays open AP transactions. Several invoices must add up to the
 * line exactly; one invoice larger than the line is a part payment recorded
 * as partial with its remainder. A selection larger than the line, or a line
 * larger than the selection, is refused.
 *
 * Ledger account: a balanced JournalEntry between the bank's ledger account
 * and the chosen account, with the VAT split out when a rate and a VAT
 * account are given, posted through its declared `postDirect` transition so
 * the balance and period guards run.
 *
 * Either way the match is written as a ReconciliationMatch and confirmed
 * through its declared `confirm` transition. That transition is what
 * ReconciliationMatchSettlementListener settles on, so a manual match pays its
 * invoices through the same path as a rule or the bank feed. The line moves to
 * matched through its own `mark-matched` transition.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Bank;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;

/**
 * Manual bank line matching (REQ-BMM-001, REQ-BMM-002).
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One service writes the match, the journal and the line.
 */
class ManualMatchService {
	/**
	 * Amounts closer than this are equal (half a cent).
	 *
	 * @var float
	 */
	private const TOLERANCE = 0.005;

	/**
	 * Payable states per invoice schema.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const PAYABLE = [
		'ARInvoice' => ['issued', 'overdue'],
		'APTransaction' => ['issued', 'overdue', 'partially-paid'],
	];

	/**
	 * Statement states in which a line can no longer be matched.
	 *
	 * @var array<int,string>
	 */
	private const CLOSED_STATEMENT = ['reconciled', 'audit-locked'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param ObjectTransitionRunner $transitions   Runs the declared transitions.
	 * @param SettingsService        $settings      Register slug.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunner $transitions,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Read a bank line by uuid or by its `lineId`.
	 *
	 * @param string $lineId The line's uuid or business id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When the line does not exist.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function findLine(string $lineId): array {
		$line = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'BankStatementLine'), id: $lineId, fallbackProperty: 'lineId');
		if ($line === null) {
			throw new OutOfBoundsException('bank line ' . $lineId . ' not found');
		}

		$line = (ObjectIdentifier::recordWithId(candidate: $line) ?? $line);
		return $line;

	}//end findLine()

	/**
	 * Match a line to open invoices.
	 *
	 * @param array<string,mixed> $line      The line, from findLine().
	 * @param array<int,string>   $targetIds Invoice uuids.
	 * @param string              $actor     The confirming user id, or `system:bankfeed`.
	 * @param string              $reason    Why the match was made; '' for a match by hand.
	 *
	 * @return array<string,mixed> The confirmed match.
	 *
	 * @throws ManualMatchRefusedException When the line or the selection cannot be matched.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function matchInvoices(array $line, array $targetIds, string $actor, string $reason = ''): array {
		$this->assertMatchable(line: $line);
		$targetIds = array_values(array_unique(array_filter(array_map('strval', $targetIds), static fn (string $id): bool => $id !== '')));
		if ($targetIds === []) {
			throw new ManualMatchRefusedException(template: 'Select at least one invoice.');
		}

		$amount = (float)($line['amount'] ?? 0);
		$lineAmount = abs($amount);
		$schema = 'APTransaction';
		$type = 'ap-invoice';
		if ($amount >= 0) {
			$schema = 'ARInvoice';
			$type = 'ar-invoice';
		}

		$invoices = $this->readInvoices(schema: $schema, ids: $targetIds, administrationId: (string)($line['administrationId'] ?? ''));
		$total = array_sum(array_map(fn (array $invoice): float => $this->invoiceAmount(schema: $schema, invoice: $invoice), $invoices));

		$partial = false;
		$remainder = 0.0;
		if ($total - $lineAmount > self::TOLERANCE) {
			if (count($invoices) > 1) {
				throw new ManualMatchRefusedException(
					template: 'The selection exceeds the bank line by %1$s.',
					parameters: [self::money(amount: $total - $lineAmount)]
				);
			}

			$partial = true;
			$remainder = round($total - $lineAmount, 2);
		}

		if ($lineAmount - $total > self::TOLERANCE) {
			throw new ManualMatchRefusedException(
				template: 'The bank line exceeds the selection by %1$s.',
				parameters: [self::money(amount: $lineAmount - $total)]
			);
		}

		$extra = [
			'isPartial' => $partial,
			'partial' => $partial,
			'resolutionReason' => 'Matched by hand',
		];
		if ($partial === true) {
			$extra['resolutionReason'] = 'Part payment by hand, remainder ' . self::money(amount: $remainder);
		}

		if ($reason !== '') {
			$extra['resolutionReason'] = $reason;
		}

		// The T4 shortcut field that names the first invoice.
		$shortcut = ['ARInvoice' => 'arInvoiceId', 'APTransaction' => 'apTransactionId'][$schema];
		$extra[$shortcut] = $targetIds[0];

		$match = $this->writeConfirmedMatch(line: $line, type: $type, targetIds: $targetIds, matchedAmount: $lineAmount, actor: $actor, extra: $extra);
		$match['remainder'] = $remainder;
		return $match;

	}//end matchInvoices()

	/**
	 * Match a line to a payment plan (receivables-payment-plans REQ-RPPL-003).
	 *
	 * The match names the plan's invoices and is partial, so the settlement
	 * listener leaves them to PaymentPlanAllocator, which pays the instalments
	 * and moves an invoice to paid once nothing is left due.
	 *
	 * @param array<string,mixed> $line   The line, from findLine().
	 * @param array<string,mixed> $plan   The active plan, with its id.
	 * @param string              $actor  The confirming user id, or `system:bankfeed`.
	 * @param string              $reason Why the match was made.
	 *
	 * @return array<string,mixed> The confirmed match.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be matched.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function matchPaymentPlan(array $line, array $plan, string $actor, string $reason): array {
		$this->assertMatchable(line: $line);
		if ((float)($line['amount'] ?? 0) <= 0) {
			throw new ManualMatchRefusedException(template: 'Only money received can pay a payment plan.');
		}

		$invoiceIds = array_values(array_map('strval', (array)($plan['invoiceIds'] ?? [])));
		if ($invoiceIds === []) {
			throw new ManualMatchRefusedException(template: 'Select at least one invoice.');
		}

		$extra = [
			'isPartial' => true,
			'partial' => true,
			'paymentPlanId' => (string)$plan['id'],
			'arInvoiceId' => $invoiceIds[0],
			'resolutionReason' => $reason,
		];
		return $this->writeConfirmedMatch(
			line: $line,
			type: 'ar-invoice',
			targetIds: $invoiceIds,
			matchedAmount: abs((float)$line['amount']),
			actor: $actor,
			extra: $extra
		);

	}//end matchPaymentPlan()

	/**
	 * Match a line to the one object payment request it quotes and confirm it
	 * (REQ-ORS-003). The confirm transition is what settles the request.
	 *
	 * @param array<string,mixed> $line    The line, with its id.
	 * @param array<string,mixed> $request The pending PaymentRequest, with its id.
	 * @param string              $actor   The confirming user id, or `system:bank-match`.
	 *
	 * @return array<string,mixed> The confirmed match.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be matched.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function matchPaymentRequest(array $line, array $request, string $actor): array {
		$this->assertMatchable(line: $line);

		return $this->writeConfirmedMatch(
			line: $line,
			type: 'payment-request',
			targetIds: [(string)$request['id']],
			matchedAmount: abs((float)($line['amount'] ?? 0)),
			actor: $actor,
			extra: self::paymentRequestExtra(request: $request)
		);

	}//end matchPaymentRequest()

	/**
	 * Propose a line as a candidate for an object payment request, for the
	 * bookkeeper to confirm or reject; nothing is settled (REQ-ORS-003). The
	 * same line and request are proposed once.
	 *
	 * @param array<string,mixed> $line    The line, with its id.
	 * @param array<string,mixed> $request The pending PaymentRequest, with its id.
	 *
	 * @return array<string,mixed> The pending match, as stored.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be matched.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function proposePaymentRequest(array $line, array $request): array {
		$this->assertMatchable(line: $line);

		$payload = self::buildMatch(
			line: $line,
			type: 'payment-request',
			targetIds: [(string)$request['id']],
			matchedAmount: abs((float)($line['amount'] ?? 0)),
			extra: array_merge(
				self::paymentRequestExtra(request: $request),
				['confidence' => 'auto', 'matchAlgorithm' => 'exact', 'manualOverride' => false, 'resolutionStatus' => 'pending']
			)
		);
		$existing = $this->scoped(schema: 'ReconciliationMatch')->findAll(['filters' => ['matchId' => $payload['matchId']], 'limit' => 1]);
		$known = ObjectIdentifier::recordWithId(candidate: ($existing[0] ?? null));
		if ($known !== null) {
			return $known;
		}

		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'ReconciliationMatch')->saveObject($payload));
		return ($saved ?? $payload);

	}//end proposePaymentRequest()

	/**
	 * The fields a payment-request match carries beyond the common ones.
	 *
	 * @param array<string,mixed> $request The PaymentRequest.
	 *
	 * @return array<string,mixed>
	 */
	private static function paymentRequestExtra(array $request): array {
		return [
			'isPartial' => false,
			'partial' => false,
			'resolutionReason' => sprintf('Reference %s quoted on the bank line.', (string)($request['paymentReference'] ?? '')),
		];

	}//end paymentRequestExtra()

	/**
	 * Book a line to a ledger account.
	 *
	 * @param array<string,mixed> $line   The line, from findLine().
	 * @param array<string,mixed> $ledger `accountNumber`, optional `vatRate` with `vatAccountNumber`, `description`.
	 * @param string              $actor  The confirming user id.
	 *
	 * @return array<string,mixed> The confirmed match, with `journalEntryId`.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be booked.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function bookToLedger(array $line, array $ledger, string $actor): array {
		$this->assertMatchable(line: $line);
		$account = trim((string)($ledger['accountNumber'] ?? ''));
		if ($account === '') {
			throw new ManualMatchRefusedException(template: 'Choose a ledger account.');
		}

		$bankAccount = $this->bankLedgerAccount(line: $line);
		$journal = $this->buildJournalEntry(line: $line, bankAccount: $bankAccount, ledger: $ledger);
		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'JournalEntry')->saveObject($journal));
		$journalId = (string)($saved['id'] ?? '');
		$this->transitions->run(objectId: $journalId, action: 'postDirect');

		$posted = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'JournalEntry'), id: $journalId);
		$glTransactionId = (string)($posted['glTransactionId'] ?? '');
		if ($glTransactionId === '') {
			$this->logger->warning('ManualMatchService: posted journal entry carries no glTransactionId', ['journalEntryId' => $journalId]);
			$glTransactionId = $journalId;
		}

		$match = $this->writeConfirmedMatch(
			line: $line,
			type: 'gl-transaction',
			targetIds: [$glTransactionId],
			matchedAmount: abs((float)($line['amount'] ?? 0)),
			actor: $actor,
			extra: ['glTransactionId' => $glTransactionId, 'resolutionReason' => 'Booked by hand to ' . $account]
		);
		$match['journalEntryId'] = $journalId;
		return $match;

	}//end bookToLedger()

	/**
	 * Post money received on a line to a revenue account, through a journal
	 * entry and its declared postDirect transition, with the bank account of
	 * the line's statement debited (REQ-ORS-004). Writes no match.
	 *
	 * @param array<string,mixed> $line        The bank line, money in.
	 * @param string              $account     The account credited.
	 * @param string              $description The posting text.
	 *
	 * @return string The journal entry id.
	 *
	 * @throws ManualMatchRefusedException When the bank account has no ledger account.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
	 */
	public function postReceipt(array $line, string $account, string $description): string {
		$journal = $this->buildJournalEntry(
			line: $line,
			bankAccount: $this->bankLedgerAccount(line: $line),
			ledger: ['accountNumber' => $account, 'description' => $description]
		);
		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'JournalEntry')->saveObject($journal));
		$journalId = (string)($saved['id'] ?? '');
		$this->transitions->run(objectId: $journalId, action: 'postDirect');

		return $journalId;

	}//end postReceipt()

	/**
	 * The balanced journal entry for a ledger booking.
	 *
	 * @param array<string,mixed> $line        The bank line.
	 * @param string              $bankAccount The bank's ledger account number.
	 * @param array<string,mixed> $ledger      The chosen account and VAT.
	 *
	 * @return array<string,mixed> The JournalEntry payload in state draft.
	 *
	 * @throws ManualMatchRefusedException When a VAT rate comes without a VAT account.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function buildJournalEntry(array $line, string $bankAccount, array $ledger): array {
		$amount = (float)($line['amount'] ?? 0);
		$gross = round(abs($amount), 2);
		$account = trim((string)($ledger['accountNumber'] ?? ''));
		$vatRate = (float)($ledger['vatRate'] ?? 0);
		$vatAccount = trim((string)($ledger['vatAccountNumber'] ?? ''));
		if ($vatRate > 0 && $vatAccount === '') {
			throw new ManualMatchRefusedException(template: 'Choose the VAT account for the VAT amount.');
		}

		$net = $gross;
		$vat = 0.0;
		if ($vatRate > 0) {
			$net = round($gross / (1 + ($vatRate / 100)), 2);
			$vat = round($gross - $net, 2);
		}

		$description = trim((string)($ledger['description'] ?? ''));
		if ($description === '') {
			$description = trim((string)($line['remittanceInfo'] ?? ($line['reference'] ?? ($line['narrative'] ?? 'Bank line'))));
		}

		// Money out (debit line): the cost is debited, the bank credited.
		// Money in (credit line): the bank is debited, the account credited.
		$counterSide = 'credit';
		$bankSide = 'debit';
		if ($amount < 0) {
			$counterSide = 'debit';
			$bankSide = 'credit';
		}

		$lines = [
			['accountNumber' => $account, 'side' => $counterSide, 'amount' => $net, 'description' => $description],
		];
		if ($vat > 0) {
			$lines[] = ['accountNumber' => $vatAccount, 'side' => $counterSide, 'amount' => $vat, 'description' => $description];
		}

		$lines[] = ['accountNumber' => $bankAccount, 'side' => $bankSide, 'amount' => $gross, 'description' => $description];

		$lineKey = (string)($line['lineId'] ?? ($line['id'] ?? ''));
		return [
			'journalNumber' => 'BNK-' . substr(hash('sha256', $lineKey), 0, 12),
			'entryDate' => substr((string)($line['valueDate'] ?? gmdate('Y-m-d')), 0, 10),
			'description' => $description,
			'lines' => $lines,
			'journalType' => 'manual',
			// The bank ledger may post the VAT line it splits out
			// (ledger-booking-rules REQ-LBR-002).
			'sourceApp' => 'bank',
			'approvalState' => 'not-required',
			'administrationId' => (string)($line['administrationId'] ?? ''),
			'state' => 'draft',
		];

	}//end buildJournalEntry()

	/**
	 * The confirmed match payload before it is written.
	 *
	 * @param array<string,mixed> $line          The bank line.
	 * @param string              $type          `ar-invoice`, `ap-invoice` or `gl-transaction`.
	 * @param array<int,string>   $targetIds     The target uuids.
	 * @param float               $matchedAmount The amount matched.
	 * @param array<string,mixed> $extra         Type-specific fields.
	 *
	 * @return array<string,mixed> The ReconciliationMatch payload, state pending.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public static function buildMatch(array $line, string $type, array $targetIds, float $matchedAmount, array $extra = []): array {
		$lineUuid = (string)($line['id'] ?? '');
		$lineKey = (string)($line['lineId'] ?? $lineUuid);
		$now = gmdate('Y-m-d\TH:i:s\Z');
		return array_merge(
			[
				'matchId' => 'MM-' . substr(hash('sha256', $lineKey . '|' . implode(',', $targetIds)), 0, 16),
				'bankLineRefs' => [$lineUuid],
				'bankStatementLineId' => $lineUuid,
				'bankLineId' => $lineKey,
				'targetType' => $type,
				'matchType' => $type,
				'targetRefs' => $targetIds,
				'matchedObjectId' => $targetIds[0],
				'matchedAmount' => round($matchedAmount, 2),
				'confidence' => 'manual',
				'matchAlgorithm' => 'manual',
				'manualOverride' => true,
				'state' => 'candidate',
				'status' => 'pending',
				'reconId' => '',
				'resolutionStatus' => 'matched',
				'matchedAt' => $now,
				'administrationId' => (string)($line['administrationId'] ?? ''),
			],
			$extra
		);

	}//end buildMatch()

	/**
	 * Write the match, confirm it and mark the line matched.
	 *
	 * @param array<string,mixed> $line          The bank line.
	 * @param string              $type          The target type.
	 * @param array<int,string>   $targetIds     The target uuids.
	 * @param float               $matchedAmount The amount matched.
	 * @param string              $actor         The confirming user.
	 * @param array<string,mixed> $extra         Type-specific fields.
	 *
	 * @return array<string,mixed> The match as stored after confirmation.
	 */
	private function writeConfirmedMatch(
		array $line,
		string $type,
		array $targetIds,
		float $matchedAmount,
		string $actor,
		array $extra
	): array {
		$payload = self::buildMatch(line: $line, type: $type, targetIds: $targetIds, matchedAmount: $matchedAmount, extra: $extra);
		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'ReconciliationMatch')->saveObject($payload));
		$matchId = (string)($saved['id'] ?? '');

		// The declared confirm transition moves `status` and fires the event
		// ReconciliationMatchSettlementListener settles the invoices on.
		$this->transitions->run(objectId: $matchId, action: 'confirm');
		$this->scoped(schema: 'ReconciliationMatch')->patchObject(
			$matchId,
			['state' => 'confirmed', 'confirmedBy' => $actor, 'confirmedAt' => gmdate('Y-m-d\TH:i:s\Z')]
		);

		$lineUuid = (string)($line['id'] ?? '');
		$this->transitions->run(objectId: $lineUuid, action: 'mark-matched');
		$this->scoped(schema: 'BankStatementLine')->patchObject(
			$lineUuid,
			['matchState' => 'confirmed', 'reconciliationMatchId' => $matchId]
		);

		$this->logger->info(
			'ManualMatchService: line matched by hand',
			['lineId' => $lineUuid, 'matchId' => $matchId, 'targetType' => $type, 'actor' => $actor]
		);

		$stored = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'ReconciliationMatch'), id: $matchId);
		return array_merge(($stored ?? $payload), ['id' => $matchId]);

	}//end writeConfirmedMatch()

	/**
	 * Refuse a line that is already matched or sits on a closed statement.
	 *
	 * @param array<string,mixed> $line The bank line.
	 *
	 * @return void
	 *
	 * @throws ManualMatchRefusedException When the line cannot be matched.
	 */
	private function assertMatchable(array $line): void {
		$status = (string)($line['status'] ?? 'unmatched');
		$matchState = (string)($line['matchState'] ?? 'unmatched');
		if ($status !== 'unmatched' || in_array($matchState, ['confirmed', 'routed-to-suspense'], true) === true) {
			throw new ManualMatchRefusedException(template: 'This bank line is already matched.');
		}

		$statementId = (string)($line['statementId'] ?? '');
		if ($statementId === '') {
			return;
		}

		$statement = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'BankStatement'), id: $statementId, fallbackProperty: 'statementId');
		if ($statement !== null && in_array((string)($statement['lifecycleState'] ?? ''), self::CLOSED_STATEMENT, true) === true) {
			throw new ManualMatchRefusedException(template: 'The bank statement of this line is already reconciled.');
		}

	}//end assertMatchable()

	/**
	 * Read the selected invoices and check they can be paid.
	 *
	 * @param string            $schema           `ARInvoice` or `APTransaction`.
	 * @param array<int,string> $ids              The invoice uuids.
	 * @param string            $administrationId The line's administration.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ManualMatchRefusedException When an invoice is missing, foreign or not open.
	 */
	private function readInvoices(string $schema, array $ids, string $administrationId): array {
		$stateField = InvoiceSettlementService::STATE_FIELDS[$schema];
		$invoices = [];
		foreach ($ids as $id) {
			$invoice = ObjectIdentifier::findOne(scoped: $this->scoped(schema: $schema), id: $id);
			if ($invoice === null || (string)($invoice['administrationId'] ?? '') !== $administrationId) {
				throw new ManualMatchRefusedException(template: 'Invoice %1$s is not an open invoice of this administration.', parameters: [$id]);
			}

			if (in_array((string)($invoice[$stateField] ?? ''), self::PAYABLE[$schema], true) === false) {
				throw new ManualMatchRefusedException(
					template: 'Invoice %1$s is not open for payment.',
					parameters: [(string)($invoice['invoiceNumber'] ?? $id)]
				);
			}

			$invoices[] = $invoice;
		}

		return $invoices;

	}//end readInvoices()

	/**
	 * The amount an invoice asks for.
	 *
	 * @param string              $schema  The invoice schema.
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return float
	 */
	private function invoiceAmount(string $schema, array $invoice): float {
		if ($schema === 'ARInvoice') {
			return round((float)($invoice['amountDue'] ?? ($invoice['grossAmount'] ?? 0)), 2);
		}

		return round((float)($invoice['totalAmount'] ?? 0), 2);

	}//end invoiceAmount()

	/**
	 * The ledger account of the bank account the line's statement belongs to.
	 *
	 * @param array<string,mixed> $line The bank line.
	 *
	 * @return string
	 *
	 * @throws ManualMatchRefusedException When the bank account has no ledger account.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
	 */
	public function bankLedgerAccount(array $line): string {
		$statement = ObjectIdentifier::findOne(
			scoped: $this->scoped(schema: 'BankStatement'),
			id: (string)($line['statementId'] ?? ''),
			fallbackProperty: 'statementId'
		);
		$iban = (string)($statement['bankAccountIban'] ?? '');
		$administrationId = (string)($line['administrationId'] ?? '');
		$accounts = [];
		if ($iban !== '') {
			$accounts = $this->scoped(schema: 'BankAccount')->findAll(['filters' => ['iban' => $iban, 'administrationId' => $administrationId], 'limit' => 1]);
		}

		$bankAccount = ObjectIdentifier::recordWithId(candidate: ($accounts[0] ?? null));
		$ledger = trim((string)($bankAccount['ledgerAccountNumber'] ?? ''));
		if ($ledger === '') {
			// A statement imported by file names the ledger account it books against.
			$ledger = trim((string)($statement['glAccountId'] ?? ''));
		}

		if ($ledger === '') {
			throw new ManualMatchRefusedException(
				template: 'Set the ledger account of bank account %1$s first.',
				parameters: [$iban]
			);
		}

		return $ledger;

	}//end bankLedgerAccount()

	/**
	 * The object service scoped to shillinq's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()

	/**
	 * Format an amount the way the refusal messages show it.
	 *
	 * @param float $amount The amount.
	 *
	 * @return string
	 */
	private static function money(float $amount): string {
		return 'EUR ' . number_format($amount, 2, '.', ',');

	}//end money()
}//end class
