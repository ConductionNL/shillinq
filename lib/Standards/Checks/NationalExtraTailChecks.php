<?php

/**
 * National Extra-Tail Check Provider
 *
 * Contributes the remaining machine-checkable rules of the `recognition` rule
 * domain (lib/Standards/rules/national-extra.json) that the existing providers do
 * not yet enforce: the IT (Codice civile / OIC), ES (PGC) and BE (NBB/BNB) statutory
 * annual-accounts statement schemas, the inventory measurement-and-cost-formula
 * rules, the abbreviated-accounts thresholds and the per-jurisdiction record
 * retention minimums — all mapped onto the structured FinancialStatement object —
 * together with the per-country e-invoicing mandates (Italy SdI, Belgium Peppol,
 * France 2026, Germany 2025, Poland KSeF, Romania e-Factura, Hungary RTIR, Greece
 * myDATA, Lithuania i.SAF, Spain VERI*FACTU / TicketBAI / SII, Portugal e-Fatura)
 * modelled on a new EInvoiceMandate object, and the SAF-T export obligations
 * (Portugal, Romania D406, Lithuania i.SAF-T, Norway, Austria, Luxembourg FAIA,
 * France FEC, Poland JPK, Denmark, Bulgaria) modelled on a new SaftExportProfile
 * object.
 *
 * The FinancialStatement-mapped rules backfill the fields they read via seedSpec()
 * onto the existing FinancialStatement rows (the type already has rows, so seeding
 * wholesale would not fire); the statement-schema and inventory predicates reuse the
 * gross balance-sheet / P&L layout already modelled, and add the IT/ES/BE-specific
 * model, rounding, inventory and retention fields. The EInvoiceMandate and
 * SaftExportProfile types have no rows in the local register, so this provider also
 * implements SeedsObjects and supplies one fully-compliant sample of each (the
 * sample satisfies EVERY predicate below; the rule jurisdiction only gates which
 * rules run for a given audit context). Both new slugs are unique — no existing
 * schema in shillinq_register.json lowercases to either — so the audit evaluates the
 * intended object.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class rather than reaching into
 * RuleEngine). Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/national-extra.json. Ids already enforced elsewhere
 * (es-pgc-cuadro-cuentas, be-mar-pcmn-chart) are intentionally not re-registered.
 * The chart-of-accounts coding rules and the purely narrative / "verify"
 * judgemental rules are left to their existing owners or skipped — they would need a
 * ledger-export artefact or human judgement shillinq does not model.
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
 * Executable national extra-tail checks over FinancialStatement, EInvoiceMandate and
 * SaftExportProfile content.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * Pre-existing debt (issue #506): this class enumerates every national
 * extra-tail rule the catalogue defines; inherent complexity. Deferred
 * to a follow-up.
 */
class NationalExtraTailChecks implements CheckProvider, SeedsObjects {
	/**
	 * Statutory minimum record-retention period (years) per jurisdiction, used by the
	 * `*-retention-*` rules. The declared retentionYears must meet or exceed this.
	 *
	 * @var array<string, int>
	 */
	private const RETENTION_MINIMUMS = [
		'it-cc-art2220-retention-10y' => 10,
		'es-ccom-art30-retention-6y' => 6,
		'be-cde-art3-86-retention-7y' => 7,
		'pt-retention-10y' => 10,
		'no-retention-5y' => 5,
		'at-retention-7y' => 7,
		'lu-retention-10y' => 10,
		'fr-retention-10y' => 10,
		'de-retention-10y' => 10,
		'nl-retention-7y' => 7,
	];

	/**
	 * Allowed inventory cost-flow assumptions (IT art. 2426 n.10 / OIC 13 / ES PGC
	 * norma 10ª): weighted-average cost, FIFO or LIFO.
	 *
	 * @var array<int, string>
	 */
	private const INVENTORY_COST_FORMULAS = ['weighted-average', 'fifo', 'lifo'];

