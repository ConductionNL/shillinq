<?php

/**
 * Unit tests for OssInvoiceRouter.
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
use OCA\Shillinq\Service\OssInvoiceRouter;
use OCA\Shillinq\Service\OssRateResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Covers REQ-OSS-006 and REQ-OSS-015 (B2C/B2B fork at invoice time).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssInvoiceRouterTest extends TestCase {

	/**
	 * The router under test.
	 *
	 * @var OssInvoiceRouter
	 */
	private OssInvoiceRouter $router;

	/**
	 * Set up fixtures — the resolver's isOssDestination is pure, so the container
	 * is never touched by the router.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$resolver = new OssRateResolver( $appConfig,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$this->router = new OssInvoiceRouter($resolver);

	}//end setUp()

	/**
	 * B2C in an EU member state routes to OSS (REQ-OSS-015).
	 *
	 * @return void
	 */
	public function testB2cEuRoutesToOss(): void {
		$decision = $this->router->route('b2c', 'ES');
		self::assertSame('oss', $decision['route']);
		self::assertNull($decision['warning']);

	}//end testB2cEuRoutesToOss()

	/**
	 * Domestic NL never enters OSS (REQ-OSS-001).
	 *
	 * @return void
	 */
	public function testDomesticNlRoutesToDomestic(): void {
		self::assertSame('domestic', $this->router->route('b2c', 'NL')['route']);

	}//end testDomesticNlRoutesToDomestic()

	/**
	 * B2B with a validated VAT-ID routes to the reverse-charge / ICP path (REQ-OSS-006).
	 *
	 * @return void
	 */
	public function testB2bValidVatRoutesToIcp(): void {
		$decision = $this->router->route('b2b', 'BE', 'valid');
		self::assertSame('icp', $decision['route']);
		self::assertNull($decision['warning']);

	}//end testB2bValidVatRoutesToIcp()

	/**
	 * B2B without a validated VAT-ID is reclassified to B2C and warns (REQ-OSS-006).
	 *
	 * @return void
	 */
	public function testB2bInvalidVatReclassifiedToOss(): void {
		$decision = $this->router->route('b2b', 'BE', 'invalid');
		self::assertSame('oss', $decision['route']);
		self::assertSame('oss.vatid.missing_reclassified_b2c', $decision['warning']);

	}//end testB2bInvalidVatReclassifiedToOss()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
