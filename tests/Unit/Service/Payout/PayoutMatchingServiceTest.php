<?php

/**
 * PayoutMatchingService tests (receivables-provider-payouts task 2.2)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payout
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Payout\PayoutMatchingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Each payout line is matched to the request, deposit or web shop invoice it paid.
 *
 * @covers \OCA\Shillinq\Service\Payout\PayoutMatchingService
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.2
 */
final class PayoutMatchingServiceTest extends TestCase {

	/**
	 * The register rows by schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [
		'PaymentRequest' => [
			['id' => 'pr-1', 'administrationId' => 'THV', 'paymentIntentId' => 'tr_example0001', 'invoiceReference' => 'inv-0601'],
			['id' => 'pr-2', 'administrationId' => 'THV', 'providerPaymentId' => 'tr_example0002', 'invoiceReference' => 'inv-0602'],
			['id' => 'pr-other', 'administrationId' => 'ELSE', 'paymentIntentId' => 'tr_example0007'],
		],
		'DepositPayment' => [
			['id' => 'dep-1', 'administrationId' => 'THV', 'paymentIntentId' => 'tr_example0003', 'arInvoiceId' => 'inv-0603'],
		],
		'ARInvoice' => [
			['id' => 'inv-0604', 'administrationId' => 'THV', 'webshop' => ['paymentReference' => 'tr_example0004']],
		],
	];

	/**
	 * The matcher over the rows; the object service filters on dotted paths as OpenRegister does.
	 *
	 * @return PayoutMatchingService
	 */
	private function matcher(): PayoutMatchingService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			static function (string|int $slug) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$slug;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema): array {
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($config): bool {
							foreach ((array)($config['filters'] ?? []) as $path => $wanted) {
								$value = $row;
								foreach (explode('.', (string)$path) as $key) {
									$value = (((array)$value)[$key] ?? null);
								}

								if ($value !== $wanted) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $default);

		return new PayoutMatchingService(objectService: $objects, appConfig: $config);
	}//end matcher()

	/**
	 * Payments match a request by either id field, a deposit and a web shop invoice;
	 * a refund matches the invoice of its original payment; the rest stays unmatched.
	 *
	 * @return void
	 */
	public function testEachLineIsMatchedToWhatItPaid(): void {
		$payout = $this->matcher()->match(
			[
				'administrationId' => 'THV',
				'settlementReference' => 'st_example0001',
				'state' => 'received',
				'lines' => [
					['providerPaymentId' => 'tr_example0001', 'type' => 'payment', 'amount' => 145.2],
					['providerPaymentId' => 'tr_example0002', 'type' => 'payment', 'amount' => 60.0],
					['providerPaymentId' => 'tr_example0003', 'type' => 'payment', 'amount' => 50.0],
					['providerPaymentId' => 'tr_example0004', 'type' => 'payment', 'amount' => 12.5],
					['providerPaymentId' => 're_example0005', 'type' => 'refund', 'amount' => 22.85, 'originalPaymentId' => 'tr_example0003'],
					['providerPaymentId' => 'tr_example0099', 'type' => 'payment', 'amount' => 18.0],
					['providerPaymentId' => 'tr_example0007', 'type' => 'payment', 'amount' => 9.0],
					'not-a-line',
				],
			]
		);

		$this->assertSame(
			[
				['matched', 'pr-1', 'payment-request'],
				['matched', 'pr-2', 'payment-request'],
				['matched', 'dep-1', 'deposit'],
				['matched', 'inv-0604', 'invoice'],
				['matched', 'inv-0603', 'invoice'],
				['unmatched', null, null],
				['unmatched', null, null],
			],
			array_map(
				static fn (array $line): array => [$line['status'], ($line['matchedObjectId'] ?? null), ($line['matchedObjectType'] ?? null)],
				$payout['lines']
			)
		);
		$this->assertSame(5, $payout['matchedLineCount']);
		$this->assertSame(2, $payout['unmatchedLineCount']);
		$this->assertSame('matched', $payout['state']);
		$this->assertSame(
			['tr_example0099', 'tr_example0007'],
			array_column(PayoutMatchingService::unmatched($payout), 'providerPaymentId')
		);
	}//end testEachLineIsMatchedToWhatItPaid()

	/**
	 * A bookkeeper's earlier match is kept, a booked payout keeps its state, and a refund
	 * whose original payment names no invoice stays unmatched.
	 *
	 * @return void
	 */
	public function testEarlierMatchesAndStatesAreKept(): void {
		$this->rows['PaymentRequest'][] = ['id' => 'pr-3', 'administrationId' => 'THV', 'paymentIntentId' => 'tr_example0008'];
		$payout = $this->matcher()->match(
			[
				'administrationId' => 'THV',
				'state' => 'booked',
				'lines' => [
					['providerPaymentId' => 'tr_example0099', 'type' => 'payment', 'amount' => 18.0, 'status' => 'matched', 'matchedObjectId' => 'inv-by-hand'],
					['providerPaymentId' => 'cb_example0009', 'type' => 'chargeback', 'amount' => 30.0, 'originalPaymentId' => 'tr_example0008'],
				],
			]
		);

		$this->assertSame('inv-by-hand', $payout['lines'][0]['matchedObjectId']);
		$this->assertSame('unmatched', $payout['lines'][1]['status']);
		$this->assertSame('booked', $payout['state']);
	}//end testEarlierMatchesAndStatesAreKept()
}//end class