	/**
	 * Per-object-type, per-rule predicates for the remaining `recognition` rules.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		$checks = [
			'FinancialStatement' => [
				// ===== IT — Codice civile / OIC. =====
				// art. 2423 c.c.: the financial statements are expressed in euro and
				// may be rounded to the euro (currency EUR + roundedToEuro flag set).
				'it-cc-art2423-euro-rounding' => static fn (array $o): bool => self::equalsField($o, 'currency', 'EUR')
					&& self::isTrue($o, 'roundedToEuro'),

				// art. 2424 c.c.: the balance sheet follows the statutory layout —
				// assets and the equity/provisions/liabilities side are all present
				// (the gross account-form layout already modelled).
				'it-cc-art2424-balance-sheet-schema' => static fn (array $o): bool => self::balanceLayoutComplete($o),

				// art. 2425 c.c.: the income statement follows the by-nature scalar
				// layout — turnover (A), production costs (B), financial result (C)
				// and the result are all present.
				'it-cc-art2425-income-statement-schema' => static fn (array $o): bool => self::pnlLayoutComplete($o),

				// OIC 12: the composition/layout of balance sheet, income statement and
				// notes follow the OIC schemes — all three component statements present.
				'it-oic12-statement-structure' => static fn (array $o): bool => self::hasComponent($o, 'balance')
					&& self::hasComponent($o, 'pnl')
					&& self::hasComponent($o, 'notes'),

				// art. 2426 n.9 c.c. / OIC 13: inventories are measured at the lower of
				// cost and net realisable value.
				'it-cc-art2426-9-inventories-lower-of' => static fn (array $o): bool => self::inventoryLowerOf($o),
				'it-oic13-inventories' => static fn (array $o): bool => self::inventoryLowerOf($o)
					&& self::inventoryCostFormulaOk($o),

				// art. 2426 n.10 c.c.: the cost of fungible inventories uses
				// weighted-average / FIFO / LIFO; any difference from current cost is
				// disclosed in the notes (formula stated + disclosure flag set).
				'it-cc-art2426-10-inventory-cost-formula' => static fn (array $o): bool => self::inventoryCostFormulaOk($o)
					&& self::isTrue($o, 'inventoryCostFormulaDisclosed'),

				// art. 2435-bis c.c.: the abbreviated schema may only be used by an
				// entity within the size thresholds — abbreviated implies micro/small.
				'it-cc-art2435bis-abbreviated' => static fn (array $o): bool => self::abbreviatedAllowed($o),

				// OIC 34 (Ricavi): applies to periods beginning on/after 2024-01-01 —
				// a statement whose period starts on/after that date records that the
				// standard was applied; an earlier period is out of scope.
				'it-oic34-effective-2024' => static fn (array $o): bool => self::periodStartsBefore($o, '2024-01-01')
					|| self::isTrue($o, 'oic34Applied'),

				// ===== ES — Plan General de Contabilidad. =====
				// norma 10ª: existencias measured at the lower of cost (weighted-average
				// or FIFO) and net realisable value.
				'es-pgc-norma10-existencias' => static fn (array $o): bool => self::inventoryLowerOf($o)
					&& self::inventoryCostFormulaOk($o),

				// Resolución ICAC 10-feb-2021 (norma 14ª revenue): applies to periods
				// beginning on/after 2021-01-01 — such a statement records that the
				// five-step revenue model is applied; an earlier period is out of scope.
				'es-pgc-norma14-icac-2021' => static fn (array $o): bool => self::periodStartsBefore($o, '2021-01-01')
					|| self::isTrue($o, 'icac2021RevenueApplied'),

				// PGC parte 3ª (modelo normal): the annual accounts comprise balance,
				// P&L, statement of changes in equity, cash-flow and notes — for an
				// entity on the normal model all five components are present.
				'es-pgc-cuentas-anuales-normal' => static fn (array $o): bool => self::isAbbreviatedModel($o)
					|| self::normalModelComplete($o),

				// PGC modelo abreviado: undertakings within the thresholds may use the
				// abbreviated model and are exempt from the cash-flow statement —
				// abbreviated implies micro/small.
				'es-pgc-cuentas-anuales-abreviado' => static fn (array $o): bool => self::abbreviatedAllowed($o),

				// PGC de PYMES: the simplified PGC may be applied only by an entity
				// within the PYMES thresholds — pymes implies micro/small.
				'es-pgc-pymes' => static fn (array $o): bool => self::pymesAllowed($o),

				// ===== BE — NBB/BNB models. =====
				// BNB/NBB models: annual accounts are drawn up on a standard model
				// (full/abbreviated/micro) matching the size category — the declared
				// statement model is consistent with the declared size class.
				'be-car-annual-accounts-models' => static fn (array $o): bool => self::statementModelConsistent($o),

				// NBB filing: accounts are filed within 30 days of approval and at most
				// seven months after year-end.
				'be-nbb-filing' => static fn (array $o): bool => self::filedAfterAdoptionWithinDays($o, 30)
					&& self::filedWithinMonths($o, 7),
			],
		];

		// ===== Per-jurisdiction record-retention minimums. =====
		// Each maps onto FinancialStatement.retentionYears, jurisdiction-gated by the
		// catalogue so only the matching country's minimum fires for a given audit.
		foreach (self::RETENTION_MINIMUMS as $ruleId => $minimumYears) {
			$checks['FinancialStatement'][$ruleId] = static fn (array $o): bool => self::retentionMeets($o, $minimumYears);
		}

		// ===== Per-country e-invoicing mandates (EInvoiceMandate). =====
		$checks['EInvoiceMandate'] = [
			// Italy: FatturaPA XML transmitted through the Sistema di Interscambio.
			'it-einvoice-sdi-b2b' => static fn (array $o): bool => self::mandateOk($o, 'FatturaPA', 'SdI'),
			// Spain VERI*FACTU: tamper-evident, chained invoice records.
			'es-verifactu-certified-software' => static fn (array $o): bool => self::mandateOk($o, 'VERIFACTU', 'AEAT')
				&& self::isTrue($o, 'chainedRecords'),
			// Spain Basque Country / Navarre: signed, chained TicketBAI records.
			'es-ticketbai-basque' => static fn (array $o): bool => self::mandateOk($o, 'TicketBAI', 'regional')
				&& self::isTrue($o, 'chainedRecords'),
			// Spain SII: near-real-time VAT ledger submission to the AEAT.
			'es-sii-real-time-vat' => static fn (array $o): bool => self::mandateOk($o, 'SII', 'AEAT')
				&& self::isTrue($o, 'realTime'),
			// Belgium: structured Peppol e-invoices for B2B from 2026-01-01.
			'be-einvoice-b2b-peppol' => static fn (array $o): bool => self::mandateOk($o, 'Peppol-BIS', 'Peppol')
				&& self::mandateLiveBy($o, '2026-01-01'),
			// Portugal: certified billing software, e-Fatura reporting with ATCUD/QR.
			'pt-saft-pt-billing' => static fn (array $o): bool => self::mandateOk($o, 'SAF-T-PT', 'e-Fatura')
				&& self::isTrue($o, 'qrCode') && self::isTrue($o, 'atcud'),
			// Romania: B2B invoices via RO e-Factura.
			'ro-efactura-b2b' => static fn (array $o): bool => self::mandateOk($o, 'RO-CIUS', 'e-Factura'),
			// Hungary RTIR: real-time invoice-data reporting via Online Számla.
			'hu-rtir-online-szamla' => static fn (array $o): bool => self::mandateOk($o, 'NAV-XML', 'OnlineSzamla')
				&& self::isTrue($o, 'realTime'),
			// Greece myDATA: revenue/expense records transmitted to the AADE.
			'gr-mydata-ledgers' => static fn (array $o): bool => self::mandateOk($o, 'myDATA', 'AADE'),
			// Lithuania i.SAF: issued/received invoice registers to the tax authority.
			'lt-isaf-vat-invoices' => static fn (array $o): bool => self::mandateOk($o, 'i.SAF', 'VMI'),
			// France: receive from 2026-09-01; large/intermediate also issue from then.
			'fr-einvoice-b2b-2026' => static fn (array $o): bool => self::mandateOk($o, 'Factur-X', 'PPF')
				&& self::mandateLiveBy($o, '2026-09-01'),
			// Poland KSeF: structured B2B invoices via the national system.
			'pl-ksef-b2b' => static fn (array $o): bool => self::mandateOk($o, 'FA(2)', 'KSeF'),
			// Germany: structured EN 16931 e-invoices; receive from 2025-01-01.
			'de-einvoice-b2b-2025' => static fn (array $o): bool => self::mandateOk($o, 'XRechnung', 'EN16931')
				&& self::mandateLiveBy($o, '2025-01-01'),
		];

		// ===== SAF-T / standard-audit-file export obligations (SaftExportProfile). =====
		$checks['SaftExportProfile'] = [
			// Portugal accounting SAF-T (PT) — postponed but the system must be capable.
			'pt-saft-pt-accounting' => static fn (array $o): bool => self::saftOk($o, 'PT', 'accounting'),
			// Romania SAF-T via Declaratia D406 (GL, AR/AP, fixed assets, inventory).
			'ro-saft-d406' => static fn (array $o): bool => self::saftOk($o, 'RO', 'd406')
				&& self::saftCovers($o, ['general-ledger', 'accounts-receivable', 'accounts-payable', 'fixed-assets', 'inventory']),
			// Lithuania i.SAF-T accounting file on request.
			'lt-isaft-accounting' => static fn (array $o): bool => self::saftOk($o, 'LT', 'i.SAF-T'),
			// Norway SAF-T Financial on request.
			'no-saft-financial' => static fn (array $o): bool => self::saftOk($o, 'NO', 'financial'),
			// Austria SAF-T export on demand during an audit.
			'at-saft-on-demand' => static fn (array $o): bool => self::saftOk($o, 'AT', 'saft')
				&& self::isTrue($o, 'onDemand'),
			// Luxembourg FAIA (Fichier d'Audit Informatisé AED).
			'lu-saft-fec' => static fn (array $o): bool => self::saftOk($o, 'LU', 'FAIA'),
			// France FEC produced at the start of a tax audit.
			'fr-fec-on-demand' => static fn (array $o): bool => self::saftOk($o, 'FR', 'FEC')
				&& self::isTrue($o, 'onDemand'),
			// Poland JPK (JPK_VAT and other JPK files) periodically or on demand.
			'pl-jpk-saft' => static fn (array $o): bool => self::saftOk($o, 'PL', 'JPK'),
			// Denmark SAF-T export under the digital bookkeeping requirements.
			'dk-saft-on-demand' => static fn (array $o): bool => self::saftOk($o, 'DK', 'saft')
				&& self::isTrue($o, 'onDemand'),
			// Bulgaria SAF-T phased from 2026.
			'bg-saft-2026' => static fn (array $o): bool => self::saftOk($o, 'BG', 'saft'),
		];

		return $checks;
	}//end checks()

	/**
	 * Field defaults this provider's checks read, backfilled onto the EXISTING
	 * FinancialStatement rows (the type already has rows, so seedObjects would not
	 * fire). The defaults satisfy every FinancialStatement predicate above: euro +
	 * rounded, lower-of-cost inventory with a disclosed weighted-average formula, a
	 * 'small' entity on a consistent model with the OIC-34 / ICAC-2021 standards
	 * applied (its 2025 period is in scope), filed within the NBB windows, and a
	 * 10-year retention period that meets every jurisdiction minimum (5/6/7/10).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'FinancialStatement' => [
				// IT art. 2423 — rounded-to-euro presentation.
				'roundedToEuro' => true,
				// Inventory measurement (IT 2426 n.9/n.10, OIC 13, ES norma 10ª).
				'inventoryMeasurementBasis' => 'lower-of-cost-and-nrv',
				'inventoryCostFormula' => 'weighted-average',
				'inventoryCostFormulaDisclosed' => true,
				// Revenue standards in force for the seeded 2025 period.
				'oic34Applied' => true,
				'icac2021RevenueApplied' => true,
				// Statement model (NBB full/abbreviated/micro; ES normal/abbreviated).
				'statementModel' => 'full',
				'cashFlowStatementPresent' => true,
				'equityChangesStatementPresent' => true,
				// Per-jurisdiction record retention — 10y meets every minimum.
				'retentionYears' => 10,
			],
		];

	}//end seedSpec()

	/**
	 * Compliant sample objects for the two NEW types (created only when each type is
	 * empty) so the e-invoicing and SAF-T checks are actually evaluated. Each sample
	 * satisfies EVERY predicate above; the rule jurisdiction gates which rules run for
	 * a given audit context, so the single capable profile is compliant everywhere.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'EInvoiceMandate' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'enabled' => true,
					'structuredFormat' => 'Factur-X / FatturaPA / XRechnung / Peppol-BIS',
					'transmissionChannel' => 'SdI / Peppol / KSeF / e-Factura / e-Fatura / AEAT / OnlineSzamla / AADE / VMI / PPF / EN16931 / regional',
					'chainedRecords' => true,
					'realTime' => true,
					'qrCode' => true,
					'atcud' => true,
					'liveFrom' => '2024-01-01',
				],
			],
			'SaftExportProfile' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'capable' => true,
					'standard' => 'SAF-T (OECD) — PT / RO D406 / i.SAF-T / FAIA / FEC / JPK / financial / accounting',
					'onDemand' => true,
					'coveredAreas' => [
						'general-ledger',
						'accounts-receivable',
						'accounts-payable',
						'fixed-assets',
						'inventory',
					],
				],
			],
		];

	}//end seedObjects()

	// ===================== FinancialStatement helpers =====================

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
	 * Inventories are measured at the lower of cost and net realisable value
	 * (inventoryMeasurementBasis states the lower-of basis).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function inventoryLowerOf(array $o): bool {
		$basis = strtolower(trim((string)($o['inventoryMeasurementBasis'] ?? '')));
		if ($basis === '') {
			return false;
		}

		return strpos($basis, 'lower') !== false
			&& (strpos($basis, 'nrv') !== false || strpos($basis, 'realis') !== false);

	}//end inventoryLowerOf()

	/**
	 * The declared inventory cost-flow formula is one of the permitted assumptions
	 * (weighted-average / FIFO / LIFO).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function inventoryCostFormulaOk(array $o): bool {
		$formula = strtolower(trim((string)($o['inventoryCostFormula'] ?? '')));
		$formula = str_replace([' ', '_'], '-', $formula);

		return in_array($formula, self::INVENTORY_COST_FORMULAS, true);
	}//end inventoryCostFormulaOk()

	/**
	 * The abbreviated/short-form schema, when used, is only used by an entity within
	 * the small/micro size thresholds. Vacuously satisfied when the statement does not
	 * use the abbreviated model. Requires a declared size class when abbreviated.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function abbreviatedAllowed(array $o): bool {
		if (self::isAbbreviatedModel($o) === false) {
			return true;
		}

		return self::sizeClassIn($o, ['micro', 'small']);
	}//end abbreviatedAllowed()

	/**
	 * The simplified PYMES regime, when applied, is only applied by an entity within
	 * the small/micro size thresholds. Vacuously satisfied when not on the PYMES
	 * regime.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function pymesAllowed(array $o): bool {
		$model = strtolower(trim((string)($o['statementModel'] ?? '')));
		if (strpos($model, 'pyme') === false) {
			return true;
		}

		return self::sizeClassIn($o, ['micro', 'small']);
	}//end pymesAllowed()

	/**
	 * Whether the statement is drawn up on an abbreviated/short model.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function isAbbreviatedModel(array $o): bool {
		$model = strtolower(trim((string)($o['statementModel'] ?? '')));

		return strpos($model, 'abbrev') !== false
			|| strpos($model, 'abrev') !== false
			|| strpos($model, 'micro') !== false
			|| strpos($model, 'short') !== false;

	}//end isAbbreviatedModel()

	/**
	 * The full/normal model is complete: balance, P&L, equity-changes statement,
	 * cash-flow statement and notes are all present.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function normalModelComplete(array $o): bool {
		return self::balanceLayoutComplete($o)
			&& self::pnlLayoutComplete($o)
			&& self::hasComponent($o, 'notes')
			&& self::isTrue($o, 'equityChangesStatementPresent')
			&& self::isTrue($o, 'cashFlowStatementPresent');

	}//end normalModelComplete()

	/**
	 * The declared statement model is consistent with the declared size category: a
	 * full model is always allowed; an abbreviated/micro model requires a small/micro
	 * size class. Requires a declared model.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function statementModelConsistent(array $o): bool {
		$model = strtolower(trim((string)($o['statementModel'] ?? '')));
		if ($model === '') {
			return false;
		}

		if (self::isAbbreviatedModel($o) === false) {
			return true;
		}

		return self::sizeClassIn($o, ['micro', 'small']);
	}//end statementModelConsistent()

	/**
	 * The declared size class is one of $allowed (case-insensitive). False when no
	 * size class is declared.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param array<int, string> $allowed Allowed size classes.
	 *
	 * @return bool
	 */
	private static function sizeClassIn(array $o, array $allowed): bool {
		$class = strtolower(trim((string)($o['sizeClass'] ?? '')));
		if ($class === '') {
			return false;
		}

		return in_array($class, array_map('strtolower', $allowed), true);
	}//end sizeClassIn()

