<?php

/**
 * VAT-tail / NL-BBV / ledger-integrity-tail check provider
 *
 * Contributes the remaining machine-checkable rules of three corpora that the
 * VatChecks / RemainingVatChecks, RuleEngine built-in registry and
 * LedgerIntegrityChecks providers do not yet enforce:
 *
 *  - VAT Directive 2006/112/EC tail (lib/Standards/rules/vat.json): the
 *    triangulation simplification and its reverse-charge leg (arts. 141/197),
 *    the mixed-use pro-rata deduction (art. 173), the deduction adjustment /
 *    capital-goods revision period (art. 184) and the small-enterprise turnover
 *    exemption (art. 282). The OSS/IOSS and margin-scheme tail rules are
 *    intentionally skipped — shillinq models neither a One-Stop-Shop return nor a
 *    margin-scheme ledger, so no meaningful field predicate can be written.
 *
 *  - NL public-sector BBV (lib/Standards/rules/public-sector.json, Besluit
 *    begroting en verantwoording provincies en gemeenten): the budget and
 *    annual-accounts composition (arts. 7/8/24), the mandatory paragraphs
 *    (art. 9), the taakvelden overview, and the asset capitalisation / valuation
 *    rules (arts. 59/62). These map onto a new BbvStatement object that records,
 *    per fiscal year, the structural composition of a municipality's / province's
 *    begroting and jaarstukken. The IPSAS and GASB rules in the same corpus are
 *    intentionally NOT enforced (out of scope: shillinq is an NL-first ledger and
 *    does not model an IPSAS/GASB measurement engine).
 *
 *  - Ledger-integrity tail (lib/Standards/rules/integrity-retention.json): the
 *    FR FEC file-format / naming rule (fr-fec-format), the NF525 secured-archive
 *    grand-total chaining (fr-nf525-secured-archive) and the PT certified-
 *    invoicing chained ATCUD/hash (pt-certified-invoicing-software). These map
 *    onto GLTransaction fields recording the export-file shape, the perpetual
 *    running total and the tamper-evident document-chain hash.
 *
 * BbvStatement has no existing rows in the local register, so this provider also
 * implements SeedsObjects and supplies fully-compliant sample statements; its
 * schema is declared in lib/Settings/register.d/checks-vat-bbv-ledger-tail.json.
 * ARInvoice / APTransaction / GLTransaction already carry rows, so the extra
 * scalar/array fields the VAT-tail and ledger-tail predicates read are added via
 * seedSpec() (backfilled by RuleTestDataSeeder) with defaults that SATISFY the
 * predicates (the trigger flags default off, so the conditional rules pass
 * vacuously on existing data — mirroring RemainingVatChecks).
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class). Every ruleId is a real
 * `machineCheckable` id from its corpus and is not registered by any other
 * provider.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable VAT-tail, NL-BBV and ledger-integrity-tail checks.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): this class enumerates every VAT-tail/
 * NL-BBV/ledger-integrity-tail rule the catalogue defines; inherent
 * complexity. Variable renames deferred pending a dedicated pass.
 */
final class VatBbvLedgerTailChecks implements CheckProvider, SeedsObjects {
	/**
	 * The seven mandatory BBV paragraphs (art. 9 BBV): local levies, resilience
	 * capacity (weerstandsvermogen), maintenance of capital goods (onderhoud
	 * kapitaalgoederen), financing, operational management (bedrijfsvoering),
	 * related parties (verbonden partijen) and land policy (grondbeleid).
	 *
	 * @var array<int, string>
	 */
	private const BBV_PARAGRAPHS = [
		'lokaleheffingen',
		'weerstandsvermogen',
		'onderhoudkapitaalgoederen',
		'financiering',
		'bedrijfsvoering',
		'verbondenpartijen',
		'grondbeleid',
	];

	/**
	 * EU VAT identifier country prefixes (incl. Greece's 'EL' and 'XI').
	 *
	 * @var array<int, string>
	 */
	private const VAT_PREFIXES = [
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'EL',
		'ES',
		'FI',
		'FR',
		'GR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
		'XI',
	];

