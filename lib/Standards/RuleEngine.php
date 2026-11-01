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
	 * The order in which rules are evaluated and reported, per object type.
	 * Rule ids it does not list follow in registration order.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const RULE_ORDER = [
		'ARInvoice' => [
			'en16931-br-02',
			'en16931-br-03',
			'en16931-br-05',
			'en16931-br-13',
			'en16931-br-14',
			'en16931-br-co-15',
			'gl-sequential-invoice-numbering',
			'vatdir-art226-5',
			'en16931-br-07',
			'vatdir-art226-10',
			'en16931-br-cl-03',
			'en16931-br-dec-12',
			'en16931-br-dec-13',
			'en16931-br-dec-14',
			'en16931-br-16',
			'en16931-br-21',
			'en16931-br-22',
			'en16931-br-23',
			'en16931-br-24',
			'en16931-br-25',
			'en16931-br-26',
			'en16931-br-co-04',
			'en16931-br-dec-23',
			'en16931-br-12',
			'en16931-br-dec-09',
			'en16931-br-co-10',
			'en16931-br-co-13',
			'en16931-br-co-18',
			'en16931-br-45',
			'en16931-br-46',
			'en16931-br-47',
			'en16931-br-48',
			'en16931-br-dec-19',
			'en16931-br-dec-20',
			'en16931-br-co-14',
			'en16931-br-co-17',
			'vatdir-art226-8',
			'vatdir-art226-9',
			'en16931-br-s-08',
			'en16931-br-s-09',
			'en16931-br-z-08',
			'en16931-br-z-09',
			'en16931-br-e-08',
			'en16931-br-e-09',
			'en16931-br-ae-08',
			'en16931-br-ae-09',
			'en16931-br-o-08',
			'en16931-br-o-09',
			'en16931-br-g-08',
			'en16931-br-g-09',
			'en16931-br-ic-08',
			'en16931-br-ic-09',
			'en16931-br-s-05',
			'en16931-br-z-05',
			'en16931-br-e-05',
			'en16931-br-ae-05',
			'en16931-br-g-05',
			'en16931-br-ic-05',
			'en16931-br-o-05',
			'en16931-br-s-10',
			'en16931-br-z-10',
			'en16931-br-e-10',
			'en16931-br-ae-10',
			'en16931-br-o-10',
			'en16931-br-g-10',
			'en16931-br-ic-10',
			'en16931-br-31',
			'en16931-br-32',
			'en16931-br-33',
			'en16931-br-36',
			'en16931-br-37',
			'en16931-br-38',
			'en16931-br-41',
			'en16931-br-42',
			'en16931-br-43',
			'en16931-br-44',
			'en16931-br-co-11',
			'en16931-br-co-12',
			'en16931-br-dec-01',
			'en16931-br-dec-02',
			'en16931-br-dec-05',
			'en16931-br-dec-06',
			'en16931-br-dec-10',
			'en16931-br-dec-11',
			'en16931-br-dec-24',
			'en16931-br-dec-25',
			'en16931-br-dec-27',
			'en16931-br-dec-28',
			'en16931-br-01',
			'en16931-br-06',
			'en16931-br-08',
			'en16931-br-09',
			'en16931-br-10',
			'vatdir-art226-3',
			'en16931-br-co-26',
			'en16931-br-co-09',
			'en16931-br-co-03',
			'en16931-br-co-25',
			'en16931-br-s-02',
			'en16931-br-z-02',
			'en16931-br-e-02',
			'en16931-br-g-02',
			'en16931-br-ae-02',
			'en16931-br-ic-02',
			'en16931-br-o-02',
			'en16931-br-04',
			'vatdir-art226-1',
			'vatdir-art226-2',
			'vatdir-art226-7',
		],
		'APTransaction' => [
			'en16931-br-02',
			'en16931-br-03',
			'en16931-br-05',
			'en16931-br-cl-03',
			'en16931-br-14',
			'vatdir-art226-5',
			'vatdir-art226-10',
			'gl-source-document-traceability',
		],
		'GLTransaction' => [
			'gl-completeness-timeliness',
			'gl-sequential-journal-numbering',
			'gl-chronological-order',
			'ledger-double-entry-balance',
			'gl-source-document-traceability',
		],
	];

	/**
	 * The registered predicates, keyed by object type then rule id. Each
	 * predicate is `fn(array $object, array $context): bool` — true = the rule is
	 * satisfied. Every key is a real RuleCatalogue id. A provider predicate
	 * overrides the built-in one with the same rule id.
	 *
	 * @return array<string, array<string, callable>>
	 */
	private static function checks(): array {
		$builtin = [
			'ARInvoice'     => array_merge(self::invoiceChecks(), self::allowanceChargeChecks()),
			'APTransaction' => self::purchaseInvoiceChecks(),
			'GLTransaction' => self::journalChecks(),
		];

		return self::inRuleOrder(self::withProviderChecks($builtin));
	}//end checks()

	/**
	 * Merge per-domain providers (lib/Standards/Checks/*.php) into the built-in
	 * registry. Each provider contributes [objectType => [ruleId => predicate]];
	 * a provider predicate replaces a built-in one with the same rule id, so the
	 * corpus can grow an executable check per domain without editing this file.
	 *
	 * @param array<string, array<string, callable>> $checks The built-in registry.
	 *
	 * @return array<string, array<string, callable>>
	 */
	private static function withProviderChecks(array $checks): array {
		foreach (self::providers() as $provider) {
			foreach ($provider::checks() as $objectType => $ruleChecks) {
				$checks[$objectType] = array_merge(($checks[$objectType] ?? []), $ruleChecks);
			}
		}

		return $checks;
	}//end withProviderChecks()

	/**
	 * Put each object type's rules in RULE_ORDER; rule ids it does not list
	 * follow in registration order.
	 *
	 * @param array<string, array<string, callable>> $checks The merged registry.
	 *
	 * @return array<string, array<string, callable>>
	 */
	private static function inRuleOrder(array $checks): array {
		foreach (self::RULE_ORDER as $objectType => $ruleIds) {
			$listed = array_intersect_key(array_flip($ruleIds), ($checks[$objectType] ?? []));
			$checks[$objectType] = array_replace($listed, ($checks[$objectType] ?? []));
		}

		return $checks;
	}//end inRuleOrder()

	/**
	 * The sales-invoice rules no provider implements: BR-CO-15, sequential
	 * numbering and the supply date.
	 *
	 * @return array<string, callable>
	 */
	private static function invoiceChecks(): array {
		return [
			// BR-CO-15: total with VAT = total without VAT + total VAT (at cent precision).
			'en16931-br-co-15' => static fn (array $o): bool => self::centsEqual(
				(float)($o['grossAmount'] ?? 0),
				((float)($o['netAmount'] ?? 0) + (float)($o['vatAmount'] ?? 0))
			),
			// A gapless sequential invoice number — presence is enforceable per-object.
			'gl-sequential-invoice-numbering' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
			'vatdir-art226-7' => static fn (array $o): bool => self::present($o, 'supplyDate'),
		];

	}//end invoiceChecks()

	/**
	 * The EN 16931 document-level allowances (BG-20) and charges (BG-21), and the line-level ones.
	 *
	 * @return array<string, callable>
	 */
	private static function allowanceChargeChecks(): array {
		return [
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
		];

	}//end allowanceChargeChecks()

	/**
	 * Purchase (received) invoices: the same EN 16931 / VAT Directive content rules, mapped to the APTransaction header fields.
	 *
	 * @return array<string, callable>
	 */
	private static function purchaseInvoiceChecks(): array {
		return [
			'en16931-br-02' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
			'en16931-br-03' => static fn (array $o): bool => self::present($o, 'invoiceDate'),
			'en16931-br-05' => static fn (array $o): bool => self::present($o, 'currency'),
			'en16931-br-cl-03' => static fn (array $o): bool => self::isIsoCurrency((string)($o['currency'] ?? '')),
			'en16931-br-14' => static fn (array $o): bool => self::numericPresent($o, 'totalAmount'),
			'vatdir-art226-5' => static fn (array $o): bool => self::present($o, 'vendorId'),
			'vatdir-art226-10' => static fn (array $o): bool => self::numericPresent($o, 'taxAmount'),
			'gl-source-document-traceability' => static fn (array $o): bool => self::present($o, 'sourceDocumentUri'),
		];

	}//end purchaseInvoiceChecks()

	/**
	 * The numbering, dating and traceability rules of a journal entry.
	 *
	 * @return array<string, callable>
	 */
	private static function journalChecks(): array {
		return [
			// Completeness: a real double entry — at least two lines, each with an account and a non-zero, sided amount.
			'gl-completeness-timeliness' => static fn (array $o): bool => self::glComplete($o),
			'gl-sequential-journal-numbering' => static fn (array $o): bool => self::present($o, 'transactionNumber'),
			// Recorded with a posting date (chronological order / timeliness).
			'gl-chronological-order' => static fn (array $o): bool => self::present($o, 'postingDate'),
			// Each entry traceable to a source document (Belegfunktion / GoBD).
			'gl-source-document-traceability' => static fn (array $o): bool => self::present($o, 'sourceReference'),
		];

	}//end journalChecks()

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
