<?php

/**
 * National Financial-Statement Reporting Check Provider
 *
 * Contributes executable checks for the `national-reporting` rule domain to the
 * RuleEngine. The predicates map the statutory annual-accounts obligations of the
 * NL (BW 2 Titel 9), DE (HGB), FR (PCG / FEC / greffe) and EU (ESEF iXBRL)
 * frameworks onto a structured FinancialStatement object — statement composition,
 * balance-sheet and profit-and-loss layout, the gross/no-netting principle, size
 * classification against the statutory thresholds, the audit duty, the
 * preparation/filing deadlines and the listed-issuer ESEF tagging obligations.
 *
 * FinancialStatement has no existing rows in the local register, so this provider
 * also implements SeedsObjects and supplies one fully-compliant sample per
 * jurisdiction (NL / DE / FR / EU-listed); the seeder creates them so the audit
 * actually evaluates these checks. Narrative chart-of-accounts class rules
 * (RGS / SKR04 / PCG account classes), the SAF-T master-file group rules and the
 * "verify" judgemental rules are intentionally not registered here — they would
 * require a ledger-export artefact or human judgement shillinq does not model.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class rather than reaching into
 * RuleEngine). Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/national-reporting.json.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Commenting.DocCommentAlignment, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

use DateTimeImmutable;

/**
 * Executable national financial-statement reporting checks against FinancialStatement.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): this class enumerates every national
 * reporting rule the catalogue defines; inherent complexity. Variable
 * renames deferred pending a dedicated pass.
 */
class FinancialStatementsChecks implements CheckProvider, SeedsObjects {
	/**
	 * NL size thresholds (BW 2:395a/396/397): [balanceTotal, turnover, employees].
	 *
	 * @var array<string, array<int, float>>
	 */
	private const NL_THRESHOLDS = [
		'micro' => [450000.0, 900000.0, 10.0],
		'small' => [7500000.0, 15000000.0, 50.0],
		'medium' => [25000000.0, 50000000.0, 250.0],
	];

	/**
	 * DE size thresholds (HGB 267/267a, 2024): [balanceTotal, turnover, employees].
	 *
	 * @var array<string, array<int, float>>
	 */
	private const DE_THRESHOLDS = [
		'micro' => [450000.0, 900000.0, 10.0],
		'small' => [7500000.0, 15000000.0, 50.0],
		'medium' => [25000000.0, 50000000.0, 250.0],
	];

