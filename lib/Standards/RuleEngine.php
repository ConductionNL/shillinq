<?php

/**
 * Rule Engine
 *
 * Evaluates a bookkeeping object against the machine-checkable rules that apply
 * to it, returning structured Violations. This is the executable layer over the
 * static RuleCatalogue: each registered check is a pure predicate over an object
 * (plus context), keyed by a real catalogue rule id, so a violation carries the
 * rule's severity and source straight from the corpus. Applicability is scoped by
 * jurisdiction (a rule applies to its own country, plus EU-wide rules for EU
 * members and `global` rules everywhere) so the engine only enforces what the
 * administration is actually subject to.
 *
 * Only rules with a registered predicate here are enforced today; the rest of the
 * ~1,300-rule corpus is catalogued and grows an executable check per wave. The
 * predicates are side-effect free and unit-tested; the lifecycle wiring + object
 * loading live in OCA\Shillinq\Lifecycle\RuleComplianceGuard.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Operators.ComparisonOperatorUsage, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards;

/**
 * Evaluates objects against applicable machine-checkable rules.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * Pre-existing debt (issue #506): this engine dispatches to every check
 * provider in the standards catalogue; its size/complexity scale with
 * the number of supported reporting frameworks. Deferred to a follow-up.
 */
final class RuleEngine {

	/**
	 * Memoised rule index (id => rule), built from RuleCatalogue.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $index = null;

	/**
	 * Memoised list of discovered per-domain CheckProvider class-strings.
	 *
	 * @var array<int, class-string>|null
	 */
	private static ?array $providers = null;

