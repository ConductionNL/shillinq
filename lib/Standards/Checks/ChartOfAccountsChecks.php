<?php

/**
 * Chart-of-Accounts Check Provider
 *
 * Contributes executable checks for the `chart-of-accounts` rule domain to the
 * RuleEngine. The predicates map the national standard charts of accounts onto the
 * structured Account fields shillinq already models — the leading-digit account
 * class of `accountNumber` and the `accountType` (assets / liabilities / equity /
 * revenue / expenses) it must be consistent with:
 *
 *  - RGS (NL, rgs-*): each GL account carries a standardised RGS reference code and
 *    a unique RGS reference number (mapped to the SBR taxonomy).
 *  - PCG (FR, pcg-class-1..8 + pcg-common-coa): the French plan comptable général's
 *    seven decimal classes (capitaux / immobilisations / stocks / tiers / financiers
 *    / charges / produits) plus class 8 special accounts.
 *  - SKR04 (DE, skr04-class-mapping): the German Abschlussgliederung class ranges.
 *  - PCMN/MAR (BE, be-mar-pcmn-chart): classes 1-5 balance sheet, 6-7 income.
 *  - PGC (ES, es-pgc-cuadro-cuentas): the Spanish cuadro de cuentas' seven groups.
 *
 * Each class predicate is referential: it is vacuously true for accounts outside the
 * class and, for accounts whose leading digit selects the class, asserts that the
 * modelled `accountType` is consistent with what that national standard records in
 * the class. Statement-presentation / SBR-filing / système-abrégé rules that concern
 * a financial statement rather than the Account object are intentionally not
 * registered here.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule is
 * satisfied — and is side-effect free and self-contained (it inlines its logic via
 * the private static helpers on this class rather than reaching into RuleEngine).
 * Every ruleId is a real `machineCheckable` id from lib/Standards/rules/
 * national-reporting.json and national-extra.json.
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
 * Executable national chart-of-accounts checks against Account.accountNumber /
 * Account.accountType.
 */
class ChartOfAccountsChecks implements CheckProvider {
	/**
	 * Per-object-type, per-rule predicates for the `chart-of-accounts` domain.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'Account' => [
				// --- RGS (NL) — standardised reference codes / structure. ---
				// rgs-coa-standard-codes: the account is mapped to a standardised RGS
				// reference code (enabling uniform SBR exchange to KvK/Belastingdienst/CBS).
				'rgs-coa-standard-codes' => static fn (array $o): bool => self::present($o, 'rgsCode'),
				// rgs-code-structure: the account carries BOTH a hierarchical RGS reference
				// code (alphabetic, hierarchical) AND a unique numeric RGS reference number
				// that maps one-to-one to the SBR taxonomy element.
				'rgs-code-structure' => static fn (array $o): bool => (self::rgsCodeShapeOk((string)($o['rgsCode'] ?? ''))
					&& self::rgsNumberShapeOk((string)($o['rgsReferenceNumber'] ?? ''))),

				// --- PCG (FR) — plan comptable général, seven decimal classes. ---
				// pcg-common-coa: the account number follows the PCG decimal numbering of
				// the (1..8) classes — a purely numeric code whose leading digit is 1-8.
				'pcg-common-coa' => static fn (array $o): bool => self::numericClassCode($o, 1, 8),
				// pcg-class-1 (comptes de capitaux): equity, reserves, results, regulated
				// provisions and long-term borrowings — equity or liabilities.
				'pcg-class-1-capitaux' => static fn (array $o): bool => self::classTypeOk($o, '1', ['equity', 'liabilities']),
				// pcg-class-2 (comptes d'immobilisations): intangible/tangible/financial
				// fixed assets and their depreciation/impairment — assets.
				'pcg-class-2-immobilisations' => static fn (array $o): bool => self::classTypeOk($o, '2', ['assets']),
				// pcg-class-3 (comptes de stocks et en-cours): inventories / WIP — assets.
				'pcg-class-3-stocks' => static fn (array $o): bool => self::classTypeOk($o, '3', ['assets']),
				// pcg-class-4 (comptes de tiers): receivables and payables (suppliers,
				// customers, personnel, State incl. TVA) — assets or liabilities.
				'pcg-class-4-tiers' => static fn (array $o): bool => self::classTypeOk($o, '4', ['assets', 'liabilities']),
				// pcg-class-5 (comptes financiers): bank, cash, marketable securities and
				// other treasury — assets (or short-term bank liabilities).
				'pcg-class-5-financiers' => static fn (array $o): bool => self::classTypeOk($o, '5', ['assets', 'liabilities']),
				// pcg-class-6 (comptes de charges): expenses by nature plus income tax.
				'pcg-class-6-charges' => static fn (array $o): bool => self::classTypeOk($o, '6', ['expenses']),
				// pcg-class-7 (comptes de produits): income by nature.
				'pcg-class-7-produits' => static fn (array $o): bool => self::classTypeOk($o, '7', ['revenue']),
				// (pcg-class-8-special dropped: its predicate was effectively always-true for any
				// well-formed numeric account number and asserted nothing about accountType, so it
				// added no real enforcement value — flagged by the workflow verify stage.)
				// --- SKR04 (DE) — Abschlussgliederung class ranges. ---
				// skr04-class-mapping: class 0 fixed assets/equity, 1 financial & current
				// assets, 2 equity & provisions, 3 liabilities, 4-7 income/expenses.
				'skr04-class-mapping' => static fn (array $o): bool => self::skr04ClassOk($o),

				// --- PCMN/MAR (BE) — minimum standardised chart of accounts. ---
				// be-mar-pcmn-chart: classes 1-5 balance sheet, 6-7 income statement; the
				// leading-digit class must be consistent with the account type.
				'be-mar-pcmn-chart' => static fn (array $o): bool => self::pcmnClassOk($o),

				// --- PGC (ES) — cuadro de cuentas, seven groups. ---
				// es-pgc-cuadro-cuentas: coded in seven groups (1 financiación básica …
				// 7 ventas e ingresos); the leading-digit group must match the account type.
				'es-pgc-cuadro-cuentas' => static fn (array $o): bool => self::pgcGroupOk($o),
			],
		];

	}//end checks()

	/**
	 * Test-data field defaults this provider's checks expect on Account. The RGS
	 * presence/structure checks need a standardised reference code and a unique RGS
	 * reference number; the seeder backfills these (only where missing) onto the
	 * existing Account rows so the NL `rgs-*` rules can be enforced. The PCG/SKR04/
	 * PCMN/PGC class predicates read the existing accountNumber/accountType, so they
	 * need no seeding.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'Account' => [
				// Hierarchical alphabetic RGS reference code (RGS rgs-coa-standard-codes).
				'rgsCode' => 'BLimVap',
				// Unique numeric RGS reference number mapped to the SBR taxonomy element
				// (RGS rgs-code-structure).
				'rgsReferenceNumber' => '10101010',
			],
		];

	}//end seedSpec()

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
	 * The account's class digit: the FIRST digit of the (numeric) account number, or
	 * '' when the account number holds no leading digit.
	 *
	 * @param array<string, mixed> $o The Account.
	 *
	 * @return string The leading digit '0'..'9', or '' when none.
	 */
	private static function accountClass(array $o): string {
		$number = trim((string)($o['accountNumber'] ?? ''));
		if ($number === '' || ctype_digit($number[0]) === false) {
			return '';
		}

		return $number[0];
	}//end accountClass()