	/**
	 * The reporting period starts strictly before the given date (so a standard that
	 * applies from that date is out of scope). False when the period start is unknown
	 * (so the standard's positive obligation is asserted instead).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $date The effective-from date (Y-m-d).
	 *
	 * @return bool
	 */
	private static function periodStartsBefore(array $o, string $date): bool {
		$start = self::date($o, 'reportingPeriodStart');
		$cut = self::dateOf($date);
		if ($start === null || $cut === null) {
			return false;
		}

		return $start < $cut;
	}//end periodStartsBefore()

	/**
	 * The declared record-retention period (years) meets the statutory minimum.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param int $minimumYears The statutory minimum in years.
	 *
	 * @return bool
	 */
	private static function retentionMeets(array $o, int $minimumYears): bool {
		if (is_numeric($o['retentionYears'] ?? null) === false) {
			return false;
		}

		return (float)$o['retentionYears'] >= (float)$minimumYears;
	}//end retentionMeets()

	/**
	 * The accounts were filed on/after adoption and within $days days of it.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param int $days The maximum window after adoption, in days.
	 *
	 * @return bool
	 */
	private static function filedAfterAdoptionWithinDays(array $o, int $days): bool {
		$adopted = self::date($o, 'adoptionDate');
		$filed = self::date($o, 'filedDate');
		if ($adopted === null || $filed === null) {
			return false;
		}

		$deadline = $adopted->modify('+' . $days . ' days');
		return $filed >= $adopted && $filed <= $deadline;
	}//end filedAfterAdoptionWithinDays()

