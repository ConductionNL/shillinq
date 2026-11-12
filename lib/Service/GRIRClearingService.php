<?php

/**
 * GR/IR Clearing Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 09 of the bookkeeping-purchase-order-3way chain
 * (REQ-PO3W-009). Materialises the two-stage Goods-Received/Invoice-Received
 * clearing GL postings that make the "no GRN -> no invoice approval"
 * invariant enforceable per IFRS goods-in-receipt accounting:
 *
 *  - On GoodsReceiptNote accept the {@see createGRIRPosting()} method
 *    materialises a balanced 2-line GLTransaction:
 *      DR PO-line gl_account (Inventory or expense, e.g. 1200)
 *      CR GR/IR clearing (account from configuration, e.g. 2910)
 *    for the value of the accepted line (quantityAccepted * unitPrice).
 *
 *  - On ThreeWayMatch approval the {@see settleGRIRPosting()} method
 *    materialises a balanced 3-line settlement GLTransaction:
 *      DR GR/IR clearing (2910) for the net-of-VAT clearing balance
 *      CR Accounts Payable (supplier liability, e.g. 4400) for the
 *         net-of-VAT clearing balance
 *      CR VAT Payable (2100) for the invoice's totalVat
 *    The clearing leg of the settlement debits the SAME GR/IR account
 *    that the GRN-accept posting credited, so the GR/IR control account
 *    nets to zero at period-end (no dangling goods-in-transit).
 *
 * Both postings preserve `costCenter` and `projectCode` from the PO line
 * and carry the ThreeWayMatch id on the parent GLTransaction's
 * `sourceReference` so the audit trail in slice 11 can re-thread the
 * accounting back to the matching record.
 *
 * Money discipline (REQ-PO3W-009 + ADR-022): the unit price is stored
 * as integer cents on the PurchaseOrderLine; quantity is stored as a
 * float with `multipleOf 0.001` (integer thousandths). The clearing
 * amount is computed in integer thousandths-of-a-cent (cents * 1000)
 * and rounded HALF-UP to cents on the final amount. The balance
 * invariant `debitCents == creditCents` is asserted before any GLLine
 * is written.
 *
 * The GR/IR clearing account code is sourced from the administration-
 * wide app config (`gr_ir_clearing_account`, default `2910`) and may be
 * overridden per ToleranceProfile via the optional `gr_ir_clearing_account`
 * field on the profile (REQ-PO3W-009 acceptance: "GR/IR clearing account
 * configurable per ToleranceProfile"). The Accounts Payable and VAT
 * Payable account codes are likewise configurable via the
 * `accounts_payable_account` and `vat_payable_account` keys with defaults
 * `4400` and `2100`.
 *
 * Security (ADR-005): every read/write is scoped to the caller's
 * administrationId, validated by AdministrationContextService — cross-
 * tenant refs are masked as 404. GL account codes come from
 * configuration and the GR/IR posting itself derives from server-side
 * GRN/PurchaseOrderLine data, never from client input.
 *
 * The service is wired by GoodsReceiptNoteService.acceptGRN() (member 04,
 * GRN-accept trigger) and by the matching engine when ThreeWayMatch
 * transitions to `auto_approved` or `within_tolerance` (member 06-08,
 * settlement trigger). This slice owns only the posting logic; the
 * lifecycle triggers stay in their respective slices, keeping the
 * accounting double-entry invariant reviewable in one place per design D6.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Member 09 of bookkeeping-purchase-order-3way: GR/IR clearing + settlement
 * GL postings.
 *
 * Public methods:
 *  - createGRIRPosting(): on GRN accept, materialise DR PO-line gl_account /
 *    CR GR/IR clearing for the accepted line value (preserves
 *    costCenter + projectCode).
 *  - settleGRIRPosting(): on invoice match approval, materialise DR GR/IR
 *    clearing / CR Accounts Payable + VAT Payable (preserves
 *    costCenter + projectCode; references the ThreeWayMatch).
 *  - reconcileGRIRSaldoForPeriod(): sum the GR/IR account saldo across
 *    a period; the result MUST equal zero when every GRN has a matching
 *    approved invoice.
 *  - postGRIRForGoodsReceiptAccept(): (grir-accrual-wiring) fan out
 *    createGRIRPosting() over every accepted GoodsReceiptLine of a
 *    just-accepted GoodsReceiptNote — the caller wired by
 *    {@see \OCA\Shillinq\Listener\GRIRClearingListener}.
 *  - postGRIRForServiceReceiptAccept(): (grir-accrual-wiring) same fan-out
 *    for an accepted SvcReceipt's SvcReceiptLine rows.
 *  - settleGRIRForMatchedInvoice(): (grir-accrual-wiring) resolve the
 *    driving auto_approved/within_tolerance ThreeWayMatch for a matched
 *    SupplierInvoice and call settleGRIRPosting().
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Service touches multiple
 * registers (PurchaseOrder, PurchaseOrderLine, GoodsReceiptNote,
 * GoodsReceiptLine, SupplierInvoice, ThreeWayMatch, ToleranceProfile,
 * GLTransaction, GLLine); the slice contract is "materialise the GR/IR
 * accounting in one place" so each register is touched at most once per
 * public method.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Long because of the
 * exhaustive PHPDoc demanded by ADR-005 + the per-config doc blocks; the
 * actual logic is concentrated in two public methods (slice contract D6).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregate complexity
 * reflects the GRN-accept + settlement + reconciliation triad — each path
 * is a single straight-through compute + post pipeline.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
 * @spec openspec/specs/grir-accrual-wiring/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class GRIRClearingService {

	/**
	 * App-config key for the GR/IR clearing account number.
	 *
	 * RGS 3.5 MKB places goederen-in-ontvangst / tussenrekening goederen
	 * around the 2900 range; we default to `2910` per REQ-PO3W-009.
	 *
	 * @var string
	 */
	public const CFG_GR_IR_CLEARING_ACCOUNT = 'gr_ir_clearing_account';

	/**
	 * App-config key for the Accounts Payable (crediteuren) account number.
	 *
	 * RGS 3.5 MKB default `4400` (Crediteuren).
	 *
	 * @var string
	 */
	public const CFG_ACCOUNTS_PAYABLE_ACCOUNT = 'accounts_payable_account';

	/**
	 * App-config key for the VAT Payable (te betalen BTW) account number.
	 *
	 * RGS 3.5 MKB default `2100` (Te betalen BTW).
	 *
	 * @var string
	 */
	public const CFG_VAT_PAYABLE_ACCOUNT = 'vat_payable_account';

	/**
	 * Default GR/IR clearing account when not configured (per REQ-PO3W-009).
	 *
	 * @var string
	 */
	private const DEFAULT_GR_IR_CLEARING_ACCOUNT = '2910';

	/**
	 * Default Accounts Payable account when not configured.
	 *
	 * @var string
	 */
	private const DEFAULT_AP_ACCOUNT = '4400';

	/**
	 * Default VAT Payable account when not configured.
	 *
	 * @var string
	 */
	private const DEFAULT_VAT_ACCOUNT = '2100';

	/**
	 * Journal code stamped on the GR/IR clearing GLTransaction at GRN accept.
	 *
	 * @var string
	 */
	private const JOURNAL_CODE_CLEARING = 'GRIR';

	/**
	 * Journal code stamped on the settlement GLTransaction at match approval.
	 *
	 * @var string
	 */
	private const JOURNAL_CODE_SETTLEMENT = 'GRIR-SETTLE';

	/**
	 * Schema slug for the PurchaseOrderLine register.
	 *
	 * @var string
	 */
	private const SCHEMA_PO_LINE = 'PurchaseOrderLine';

	/**
	 * Schema slug for the GoodsReceiptLine register (grir-accrual-wiring
	 * per-line fan-out on GRN accept).
	 *
	 * @var string
	 */
	private const SCHEMA_GRN_LINE = 'GoodsReceiptLine';

	/**
	 * Schema slug for the SvcReceiptLine register (grir-accrual-wiring
	 * per-line fan-out on SvcReceipt accept).
	 *
	 * @var string
	 */
	private const SCHEMA_SVC_RECEIPT_LINE = 'SvcReceiptLine';

	/**
	 * Schema slug for the SupplierInvoice register.
	 *
	 * @var string
	 */
	private const SCHEMA_INVOICE = 'SupplierInvoice';

	/**
	 * Schema slug for the ToleranceProfile register.
	 *
	 * @var string
	 */
	private const SCHEMA_TOLERANCE_PROFILE = 'ToleranceProfile';

	/**
	 * Schema slug for the GLTransaction header.
	 *
	 * @var string
	 */
	private const SCHEMA_GL_TXN = 'GLTransaction';

	/**
	 * Schema slug for the GLLine row.
	 *
	 * @var string
	 */
	private const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Schema slug for the ThreeWayMatch register (grir-accrual-wiring
	 * resolution of the driving match for a matched SupplierInvoice).
	 *
	 * @var string
	 */
	private const SCHEMA_THREE_WAY_MATCH = 'ThreeWayMatch';

	/**
	 * ThreeWayMatch.matchStatus value that authorises settlement — exact
	 * 3-way match, zero divergence. Mirrors
	 * {@see \OCA\Shillinq\Service\ThreeWayMatchingEngine::STATUS_AUTO_APPROVED}
	 * without a hard class dependency (this service only reads the
	 * ThreeWayMatch data shape, per design D6).
	 *
	 * @var string
	 */
	private const MATCH_STATUS_AUTO_APPROVED = 'auto_approved';

	/**
	 * ThreeWayMatch.matchStatus value that authorises settlement —
	 * divergence detected but within tolerance. Mirrors
	 * {@see \OCA\Shillinq\Service\ThreeWayMatchingEngine::STATUS_WITHIN_TOLERANCE}.
	 *
	 * @var string
	 */
	private const MATCH_STATUS_WITHIN_TOLERANCE = 'within_tolerance';

	/**
	 * Constructor.
	 *
	 *                                      ObjectService resolution.
	 * @param IAppConfig $appConfig App config (account codes +
	 *                              register slug).
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope (ADR-005).
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) administrationContext is the
	 * canonical name fleet-wide.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Materialise the GR/IR clearing GL posting on GRN accept (REQ-PO3W-009).
	 *
	 * Computes the clearing amount as `quantityAccepted * unitPrice` for the
	 * supplied GoodsReceiptLine, derives the debit account from the PO-line
	 * `glAccount` and the credit account from configuration (with optional
	 * per-ToleranceProfile override). Writes a single balanced
	 * `GLTransaction` with two `GLLine` rows.
	 *
	 * Idempotency: a deterministic `transactionNumber` is built from the
	 * GRN line id so a re-fire of the GRN-accept lifecycle does not produce
	 * duplicate postings; the caller (GoodsReceiptNoteService.acceptGRN)
	 * is also expected to suppress re-fires via the locked StockMove.
	 *
	 * @param string $administrationId Tenant scope (server-resolved).
	 * @param array<string,mixed> $grn The accepted GoodsReceiptNote.
	 * @param array<string,mixed> $grnLine The accepted GoodsReceiptLine row.
	 * @param string|null $threeWayMatchId Optional ThreeWayMatch id; populated
	 *                                     once slice 06 has emitted the match
	 *                                     record. Not required at GRN time.
	 *
	 * @return array<string,mixed> Result envelope: {posted: bool, transaction:?,
	 *                             debitAccount, creditAccount, amountCents,
	 *                             message}.
	 *
	 * @throws \RuntimeException When the administration is inaccessible, the
	 *                           referenced PO line is missing, or the GL
	 *                           balance invariant fails.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009

	 */
	public function createGRIRPosting(
		string $administrationId,
		array $grn,
		array $grnLine,
		?string $threeWayMatchId = null,
	): array {
		$this->assertAccess(administrationId: $administrationId);

		$run = new GRIRClearingRun(administrationId: $administrationId, grnLine: $grnLine);
		$run->grn = $grn;
		$run->threeWayMatchId = $threeWayMatchId;
		$this->loadClearingPoLine(run: $run);
		$skipped = $this->prepareClearing(run: $run);
		if ($skipped !== null) {
			return $skipped;
		}

		return $this->postBalancedTransaction(
			administrationId: $administrationId,
			transaction: $this->clearingHeader(run: $run),
			lines: $run->lines()
		);

	}//end createGRIRPosting()

	/**
	 * Load the PO line the GRN line receives against.
	 *
	 * @param GRIRClearingRun $run The clearing run.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the GRN line names no PO line or it is missing.
	 */
	private function loadClearingPoLine(GRIRClearingRun $run): void {
		$poLineId = (string)($run->grnLine['poLineId'] ?? '');
		if ($poLineId === '') {
			throw new RuntimeException('GR/IR posting requires a poLineId on the GRN line');
		}

		$poLine = $this->records()->findOne(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'id' => $poLineId,
				'administrationId' => $run->administrationId,
			]
		);
		if ($poLine === null) {
			throw new RuntimeException('Purchase order line not found');
		}

		$run->poLine = $poLine;
	}//end loadClearingPoLine()

	/**
	 * Resolve the clearing amount, accounts and labels, or the not-posted
	 * result that stops the posting.
	 *
	 * QuantityAccepted is multipleOf 0.001 on the GRN line; unitPrice is
	 * integer cents on the PO line (ADR-022). The clearing amount is a single
	 * integer-cent value with HALF-UP rounding.
	 *
	 * @param GRIRClearingRun $run The clearing run.
	 *
	 * @return array<string,mixed>|null The not-posted result, or null to post.
	 */
	private function prepareClearing(GRIRClearingRun $run): ?array {
		$run->amountCents = $this->computeAmountCents(
			quantity: (float)($run->grnLine['quantityAccepted'] ?? 0),
			unitPriceCents: (int)($run->poLine['unitPrice'] ?? 0)
		);
		if ($run->amountCents <= 0) {
			return $this->notPosted(message: 'Accepted quantity yields a zero or negative clearing amount; skipped');
		}

		$run->debitAccount = $this->resolvePoLineAccount(poLine: $run->poLine);
		$run->creditAccount = $this->grIrClearingAccount(toleranceProfileId: $this->toleranceProfileId(poLine: $run->poLine));
		$refusal = $this->clearingAccountRefusal(run: $run);
		if ($refusal === null) {
			$this->describeClearing(run: $run);
		}

		return $refusal;
	}//end prepareClearing()

	/**
	 * The not-posted result when a clearing account is not configured.
	 *
	 * @param GRIRClearingRun $run The clearing run.
	 *
	 * @return array<string,mixed>|null The not-posted result, or null.
	 */
	private function clearingAccountRefusal(GRIRClearingRun $run): ?array {
		if ($run->debitAccount === '' || $run->creditAccount === '') {
			$this->logger->warning(
				'GRIRClearingService: GL accounts not configured; clearing posting skipped',
				[
					'grnLineId' => ($run->grnLine['id'] ?? null),
					'debitAccount' => $run->debitAccount,
					'creditAccount' => $run->creditAccount,
				]
			);
			return $this->notPosted(message: 'GR/IR clearing accounts not configured');
		}

		return null;
	}//end clearingAccountRefusal()

	/**
	 * Derive the dimensions, GRN number and line id, description and posting
	 * date of the clearing posting.
	 *
	 * @param GRIRClearingRun $run The clearing run.
	 *
	 * @return void
	 */
	private function describeClearing(GRIRClearingRun $run): void {
		$run->costCenter = trim((string)($run->poLine['costCenter'] ?? ''));
		$run->projectCode = trim((string)($run->poLine['projectCode'] ?? ''));
		$run->grnNumber = (string)($run->grn['grnNumber'] ?? '');
		$run->grnLineId = (string)($run->grnLine['id'] ?? ($run->grnLine['@self']['id'] ?? ''));

		$run->description = sprintf(
			'GR/IR clearing — %s line %s — %d cents',
			$this->nonEmptyOr(value: $run->grnNumber, fallback: 'GRN'),
			$run->grnLineId,
			$run->amountCents
		);
		$run->postingDate = $this->postingDate(grn: $run->grn);
	}//end describeClearing()

	/**
	 * The clearing GLTransaction header.
	 *
	 * @param GRIRClearingRun $run The clearing run.
	 *
	 * @return array<string,mixed> The transaction header.
	 */
	private function clearingHeader(GRIRClearingRun $run): array {
		return [
			'transactionNumber' => $this->clearingTransactionNumber(grnNumber: $run->grnNumber, grnLineId: $run->grnLineId),
			'journalCode' => self::JOURNAL_CODE_CLEARING,
			'postingDate' => $run->postingDate,
			'periodId' => $this->periodId(postingDate: $run->postingDate),
			'currency' => 'EUR',
			'description' => $run->description,
			'sourceReference' => $run->threeWayMatchId ?? $run->grnLineId,
			'threeWayMatchId' => $run->threeWayMatchId,
			'state' => 'draft',
			'administrationId' => $run->administrationId,
		];
	}//end clearingHeader()

	/**
	 * Materialise the settlement GL posting on ThreeWayMatch approval
	 * (REQ-PO3W-009 settlement leg).
	 *
	 * Settles the GR/IR clearing balance accumulated at GRN time by
	 * debiting the SAME GR/IR account (so the control nets to zero) and
	 * crediting Accounts Payable for the invoice net amount + VAT Payable
	 * for the invoice VAT. The clearing leg's amount equals the
	 * invoice's `totalExclVat` (which under tolerance equals the cumulative
	 * GRN clearing amount within the configured price-tolerance); any
	 * residual price delta is absorbed on the Accounts Payable leg per
	 * the matching engine's tolerance gate (slice 06).
	 *
	 * The settlement preserves `costCenter` + `projectCode` from the
	 * first matched PO line (slice 07 multi-PO consolidation guarantees
	 * matched PO lines share the same administration; cost-center
	 * preservation on the AP leg honours the dimensional reporting
	 * contract per REQ-PO3W-009).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $threeWayMatch The approved ThreeWayMatch record.
	 *
	 * @return array<string,mixed> Result envelope: {posted, transaction,
	 *                             debitAccount, creditAccounts: [], amountCents,
	 *                             message}.
	 *
	 * @throws \RuntimeException When the administration is inaccessible, the
	 *                           invoice is missing, or the GL balance
	 *                           invariant fails.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function settleGRIRPosting(string $administrationId, array $threeWayMatch): array {
		$this->assertAccess(administrationId: $administrationId);

		$run = new GRIRSettlementRun(administrationId: $administrationId, threeWayMatch: $threeWayMatch);
		$this->loadSettlementInvoice(run: $run);
		$skipped = $this->prepareSettlement(run: $run);
		if ($skipped !== null) {
			return $skipped;
		}

		$lines = $run->lines();
		return $this->postBalancedTransaction(
			administrationId: $administrationId,
			transaction: $this->settlementHeader(run: $run),
			lines: $lines
		);

	}//end settleGRIRPosting()

	/**
	 * Load the invoice the match names and read its totals.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the match names no invoice or it is missing.
	 */
	private function loadSettlementInvoice(GRIRSettlementRun $run): void {
		if ($run->invoiceId === '') {
			throw new RuntimeException('settleGRIRPosting requires invoiceId on the ThreeWayMatch');
		}

		$invoice = $this->records()->findOne(
			schema: self::SCHEMA_INVOICE,
			filters: [
				'id' => $run->invoiceId,
				'administrationId' => $run->administrationId,
			]
		);
		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found for settlement');
		}

		$run->useInvoice(invoice: $invoice);
	}//end loadSettlementInvoice()

	/**
	 * Resolve everything the posting needs, or the not-posted result that
	 * stops it: an invoice without value, unconfigured accounts, or nothing
	 * left to post on the excl/VAT legs.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return array<string,mixed>|null The not-posted result, or null to post.
	 */
	private function prepareSettlement(GRIRSettlementRun $run): ?array {
		if ($run->hasMonetaryValue() === false) {
			return $this->notPosted(message: 'Invoice has no monetary value to settle');
		}

		$this->resolveSettlementAccounts(run: $run);
		$refusal = $this->settlementAccountRefusal(run: $run);
		if ($refusal !== null) {
			return $refusal;
		}

		$this->describeSettlement(run: $run);
		if ($run->hasLegAmounts() === false) {
			// Defensive: nothing to post.
			return $this->notPosted(message: 'Invoice carries no excl/vat amount to settle');
		}

		return null;
	}//end prepareSettlement()

	/**
	 * Resolve the first matched PO line so costCenter + projectCode are
	 * preserved on every settlement line (REQ-PO3W-009 — dimensional
	 * reporting contract), and the accounts the posting uses. The matching
	 * engine guarantees at least one PO line is present on an approved match.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return void
	 */
	private function resolveSettlementAccounts(GRIRSettlementRun $run): void {
		$matchedPoLine = $this->resolveFirstMatchedPoLine(
			administrationId: $run->administrationId,
			threeWayMatch: $run->threeWayMatch
		);

		$run->costCenter = trim((string)(($matchedPoLine['costCenter'] ?? '')));
		$run->projectCode = trim((string)(($matchedPoLine['projectCode'] ?? '')));
		$toleranceProfileId = $this->toleranceProfileId(poLine: $matchedPoLine);

		$run->clearingAccount = $this->grIrClearingAccount(toleranceProfileId: $toleranceProfileId);
		$run->apAccount = $this->accountsPayableAccount();
		$run->vatAccount = $this->vatPayableAccount();
	}//end resolveSettlementAccounts()

	/**
	 * The not-posted result when a settlement account is not configured.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return array<string,mixed>|null The not-posted result, or null.
	 */
	private function settlementAccountRefusal(GRIRSettlementRun $run): ?array {
		if ($run->clearingAccount === '' || $run->apAccount === '') {
			$this->logger->warning(
				'GRIRClearingService: settlement accounts not configured; settlement posting skipped',
				[
					'invoiceId' => $run->invoiceId,
					'clearingAccount' => $run->clearingAccount,
					'apAccount' => $run->apAccount,
				]
			);
			return $this->notPosted(message: 'Settlement accounts not configured');
		}

		return $this->vatAccountRefusal(run: $run);
	}//end settlementAccountRefusal()

	/**
	 * The not-posted result when the invoice carries VAT but no VAT account
	 * is configured.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return array<string,mixed>|null The not-posted result, or null.
	 */
	private function vatAccountRefusal(GRIRSettlementRun $run): ?array {
		if ($run->totalVat > 0 && $run->vatAccount === '') {
			$this->logger->warning(
				'GRIRClearingService: VAT account not configured; settlement posting skipped',
				['invoiceId' => $run->invoiceId]
			);
			return $this->notPosted(message: 'VAT settlement account not configured');
		}

		return null;
	}//end vatAccountRefusal()

	/**
	 * A not-posted result envelope.
	 *
	 * @param string $message Why nothing was posted.
	 *
	 * @return array{posted:false,message:string}
	 */
	private function notPosted(string $message): array {
		return [
			'posted' => false,
			'message' => $message,
		];
	}//end notPosted()

	/**
	 * Derive the match id, invoice number, description and posting date.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return void
	 */
	private function describeSettlement(GRIRSettlementRun $run): void {
		$match = $run->threeWayMatch;
		$run->matchId = (string)($match['id'] ?? ($match['@self']['id'] ?? ''));
		$run->invoiceNumber = (string)($run->invoice['invoiceNumber'] ?? '');

		$run->description = sprintf(
			'GR/IR settlement — invoice %s — match %s',
			$this->nonEmptyOr(value: $run->invoiceNumber, fallback: $run->invoiceId),
			$this->nonEmptyOr(value: $run->matchId, fallback: 'n/a')
		);
		$run->postingDate = $this->postingDate(grn: $run->invoice);
	}//end describeSettlement()

	/**
	 * A value, or a fallback when the value is empty.
	 *
	 * @param string      $value    The preferred value.
	 * @param string|null $fallback The value used when it is empty.
	 *
	 * @return string|null The value or the fallback.
	 */
	private function nonEmptyOr(string $value, ?string $fallback): ?string {
		if ($value !== '') {
			return $value;
		}

		return $fallback;
	}//end nonEmptyOr()

	/**
	 * The settlement GLTransaction header.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return array<string,mixed> The transaction header.
	 */
	private function settlementHeader(GRIRSettlementRun $run): array {
		return [
			'transactionNumber' => $this->settlementNumber(run: $run),
			'journalCode' => self::JOURNAL_CODE_SETTLEMENT,
			'postingDate' => $run->postingDate,
			'periodId' => $this->periodId(postingDate: $run->postingDate),
			'currency' => 'EUR',
			'description' => $run->description,
			'sourceReference' => $this->nonEmptyOr(value: $run->matchId, fallback: $run->invoiceId),
			'threeWayMatchId' => $this->nonEmptyOr(value: $run->matchId, fallback: null),
			'state' => 'draft',
			'administrationId' => $run->administrationId,
		];
	}//end settlementHeader()

	/**
	 * The settlement transaction number for the run's invoice and match.
	 *
	 * @param GRIRSettlementRun $run The settlement run.
	 *
	 * @return string The transaction number.
	 */
	private function settlementNumber(GRIRSettlementRun $run): string {
		return $this->settlementTransactionNumber(
			invoiceNumber: $run->invoiceNumber,
			matchId: $run->matchId,
			invoiceId: $run->invoiceId
		);
	}//end settlementNumber()

	/**
	 * Compute the GR/IR clearing account saldo for a fiscal period
	 * (REQ-PO3W-009 — period-end reconciliation).
	 *
	 * Iterates every GLLine on the configured GR/IR clearing account for
	 * the given period and computes the net (debit minus credit) in
	 * integer cents. The result MUST equal zero when every GRN in the
	 * period has a matching approved invoice; a non-zero value means
	 * dangling goods-in-transit (the operator must investigate).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $periodId Fiscal period identifier (e.g. `2026-Q2`).
	 *
	 * @return array{periodId:string,clearingAccount:string,debitCents:int,
	 *                creditCents:int,saldoCents:int,balanced:bool}
	 *
	 * @throws \RuntimeException When the administration is inaccessible.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function reconcileGRIRSaldoForPeriod(string $administrationId, string $periodId): array {
		$this->assertAccess(administrationId: $administrationId);

		$clearingAccount = $this->grIrClearingAccount(toleranceProfileId: null);
		$lines = $this->records()->findAll(
			schema: self::SCHEMA_GL_LINE,
			filters: [
				'accountNumber' => $clearingAccount,
				'periodId' => $periodId,
				'administrationId' => $administrationId,
			]
		);

		$debitCents = 0;
		$creditCents = 0;
		foreach ($lines as $line) {
			$side = (string)($line['side'] ?? '');
			$amount = (float)($line['amount'] ?? 0);
			$cents = (int)round(($amount * 100.0), 0, PHP_ROUND_HALF_UP);
			if ($side === 'debit') {
				$debitCents += $cents;
			} elseif ($side === 'credit') {
				$creditCents += $cents;
			}
		}

		$balanceCents = $debitCents - $creditCents;

		return [
			'periodId' => $periodId,
			'clearingAccount' => $clearingAccount,
			'debitCents' => $debitCents,
			'creditCents' => $creditCents,
			'saldoCents' => $balanceCents,
			'balanced' => ($balanceCents === 0),
		];

	}//end reconcileGRIRSaldoForPeriod()

	/**
	 * Fan out {@see createGRIRPosting()} over every accepted
	 * `GoodsReceiptLine` of a just-accepted `GoodsReceiptNote`
	 * (grir-accrual-wiring REQ-001). Wired by
	 * {@see \OCA\Shillinq\Listener\GRIRClearingListener} on the
	 * `GoodsReceiptNote` `* -> accepted` transition.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $grn The accepted GoodsReceiptNote.
	 *
	 * @return array<string,mixed> Result envelope: {posted:int, skipped:int, results:array}.
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function postGRIRForGoodsReceiptAccept(string $administrationId, array $grn): array {
		$fanOut = new GRIRReceiptFanOut(
			administrationId: $administrationId,
			receipt: $grn,
			lineSchema: self::SCHEMA_GRN_LINE,
			parentField: 'grnId'
		);
		return $this->postGRIRForReceiptLines(fanOut: $fanOut);

	}//end postGRIRForGoodsReceiptAccept()

	/**
	 * Fan out {@see createGRIRPosting()} over every accepted
	 * `SvcReceiptLine` of a just-accepted `SvcReceipt` (grir-accrual-wiring
	 * REQ-002). `SvcReceipt` carries no `grnNumber`/`receivedAt` fields —
	 * this normalises the receipt array to the shape `createGRIRPosting()`
	 * expects (`receiptNumber` -> `grnNumber`, `periodEnd`/`confirmedAt`
	 * -> `receivedAt`) without changing `createGRIRPosting()` itself.
	 * Wired by {@see \OCA\Shillinq\Listener\GRIRClearingListener} on the
	 * `SvcReceipt` `confirmed -> accepted` transition.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $receipt The accepted SvcReceipt.
	 *
	 * @return array<string,mixed> Result envelope: {posted:int, skipped:int, results:array}.
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function postGRIRForServiceReceiptAccept(string $administrationId, array $receipt): array {
		$fanOut = new GRIRReceiptFanOut(
			administrationId: $administrationId,
			receipt: $receipt,
			lineSchema: self::SCHEMA_SVC_RECEIPT_LINE,
			parentField: 'serviceReceiptId'
		);
		return $this->postGRIRForReceiptLines(fanOut: $fanOut->grnShaped());

	}//end postGRIRForServiceReceiptAccept()

	/**
	 * Resolve the driving `auto_approved`/`within_tolerance` `ThreeWayMatch`
	 * for a `SupplierInvoice` that just reached `matched`, and call
	 * {@see settleGRIRPosting()} (grir-accrual-wiring REQ-003). When no
	 * qualifying match is found the settlement is skipped without error
	 * (the invoice may have been matched by a path this service does not
	 * recognise, or the match is not yet visible — fail-soft per REQ-004).
	 * Wired by {@see \OCA\Shillinq\Listener\GRIRClearingListener} on the
	 * `SupplierInvoice` `matching -> matched` transition.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array<string,mixed> Result envelope (see {@see settleGRIRPosting()}),
	 *                             or {posted:false, message} when no
	 *                             qualifying match is found.
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function settleGRIRForMatchedInvoice(string $administrationId, string $invoiceId): array {
		if ($invoiceId === '') {
			return [
				'posted' => false,
				'message' => 'invoiceId is required',
			];
		}

		$matches = $this->records()->findAll(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			filters: [
				'invoiceId' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);

		$candidate = null;
		$candidateTime = '';
		foreach ($matches as $match) {
			$status = (string)($match['matchStatus'] ?? '');
			if ($status !== self::MATCH_STATUS_AUTO_APPROVED && $status !== self::MATCH_STATUS_WITHIN_TOLERANCE) {
				continue;
			}

			$createdAt = (string)($match['createdAt'] ?? '');
			if ($candidate === null || $createdAt > $candidateTime) {
				$candidate = $match;
				$candidateTime = $createdAt;
			}
		}

		if ($candidate === null) {
			$this->logger->debug(
				'GRIRClearingService: no auto_approved/within_tolerance ThreeWayMatch found for matched invoice; settlement skipped',
				['invoiceId' => $invoiceId]
			);
			return [
				'posted' => false,
				'message' => 'No approved ThreeWayMatch found for invoice',
			];
		}

		return $this->settleGRIRPosting(administrationId: $administrationId, threeWayMatch: $candidate);
	}//end settleGRIRForMatchedInvoice()

	/**
	 * Shared per-line fan-out for {@see postGRIRForGoodsReceiptAccept()}
	 * and {@see postGRIRForServiceReceiptAccept()}: loads every child line
	 * row for the given receipt (by parent-FK field) and posts
	 * {@see createGRIRPosting()} for every line with `quantityAccepted > 0`.
	 *
	 * @param GRIRReceiptFanOut $fanOut The receipt, its line schema and parent field, in its administration.
	 *
	 * @return array<string,mixed> Result envelope: {posted:int, skipped:int, results:array}.
	 */
	private function postGRIRForReceiptLines(GRIRReceiptFanOut $fanOut): array {
		$tally = new GRIRFanOutTally();
		$receiptId = $fanOut->receiptId();
		if ($receiptId === '') {
			return $tally->envelope();
		}

		$lines = $this->records()->findAll(schema: $fanOut->lineSchema, filters: $fanOut->lineFilters(receiptId: $receiptId));
		foreach ($lines as $line) {
			$tally->count(result: $this->postAcceptedReceiptLine(fanOut: $fanOut, line: $line));
		}

		return $tally->envelope();

	}//end postGRIRForReceiptLines()

	/**
	 * Post one receipt line to GR/IR; null when nothing on it was accepted.
	 *
	 * @param GRIRReceiptFanOut $fanOut The fan-out.
	 * @param array<string,mixed> $line The receipt line row.
	 *
	 * @return array<string,mixed>|null The createGRIRPosting() result, or null.
	 */
	private function postAcceptedReceiptLine(GRIRReceiptFanOut $fanOut, array $line): ?array {
		if ((float)($line['quantityAccepted'] ?? 0) <= 0.0) {
			return null;
		}

		return $this->createGRIRPosting(
			administrationId: $fanOut->administrationId,
			grn: $fanOut->receipt,
			grnLine: $line
		);
	}//end postAcceptedReceiptLine()

	/**
	 * Compute the clearing line amount in integer cents.
	 *
	 * `unitPriceCents` is integer cents (ADR-022) and `quantity` is
	 * multipleOf 0.001 (integer thousandths). The result is integer
	 * cents with HALF-UP rounding.
	 *
	 * @param float $quantity quantityAccepted from the GRN line.
	 * @param int $unitPriceCents unitPrice from the PO line.
	 *
	 * @return int Amount in integer cents.
	 */
	private function computeAmountCents(float $quantity, int $unitPriceCents): int {
		if ($quantity <= 0.0 || $unitPriceCents <= 0) {
			return 0;
		}

		$quantityThousandths = (int)round(($quantity * 1000.0), 0, PHP_ROUND_HALF_UP);
		// AmountCents = (cents * thousandths) / 1000, half-up.
		$product = ($unitPriceCents * $quantityThousandths);

		return (int)(intdiv(($product + 500), 1000));
	}//end computeAmountCents()

	/**
	 * Persist a balanced GLTransaction with its GLLine rows.
	 *
	 * Asserts the double-entry balance invariant (`sum debit == sum credit`)
	 * before writing any GLLine to avoid leaving a half-balanced transaction
	 * behind on a partial failure. On post-write detection of an imbalance
	 * (defensive — should never happen), the transaction is logged but not
	 * retroactively reverted; integration tests assert balance.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $transaction Header data.
	 * @param array<int,array> $lines Per-line entries (see method body for shape).
	 *
	 * @return array<string,mixed> Result envelope.
	 *
	 * @throws \RuntimeException When the lines do not balance.
	 */
	private function postBalancedTransaction(
		string $administrationId,
		array $transaction,
		array $lines,
	): array {
		$debitCents = 0;
		$creditCents = 0;
		foreach ($lines as $line) {
			if ($line['side'] === 'debit') {
				$debitCents += (int)$line['amountCents'];
			} elseif ($line['side'] === 'credit') {
				$creditCents += (int)$line['amountCents'];
			}
		}

		if ($debitCents !== $creditCents) {
			throw new RuntimeException(
				sprintf(
					'GRIRClearingService: GL balance invariant failed (debit=%d credit=%d)',
					$debitCents,
					$creditCents
				)
			);
		}

		try {
			$header = $this->saveOnSchema(schema: self::SCHEMA_GL_TXN, data: $transaction);
			$headerId = (string)($header['id'] ?? ($header['@self']['id'] ?? ''));

			$lineNumber = 1;
			$persistedLines = [];
			foreach ($lines as $line) {
				$persistedLines[] = $this->saveOnSchema(
					schema: self::SCHEMA_GL_LINE,
					data: [
						'transactionId' => $headerId,
						'lineNumber' => $lineNumber,
						'accountNumber' => $line['accountNumber'],
						'side' => $line['side'],
						'amount' => round(($line['amountCents'] / 100.0), 2),
						'currency' => 'EUR',
						'description' => $line['description'],
						'costCenter' => $line['costCenter'],
						'costCenterCode' => $line['costCenter'],
						'projectCode' => $line['projectCode'],
						'periodId' => ($transaction['periodId'] ?? ''),
						'administrationId' => $administrationId,
					]
				);
				$lineNumber++;
			}

			return [
				'posted' => true,
				'transaction' => $header,
				'lines' => $persistedLines,
				'amountCents' => $debitCents,
				'message' => 'GR/IR posting materialised',
			];
		} catch (RuntimeException $e) {
			// Re-raise balance failure as-is.
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error(
				'GRIRClearingService: failed to persist GL posting',
				[
					'transactionNumber' => ($transaction['transactionNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			throw new RuntimeException('Failed to persist GR/IR posting');
		}//end try

	}//end postBalancedTransaction()

	/**
	 * Resolve the GL account number from the PO line, with a defensive
	 * fallback to the administration-wide `default_inventory_account`
	 * configuration.
	 *
	 * @param array<string,mixed> $poLine The PO line.
	 *
	 * @return string Account number ('' when neither the line nor the
	 *                fallback is configured).
	 */
	private function resolvePoLineAccount(array $poLine): string {
		$account = trim((string)($poLine['glAccount'] ?? ''));
		if ($account !== '') {
			return $account;
		}

		$fallback = trim($this->appConfig->getValueString(AppIdentity::APP_ID, 'inventory_account', ''));
		return $fallback;
	}//end resolvePoLineAccount()

	/**
	 * Resolve the GR/IR clearing account.
	 *
	 * Lookup order:
	 *   1. Per-ToleranceProfile override: `grIrClearingAccount` field on
	 *      the matched ToleranceProfile (REQ-PO3W-009 acceptance: "GR/IR
	 *      clearing account configurable per ToleranceProfile").
	 *   2. Administration-wide app config (`gr_ir_clearing_account`).
	 *   3. Hard-coded default `2910` (RGS 3.5 MKB goederen-tussenrekening).
	 *
	 * @param string|null $toleranceProfileId Optional ToleranceProfile id.
	 *
	 * @return string Account number.
	 */
	private function grIrClearingAccount(?string $toleranceProfileId): string {
		if ($toleranceProfileId !== null && $toleranceProfileId !== '') {
			$profile = $this->records()->findOne(
				schema: self::SCHEMA_TOLERANCE_PROFILE,
				filters: ['id' => $toleranceProfileId]
			);
			if ($profile !== null) {
				$override = trim((string)($profile['grIrClearingAccount'] ?? ''));
				if ($override !== '') {
					return $override;
				}
			}
		}

		$configured = trim(
			$this->appConfig->getValueString(
				AppIdentity::APP_ID,
				self::CFG_GR_IR_CLEARING_ACCOUNT,
				self::DEFAULT_GR_IR_CLEARING_ACCOUNT
			)
		);
		if ($configured !== '') {
			return $configured;
		}

		return self::DEFAULT_GR_IR_CLEARING_ACCOUNT;
	}//end grIrClearingAccount()

	/**
	 * Resolve the Accounts Payable account from app config.
	 *
	 * @return string Account number ('' if neither configured nor default).
	 */
	private function accountsPayableAccount(): string {
		$configured = trim(
			$this->appConfig->getValueString(
				AppIdentity::APP_ID,
				self::CFG_ACCOUNTS_PAYABLE_ACCOUNT,
				self::DEFAULT_AP_ACCOUNT
			)
		);
		if ($configured !== '') {
			return $configured;
		}

		return self::DEFAULT_AP_ACCOUNT;
	}//end accountsPayableAccount()

	/**
	 * Resolve the VAT Payable account from app config.
	 *
	 * @return string Account number.
	 */
	private function vatPayableAccount(): string {
		$configured = trim(
			$this->appConfig->getValueString(
				AppIdentity::APP_ID,
				self::CFG_VAT_PAYABLE_ACCOUNT,
				self::DEFAULT_VAT_ACCOUNT
			)
		);
		if ($configured !== '') {
			return $configured;
		}

		return self::DEFAULT_VAT_ACCOUNT;
	}//end vatPayableAccount()

	/**
	 * Extract the ToleranceProfile id from a PO line if one is referenced.
	 *
	 * The slice-01 PurchaseOrderLine schema does NOT declare a
	 * toleranceProfileId field as a hard requirement; the profile is
	 * resolved by the matching engine in slice 06 from the (supplier,
	 * category, gl_account) scope. We honour the optional inline
	 * reference field `toleranceProfileId` if present (set by the
	 * matching engine when the profile is determined) so the per-profile
	 * override is observable in this slice's tests.
	 *
	 * @param array<string,mixed> $poLine PO line.
	 *
	 * @return string|null Profile id (null when not set).
	 */
	private function toleranceProfileId(array $poLine): ?string {
		$id = trim((string)($poLine['toleranceProfileId'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $id;
	}//end toleranceProfileId()

	/**
	 * Resolve the first matched PO line referenced by a ThreeWayMatch.
	 *
	 * The matching engine in slice 06 records `matchedPoIds[]` on the
	 * ThreeWayMatch but the per-line link travels via
	 * `matchedPoLineIds[]` (which the engine populates when known) or
	 * is recoverable by joining matchedPoIds + PurchaseOrderLine.poId.
	 * We try the explicit per-line link first, then fall back to the
	 * first PO line of the first matched PO. Returns an empty array if
	 * nothing resolves (the caller can still post a settlement without
	 * costCenter/projectCode preservation).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $threeWayMatch Approved match record.
	 *
	 * @return array<string,mixed> The PO line (or empty array when nothing
	 *                             resolves).
	 */
	private function resolveFirstMatchedPoLine(string $administrationId, array $threeWayMatch): array {
		$matchedPoLineIds = $this->stringArray(input: ($threeWayMatch['matchedPoLineIds'] ?? []));
		foreach ($matchedPoLineIds as $poLineId) {
			$poLine = $this->records()->findOne(
				schema: self::SCHEMA_PO_LINE,
				filters: [
					'id' => $poLineId,
					'administrationId' => $administrationId,
				]
			);
			if ($poLine !== null) {
				return $poLine;
			}
		}

		$matchedPoIds = $this->stringArray(input: ($threeWayMatch['matchedPoIds'] ?? []));
		foreach ($matchedPoIds as $poId) {
			$rows = $this->records()->findAll(
				schema: self::SCHEMA_PO_LINE,
				filters: [
					'poId' => $poId,
					'administrationId' => $administrationId,
				]
			);
			foreach ($rows as $row) {
				return $row;
			}
		}

		return [];
	}//end resolveFirstMatchedPoLine()

	/**
	 * Build a deterministic transactionNumber for a GRN-clearing posting.
	 *
	 * Format: `GRIR-<grnNumber-or-grnLineId>` with non-alphanumerics
	 * stripped from the suffix. Deterministic so a double-fire of the
	 * GRN-accept lifecycle (e.g. cron retry) does not produce duplicate
	 * postings under the (administrationId, transactionNumber) unique
	 * index on GLTransaction.
	 *
	 * @param string $grnNumber GRN number (may be empty if not yet
	 *                          stamped).
	 * @param string $grnLineId GRN line id.
	 *
	 * @return string transactionNumber.
	 */
	private function clearingTransactionNumber(string $grnNumber, string $grnLineId): string {
		$base = trim($grnNumber);
		if ($base === '') {
			$base = $grnLineId;
		}

		$suffix = preg_replace('/[^A-Za-z0-9]/', '', $grnLineId) ?? '';
		if ($suffix !== '') {
			return 'GRIR-' . $base . '-' . strtoupper(substr($suffix, 0, 12));
		}

		return 'GRIR-' . $base;
	}//end clearingTransactionNumber()

	/**
	 * Build a deterministic transactionNumber for a settlement posting.
	 *
	 * Format: `GRIR-SETTLE-<invoiceNumber-or-matchId-or-invoiceId>`.
	 *
	 * @param string $invoiceNumber Invoice number.
	 * @param string $matchId ThreeWayMatch id.
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return string transactionNumber.
	 */
	private function settlementTransactionNumber(string $invoiceNumber, string $matchId, string $invoiceId): string {
		$primary = trim($invoiceNumber);
		if ($primary === '') {
			$primary = $invoiceId;
			if ($matchId !== '') {
				$primary = $matchId;
			}
		}

		$suffix = preg_replace('/[^A-Za-z0-9]/', '', $matchId) ?? '';
		if ($suffix !== '') {
			return 'GRIR-SETTLE-' . $primary . '-' . strtoupper(substr($suffix, 0, 8));
		}

		return 'GRIR-SETTLE-' . $primary;
	}//end settlementTransactionNumber()

	/**
	 * Resolve the posting date as yyyy-mm-dd from the GRN or invoice.
	 *
	 * @param array<string,mixed> $grn GRN or SupplierInvoice record.
	 *
	 * @return string yyyy-mm-dd.
	 */
	private function postingDate(array $grn): string {
		$candidate = (string)(
			$grn['receivedAt'] ?? $grn['acceptedAt'] ?? $grn['invoiceDate'] ?? $grn['peppolReceivedAt'] ?? $grn['createdAt'] ?? ''
		);
		if ($candidate === '') {
			return date('Y-m-d');
		}

		return substr($candidate, 0, 10);
	}//end postingDate()

	/**
	 * The record store on the app's register, logging as this service.
	 *
	 * @return SchemaRecordStore The record store.
	 */
	private function records(): SchemaRecordStore {
		return new SchemaRecordStore(
			binding: new RegisterBinding(objectService: $this->objectService, appConfig: $this->appConfig),
			log: new RecordStoreLog(logger: $this->logger, source: 'GRIRClearingService')
		);
	}//end records()

	/**
	 * Quarter identifier (yyyy-Qn) from a yyyy-mm-dd posting date.
	 *
	 * @param string $postingDate Posting date.
	 *
	 * @return string yyyy-Qn.
	 */
	private function periodId(string $postingDate): string {
		$year = (int)substr($postingDate, 0, 4);
		$month = (int)substr($postingDate, 5, 2);
		if ($year === 0) {
			$year = (int)date('Y');
		}

		if ($month < 1) {
			$month = (int)date('m');
		}

		$quarter = (int)ceil(max(1, $month) / 3);
		return sprintf('%04d-Q%d', $year, $quarter);
	}//end periodId()

	/**
	 * Sanitise a string array input.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array<int,string>
	 */
	private function stringArray(mixed $input): array {
		if (is_array($input) === false) {
			return [];
		}

		$out = [];
		foreach ($input as $entry) {
			$value = trim((string)$entry);
			if ($value !== '') {
				$out[] = $value;
			}
		}

		return array_values(array_unique($out));
	}//end stringArray()

	/**
	 * Persist on the configured register, returning the canonical array
	 * payload.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the row type is unsupported.
	 */
	private function saveOnSchema(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, which
		// extends JsonSerializable and declares `getObject(): array` — so the
		// is_object()/method_exists() guards that used to wrap these two calls
		// could never be false, and the trailing throw was unreachable.
		// jsonSerialize() still returns mixed, so that check stays.
		$out = $saved->jsonSerialize();
		if (is_array($out) === true) {
			return $out;
		}

		return $saved->getObject();
	}//end saveOnSchema()

	/**
	 * Resolve the OR register slug from app config.
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

	/**
	 * Assert the caller can access the requested administration; mask as
	 * not-found per ADR-005.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the administration is not accessible.
	 */
	private function assertAccess(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

	}//end assertAccess()
}//end class
