<?php

/**
 * Unit tests for WebshopVatRoute.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Webshop
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Webshop;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\OssRateResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ViesServiceInterface;
use OCA\Shillinq\Service\OssInvoiceRouter;
use OCA\Shillinq\Service\OssRateResolver;
use OCA\Shillinq\Service\Webshop\WebshopVatRoute;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Web shop VAT follows the destination rules; an order without a route is refused (REQ-SWO-003).
 */
#[CoversClass(WebshopVatRoute::class)]
#[UsesClass(OssInvoiceRouter::class)]
#[UsesClass(OssRateResolver::class)]
final class WebshopVatRouteTest extends TestCase {

	/**
	 * The destination rates the resolver answers, by country and category.
	 *
	 * @var array<string,float>
	 */
	private array $destinationRates = [];

	/**
	 * VIES, asserted per test.
	 *
	 * @var ViesServiceInterface&MockObject
	 */
	private ViesServiceInterface&MockObject $vies;

	/**
	 * The route over a real router and a rate resolver answering $destinationRates.
	 *
	 * @return WebshopVatRoute
	 */
	private function route(): WebshopVatRoute {
		$euStates = new OssRateResolver(appConfig: $this->createStub(IAppConfig::class), objectService: $this->createStub(ObjectServiceInterface::class));
		$rates = $this->createMock(OssRateResolverInterface::class);
		$rates->method('isOssDestination')->willReturnCallback(static fn (string $countryCode): bool => $euStates->isOssDestination(countryCode: $countryCode));
		$rates->method('resolve')->willReturnCallback(
			function (string $countryCode, string $rateCategory, string $invoiceDate): ?array {
				$key = $countryCode . '/' . $rateCategory;
				if (array_key_exists($key, $this->destinationRates) === false) {
					return null;
				}

				return ['appliedVatRate' => $this->destinationRates[$key], 'appliedRateCategory' => $rateCategory, 'tedbRateVersion' => strtolower($countryCode . '-' . $rateCategory)];
			}
		);

		return new WebshopVatRoute(router: new OssInvoiceRouter(rateResolver: $euStates), rates: $rates, vies: $this->vies);
	}//end route()

	/**
	 * VIES as a mock with no expectations yet.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->vies = $this->createMock(ViesServiceInterface::class);
	}//end setUp()

	/**
	 * An order with the given buyer and line rates.
	 *
	 * @param array<string,mixed> $buyer The buyer.
	 * @param array<int,float>    $rates The line rates.
	 *
	 * @return array<string,mixed>
	 */
	private static function order(array $buyer, array $rates = [9.0, 21.0]): array {
		$lines = [];
		foreach ($rates as $rate) {
			$lines[] = ['description' => 'Item', 'quantity' => 1, 'unitPrice' => 10.0, 'vatRate' => $rate];
		}

		return ['buyer' => $buyer, 'lines' => $lines];
	}//end order()

	/**
	 * A Dutch buyer, or one without a country, keeps the shop's rates and VIES is not asked.
	 *
	 * @return void
	 */
	public function testADomesticOrderKeepsTheShopRates(): void {
		$this->vies->expects(self::never())->method('validate');

		foreach (['NL', 'nl', ''] as $country) {
			$route = $this->route()->routeFor(order: self::order(buyer: ['type' => 'business', 'vatId' => 'NL001234567B01', 'countryCode' => $country]), invoiceDate: '2026-11-12', adminId: 'adm-1');

			self::assertSame(['domestic', 'NL', ['9' => 9.0, '21' => 21.0], null, null, null], array_values($route), $country);
		}
	}//end testADomesticOrderKeepsTheShopRates()

	/**
	 * A consumer in another member state gets each shop rate's destination rate and the OSS context of the highest rate.
	 *
	 * @return void
	 */
	public function testAConsumerInAnotherMemberStateRoutesThroughOss(): void {
		$this->destinationRates = ['BE/reduced1' => 6.0, 'BE/standard' => 21.0];
		$this->vies->expects(self::never())->method('validate');

		$route = $this->route()->routeFor(order: self::order(buyer: ['type' => 'consumer', 'countryCode' => 'be']), invoiceDate: '2026-05-31', adminId: 'adm-1');

		self::assertSame('oss', $route['route']);
		self::assertSame(['9' => 6.0, '21' => 21.0], $route['rates']);
		self::assertSame(
			['destinationCountry' => 'BE', 'appliedVatRate' => 21.0, 'appliedRateCategory' => 'standard', 'tedbRateVersion' => 'be-standard', 'ossEligible' => true, 'ossReportingPeriod' => '2026-Q2'],
			$route['ossContext']
		);
		self::assertNull($route['refusal']);
	}//end testAConsumerInAnotherMemberStateRoutesThroughOss()