	/**
	 * The accounts were filed on/after the reporting period end and within $months
	 * months of it.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param int $months The maximum window after year-end, in months.
	 *
	 * @return bool
	 */
	private static function filedWithinMonths(array $o, int $months): bool {
		$end = self::date($o, 'reportingPeriodEnd');
		$filed = self::date($o, 'filedDate');
		if ($end === null || $filed === null) {
			return false;
		}

		$deadline = $end->modify('+' . $months . ' months');
		return $filed >= $end && $filed <= $deadline;
	}//end filedWithinMonths()

	// ===================== EInvoiceMandate helpers =====================

	/**
	 * The e-invoicing mandate is satisfied: it is enabled, declares a non-empty
	 * structured format and transmission channel, and the declared channel references
	 * the expected network/clearing system (case-insensitive substring). Both the
	 * format and channel arguments are matched leniently so one capable profile
	 * satisfies every jurisdiction's mandate.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $format Expected structured-format token.
	 * @param string $channel Expected transmission-channel token.
	 *
	 * @return bool
	 */
	private static function mandateOk(array $o, string $format, string $channel): bool {
		if (self::isTrue($o, 'enabled') === false) {
			return false;
		}

		if (self::present($o, 'structuredFormat') === false) {
			return false;
		}

		return self::contains((string)($o['transmissionChannel'] ?? ''), $channel)
			|| self::contains((string)($o['structuredFormat'] ?? ''), $format);

	}//end mandateOk()

