<?php

/**
 * shillinq's part of the Zuiddrecht example set writes the finance records the
 * ShActief, ShBtwAangifte, ShBegroting, ShBetaalrun and ShInkooporder boards
 * open, in the shipped default administration.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped example-set fragment from disk.
 */
class ExampleSetFinanceRecordsTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The fragment's objects by key.
	 *
	 * @return array<string, array<string, mixed>> The objects
	 */
	private static function objects(): array {
		self::assertFileExists(filename: self::FRAGMENT);
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$byKey    = [];
		foreach ($fragment['objects'] as $object) {
			$byKey[$object['key']] = $object;
		}

		return $byKey;
	}//end objects()

	/**
	 * One record per board, each of the schema the board's page reads.
	 *
	 * @return void
	 */
	public function testEveryBoardHasItsRecord(): void {
		$schemas = array_map(fn (array $o): string => $o['schema'], self::objects());

		$this->assertSame(expected: 'FixedAsset', actual: $schemas['fixed-asset-vrachtwagen'] ?? null);
		$this->assertSame(expected: 'BtwAangifte', actual: $schemas['btw-aangifte-2026-q3'] ?? null);
		$this->assertSame(expected: 'AnnualBudget', actual: $schemas['annual-budget-2027'] ?? null);
		$this->assertSame(expected: 'PaymentRun', actual: $schemas['payment-run-2026-41'] ?? null);
		$this->assertSame(expected: 'PurchaseOrder', actual: $schemas['purchase-order-io-2026-0087'] ?? null);
	}//end testEveryBoardHasItsRecord()

	/**
	 * Every record is in the default administration, which Pieter's membership
	 * names by code; the fixed asset's schema takes the administration's uuid.
	 *
	 * @return void
	 */
	public function testEveryRecordIsInTheDefaultAdministration(): void {
		foreach (self::objects() as $key => $object) {
			if ($key === 'fixed-asset-vrachtwagen') {
				$this->assertSame(expected: '{{lookup:administration}}', actual: $object['data']['administrationId']);
				$this->assertSame(expected: ['administrationCode' => 'ADM-001'], actual: $object['lookups']['administration']['where']);
				continue;
			}

			$this->assertSame(expected: 'ADM-001', actual: $object['data']['administrationId'], message: $key);
		}
	}//end testEveryRecordIsInTheDefaultAdministration()

	/**
	 * The payment run waits for its second approval and adds up; the order is
	 * partly received from a supplier the run also pays.
	 *
	 * @return void
	 */
	public function testThePaymentRunAndOrderHangTogether(): void {
		$objects = self::objects();
		$run     = $objects['payment-run-2026-41']['data'];
		$order   = $objects['purchase-order-io-2026-0087']['data'];

		$this->assertSame(expected: 'draft', actual: $run['status']);
		$this->assertEqualsWithDelta(expected: $run['totalAmount'], actual: array_sum(array_column($run['paymentLines'], 'amount')), delta: 0.001);
		$this->assertSame(expected: 'partial_received', actual: $order['statusCode']);
		$this->assertSame(expected: '{{object:payee-de-linde.id}}', actual: $order['supplierId']);
		$this->assertContains(needle: $order['supplierId'], haystack: array_column($run['paymentLines'], 'payeeId'));
	}//end testThePaymentRunAndOrderHangTogether()
}//end class
