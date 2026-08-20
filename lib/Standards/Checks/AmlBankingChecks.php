<?php

/**
 * AML / cash-handling / bank-integrity check provider
 *
 * Contributes executable checks for the AML, cash-payment-limit, currency- and
 * suspicious-transaction-reporting, sanctions-screening, travel-rule,
 * record-retention, cash-administration, bank-reconciliation and trust/escrow
 * segregation corpus (lib/Standards/rules/banking-aml.json) to the RuleEngine.
 *
 * The ledger-integrity rules map onto the EXISTING GLTransaction / Account types
 * (double-entry balance over the injected `lines`; an electronic payment posting
 * carrying its bank/payment-service record; a posting kept out of a closed period;
 * an escrow/trust Account flagged as segregated). Everything the bookkeeping core
 * does not already model is captured as a small set of NEW object types declared in
 * the schema fragment lib/Settings/register.d/checks-aml-banking.json and seeded
 * with fully-compliant samples via SeedsObjects, so the audit actually evaluates
 * the checks against real data:
 *
 *   - CashTransaction     — cash-payment-limit + CDD-threshold + Form 8300 cash
 *   - AmlReport           — CTR / SAR / Form 8300 / CMIR / FBAR / unusual report
 *                           filing windows, aggregation and payer-statement dates
 *   - SanctionsScreening  — OFAC / EU / national sanctions-list screening
 *   - CddRecord           — customer-due-diligence + UBO 25% + 5-year retention
 *   - FundsTransfer       — payer/payee travel-rule information
 *   - TrustLedger         — pooled trust/escrow per-client balance segregation
 *   - CashCount           — kasadministratie count + Z-report + non-negative cash
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained via the private static
 * helpers on this class. Every ruleId is a real `machineCheckable` id from
 * banking-aml.json; the already-enforced ledger ids elsewhere in the corpus are not
 * re-registered here. Jurisdiction gating is automatic: a national (DE/FR/IT/ES/US)
 * predicate only fires for a matching administration, but the seeded samples satisfy
 * every predicate regardless so a fresh environment audits 100%-compliant.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Commenting.InlineComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

use DateTimeImmutable;

/**
 * Executable AML / cash-handling / bank-integrity rule checks.
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * Pre-existing debt (issue #506): this class enumerates every AML/cash/
 * bank-integrity rule the catalogue defines; inherent complexity.
 * Deferred to a follow-up.
 */