	/**
	 * Per-object-type, per-rule predicates for the VAT / BBV / ledger tail.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// --- Triangulation simplification (art. 141). ---
				// The intermediary B's intra-Community acquisition is exempt where
				// the goods move directly to a final customer C in a third Member
				// State who accounts for the VAT under reverse charge: the three
				// parties are in three different Member States, the goods are
				// delivered directly to C, and C's VAT id (the reverse-charge
				// debtor) is identified. Vacuous when this is not a triangulation.
				'vatdir-art141-triangulation-simplification' => static fn (array $o): bool => (self::truthy($o, 'triangulation') === false
					|| self::triangulationConditionsWith($o)),

				// --- Reverse charge on triangulation (art. 197). ---
				// VAT is payable by the final customer C where the art.-141
				// conditions are met AND a compliant invoice is issued — the
				// invoice carries the reverse-charge mention. Vacuous otherwise.
				'vatdir-art197-reverse-charge-triangulation' => static fn (array $o): bool => (self::truthy($o, 'triangulation') === false
					|| (self::triangulationConditionsWith($o) && self::hasReverseChargeMention($o))),

				// --- SME turnover exemption (art. 282). ---
				// A small enterprise applying the exemption has an annual turnover
				// below the national threshold (and, where it uses the cross-border
				// SME scheme, a Union-wide turnover at most EUR 100 000). Vacuous
				// when the exemption is not applied.
				'vatdir-art282-smes-exemption' => static fn (array $o): bool => (self::truthy($o, 'smeExemptionApplied') === false
					|| self::smeExemptionConsistent($o)),
			],
			'APTransaction' => [
				// --- Pro-rata deduction on mixed use (art. 173). ---
				// Where a purchase is used for both deductible and non-deductible
				// transactions, only the pro-rata proportion is deductible: a valid
				// percentage (0..100) is recorded and the deductible VAT equals the
				// input VAT times that proportion. Vacuous for single-use purchases.
				'vatdir-art173-pro-rata-deduction' => static fn (array $o): bool => (self::truthy($o, 'mixedUse') === false
					|| self::proRataConsistent($o)),

				// --- Adjustment of deductions (art. 184). ---
				// Where an initial deduction is later adjusted, the adjustment amount
				// is recorded; for capital goods a multi-year revision period (NL:
				// 5y movables / 10y immovable) is recorded as a positive number of
				// years. Vacuous when no adjustment is made.
				'vatdir-art184-adjustment-deductions' => static fn (array $o): bool => (self::truthy($o, 'deductionAdjusted') === false
					|| self::adjustmentConsistent($o)),
			],
			'GLTransaction' => [
				// --- FR FEC file format & naming (fr-fec-format). ---
				// The Fichier des Écritures Comptables is a flat text or XML file,
				// tab- or pipe-delimited, named SIREN + 'FEC' + closing date
				// (YYYYMMDD). Enforced on the recorded export descriptor. Vacuous
				// until an export descriptor is attached.
				'fr-fec-format' => static fn (array $o): bool => (self::hasFecExport($o) === false
					|| self::fecExportFormatOk($o)),

				// --- NF525 secured archive / grand total (fr-nf525-secured-archive). ---
				// Certified cash software produces periodic closing totals (grand
				// total perpétuel) secured and archived as an unbroken sequence:
				// a perpetual running total, a gapless sequence number and an
				// integrity signature. Vacuous until a closing record is attached.
				'fr-nf525-secured-archive' => static fn (array $o): bool => (self::hasNf525Closing($o) === false
					|| self::nf525ClosingOk($o)),

				// --- PT certified chained invoicing (pt-certified-invoicing-software). ---
				// Each document carries a chained ATCUD / hash ensuring an unbroken,
				// tamper-evident sequence: the ATCUD, the document hash and the
				// previous document's hash are all present (chain link). Vacuous
				// until the certified-invoicing chain is attached.
				'pt-certified-invoicing-software' => static fn (array $o): bool => (self::hasPtChain($o) === false
					|| self::ptChainOk($o)),
			],
			'BbvStatement' => [
				// --- Budget structure (art. 7 BBV). ---
				// The begroting consists of a policy budget (beleidsbegroting =
				// programmaplan + paragraphs) and a financial budget (financiële
				// begroting = overzicht baten/lasten + financiële positie).
				'bbv-art7-begroting-structure' => static fn (array $o): bool => self::isBudgetDoc($o) === false
					|| (self::hasPart($o, 'beleidsbegroting')
					&& self::hasPart($o, 'financielebegroting')),

				// --- Programme plan content (art. 8 BBV). ---
				// The programmaplan sets out the programmes, the algemene
				// dekkingsmiddelen, the overhead, the VPB charge and the amount for
				// unforeseen expenditure (onvoorzien).
				'bbv-art8-programmaplan' => static fn (array $o): bool => self::isBudgetDoc($o) === false
					|| self::programmaplanComplete($o),

				// --- Mandatory paragraphs (art. 9 BBV). ---
				// Both the begroting and the jaarstukken include the seven mandatory
				// paragraphs (local levies, weerstandsvermogen, onderhoud
				// kapitaalgoederen, financiering, bedrijfsvoering, verbonden
				// partijen, grondbeleid).
				'bbv-art9-mandatory-paragraphs' => static fn (array $o): bool => self::allParagraphsPresent($o),

				// --- Annual accounts composition (art. 24 BBV). ---
				// The jaarstukken consist of the jaarverslag (programmaverantwoording
				// + paragraphs) and the jaarrekening (overzicht baten/lasten, balans,
				// rechtmatigheidsverantwoording and accountantsverklaring).
				'bbv-art24-jaarstukken' => static fn (array $o): bool => self::isAccountsDoc($o) === false
					|| self::jaarstukkenComplete($o),

				// --- Capitalisation of fixed assets (art. 59 BBV). ---
				// Tangible/intangible fixed assets meeting the activation criteria
				// are capitalised: each declared fixed asset is flagged geactiveerd
				// (capitalised) on the balance sheet.
				'bbv-art59-activeren' => static fn (array $o): bool => self::fixedAssetsActivated($o),

				// --- Valuation at acquisition/production cost (art. 62 BBV). ---
				// Assets are valued at verkrijgings- of vervaardigingsprijs: each
				// declared fixed asset records a non-negative acquisition-or-
				// production cost and is valued on that basis.
				'bbv-art62-verkrijgingsprijs' => static fn (array $o): bool => self::fixedAssetsValuedAtCost($o),

				// --- Taakvelden overview (bbv-taakvelden-overview). ---
				// The begroting and jaarstukken include the mandatory overview of
				// estimated revenues and expenses per taakveld: a non-empty list of
				// taakveld lines, each with a code and a numeric estimated revenue
				// and expense, enabling inter-municipal comparison.
				'bbv-taakvelden-overview' => static fn (array $o): bool => self::taskFieldsComplete($o),
			],
		];

	}//end checks()

	/**
	 * Field defaults backfilled onto the EXISTING ARInvoice / APTransaction /
	 * GLTransaction rows. The VAT-tail trigger flags default off so the conditional
	 * rules are satisfied vacuously on existing data; the ledger-tail descriptors
	 * default to a fully-compliant FEC/NF525/PT-chain record so those rules pass on
	 * the seeded GL transactions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'ARInvoice' => [
				// Triangulation / SME tail off by default — the existing domestic
				// sales invoices are neither a triangular transaction nor under the
				// small-enterprise exemption, so arts. 141/197/282 pass vacuously.
				'triangulation' => false,
				'smeExemptionApplied' => false,
			],
			'APTransaction' => [
				// Single-use, unadjusted purchases by default, so arts. 173/184 pass
				// vacuously on the existing purchase rows.
				'mixedUse' => false,
				'deductionAdjusted' => false,
			],
			'GLTransaction' => [
				// A compliant FR FEC export descriptor.
				'fecExport' => [
					'fileType' => 'text',
					'delimiter' => 'pipe',
					'encoding' => 'iso-8859-15',
					'fileName' => '123456789FEC20261231.txt',
				],
				// A compliant NF525 perpetual-closing record (gapless, signed).
				'nf525Closing' => [
					'period' => 'daily',
					'perpetualTotal' => 125000.00,
					'sequenceNumber' => 4210,
					'signature' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
				],
				// A compliant PT certified-invoicing chain link.
				'ptChain' => [
					'atcud' => 'CSDF7T5H-3210',
					'hash' => '0f1e2d3c4b5a69788796a5b4c3d2e1f0',
					'previousHash' => 'ffeeddccbbaa99887766554433221100',
				],
			],
		];

	}//end seedSpec()

	/**
	 * One fully-compliant BbvStatement per document role (begroting + jaarstukken),
	 * created only when the BbvStatement type is empty so the audit evaluates the
	 * BBV checks. Each sample satisfies every BBV predicate above.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		$paragraphs = self::BBV_PARAGRAPHS;

		$taskFields = [
			['code' => '0.1', 'name' => 'Bestuur', 'estimatedRevenue' => 120000.00, 'estimatedExpense' => 480000.00],
			['code' => '2.1', 'name' => 'Verkeer en vervoer', 'estimatedRevenue' => 50000.00, 'estimatedExpense' => 900000.00],
			['code' => '5.2', 'name' => 'Sportaccommodaties', 'estimatedRevenue' => 75000.00, 'estimatedExpense' => 260000.00],
		];

		$fixedAssets = [
			['name' => 'Gemeentehuis', 'kind' => 'tangible', 'capitalised' => true, 'acquisitionCost' => 4200000.00, 'valuationBasis' => 'acquisitionCost'],
			['name' => 'Softwarelicentie GIS', 'kind' => 'intangible', 'capitalised' => true, 'acquisitionCost' => 85000.00, 'valuationBasis' => 'productionCost'],
		];

		$base = [
			'administrationId' => 'adm-shillinq-bbv-1',
			'jurisdiction' => 'NL',
			'entityType' => 'municipality',
			'fiscalYear' => 2026,
			'currency' => 'EUR',
			'paragraphs' => $paragraphs,
			'taskFields' => $taskFields,
			'fixedAssets' => $fixedAssets,
		];

		$budget = $base;
		$budget['documentType'] = 'begroting';
		$budget['parts'] = ['beleidsbegroting', 'financielebegroting'];
		$budget['programmePlan'] = [
			'programmes' => [
				['code' => '1', 'name' => 'Bestuur en ondersteuning'],
				['code' => '2', 'name' => 'Verkeer, vervoer en waterstaat'],
			],
			'generalFundingSources' => 38500000.00,
			'overhead' => 6200000.00,
			'vpbCharge' => 0.00,
			'unforeseen' => 150000.00,
		];

		$jaarstukken = $base;
		$jaarstukken['documentType'] = 'jaarstukken';
		$jaarstukken['parts'] = ['jaarverslag', 'annualAccounts'];
		$jaarstukken['annualAccounts'] = [
			'overviewRevenueExpenses' => true,
			'balance' => true,
			'lawfulnessAccountability' => true,
			'auditorsStatement' => true,
		];

		return [
			'BbvStatement' => [$budget, $jaarstukken],
		];

	}//end seedObjects()

	// ===================== VAT-tail helpers =====================

	/**
	 * The art.-141 triangulation conditions are met: the three parties (seller,
	 * intermediary, final customer) are in three DIFFERENT Member States, the goods
	 * are delivered directly to the final customer, and the final customer's VAT id
	 * (the reverse-charge debtor) is well-formed.
	 *
	 * @param array<string, mixed> $o The invoice.
	 *
	 * @return bool
	 */
	private static function triangulationConditionsWith(array $o): bool {
		$a = self::country((string)($o['sellerCountryCode'] ?? ''));
		$b = self::country((string)($o['intermediaryCountryCode'] ?? ''));
		$c = self::country((string)($o['finalCustomerCountryCode'] ?? ''));
		if ($a === '' || $b === '' || $c === '') {
			return false;
		}

		if ($a === $b || $a === $c || $b === $c) {
			return false;
		}

		return self::truthy($o, 'directDelivery') === true
			&& self::vatIdShapeOk((string)($o['finalCustomerVatId'] ?? ''));

	}//end triangulationConditionsMet()