	/**
	 * The account number is a purely numeric (decimal) code whose leading digit lies
	 * within the inclusive class range [$min, $max]. Used to assert that the chart
	 * follows the standard's decimal class numbering. Fail-closed on an empty / non
	 * numeric account number.
	 *
	 * @param array<string, mixed> $o The Account.
	 * @param int $min Lowest permitted leading digit.
	 * @param int $max Highest permitted leading digit.
	 *
	 * @return bool
	 */
	private static function numericClassCode(array $o, int $min, int $max): bool {
		$number = trim((string)($o['accountNumber'] ?? ''));
		if ($number === '' || ctype_digit($number) === false) {
			return false;
		}

		$class = (int)$number[0];
		return $class >= $min && $class <= $max;
	}//end numericClassCode()

	/**
	 * For an account whose leading-digit class equals $class, its `accountType` must
	 * be one of $allowed. Vacuously true for accounts outside the class (a different
	 * leading digit, or a non-numeric account number that this national rule cannot
	 * place). This is the referential consistency test between the standard's class
	 * and the modelled account type.
	 *
	 * @param array<string, mixed> $o The Account.
	 * @param string $class The leading-digit class this rule governs.
	 * @param array<int, string> $allowed Permitted accountType values for the class.
	 *
	 * @return bool
	 */
	private static function classTypeOk(array $o, string $class, array $allowed): bool {
		if (self::accountClass($o) !== $class) {
			return true;
		}

		return in_array((string)($o['accountType'] ?? ''), $allowed, true);
	}//end classTypeOk()