	/**
	 * Per-object-type, per-rule predicates for the `national-reporting` domain.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'FinancialStatement' => [
				// ===== NL — Burgerlijk Wetboek Boek 2, Titel 9. =====
				// art. 362(6): the accounts of an NL entity are drawn up in euros and
				// in Dutch (currency EUR, language Dutch), unless another
				// currency/language is justified. Applies to NL-jurisdiction
				// statements only — a foreign entity's accounts are out of its scope.
				'bw-art362-6-euro-language' => static fn (array $o): bool => self::jurisdictionNot($o, 'NL')
					|| (self::equalsField($o, 'currency', 'EUR') && self::langIs($o, 'nl')),

				// art. 363: items are not netted and each carries a prior-year
				// comparative (gross presentation + comparatives).
				'bw-art363-aggregation' => static fn (array $o): bool => self::isFalse($o, 'itemsNetted')
					&& self::isTrue($o, 'priorYearComparatives'),

				// art. 364: the balance sheet splits assets into fixed + current and
				// the credit side into equity, provisions and liabilities.
				'bw-art364-balance-layout' => static fn (array $o): bool => self::balanceLayoutComplete($o),

				// art. 377: the P&L is presented in a statutory model (categorical or
				// functional) showing net turnover, operating expenses, financial
				// result and the result.
				'bw-art377-pnl-layout' => static fn (array $o): bool => self::pnlLayoutComplete($o)
					&& self::inEnum($o, 'plPresentationModel', ['categorical', 'functional']),

				// art. 395a: a micro classification holds only when at most one of the
				// three micro thresholds is exceeded.
				'bw-art395a-micro' => static fn (array $o): bool => self::sizeClassConsistent($o, self::NL_THRESHOLDS, 'micro'),

				// art. 396: a small classification holds only when at most one small
				// threshold is exceeded.
				'bw-art396-small' => static fn (array $o): bool => self::sizeClassConsistent($o, self::NL_THRESHOLDS, 'small'),

				// art. 397: a medium classification holds only when at most one medium
				// threshold is exceeded.
				'bw-art397-medium' => static fn (array $o): bool => self::sizeClassConsistent($o, self::NL_THRESHOLDS, 'medium'),

				// art. 398: a different size regime may only be applied once the
				// criteria are met on two consecutive balance dates — so the declared
				// size class must equal the prior-year size class (stable regime), or
				// the prior year is unknown.
				'bw-art398-consistency-regime' => static fn (array $o): bool => self::regimeStable($o),

				// art. 394: the accounts are filed with the KvK within 12 months of
				// year-end (and the filing date is on/after the period end).
				'bw-art394-filing-kvk' => static fn (array $o): bool => self::filedWithin($o, 12),

				// art. 210: management prepares the accounts within (5 + up to 5 =) at
				// most 10 months of year-end.
				'bw-art210-prepare-deadline' => static fn (array $o): bool => self::preparedWithin($o, 10),

				// art. 393: entities that are not small (medium/large) must be audited
				// by a registered external auditor.
				'bw-art393-audit' => static fn (array $o): bool => self::auditDutyWith($o),

				// ===== DE — Handelsgesetzbuch. =====
				// §242 + §242(3): the Jahresabschluss comprises a balance sheet and a
				// profit-and-loss account (both component statements present).
				'hgb-242-financial-statements' => static fn (array $o): bool => self::hasComponent($o, 'balance')
					&& self::hasComponent($o, 'pnl'),

				// §244: a DE merchant's Jahresabschluss is prepared in German and in
				// euros. Applies to DE-jurisdiction statements only — a foreign
				// entity's accounts are out of its scope.
				'hgb-244-language-currency' => static fn (array $o): bool => self::jurisdictionNot($o, 'DE')
					|| (self::equalsField($o, 'currency', 'EUR') && self::langIs($o, 'de')),

				// §246: completeness + gross principle — items are not offset
				// (Saldierungsverbot).
				'hgb-246-completeness-gross' => static fn (array $o): bool => self::isFalse($o, 'itemsNetted'),

				// §266: the balance sheet is in account form (Kontoform) with the
				// prescribed asset / equity-and-liabilities structure.
				'hgb-266-balance-structure' => static fn (array $o): bool => self::equalsField($o, 'balanceForm', 'account')
					&& self::balanceLayoutComplete($o),

				// §275: the P&L is presented in vertical/staggered form (Staffelform).
				'hgb-275-pnl-vertical' => static fn (array $o): bool => self::equalsField($o, 'plForm', 'vertical')
					&& self::pnlLayoutComplete($o),

				// §267: small/medium/large by exceeding at least two of three criteria —
				// the declared class is internally consistent with the thresholds.
				'hgb-267-size-classes' => static fn (array $o): bool => self::sizeClassDeclared($o)
					&& (self::sizeClassConsistent($o, self::DE_THRESHOLDS, 'micro')
					&& self::sizeClassConsistent($o, self::DE_THRESHOLDS, 'small')
					&& self::sizeClassConsistent($o, self::DE_THRESHOLDS, 'medium')),

				// §267(1) (2024): a small corporation does not exceed two of the small
				// thresholds.
				'hgb-267-small-thresholds-2024' => static fn (array $o): bool => self::sizeClassConsistent($o, self::DE_THRESHOLDS, 'small'),

				// §267(2) (2024): a medium corporation does not exceed two of the
				// medium thresholds.
				'hgb-267-medium-thresholds-2024' => static fn (array $o): bool => self::sizeClassConsistent($o, self::DE_THRESHOLDS, 'medium'),

				// §267a: a micro corporation does not exceed two of the micro
				// thresholds.
				'hgb-267a-micro' => static fn (array $o): bool => self::sizeClassConsistent($o, self::DE_THRESHOLDS, 'micro'),

				// §316: corporations that are not small must be audited.
				'hgb-316-audit-duty' => static fn (array $o): bool => self::auditDutyWith($o),

				// §325: disclosure within one year (12 months) of the balance-sheet
				// date.
				'hgb-325-disclosure-deadline' => static fn (array $o): bool => self::filedWithin($o, 12),

				// ===== FR — PCG / FEC / greffe. =====
				// FEC: every accounting entry is balanced (total debit equals total
				// credit).
				'fec-balanced-entries' => static fn (array $o): bool => self::fecBalanced($o),

				// greffe: approved accounts are filed within (up to two months
				// electronically of) approval; enforced as filed on/after adoption and
				// within two months of it.
				'fr-filing-greffe' => static fn (array $o): bool => self::filedAfterAdoptionWithin($o, 2),

				// CAC audit thresholds: a statutory auditor is mandatory when two of
				// (balance 5M / turnover 10M / 50 employees) are exceeded.
				'fr-audit-cac-thresholds' => static fn (array $o): bool => self::frAuditConsistent($o),

				// ===== EU — ESEF. =====
				// ESEF scope: tagging applies only to issuers on an EU regulated
				// market — a non-listed statement is vacuously in scope-compliant.
				'esef-scope-listed-only' => static fn (array $o): bool => self::isBool($o, 'listedOnRegulatedMarket'),

				// ESEF XHTML: a listed issuer's report is in XHTML format.
				'esef-xhtml-format' => static fn (array $o): bool => self::notListedOr($o, static fn (array $x): bool => self::isTrue($x, 'xhtmlFormat')),

				// ESEF iXBRL: a listed issuer's consolidated IFRS statements are
				// marked up with Inline XBRL.
				'esef-ixbrl-consolidated' => static fn (array $o): bool => self::notListedOr($o, static fn (array $x): bool => self::isTrue($x, 'iXbrlTagged')),

				// ESEF detailed tagging: a listed issuer tags the primary statements at
				// individual-value level.
				'esef-detailed-primary' => static fn (array $o): bool => self::notListedOr($o, static fn (array $x): bool => self::isTrue($x, 'detailTaggedPrimary')),
			],
		];

	}//end checks()

	/**
	 * No field backfill on existing rows — FinancialStatement is a new type seeded
	 * wholesale by seedObjects().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * One fully-compliant FinancialStatement per jurisdiction (NL / DE / FR / EU-listed),
	 * created only when the type is empty so the audit evaluates these checks. Each
	 * sample satisfies EVERY predicate above (the predicates are jurisdiction-agnostic;
	 * the rule jurisdiction only gates which rules run for a given audit context).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		$balanceSheet = [
			'fixedAssets' => 120000.0,
			'currentAssets' => 80000.0,
			'equity' => 90000.0,
			'provisions' => 10000.0,
			'liabilities' => 100000.0,
		];

		$profitAndLoss = [
			'netTurnover' => 500000.0,
			'operatingExpenses' => 430000.0,
			'financialResult' => -5000.0,
			'result' => 65000.0,
		];

		// A small entity: exceeds at most one of each jurisdiction's small/medium
		// thresholds, so 'small' is the consistent class and audit is not required.
		$base = [
			'statementType' => 'annual',
			'framework' => 'bw',
			'reportingPeriodStart' => '2025-01-01',
			'reportingPeriodEnd' => '2025-12-31',
			'currency' => 'EUR',
			'balanceTotal' => 200000.0,
			'turnover' => 500000.0,
			'employees' => 12.0,
			'sizeClass' => 'small',
			'sizeClassPriorYear' => 'small',
			'balanceSheet' => $balanceSheet,
			'profitAndLoss' => $profitAndLoss,
			'plPresentationModel' => 'categorical',
			'balanceForm' => 'account',
			'plForm' => 'vertical',
			'priorYearComparatives' => true,
			'itemsNetted' => false,
			'components' => ['balance', 'pnl', 'notes'],
			'audited' => false,
			'preparedDate' => '2026-05-31',
			'adoptionDate' => '2026-06-15',
			'filedDate' => '2026-06-20',
			'xhtmlFormat' => false,
			'iXbrlTagged' => false,
			'detailTaggedPrimary' => false,
			'listedOnRegulatedMarket' => false,
			'fecEntries' => [
				['entryNum' => '1', 'debit' => 1000.0, 'credit' => 1000.0],
				['entryNum' => '2', 'debit' => 250.5, 'credit' => 250.5],
			],
		];

		$nl = $base;
		$nl['jurisdiction'] = 'NL';
		$nl['language'] = 'nl';

		$de = $base;
		$de['jurisdiction'] = 'DE';
		$de['framework'] = 'hgb';
		$de['language'] = 'de';

		$fr = $base;
		$fr['jurisdiction'] = 'FR';
		$fr['framework'] = 'pcg';
		$fr['language'] = 'fr';

		// An EU listed issuer: consolidated IFRS, in ESEF scope and fully tagged.
		$eu = $base;
		$eu['jurisdiction'] = 'NL';
		$eu['framework'] = 'ifrs';
		$eu['language'] = 'nl';
		$eu['statementType'] = 'consolidated';
		$eu['listedOnRegulatedMarket'] = true;
		$eu['xhtmlFormat'] = true;
		$eu['iXbrlTagged'] = true;
		$eu['detailTaggedPrimary'] = true;

		return [
			'FinancialStatement' => [$nl, $de, $fr, $eu],
		];

	}//end seedObjects()

	// ===================== private helpers =====================

	/**
	 * A non-empty scalar value is present under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * The string value under $key equals $value (case-insensitive, trimmed).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 * @param string $value The expected value.
	 *
	 * @return bool
	 */
	private static function equalsField(array $o, string $key, string $value): bool {
		return self::present($o, $key) === true
			&& strcasecmp(trim((string)$o[$key]), $value) === 0;

	}//end equalsField()