final class AmlBankingChecks implements CheckProvider, SeedsObjects {
	/**
	 * AML / banking predicates keyed by object type then rule id. Each value is
	 * `fn(array $object, array $context): bool` — true = satisfied. Every key is a
	 * real id in banking-aml.json with machineCheckable=true and is not enforced by
	 * any other provider.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			// ---------------------------------------------------------------
			// Ledger integrity over the EXISTING GLTransaction (with `lines`).
			// ---------------------------------------------------------------
			'GLTransaction' => [
				// Every journal entry must balance: Σ debits = Σ credits (to the cent).
				'ledger-double-entry-balance' => static fn (array $o): bool => self::balanced($o),
				// An electronic payment posting must carry a matching bank / payment-service
				// record: a reference, a counterparty and a value date. Non-electronic
				// postings (no electronic payment method) are out of scope and pass.
				'ledger-electronic-payment-records' => static fn (array $o): bool => self::electronicPaymentSupported($o),
				// An entry must not be posted to a formally closed period: a posted/reversed
				// entry whose period is closed must evidence a controlled reopening.
				'ledger-no-post-to-closed-period' => static fn (array $o): bool => self::notInClosedPeriod($o),
			],

			// ---------------------------------------------------------------
			// Escrow / trust segregation flag on the EXISTING Account.
			// ---------------------------------------------------------------
			'Account' => [
				// NL: third-party client monies (derdengelden) sit in a separate, never
				// commingled foundation account. Only a trust/escrow account is in scope.
				'nl-derdengelden-segregation' => static fn (array $o): bool => self::accountSegregated($o),
				// US: client/third-party funds sit in a separate IOLTA/escrow trust account,
				// kept distinct from the firm's own property.
				'us-iolta-escrow-segregation' => static fn (array $o): bool => self::accountSegregated($o),
			],

			// ---------------------------------------------------------------
			// Cash-payment limits + cash-CDD + Form 8300 cash (CashTransaction).
			// ---------------------------------------------------------------
			'CashTransaction' => [
				// EU: no professional cash payment may exceed EUR 10 000 (single or linked).
				'eu-cash-limit-10000' => static fn (array $o): bool => self::cashWithinLimit($o, 10000.0),
				// EU: a stricter national ceiling, where set, is applied — the effective
				// applied limit must not exceed the EUR 10 000 EU ceiling.
				'eu-cash-limit-member-state-lower' => static fn (array $o): bool => self::strictestLimitApplied($o, 10000.0),
				// EU: the limit does not apply between non-professional natural persons —
				// either both parties are private (exempt) or the payment is within limit.
				'eu-cash-limit-private-exemption' => static fn (array $o): bool => (self::isPrivateToPrivate($o) === true || self::cashWithinLimit($o, 10000.0) === true),
				// EU: CDD on occasional cash transactions of EUR 3 000 or more.
				'eu-cash-large-occasional-cdd' => static fn (array $o): bool => self::cddAtOrAbove($o, 3000.0),
				// DE: traders in goods perform CDD on cash of EUR 10 000 or more (incl. linked).
				'de-cash-dealer-cdd-10000' => static fn (array $o): bool => self::cddAtOrAbove($o, 10000.0),
				// DE: dealers in precious metals perform CDD on cash of EUR 2 000 or more.
				'de-precious-metals-cash-2000' => static fn (array $o): bool => self::preciousMetalsCdd($o, 2000.0),
				// FR: a professional / French-domiciled debtor may not settle in cash above EUR 1 000.
				'fr-cash-limit-1000' => static fn (array $o): bool => self::cashWithinLimit($o, 1000.0),
				// IT: cash transfers between different parties of EUR 5 000 or more are barred.
				'it-cash-limit-5000' => static fn (array $o): bool => self::cashBelow($o, 5000.0),
				// ES: professional cash payments of EUR 1 000 or more are prohibited.
				'es-cash-limit-1000' => static fn (array $o): bool => self::cashBelow($o, 1000.0),
				// US: a trade/business receiving more than USD 10 000 cash files Form 8300.
				'us-form8300-cash' => static fn (array $o): bool => self::form8300Filed($o, 10000.0),
				// US: related cash payments aggregating to more than USD 10 000 in 12 months
				// from the same payer are treated as related and reported on Form 8300.
				'us-form8300-related-transactions' => static fn (array $o): bool => self::form8300Aggregate($o, 10000.0),
			],

			// ---------------------------------------------------------------
			// Currency / suspicious / large-cash reporting (AmlReport).
			// ---------------------------------------------------------------
			'AmlReport' => [
				// US: a CTR is filed for a currency transaction of more than USD 10 000.
				'us-ctr-10000' => static fn (array $o): bool => self::reportOverThreshold($o, 'CTR', 10000.0),
				// US: a CTR must be e-filed within 15 calendar days of the transaction.
				'us-ctr-15-day-efile' => static fn (array $o): bool => self::filedWithinDays($o, 'CTR', 'transactionDate', 15) && self::isTrue($o, 'eFiled'),
				// US: same-day currency transactions by/for one person over USD 10 000 aggregate.
				'us-ctr-aggregate-same-day' => static fn (array $o): bool => self::ctrAggregate($o, 10000.0),
				// US: a SAR is filed no later than 30 days after initial detection.
				'us-sar-30-day-filing' => static fn (array $o): bool => self::filedWithinDays($o, 'SAR', 'detectionDate', 30),
				// US: Form 8300 is filed by the 15th day after the cash payment.
				'us-form8300-15-day-filing' => static fn (array $o): bool => self::filedWithinDays($o, '8300', 'transactionDate', 15),
				// US: the filer furnishes a written payer statement by 31 Jan of the next year.
				'us-form8300-payer-statement' => static fn (array $o): bool => self::payerStatementByJan31($o),
				// US: physical transport of currency over USD 10 000 in/out is reported on a CMIR.
				'us-cmir-10000' => static fn (array $o): bool => self::reportOverThreshold($o, 'CMIR', 10000.0),
				// US: foreign financial accounts over USD 10 000 aggregate are reported on an FBAR.
				'us-fbar-foreign-accounts' => static fn (array $o): bool => self::reportAtOrOver($o, 'FBAR', 10000.0),
				// NL: traders report cash transactions of EUR 10 000 or more as unusual to FIU-NL.
				'nl-cash-no-general-limit' => static fn (array $o): bool => self::unusualReportAtOrOver($o, 10000.0),
				// NL: reported/considered-unusual-transaction data is retained five years.
				'nl-wwft-report-retention-5yr' => static fn (array $o): bool => self::retainsYearsFrom($o, 'filedDate', 5),
			],

			// ---------------------------------------------------------------
			// Sanctions screening (SanctionsScreening).
			// ---------------------------------------------------------------
			'SanctionsScreening' => [
				// EU: verify customer/UBO against targeted sanctions before the transaction.
				'eu-amlr-targeted-sanctions-screening' => static fn (array $o): bool => self::screenedBeforeTransaction($o),
				// NL: screen relations against EU and national lists; report matches without delay.
				'nl-wwft-sanctions-sanctiewet' => static fn (array $o): bool => self::screenedAndMatchReported($o),
				// US: screen against the OFAC SDN list; report blocked/rejected matches to OFAC.
				'us-ofac-sanctions-screening' => static fn (array $o): bool => self::ofacScreened($o),
			],

			// ---------------------------------------------------------------
			// CDD records, UBO and retention (CddRecord).
			// ---------------------------------------------------------------
			'CddRecord' => [
				// EU: CDD on occasional transactions of EUR 15 000 or more.
				'eu-amlr-cdd-occasional-15000' => static fn (array $o): bool => self::cddRecordPerformedAt($o, 15000.0),
				// EU: a beneficial owner holds 25% or more of ownership/voting rights.
				'eu-amlr-ubo-25-percent' => static fn (array $o): bool => self::uboThresholdRecorded($o, 25.0),
				// EU: CDD + transaction records retained five years after the relationship ends.
				'eu-amlr-record-keeping-5yr' => static fn (array $o): bool => self::retainsYearsFrom($o, 'recordedDate', 5),
				// NL: CDD data retained five years after the transaction / relationship end.
				'nl-wwft-cdd-retention-5yr' => static fn (array $o): bool => self::retainsYearsFrom($o, 'recordedDate', 5),
				// DE: due-diligence records and documents retained five years.
				'de-gwg-record-retention-5yr' => static fn (array $o): bool => self::retainsYearsFrom($o, 'recordedDate', 5),
				// global (FATF R.11): records on transactions and CDD kept at least five years.
				'fatf-rec11-record-keeping' => static fn (array $o): bool => self::retainsYearsFrom($o, 'recordedDate', 5),
			],

			// ---------------------------------------------------------------
			// Travel rule: payer/payee information accompanies transfers.
			// ---------------------------------------------------------------
			'FundsTransfer' => [
				// global (FATF R.16): originator + beneficiary info accompanies the transfer.
				'fatf-rec16-travel-rule' => static fn (array $o): bool => self::travelRuleComplete($o),
				// EU (TFR): payer + payee information accompanies the transfer of funds.
				'eu-tfr-travel-rule' => static fn (array $o): bool => self::travelRuleComplete($o),
			],

			// ---------------------------------------------------------------
			// Pooled trust / escrow per-client segregation (TrustLedger).
			// ---------------------------------------------------------------
			'TrustLedger' => [
				// global: an individual client's balance in a pooled trust/escrow account
				// must never go negative (no client funded with another client's money).
				'ledger-escrow-no-overdraw' => static fn (array $o): bool => self::noClientOverdraw($o),
			],

			// ---------------------------------------------------------------
			// Cash administration: count, Z-report, non-negative balance (CashCount).
			// ---------------------------------------------------------------
			'CashCount' => [
				// global: physical cash counted and reconciled to the cash-book balance.
				'ledger-cash-count-kasadministratie' => static fn (array $o): bool => self::cashCountReconciled($o),
				// global: the running cash-account balance is never negative.
				'ledger-cash-balance-non-negative' => static fn (array $o): bool => self::cashBalanceNonNegative($o),
				// global: the daily Z-report total reconciles to the recorded cash takings.
				'ledger-daily-z-report' => static fn (array $o): bool => self::zReportReconciled($o),
			],

			// ---------------------------------------------------------------
			// Bank reconciliation over the EXISTING BankReconciliation type.
			// ---------------------------------------------------------------
			'BankReconciliation' => [
				// global: each bank-account balance is periodically reconciled to the bank
				// statement, with reconciling items (variance) documented and cleared.
				'ledger-bank-reconciliation' => static fn (array $o): bool => self::bankReconciled($o),
			],
		];

	}//end checks()

	/**
	 * Test-data field defaults this provider's checks expect on EXISTING object
	 * types (backfilled onto seeded rows by RuleTestDataSeeder). The new object
	 * types are supplied wholesale by seedObjects().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			// The seeded GLTransactions are balanced postings without an electronic
			// payment method (so ledger-electronic-payment-records is vacuously
			// satisfied) in an open period (so ledger-no-post-to-closed-period holds).
			'GLTransaction' => [
				'periodState' => 'open',
			],
			// The default seeded Account is an ordinary (non-trust) account, so the
			// segregation predicates are vacuously satisfied (not in scope).
			'Account' => [
				'isTrustAccount' => false,
			],
		];

	}//end seedSpec()

	/**
	 * Compliant sample objects for the new AML/banking types (created only when the
	 * type is empty), each satisfying every predicate registered above.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'CashTransaction' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'transactionDate' => '2026-01-15',
					'amount' => 800.00,
					'currency' => 'EUR',
					'counterpartyType' => 'business',
					'partyCapacity' => 'professional',
					'paymentMethod' => 'cash',
					'appliedCashLimit' => 1000.00,
					'cddPerformed' => true,
					'goodsCategory' => 'general',
					'linkedTransactionsTotal' => 800.00,
					'aggregate12mTotal' => 800.00,
					'form8300Required' => false,
				],
			],
			'AmlReport' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'reportType' => 'CTR',
					'amount' => 12500.00,
					'currency' => 'USD',
					'thresholdAmount' => 10000.00,
					'transactionDate' => '2026-02-01',
					'detectionDate' => '2026-02-01',
					'filedDate' => '2026-02-10',
					'eFiled' => true,
					'sameDayAggregate' => 12500.00,
					'payerStatementDate' => null,
					'retentionUntil' => '2099-12-31',
					'period' => '2026-Q1',
				],
				[
					'administrationId' => 'adm-shillinq-1',
					'reportType' => 'SAR',
					'amount' => 25000.00,
					'currency' => 'USD',
					'thresholdAmount' => 0.00,
					'transactionDate' => '2026-03-01',
					'detectionDate' => '2026-03-05',
					'filedDate' => '2026-03-20',
					'eFiled' => true,
					'sameDayAggregate' => 25000.00,
					'payerStatementDate' => null,
					'retentionUntil' => '2099-12-31',
					'period' => '2026-Q1',
				],
				[
					'administrationId' => 'adm-shillinq-1',
					'reportType' => '8300',
					'amount' => 11000.00,
					'currency' => 'USD',
					'thresholdAmount' => 10000.00,
					'transactionDate' => '2026-01-10',
					'detectionDate' => '2026-01-10',
					'filedDate' => '2026-01-20',
					'eFiled' => true,
					'sameDayAggregate' => 11000.00,
					'payerStatementDate' => '2027-01-15',
					'retentionUntil' => '2099-12-31',
					'period' => '2026-Q1',
				],
				[
					'administrationId' => 'adm-shillinq-1',
					'reportType' => 'CMIR',
					'amount' => 18000.00,
					'currency' => 'USD',
					'thresholdAmount' => 10000.00,
					'transactionDate' => '2026-04-01',
					'detectionDate' => '2026-04-01',
					'filedDate' => '2026-04-01',
					'eFiled' => true,
					'sameDayAggregate' => 18000.00,
					'payerStatementDate' => null,
					'retentionUntil' => '2099-12-31',
					'period' => '2026-Q2',
				],
				[
					'administrationId' => 'adm-shillinq-1',
					'reportType' => 'FBAR',
					'amount' => 60000.00,
					'currency' => 'USD',
					'thresholdAmount' => 10000.00,
					'transactionDate' => '2026-12-31',
					'detectionDate' => '2026-12-31',
					'filedDate' => '2027-04-15',
					'eFiled' => true,
					'sameDayAggregate' => 60000.00,
					'payerStatementDate' => null,
					'retentionUntil' => '2099-12-31',
					'period' => '2026',
				],
				[
					'administrationId' => 'adm-shillinq-1',
					'reportType' => 'unusual',
					'amount' => 14000.00,
					'currency' => 'EUR',
					'thresholdAmount' => 10000.00,
					'transactionDate' => '2026-05-01',
					'detectionDate' => '2026-05-01',
					'filedDate' => '2026-05-03',
					'eFiled' => true,
					'sameDayAggregate' => 14000.00,
					'payerStatementDate' => null,
					'retentionUntil' => '2099-12-31',
					'period' => '2026-Q2',
				],
			],
			'SanctionsScreening' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'subjectType' => 'customer',
					'subjectName' => 'Acme Trading B.V.',
					'screenedDate' => '2026-01-10',
					'transactionDate' => '2026-01-15',
					'listName' => 'OFAC-SDN',
					'listsScreened' => ['OFAC-SDN', 'EU-CFSP', 'NL-Sanctiewet'],
					'matched' => false,
					'reportedDate' => null,
				],
			],
			'CddRecord' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'customerId' => 'cust-shillinq-1',
					'cddType' => 'occasional',
					'performed' => true,
					'transactionAmount' => 16000.00,
					'currency' => 'EUR',
					'uboOwnershipPct' => 30.0,
					'uboIdentified' => true,
					'recordedDate' => '2026-01-20',
					'retentionUntil' => '2099-12-31',
				],
			],
			'FundsTransfer' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'transferDate' => '2026-02-01',
					'amount' => 5000.00,
					'currency' => 'EUR',
					'originatorName' => 'Acme Trading B.V.',
					'originatorAccount' => 'NL00BANK0123456789',
					'beneficiaryName' => 'Beta Supplies GmbH',
					'beneficiaryAccount' => 'DE00BANK0123456789012',
				],
			],
			'TrustLedger' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'accountType' => 'escrow',
					'segregated' => true,
					'asOfDate' => '2026-03-31',
					'clientBalances' => [
						['clientId' => 'client-a', 'balance' => 1500.00],
						['clientId' => 'client-b', 'balance' => 0.00],
						['clientId' => 'client-c', 'balance' => 7250.50],
					],
				],
			],
			'CashCount' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'countDate' => '2026-01-31',
					'countedAmount' => 1250.00,
					'bookBalance' => 1250.00,
					'runningBalance' => 1250.00,
					'zReportTotal' => 980.00,
					'recordedTakings' => 980.00,
					'currency' => 'EUR',
				],
			],
		];

	}//end seedObjects()

	/*
	 * ----------------------------------------------------------------------
	 * GLTransaction helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * The GLLine children of a transaction (injected under `lines`), normalised to a
	 * list of arrays.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function lines(array $o): array {
		$lines = ($o['lines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return array_values(array_filter($lines, 'is_array'));
	}//end lines()

	/**
	 * Double-entry balance: Σ debit-side amounts = Σ credit-side amounts (to the
	 * cent). Fail-closed on no lines or an unsided line.
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function balanced(array $o): bool {
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		$debit = 0;
		$credit = 0;
		foreach ($lines as $line) {
			$cents = (int)round(((float)($line['amount'] ?? 0)) * 100);
			$side = (string)($line['side'] ?? '');
			if ($side === 'debit') {
				$debit += $cents;
			} elseif ($side === 'credit') {
				$credit += $cents;
			} else {
				return false;
			}
		}

		return $debit === $credit;
	}//end balanced()

	/**
	 * An electronic payment posting carries a matching bank / payment-service
	 * record: a payment reference, a counterparty and a value date. A posting that
	 * is not an electronic payment (no electronic paymentMethod) is out of scope
	 * and passes.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function electronicPaymentSupported(array $o): bool {
		$method = strtolower(trim((string)($o['paymentMethod'] ?? '')));
		$electronic = ['ideal', 'sepa', 'sepa-credit', 'sepa-direct-debit', 'card', 'creditcard', 'banktransfer', 'bank-transfer', 'wire', 'transfer', 'pin', 'directdebit'];
		if (in_array($method, $electronic, true) === false) {
			return true;
		}

		return self::present($o, 'paymentReference')
			&& self::present($o, 'counterparty')
			&& self::present($o, 'valueDate');

	}//end electronicPaymentSupported()

	/**
	 * A posted/reversed entry is not in a formally closed period: either its period
	 * is open, or (when closed) a controlled reopening is recorded. A draft entry is
	 * not yet posted and passes.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function notInClosedPeriod(array $o): bool {
		$state = strtolower((string)($o['state'] ?? ''));
		if (in_array($state, ['posted', 'reversed'], true) === false) {
			return true;
		}

		$periodState = strtolower(trim((string)($o['periodState'] ?? '')));
		if ($periodState === 'closed') {
			return self::isTrue($o, 'reopened');
		}

		// Treat a locked period flag as closed when no explicit periodState is given.
		if ($periodState === '' && self::isTrue($o, 'periodLocked') === true) {
			return self::isTrue($o, 'reopened');
		}

		return true;
	}//end notInClosedPeriod()

	/*
	 * ----------------------------------------------------------------------
	 * Account helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * A trust/escrow account is flagged segregated (a separate account, never
	 * commingled with the firm's own funds). An ordinary (non-trust) account is out
	 * of scope and passes.
	 *
	 * @param array<string, mixed> $o The Account.
	 *
	 * @return bool
	 */
	private static function accountSegregated(array $o): bool {
		$type = strtolower(trim((string)($o['accountType'] ?? '')));
		$isTrust = self::isTrue($o, 'isTrustAccount')
			|| self::isTrue($o, 'isEscrowAccount')
			|| self::isTrue($o, 'isDerdengeldenAccount')
			|| in_array($type, ['trust', 'escrow', 'iolta', 'derdengelden'], true);

		if ($isTrust === false) {
			return true;
		}

		return self::isTrue($o, 'segregated') === true && self::isTrue($o, 'commingled') === false;
	}//end accountSegregated()