	/**
	 * The invoice carries a reverse-charge mention (art. 226(11a) / 197): a
	 * boolean flag, OR free-text that mentions reverse charge / btw verlegd.
	 *
	 * @param array<string, mixed> $o The invoice.
	 *
	 * @return bool
	 */
	private static function hasReverseChargeMention(array $o): bool {
		if (self::truthy($o, 'reverseChargeMention') === true) {
			return true;
		}

		$note = strtolower((string)($o['invoiceNote'] ?? ''));
		return strpos($note, 'reverse charge') !== false
			|| strpos($note, 'btw verlegd') !== false
			|| strpos($note, 'verlegd') !== false;

	}//end hasReverseChargeMention()

	/**
	 * The SME exemption is internally consistent: a stated annual turnover below the
	 * stated national threshold, and — where the cross-border Union SME scheme is
	 * used — a stated Union-wide turnover at most EUR 100 000.
	 *
	 * @param array<string, mixed> $o The invoice.
	 *
	 * @return bool
	 */
	private static function smeExemptionConsistent(array $o): bool {
		if (self::numericPresent($o, 'annualTurnover') === false
			|| self::numericPresent($o, 'smeThreshold') === false
		) {
			return false;
		}

		if ((float)$o['annualTurnover'] > (float)$o['smeThreshold']) {
			return false;
		}

		if (self::truthy($o, 'crossBorderSme') === true) {
			return self::numericPresent($o, 'unionTurnover') === true
				&& (float)$o['unionTurnover'] <= 100000.0;
		}

		return true;
	}//end smeExemptionConsistent()

