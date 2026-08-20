<?php

/**
 * Compliance-tail check provider
 *
 * Contributes the last cleanly-mappable machine-checkable rules across three
 * domains that the domain-specific providers do not yet enforce:
 *
 * 1. The EN 16931 special-VAT-category business rules for the categories shillinq
 *    can carry on an ARInvoice but the per-category InvoicingExtraChecks /
 *    InvoicingTailChecks providers do not cover: IGIC (Canary Islands, category
 *    'L', BR-AF-01/05/08/10), IPSI (Ceuta/Melilla, category 'M', BR-AG-01/10) and
 *    the Italian split-payment regime (category 'B', BR-B-01/02). They map onto the
 *    same ARInvoice.vatBreakdown[].category + invoiceLines[].vatCategory shape the
 *    existing standard/zero/exempt-category checks use, are conditioned on the
 *    category they govern and are vacuously satisfied for invoices that do not use
 *    it — so they pass on the standard-rated ('S') seed yet retain a real false path
 *    for a genuine IGIC/IPSI/split-payment invoice.
 *
 * 2. The OECD SAF-T 2.0 file-structure rules (national-reporting domain): the seven
 *    mandatory/conditional master-file and entry groups a SAF-T export must contain
 *    (Header, GeneralLedgerAccounts, Customers/Suppliers, TaxTable,
 *    GeneralLedgerEntries, SourceDocuments) plus the XSD-validity assertion. These
 *    target a new SaftFile object type (distinct from the existing SaftExportProfile
 *    configuration object); as that type has no rows in the test register this
 *    provider also implements SeedsObjects and supplies one fully-compliant sample
 *    so the audit actually evaluates the structure checks.
 *
 * 3. The VAT-Directive margin-scheme rules (vat domain) that map onto ARInvoice
 *    margin fields: art. 315 (the taxable amount under the margin scheme is the
 *    profit margin = selling price - purchase price) and arts. 322-325 (a dealer
 *    using the margin scheme deducts no input VAT and shows no separate VAT on the
 *    invoice). Both are conditioned on a marginScheme flag and vacuously satisfied
 *    for ordinary invoices. The OSS/IOSS return rules (oss-*, ioss-*), the
 *    second-hand-goods scope rule (art. 306) and the triangulation simplification
 *    (art. 141) are intentionally NOT registered here: shillinq models no One-Stop-
 *    Shop / Import-One-Stop-Shop return nor a triangulation chain, so a meaningful
 *    predicate over real fields cannot be written for them.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class rather than reaching into
 * RuleEngine). Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/invoicing.json, national-reporting.json or vat.json.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.Commenting.InlineComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable tail checks: EN 16931 special VAT categories + margin scheme over
 * ARInvoice, and OECD SAF-T file structure over a new SaftFile object.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Pre-existing debt
 *     (issue #506): this class enumerates every tail rule the catalogue
 *     defines; inherent complexity. Deferred to a follow-up.
 */