	/**
	 * The registered predicates, keyed by object type then rule id. Each
	 * predicate is `fn(array $object, array $context): bool` — true = the rule is
	 * satisfied. Every key is a real RuleCatalogue id.
	 *
	 * @return array<string, array<string, callable>>
	 */
	private static function checks(): array {
		$builtin = [
			'ARInvoice' => [
				// EN 16931 mandatory invoice fields (BT-1/2/5/109/112) mapped to the ARInvoice header.
				'en16931-br-02' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
				'en16931-br-03' => static fn (array $o): bool => self::present($o, 'invoiceDate'),
				'en16931-br-05' => static fn (array $o): bool => self::present($o, 'currency'),
				'en16931-br-13' => static fn (array $o): bool => self::numericPresent($o, 'netAmount'),
				'en16931-br-14' => static fn (array $o): bool => self::numericPresent($o, 'grossAmount'),
				// BR-CO-15: total with VAT = total without VAT + total VAT (at cent precision).
				'en16931-br-co-15' => static fn (array $o): bool => self::centsEqual(
					(float)($o['grossAmount'] ?? 0),
					((float)($o['netAmount'] ?? 0) + (float)($o['vatAmount'] ?? 0))
				),
				// A gapless sequential invoice number — presence is enforceable per-object.
				'gl-sequential-invoice-numbering' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
				// Buyer / customer must be identified (VAT Directive art. 226(5) / EN 16931 BT-44).
				'vatdir-art226-5' => static fn (array $o): bool => self::present($o, 'customerId'),
				'en16931-br-07' => static fn (array $o): bool => self::present($o, 'customerId'),
				// The VAT amount payable must be stated (VAT Directive art. 226(10)).
				'vatdir-art226-10' => static fn (array $o): bool => self::numericPresent($o, 'vatAmount'),
				// Currency must be a valid ISO 4217 code (EN 16931 BR-CL-03).
				'en16931-br-cl-03' => static fn (array $o): bool => self::isIsoCurrency((string)($o['currency'] ?? '')),
				// Totals must not exceed 2 decimals (EN 16931 BR-DEC-12/13/14).
				'en16931-br-dec-12' => static fn (array $o): bool => self::maxDecimals($o['netAmount'] ?? null, 2),
				'en16931-br-dec-13' => static fn (array $o): bool => self::maxDecimals($o['vatAmount'] ?? null, 2),
				'en16931-br-dec-14' => static fn (array $o): bool => self::maxDecimals($o['grossAmount'] ?? null, 2),

				// --- EN 16931 invoice-line group (BG-25), enforced against the modeled `invoiceLines[]`. ---
				'en16931-br-16' => static fn (array $o): bool => self::lines($o) !== [],
				'en16931-br-21' => static fn (array $o): bool => self::allLinesPresent($o, 'lineId'),
				'en16931-br-22' => static fn (array $o): bool => self::allLinesNumeric($o, 'quantity'),
				'en16931-br-23' => static fn (array $o): bool => self::allLinesPresent($o, 'unitCode'),
				'en16931-br-24' => static fn (array $o): bool => self::allLinesNumeric($o, 'netAmount'),
				'en16931-br-25' => static fn (array $o): bool => self::allLinesPresent($o, 'itemName'),
				'en16931-br-26' => static fn (array $o): bool => self::allLinesNumeric($o, 'netPrice'),
				'en16931-br-co-04' => static fn (array $o): bool => self::allLinesVatCategorized($o),
				'en16931-br-dec-23' => static fn (array $o): bool => self::allLinesMaxDecimals($o, 'netAmount', 2),

				// --- Document totals derived from the lines (BT-106 / BT-109). ---
				'en16931-br-12' => static fn (array $o): bool => self::numericPresent($o, 'lineNetTotal'),
				'en16931-br-dec-09' => static fn (array $o): bool => self::maxDecimals($o['lineNetTotal'] ?? null, 2),
				'en16931-br-co-10' => static fn (array $o): bool => self::centsEqual(
					(float)($o['lineNetTotal'] ?? 0),
					self::sumLineNet($o)
				),
				'en16931-br-co-13' => static fn (array $o): bool => self::centsEqual(
					(float)($o['netAmount'] ?? 0),
					(float)($o['lineNetTotal'] ?? 0)
				),

				// --- EN 16931 VAT breakdown group (BG-23), enforced against the modeled `vatBreakdown[]`. ---
				'en16931-br-co-18' => static fn (array $o): bool => self::vatBreakdown($o) !== [],
				'en16931-br-45' => static fn (array $o): bool => self::allBreakdownNumeric($o, 'taxableAmount'),
				'en16931-br-46' => static fn (array $o): bool => self::allBreakdownNumeric($o, 'taxAmount'),
				'en16931-br-47' => static fn (array $o): bool => self::allBreakdownPresent($o, 'category'),
				'en16931-br-48' => static fn (array $o): bool => self::allBreakdownRated($o),
				'en16931-br-dec-19' => static fn (array $o): bool => self::allBreakdownMaxDecimals($o, 'taxableAmount', 2),
				'en16931-br-dec-20' => static fn (array $o): bool => self::allBreakdownMaxDecimals($o, 'taxAmount', 2),
				'en16931-br-co-14' => static fn (array $o): bool => self::centsEqual(
					(float)($o['vatAmount'] ?? 0),
					self::sumBreakdownTax($o)
				),
				'en16931-br-co-17' => static fn (array $o): bool => self::breakdownTaxConsistent($o),

				// VAT Directive art. 226(8)/(9): taxable amount per rate + the rate applied.
				'vatdir-art226-8' => static fn (array $o): bool => self::allBreakdownNumeric($o, 'taxableAmount'),
				'vatdir-art226-9' => static fn (array $o): bool => self::allBreakdownRated($o),

				// --- EN 16931 per-VAT-category breakdown consistency (BG-23 keyed by BT-118). ---
				// *-08: category taxable amount = Σ line net of that category. *-09: tax amount
				// is taxable × rate for Standard-rated and zero for the exempt/zero-rated families.
				'en16931-br-s-08' => static fn (array $o): bool => self::categoryTaxableOk($o, 'S'),
				'en16931-br-s-09' => static fn (array $o): bool => self::categoryTaxOk($o, 'S', false),
				'en16931-br-z-08' => static fn (array $o): bool => self::categoryTaxableOk($o, 'Z'),
				'en16931-br-z-09' => static fn (array $o): bool => self::categoryTaxOk($o, 'Z', true),
				'en16931-br-e-08' => static fn (array $o): bool => self::categoryTaxableOk($o, 'E'),
				'en16931-br-e-09' => static fn (array $o): bool => self::categoryTaxOk($o, 'E', true),
				'en16931-br-ae-08' => static fn (array $o): bool => self::categoryTaxableOk($o, 'AE'),
				'en16931-br-ae-09' => static fn (array $o): bool => self::categoryTaxOk($o, 'AE', true),
				'en16931-br-o-08' => static fn (array $o): bool => self::categoryTaxableOk($o, 'O'),
				'en16931-br-o-09' => static fn (array $o): bool => self::categoryTaxOk($o, 'O', true),
				'en16931-br-g-08' => static fn (array $o): bool => self::categoryTaxableOk($o, 'G'),
				'en16931-br-g-09' => static fn (array $o): bool => self::categoryTaxOk($o, 'G', true),
				'en16931-br-ic-08' => static fn (array $o): bool => self::categoryTaxableOk($o, 'K'),
				'en16931-br-ic-09' => static fn (array $o): bool => self::categoryTaxOk($o, 'K', true),

				// --- EN 16931 invoiced-item VAT rate per line category (BT-152 by BT-151, BR-*-05). ---
				// Standard rated must be > 0; the zero/exempt/reverse/export/intra families must be 0;
				// 'Not subject to VAT' must carry no rate at all.
				'en16931-br-s-05' => static fn (array $o): bool => self::lineRateByCategory($o, 'S', 'positive'),
				'en16931-br-z-05' => static fn (array $o): bool => self::lineRateByCategory($o, 'Z', 'zero'),
				'en16931-br-e-05' => static fn (array $o): bool => self::lineRateByCategory($o, 'E', 'zero'),
				'en16931-br-ae-05' => static fn (array $o): bool => self::lineRateByCategory($o, 'AE', 'zero'),
				'en16931-br-g-05' => static fn (array $o): bool => self::lineRateByCategory($o, 'G', 'zero'),
				'en16931-br-ic-05' => static fn (array $o): bool => self::lineRateByCategory($o, 'K', 'zero'),
				'en16931-br-o-05' => static fn (array $o): bool => self::lineRateByCategory($o, 'O', 'absent'),

				// --- EN 16931 VAT exemption reason per breakdown category (BT-120/121 by BT-118, BR-*-10). ---
				// Standard/Zero rated must NOT carry an exemption reason; the exempt/reverse/not-subject/
				// export/intra-community families MUST carry one.
				'en16931-br-s-10' => static fn (array $o): bool => self::breakdownExemptionByCategory($o, 'S', false),
				'en16931-br-z-10' => static fn (array $o): bool => self::breakdownExemptionByCategory($o, 'Z', false),
				'en16931-br-e-10' => static fn (array $o): bool => self::breakdownExemptionByCategory($o, 'E', true),
				'en16931-br-ae-10' => static fn (array $o): bool => self::breakdownExemptionByCategory($o, 'AE', true),
				'en16931-br-o-10' => static fn (array $o): bool => self::breakdownExemptionByCategory($o, 'O', true),
				'en16931-br-g-10' => static fn (array $o): bool => self::breakdownExemptionByCategory($o, 'G', true),
				'en16931-br-ic-10' => static fn (array $o): bool => self::breakdownExemptionByCategory($o, 'K', true),

				// --- EN 16931 document-level allowances (BG-20) and charges (BG-21). ---
				// BR-31/32/33: each allowance has amount, VAT category, and a reason or reason code.
				'en16931-br-31' => static fn (array $o): bool => self::allItemsNumeric($o, 'allowances', 'amount'),
				'en16931-br-32' => static fn (array $o): bool => self::allItemsPresent($o, 'allowances', 'vatCategory'),
				'en16931-br-33' => static fn (array $o): bool => self::allItemsHaveReason($o, 'allowances'),
				// BR-36/37/38: each charge has amount, VAT category, and a reason or reason code.
				'en16931-br-36' => static fn (array $o): bool => self::allItemsNumeric($o, 'charges', 'amount'),
				'en16931-br-37' => static fn (array $o): bool => self::allItemsPresent($o, 'charges', 'vatCategory'),
				'en16931-br-38' => static fn (array $o): bool => self::allItemsHaveReason($o, 'charges'),
				// BR-41/42/43/44: each invoice-line allowance/charge has an amount and a reason.
				'en16931-br-41' => static fn (array $o): bool => self::allLineItemsNumeric($o, 'lineAllowances', 'amount'),
				'en16931-br-42' => static fn (array $o): bool => self::allLineItemsHaveReason($o, 'lineAllowances'),
				'en16931-br-43' => static fn (array $o): bool => self::allLineItemsNumeric($o, 'lineCharges', 'amount'),
				'en16931-br-44' => static fn (array $o): bool => self::allLineItemsHaveReason($o, 'lineCharges'),
				// BR-CO-11/12: document allowance/charge totals reconcile to the sum of the lines.
				'en16931-br-co-11' => static fn (array $o): bool => self::centsEqual(
					(float)($o['allowancesTotal'] ?? 0),
					self::sumItems($o, 'allowances')
				),
				'en16931-br-co-12' => static fn (array $o): bool => self::centsEqual(
					(float)($o['chargesTotal'] ?? 0),
					self::sumItems($o, 'charges')
				),
				// BR-DEC-01/02/05/06/10/11/24/25/27/28: allowance/charge amounts respect 2 decimals.
				'en16931-br-dec-01' => static fn (array $o): bool => self::allItemsMaxDecimals($o, 'allowances', 'amount', 2),
				'en16931-br-dec-02' => static fn (array $o): bool => self::allItemsMaxDecimals($o, 'allowances', 'baseAmount', 2),
				'en16931-br-dec-05' => static fn (array $o): bool => self::allItemsMaxDecimals($o, 'charges', 'amount', 2),
				'en16931-br-dec-06' => static fn (array $o): bool => self::allItemsMaxDecimals($o, 'charges', 'baseAmount', 2),
				'en16931-br-dec-10' => static fn (array $o): bool => self::maxDecimals($o['allowancesTotal'] ?? null, 2),
				'en16931-br-dec-11' => static fn (array $o): bool => self::maxDecimals($o['chargesTotal'] ?? null, 2),
				'en16931-br-dec-24' => static fn (array $o): bool => self::allLineItemsMaxDecimals($o, 'lineAllowances', 'amount', 2),
				'en16931-br-dec-25' => static fn (array $o): bool => self::allLineItemsMaxDecimals($o, 'lineAllowances', 'baseAmount', 2),
				'en16931-br-dec-27' => static fn (array $o): bool => self::allLineItemsMaxDecimals($o, 'lineCharges', 'amount', 2),
				'en16931-br-dec-28' => static fn (array $o): bool => self::allLineItemsMaxDecimals($o, 'lineCharges', 'baseAmount', 2),

				// --- EN 16931 seller/buyer party identification (BG-4/BG-7) + payment/tax-point. ---
				'en16931-br-01' => static fn (array $o): bool => self::present($o, 'specificationId'),
				'en16931-br-06' => static fn (array $o): bool => self::present($o, 'sellerName'),
				'en16931-br-08' => static fn (array $o): bool => self::present($o, 'sellerAddress'),
				'en16931-br-09' => static fn (array $o): bool => self::present($o, 'sellerCountryCode'),
				'en16931-br-10' => static fn (array $o): bool => self::present($o, 'buyerAddress'),
				'vatdir-art226-3' => static fn (array $o): bool => self::present($o, 'sellerVatId'),
				// BR-CO-26: a supplier must be identifiable by id, legal-reg or VAT id.
				'en16931-br-co-26' => static fn (array $o): bool => (self::present($o, 'sellerIdentifier')
					|| self::present($o, 'sellerTaxRegId') || self::present($o, 'sellerVatId')),
				// BR-CO-09: every present VAT identifier carries an ISO 3166-1 alpha-2 prefix.
				'en16931-br-co-09' => static fn (array $o): bool => self::vatIdsHaveCountryPrefix($o),
				// BR-CO-03: tax point date and tax point date code are mutually exclusive.
				'en16931-br-co-03' => static fn (array $o): bool => (self::present($o, 'taxPointDate') === false
					|| self::present($o, 'taxPointDateCode') === false),
				// BR-CO-25: a positive amount due requires a payment due date or payment terms.
				'en16931-br-co-25' => static fn (array $o): bool => (((float)($o['grossAmount'] ?? 0)) <= 0
					|| self::present($o, 'dueDate') || self::present($o, 'paymentTerms')),
				// BR-{S,Z,E}-02: a line of this category requires a seller VAT/tax identifier.
				'en16931-br-s-02' => static fn (array $o): bool => self::sellerVatRequiredFor($o, 'S'),
				'en16931-br-z-02' => static fn (array $o): bool => self::sellerVatRequiredFor($o, 'Z'),
				'en16931-br-e-02' => static fn (array $o): bool => self::sellerVatRequiredFor($o, 'E'),
				// BR-G-02: export line requires the seller VAT id or tax representative VAT id.
				'en16931-br-g-02' => static fn (array $o): bool => (self::anyLineHasCategory($o, 'G') === false
					|| self::present($o, 'sellerVatId') || self::present($o, 'sellerTaxRepVatId')),
				// BR-AE-02: reverse-charge line requires seller AND buyer identification.
				'en16931-br-ae-02' => static fn (array $o): bool => (self::anyLineHasCategory($o, 'AE') === false
					|| ((self::present($o, 'sellerVatId') || self::present($o, 'sellerTaxRegId') || self::present($o, 'sellerTaxRepVatId'))
					&& (self::present($o, 'buyerVatId') || self::present($o, 'buyerLegalRegId')))),
				// BR-IC-02: intra-community line requires seller VAT id (or tax rep) AND buyer VAT id.
				'en16931-br-ic-02' => static fn (array $o): bool => (self::anyLineHasCategory($o, 'K') === false
					|| ((self::present($o, 'sellerVatId') || self::present($o, 'sellerTaxRepVatId')) && self::present($o, 'buyerVatId'))),
				// BR-O-02: a 'Not subject to VAT' line forbids seller/buyer VAT identifiers.
				'en16931-br-o-02' => static fn (array $o): bool => (self::anyLineHasCategory($o, 'O') === false
					|| (self::present($o, 'sellerVatId') === false && self::present($o, 'sellerTaxRepVatId') === false
					&& self::present($o, 'buyerVatId') === false)),

				// --- EN 16931 / VAT Directive mandatory invoice-content fields. ---
				'en16931-br-04' => static fn (array $o): bool => self::present($o, 'invoiceTypeCode'),
				'vatdir-art226-1' => static fn (array $o): bool => self::present($o, 'invoiceDate'),
				'vatdir-art226-2' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
				'vatdir-art226-7' => static fn (array $o): bool => self::present($o, 'supplyDate'),
			],
			'APTransaction' => [
				// Purchase (received) invoices: the same EN 16931 / VAT Directive
				// content rules apply, mapped to the APTransaction header fields.
				'en16931-br-02' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
				'en16931-br-03' => static fn (array $o): bool => self::present($o, 'invoiceDate'),
				'en16931-br-05' => static fn (array $o): bool => self::present($o, 'currency'),
				'en16931-br-cl-03' => static fn (array $o): bool => self::isIsoCurrency((string)($o['currency'] ?? '')),
				'en16931-br-14' => static fn (array $o): bool => self::numericPresent($o, 'totalAmount'),
				'vatdir-art226-5' => static fn (array $o): bool => self::present($o, 'vendorId'),
				'vatdir-art226-10' => static fn (array $o): bool => self::numericPresent($o, 'taxAmount'),
				'gl-source-document-traceability' => static fn (array $o): bool => self::present($o, 'sourceDocumentUri'),
			],
			'GLTransaction' => [
				// Completeness: a real double entry — at least two lines, each with an account and a non-zero, sided amount.
				'gl-completeness-timeliness' => static fn (array $o): bool => self::glComplete($o),
				'gl-sequential-journal-numbering' => static fn (array $o): bool => self::present($o, 'transactionNumber'),
				// Recorded with a posting date (chronological order / timeliness).
				'gl-chronological-order' => static fn (array $o): bool => self::present($o, 'postingDate'),
				// Every journal entry must balance: total debits = total credits.
				'ledger-double-entry-balance' => static fn (array $o): bool => self::glBalanced($o),
				// Each entry traceable to a source document (Belegfunktion / GoBD).
				'gl-source-document-traceability' => static fn (array $o): bool => self::present($o, 'sourceReference'),
			],
		];

		// Merge per-domain providers (lib/Standards/Checks/*.php). Each provider
		// contributes [objectType => [ruleId => predicate]]; later providers add to
		// (never silently overwrite) the built-in registry, so the corpus can grow
		// an executable check per domain without editing this file.
		foreach (self::providers() as $provider) {
			foreach ($provider::checks() as $objectType => $ruleChecks) {
				$builtin[$objectType] = array_merge(($builtin[$objectType] ?? []), $ruleChecks);
			}
		}

		return $builtin;
	}//end checks()

