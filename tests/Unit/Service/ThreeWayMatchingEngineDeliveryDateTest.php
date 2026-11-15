<?php

/**
 * The delivery date axis of the three-way match (REQ-TWM-003).
 *
 * Runs the whole evaluation over an in-memory store: the actual date is the
 * `receivedAt` of the goods receipt that holds the matched line, the expected
 * date is the order line's, else the order's, and a delta beyond the
 * profile's `dateToleranceDays` makes the match `exception_date`, ranked after
 * price and quantity. The tolerance service is real; the invoice service and
 * the logger are doubles.
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
 * @spec openspec/changes/three-way-match-delivery-date-exception/specs/bookkeeping-three-way-matching-engine/spec.md#requirement-a-delivery-outside-the-date-tolerance-is-an-exception-req-twm-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCA\Shillinq\Service\ToleranceProfileService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A store of rows per schema that answers exact-match filters and keeps what is saved.
 */
final class DeliveryDateMatchStore {

	/**
	 * What was saved, per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $saved = [];

	/**
	 * The current schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * @param array<string, array<int, array<string, mixed>>> $rows The rows per schema.
	 */
	public function __construct(private array $rows) {
	}

	public function setRegister(string $register): self {
		return $this;
	}

	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}

	public function findAll(array $config = []): array {
		$out = [];
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			foreach ((array)($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return $out;
	}

	public function saveObject(array $data): array {
		$data['id'] = 'm-' . (count($this->saved[$this->schema] ?? []) + 1);
		$this->saved[$this->schema][] = $data;
		return $data;
	}
}//end class

/**
 * @covers \OCA\Shillinq\Service\ThreeWayMatchingEngine
 * @uses   \OCA\Shillinq\Service\ToleranceProfileService
 * @uses   \OCA\Shillinq\Service\ThreeWayMatchRun
 * @uses   \OCA\Shillinq\Service\ExceptionRoute
 */
final class ThreeWayMatchingEngineDeliveryDateTest extends TestCase {

	/**
	 * The invoice transitions the engine asked for.
	 *
	 * @var array<int, string>
	 */
	private array $transitions = [];

	/**
	 * The rows a scenario starts from: one invoice line that matches the
	 * order line and the receipt exactly in price and quantity.
	 *
	 * @param array<string, mixed> $poLine  Fields for the order line.
	 * @param array<string, mixed> $po      Fields for the order.
	 * @param array<string, mixed> $receipt Fields for the goods receipt.
	 * @param int|null             $days    The profile's dateToleranceDays; null for none.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function rows(array $poLine = [], array $po = [], array $receipt = [], ?int $days = 3): array {
		$profile = ['profileId' => 'TP-G', 'scope' => 'global', 'status' => 'active', 'administrationId' => 'adm-1', 'priceToleranceAmount' => 0, 'priceTolerancePercentage' => 0, 'quantityTolerancePercentage' => 0];
		if ($days !== null) {
			$profile['dateToleranceDays'] = $days;
		}

		return [
			'SupplierInvoice' => [[
				'id' => 'inv-1', 'supplierId' => 'sup-1', 'administrationId' => 'adm-1', 'matchedPoIds' => ['po-1'],
				'lines' => [['lineNumber' => 1, 'productCode' => 'P-1', 'quantity' => 10, 'unitPrice' => 1000, 'lineExtension' => 10000, 'vatRate' => 0.21]],
			]],
			'PurchaseOrder' => [['id' => 'po-1', 'administrationId' => 'adm-1'] + $po],
			'PurchaseOrderLine' => [[
				'id' => 'pol-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'lineNumber' => 1, 'productOrServiceCode' => 'P-1',
				'quantityOrdered' => 10, 'unitPrice' => 1000, 'vatAmount' => 2100,
			] + $poLine],
			'GoodsReceiptNote' => [['id' => 'grn-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted'] + $receipt],
			'GoodsReceiptLine' => [['id' => 'grl-1', 'grnId' => 'grn-1', 'administrationId' => 'adm-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10, 'quantityAccepted' => 10]],
			'SvcReceipt' => [],
			'SvcReceiptLine' => [],
			'ToleranceProfile' => [$profile],
		];

	}//end rows()

	/**
	 * Evaluate the invoice over the rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows The store.
	 *
	 * @return array<string, mixed> The saved match.
	 */
	private function evaluate(array $rows): array {
		$store     = new DeliveryDateMatchStore($rows);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger    = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$invoices = $this->createMock(SupplierInvoiceService::class);
		$invoices->method('setStatus')->willReturnCallback(
			function (string $administrationId, string $invoiceId, string $toStatus): array {
				$this->transitions[] = $toStatus;
				return [];
			}
		);

		$engine = new ThreeWayMatchingEngine(
			appConfig: $appConfig,
			toleranceService: new ToleranceProfileService(container: $container, appConfig: $appConfig, logger: $logger),
			invoiceService: $invoices,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($store),
			exceptionResolution: null,
		);
		$engine->evaluateMatch('adm-1', 'inv-1');

		$this->assertCount(1, $store->saved['ThreeWayMatch'] ?? []);
		return $store->saved['ThreeWayMatch'][0];

	}//end evaluate()

	/**
	 * The deliveryDate divergences of a match.
	 *
	 * @param array<string, mixed> $match The match.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function dateDivergences(array $match): array {
		return array_values(array_filter((array)$match['divergenceDetails'], static fn (array $d): bool => ($d['field'] ?? '') === 'deliveryDate'));

	}//end dateDivergences()

	public function testALateDeliveryBeyondTheToleranceIsAnException(): void {
		$match = $this->evaluate(self::rows(poLine: ['expectedDeliveryDate' => '2026-06-01'], receipt: ['receivedAt' => '2026-06-08T10:00:00+02:00']));

		$this->assertSame('exception_date', $match['matchStatus']);
		$date = self::dateDivergences($match)[0];
		$this->assertSame(['2026-06-01', '2026-06-08', 7], [$date['expected'], $date['actual'], $date['deltaDays']]);
		$this->assertSame(['matching', 'exception'], $this->transitions);

	}//end testALateDeliveryBeyondTheToleranceIsAnException()

	public function testAnEarlyDeliveryBeyondTheToleranceIsAnException(): void {
		$match = $this->evaluate(self::rows(poLine: ['expectedDeliveryDate' => '2026-06-10'], receipt: ['receivedAt' => '2026-06-02']));

		$this->assertSame('exception_date', $match['matchStatus']);
		$this->assertSame(-8, self::dateDivergences($match)[0]['deltaDays']);

	}//end testAnEarlyDeliveryBeyondTheToleranceIsAnException()

	public function testADeliveryWithinTheToleranceKeepsItsStatus(): void {
		$match = $this->evaluate(self::rows(poLine: ['expectedDeliveryDate' => '2026-06-01'], receipt: ['receivedAt' => '2026-06-04']));

		$this->assertSame('auto_approved', $match['matchStatus']);
		$this->assertSame(['matching', 'matched'], $this->transitions);

	}//end testADeliveryWithinTheToleranceKeepsItsStatus()

	public function testALineWithoutAnExpectedDateIsNotDateChecked(): void {
		$match = $this->evaluate(self::rows(receipt: ['receivedAt' => '2026-06-30']));

		$this->assertSame('auto_approved', $match['matchStatus']);
		$this->assertSame([], self::dateDivergences($match));

	}//end testALineWithoutAnExpectedDateIsNotDateChecked()

	public function testTheOrdersDateAppliesWhenTheLineHasNone(): void {
		$match = $this->evaluate(self::rows(po: ['expectedDeliveryDate' => '2026-06-01'], receipt: ['receivedAt' => '2026-06-08']));

		$this->assertSame('exception_date', $match['matchStatus']);
		$this->assertSame('2026-06-01', self::dateDivergences($match)[0]['expected']);

	}//end testTheOrdersDateAppliesWhenTheLineHasNone()

	public function testTheLinesOwnDateWinsOverTheOrders(): void {
		$match = $this->evaluate(self::rows(poLine: ['expectedDeliveryDate' => '2026-06-07'], po: ['expectedDeliveryDate' => '2026-06-01'], receipt: ['receivedAt' => '2026-06-08']));

		$this->assertSame('auto_approved', $match['matchStatus']);
		$this->assertSame(1, self::dateDivergences($match)[0]['deltaDays']);

	}//end testTheLinesOwnDateWinsOverTheOrders()

	public function testAReceiptWithoutAReceivedDateIsNotDateChecked(): void {
		$match = $this->evaluate(self::rows(poLine: ['expectedDeliveryDate' => '2026-06-01']));

		$this->assertSame('auto_approved', $match['matchStatus']);
		$this->assertSame([], self::dateDivergences($match));

	}//end testAReceiptWithoutAReceivedDateIsNotDateChecked()

	public function testWithoutADateToleranceAnyDifferenceIsAnException(): void {
		$match = $this->evaluate(self::rows(poLine: ['expectedDeliveryDate' => '2026-06-01'], receipt: ['receivedAt' => '2026-06-02'], days: null));

		$this->assertSame('exception_date', $match['matchStatus']);

	}//end testWithoutADateToleranceAnyDifferenceIsAnException()

	public function testAPriceBreachOutranksADateBreachWhichIsStillRecorded(): void {
		$rows = self::rows(poLine: ['expectedDeliveryDate' => '2026-06-01'], receipt: ['receivedAt' => '2026-06-08']);
		$rows['SupplierInvoice'][0]['lines'][0]['unitPrice'] = 1500;
		$rows['SupplierInvoice'][0]['lines'][0]['lineExtension'] = 15000;

		$match = $this->evaluate($rows);

		$this->assertSame('exception_price', $match['matchStatus']);
		$this->assertSame(7, self::dateDivergences($match)[0]['deltaDays']);

	}//end testAPriceBreachOutranksADateBreachWhichIsStillRecorded()
}//end class