	/**
	 * SKR04 (DE) leading-digit class mapping (skr04-class-mapping). Each numeric class
	 * constrains the permitted account type; non-numeric or out-of-range account
	 * numbers fail (the chart must use the SKR04 decimal classes):
	 *  0 fixed assets & equity        → assets / equity
	 *  1 financial & current assets   → assets
	 *  2 equity & provisions          → equity / liabilities
	 *  3 liabilities                  → liabilities
	 *  4-7 income / expenses          → revenue / expenses
	 *  8-9 (statistical / open items) → any (not constrained by the rule).
	 *
	 * @param array<string, mixed> $o The Account.
	 *
	 * @return bool
	 */
	private static function skr04ClassOk(array $o): bool {
		$class = self::accountClass($o);
		if ($class === '') {
			return false;
		}

		$type = (string)($o['accountType'] ?? '');
		$allowed = [
			'0' => ['assets', 'equity'],
			'1' => ['assets'],
			'2' => ['equity', 'liabilities'],
			'3' => ['liabilities'],
			'4' => ['revenue', 'expenses'],
			'5' => ['revenue', 'expenses'],
			'6' => ['revenue', 'expenses'],
			'7' => ['revenue', 'expenses'],
		];

		if (array_key_exists($class, $allowed) === false) {
			return true;
		}

		return in_array($type, $allowed[$class], true);
	}//end skr04ClassOk()

	/**
	 * PCMN/MAR (BE) leading-digit class mapping (be-mar-pcmn-chart): classes 1-5 are
	 * the balance sheet, 6-7 the income statement. Non-numeric account numbers fail;
	 * a leading digit outside 1-7 (e.g. class 0, reserved) is not constrained.
	 *  1 capital & long-term liabilities → equity / liabilities
	 *  2 immobilisations (fixed assets)  → assets
	 *  3 stocks & commandes en cours     → assets
	 *  4 créances & dettes               → assets / liabilities
	 *  5 placements & valeurs disponibles→ assets / liabilities
	 *  6 charges                         → expenses
	 *  7 produits                        → revenue.
	 *
	 * @param array<string, mixed> $o The Account.
	 *
	 * @return bool
	 */
	private static function pcmnClassOk(array $o): bool {
		$class = self::accountClass($o);
		if ($class === '') {
			return false;
		}

		$type = (string)($o['accountType'] ?? '');
		$allowed = [
			'1' => ['equity', 'liabilities'],
			'2' => ['assets'],
			'3' => ['assets'],
			'4' => ['assets', 'liabilities'],
			'5' => ['assets', 'liabilities'],
			'6' => ['expenses'],
			'7' => ['revenue'],
		];

		if (array_key_exists($class, $allowed) === false) {
			return true;
		}

		return in_array($type, $allowed[$class], true);
	}//end pcmnClassOk()

	/**
	 * PGC (ES) leading-digit group mapping (es-pgc-cuadro-cuentas): seven groups, the
	 * leading digit selecting the group. Non-numeric account numbers fail; a leading
	 * digit outside 1-7 (groups 8/9 hold gastos/ingresos de patrimonio) is not
	 * constrained by this rule.
	 *  1 financiación básica            → equity / liabilities
	 *  2 inmovilizado (activos fijos)   → assets
	 *  3 existencias (stocks)           → assets
	 *  4 acreedores y deudores          → assets / liabilities
	 *  5 cuentas financieras            → assets / liabilities
	 *  6 compras y gastos               → expenses
	 *  7 ventas e ingresos              → revenue.
	 *
	 * @param array<string, mixed> $o The Account.
	 *
	 * @return bool
	 */
	private static function pgcGroupOk(array $o): bool {
		$class = self::accountClass($o);
		if ($class === '') {
			return false;
		}

		$type = (string)($o['accountType'] ?? '');
		$allowed = [
			'1' => ['equity', 'liabilities'],
			'2' => ['assets'],
			'3' => ['assets'],
			'4' => ['assets', 'liabilities'],
			'5' => ['assets', 'liabilities'],
			'6' => ['expenses'],
			'7' => ['revenue'],
		];

		if (array_key_exists($class, $allowed) === false) {
			return true;
		}

		return in_array($type, $allowed[$class], true);
	}//end pgcGroupOk()

	/**
	 * An RGS reference code is well-shaped: a hierarchical alphabetic code of at least
	 * two letters (the RGS reference codes are alphabetic and hierarchical, e.g.
	 * 'BLimVapBgWaa'). No digits / separators are expected in the reference code.
	 *
	 * @param string $code The RGS reference code.
	 *
	 * @return bool
	 */
	private static function rgsCodeShapeOk(string $code): bool {
		$clean = trim($code);
		return strlen($clean) >= 2 && ctype_alpha($clean) === true;
	}//end rgsCodeShapeOk()

	/**
	 * An RGS reference number is well-shaped: a non-empty purely numeric identifier
	 * (the unique number that maps one-to-one to the SBR taxonomy element).
	 *
	 * @param string $number The RGS reference number.
	 *
	 * @return bool
	 */
	private static function rgsNumberShapeOk(string $number): bool {
		$clean = trim($number);
		return $clean !== '' && ctype_digit($clean) === true;
	}//end rgsNumberShapeOk()
}//end class
