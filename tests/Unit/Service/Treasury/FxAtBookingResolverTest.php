<?php

/**
 * Unit tests for FxAtBookingResolver: which FxRate a booking date gets, when
 * it is refused, the base currency of an administration and the rounding.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-fx-at-booking/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\Shillinq\Service\Treasury\FxAtBookingResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The resolver over an in-memory register.
 */
#[CoversClass(FxAtBookingResolver::class)]
#[UsesClass(ObjectIdentifier::class)]
final class FxAtBookingResolverTest extends TestCase {
	/**
	 * The design's seed rates: USD on Friday 2026-09-25, GBP on 2026-09-18.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const SEED_RATES = [
		[
			'id' => 'fx-usd', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
			'date' => '2026-09-25', 'source' => 'manual', 'rate' => 0.9150, 'manualOverrideReason' => 'ECB-koers overgenomen',
		],
		['id' => 'fx-gbp', 'transactionCurrency' => 'GBP', 'baseCurrency' => 'EUR', 'date' => '2026-09-18', 'source' => 'ecb', 'rate' => 1.1830],
	];

	/**
	 * A resolver over the given rates and administrations.
	 *
	 * @param array<int,array<string,mixed>> $rates           FxRate rows.
	 * @param int                            $maxAge          The configured maximum age (0 = unset).
	 * @param array<int,array<string,mixed>> $administrations Administration rows.
	 *
	 * @return FxAtBookingResolver
	 */
	private function resolver(array $rates, int $maxAge = 0, array $administrations = []): FxAtBookingResolver {
		$store = new InMemoryObjectServiceStub(['FxRate' => $rates, 'Administration' => $administrations]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) use ($maxAge): int {
				if ($key === FxAtBookingResolver::CFG_MAX_RATE_AGE_DAYS) {
					return $maxAge;
				}

				return $default;
			}
		);
		return new FxAtBookingResolver($store, $appConfig);

	}//end resolver()

	/**
	 * A rate dated on the booking date is used.
	 *
	 * @return void
	 */
	public function testASameDayRateIsUsed(): void {
		$rates = self::SEED_RATES;
		$rates[
			] = ['id' => 'fx-usd-2', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
			'date' => '2026-09-28', 'source' => 'ecb', 'rate' => 0.9200,
		];

		$this->assertSame(
			['rate' => 0.92, 'source' => 'ecb', 'date' => '2026-09-28'],
			$this->resolver($rates)->resolve('USD', 'EUR', '2026-09-28', 'adm-1')
		);

	}//end testASameDayRateIsUsed()

	/**
	 * A Sunday booking takes Friday's rate, and a later rate is not looked ahead to.
	 *
	 * @return void
	 */
	public function testAWeekendBookingTakesTheNewestEarlierRate(): void {
		$rates = self::SEED_RATES;
		$rates[
			] = ['id' => 'fx-usd-old', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
			'date' => '2026-09-24', 'source' => 'ecb', 'rate' => 0.9000,
		];
		$rates[
			] = ['id' => 'fx-usd-next', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
			'date' => '2026-09-28', 'source' => 'ecb', 'rate' => 0.9300,
		];

		$this->assertSame(
			['rate' => 0.915, 'source' => 'manual', 'date' => '2026-09-25'],
			$this->resolver($rates)->resolve('USD', 'EUR', '2026-09-27', 'adm-1')
		);

	}//end testAWeekendBookingTakesTheNewestEarlierRate()

	/**
	 * On the same date the administration's own rate wins over a shared one, and
	 * another administration's rate is never used.
	 *
	 * @return void
	 */
	public function testAnAdministrationScopedRateWinsOverASharedOne(): void {
		$rates = [
			['id' => 'shared', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-09-25', 'source' => 'ecb', 'rate' => 0.9150],
			[
				'id' => 'own', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
				'date' => '2026-09-25', 'source' => 'manual', 'rate' => 0.9100, 'administrationId' => 'adm-1',
			],
			[
				'id' => 'other', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
				'date' => '2026-09-26', 'source' => 'ecb', 'rate' => 0.9999, 'administrationId' => 'adm-2',
			],
		];

		$this->assertSame(0.91, $this->resolver($rates)->resolve('USD', 'EUR', '2026-09-27', 'adm-1')['rate']);
		$this->assertSame(0.9999, $this->resolver($rates)->resolve('USD', 'EUR', '2026-09-27', 'adm-2')['rate']);
		$this->assertSame(0.915, $this->resolver($rates)->resolve('USD', 'EUR', '2026-09-27', 'adm-3')['rate']);

	}//end testAnAdministrationScopedRateWinsOverASharedOne()

	/**
	 * On the same date and scope ecb wins over bank-feed over manual.
	 *
	 * @return void
	 */
	public function testOnTheSameDateEcbWinsOverBankFeedOverManual(): void {
		$rate = static fn (string $id, string $source, float $value): array => [
			'id' => $id, 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-09-25', 'source' => $source, 'rate' => $value,
		];

		$all = $this->resolver([$rate('a', 'manual', 0.91), $rate('b', 'ecb', 0.92), $rate('c', 'bank-feed', 0.93)]);
		$this->assertSame('ecb', $all->resolve('USD', 'EUR', '2026-09-25', 'adm-1')['source']);
		$noEcb = $this->resolver([$rate('a', 'manual', 0.91), $rate('c', 'bank-feed', 0.93)]);
		$this->assertSame('bank-feed', $noEcb->resolve('USD', 'EUR', '2026-09-25', 'adm-1')['source']);

	}//end testOnTheSameDateEcbWinsOverBankFeedOverManual()

	/**
	 * A rate older than the allowed age is refused, naming the pair and the dates.
	 *
	 * @return void
	 */
	public function testAStaleRateIsRefused(): void {
		try {
			$this->resolver(self::SEED_RATES)->resolve('GBP', 'EUR', '2026-09-27', 'adm-1');
			$this->fail('a nine-day-old rate was used');
		} catch (RuntimeException $e) {
			$this->assertSame(
				'The newest GBP to EUR exchange rate is dated 2026-09-18, 9 days before the booking date 2026-09-27 (at most 5 allowed). '
				. 'Enter a rate under Bookkeeping > Multi-currency > FX rates, or type the rate on the line.',
				$e->getMessage()
			);
		}

	}//end testAStaleRateIsRefused()

	/**
	 * The configured age replaces the default.
	 *
	 * @return void
	 */
	public function testTheConfiguredMaximumAgeApplies(): void {
		$this->assertSame(1.183, $this->resolver(self::SEED_RATES, 10)->resolve('GBP', 'EUR', '2026-09-27', 'adm-1')['rate']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('(at most 1 allowed)');
		$this->resolver(self::SEED_RATES, 1)->resolve('USD', 'EUR', '2026-09-27', 'adm-1');

	}//end testTheConfiguredMaximumAgeApplies()

	/**
	 * No rate on or before the date is refused; unusable rows do not count.
	 *
	 * @return void
	 */
	public function testAMissingRateIsRefused(): void {
		$rates = [
			['id' => 'zero', 'transactionCurrency' => 'CHF', 'baseCurrency' => 'EUR', 'date' => '2026-09-25', 'source' => 'ecb', 'rate' => 0],
			['id' => 'undated', 'transactionCurrency' => 'CHF', 'baseCurrency' => 'EUR', 'date' => '', 'source' => 'ecb', 'rate' => 1.05],
			['id' => 'later', 'transactionCurrency' => 'CHF', 'baseCurrency' => 'EUR', 'date' => '2026-09-30', 'source' => 'ecb', 'rate' => 1.05],
			['id' => 'other-base', 'transactionCurrency' => 'CHF', 'baseCurrency' => 'USD', 'date' => '2026-09-25', 'source' => 'ecb', 'rate' => 1.1],
		];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'No CHF to EUR exchange rate exists on or before 2026-09-27. '
			. 'Enter one under Bookkeeping > Multi-currency > FX rates, or type the rate on the line.'
		);
		$this->resolver($rates)->resolve('CHF', 'EUR', '2026-09-27', 'adm-1');

	}//end testAMissingRateIsRefused()

	/**
	 * The base currency is the administration's functional currency, EUR when it names none.
	 *
	 * @return void
	 */
	public function testTheBaseCurrencyIsTheAdministrationsFunctionalCurrency(): void {
		$resolver = $this->resolver(
			[],
			0,
			[
				['id' => 'adm-us', 'functionalCurrency' => 'usd'],
				['id' => 'adm-nl', 'functionalCurrency' => ''],
			]
		);

		$this->assertSame('USD', $resolver->baseCurrencyFor('adm-us'));
		$this->assertSame('EUR', $resolver->baseCurrencyFor('adm-nl'));
		$this->assertSame('EUR', $resolver->baseCurrencyFor('adm-unknown'));

	}//end testTheBaseCurrencyIsTheAdministrationsFunctionalCurrency()

	/**
	 * Base cents are the transaction cents times the rate, rounded half up.
	 *
	 * @return void
	 */
	public function testBaseCentsRoundHalfUp(): void {
		$this->assertSame(91500, FxAtBookingResolver::toBaseCents(100000, 0.9150));
		$this->assertSame(1820400, FxAtBookingResolver::toBaseCents(2000000, 0.9102));
		$this->assertSame(2, FxAtBookingResolver::toBaseCents(3, 0.5));
		$this->assertSame(1, FxAtBookingResolver::toBaseCents(3, 0.4));

	}//end testBaseCentsRoundHalfUp()
}//end class