	/*
	 * ----------------------------------------------------------------------
	 * CashTransaction helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * The effective cash amount of the transaction (single + linked operations),
	 * since a limit/threshold applies to linked operations as a whole.
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 *
	 * @return float
	 */
	private static function cashAmount(array $o): float {
		$single = (float)($o['amount'] ?? 0);
		$linked = (float)($o['linkedTransactionsTotal'] ?? 0);
		return max($single, $linked);
	}//end cashAmount()

	/**
	 * Whether the payment is a cash payment with at least one professional party
	 * (so the professional cash-limit applies).
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 *
	 * @return bool
	 */
	private static function isProfessionalCash(array $o): bool {
		if (strtolower(trim((string)($o['paymentMethod'] ?? 'cash'))) !== 'cash') {
			return false;
		}

		return self::isPrivateToPrivate($o) === false;
	}//end isProfessionalCash()

	/**
	 * Whether both parties act privately (no professional capacity) — the EU limit
	 * exemption.
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 *
	 * @return bool
	 */
	private static function isPrivateToPrivate(array $o): bool {
		$capacity = strtolower(trim((string)($o['partyCapacity'] ?? '')));
		$counterparty = strtolower(trim((string)($o['counterpartyType'] ?? '')));
		return $capacity === 'private' && in_array($counterparty, ['private', 'consumer', 'natural-person', ''], true);
	}//end isPrivateToPrivate()