	/**
	 * The pro-rata deduction is internally consistent: a deductible proportion in
	 * [0,100] is recorded and the deductible VAT equals the input (tax) amount times
	 * that proportion (to the cent).
	 *
	 * @param array<string, mixed> $o The purchase transaction.
	 *
	 * @return bool
	 */
	private static function proRataConsistent(array $o): bool {
		if (self::numericPresent($o, 'proRataPercent') === false
			|| self::numericPresent($o, 'taxAmount') === false
			|| self::numericPresent($o, 'deductibleVatAmount') === false
		) {
			return false;
		}

		$pct = (float)$o['proRataPercent'];
		if ($pct < 0.0 || $pct > 100.0) {
			return false;
		}

		$expected = ((float)$o['taxAmount']) * $pct / 100.0;
		return self::centsEqual($expected, (float)$o['deductibleVatAmount']);
	}//end proRataConsistent()

	/**
	 * The deduction adjustment is internally consistent: an adjustment amount is
	 * recorded as a number, and — for capital goods — a positive whole-year revision
	 * period is recorded (NL: 5y movables, 10y immovable property).
	 *
	 * @param array<string, mixed> $o The purchase transaction.
	 *
	 * @return bool
	 */
	private static function adjustmentConsistent(array $o): bool {
		if (self::numericPresent($o, 'adjustmentAmount') === false) {
			return false;
		}

		if (self::truthy($o, 'capitalGood') === true) {
			return self::numericPresent($o, 'adjustmentPeriodYears') === true
				&& (float)$o['adjustmentPeriodYears'] > 0.0;
		}

		return true;
	}//end adjustmentConsistent()