	/**
	 * The statement's own jurisdiction is NOT $code (case-insensitive) — used to
	 * scope a national language/presentation rule out of a foreign entity's accounts.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $code ISO 3166-1 alpha-2 jurisdiction code.
	 *
	 * @return bool
	 */
	private static function jurisdictionNot(array $o, string $code): bool {
		return self::present($o, 'jurisdiction') === true
			&& strcasecmp(trim((string)$o['jurisdiction']), $code) !== 0;

	}//end jurisdictionNot()

	/**
	 * The language code under 'language' is $lang (case-insensitive, two-letter).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $lang ISO 639-1 code (e.g. 'nl', 'de').
	 *
	 * @return bool
	 */
	private static function langIs(array $o, string $lang): bool {
		return self::present($o, 'language') === true
			&& strcasecmp(substr(trim((string)$o['language']), 0, 2), $lang) === 0;

	}//end langIs()

	/**
	 * The value under $key is one of $allowed (case-insensitive).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 * @param array<int, string> $allowed Allowed values.
	 *
	 * @return bool
	 */
	private static function inEnum(array $o, string $key, array $allowed): bool {
		if (self::present($o, $key) === false) {
			return false;
		}

		$val = strtolower(trim((string)$o[$key]));
		foreach ($allowed as $a) {
			if (strtolower($a) === $val) {
				return true;
			}
		}

		return false;
	}//end inEnum()

