<?php

/**
 * Unit tests for the add-shillinq-multi-currency T4 envelope register fragment (ADR-037).
 *
 * Verifies the fragment declares the FxRate schema (REQ-MC-002) with the
 * composite-key uniqueness clause, the additive GLLine multi-currency
 * overlay (MODIFIED REQ-GL-003), the two ScheduledWorkflow records that
 * drive ECB ingestion (REQ-MC-003) + period-end revaluation (REQ-MC-004),
 * and the IAS 21 translation Mapping (REQ-MC-005). Also asserts the
 * matching manifest fragment ships the FXRates index + detail pages
 * (REQ-MC-006).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Structural tests for the multi-currency T4 envelope register fragment.
 */
final class MultiCurrencyT4FragmentTest extends TestCase {
	/**
	 * Decoded register fragment.
	 *
	 * @var array<string,mixed>
	 */
	private array $fragment;

	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-multi-currency-t4.json';
		self::assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($decoded);
		$this->fragment = $decoded;
	}

	/**
	 * REQ-MC-002 — FxRate schema with composite uniqueness on
	 * (transactionCurrency, baseCurrency, date, source).
	 */
	public function testFxRateSchemaDeclaresRequiredFieldsAndUniqueness(): void {
		$schemas = ($this->fragment['components']['schemas'] ?? []);
		self::assertArrayHasKey('FxRate', $schemas);

		$fx = $schemas['FxRate'];
		foreach (['transactionCurrency', 'baseCurrency', 'date', 'source', 'rate'] as $field) {
			self::assertContains($field, $fx['required']);
			self::assertArrayHasKey($field, $fx['properties']);
		}

		self::assertSame(
			['ecb', 'manual', 'bank-feed'],
			$fx['properties']['source']['enum'],
			'FxRate.source enum MUST cover ecb / manual / bank-feed provenance'
		);

		self::assertSame('^[A-Z]{3}$', $fx['properties']['transactionCurrency']['pattern']);
		self::assertSame('^[A-Z]{3}$', $fx['properties']['baseCurrency']['pattern']);

		$unique = $fx['x-openregister-uniqueness']['compositeKey'];
		self::assertEqualsCanonicalizing(
			['transactionCurrency', 'baseCurrency', 'date', 'source'],
			$unique,
			'Composite key MUST match REQ-MC-002 uniqueness clause'
		);

		self::assertTrue(
			($fx['x-openregister-audit-trail']['enabled'] ?? false),
			'FxRate MUST carry x-openregister-audit-trail.enabled=true'
		);

		// schema.org annotation per REQ-MC-002 alignment with ExchangeRateSpecification.
		self::assertSame('schema:ExchangeRateSpecification', $fx['x-schema-org']);
	}

	/**
	 * MODIFIED REQ-GL-003 — GLLine overlay declares the multi-currency field set
	 * additively. The existing single-currency `amount` + `currency` survives in
	 * the base schema; the overlay adds the new semantic fields without rename.
	 */
	public function testGlLineOverlayDeclaresMultiCurrencyFields(): void {
		$schemas = ($this->fragment['components']['schemas'] ?? []);
		self::assertArrayHasKey('GLLine', $schemas);

		$props = $schemas['GLLine']['properties'];
		foreach (['transactionAmount', 'transactionCurrency', 'baseCurrencyAmount', 'baseCurrency', 'fxRate', 'fxRateSource', 'fxRateDate'] as $field) {
			self::assertArrayHasKey(
				$field,
				$props,
				'GLLine multi-currency overlay MUST declare ' . $field . ' per MODIFIED REQ-GL-003'
			);
		}

		// FX orientation contract: fxRate is a non-negative number.
		self::assertSame('number', $props['fxRate']['type']);
		self::assertSame(0, $props['fxRate']['minimum']);

		// fxRateSource enum mirrors FxRate.source so the audit join is unambiguous.
		self::assertSame(['ecb', 'manual', 'bank-feed'], $props['fxRateSource']['enum']);
	}

	/**
	 * REQ-MC-003 — ECB daily ingestion declared as a ScheduledWorkflow record
	 * (NOT an FxRateImportJob extends TimedJob).
	 */
	public function testScheduledWorkflowEcbDailyIngest(): void {
		$workflows = ($this->fragment['x-openregister-scheduled-workflows'] ?? []);
		self::assertArrayHasKey('shillinq-fx-ecb-daily-ingest', $workflows);

		$wf = $workflows['shillinq-fx-ecb-daily-ingest'];
		self::assertStringContainsString('openconnector://ecb-eurofxref-daily', $wf['connector']);
		self::assertSame('ingest-fxrates', $wf['action']['type']);
		self::assertSame('FxRate', $wf['action']['targetSchema']);
		self::assertSame('invert-on-ingest', $wf['action']['orientation']);
		self::assertSame('ecb', $wf['action']['defaultSource']);
		self::assertSame('EUR', $wf['action']['defaultBaseCurrency']);
	}

	/**
	 * REQ-MC-004 — period-end FX revaluation declared as a ScheduledWorkflow
	 * record triggered by PeriodStatus.softClose (NOT an FxRevaluationService
	 * PHP class).
	 */
	public function testScheduledWorkflowPeriodEndRevaluation(): void {
		$workflows = ($this->fragment['x-openregister-scheduled-workflows'] ?? []);
		self::assertArrayHasKey('shillinq-fx-period-end-revaluation', $workflows);

		$wf = $workflows['shillinq-fx-period-end-revaluation'];
		self::assertSame('PeriodStatus.transition.softClose', $wf['trigger']);
		self::assertSame('revalue-fx-positions', $wf['action']['type']);
		self::assertEqualsCanonicalizing(
			['ARInvoice', 'APInvoice', 'BankAccount', 'CurrencyBalance'],
			$wf['action']['targetRegisters']
		);
		// P&L impact lands on dedicated 8920 (gain) / 8921 (loss) GL accounts.
		self::assertSame('8920', $wf['action']['gainGlAccount']);
		self::assertSame('8921', $wf['action']['lossGlAccount']);
	}

	/**
	 * REQ-MC-005 — IAS 21 / RJ 122 translation declared as an OR Mapping
	 * (NOT a ConsolidationTranslationService PHP class).
	 */
	public function testIas21MappingDeclaresRateSelectors(): void {
		$mappings = ($this->fragment['x-openregister-mappings'] ?? []);
		self::assertArrayHasKey('ias21-translation-functional-to-presentation', $mappings);

		$mapping = $mappings['ias21-translation-functional-to-presentation'];
		self::assertSame('FxRate', $mapping['sourceSchema']);
		self::assertSame('GLLine', $mapping['targetSchema']);

		$selectors = array_column($mapping['rules'], 'rateSelector', 'appliesTo');
		self::assertSame('closing-rate', $selectors['balanceSheet']);
		self::assertSame('average-rate', $selectors['incomeStatement']);
		self::assertSame('historical-rate', $selectors['equity']);

		self::assertSame('3990', $mapping['ctaPosting']['glAccount']);
		self::assertSame('credit-on-gain', $mapping['ctaPosting']['side']);
	}

	/**
	 * REQ-MC-002 — seed FxRate objects so a fresh administratie sees example
	 * rates for the demo USD + GBP corridors in design D1.
	 */
	public function testSeedFxRatesPresent(): void {
		$objects = ($this->fragment['objects'] ?? []);
		self::assertGreaterThanOrEqual(2, count($objects));

		$byPair = static function (array $items, string $tx, string $base) {
			foreach ($items as $item) {
				if (($item['transactionCurrency'] ?? '') === $tx && ($item['baseCurrency'] ?? '') === $base) {
					return $item;
				}
			}
			return null;
		};

		$usdEur = $byPair($objects, 'USD', 'EUR');
		self::assertIsArray($usdEur, 'USD-EUR seed rate MUST be present');
		self::assertSame('ecb', $usdEur['source']);
		self::assertGreaterThan(0, $usdEur['rate']);

		$gbpEur = $byPair($objects, 'GBP', 'EUR');
		self::assertIsArray($gbpEur, 'GBP-EUR seed rate MUST be present');
		self::assertSame('ecb', $gbpEur['source']);
	}

	/**
	 * REQ-MC-006 — manifest carries the FXRates index + detail pages and adds
	 * the FXRates child to the existing BookkeepingMultiCurrency menu group
	 * (rather than declaring a parallel menu — handoff spec line 22).
	 */
	public function testManifestFragmentDeclaresFxRatesPages(): void {
		$path = __DIR__ . '/../../../src/manifest.d/add-shillinq-multi-currency-t4.json';
		self::assertFileExists($path);

		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($decoded);

		$hasNav = false;
		foreach (($decoded['menu'] ?? []) as $menu) {
			if (($menu['id'] ?? '') !== 'BookkeepingMultiCurrency') {
				continue;
			}
			foreach (($menu['children'] ?? []) as $child) {
				if (($child['id'] ?? '') === 'FXRates') {
					$hasNav = true;
					self::assertSame('FXRates', $child['route']);
				}
			}
		}
		self::assertTrue(
			$hasNav,
			'BookkeepingMultiCurrency menu MUST carry FXRates child per REQ-MC-006'
		);

		$pageIds = array_map(static fn ($p) => $p['id'] ?? '', ($decoded['pages'] ?? []));
		self::assertContains('FXRates', $pageIds);
		self::assertContains('FXRateDetail', $pageIds);

		foreach (($decoded['pages'] ?? []) as $page) {
			if (in_array($page['id'] ?? '', ['FXRates', 'FXRateDetail'], true)) {
				self::assertSame('FxRate', $page['config']['schema']);
			}
		}
	}
}//end class
