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
}//end class