	/**
	 * The merged test-data field defaults declared by all providers, keyed by
	 * object type then field name. Consumed by RuleTestDataSeeder.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function providerSeedSpecs(): array {
		$specs = [];
		foreach (self::providers() as $provider) {
			foreach ($provider::seedSpec() as $objectType => $fields) {
				$specs[$objectType] = array_merge(($specs[$objectType] ?? []), $fields);
			}
		}

		return $specs;
	}//end providerSeedSpecs()

	/**
	 * The sample objects to create for empty object types, declared by providers
	 * that implement the SeedsObjects capability. Keyed by object type.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function providerSeedObjects(): array {
		$objects = [];
		foreach (self::providers() as $provider) {
			if (in_array(\OCA\Shillinq\Standards\Checks\SeedsObjects::class, class_implements($provider), true) === false) {
				continue;
			}

			// @phpstan-ignore-next-line Narrowed to SeedsObjects via class_implements check above.
			$seedsProvider = $provider;
			foreach ($seedsProvider::seedObjects() as $objectType => $samples) {
				$objects[$objectType] = array_merge(($objects[$objectType] ?? []), $samples);
			}
		}

		return $objects;
	}//end providerSeedObjects()

	/**
	 * Discover the registered per-domain CheckProvider classes (memoised).
	 *
	 * @return array<int, class-string<\OCA\Shillinq\Standards\Checks\CheckProvider>>
	 */
	private static function providers(): array {
		if (self::$providers !== null) {
			return self::$providers;
		}

		$found = [];
		foreach ((glob(__DIR__ . '/Checks/*.php') ?: []) as $file) {
			$class = '\\OCA\\Shillinq\\Standards\\Checks\\' . basename($file, '.php');
			if (class_exists($class) === true
				&& in_array(\OCA\Shillinq\Standards\Checks\CheckProvider::class, class_implements($class), true) === true
			) {
				$found[] = $class;
			}
		}

		self::$providers = $found;
		return $found;
	}//end providers()

