<?php

/**
 * Webshop VAT Route
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Decides the VAT a web shop order is invoiced at (REQ-SWO-003): a domestic
 * order at the line's own rate, a consumer in another EU member state at that
 * state's rate through OSS, a business buyer in another member state with a
 * VAT id VIES confirms under reverse charge with the mention "Btw verlegd".
 * An order whose route cannot be determined is refused, never invoiced at the
 * Dutch rate.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Webshop
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Webshop;

use OCA\Shillinq\Kernel\Contracts\OssRateResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ViesServiceInterface;
use OCA\Shillinq\Service\OssInvoiceRouter;

/**
 * Routes one web shop order to domestic, OSS or reverse-charge VAT.
 *
 * The result maps every rate the shop applied (keyed as rateKey() writes it)
 * to the rate the invoice charges, and carries the OSS context or the
 * reverse-charge mention the invoice needs. A refusal is a result with a
 * `refusal` text and no rates.
 */
class WebshopVatRoute {

	/**
	 * The mention a reverse-charged invoice carries.
	 *
	 * @var string
	 */
	public const REVERSE_CHARGE_MENTION = 'Btw verlegd';

	/**
	 * The EuVatRate category a Dutch shop rate stands for.
	 *
	 * @var array<int|string,string>
	 */
	public const CATEGORY_OF_RATE = ['21' => 'standard', '9' => 'reduced1', '0' => 'zero'];

	/**
	 * Constructor.
	 *
	 * @param OssInvoiceRouter         $router Decides domestic, OSS or ICP from buyer type, country and VAT id status.
	 * @param OssRateResolverInterface $rates  The destination rate in force on a date.
	 * @param ViesServiceInterface     $vies   Validates a business buyer's VAT id.
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	public function __construct(
		private readonly OssInvoiceRouter $router,
		private readonly OssRateResolverInterface $rates,
		private readonly ViesServiceInterface $vies,
	) {
	}//end __construct()

	/**
	 * The VAT route of an order on its invoice date.
	 *
	 * @param array<string,mixed> $order       The WebshopOrder.
	 * @param string              $invoiceDate The invoice date (Y-m-d).
	 * @param string              $adminId     The administration the VAT id check is recorded for.
	 *
	 * @return array<string,mixed> The route: route, country, rates, ossContext, mention and refusal (null unless refused).
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	public function routeFor(array $order, string $invoiceDate, string $adminId): array {
		$buyer   = (array)($order['buyer'] ?? []);
		$country = strtoupper(trim((string)($buyer['countryCode'] ?? '')));
		$shopRates = self::shopRates(order: $order);
		if ($country === '' || $country === 'NL') {
			return self::result(route: 'domestic', country: 'NL', rates: $shopRates);
		}

		if ($this->rates->isOssDestination(countryCode: $country) === false) {
			return self::refusal(country: $country, text: sprintf('The buyer is in %s; shillinq has no VAT route for a sale outside the EU.', $country));
		}

		$type   = 'b2c';
		$status = '';
		if (($buyer['type'] ?? 'consumer') === 'business') {
			$type  = 'b2b';
			$vatId = trim((string)($buyer['vatId'] ?? ''));
			if ($vatId !== '') {
				$status = 'invalid';
				if (($this->vies->validate(administrationId: $adminId, vatId: $vatId)['valid'] ?? false) === true) {
					$status = 'valid';
				}
			}
		}

		$decision = $this->router->route(customerType: $type, destinationCountry: $country, vatValidationStatus: $status);
		if (($decision['route'] ?? '') === 'icp') {
			return self::result(
				route: 'icp',
				country: $country,
				rates: array_fill_keys(array_keys($shopRates), 0.0),
				mention: self::REVERSE_CHARGE_MENTION
			);
		}

		if ($decision['route'] !== 'oss') {
			return self::refusal(country: $country, text: sprintf('The VAT route of a sale to %s could not be determined.', $country));
		}

		return $this->ossRoute(country: $country, shopRates: $shopRates, invoiceDate: $invoiceDate);
	}//end routeFor()

	/**
	 * The key a shop rate is looked up under.
	 *
	 * @param float $rate The rate in percent.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	public static function rateKey(float $rate): string {
		return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
	}//end rateKey()

	/**
	 * The OSS route: every shop rate mapped to the destination rate of its category.
	 *
	 * @param string             $country     The destination member state.
	 * @param array<string,float> $shopRates  The shop's rates by key.
	 * @param string             $invoiceDate The invoice date (Y-m-d).
	 *
	 * @return array<string,mixed> The route: route, country, rates, ossContext, mention and refusal (null unless refused).
	 */
	private function ossRoute(string $country, array $shopRates, string $invoiceDate): array {
		$rates   = [];
		$context = null;
		$highest = -1.0;
		foreach ($shopRates as $key => $shopRate) {
			$category = self::CATEGORY_OF_RATE[$key] ?? null;
			if ($category === null) {
				return self::refusal(country: $country, text: sprintf('The shop rate of %s%% has no OSS rate category for a sale to %s.', $key, $country));
			}

			$resolved = $this->rates->resolve(countryCode: $country, rateCategory: $category, invoiceDate: $invoiceDate);
			if ($resolved === null) {
				return self::refusal(country: $country, text: sprintf('No %s VAT rate of category %s is in force on %s.', $country, $category, $invoiceDate));
			}

			$rates[$key] = (float)$resolved['appliedVatRate'];
			if ($shopRate > $highest) {
				$highest = $shopRate;
				$context = $resolved;
			}
		}

		$month = (int)substr($invoiceDate, 5, 2);
		$ossContext = [
			'destinationCountry' => $country,
			'appliedVatRate' => (float)($context['appliedVatRate'] ?? 0),
			'appliedRateCategory' => (string)($context['appliedRateCategory'] ?? ''),
			'tedbRateVersion' => (string)($context['tedbRateVersion'] ?? ''),
			'ossEligible' => true,
			'ossReportingPeriod' => sprintf('%s-Q%d', substr($invoiceDate, 0, 4), (int)ceil(max(1, $month) / 3)),
		];

		return self::result(route: 'oss', country: $country, rates: $rates, ossContext: $ossContext);
	}//end ossRoute()

