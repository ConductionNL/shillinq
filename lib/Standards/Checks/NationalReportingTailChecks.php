<?php

/**
 * National-Reporting Tail Check Provider
 *
 * Contributes the remaining machine-checkable `national-reporting` rules that
 * FinancialStatementsChecks does not yet enforce: the detailed balance-sheet and
 * profit-and-loss composition obligations (NL BW 2:365/365-1/366/367/369 fixed-
 * and current-asset classes, 2:373 equity components, 2:375 debt-maturity split;
 * DE HGB 268 equity detail, 266 small abridged balance, 275 Gesamtkosten-/
 * Umsatzkostenverfahren; FR PCG balance-table and income-statement-list models),
 * the Dutch legal-reserve and intangible-life rules of RJ 210, the RGS/SBR deposit
 * channel, the German and French retention periods (HGB 257, FR PCG), the French
 * abridged/base accounting-system choice and confidentiality option, the FR FEC
 * export capability and field structure, and the EU ESEF notes block-tagging and
 * taxonomy-extension anchoring obligations.
 *
 * Every predicate maps onto fields of the EXISTING FinancialStatement object (which
 * already carries four seeded rows). The new fields those predicates read are
 * backfilled onto the existing rows via seedSpec() with defaults that SATISFY the
 * predicates, and the FinancialStatement schema is extended (with its version
 * bumped) in the lib/Settings/register.d/checks-national-reporting-tail.json
 * fragment so OpenRegister re-imports the added properties. No second
 * FinancialStatement schema is created.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class rather than reaching into
 * RuleEngine). Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/national-reporting.json that is not already enforced by
 * FinancialStatementsChecks. The SAF-T master-file group rules (saft-*) and the
 * SAF-T/FEC XML-schema-validity rules are intentionally not registered: they
 * require a ledger-export artefact shillinq does not model. The PCG class-8
 * off-balance-sheet rule is skipped as narrative/judgemental.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable tail national-reporting checks over the FinancialStatement object.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Pre-existing debt
 *     (issue #506): this class enumerates every tail national-reporting
 *     rule the catalogue defines; inherent complexity. Deferred to a
 *     follow-up.
 */