	/**
	 * Evaluate an object of $objectType against its applicable machine-checkable
	 * rules, returning the Violations (empty when compliant).
	 *
	 * @param string $objectType OpenRegister schema name (e.g. `ARInvoice`).
	 * @param array<string, mixed> $object The object (GL transactions also carry `lines`).
	 * @param array<string, mixed> $context `{ jurisdiction?: string }` — defaults to NL.
	 *
	 * @return array<int, Violation>
	 */
	public static function evaluate(string $objectType, array $object, array $context = []): array {
		$rules = self::index();
		$violations = [];

		foreach ((self::checks()[$objectType] ?? []) as $ruleId => $predicate) {
			$rule = ($rules[$ruleId] ?? null);
			if ($rule === null || self::applies($rule, $context) === false) {
				continue;
			}

			$satisfied = false;
			try {
				$satisfied = (bool)$predicate($object, $context);
			} catch (\Throwable $e) {
				$satisfied = false;
			}

			if ($satisfied === false) {
				$violations[] = self::violationFor($ruleId);
			}
		}

		return $violations;
	}//end evaluate()

	/**
	 * True when any violation is `mandatory` (i.e. a lifecycle guard must block).
	 *
	 * @param array<int, Violation> $violations Violations to inspect.
	 *
	 * @return bool
	 */
	public static function hasMandatory(array $violations): bool {
		foreach ($violations as $violation) {
			if ($violation->severity === 'mandatory') {
				return true;
			}
		}

		return false;
	}//end hasMandatory()