	/**
	 * A professional cash payment stays strictly below the ceiling (single + linked).
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 * @param float $limit The ceiling.
	 *
	 * @return bool
	 */
	private static function cashWithinLimit(array $o, float $limit): bool {
		if (self::isProfessionalCash($o) === false) {
			return true;
		}

		return self::cashAmount($o) < $limit;
	}//end cashWithinLimit()

	/**
	 * A cash transfer between parties stays below the prohibition threshold (used by
	 * the IT/ES "EUR X or more is prohibited" rules — at or above the threshold fails).
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 * @param float $threshold The prohibition threshold.
	 *
	 * @return bool
	 */
	private static function cashBelow(array $o, float $threshold): bool {
		if (self::isProfessionalCash($o) === false) {
			return true;
		}

		return self::cashAmount($o) < $threshold;
	}//end cashBelow()

	/**
	 * The effective applied cash limit is no looser than the EU ceiling: when a
	 * national limit is recorded it must be at or below the EU ceiling (stricter is
	 * applied); when none is recorded the EU ceiling itself governs and passes.
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 * @param float $euCeil The EU ceiling.
	 *
	 * @return bool
	 */
	private static function strictestLimitApplied(array $o, float $euCeil): bool {
		if (self::numeric($o, 'appliedCashLimit') === false) {
			return true;
		}

		return (float)$o['appliedCashLimit'] <= $euCeil;
	}//end strictestLimitApplied()

