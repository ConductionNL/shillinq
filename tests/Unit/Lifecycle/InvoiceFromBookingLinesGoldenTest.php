<?php

/**
 * Golden test for InvoiceFromBookingGuard::buildLineItems.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InvoiceFromBookingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the exact invoice lines per order and deposit credit.
 *
 * Regenerate with INVOICE_FROM_BOOKING_LINES_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class InvoiceFromBookingLinesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/invoice-from-booking-lines.golden.json';

	public function testLinesMatchGolden(): void {
		$guard = new InvoiceFromBookingGuard($this->createMock(ContainerInterface::class), $this->createMock(IAppConfig::class), new NullLogger());
		$order = ['administrationId' => 'adm-1', 'basePrice' => '10000', 'taxRate' => '9', 'description' => 'Zaalhuur'];
		$actual = [
			'withDeposit' => $guard->buildLineItems($order, 2500),
			'noDeposit' => $guard->buildLineItems($order, 0),
			'negativeDeposit' => $guard->buildLineItems($order, -5),
			'defaults' => $guard->buildLineItems([], 1),
			'oddCents' => $guard->buildLineItems(['basePrice' => 333], 0),
		];

		if (getenv('INVOICE_FROM_BOOKING_LINES_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}
}