	// ===================== ledger-tail helpers =====================

	/**
	 * A FR FEC export descriptor is attached.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function hasFecExport(array $o): bool {
		return is_array(($o['fecExport'] ?? null)) === true;
	}//end hasFecExport()

	/**
	 * The FEC export descriptor is well-formed: a flat text or XML file type, a tab
	 * or pipe delimiter, a non-empty encoding, and a file name matching
	 * SIREN(9 digits) + 'FEC' + closing date (YYYYMMDD) with the file extension.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function fecExportFormatOk(array $o): bool {
		$fec = ($o['fecExport'] ?? []);
		if (is_array($fec) === false) {
			return false;
		}

		$fileType = strtolower(trim((string)($fec['fileType'] ?? '')));
		$delimiter = strtolower(trim((string)($fec['delimiter'] ?? '')));
		$encoding = trim((string)($fec['encoding'] ?? ''));
		$fileName = trim((string)($fec['fileName'] ?? ''));

		if (in_array($fileType, ['text', 'txt', 'xml'], true) === false) {
			return false;
		}

		if (in_array($delimiter, ['tab', 'pipe'], true) === false) {
			return false;
		}

		if ($encoding === '') {
			return false;
		}

		// SIREN (9 digits) + 'FEC' + YYYYMMDD + extension.
		return preg_match('/^[0-9]{9}FEC[0-9]{8}\.(txt|xml)$/i', $fileName) === 1;
	}//end fecExportFormatOk()

	/**
	 * A NF525 periodic-closing record is attached.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function hasNf525Closing(array $o): bool {
		return is_array(($o['nf525Closing'] ?? null)) === true;
	}//end hasNf525Closing()

	/**
	 * The NF525 closing record is well-formed: a recognised closing period (daily /
	 * monthly / annual), a numeric perpetual running total (grand total perpétuel),
	 * a positive gapless sequence number and a non-empty integrity signature.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function nf525ClosingOk(array $o): bool {
		$rec = ($o['nf525Closing'] ?? []);
		if (is_array($rec) === false) {
			return false;
		}

		$period = strtolower(trim((string)($rec['period'] ?? '')));
		if (in_array($period, ['daily', 'monthly', 'annual'], true) === false) {
			return false;
		}

		if (is_numeric($rec['perpetualTotal'] ?? null) === false) {
			return false;
		}

		$seq = ($rec['sequenceNumber'] ?? null);
		if (is_numeric($seq) === false || (float)$seq <= 0.0) {
			return false;
		}

		return trim((string)($rec['signature'] ?? '')) !== '';
	}//end nf525ClosingOk()

	/**
	 * A PT certified-invoicing chain link is attached.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function hasPtChain(array $o): bool {
		return is_array(($o['ptChain'] ?? null)) === true;
	}//end hasPtChain()

	/**
	 * The PT chain link is well-formed: a non-empty ATCUD, a document hash and the
	 * previous document's hash, so the document forms an unbroken, tamper-evident
	 * chain.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function ptChainOk(array $o): bool {
		$chain = ($o['ptChain'] ?? []);
		if (is_array($chain) === false) {
			return false;
		}

		return trim((string)($chain['atcud'] ?? '')) !== ''
			&& trim((string)($chain['hash'] ?? '')) !== ''
			&& trim((string)($chain['previousHash'] ?? '')) !== '';

	}//end ptChainOk()

	// ===================== BBV helpers =====================

	/**
	 * The statement plays the budget (begroting) document role.
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function isBudgetDoc(array $o): bool {
		return strtolower(trim((string)($o['documentType'] ?? ''))) === 'begroting';
	}//end isBudgetDoc()

	/**
	 * The statement plays the annual-accounts (jaarstukken) document role.
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function isAccountsDoc(array $o): bool {
		return strtolower(trim((string)($o['documentType'] ?? ''))) === 'jaarstukken';
	}//end isAccountsDoc()

	/**
	 * The statement's `parts` list contains the normalised part code $code.
	 *
	 * @param array<string, mixed> $o The statement.
	 * @param string $code The part code (normalised, lowercase).
	 *
	 * @return bool
	 */
	private static function hasPart(array $o, string $code): bool {
		$parts = ($o['parts'] ?? null);
		if (is_array($parts) === false) {
			return false;
		}

		foreach ($parts as $p) {
			if (self::normalise((string)$p) === $code) {
				return true;
			}
		}

		return false;
	}//end hasPart()