	/**
	 * The distinct rates the shop applied to the lines, by key.
	 *
	 * @param array<string,mixed> $order The order.
	 *
	 * @return array<string,float>
	 */
	private static function shopRates(array $order): array {
		$rates = [];
		foreach ((array)($order['lines'] ?? []) as $line) {
			$rate = max(0.0, (float)($line['vatRate'] ?? 0));
			$rates[self::rateKey(rate: $rate)] = $rate;
		}

		return $rates;
	}//end shopRates()

	/**
	 * A routed result.
	 *
	 * @param string                   $route      domestic, oss or icp.
	 * @param string                   $country    The destination country.
	 * @param array<string,float>      $rates      The invoiced rate per shop rate key.
	 * @param array<string,mixed>|null $ossContext The OSS context of an OSS sale.
	 * @param string|null              $mention    The reverse-charge mention.
	 *
	 * @return array<string,mixed> The route: route, country, rates, ossContext, mention and refusal (null unless refused).
	 */
	private static function result(string $route, string $country, array $rates, ?array $ossContext = null, ?string $mention = null): array {
		return ['route' => $route, 'country' => $country, 'rates' => $rates, 'ossContext' => $ossContext, 'mention' => $mention, 'refusal' => null];
	}//end result()

	/**
	 * A refused result.
	 *
	 * @param string $country The destination country.
	 * @param string $text    Why no route applies.
	 *
	 * @return array<string,mixed> The route: route, country, rates, ossContext, mention and refusal (null unless refused).
	 */
	private static function refusal(string $country, string $text): array {
		return ['route' => 'refused', 'country' => $country, 'rates' => [], 'ossContext' => null, 'mention' => null, 'refusal' => $text];
	}//end refusal()
}//end class
