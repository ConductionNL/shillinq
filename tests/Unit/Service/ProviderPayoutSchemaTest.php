<?php

/**
 * The ProviderPayout schema, its declaration in the register, and its seeds.
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * A provider payout is a schema of the shillinq register with seeds that add up.
 */
final class ProviderPayoutSchemaTest extends TestCase {

	private const SETTINGS_DIR = __DIR__ . '/../../../lib/Settings';

	/**
	 * The seeds of the fragment for one schema, without their @self block.
	 *
	 * @param string $schema The schema of the seeds.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function seeds(string $schema='ProviderPayout'): array {
		$fragment = json_decode(
			json: (string)file_get_contents(filename: self::SETTINGS_DIR . '/register.d/receivables-provider-payouts.json'),
			associative: true
		);
		$seeds = [];
		foreach ($fragment['objects'] as $seed) {
			if ($seed['@self']['schema'] !== $schema) {
				continue;
			}

			unset($seed['@self']);
			$seeds[] = $seed;
		}

		return $seeds;

	}//end seeds()

	/**
	 * REQ-RPT-001: the register declares ProviderPayout and the merged register carries it per administration.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresProviderPayout(): void {
		$declared = json_decode(
			json: (string)file_get_contents(filename: self::SETTINGS_DIR . '/register.d/000-register-declaration.json'),
			associative: true
		);
		self::assertContains(needle: 'ProviderPayout', haystack: $declared['components']['registers']['shillinq']['schemas']);

		$schema = RegisterSchema::schema(slug: 'ProviderPayout');
		self::assertContains(needle: 'administrationId', haystack: $schema['required']);
		self::assertSame(expected: ['mollie', 'stripe'], actual: $schema['properties']['provider']['enum']);

	}//end testTheRegisterDeclaresProviderPayout()

	/**
	 * REQ-RPT-001: every seed is valid, its amounts add up and its state follows its open payments.
	 *
	 * @return void
	 */
	public function testTheSeedsAreValidAndAddUp(): void {
		$seeds = $this->seeds();
		self::assertCount(expectedCount: 3, haystack: $seeds);

		foreach ($seeds as $seed) {
			$reference = (string)$seed['settlementReference'];
			self::assertSame(expected: [], actual: RegisterSchema::errors(slug: 'ProviderPayout', object: $seed), message: $reference);
			self::assertEqualsWithDelta(
				expected: ($seed['amountGross'] - $seed['amountFees'] - $seed['amountRefunds'] - $seed['amountChargebacks']),
				actual: $seed['amountNet'],
				delta: 0.001,
				message: $reference
			);
			self::assertEqualsWithDelta(
				expected: $seed['amountGross'],
				actual: array_sum(array: array_column(array: $seed['lines'], column_key: 'amount')),
				delta: 0.001,
				message: $reference
			);
			self::assertEqualsWithDelta(
				expected: $seed['amountFees'],
				actual: array_sum(array: array_column(array: $seed['lines'], column_key: 'fee')),
				delta: 0.001,
				message: $reference
			);

			$byStatus = array_count_values(array: array_column(array: $seed['lines'], column_key: 'status'));
			self::assertSame(expected: ($byStatus['matched'] ?? 0), actual: $seed['matchedLineCount'], message: $reference);
			self::assertSame(expected: ($byStatus['unmatched'] ?? 0), actual: $seed['unmatchedLineCount'], message: $reference);
		}

	}//end testTheSeedsAreValidAndAddUp()

	/**
	 * REQ-RPT-001: a payout without its provider or amount is refused.
	 *
	 * @return void
	 */
	public function testAPayoutWithoutProviderOrAmountIsRefused(): void {
		$seed = $this->seeds()[0];
		unset($seed['provider'], $seed['amountNet']);

		self::assertNotSame(expected: [], actual: RegisterSchema::errors(slug: 'ProviderPayout', object: $seed));

	}//end testAPayoutWithoutProviderOrAmountIsRefused()
	/**
	 * REQ-RPPO-005: the register declares PaymentDevice and its seed is valid.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresPaymentDeviceWithAValidSeed(): void {
		$declared = json_decode(
			json: (string)file_get_contents(filename: self::SETTINGS_DIR . '/register.d/000-register-declaration.json'),
			associative: true
		);
		self::assertContains(needle: 'PaymentDevice', haystack: $declared['components']['registers']['shillinq']['schemas']);

		$seeds = $this->seeds(schema: 'PaymentDevice');
		self::assertCount(expectedCount: 1, haystack: $seeds);
		self::assertSame(expected: [], actual: RegisterSchema::errors(slug: 'PaymentDevice', object: $seeds[0]));
		self::assertSame(expected: 'term_example0001', actual: $seeds[0]['deviceId']);

	}//end testTheRegisterDeclaresPaymentDeviceWithAValidSeed()

	/**
	 * REQ-RPPO-004: a reconciliation match can target a provider payout.
	 *
	 * @return void
	 */
	public function testAReconciliationMatchCanTargetAProviderPayout(): void {
		$schema = RegisterSchema::schema(slug: 'ReconciliationMatch');
		self::assertContains(needle: 'provider-payout', haystack: $schema['properties']['targetType']['enum']);
		self::assertContains(needle: 'provider-payout', haystack: $schema['properties']['matchType']['enum']);
		self::assertContains(needle: 'ar-invoice', haystack: $schema['properties']['targetType']['enum']);

	}//end testAReconciliationMatchCanTargetAProviderPayout()
}//end class