class ComplianceTailChecks implements CheckProvider, SeedsObjects {
	/**
	 * Per-object-type, per-rule predicates for the compliance-tail rules.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// ===== EN 16931 — IGIC (Canary Islands general indirect tax), category 'L'. =====
				// BR-AF-01: when a line/allowance/charge uses 'L', the VAT breakdown
				// contains at least one breakdown group of 'L'.
				'en16931-br-af-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'L'),
				// BR-AF-05: every 'L' line carries a VAT rate of zero or greater (a
				// present, non-negative rate).
				'en16931-br-af-05' => static fn (array $o): bool => self::lineRateNonNegativeForCategory($o, 'L'),
				// BR-AF-08: each 'L' breakdown group's taxable amount reconciles to the
				// sum of 'L' line nets plus 'L' document charges minus 'L' allowances.
				'en16931-br-af-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'L'),
				// BR-AF-10: a 'L' breakdown group carries no VAT exemption reason
				// (code or text) — IGIC is a taxed, not exempt, category.
				'en16931-br-af-10' => static fn (array $o): bool => self::breakdownNoExemptionReason($o, 'L'),

				// ===== EN 16931 — IPSI (Ceuta/Melilla), category 'M'. =====
				// BR-AG-01: when a line/allowance/charge uses 'M', the VAT breakdown
				// contains at least one breakdown group of 'M'.
				'en16931-br-ag-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'M'),
				// BR-AG-10: a 'M' breakdown group carries no VAT exemption reason
				// (code or text) — IPSI is a taxed, not exempt, category.
				'en16931-br-ag-10' => static fn (array $o): bool => self::breakdownNoExemptionReason($o, 'M'),

				// ===== EN 16931 — Italian split payment (scissione dei pagamenti), category 'B'. =====
				// BR-B-01: an invoice using the 'B' category is a domestic Italian
				// invoice — seller and buyer country are both 'IT'.
				'en16931-br-b-01' => static fn (array $o): bool => self::splitPaymentDomesticItaly($o),
				// BR-B-02: an invoice that carries a 'B' (split-payment) line/allowance/
				// charge must NOT also carry a standard-rated 'S' line/allowance/charge.
				'en16931-br-b-02' => static fn (array $o): bool => self::splitPaymentNotMixedWithStandard($o),

				// ===== VAT Directive — margin scheme. =====
				// art. 315: under the margin scheme the taxable amount is the profit
				// margin — selling price less purchase price (the modelled margin
				// amount reconciles to selling - purchase, and is non-negative).
				'vatdir-art315-margin-taxable-amount' => static fn (array $o): bool => self::marginTaxableAmountOk($o),
				// arts. 322-325: a dealer using the margin scheme deducts no input VAT
				// on the margin-scheme goods and shows no VAT separately on the invoice
				// (no per-line / breakdown VAT, no separately-stated header VAT amount).
				'vatdir-art322-margin-no-deduction' => static fn (array $o): bool => self::marginNoSeparateVat($o),
			],
			'SaftFile' => [
				// ===== OECD SAF-T 2.0 — file structure. =====
				// Header group: company id, fiscal/selection period, currency, schema
				// version and producing-software details.
				'saft-header-group' => static fn (array $o): bool => self::headerGroupComplete($o),
				// GeneralLedgerAccounts master file: each account with id, description,
				// type and opening/closing balances.
				'saft-gl-accounts-group' => static fn (array $o): bool => self::glAccountsGroupComplete($o),
				// Customers and Suppliers master files: party id, VAT registration,
				// address and balances.
				'saft-customers-suppliers-group' => static fn (array $o): bool => self::partiesGroupComplete($o),
				// TaxTable master file: tax (VAT) codes, rates and descriptions.
				'saft-taxtable-group' => static fn (array $o): bool => self::taxTableGroupComplete($o),
				// GeneralLedgerEntries: journals and transaction lines, each line with
				// account, date, debit/credit amount and document reference.
				'saft-gl-entries-group' => static fn (array $o): bool => self::glEntriesGroupComplete($o),
				// SourceDocuments: sales/purchase invoices, payments, movement-of-goods
				// records linking source documents to ledger entries.
				'saft-source-documents-group' => static fn (array $o): bool => self::sourceDocumentsGroupComplete($o),
				// The export is a valid XML document conforming to the applicable
				// national SAF-T XSD schema version.
				'saft-xml-schema-validity' => static fn (array $o): bool => self::xsdValid($o),
			],
		];

	}//end checks()

	/**
	 * Field defaults backfilled onto existing ARInvoice rows so the margin-scheme
	 * predicates are decidable. The seeded invoices are ordinary (non-margin)
	 * supplies, so the marginScheme flag defaults off and both margin rules are
	 * vacuously satisfied; SaftFile is a new type seeded wholesale by seedObjects().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'ARInvoice' => [
				// Default: an ordinary (non-margin-scheme) supply, so the art. 315 /
				// art. 322 margin predicates are vacuously satisfied.
				'marginScheme' => false,
			],
		];

	}//end seedSpec()

	/**
	 * One fully-compliant SaftFile sample, created only when the SaftFile type is
	 * empty, so the seven SAF-T structure checks are actually evaluated. The sample
	 * carries a complete Header, a GeneralLedgerAccounts master file, Customers and
	 * Suppliers master files, a TaxTable, GeneralLedgerEntries journals, a
	 * SourceDocuments group and asserts XSD validity.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'SaftFile' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'fileVersion' => '2.0',
					'auditFileCountry' => 'NL',
					'xsdValid' => true,
					'header' => [
						'companyName' => 'Shillinq Demo B.V.',
						'companyId' => 'NL854502130B01',
						'taxRegistrationNbr' => 'NL854502130B01',
						'currencyCode' => 'EUR',
						'fiscalYear' => '2026',
						'startDate' => '2026-01-01',
						'endDate' => '2026-03-31',
						'taxonomyVersion' => 'NT16',
						'softwareId' => 'shillinq',
						'softwareVersion' => '1.0.0',
					],
					'generalLedgerAccounts' => [
						[
							'accountId' => '1000',
							'description' => 'Cash',
							'accountType' => 'asset',
							'openingBalance' => 5000.00,
							'closingBalance' => 7200.00,
						],
						[
							'accountId' => '8000',
							'description' => 'Revenue',
							'accountType' => 'income',
							'openingBalance' => 0.00,
							'closingBalance' => 12000.00,
						],
					],
					'customers' => [
						[
							'customerId' => 'C-001',
							'name' => 'Acme Buyer GmbH',
							'vatNumber' => 'DE123456789',
							'address' => 'Hauptstrasse 1, Berlin',
							'openingBalance' => 0.00,
							'closingBalance' => 1210.00,
						],
					],
					'suppliers' => [
						[
							'supplierId' => 'S-001',
							'name' => 'Office Supplies BV',
							'vatNumber' => 'NL001234567B01',
							'address' => 'Kerkstraat 5, Utrecht',
							'openingBalance' => 0.00,
							'closingBalance' => -363.00,
						],
					],
					'taxTable' => [
						[
							'taxCode' => 'NL-HIGH',
							'taxPercentage' => 21.0,
							'description' => 'NL standaardtarief 21%',
						],
						[
							'taxCode' => 'NL-LOW',
							'taxPercentage' => 9.0,
							'description' => 'NL verlaagd tarief 9%',
						],
					],
					'glEntries' => [
						[
							'journalId' => 'SALES',
							'transactionId' => 'T-2026-0001',
							'lines' => [
								[
									'accountId' => '1000',
									'postingDate' => '2026-01-15',
									'debitAmount' => 1210.00,
									'creditAmount' => 0.00,
									'documentReference' => 'INV-2026-0001',
								],
								[
									'accountId' => '8000',
									'postingDate' => '2026-01-15',
									'debitAmount' => 0.00,
									'creditAmount' => 1000.00,
									'documentReference' => 'INV-2026-0001',
								],
							],
						],
					],
					'sourceDocuments' => [
						[
							'documentType' => 'sales-invoice',
							'documentReference' => 'INV-2026-0001',
							'glTransactionId' => 'T-2026-0001',
							'date' => '2026-01-15',
							'totalAmount' => 1210.00,
						],
					],
				],
			],
		];

	}//end seedObjects()

	// ===================== EN 16931 category helpers =====================

	/**
	 * When any invoice line / document allowance / document charge carries VAT
	 * category $cat, the VAT breakdown contains at least one group of that category
	 * (EN 16931 BR-{AF,AG}-01). Vacuously true when the category is not used.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownCoversCategory(array $o, string $cat): bool {
		if (self::categoryUsed($o, $cat) === false) {
			return true;
		}

		return self::breakdownHasCategory($o, $cat);
	}//end breakdownCoversCategory()

	/**
	 * Every invoice line of category $cat carries a VAT rate of zero or greater (a
	 * present, non-negative numeric rate) — EN 16931 BR-AF-05. Vacuously true when
	 * the category is not used on a line.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function lineRateNonNegativeForCategory(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			if (is_numeric($line['vatRate'] ?? null) === false || ((float)$line['vatRate']) < 0.0) {
				return false;
			}
		}

		return true;
	}//end lineRateNonNegativeForCategory()

	/**
	 * For each breakdown group of category $cat, its taxable amount reconciles (cent
	 * precise) to the sum of the net amounts of the invoice lines, document charges
	 * (added) and document allowances (subtracted) of that same category (EN 16931
	 * BR-AF-08). Vacuously true when no breakdown group of $cat exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownTaxableReconciles(array $o, string $cat): bool {
		if (self::breakdownHasCategory($o, $cat) === false) {
			return true;
		}

		$lineSum = 0.0;
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') === $cat) {
				$lineSum += (float)($line['netAmount'] ?? 0);
			}
		}

		$allowanceSum = 0.0;
		foreach (self::items($o, 'allowances') as $item) {
			if ((string)($item['vatCategory'] ?? '') === $cat) {
				$allowanceSum += (float)($item['amount'] ?? 0);
			}
		}

		$chargeSum = 0.0;
		foreach (self::items($o, 'charges') as $item) {
			if ((string)($item['vatCategory'] ?? '') === $cat) {
				$chargeSum += (float)($item['amount'] ?? 0);
			}
		}

		$expected = ($lineSum - $allowanceSum + $chargeSum);

		$taxableSum = 0.0;
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') === $cat) {
				$taxableSum += (float)($group['taxableAmount'] ?? 0);
			}
		}

		return self::centsEqual($taxableSum, $expected);
	}//end breakdownTaxableReconciles()

	/**
	 * Every breakdown group of category $cat (a taxed category) carries NO VAT
	 * exemption reason code or text (EN 16931 BR-AF-10 / BR-AG-10). Vacuously true
	 * when no breakdown group of $cat exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownNoExemptionReason(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			$hasCode = trim((string)($group['exemptionReasonCode'] ?? '')) !== '';
			$hasText = trim((string)($group['exemptionReasonText'] ?? '')) !== '';
			if ($hasCode === true || $hasText === true) {
				return false;
			}
		}

		return true;
	}//end breakdownNoExemptionReason()

	/**
	 * When the 'B' split-payment category is used anywhere, the invoice is a domestic
	 * Italian invoice — seller and buyer country are both 'IT' (EN 16931 BR-B-01).
	 * Vacuously true when split payment is not used.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function splitPaymentDomesticItaly(array $o): bool {
		if (self::categoryUsed($o, 'B') === false) {
			return true;
		}

		return self::isItaly($o, 'sellerCountryCode') && self::isItaly($o, 'buyerCountryCode');
	}//end splitPaymentDomesticItaly()

	/**
	 * An invoice that carries a split-payment ('B') line / allowance / charge does
	 * NOT also carry a standard-rated ('S') line / allowance / charge (EN 16931
	 * BR-B-02). Vacuously true when split payment is not used.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function splitPaymentNotMixedWithStandard(array $o): bool {
		if (self::categoryUsed($o, 'B') === false) {
			return true;
		}

		return self::categoryUsed($o, 'S') === false;
	}//end splitPaymentNotMixedWithStandard()

	/**
	 * The country code under $key is Italy ('IT', case-insensitive, first two
	 * alphabetic characters).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The country-code field.
	 *
	 * @return bool
	 */
	private static function isItaly(array $o, string $key): bool {
		$code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($o[$key] ?? '')) ?? '', 0, 2));
		return $code === 'IT';
	}//end isItaly()

	/**
	 * Whether category $cat is used on any invoice line, document allowance or
	 * document charge.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function categoryUsed(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') === $cat) {
				return true;
			}
		}

		foreach (['allowances', 'charges'] as $key) {
			foreach (self::items($o, $key) as $item) {
				if ((string)($item['vatCategory'] ?? '') === $cat) {
					return true;
				}
			}
		}

		return false;
	}//end categoryUsed()

	/**
	 * True when at least one VAT breakdown group carries the category $cat.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownHasCategory(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') === $cat) {
				return true;
			}
		}

		return false;
	}//end breakdownHasCategory()

	// ===================== margin-scheme helpers =====================

	/**
	 * Under the margin scheme the taxable amount is the profit margin: the modelled
	 * margin amount equals selling price minus purchase price and is non-negative
	 * (VAT Directive art. 315). Vacuously true when the invoice is not a margin-scheme
	 * supply.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function marginTaxableAmountOk(array $o): bool {
		if (self::truthy($o, 'marginScheme') === false) {
			return true;
		}

		if (self::numericPresent($o, 'marginPurchasePrice') === false
			|| self::numericPresent($o, 'marginSellingPrice') === false
		) {
			return false;
		}

		$margin = ((float)$o['marginSellingPrice'] - (float)$o['marginPurchasePrice']);
		if ($margin < 0.0) {
			return false;
		}

		// The stated margin taxable amount, when present, reconciles to selling - purchase.
		if (self::numericPresent($o, 'marginTaxableAmount') === true) {
			return self::centsEqual((float)$o['marginTaxableAmount'], $margin);
		}

		return true;
	}//end marginTaxableAmountOk()

	/**
	 * A dealer using the margin scheme deducts no input VAT and shows no VAT
	 * separately on the invoice (VAT Directive arts. 322-325): no header VAT amount is
	 * separately stated and no invoice line carries a positive VAT rate. Vacuously
	 * true when the invoice is not a margin-scheme supply.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function marginNoSeparateVat(array $o): bool {
		if (self::truthy($o, 'marginScheme') === false) {
			return true;
		}

		// No separately-stated header VAT amount.
		if (self::numericPresent($o, 'taxAmount') === true && (float)$o['taxAmount'] > 0.0) {
			return false;
		}

		// No invoice line shows a positive VAT rate (no VAT shown separately).
		foreach (self::lines($o) as $line) {
			if (is_numeric($line['vatRate'] ?? null) === true && ((float)$line['vatRate']) > 0.0) {
				return false;
			}
		}

		// No breakdown group shows a positive tax amount.
		foreach (self::vatBreakdown($o) as $group) {
			if (is_numeric($group['taxAmount'] ?? null) === true && ((float)$group['taxAmount']) > 0.0) {
				return false;
			}
		}

		return true;
	}//end marginNoSeparateVat()

	// ===================== SAF-T structure helpers =====================

	/**
	 * The Header group is present with company identification, the fiscal/selection
	 * period, currency, taxonomy/schema version and producing-software details
	 * (SAF-T Header group).
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 *
	 * @return bool
	 */
	private static function headerGroupComplete(array $o): bool {
		$h = ($o['header'] ?? null);
		if (is_array($h) === false) {
			return false;
		}

		foreach (['companyName', 'companyId', 'currencyCode', 'startDate', 'endDate', 'taxonomyVersion', 'softwareId'] as $key) {
			if (self::present($h, $key) === false) {
				return false;
			}
		}

		return true;
	}//end headerGroupComplete()

	/**
	 * The GeneralLedgerAccounts master-file group lists at least one account, each
	 * with an id, description, type and opening/closing balances (SAF-T
	 * MasterFiles/GeneralLedgerAccounts).
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 *
	 * @return bool
	 */
	private static function glAccountsGroupComplete(array $o): bool {
		$accounts = self::items($o, 'generalLedgerAccounts');
		if ($accounts === []) {
			return false;
		}

		foreach ($accounts as $account) {
			if (self::present($account, 'accountId') === false
				|| self::present($account, 'description') === false
				|| self::present($account, 'accountType') === false
				|| self::numericPresent($account, 'openingBalance') === false
				|| self::numericPresent($account, 'closingBalance') === false
			) {
				return false;
			}
		}

		return true;
	}//end glAccountsGroupComplete()

	/**
	 * The Customers and Suppliers master-file groups are present, each listing at
	 * least one party with identification, a VAT registration number, an address and
	 * balances (SAF-T MasterFiles/Customers,Suppliers).
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 *
	 * @return bool
	 */
	private static function partiesGroupComplete(array $o): bool {
		return self::partyListComplete($o, 'customers', 'customerId')
			&& self::partyListComplete($o, 'suppliers', 'supplierId');

	}//end partiesGroupComplete()

	/**
	 * A party master-file list under $key has at least one party, each with the id
	 * field $idKey, a VAT number, an address and opening/closing balances.
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 * @param string $key The party collection property.
	 * @param string $idKey The party id field name.
	 *
	 * @return bool
	 */
	private static function partyListComplete(array $o, string $key, string $idKey): bool {
		$parties = self::items($o, $key);
		if ($parties === []) {
			return false;
		}

		foreach ($parties as $party) {
			if (self::present($party, $idKey) === false
				|| self::present($party, 'name') === false
				|| self::present($party, 'vatNumber') === false
				|| self::present($party, 'address') === false
				|| self::numericPresent($party, 'openingBalance') === false
				|| self::numericPresent($party, 'closingBalance') === false
			) {
				return false;
			}
		}

		return true;
	}//end partyListComplete()

	/**
	 * The TaxTable master-file group defines at least one tax (VAT) code with a code,
	 * a numeric rate and a description (SAF-T MasterFiles/TaxTable).
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 *
	 * @return bool
	 */
	private static function taxTableGroupComplete(array $o): bool {
		$taxes = self::items($o, 'taxTable');
		if ($taxes === []) {
			return false;
		}

		foreach ($taxes as $tax) {
			if (self::present($tax, 'taxCode') === false
				|| self::numericPresent($tax, 'taxPercentage') === false
				|| self::present($tax, 'description') === false
			) {
				return false;
			}
		}

		return true;
	}//end taxTableGroupComplete()

	/**
	 * The GeneralLedgerEntries group has at least one journal/transaction, each
	 * carrying transaction lines with an account, posting date, debit/credit amount
	 * and a document reference (SAF-T GeneralLedgerEntries).
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 *
	 * @return bool
	 */
	private static function glEntriesGroupComplete(array $o): bool {
		$transactions = self::items($o, 'glEntries');
		if ($transactions === []) {
			return false;
		}

		foreach ($transactions as $transaction) {
			if (self::present($transaction, 'journalId') === false
				|| self::present($transaction, 'transactionId') === false
			) {
				return false;
			}

			$lines = self::items($transaction, 'lines');
			if ($lines === []) {
				return false;
			}

			foreach ($lines as $line) {
				if (self::present($line, 'accountId') === false
					|| self::present($line, 'postingDate') === false
					|| self::numericPresent($line, 'debitAmount') === false
					|| self::numericPresent($line, 'creditAmount') === false
					|| self::present($line, 'documentReference') === false
				) {
					return false;
				}
			}
		}//end foreach

		return true;
	}//end glEntriesGroupComplete()

	/**
	 * The SourceDocuments group has at least one source document, each carrying a
	 * document type, a document reference and a link to a ledger transaction (SAF-T
	 * SourceDocuments).
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 *
	 * @return bool
	 */
	private static function sourceDocumentsGroupComplete(array $o): bool {
		$documents = self::items($o, 'sourceDocuments');
		if ($documents === []) {
			return false;
		}

		foreach ($documents as $document) {
			if (self::present($document, 'documentType') === false
				|| self::present($document, 'documentReference') === false
				|| self::present($document, 'glTransactionId') === false
			) {
				return false;
			}
		}

		return true;
	}//end sourceDocumentsGroupComplete()

	/**
	 * The export asserts it is a valid XML document conforming to the applicable
	 * national SAF-T XSD schema version (SAF-T xml-schema-validity).
	 *
	 * @param array<string, mixed> $o The SaftFile.
	 *
	 * @return bool
	 */
	private static function xsdValid(array $o): bool {
		return self::truthy($o, 'xsdValid');
	}//end xsdValid()

	// ===================== shared accessors / utilities =====================

	/**
	 * A non-empty scalar value is present under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * A present, numeric value under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function numericPresent(array $o, string $key): bool {
		return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;
	}//end numericPresent()

	/**
	 * The field is a truthy boolean flag (true / 'true' / 1 / '1' / 'yes').
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function truthy(array $o, string $key): bool {
		$v = ($o[$key] ?? null);
		if (is_bool($v) === true) {
			return $v;
		}

		return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes'], true);
	}//end truthy()

	/**
	 * Two amounts are equal at cent precision.
	 *
	 * @param float $a The first amount.
	 * @param float $b The second amount.
	 *
	 * @return bool
	 */
	private static function centsEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end centsEqual()

	/**
	 * The invoice lines (BG-25) normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function lines(array $o): array {
		$lines = ($o['invoiceLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return array_values(array_filter($lines, 'is_array'));
	}//end lines()

	/**
	 * The VAT breakdown groups (BG-23) normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function vatBreakdown(array $o): array {
		$vb = ($o['vatBreakdown'] ?? []);
		if (is_array($vb) === false) {
			return [];
		}

		return array_values(array_filter($vb, 'is_array'));
	}//end vatBreakdown()

	/**
	 * A named array-of-objects property normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The host object.
	 * @param string $key The property.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function items(array $o, string $key): array {
		$items = ($o[$key] ?? []);
		if (is_array($items) === false) {
			return [];
		}

		return array_values(array_filter($items, 'is_array'));
	}//end items()
}//end class