	/**
	 * A business whose VAT id VIES confirms is reverse charged at 0 with the mention "Btw verlegd".
	 *
	 * @return void
	 */
	public function testABusinessWithAConfirmedVatIdIsReverseCharged(): void {
		$this->vies->expects(self::once())->method('validate')->with('adm-1', 'DE123456789')->willReturn(['valid' => true]);

		$route = $this->route()->routeFor(order: self::order(buyer: ['type' => 'business', 'vatId' => ' DE123456789 ', 'countryCode' => 'DE']), invoiceDate: '2026-11-12', adminId: 'adm-1');

		self::assertSame(['icp', 'DE', ['9' => 0.0, '21' => 0.0], null, 'Btw verlegd', null], array_values($route));
	}//end testABusinessWithAConfirmedVatIdIsReverseCharged()

	/**
	 * A business whose VAT id VIES refuses, or that sends none, pays the destination rate.
	 *
	 * @return void
	 */
	public function testABusinessWithoutAConfirmedVatIdPaysTheDestinationRate(): void {
		$this->destinationRates = ['DE/standard' => 19.0];
		$this->vies->expects(self::once())->method('validate')->willReturn(['valid' => false]);

		$refused = $this->route()->routeFor(order: self::order(buyer: ['type' => 'business', 'vatId' => 'DE000', 'countryCode' => 'DE'], rates: [21.0]), invoiceDate: '2026-11-12', adminId: 'adm-1');
		$without = $this->route()->routeFor(order: self::order(buyer: ['type' => 'business', 'countryCode' => 'DE'], rates: [21.0]), invoiceDate: '2026-11-12', adminId: 'adm-1');

		self::assertSame(['oss', ['21' => 19.0]], [$refused['route'], $refused['rates']]);
		self::assertSame(['oss', ['21' => 19.0]], [$without['route'], $without['rates']]);
	}//end testABusinessWithoutAConfirmedVatIdPaysTheDestinationRate()

	/**
	 * Outside the EU, a shop rate without an OSS category and a destination without a rate in force are refused, never routed at the Dutch rate.
	 *
	 * @return void
	 */
	public function testAnOrderWithoutARouteIsRefused(): void {
		$this->destinationRates = ['FR/standard' => 20.0];
		$cases = [
			'The buyer is in US; shillinq has no VAT route for a sale outside the EU.' => self::order(buyer: ['type' => 'consumer', 'countryCode' => 'US']),
			'The shop rate of 6% has no OSS rate category for a sale to FR.' => self::order(buyer: ['type' => 'consumer', 'countryCode' => 'FR'], rates: [21.0, 6.0]),
			'No FR VAT rate of category reduced1 is in force on 2026-11-12.' => self::order(buyer: ['type' => 'consumer', 'countryCode' => 'FR']),
		];
		foreach ($cases as $reason => $order) {
			$route = $this->route()->routeFor(order: $order, invoiceDate: '2026-11-12', adminId: 'adm-1');

			self::assertSame(['refused', [], null, $reason], [$route['route'], $route['rates'], $route['ossContext'], $route['refusal']], $reason);
		}
	}//end testAnOrderWithoutARouteIsRefused()

	/**
	 * A rate's key drops trailing zeros.
	 *
	 * @return void
	 */
	public function testARateKeyDropsTrailingZeros(): void {
		self::assertSame(['21', '9', '0', '5.5'], [WebshopVatRoute::rateKey(rate: 21.0), WebshopVatRoute::rateKey(rate: 9.00), WebshopVatRoute::rateKey(rate: 0.0), WebshopVatRoute::rateKey(rate: 5.50)]);
	}//end testARateKeyDropsTrailingZeros()
}//end class
