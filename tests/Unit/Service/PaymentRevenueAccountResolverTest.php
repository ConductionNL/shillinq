<?php

/**
 * Unit tests for PaymentRevenueAccountResolver.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCP\IAppConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping, and above all what an absent or broken mapping answers
 * (REQ-SOPR-002).
 */
#[CoversClass(PaymentRevenueAccountResolver::class)]
final class PaymentRevenueAccountResolverTest extends TestCase {
	/**
	 * Build the resolver over a raw config value.
	 *
	 * @param string $raw The stored app-config string.
	 *
	 * @return PaymentRevenueAccountResolver The resolver.
	 */
	private function resolver(string $raw): PaymentRevenueAccountResolver {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($raw);

		return new PaymentRevenueAccountResolver(appConfig: $appConfig);
	}//end resolver()

	/**
	 * A mapped type resolves to its account.
	 *
	 * @return void
	 */
	public function testAMappedTypeResolves(): void {
		$resolver = $this->resolver('{"leges":"8300","dwangsom":"8400"}');

		self::assertSame('8400', $resolver->resolve('dwangsom'));
	}//end testAMappedTypeResolves()

	/**
	 * An unmapped type resolves to null, never to a default account. Booking a
	 * receipt on a guessed account is the failure this null exists to prevent.
	 *
	 * @return void
	 */
	public function testAnUnmappedTypeResolvesToNull(): void {
		$resolver = $this->resolver('{"leges":"8300"}');

		self::assertNull($resolver->resolve('deposit'));
	}//end testAnUnmappedTypeResolvesToNull()

	/**
	 * An empty configuration maps nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyConfigurationMapsNothing(): void {
		$resolver = $this->resolver('');

		self::assertSame([], $resolver->all());
		self::assertNull($resolver->resolve('leges'));
	}//end testAnEmptyConfigurationMapsNothing()

	/**
	 * A configuration that is not JSON maps nothing rather than throwing on a
	 * webhook, which would turn a mistyped setting into a payment outage.
	 *
	 * @return void
	 */
	public function testAMalformedConfigurationMapsNothing(): void {
		$resolver = $this->resolver('8400');

		self::assertSame([], $resolver->all());
	}//end testAMalformedConfigurationMapsNothing()

	/**
	 * An entry mapped to an empty string is not a mapping.
	 *
	 * @return void
	 */
	public function testAnEmptyAccountIsNotAMapping(): void {
		$resolver = $this->resolver('{"leges":"  "}');

		self::assertNull($resolver->resolve('leges'));
	}//end testAnEmptyAccountIsNotAMapping()

	/**
	 * A resolver whose app config is an in-memory store, with what was
	 * written captured in $written.
	 *
	 * @param string             $raw     The starting stored value.
	 * @param array<int, string> $written Receives every value written.
	 *
	 * @return PaymentRevenueAccountResolver The resolver.
	 */
	private function storingResolver(string $raw, array &$written): PaymentRevenueAccountResolver {
		$stored = $raw;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key) use (&$stored): string {
				return ($app === 'shillinq' && $key === PaymentRevenueAccountResolver::CONFIG_KEY) ? $stored : '';
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored, &$written): bool {
				self::assertSame('shillinq', $app);
				self::assertSame(PaymentRevenueAccountResolver::CONFIG_KEY, $key);
				$stored = $value;
				$written[] = $value;
				return true;
			}
		);

		return new PaymentRevenueAccountResolver(appConfig: $appConfig);
	}//end storingResolver()

	/**
	 * Saving sets, keeps and clears per type: a submitted value replaces, an
	 * empty one removes, an absent type and an unknown key change nothing.
	 *
	 * @return void
	 */
	public function testSaveMergesTheSubmittedTypes(): void {
		$written = [];
		$resolver = $this->storingResolver('{"leges":"8300","deposit":"1600"}', $written);

		$map = $resolver->save(['dwangsom' => ' 8400 ', 'deposit' => '', 'bogus' => '9999']);

		self::assertSame(['leges' => '8300', 'dwangsom' => '8400'], $map);
		self::assertSame(['{"leges":"8300","dwangsom":"8400"}'], $written);
		self::assertSame('8400', $resolver->resolve('dwangsom'));
		self::assertNull($resolver->resolve('deposit'));
		self::assertNull($resolver->resolve('bogus'));
	}//end testSaveMergesTheSubmittedTypes()

	/**
	 * A value that is not an account number is refused, and nothing is
	 * written, not even the valid types submitted with it.
	 *
	 * @return void
	 */
	public function testSaveRefusesANonAccountAndStoresNothing(): void {
		$written = [];
		$resolver = $this->storingResolver('{"leges":"8300"}', $written);

		try {
			$resolver->save(['dwangsom' => '8400', 'other' => '84 00']);
			self::fail('A value with a space must be refused.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('"other"', $e->getMessage());
		}

		self::assertSame([], $written);
		self::assertNull($resolver->resolve('dwangsom'));
	}//end testSaveRefusesANonAccountAndStoresNothing()

	/**
	 * An array value is refused rather than cast.
	 *
	 * @return void
	 */
	public function testSaveRefusesANonScalar(): void {
		$written = [];
		$resolver = $this->storingResolver('', $written);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('"leges"');

		$resolver->save(['leges' => ['8300']]);
	}//end testSaveRefusesANonScalar()

	/**
	 * Clearing the last mapping stores an empty object, which maps nothing.
	 *
	 * @return void
	 */
	public function testClearingEveryTypeStoresAnEmptyMapping(): void {
		$written = [];
		$resolver = $this->storingResolver('{"leges":"8300"}', $written);

		self::assertSame([], $resolver->save(['leges' => '']));
		self::assertSame(['{}'], $written);
		self::assertSame([], $resolver->all());
	}//end testClearingEveryTypeStoresAnEmptyMapping()

	/**
	 * The form lists every type in a fixed order, empty where unmapped.
	 *
	 * @return void
	 */
	public function testFormListsEveryTypeEmptyWhereUnmapped(): void {
		$resolver = $this->resolver('{"clearing":"1100","dwangsom":"8400","stray":"1"}');

		self::assertSame(
			['leges' => '', 'dwangsom' => '8400', 'deposit' => '', 'other' => '', 'contribution' => '', 'event-fee' => '', 'clearing' => '1100'],
			$resolver->form()
		);
	}//end testFormListsEveryTypeEmptyWhereUnmapped()
}//end class