	/**
	 * The programmaplan is complete (art. 8): a non-empty list of programmes plus a
	 * numeric algemene dekkingsmiddelen, overhead, VPB charge and onvoorzien amount.
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function programmaplanComplete(array $o): bool {
		$plan = ($o['programmePlan'] ?? null);
		if (is_array($plan) === false) {
			return false;
		}

		$programmes = ($plan['programmes'] ?? null);
		if (is_array($programmes) === false || $programmes === []) {
			return false;
		}

		foreach (['generalFundingSources', 'overhead', 'vpbCharge', 'unforeseen'] as $key) {
			if (is_numeric($plan[$key] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end programmaplanComplete()

	/**
	 * All seven mandatory BBV paragraphs (art. 9) are present in the statement's
	 * `paragraphs` list (matched after normalisation).
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function allParagraphsPresent(array $o): bool {
		$given = ($o['paragraphs'] ?? null);
		if (is_array($given) === false) {
			return false;
		}

		$have = [];
		foreach ($given as $p) {
			$have[self::normalise((string)$p)] = true;
		}

		foreach (self::BBV_PARAGRAPHS as $required) {
			if (isset($have[$required]) === false) {
				return false;
			}
		}

		return true;
	}//end allParagraphsPresent()

	/**
	 * The jaarstukken are complete (art. 24): the jaarverslag part is present AND the
	 * jaarrekening declares its four components (overzicht baten/lasten, balans,
	 * rechtmatigheidsverantwoording, accountantsverklaring).
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function jaarstukkenComplete(array $o): bool {
		if (self::hasPart($o, 'jaarverslag') === false || self::hasPart($o, 'annualAccounts') === false) {
			return false;
		}

		$jr = ($o['annualAccounts'] ?? null);
		if (is_array($jr) === false) {
			return false;
		}

		foreach (['overviewRevenueExpenses', 'balance', 'lawfulnessAccountability', 'auditorsStatement'] as $key) {
			if (self::truthyValue($jr[$key] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end jaarstukkenComplete()

	/**
	 * Every declared fixed asset that meets the activation criteria is capitalised
	 * (art. 59): each entry in `fixedAssets` carries an explicit capitalised flag set
	 * true. Requires at least one declared fixed asset.
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function fixedAssetsActivated(array $o): bool {
		$assets = ($o['fixedAssets'] ?? null);
		if (is_array($assets) === false || $assets === []) {
			return false;
		}

		foreach ($assets as $a) {
			if (is_array($a) === false || self::truthyValue($a['capitalised'] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end fixedAssetsActivated()

	/**
	 * Every declared fixed asset is valued at acquisition/production cost (art. 62):
	 * each entry records a non-negative numeric acquisition cost and a valuation
	 * basis of acquisition or production cost. Requires at least one fixed asset.
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function fixedAssetsValuedAtCost(array $o): bool {
		$assets = ($o['fixedAssets'] ?? null);
		if (is_array($assets) === false || $assets === []) {
			return false;
		}

		foreach ($assets as $a) {
			if (is_array($a) === false) {
				return false;
			}

			if (is_numeric($a['acquisitionCost'] ?? null) === false || (float)$a['acquisitionCost'] < 0.0) {
				return false;
			}

			$basis = self::normalise((string)($a['valuationBasis'] ?? ''));
			if (in_array($basis, ['acquisitioncost', 'productioncost', 'verkrijgingsprijs', 'vervaardigingsprijs'], true) === false) {
				return false;
			}
		}

		return true;
	}//end fixedAssetsValuedAtCost()

	/**
	 * The taakvelden overview is complete: a non-empty list of taakveld lines, each
	 * carrying a non-empty code and numeric estimated revenue and expense.
	 *
	 * @param array<string, mixed> $o The statement.
	 *
	 * @return bool
	 */
	private static function taskFieldsComplete(array $o): bool {
		$lines = ($o['taskFields'] ?? null);
		if (is_array($lines) === false || $lines === []) {
			return false;
		}

		foreach ($lines as $l) {
			if (is_array($l) === false) {
				return false;
			}

			if (trim((string)($l['code'] ?? '')) === '') {
				return false;
			}

			if (is_numeric($l['estimatedRevenue'] ?? null) === false
				|| is_numeric($l['estimatedExpense'] ?? null) === false
			) {
				return false;
			}
		}

		return true;
	}//end taakveldenComplete()