	/**
	 * The field under $key is a boolean (or boolean-like) value at all.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isBool(array $o, string $key): bool {
		return array_key_exists($key, $o) === true
			&& (is_bool($o[$key]) === true || in_array($o[$key], [0, 1, '0', '1', 'true', 'false'], true) === true);

	}//end isBool()

	/**
	 * The boolean field under $key is explicitly true.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isTrue(array $o, string $key): bool {
		return self::isBool($o, $key) === true
			&& in_array($o[$key], [true, 1, '1', 'true'], true) === true;

	}//end isTrue()

	/**
	 * The boolean field under $key is explicitly false.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isFalse(array $o, string $key): bool {
		return self::isBool($o, $key) === true
			&& in_array($o[$key], [false, 0, '0', 'false'], true) === true;

	}//end isFalse()

	/**
	 * The nested balanceSheet carries all five statutory components as numbers
	 * (fixed/current assets; equity, provisions, liabilities).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function balanceLayoutComplete(array $o): bool {
		$bs = ($o['balanceSheet'] ?? null);
		if (is_array($bs) === false) {
			return false;
		}

		foreach (['fixedAssets', 'currentAssets', 'equity', 'provisions', 'liabilities'] as $key) {
			if (array_key_exists($key, $bs) === false || is_numeric($bs[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end balanceLayoutComplete()

	/**
	 * The nested profitAndLoss carries net turnover, operating expenses, financial
	 * result and the result, all as numbers.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function pnlLayoutComplete(array $o): bool {
		$pl = ($o['profitAndLoss'] ?? null);
		if (is_array($pl) === false) {
			return false;
		}

		foreach (['netTurnover', 'operatingExpenses', 'financialResult', 'result'] as $key) {
			if (array_key_exists($key, $pl) === false || is_numeric($pl[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end pnlLayoutComplete()

	/**
	 * The component code $code appears in the statement's components list.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $code The component code (e.g. 'balance', 'pnl').
	 *
	 * @return bool
	 */
	private static function hasComponent(array $o, string $code): bool {
		$components = ($o['components'] ?? null);
		if (is_array($components) === false) {
			return false;
		}

		foreach ($components as $c) {
			if (strcasecmp(trim((string)$c), $code) === 0) {
				return true;
			}
		}

		return false;
	}//end hasComponent()