	/**
	 * Build a Violation for a rule id from the catalogue (severity/source/text).
	 *
	 * @param string $ruleId The catalogue rule id.
	 *
	 * @return Violation
	 */
	public static function violationFor(string $ruleId): Violation {
		$rule = (self::index()[$ruleId] ?? null);
		return new Violation(
			$ruleId,
			(string)($rule['severity'] ?? 'mandatory'),
			(string)($rule['source'] ?? $ruleId),
			(string)($rule['statement'] ?? '')
		);

	}//end violationFor()

	/**
	 * Object types that have at least one registered executable check.
	 *
	 * @return array<int, string>
	 */
	public static function supportedTypes(): array {
		return array_keys(self::checks());
	}//end supportedTypes()

	/**
	 * All catalogue rule ids that have a registered executable check (across all
	 * object types) — i.e. the rules the engine can actually enforce today.
	 *
	 * @return array<int, string>
	 */
	public static function checkedRuleIds(): array {
		$ids = [];
		foreach (self::checks() as $byRule) {
			foreach (array_keys($byRule) as $ruleId) {
				$ids[$ruleId] = true;
			}
		}

		return array_keys($ids);
	}//end checkedRuleIds()

	/**
	 * Reset the memoised index (test hook).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$index = null;
		self::$providers = null;

	}//end reset()

	/**
	 * Whether a rule applies to the context jurisdiction: its own country, plus
	 * EU-wide rules for EU members and `global` rules everywhere.
	 *
	 * @param array<string, mixed> $rule The catalogue rule.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private static function applies(array $rule, array $context): bool {
		$ruleJ = strtoupper((string)($rule['jurisdiction'] ?? ''));
		$code = strtoupper((string)($context['jurisdiction'] ?? 'NL'));

		if ($ruleJ === $code || $ruleJ === 'GLOBAL') {
			return true;
		}

		return $ruleJ === 'EU' && in_array($code, ComplianceCatalogue::EU_MEMBER_STATES, true);
	}//end applies()

	/**
	 * True when an object field holds a non-empty value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool True when the field is a non-empty value.
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * True when an object field holds a present, numeric value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool True when the field is a present, numeric value.
	 */
	private static function numericPresent(array $o, string $key): bool {
		return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;
	}//end numericPresent()

	/**
	 * Compare two amounts at cent precision.
	 *
	 * @param float $a Left amount.
	 * @param float $b Right amount.
	 *
	 * @return bool True when equal at cent precision (avoids float-equality issues).
	 */
	private static function centsEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end centsEqual()

	/**
	 * True when a string is an ISO 4217-shaped currency code.
	 *
	 * @param string $code A currency code.
	 *
	 * @return bool True when it is a 3-letter ISO 4217-shaped code.
	 */
	private static function isIsoCurrency(string $code): bool {
		return preg_match('/^[A-Z]{3}$/', $code) === 1;
	}//end isIsoCurrency()