	/**
	 * Customer due diligence has been performed when the cash transaction is at or
	 * above the CDD threshold (single + linked). Below the threshold it is not
	 * required and passes.
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 * @param float $threshold The CDD threshold.
	 *
	 * @return bool
	 */
	private static function cddAtOrAbove(array $o, float $threshold): bool {
		if (self::cashAmount($o) < $threshold) {
			return true;
		}

		return self::isTrue($o, 'cddPerformed');
	}//end cddAtOrAbove()

	/**
	 * Precious-metals dealer CDD: when the goods are precious metals and the cash is
	 * at or above the threshold, CDD must have been performed. Otherwise passes.
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 * @param float $threshold The CDD threshold.
	 *
	 * @return bool
	 */
	private static function preciousMetalsCdd(array $o, float $threshold): bool {
		if (strtolower(trim((string)($o['goodsCategory'] ?? ''))) !== 'precious-metals') {
			return true;
		}

		if (self::cashAmount($o) < $threshold) {
			return true;
		}

		return self::isTrue($o, 'cddPerformed');
	}//end preciousMetalsCdd()

	/**
	 * A trade/business receiving more than the USD 10 000 cash threshold in one
	 * transaction has filed Form 8300. At or below the threshold it is not required.
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 * @param float $threshold The reporting threshold.
	 *
	 * @return bool
	 */
	private static function form8300Filed(array $o, float $threshold): bool {
		if ((float)($o['amount'] ?? 0) <= $threshold) {
			return true;
		}

		return self::isTrue($o, 'form8300Required') === true;
	}//end form8300Filed()