	/**
	 * A size class is declared from the allowed set.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function sizeClassDeclared(array $o): bool {
		return self::inEnum($o, 'sizeClass', ['micro', 'small', 'medium', 'large']);
	}//end sizeClassDeclared()

	/**
	 * When the statement declares size class $class, its balanceTotal / turnover /
	 * employees do not exceed more than one of the $class thresholds (the statutory
	 * "not exceeding two of three" test). Vacuously true when a DIFFERENT class is
	 * declared (that other class's own rule governs it) or no metrics are modelled.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param array<string, array<int, float>> $table The jurisdiction's threshold table.
	 * @param string $class The size class this rule governs.
	 *
	 * @return bool
	 */
	private static function sizeClassConsistent(array $o, array $table, string $class): bool {
		$declared = strtolower(trim((string)($o['sizeClass'] ?? '')));
		if ($declared !== $class) {
			return true;
		}

		if (array_key_exists($class, $table) === false) {
			return true;
		}

		[$balCap, $turnCap, $empCap] = $table[$class];

		$exceeded = 0;
		if (self::numericVal($o, 'balanceTotal') > $balCap) {
			$exceeded++;
		}

		if (self::numericVal($o, 'turnover') > $turnCap) {
			$exceeded++;
		}

		if (self::numericVal($o, 'employees') > $empCap) {
			$exceeded++;
		}

		return $exceeded <= 1;
	}//end sizeClassConsistent()

	/**
	 * The declared size regime is stable: it equals the prior-year size class, or the
	 * prior-year class is not modelled (a change requires two consecutive balance
	 * dates under BW 2:398 / HGB 267(4)).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function regimeStable(array $o): bool {
		if (self::present($o, 'sizeClassPriorYear') === false) {
			return true;
		}

		return self::present($o, 'sizeClass') === true
			&& strcasecmp(trim((string)$o['sizeClass']), trim((string)$o['sizeClassPriorYear'])) === 0;

	}//end regimeStable()

	/**
	 * The audit duty is met: a non-small entity (medium/large) is audited; a
	 * micro/small entity needs no audit. Requires a declared size class.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function auditDutyWith(array $o): bool {
		if (self::sizeClassDeclared($o) === false) {
			return false;
		}

		$class = strtolower(trim((string)$o['sizeClass']));
		if ($class === 'medium' || $class === 'large') {
			return self::isTrue($o, 'audited');
		}

		return true;
	}//end auditDutyMet()

	/**
	 * The FR statutory-auditor (CAC) requirement is internally consistent: when two of
	 * the thresholds (balance 5M / turnover 10M / 50 employees) are exceeded the
	 * statement is audited; otherwise no requirement. Requires the size metrics.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frAuditConsistent(array $o): bool {
		if (self::present($o, 'balanceTotal') === false || self::present($o, 'turnover') === false
			|| self::present($o, 'employees') === false
		) {
			return false;
		}

		$exceeded = 0;
		if (self::numericVal($o, 'balanceTotal') > 5000000.0) {
			$exceeded++;
		}

		if (self::numericVal($o, 'turnover') > 10000000.0) {
			$exceeded++;
		}

		if (self::numericVal($o, 'employees') > 50.0) {
			$exceeded++;
		}

		if ($exceeded >= 2) {
			return self::isTrue($o, 'audited');
		}

		return true;
	}//end frAuditConsistent()

	/**
	 * The accounts were filed within $months months of the reporting period end, and
	 * the filing date is on/after the period end.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param int $months The maximum filing window in months.
	 *
	 * @return bool
	 */
	private static function filedWithin(array $o, int $months): bool {
		$end = self::date($o, 'reportingPeriodEnd');
		$filed = self::date($o, 'filedDate');
		if ($end === null || $filed === null) {
			return false;
		}

		$deadline = (clone $end)->modify('+' . $months . ' months');
		return $filed >= $end && $filed <= $deadline;
	}//end filedWithin()