class NationalReportingTailChecks implements CheckProvider {
	/**
	 * Per-object-type, per-rule predicates for the remaining `national-reporting`
	 * domain rules.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'FinancialStatement' => [
				// ===== NL — Burgerlijk Wetboek Boek 2, Titel 9 (composition). =====
				// art. 365: vaste activa are subdivided into intangible, tangible and
				// financial fixed assets, and their sum reconciles to the balance-sheet
				// fixedAssets total.
				'bw-art365-fixed-assets-classes' => static fn (array $o): bool => self::numericKeys($o, 'fixedAssetClasses', ['intangible', 'tangible', 'financial'])
					&& self::sumReconciles($o, 'fixedAssetClasses', ['intangible', 'tangible', 'financial'], 'balanceSheet', 'fixedAssets'),

				// art. 365 lid 1: immateriële vaste activa separately disclose
				// development costs, concessions/licences/IP, goodwill paid and
				// prepayments on intangibles.
				'bw-art365-1-intangibles' => static fn (array $o): bool => self::numericKeys($o, 'intangibleAssetClasses', ['development', 'concessions', 'goodwill', 'prepayments']),

				// art. 366: materiële vaste activa are subdivided into land and
				// buildings, plant and machinery, other operating assets and assets
				// under construction / prepayments.
				'bw-art366-tangible-assets' => static fn (array $o): bool => self::numericKeys($o, 'tangibleAssetClasses', ['landAndBuildings', 'plantAndMachinery', 'otherOperating', 'underConstruction']),

				// art. 367: financiële vaste activa separately disclose participating
				// interests, group/participation receivables, other securities and
				// other receivables.
				'bw-art367-financial-fixed-assets' => static fn (array $o): bool => self::numericKeys($o, 'financialFixedAssetClasses', ['participations', 'groupReceivables', 'otherSecurities', 'otherReceivables']),

				// art. 369: vlottende activa are subdivided into inventories,
				// receivables, securities and cash, and their sum reconciles to the
				// balance-sheet currentAssets total.
				'bw-art369-current-assets' => static fn (array $o): bool => self::numericKeys($o, 'currentAssetClasses', ['inventories', 'receivables', 'securities', 'cash'])
					&& self::sumReconciles($o, 'currentAssetClasses', ['inventories', 'receivables', 'securities', 'cash'], 'balanceSheet', 'currentAssets'),

				// art. 373: eigen vermogen is split into issued capital, share premium,
				// revaluation reserve, other statutory/articles reserves and
				// retained/undistributed result, reconciling to the equity total.
				'bw-art373-equity-components' => static fn (array $o): bool => self::numericKeys($o, 'equityComponents', ['issuedCapital', 'sharePremium', 'revaluationReserve', 'statutoryReserves', 'retainedResult'])
					&& self::sumReconciles($o, 'equityComponents', ['issuedCapital', 'sharePremium', 'revaluationReserve', 'statutoryReserves', 'retainedResult'], 'balanceSheet', 'equity'),

				// art. 375: schulden disclose separately the portion with a remaining
				// term over one year and that over five years; the short + long-term
				// split reconciles to the liabilities total and the >5yr portion is a
				// subset of the >1yr portion.
				'bw-art375-debts-maturity' => static fn (array $o): bool => self::debtsMaturityValid($o),

				// RJ 210: when development costs are capitalised a legal reserve equal
				// to the unamortised carrying amount is maintained within equity.
				'rj-210-development-legal-reserve' => static fn (array $o): bool => self::developmentLegalReserveValid($o),

				// RJ 210: an intangible fixed asset's amortisation life does not exceed
				// twenty years.
				'rj-210-intangible-max-life' => static fn (array $o): bool => self::intangibleLifeWithin($o, 20),

				// RGS/SBR: a micro or small entity's statutory KvK deposit is performed
				// via an SBR (XBRL) instance; larger entities are out of scope.
				'rgs-sbr-filing' => static fn (array $o): bool => self::sbrFilingValid($o),

				// ===== DE — Handelsgesetzbuch (composition / retention). =====
				// §266: only a small (or micro) corporation may present the abridged
				// balance sheet limited to lettered/Roman-numeral items.
				'hgb-266-small-abridged-balance' => static fn (array $o): bool => self::abridgedBalanceAllowed($o),

				// §268: Eigenkapital is broken down into subscribed capital, capital
				// reserve, revenue reserves, retained earnings carried forward and net
				// income/loss for the year.
				'hgb-268-equity-detail' => static fn (array $o): bool => self::numericKeys($o, 'equityComponentsHgb', ['subscribedCapital', 'capitalReserve', 'revenueReserves', 'retainedEarnings', 'netIncomeForYear']),

				// §275: under the total-cost method (Gesamtkostenverfahren) expenses are
				// classified by nature and the change in inventory and own work
				// capitalised are shown separately — required when the categorical model
				// is applied.
				'hgb-275-gesamtkostenverfahren' => static fn (array $o): bool => self::pnlMethodBreakdownValid($o, 'categorical', 'pnlNatureBreakdown', ['materialExpense', 'personnelExpense', 'depreciation', 'inventoryChange', 'ownWorkCapitalised']),

				// §275: under the cost-of-sales method (Umsatzkostenverfahren) expenses
				// are classified by function — required when the functional model is
				// applied.
				'hgb-275-umsatzkostenverfahren' => static fn (array $o): bool => self::pnlMethodBreakdownValid($o, 'functional', 'pnlFunctionBreakdown', ['costOfGoodsSold', 'distributionCosts', 'administrativeExpenses']),

				// §257: books, inventories, opening balance sheets and annual accounts
				// are retained for at least 10 years and commercial correspondence for
				// at least 6 years.
				'hgb-257-retention' => static fn (array $o): bool => self::numericAtLeast($o, 'retentionYearsAccounts', 10.0)
					&& self::numericAtLeast($o, 'retentionYearsCorrespondence', 6.0),

				// ===== FR — PCG / greffe / FEC (composition / retention). =====
				// PCG base system: the bilan presents fixed + current assets on the
				// asset side and equity, provisions and liabilities on the credit side,
				// in table form.
				'pcg-balance-table-form' => static fn (array $o): bool => self::balanceLayoutComplete($o)
					&& self::equalsField($o, 'balancePresentation', 'table'),

				// PCG: the compte de résultat distinguishes operating, financial and
				// exceptional result, presented in list form.
				'pcg-income-statement-list' => static fn (array $o): bool => self::numericKeys($o, 'pnlResultBreakdown', ['operatingResult', 'financialResult', 'exceptionalResult'])
					&& self::equalsField($o, 'pnlPresentation', 'list'),

				// PCG: the abridged system (système abrégé) may only be used by an
				// entity within the small/micro size limits.
				'pcg-systeme-abrege' => static fn (array $o): bool => self::abridgedSystemAllowed($o),

				// PCG: an entity above the abridged thresholds (medium/large) uses the
				// base system (système de base).
				'pcg-systeme-base' => static fn (array $o): bool => self::baseSystemForLarge($o),

				// FR confidentiality option: only a small/micro company may request that
				// its filed accounts remain confidential.
				'fr-confidentiality-option' => static fn (array $o): bool => self::confidentialityAllowed($o),

				// FR retention: accounting documents and supporting vouchers are
				// retained for at least 10 years.
				'fr-retention-period' => static fn (array $o): bool => self::numericAtLeast($o, 'retentionYearsAccounts', 10.0),

				// FEC: a computerised-accounts entity can produce the Fichier des
				// Écritures Comptables on a tax audit — modelled as the export being
				// available with at least one entry.
				'fec-mandatory-export' => static fn (array $o): bool => self::isTrue($o, 'fecExportAvailable')
					&& self::hasEntries($o, 'fecEntries'),

				// FEC: every exported entry carries the prescribed structural fields
				// (JournalCode, EcritureNum, EcritureDate, CompteNum, PieceRef, Debit,
				// Credit). Read from fecStructuredEntries — the field-complete FEC view
				// distinct from the balance-only fecEntries.
				'fec-field-structure' => static fn (array $o): bool => self::fecFieldStructureValid($o),

				// ===== EU — ESEF (consolidated-IFRS tagging detail). =====
				// ESEF: a listed issuer's IFRS notes are block-tagged at section level;
				// a non-listed statement is vacuously in scope-compliant.
				'esef-block-tagging-notes' => static fn (array $o): bool => self::notListedOr($o, static fn (array $x): bool => self::isTrue($x, 'notesBlockTagged')),

				// ESEF: where an extension element is used it is anchored to the closest
				// standard taxonomy element(s); when no extension is used the rule is
				// vacuously satisfied. Only relevant for listed issuers.
				'esef-taxonomy-extension' => static fn (array $o): bool => self::notListedOr($o, static fn (array $x): bool => self::taxonomyExtensionValid($x)),
			],
		];

	}//end checks()

	/**
	 * Backfill the fields these tail predicates read onto the EXISTING
	 * FinancialStatement rows (the four samples seeded by FinancialStatementsChecks),
	 * with defaults that satisfy every predicate above. The nested asset/equity class
	 * totals reconcile to the existing balanceSheet figures (fixedAssets 120000,
	 * currentAssets 80000, equity 90000, liabilities 100000).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'FinancialStatement' => [
				// NL BW composition — class breakdowns summing to the seeded totals.
				'fixedAssetClasses' => [
					'intangible' => 20000.0,
					'tangible' => 80000.0,
					'financial' => 20000.0,
				],
				'intangibleAssetClasses' => [
					'development' => 8000.0,
					'concessions' => 6000.0,
					'goodwill' => 4000.0,
					'prepayments' => 2000.0,
				],
				'tangibleAssetClasses' => [
					'landAndBuildings' => 50000.0,
					'plantAndMachinery' => 20000.0,
					'otherOperating' => 7000.0,
					'underConstruction' => 3000.0,
				],
				'financialFixedAssetClasses' => [
					'participations' => 9000.0,
					'groupReceivables' => 6000.0,
					'otherSecurities' => 3000.0,
					'otherReceivables' => 2000.0,
				],
				'currentAssetClasses' => [
					'inventories' => 25000.0,
					'receivables' => 35000.0,
					'securities' => 5000.0,
					'cash' => 15000.0,
				],
				'equityComponents' => [
					'issuedCapital' => 20000.0,
					'sharePremium' => 15000.0,
					'revaluationReserve' => 5000.0,
					'statutoryReserves' => 10000.0,
					'retainedResult' => 40000.0,
				],
				'liabilitiesMaturity' => [
					'shortTerm' => 60000.0,
					'longTerm' => 40000.0,
					'overFiveYears' => 15000.0,
				],
				// RJ 210 — a legal reserve equal to the unamortised development cost.
				'capitalisedDevelopmentCosts' => 8000.0,
				'developmentLegalReserve' => 8000.0,
				'intangibleAmortisationYears' => 10.0,
				// RGS/SBR — a small entity deposits via SBR/XBRL.
				'sbrFiled' => true,
				// DE HGB — abridged balance is permissible for the small entity, plus
				// the equity detail and (categorical) nature breakdown.
				'balanceDetailLevel' => 'abridged',
				'equityComponentsHgb' => [
					'subscribedCapital' => 20000.0,
					'capitalReserve' => 15000.0,
					'revenueReserves' => 15000.0,
					'retainedEarnings' => 10000.0,
					'netIncomeForYear' => 30000.0,
				],
				'pnlNatureBreakdown' => [
					'materialExpense' => 200000.0,
					'personnelExpense' => 180000.0,
					'depreciation' => 50000.0,
					'inventoryChange' => -2000.0,
					'ownWorkCapitalised' => 3000.0,
				],
				'pnlFunctionBreakdown' => [
					'costOfGoodsSold' => 300000.0,
					'distributionCosts' => 80000.0,
					'administrativeExpenses' => 50000.0,
				],
				'retentionYearsAccounts' => 10.0,
				'retentionYearsCorrespondence' => 6.0,
				// FR PCG — table-form balance, list-form income statement, abridged
				// system permissible for the small entity.
				'balancePresentation' => 'table',
				'pnlResultBreakdown' => [
					'operatingResult' => 70000.0,
					'financialResult' => -5000.0,
					'exceptionalResult' => 0.0,
				],
				'pnlPresentation' => 'list',
				'accountingSystem' => 'abrege',
				'confidentialFiling' => false,
				// FR FEC — export available, plus a field-complete FEC view (kept in a
				// distinct field so the seeder backfills it on the existing rows whose
				// balance-only fecEntries lack the structural fields).
				'fecExportAvailable' => true,
				'fecStructuredEntries' => [
					[
						'entryNum' => '1',
						'debit' => 1000.0,
						'credit' => 1000.0,
						'journalCode' => 'VE',
						'ecritureDate' => '2025-01-31',
						'compteNum' => '411000',
						'pieceRef' => 'FA-2025-001',
					],
					[
						'entryNum' => '2',
						'debit' => 250.5,
						'credit' => 250.5,
						'journalCode' => 'AC',
						'ecritureDate' => '2025-02-15',
						'compteNum' => '607000',
						'pieceRef' => 'FF-2025-014',
					],
				],
				// EU ESEF — for the seeded listed issuer; harmless on non-listed rows.
				'notesBlockTagged' => true,
				'taxonomyExtensionsUsed' => false,
				'extensionsAnchored' => true,
			],
		];

	}//end seedSpec()

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
	 * The numeric value under $key (null when absent / non-numeric).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return float|null
	 */
	private static function num(array $o, string $key): ?float {
		return is_numeric($o[$key] ?? null) === true ? (float)$o[$key] : null;
	}//end num()