	/**
	 * Related cash payments aggregating to more than the threshold within a 12-month
	 * period from the same payer are reported on Form 8300; otherwise not required.
	 *
	 * @param array<string, mixed> $o The cash transaction.
	 * @param float $threshold The aggregation threshold.
	 *
	 * @return bool
	 */
	private static function form8300Aggregate(array $o, float $threshold): bool {
		$aggregate = (float)($o['aggregate12mTotal'] ?? $o['amount'] ?? 0);
		if ($aggregate <= $threshold) {
			return true;
		}

		return self::isTrue($o, 'form8300Required') === true;
	}//end form8300Aggregate()

	/*
	 * ----------------------------------------------------------------------
	 * AmlReport helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * The report is of the given type and its amount is strictly above the threshold
	 * (the "more than" reporting tests). A report of another type is out of scope.
	 *
	 * @param array<string, mixed> $o The report.
	 * @param string $type The required report type.
	 * @param float $threshold The threshold.
	 *
	 * @return bool
	 */
	private static function reportOverThreshold(array $o, string $type, float $threshold): bool {
		if (self::reportType($o) !== strtolower($type)) {
			return true;
		}

		return (float)($o['amount'] ?? 0) > $threshold;
	}//end reportOverThreshold()

	/**
	 * The report is of the given type and its amount is at or over the threshold
	 * (the "exceeding" aggregate tests, e.g. FBAR). Another type is out of scope.
	 *
	 * @param array<string, mixed> $o The report.
	 * @param string $type The required report type.
	 * @param float $threshold The threshold.
	 *
	 * @return bool
	 */
	private static function reportAtOrOver(array $o, string $type, float $threshold): bool {
		if (self::reportType($o) !== strtolower($type)) {
			return true;
		}

		return (float)($o['amount'] ?? 0) >= $threshold;
	}//end reportAtOrOver()

	/**
	 * A same-day CTR aggregate over the threshold has been reported as a single CTR.
	 * Non-CTR reports are out of scope and pass.
	 *
	 * @param array<string, mixed> $o The report.
	 * @param float $threshold The aggregation threshold.
	 *
	 * @return bool
	 */
	private static function ctrAggregate(array $o, float $threshold): bool {
		if (self::reportType($o) !== 'ctr') {
			return true;
		}

		return (float)($o['sameDayAggregate'] ?? $o['amount'] ?? 0) > $threshold;
	}//end ctrAggregate()

	/**
	 * A report of the given type was filed within $days calendar days of the
	 * reference date. Another type is out of scope and passes.
	 *
	 * @param array<string, mixed> $o The report.
	 * @param string $type The required report type.
	 * @param string $fromKey The reference-date field (transaction / detection).
	 * @param int $days The filing window in calendar days.
	 *
	 * @return bool
	 */
	private static function filedWithinDays(array $o, string $type, string $fromKey, int $days): bool {
		if (self::reportType($o) !== strtolower($type)) {
			return true;
		}

		$from = self::parseDate((string)($o[$fromKey] ?? ''));
		$filed = self::parseDate((string)($o['filedDate'] ?? ''));
		if ($from === null || $filed === null) {
			return false;
		}

		$deadline = $from->modify('+' . $days . ' days');
		if ($deadline === false) {
			return false;
		}

		return $filed >= $from && $filed <= $deadline;
	}//end filedWithinDays()