	/**
	 * The accounts were filed on/after adoption and within $months months of it.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param int $months The maximum window after adoption, in months.
	 *
	 * @return bool
	 */
	private static function filedAfterAdoptionWithin(array $o, int $months): bool {
		$adopted = self::date($o, 'adoptionDate');
		$filed = self::date($o, 'filedDate');
		if ($adopted === null || $filed === null) {
			return false;
		}

		$deadline = (clone $adopted)->modify('+' . $months . ' months');
		return $filed >= $adopted && $filed <= $deadline;
	}//end filedAfterAdoptionWithin()

	/**
	 * The accounts were prepared within $months months of the reporting period end,
	 * and the preparation date is on/after the period end.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param int $months The maximum preparation window in months.
	 *
	 * @return bool
	 */
	private static function preparedWithin(array $o, int $months): bool {
		$end = self::date($o, 'reportingPeriodEnd');
		$prepared = self::date($o, 'preparedDate');
		if ($end === null || $prepared === null) {
			return false;
		}

		$deadline = (clone $end)->modify('+' . $months . ' months');
		return $prepared >= $end && $prepared <= $deadline;
	}//end preparedWithin()

	/**
	 * Every modelled FEC entry is balanced: total debit equals total credit at cent
	 * precision. Requires at least one entry.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function fecBalanced(array $o): bool {
		$entries = ($o['fecEntries'] ?? null);
		if (is_array($entries) === false || $entries === []) {
			return false;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				return false;
			}

			$debit = (float)($entry['debit'] ?? 0);
			$credit = (float)($entry['credit'] ?? 0);
			if (abs(round($debit, 2) - round($credit, 2)) >= 0.005) {
				return false;
			}
		}

		return true;
	}//end fecBalanced()

	/**
	 * The statement is not in ESEF scope (not listed), OR it satisfies $tagged.
	 * Requires the listed flag to be present so scope is decidable.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param callable $tagged Predicate `fn(array $o): bool` for a listed issuer.
	 *
	 * @return bool
	 */
	private static function notListedOr(array $o, callable $tagged): bool {
		if (self::isBool($o, 'listedOnRegulatedMarket') === false) {
			return false;
		}

		if (self::isTrue($o, 'listedOnRegulatedMarket') === false) {
			return true;
		}

		return (bool)$tagged($o);
	}//end notListedOr()

	/**
	 * The numeric value under $key (0.0 when absent / non-numeric).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return float
	 */
	private static function numericVal(array $o, string $key): float {
		return is_numeric($o[$key] ?? null) === true ? (float)$o[$key] : 0.0;
	}//end numericVal()

	/**
	 * Parse the date field under $key into a DateTimeImmutable, or null when absent /
	 * unparseable.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function date(array $o, string $key): ?DateTimeImmutable {
		if (self::present($o, $key) === false) {
			return null;
		}

		try {
			return new DateTimeImmutable(substr(trim((string)$o[$key]), 0, 10));
		} catch (\Exception $e) {
			return null;
		}

	}//end date()
}//end class