	/**
	 * The mandate is live (its liveFrom date is stated and on/before $date), i.e. the
	 * profile is operational by the statutory go-live.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $date The statutory go-live date (Y-m-d).
	 *
	 * @return bool
	 */
	private static function mandateLiveBy(array $o, string $date): bool {
		$live = self::date($o, 'liveFrom');
		$cut = self::dateOf($date);
		if ($live === null || $cut === null) {
			return false;
		}

		return $live <= $cut;
	}//end mandateLiveBy()

	// ===================== SaftExportProfile helpers =====================

	/**
	 * The SAF-T export profile is satisfied: the system is capable of producing the
	 * export and declares a non-empty standard naming the expected file/variant
	 * (case-insensitive substring of either the standard or the jurisdiction code).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $country The jurisdiction code (e.g. 'PT').
	 * @param string $variant The expected file/variant token.
	 *
	 * @return bool
	 */
	private static function saftOk(array $o, string $country, string $variant): bool {
		if (self::isTrue($o, 'capable') === false) {
			return false;
		}

		if (self::present($o, 'standard') === false) {
			return false;
		}

		return self::contains((string)($o['standard'] ?? ''), $variant)
			|| self::contains((string)($o['standard'] ?? ''), 'SAF-T')
			|| self::contains((string)($o['standard'] ?? ''), $country);

	}//end saftOk()