	/**
	 * True when an amount has at most a given number of decimals.
	 *
	 * @param mixed $value Amount.
	 * @param int $max Maximum decimal places allowed.
	 *
	 * @return bool True when absent/non-numeric (presence enforced elsewhere) or
	 *              the value has at most $max decimals (float-tolerant).
	 */
	private static function maxDecimals(mixed $value, int $max): bool {
		if (is_numeric($value) === false) {
			return true;
		}

		$scaled = ((float)$value) * (10 ** $max);
		return abs($scaled - round($scaled)) < 1e-6;
	}//end maxDecimals()

	/**
	 * A GL transaction is "complete" when it has at least two lines and every
	 * line carries an account, a non-zero amount and a valid debit/credit side.
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function glComplete(array $o): bool {
		$lines = ($o['lines'] ?? []);
		if (is_array($lines) === false || count($lines) < 2) {
			return false;
		}

		foreach ($lines as $line) {
			if (is_array($line) === false) {
				return false;
			}

			$hasAccount = trim((string)($line['accountNumber'] ?? '')) !== '';
			$nonZero = ((int)round(((float)($line['amount'] ?? 0)) * 100)) !== 0;
			$sided = in_array(($line['side'] ?? ''), ['debit', 'credit'], true);
			if ($hasAccount === false || $nonZero === false || $sided === false) {
				return false;
			}
		}

		return true;
	}//end glComplete()

	/**
	 * A GL transaction is "balanced" when the sum of its debit-side line amounts
	 * equals the sum of its credit-side line amounts (to the cent). Mirrors the
	 * runtime BalanceGuard, expressed here as a corpus-sourced engine check so the
	 * audit attributes it to rule `ledger-double-entry-balance`. A transaction
	 * with no lines is treated as not-yet-balanced (fail-closed).
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function glBalanced(array $o): bool {
		$lines = ($o['lines'] ?? []);
		if (is_array($lines) === false || $lines === []) {
			return false;
		}

		$debit = 0;
		$credit = 0;
		foreach ($lines as $line) {
			if (is_array($line) === false) {
				return false;
			}

			$cents = (int)round(((float)($line['amount'] ?? 0)) * 100);
			if (($line['side'] ?? '') === 'debit') {
				$debit += $cents;
			} elseif (($line['side'] ?? '') === 'credit') {
				$credit += $cents;
			}
		}

		return $debit === $credit;
	}//end glBalanced()

	/**
	 * The invoice lines (EN 16931 BG-25), normalised to a list of arrays.
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
	 * The VAT breakdown groups (EN 16931 BG-23), normalised to a list of arrays.
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
	 * Every invoice line carries a non-empty value at $key (fail-closed on no lines).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 *
	 * @return bool
	 */
	private static function allLinesPresent(array $o, string $key): bool {
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		foreach ($lines as $line) {
			if (trim((string)($line[$key] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end allLinesPresent()

	/**
	 * Every invoice line carries a numeric value at $key (fail-closed on no lines).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 *
	 * @return bool
	 */
	private static function allLinesNumeric(array $o, string $key): bool {
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		foreach ($lines as $line) {
			if (is_numeric(($line[$key] ?? null)) === false) {
				return false;
			}
		}

		return true;
	}//end allLinesNumeric()

	/**
	 * Every invoice line value at $key respects the decimal limit.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 * @param int $max Maximum decimals.
	 *
	 * @return bool
	 */
	private static function allLinesMaxDecimals(array $o, string $key, int $max): bool {
		foreach (self::lines($o) as $line) {
			if (self::maxDecimals(($line[$key] ?? null), $max) === false) {
				return false;
			}
		}

		return true;
	}//end allLinesMaxDecimals()

	/**
	 * Every invoice line is categorised with a valid UNTDID 5305 VAT category
	 * code (EN 16931 BR-CO-04). Fail-closed on no lines.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function allLinesVatCategorized(array $o): bool {
		$allowed = ['S', 'Z', 'E', 'AE', 'K', 'G', 'O', 'L', 'M', 'B'];
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		foreach ($lines as $line) {
			if (in_array((string)($line['vatCategory'] ?? ''), $allowed, true) === false) {
				return false;
			}
		}

		return true;
	}//end allLinesVatCategorized()

	/**
	 * Σ of the invoice line net amounts (BT-131), to the cent.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return float
	 */
	private static function sumLineNet(array $o): float {
		$cents = 0;
		foreach (self::lines($o) as $line) {
			$cents += (int)round(((float)($line['netAmount'] ?? 0)) * 100);
		}

		return ($cents / 100);
	}//end sumLineNet()

	/**
	 * Every VAT breakdown carries a non-empty value at $key (fail-closed on none).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The breakdown field.
	 *
	 * @return bool
	 */
	private static function allBreakdownPresent(array $o, string $key): bool {
		$vb = self::vatBreakdown($o);
		if ($vb === []) {
			return false;
		}

		foreach ($vb as $group) {
			if (trim((string)($group[$key] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end allBreakdownPresent()

	/**
	 * Every VAT breakdown carries a numeric value at $key (fail-closed on none).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The breakdown field.
	 *
	 * @return bool
	 */
	private static function allBreakdownNumeric(array $o, string $key): bool {
		$vb = self::vatBreakdown($o);
		if ($vb === []) {
			return false;
		}

		foreach ($vb as $group) {
			if (is_numeric(($group[$key] ?? null)) === false) {
				return false;
			}
		}

		return true;
	}//end allBreakdownNumeric()

	/**
	 * Every VAT breakdown value at $key respects the decimal limit.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The breakdown field.
	 * @param int $max Maximum decimals.
	 *
	 * @return bool
	 */
	private static function allBreakdownMaxDecimals(array $o, string $key, int $max): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if (self::maxDecimals(($group[$key] ?? null), $max) === false) {
				return false;
			}
		}

		return true;
	}//end allBreakdownMaxDecimals()

	/**
	 * Every VAT breakdown has a numeric rate, except category 'O' (not subject to
	 * VAT) which must not carry one (EN 16931 BR-48). Fail-closed on no breakdown.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function allBreakdownRated(array $o): bool {
		$vb = self::vatBreakdown($o);
		if ($vb === []) {
			return false;
		}

		foreach ($vb as $group) {
			if ((string)($group['category'] ?? '') === 'O') {
				continue;
			}

			if (is_numeric(($group['rate'] ?? null)) === false) {
				return false;
			}
		}

		return true;
	}//end allBreakdownRated()

	/**
	 * Σ of the VAT category tax amounts (BT-117), to the cent.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return float
	 */
	private static function sumBreakdownTax(array $o): float {
		$cents = 0;
		foreach (self::vatBreakdown($o) as $group) {
			$cents += (int)round(((float)($group['taxAmount'] ?? 0)) * 100);
		}

		return ($cents / 100);
	}//end sumBreakdownTax()

	/**
	 * Each VAT breakdown's tax amount (BT-117) equals taxable amount (BT-116) ×
	 * rate / 100 (EN 16931 BR-CO-17), to the cent. Fail-closed on no breakdown.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function breakdownTaxConsistent(array $o): bool {
		$vb = self::vatBreakdown($o);
		if ($vb === []) {
			return false;
		}

		foreach ($vb as $group) {
			$expected = (((float)($group['taxableAmount'] ?? 0)) * (((float)($group['rate'] ?? 0)) / 100));
			if (self::centsEqual((float)($group['taxAmount'] ?? 0), $expected) === false) {
				return false;
			}
		}

		return true;
	}//end breakdownTaxConsistent()

	/**
	 * For every VAT breakdown of category $cat, its taxable amount (BT-116) equals
	 * the Σ of the line net amounts categorised with that code (EN 16931 BR-*-08).
	 * Vacuously true when no breakdown of that category is present.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function categoryTaxableOk(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			$lineCents = 0;
			foreach (self::lines($o) as $line) {
				if ((string)($line['vatCategory'] ?? '') === $cat) {
					$lineCents += (int)round(((float)($line['netAmount'] ?? 0)) * 100);
				}
			}

			if ((int)round(((float)($group['taxableAmount'] ?? 0)) * 100) !== $lineCents) {
				return false;
			}
		}

		return true;
	}//end categoryTaxableOk()

	/**
	 * For every VAT breakdown of category $cat, its tax amount (BT-117) is correct
	 * (EN 16931 BR-*-09): zero for the exempt / zero-rated / reverse-charge / not-
	 * subject / export / intra-community families, or taxable × rate for Standard
	 * rated. Vacuously true when no breakdown of that category is present.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 * @param bool $zero Whether the tax amount must be zero.
	 *
	 * @return bool
	 */
	private static function categoryTaxOk(array $o, string $cat, bool $zero): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			$taxCents = (int)round(((float)($group['taxAmount'] ?? 0)) * 100);
			if ($zero === true) {
				if ($taxCents !== 0) {
					return false;
				}

				continue;
			}

			$expected = (((float)($group['taxableAmount'] ?? 0)) * (((float)($group['rate'] ?? 0)) / 100));
			if (self::centsEqual((float)($group['taxAmount'] ?? 0), $expected) === false) {
				return false;
			}
		}

		return true;
	}//end categoryTaxOk()

	/**
	 * For every invoice line of category $cat, its VAT rate (BT-152) satisfies the
	 * mode: 'positive' (> 0), 'zero' (= 0), or 'absent' (no rate). Vacuously true
	 * when no line of that category exists (EN 16931 BR-*-05).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 * @param string $mode One of 'positive', 'zero', 'absent'.
	 *
	 * @return bool
	 */
	private static function lineRateByCategory(array $o, string $cat, string $mode): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			$hasRate = (($line['vatRate'] ?? null) !== null && ($line['vatRate'] ?? '') !== '');
			if ($mode === 'absent') {
				if ($hasRate === true) {
					return false;
				}

				continue;
			}

			if ($hasRate === false || is_numeric($line['vatRate']) === false) {
				return false;
			}

			$rateCents = (int)round(((float)$line['vatRate']) * 100);
			if ($mode === 'positive' && $rateCents <= 0) {
				return false;
			}

			if ($mode === 'zero' && $rateCents !== 0) {
				return false;
			}
		}//end foreach

		return true;
	}//end lineRateByCategory()

	/**
	 * For every VAT breakdown of category $cat, the presence of an exemption reason
	 * (BT-120 text or BT-121 code) matches $mustHave (EN 16931 BR-*-10). Vacuously
	 * true when no breakdown of that category exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 * @param bool $mustHave True if a reason is required, false if forbidden.
	 *
	 * @return bool
	 */
	private static function breakdownExemptionByCategory(array $o, string $cat, bool $mustHave): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			$hasReason = (trim((string)($group['exemptionReasonCode'] ?? '')) !== ''
				|| trim((string)($group['exemptionReasonText'] ?? '')) !== '');
			if ($hasReason !== $mustHave) {
				return false;
			}
		}

		return true;
	}//end breakdownExemptionByCategory()

	/**
	 * Normalise a named array-of-objects property to a list of arrays.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The property (e.g. 'allowances').
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

	/**
	 * Every item in the named collection carries a non-empty value at $field.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property.
	 * @param string $field Item field.
	 *
	 * @return bool
	 */
	private static function allItemsPresent(array $o, string $key, string $field): bool {
		foreach (self::items($o, $key) as $item) {
			if (trim((string)($item[$field] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end allItemsPresent()

	/**
	 * Every item in the named collection carries a numeric value at $field.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property.
	 * @param string $field Item field.
	 *
	 * @return bool
	 */
	private static function allItemsNumeric(array $o, string $key, string $field): bool {
		foreach (self::items($o, $key) as $item) {
			if (is_numeric(($item[$field] ?? null)) === false) {
				return false;
			}
		}

		return true;
	}//end allItemsNumeric()

	/**
	 * Every item in the named collection carries a reason text or reason code.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property.
	 *
	 * @return bool
	 */
	private static function allItemsHaveReason(array $o, string $key): bool {
		foreach (self::items($o, $key) as $item) {
			$hasReason = (trim((string)($item['reasonText'] ?? '')) !== ''
				|| trim((string)($item['reasonCode'] ?? '')) !== '');
			if ($hasReason === false) {
				return false;
			}
		}

		return true;
	}//end allItemsHaveReason()

	/**
	 * Every item in the named collection respects the decimal limit at $field.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property.
	 * @param string $field Item field.
	 * @param int $max Maximum decimals.
	 *
	 * @return bool
	 */
	private static function allItemsMaxDecimals(array $o, string $key, string $field, int $max): bool {
		foreach (self::items($o, $key) as $item) {
			if (self::maxDecimals(($item[$field] ?? null), $max) === false) {
				return false;
			}
		}

		return true;
	}//end allItemsMaxDecimals()

	/**
	 * Σ of a named collection's amount field, to the cent.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property.
	 *
	 * @return float
	 */
	private static function sumItems(array $o, string $key): float {
		$cents = 0;
		foreach (self::items($o, $key) as $item) {
			$cents += (int)round(((float)($item['amount'] ?? 0)) * 100);
		}

		return ($cents / 100);
	}//end sumItems()

	/**
	 * Every line-level allowance/charge across all invoice lines carries a numeric
	 * value at $field (EN 16931 BR-41/43).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Line collection ('lineAllowances'|'lineCharges').
	 * @param string $field Item field.
	 *
	 * @return bool
	 */
	private static function allLineItemsNumeric(array $o, string $key, string $field): bool {
		foreach (self::lines($o) as $line) {
			foreach (self::items($line, $key) as $item) {
				if (is_numeric(($item[$field] ?? null)) === false) {
					return false;
				}
			}
		}

		return true;
	}//end allLineItemsNumeric()

	/**
	 * Every line-level allowance/charge carries a reason text or code (BR-42/44).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Line collection ('lineAllowances'|'lineCharges').
	 *
	 * @return bool
	 */
	private static function allLineItemsHaveReason(array $o, string $key): bool {
		foreach (self::lines($o) as $line) {
			foreach (self::items($line, $key) as $item) {
				$hasReason = (trim((string)($item['reasonText'] ?? '')) !== ''
					|| trim((string)($item['reasonCode'] ?? '')) !== '');
				if ($hasReason === false) {
					return false;
				}
			}
		}

		return true;
	}//end allLineItemsHaveReason()

	/**
	 * Every line-level allowance/charge respects the decimal limit at $field
	 * (EN 16931 BR-DEC-24/25/27/28).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Line collection ('lineAllowances'|'lineCharges').
	 * @param string $field Item field.
	 * @param int $max Maximum decimals.
	 *
	 * @return bool
	 */
	private static function allLineItemsMaxDecimals(array $o, string $key, string $field, int $max): bool {
		foreach (self::lines($o) as $line) {
			foreach (self::items($line, $key) as $item) {
				if (self::maxDecimals(($item[$field] ?? null), $max) === false) {
					return false;
				}
			}
		}

		return true;
	}//end allLineItemsMaxDecimals()

	/**
	 * True when at least one invoice line carries the given VAT category code.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function anyLineHasCategory(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') === $cat) {
				return true;
			}
		}

		return false;
	}//end anyLineHasCategory()

	/**
	 * A line of category $cat (Standard/Zero/Exempt) requires the seller VAT
	 * identifier, tax registration identifier, or tax representative VAT identifier
	 * (EN 16931 BR-S/Z/E-02). Vacuously true when no such line exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function sellerVatRequiredFor(array $o, string $cat): bool {
		if (self::anyLineHasCategory($o, $cat) === false) {
			return true;
		}

		return (self::present($o, 'sellerVatId') === true
			|| self::present($o, 'sellerTaxRegId') === true
			|| self::present($o, 'sellerTaxRepVatId') === true);

	}//end sellerVatRequiredFor()

	/**
	 * Every present VAT identifier (seller, seller tax representative, buyer) has an
	 * ISO 3166-1 alpha-2 country prefix (EN 16931 BR-CO-09).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function vatIdsHaveCountryPrefix(array $o): bool {
		foreach (['sellerVatId', 'sellerTaxRepVatId', 'buyerVatId'] as $key) {
			$value = trim((string)($o[$key] ?? ''));
			if ($value !== '' && preg_match('/^[A-Z]{2}/', $value) !== 1) {
				return false;
			}
		}

		return true;
	}//end vatIdsHaveCountryPrefix()

	/**
	 * Build the id => rule index from RuleCatalogue (memoised).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function index(): array {
		if (self::$index !== null) {
			return self::$index;
		}

		$index = [];
		foreach (RuleCatalogue::all() as $rule) {
			$index[(string)$rule['id']] = $rule;
		}

		self::$index = $index;
		return $index;
	}//end index()
}//end class
