<?php

/**
 * Unit tests for the bookkeeping-multi-currency register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the multi-currency fragment is valid JSON, declares the BankAccount
 * schema (with optional ISO 4217 primaryCurrency per REQ-MC-002) and the
 * CurrencyBalance register (REQ-MC-003) including its (accountId, currency)
 * uniqueness contract, documents the multi-account balance-by-currency
 * aggregation that backs REQ-MC-004 declaratively (ADR-031), enables the
 * x-openregister-audit-trail on both schemas (REQ-MC-005 audit-trail
 * scenario / REQ-AT-001), merges additively onto the monolith without
 * disturbing existing schemas (ADR-037 disjoint union), and ships
 * internally consistent EUR / USD / GBP seed pairs that satisfy the
 * design.md baseline scenarios.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MultiCurrencyFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-multi-currency.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the BankAccount schema with an optional
	 * primaryCurrency field that pattern-matches ISO 4217 (REQ-MC-002).
	 *
	 * @return void
	 */
	public function testDeclaresBankAccountWithOptionalPrimaryCurrency(): void {
		$schema = $this->fragment()['components']['schemas']['BankAccount'];
		self::assertSame('BankAccount', $schema['slug']);

		// REQ-MC-001 / REQ-MC-002 — primaryCurrency is optional (not in required[]).
		self::assertContains('accountName', $schema['required']);
		self::assertContains('iban', $schema['required']);
		self::assertNotContains(
			'primaryCurrency',
			$schema['required'],
			'primaryCurrency MUST remain optional for backward compatibility (REQ-MC-001)'
		);

		// ISO 4217 pattern enforcement.
		$prop = $schema['properties']['primaryCurrency'];
		self::assertSame('string', $prop['type']);
		self::assertSame('^[A-Z]{3}$', $prop['pattern']);

	}//end testDeclaresBankAccountWithOptionalPrimaryCurrency()

	/**
	 * The fragment declares the CurrencyBalance register with the REQ-MC-003 fields.
	 *
	 * @return void
	 */
	public function testDeclaresCurrencyBalanceSchema(): void {
		$schema = $this->fragment()['components']['schemas']['CurrencyBalance'];
		self::assertSame('CurrencyBalance', $schema['slug']);

		$expected = [
			'balanceId',
			'accountId',
			'currency',
			'balance',
			'previousBalance',
			'lastUpdated',
		];
		foreach ($expected as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "CurrencyBalance MUST declare $field");
		}

		// REQ-MC-003 — the (accountId, currency) pair is required; previousBalance is optional.
		self::assertContains('balanceId', $schema['required']);
		self::assertContains('accountId', $schema['required']);
		self::assertContains('currency', $schema['required']);
		self::assertContains('balance', $schema['required']);
		self::assertContains('lastUpdated', $schema['required']);
		self::assertNotContains('previousBalance', $schema['required']);

		// Currency must be ISO 4217.
		self::assertSame('^[A-Z]{3}$', $schema['properties']['currency']['pattern']);

	}//end testDeclaresCurrencyBalanceSchema()

	/**
	 * Both schemas enable the audit-trail (REQ-AT-001 / REQ-MC-005 detail audit-trail scenario).
	 *
	 * @return void
	 */
	public function testBothSchemasEnableAuditTrail(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['BankAccount', 'CurrencyBalance'] as $name) {
			self::assertArrayHasKey(
				'x-openregister-audit-trail',
				$schemas[$name],
				$name . ' MUST declare x-openregister-audit-trail (REQ-AT-001)'
			);
			self::assertTrue(
				$schemas[$name]['x-openregister-audit-trail']['enabled'],
				$name . ' x-openregister-audit-trail.enabled MUST be true'
			);
		}

	}//end testBothSchemasEnableAuditTrail()

	/**
	 * The multi-account balance-by-currency aggregation backs REQ-MC-004
	 * declaratively (no app-local PHP balance aggregator per ADR-031 / REQ-MC-D4).
	 *
	 * @return void
	 */
	public function testDeclaresBalanceByCurrencyAggregation(): void {
		$schema = $this->fragment()['components']['schemas']['CurrencyBalance'];
		self::assertArrayHasKey('x-openregister-aggregations', $schema);
		self::assertArrayHasKey('balanceByCurrency', $schema['x-openregister-aggregations']);

		$agg = $schema['x-openregister-aggregations']['balanceByCurrency'];
		self::assertSame(['currency'], $agg['groupBy']);

		$sumMetric = null;
		foreach ($agg['metrics'] as $metric) {
			if ($metric['field'] === 'balance' && $metric['op'] === 'sum') {
				$sumMetric = $metric;
				break;
			}
		}

		self::assertNotNull($sumMetric, 'balanceByCurrency MUST sum the `balance` field');
		self::assertSame('totalBalance', $sumMetric['as']);

	}//end testDeclaresBalanceByCurrencyAggregation()

	/**
	 * Merging the fragment over the full register.d chain adds BankAccount
	 * + CurrencyBalance without dropping existing schemas (ADR-037 disjoint
	 * union). Folds every register.d/*.json in order, as SettingsService does.
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$merged = json_decode((string)file_get_contents($this->registerPath), true);

		$fragments = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentFile) {
			$merged = $this->merge($merged, json_decode((string)file_get_contents($fragmentFile), true));
		}

		$schemas = $merged['components']['schemas'];

		// Our two schemas land.
		self::assertArrayHasKey('BankAccount', $schemas);
		self::assertArrayHasKey('CurrencyBalance', $schemas);
		// A sibling-fragment schema survives the merge.
		self::assertArrayHasKey('BcfClaim', $schemas);
		// A base-monolith schema survives.
		self::assertArrayHasKey('GLTransaction', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas, use shillinq @self envelope,
	 * carry the three EUR / USD / GBP design.md scenarios, and CurrencyBalance
	 * snapshots reference real BankAccount slugs (REQ-MC-001 / REQ-MC-005).
	 *
	 * @return void
	 */
	public function testSeedObjectsCoverEurUsdGbpAndReferToBankAccounts(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];
		$bankSlugs = [];
		$currencies = [];
		$balanceRefs = [];

		self::assertGreaterThanOrEqual(6, count($objects), 'Expect >= 6 seed objects (3 BankAccount + 3 CurrencyBalance)');

		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);

			if ($object['@self']['schema'] === 'BankAccount') {
				$bankSlugs[$object['@self']['slug']] = true;
				$currencies[$object['primaryCurrency']] = true;
			}

			if ($object['@self']['schema'] === 'CurrencyBalance') {
				$balanceRefs[$object['accountId']] = $object;
				self::assertMatchesRegularExpression(
					'/^[A-Z]{3}$/',
					$object['currency'],
					'CurrencyBalance currency MUST be ISO 4217 (REQ-MC-003)'
				);
				self::assertNotEmpty(
					$object['lastUpdated'],
					'CurrencyBalance lastUpdated MUST be set (REQ-MC-003)'
				);
			}
		}

		// Three baseline currencies represented.
		foreach (['EUR', 'USD', 'GBP'] as $iso) {
			self::assertArrayHasKey($iso, $currencies, "Expect a seed BankAccount with primaryCurrency $iso");
		}

		// Every CurrencyBalance accountId resolves to a seeded BankAccount slug
		// (FK invariant for REQ-MC-003).
		foreach ($balanceRefs as $accountId => $balance) {
			self::assertArrayHasKey(
				$accountId,
				$bankSlugs,
				'CurrencyBalance ' . $balance['@self']['slug'] . ' references unknown BankAccount ' . $accountId
			);
		}

	}//end testSeedObjectsCoverEurUsdGbpAndReferToBankAccounts()

	/**
	 * Seed CurrencyBalance pairs are unique on (accountId, currency) — the
	 * REQ-MC-003 'Prevent duplicate (accountId, currency) records' invariant.
	 *
	 * @return void
	 */
	public function testSeedCurrencyBalancesAreUniqueOnAccountCurrency(): void {
		$pairs = [];
		foreach ($this->fragment()['components']['objects'] as $object) {
			if ($object['@self']['schema'] !== 'CurrencyBalance') {
				continue;
			}

			$key = $object['accountId'] . '/' . $object['currency'];
			self::assertArrayNotHasKey(
				$key,
				$pairs,
				'Duplicate seed CurrencyBalance on (accountId, currency) = ' . $key
			);
			$pairs[$key] = true;
		}

		self::assertNotEmpty($pairs);

	}//end testSeedCurrencyBalancesAreUniqueOnAccountCurrency()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