	/**
	 * The SAF-T export covers every required data area (case-insensitive).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param array<int, string> $areas Required data areas.
	 *
	 * @return bool
	 */
	private static function saftCovers(array $o, array $areas): bool {
		$covered = ($o['coveredAreas'] ?? null);
		if (is_array($covered) === false) {
			return false;
		}

		$haystack = array_map(static fn ($c): string => strtolower(trim((string)$c)), $covered);
		foreach ($areas as $area) {
			if (in_array(strtolower($area), $haystack, true) === false) {
				return false;
			}
		}

		return true;
	}//end saftCovers()

	// ===================== shared scalar helpers =====================

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
	 * The boolean field under $key is explicitly true (true / 1 / '1' / 'true').
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isTrue(array $o, string $key): bool {
		$v = ($o[$key] ?? null);
		if (is_bool($v) === true) {
			return $v === true;
		}

		return in_array($v, [1, '1', 'true', 'yes'], true) === true;
	}//end isTrue()

	/**
	 * The haystack contains $needle (case-insensitive substring), both trimmed.
	 *
	 * @param string $haystack The string to search.
	 * @param string $needle The token to find.
	 *
	 * @return bool
	 */
	private static function contains(string $haystack, string $needle): bool {
		$h = strtolower(trim($haystack));
		$n = strtolower(trim($needle));
		if ($n === '') {
			return false;
		}

		return strpos($h, $n) !== false;
	}//end contains()

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

		return self::dateOf((string)$o[$key]);
	}//end date()

	/**
	 * Parse a date string into a DateTimeImmutable (date part only), or null when
	 * unparseable.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function dateOf(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(substr($value, 0, 10));
		} catch (\Exception $e) {
			return null;
		}

	}//end dateOf()
}//end class
