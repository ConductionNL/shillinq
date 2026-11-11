<?php

/**
 * Unit tests for OssRateResolver (pure-logic methods).
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
 * @spec openspec/changes/bookkeeping-btw-oss-eu/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\OssRateResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Covers REQ-OSS-001 / REQ-OSS-011 rate-in-force selection and EU-destination predicate.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssRateResolverTest extends TestCase {

	/**
	 * The resolver under test (container is never touched by the pure methods).
	 *
	 * @var OssRateResolver
	 */
	private OssRateResolver $resolver;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$this->resolver = new OssRateResolver( $appConfig,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * EU member states other than NL are OSS destinations (REQ-OSS-001).
	 *
	 * @return void
	 */
	public function testIsOssDestination(): void {
		self::assertTrue($this->resolver->isOssDestination('DE'));
		self::assertTrue($this->resolver->isOssDestination('fr'));
		self::assertFalse($this->resolver->isOssDestination('NL'));
		self::assertFalse($this->resolver->isOssDestination('US'));
		self::assertFalse($this->resolver->isOssDestination(''));

	}//end testIsOssDestination()

	/**
	 * The rate in force on a date is the row whose validity window covers it (REQ-OSS-007/011).
	 *
	 * @return void
	 */
	public function testSelectRateInForce(): void {
		$rates = [
			['ratePercentage' => 19.0, 'validFrom' => '2024-01-01', 'validUntil' => '2026-12-31', '@self' => ['slug' => 'de-2024']],
			['ratePercentage' => 20.0, 'validFrom' => '2027-01-01', 'validUntil' => null, '@self' => ['slug' => 'de-2027']],
		];

		$row = $this->resolver->selectRateInForce($rates, '2026-06-15');
		self::assertNotNull($row);
		self::assertSame(19.0, $row['ratePercentage']);

		$future = $this->resolver->selectRateInForce($rates, '2027-03-01');
		self::assertSame(20.0, $future['ratePercentage']);

	}//end testSelectRateInForce()

	/**
	 * No row in force on the invoice date resolves to null (REQ-OSS-001 missing-rate block).
	 *
	 * @return void
	 */
	public function testSelectRateInForceReturnsNullWhenMissing(): void {
		$rates = [['ratePercentage' => 19.0, 'validFrom' => '2027-01-01', 'validUntil' => null]];
		self::assertNull($this->resolver->selectRateInForce($rates, '2026-06-15'));
		self::assertNull($this->resolver->selectRateInForce([], '2026-06-15'));

	}//end testSelectRateInForceReturnsNullWhenMissing()

	// phpcs:enable CustomSniffs.Functions.NamedParameters

	/**
	 * Pin the rate choice over every bound and tie case.
	 *
	 * @return void
	 */
	public function testSelectRateInForceOverEveryBound(): void {
		$rows = [
			'a' => ['id' => 'a', 'validFrom' => '2025-01-01'],
			'b' => ['id' => 'b', 'validFrom' => '2025-06-01', 'validUntil' => '2025-12-31'],
			'c' => ['id' => 'c', 'validFrom' => '2025-06-01', 'validUntil' => ''],
			'd' => ['id' => 'd', 'validFrom' => '2026-02-01'],
			'e' => ['id' => 'e', 'validUntil' => '2030-01-01'],
			'f' => ['id' => 'f', 'validFrom' => '2025-03-01', 'validUntil' => null],
		];
		$pick = fn (array $keys, string $date): ?string => ($this->resolver->selectRateInForce(array_values(array_intersect_key($rows, array_flip($keys))), $date)['id'] ?? null);

		self::assertSame('c', $pick(['a', 'b', 'c', 'd'], '2025-07-01'));
		self::assertSame('c', $pick(['a', 'c', 'b'], '2025-07-01'));
		self::assertSame('b', $pick(['a', 'b'], '2025-12-31'));
		self::assertSame('a', $pick(['a', 'b'], '2026-01-01'));
		self::assertSame('d', $pick(['a', 'd'], '2026-02-01'));
		self::assertSame('f', $pick(['a', 'f', 'e'], '2025-04-01'));
		self::assertNull($pick(['d', 'e'], '2025-04-01'));
		self::assertNull($pick([], '2025-04-01'));

	}//end testSelectRateInForceOverEveryBound()

	/**
	 * Pin resolve over array, serialisable, wrapped and unusable rows.
	 *
	 * @return void
	 */
	public function testResolveNormalisesRowsAndQueriesExactly(): void {
		$queries = [];
		$serialisable = new class {
			public function jsonSerialize(): array {
				return ['@self' => ['slug' => 'nl-std-2026'], 'validFrom' => '2026-01-01', 'ratePercentage' => '21'];
			}
		};
		$wrapped = new class {
			public function getObject(): array {
				return ['@self' => ['id' => 'w'], 'validFrom' => '2025-01-01', 'ratePercentage' => 19];
			}
		};
		$badJson = new class {
			public function jsonSerialize(): string {
				return 'x';
			}

			public function getObject(): array {
				return ['validFrom' => '2027-01-01', 'ratePercentage' => 1];
			}
		};
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnSelf();
		$store->method('findAll')->willReturnCallback(
			function (array $config) use (&$queries, $serialisable, $wrapped, $badJson): array {
				$queries[] = $config;
				return [$wrapped, $badJson, 'scalar', $serialisable];
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$resolver = new OssRateResolver($config, objectService: $store);

		self::assertSame(
			['appliedVatRate' => 21.0, 'appliedRateCategory' => 'standard', 'tedbRateVersion' => 'nl-std-2026'],
			$resolver->resolve(' nl ', 'standard', '2026-06-01')
		);
		self::assertSame(
			['appliedVatRate' => 19.0, 'appliedRateCategory' => 'reduced', 'tedbRateVersion' => 'w'],
			$resolver->resolve('de', 'reduced', '2025-06-01')
		);
		self::assertNull($resolver->resolve('fr', 'standard', '2024-01-01'));
		self::assertSame(
			[
				['filters' => ['countryCode' => 'NL', 'rateCategory' => 'standard']],
				['filters' => ['countryCode' => 'DE', 'rateCategory' => 'reduced']],
				['filters' => ['countryCode' => 'FR', 'rateCategory' => 'standard']],
			],
			$queries
		);

	}//end testResolveNormalisesRowsAndQueriesExactly()
}//end class