	/**
	 * A Form 8300 filer furnished the written payer statement by 31 January of the
	 * year following the transaction. Non-8300 reports are out of scope.
	 *
	 * @param array<string, mixed> $o The report.
	 *
	 * @return bool
	 */
	private static function payerStatementByJan31(array $o): bool {
		if (self::reportType($o) !== '8300') {
			return true;
		}

		$txn = self::parseDate((string)($o['transactionDate'] ?? ''));
		$statement = self::parseDate((string)($o['payerStatementDate'] ?? ''));
		if ($txn === null || $statement === null) {
			return false;
		}

		$deadline = self::parseDate(((int)$txn->format('Y') + 1) . '-01-31');
		if ($deadline === null) {
			return false;
		}

		return $statement <= $deadline;
	}//end payerStatementByJan31()

	/**
	 * An "unusual"-type report at or over the FIU reporting threshold has been filed.
	 * Other report types are out of scope and pass.
	 *
	 * @param array<string, mixed> $o The report.
	 * @param float $threshold The FIU reporting threshold.
	 *
	 * @return bool
	 */
	private static function unusualReportAtOrOver(array $o, float $threshold): bool {
		if (self::reportType($o) !== 'unusual') {
			return true;
		}

		return (float)($o['amount'] ?? 0) >= $threshold && self::present($o, 'filedDate');
	}//end unusualReportAtOrOver()

	/**
	 * The lower-cased report type.
	 *
	 * @param array<string, mixed> $o The report.
	 *
	 * @return string
	 */
	private static function reportType(array $o): string {
		return strtolower(trim((string)($o['reportType'] ?? '')));
	}//end reportType()

	/*
	 * ----------------------------------------------------------------------
	 * SanctionsScreening helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * The customer/UBO was screened before the transaction: a screened date is
	 * present and is on or before the transaction date.
	 *
	 * @param array<string, mixed> $o The screening.
	 *
	 * @return bool
	 */
	private static function screenedBeforeTransaction(array $o): bool {
		$screened = self::parseDate((string)($o['screenedDate'] ?? ''));
		if ($screened === null) {
			return false;
		}

		$txn = self::parseDate((string)($o['transactionDate'] ?? ''));
		if ($txn === null) {
			// Screened, but no transaction recorded yet (pre-relationship) — still valid.
			return true;
		}

		return $screened <= $txn;
	}//end screenedBeforeTransaction()

	/**
	 * The relation was screened and, where a match was found, it was reported.
	 *
	 * @param array<string, mixed> $o The screening.
	 *
	 * @return bool
	 */
	private static function screenedAndMatchReported(array $o): bool {
		if (self::present($o, 'screenedDate') === false) {
			return false;
		}

		if (self::isTrue($o, 'matched') === true) {
			return self::present($o, 'reportedDate');
		}

		return true;
	}//end screenedAndMatchReported()

	/**
	 * The transaction/party was screened against the OFAC SDN list, and any match
	 * was reported to OFAC.
	 *
	 * @param array<string, mixed> $o The screening.
	 *
	 * @return bool
	 */
	private static function ofacScreened(array $o): bool {
		$lists = ($o['listsScreened'] ?? []);
		$listName = strtoupper((string)($o['listName'] ?? ''));
		$screenedOfac = (strpos($listName, 'OFAC') !== false || strpos($listName, 'SDN') !== false);
		if ($screenedOfac === false && is_array($lists) === true) {
			foreach ($lists as $list) {
				$name = strtoupper((string)$list);
				if (strpos($name, 'OFAC') !== false || strpos($name, 'SDN') !== false) {
					$screenedOfac = true;
					break;
				}
			}
		}

		if ($screenedOfac === false) {
			return false;
		}

		if (self::isTrue($o, 'matched') === true) {
			return self::present($o, 'reportedDate');
		}

		return true;
	}//end ofacScreened()

	/*
	 * ----------------------------------------------------------------------
	 * CddRecord helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * CDD was performed when the occasional transaction is at or above the threshold;
	 * below it, CDD is not required and the record passes.
	 *
	 * @param array<string, mixed> $o The CDD record.
	 * @param float $threshold The CDD threshold.
	 *
	 * @return bool
	 */
	private static function cddRecordPerformedAt(array $o, float $threshold): bool {
		if ((float)($o['transactionAmount'] ?? 0) < $threshold) {
			return true;
		}

		return self::isTrue($o, 'performed');
	}//end cddRecordPerformedAt()

	/**
	 * A beneficial owner at or above the ownership/voting threshold has been
	 * identified; below the threshold no UBO arises from ownership and the record
	 * passes.
	 *
	 * @param array<string, mixed> $o The CDD record.
	 * @param float $threshold The UBO ownership percentage threshold.
	 *
	 * @return bool
	 */
	private static function uboThresholdRecorded(array $o, float $threshold): bool {
		if (self::numeric($o, 'uboOwnershipPct') === false) {
			return false;
		}

		if ((float)$o['uboOwnershipPct'] < $threshold) {
			return true;
		}

		return self::isTrue($o, 'uboIdentified');
	}//end uboThresholdRecorded()