	/**
	 * The numeric value under $key is present and at least $min.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 * @param float $min The inclusive minimum.
	 *
	 * @return bool
	 */
	private static function numericAtLeast(array $o, string $key, float $min): bool {
		$val = self::num($o, $key);
		return $val !== null && $val >= $min;
	}//end numericAtLeast()

	/**
	 * The nested object under $group carries every listed $keys as a numeric value.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $group The nested-object field name.
	 * @param array<int, string> $keys The required numeric sub-keys.
	 *
	 * @return bool
	 */
	private static function numericKeys(array $o, string $group, array $keys): bool {
		$sub = ($o[$group] ?? null);
		if (is_array($sub) === false) {
			return false;
		}

		foreach ($keys as $key) {
			if (array_key_exists($key, $sub) === false || is_numeric($sub[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end numericKeys()

	/**
	 * The sum of the $keys in the nested $group reconciles (at cent precision) to the
	 * value of $totalKey inside the nested $totalGroup. Requires both sides present.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $group The breakdown nested-object field.
	 * @param array<int, string> $keys The sub-keys to sum.
	 * @param string $totalGroup The nested object holding the total.
	 * @param string $totalKey The total sub-key.
	 *
	 * @return bool
	 */
	private static function sumReconciles(array $o, string $group, array $keys, string $totalGroup, string $totalKey): bool {
		$sub = ($o[$group] ?? null);
		if (is_array($sub) === false) {
			return false;
		}

		$totals = ($o[$totalGroup] ?? null);
		if (is_array($totals) === false || is_numeric($totals[$totalKey] ?? null) === false) {
			return false;
		}

		$sum = 0.0;
		foreach ($keys as $key) {
			if (is_numeric($sub[$key] ?? null) === false) {
				return false;
			}

			$sum += (float)$sub[$key];
		}

		return abs(round($sum, 2) - round((float)$totals[$totalKey], 2)) < 0.005;
	}//end sumReconciles()

	/**
	 * The debt-maturity disclosure is valid: the short-term + long-term portions
	 * reconcile to the balance-sheet liabilities total, and the over-five-years
	 * portion is a non-negative subset of the long-term (over-one-year) portion.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function debtsMaturityValid(array $o): bool {
		$m = ($o['liabilitiesMaturity'] ?? null);
		if (is_array($m) === false) {
			return false;
		}

		$short = self::num($m, 'shortTerm');
		$long = self::num($m, 'longTerm');
		$over5 = self::num($m, 'overFiveYears');
		if ($short === null || $long === null || $over5 === null) {
			return false;
		}

		if ($over5 < 0.0 || $over5 > $long) {
			return false;
		}

		$bs = ($o['balanceSheet'] ?? null);
		if (is_array($bs) === false || is_numeric($bs['liabilities'] ?? null) === false) {
			return false;
		}

		return abs(round(($short + $long), 2) - round((float)$bs['liabilities'], 2)) < 0.005;
	}//end debtsMaturityValid()

	/**
	 * The RJ 210 development legal reserve is valid: when development costs are
	 * capitalised (> 0) a legal reserve at least equal to that unamortised amount is
	 * maintained; when none are capitalised the rule is vacuously satisfied.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function developmentLegalReserveValid(array $o): bool {
		$capitalised = self::num($o, 'capitalisedDevelopmentCosts');
		if ($capitalised === null) {
			return false;
		}

		if ($capitalised <= 0.0) {
			return true;
		}

		$reserve = self::num($o, 'developmentLegalReserve');
		return $reserve !== null && $reserve >= $capitalised;
	}//end developmentLegalReserveValid()

	/**
	 * The intangible amortisation life is present and does not exceed $maxYears.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param float $maxYears The statutory maximum life in years.
	 *
	 * @return bool
	 */
	private static function intangibleLifeWithin(array $o, float $maxYears): bool {
		$years = self::num($o, 'intangibleAmortisationYears');
		return $years !== null && $years > 0.0 && $years <= $maxYears;
	}//end intangibleLifeWithin()

	/**
	 * The RGS/SBR deposit channel is valid: a micro or small entity's filing is done
	 * via SBR (XBRL); a medium/large entity is out of the micro/small SBR scope and is
	 * vacuously satisfied. Requires a declared size class.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function sbrFilingValid(array $o): bool {
		$class = strtolower(trim((string)($o['sizeClass'] ?? '')));
		if ($class === '') {
			return false;
		}

		if ($class === 'micro' || $class === 'small') {
			return self::isTrue($o, 'sbrFiled');
		}

		return true;
	}//end sbrFilingValid()

	/**
	 * The abridged balance sheet (HGB 266) is only used where permitted: when the
	 * declared detail level is 'abridged' the entity must be micro or small. A full
	 * balance sheet is always permissible. Requires a declared detail level.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function abridgedBalanceAllowed(array $o): bool {
		if (self::present($o, 'balanceDetailLevel') === false) {
			return false;
		}

		$level = strtolower(trim((string)$o['balanceDetailLevel']));
		if ($level !== 'abridged') {
			return true;
		}

		$class = strtolower(trim((string)($o['sizeClass'] ?? '')));
		return $class === 'micro' || $class === 'small';
	}//end abridgedBalanceAllowed()

	/**
	 * The HGB 275 expense-method breakdown is valid: when the statement's P&L model
	 * is $model the nested $group carries every listed $keys numerically; when a
	 * different model is applied this method's rule is vacuously satisfied.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $model The plPresentationModel this method governs.
	 * @param string $group The nested breakdown field.
	 * @param array<int, string> $keys The required numeric sub-keys.
	 *
	 * @return bool
	 */
	private static function pnlMethodBreakdownValid(array $o, string $model, string $group, array $keys): bool {
		$declared = strtolower(trim((string)($o['plPresentationModel'] ?? '')));
		if ($declared !== $model) {
			return true;
		}

		return self::numericKeys($o, $group, $keys);
	}//end pnlMethodBreakdownValid()

	/**
	 * The PCG abridged system (système abrégé) is only used where permitted: when the
	 * accounting system is 'abrege' the entity must be micro or small. Any other
	 * system is always permissible. Requires a declared accounting system.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function abridgedSystemAllowed(array $o): bool {
		if (self::present($o, 'accountingSystem') === false) {
			return false;
		}

		$system = strtolower(trim((string)$o['accountingSystem']));
		if ($system !== 'abrege') {
			return true;
		}

		$class = strtolower(trim((string)($o['sizeClass'] ?? '')));
		return $class === 'micro' || $class === 'small';
	}//end abridgedSystemAllowed()

	/**
	 * The PCG base system requirement holds: a medium/large entity (above the abridged
	 * thresholds) applies the base system; a micro/small entity is not bound by this
	 * rule. Requires a declared size class and accounting system.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function baseSystemForLarge(array $o): bool {
		if (self::present($o, 'accountingSystem') === false) {
			return false;
		}

		$class = strtolower(trim((string)($o['sizeClass'] ?? '')));
		if ($class !== 'medium' && $class !== 'large') {
			return true;
		}

		return strtolower(trim((string)$o['accountingSystem'])) === 'base';
	}//end baseSystemForLarge()

	/**
	 * The FR confidentiality option is only exercised where permitted: when
	 * confidential filing is requested the entity must be micro or small. Not
	 * requesting it is always permissible.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function confidentialityAllowed(array $o): bool {
		if (self::isBool($o, 'confidentialFiling') === false) {
			return false;
		}

		if (self::isTrue($o, 'confidentialFiling') === false) {
			return true;
		}

		$class = strtolower(trim((string)($o['sizeClass'] ?? '')));
		return $class === 'micro' || $class === 'small';
	}//end confidentialityAllowed()

	/**
	 * The nested list field under $key has at least one entry.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name.
	 *
	 * @return bool
	 */
	private static function hasEntries(array $o, string $key): bool {
		$entries = ($o[$key] ?? null);
		return is_array($entries) === true && $entries !== [];
	}//end hasEntries()

	/**
	 * Every modelled FEC entry carries the prescribed structural fields: a journal
	 * code, a sequential entry number, an entry date, an account number, a piece
	 * reference and the debit/credit amounts. Requires at least one entry.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function fecFieldStructureValid(array $o): bool {
		$entries = ($o['fecStructuredEntries'] ?? null);
		if (is_array($entries) === false || $entries === []) {
			return false;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				return false;
			}

			foreach (['journalCode', 'entryNum', 'ecritureDate', 'compteNum', 'pieceRef'] as $field) {
				if (self::present($entry, $field) === false) {
					return false;
				}
			}

			if (is_numeric($entry['debit'] ?? null) === false || is_numeric($entry['credit'] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end fecFieldStructureValid()

	/**
	 * The ESEF taxonomy-extension rule is satisfied: when extension elements are used
	 * they are anchored to standard taxonomy element(s); when none are used the rule
	 * is vacuously satisfied. Requires the extensions-used flag to be decidable.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function taxonomyExtensionValid(array $o): bool {
		if (self::isBool($o, 'taxonomyExtensionsUsed') === false) {
			return false;
		}

		if (self::isTrue($o, 'taxonomyExtensionsUsed') === false) {
			return true;
		}

		return self::isTrue($o, 'extensionsAnchored');
	}//end taxonomyExtensionValid()

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
}//end class