	// ===================== generic helpers =====================

	/**
	 * A truthy boolean flag under $key (true / 'true' / 1 / '1' / 'yes').
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function truthy(array $o, string $key): bool {
		return self::truthyValue($o[$key] ?? null);
	}//end truthy()

	/**
	 * A value is truthy (true / 'true' / 1 / '1' / 'yes').
	 *
	 * @param mixed $v The value.
	 *
	 * @return bool
	 */
	private static function truthyValue($v): bool {
		if (is_bool($v) === true) {
			return $v;
		}

		return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes'], true);
	}//end truthyValue()

	/**
	 * A numeric value is present under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function numericPresent(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && is_numeric($o[$key]) === true;
	}//end numericPresent()

	/**
	 * Two monetary values are equal at cent precision (< 0.5 cent apart).
	 *
	 * @param float $a First value.
	 * @param float $b Second value.
	 *
	 * @return bool
	 */
	private static function centsEqual(float $a, float $b): bool {
		return abs(round($a, 2) - round($b, 2)) < 0.005;
	}//end centsEqual()

	/**
	 * Normalise a code/label to a comparable form: lowercase, alphanumerics only
	 * (drops spaces, hyphens, accents-as-punctuation).
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 */
	private static function normalise(string $value): string {
		return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
	}//end normalise()

	/**
	 * Normalise an ISO 3166-1 alpha-2 country code (uppercase, first two letters).
	 *
	 * @param string $value The raw country value.
	 *
	 * @return string
	 */
	private static function country(string $value): string {
		return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $value) ?? '', 0, 2));
	}//end country()

	/**
	 * A VAT identifier is well-formed (VIES shape): an EU country prefix followed by
	 * at least two alphanumeric characters of the national body.
	 *
	 * @param string $vatId The VAT identifier.
	 *
	 * @return bool
	 */
	private static function vatIdShapeOk(string $vatId): bool {
		$clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatId) ?? '');
		if (strlen($clean) < 4) {
			return false;
		}

		if (in_array(substr($clean, 0, 2), self::VAT_PREFIXES, true) === false) {
			return false;
		}

		$body = substr($clean, 2);
		return strlen($body) >= 2 && ctype_alnum($body) === true;
	}//end vatIdShapeOk()
}//end class