	/*
	 * ----------------------------------------------------------------------
	 * FundsTransfer helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Originator and beneficiary information accompanies the transfer: payer name +
	 * account and payee name + account are all present (the travel rule).
	 *
	 * @param array<string, mixed> $o The funds transfer.
	 *
	 * @return bool
	 */
	private static function travelRuleComplete(array $o): bool {
		return self::present($o, 'originatorName')
			&& self::present($o, 'originatorAccount')
			&& self::present($o, 'beneficiaryName')
			&& self::present($o, 'beneficiaryAccount');

	}//end travelRuleComplete()

	/*
	 * ----------------------------------------------------------------------
	 * TrustLedger helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * No individual client balance within the pooled trust/escrow account is
	 * negative. Fail-closed when there are no client balances to evidence.
	 *
	 * @param array<string, mixed> $o The trust ledger.
	 *
	 * @return bool
	 */
	private static function noClientOverdraw(array $o): bool {
		$balances = ($o['clientBalances'] ?? []);
		if (is_array($balances) === false || $balances === []) {
			return false;
		}

		foreach ($balances as $entry) {
			if (is_array($entry) === false || is_numeric(($entry['balance'] ?? null)) === false) {
				return false;
			}

			if ((int)round(((float)$entry['balance']) * 100) < 0) {
				return false;
			}
		}

		return true;
	}//end noClientOverdraw()

	/*
	 * ----------------------------------------------------------------------
	 * CashCount helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * The physical cash count reconciles to the cash-book balance (equal to the cent).
	 *
	 * @param array<string, mixed> $o The cash count.
	 *
	 * @return bool
	 */
	private static function cashCountReconciled(array $o): bool {
		if (self::numeric($o, 'countedAmount') === false || self::numeric($o, 'bookBalance') === false) {
			return false;
		}

		return (int)round(((float)$o['countedAmount']) * 100) === (int)round(((float)$o['bookBalance']) * 100);
	}//end cashCountReconciled()

	/**
	 * The running cash-account balance is not negative.
	 *
	 * @param array<string, mixed> $o The cash count.
	 *
	 * @return bool
	 */
	private static function cashBalanceNonNegative(array $o): bool {
		if (self::numeric($o, 'runningBalance') === false) {
			return false;
		}

		return (int)round(((float)$o['runningBalance']) * 100) >= 0;
	}//end cashBalanceNonNegative()

	/**
	 * The daily Z-report total reconciles to the recorded cash takings (to the cent).
	 *
	 * @param array<string, mixed> $o The cash count.
	 *
	 * @return bool
	 */
	private static function zReportReconciled(array $o): bool {
		if (self::numeric($o, 'zReportTotal') === false || self::numeric($o, 'recordedTakings') === false) {
			return false;
		}

		return (int)round(((float)$o['zReportTotal']) * 100) === (int)round(((float)$o['recordedTakings']) * 100);
	}//end zReportReconciled()

	/*
	 * ----------------------------------------------------------------------
	 * BankReconciliation helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * The bank-account balance is reconciled to the statement: the reconciled
	 * balance equals the statement closing balance (to the cent), and any residual
	 * variance is documented (a reason) or zero.
	 *
	 * @param array<string, mixed> $o The bank reconciliation.
	 *
	 * @return bool
	 */
	private static function bankReconciled(array $o): bool {
		if (self::numeric($o, 'closingBalance') === false || self::numeric($o, 'reconciledBalance') === false) {
			return false;
		}

		$matched = ((int)round(((float)$o['reconciledBalance']) * 100) === (int)round(((float)$o['closingBalance']) * 100));
		if ($matched === true) {
			return true;
		}

		// An unreconciled difference is acceptable only when the variance is documented.
		return self::numeric($o, 'variance') === true && self::present($o, 'varianceReason');
	}//end bankReconciled()

	/*
	 * ----------------------------------------------------------------------
	 * Generic helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * True when a field holds a non-empty value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * True when a field holds a present, numeric value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function numeric(array $o, string $key): bool {
		return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;
	}//end numeric()

	/**
	 * True when a boolean-ish field is strictly truthy (true / 1 / "1" / "true").
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function isTrue(array $o, string $key): bool {
		$value = ($o[$key] ?? null);
		if (is_bool($value) === true) {
			return $value;
		}

		return in_array($value, [1, '1', 'true'], true);
	}//end isTrue()

	/**
	 * The retention date for a record is at least $years after the reference date.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $fromKey The reference-date field.
	 * @param int $years Minimum retention period in whole years.
	 *
	 * @return bool
	 */
	private static function retainsYearsFrom(array $o, string $fromKey, int $years): bool {
		$from = self::parseDate((string)($o[$fromKey] ?? ''));
		$retention = self::parseDate((string)($o['retentionUntil'] ?? ''));
		if ($from === null || $retention === null) {
			return false;
		}

		$required = $from->modify('+' . $years . ' years');
		if ($required === false) {
			return false;
		}

		return $retention >= $required;
	}//end retainsYearsFrom()

	/**
	 * Parse a Y-m-d (or ISO 8601) date string to an immutable date, or null when
	 * empty / unparseable.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function parseDate(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}

	}//end parseDate()
}//end class
