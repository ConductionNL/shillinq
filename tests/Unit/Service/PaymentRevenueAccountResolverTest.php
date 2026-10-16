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
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping, and above all what an absent or broken mapping answers
 * (REQ-SOPR-002).
 */
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
}//end class
