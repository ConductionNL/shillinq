<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ExceptionResolutionService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCA\Shillinq\Service\ToleranceProfileService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An in-memory object store that records every call the matching engine and
 * the tolerance service make.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RecordingMatchObjectService {

	/**
	 * Every store call, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Active schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Number of findAll calls per schema.
	 *
	 * @var array<string, int>
	 */
	private array $reads = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, mixed>> $stored    Schema => rows.
	 * @param array<string, mixed>             $behaviour Failure switches per schema.
	 */
	public function __construct(
		private array $stored,
		private array $behaviour,
	) {

	}//end __construct()

	/**
	 * Fluent register setter.
	 *
	 * @param string $register Register slug.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		$this->calls[] = ['setRegister', $register];
		return $this;

	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * Return the rows of the active schema that match every filter.
	 *
	 * A behaviour of `throw` fails every read of the schema; an integer fails
	 * only that read (1-based).
	 *
	 * @param array<string, mixed> $config Query config.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = []): array {
		$this->calls[] = ['findAll', $this->schema, $config];
		$this->reads[$this->schema] = (($this->reads[$this->schema] ?? 0) + 1);
		$fail = ($this->behaviour['findAll:' . $this->schema] ?? null);
		if ($fail === 'throw' || $fail === $this->reads[$this->schema]) {
			throw new \RuntimeException('store down');
		}

		$result = [];
		foreach (($this->stored[$this->schema] ?? []) as $row) {
			if (is_array($row) === false) {
				$result[] = $row;
				continue;
			}

			$match = true;
			foreach ((array) ($config['filters'] ?? []) as $key => $value) {
				$field = ($row[$key] ?? null);
				if ($key === 'id' && $field === null) {
					$field = ($row['@self']['id'] ?? null);
				}

				if ($field !== $value) {
					$match = false;
				}
			}

			if ($match === true) {
				$result[] = $row;
			}
		}

		return $result;

	}//end findAll()

	/**
	 * Record a save and stamp an id, unless the behaviour says otherwise.
	 *
	 * @param array<string, mixed> $data Object payload.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $data): array {
		if (isset($data['createdAt']) === true) {
			$data['createdAt'] = '(now)';
		}

		$this->calls[] = ['saveObject', $this->schema, $data];
		$behaviour = ($this->behaviour['save:' . $this->schema] ?? '');
		if ($behaviour === 'throw') {
			throw new \RuntimeException('write refused');
		}

		if ($behaviour === 'no-id') {
			return $data;
		}

		if ($behaviour === 'self-id') {
			$data['@self'] = ['id' => 'm-self'];
			return $data;
		}

		$data['id'] = 'm-1';
		return $data;

	}//end saveObject()
}//end class

/**
 * Golden record of ThreeWayMatchingEngine::evaluateMatch.
 *
 * Each scenario records the returned match (or the exception), every store
 * read and write, every invoice transition, every exception alert and every
 * log message. The record was made on the code before evaluateMatch was split
 * into steps, so any change in verdict, tolerance outcome or exception path
 * fails this test.
 *
 * Re-record with SHILLINQ_GOLDEN_RECORD=1 only for an intended change.
 *
 * @covers \OCA\Shillinq\Service\ThreeWayMatchingEngine
 * @uses   \OCA\Shillinq\Service\ToleranceProfileService
 * @uses   \OCA\Shillinq\Service\ThreeWayMatchRun
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ThreeWayMatchingEngineEvaluateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/three-way-match-evaluate.golden.json';

	/**
	 * Results recorded in record mode.
	 *
	 * @var array<string, mixed>
	 */
	private static array $recorded = [];

	/**
	 * One exact goods match: invoice, PO, accepted GRN, GRN line, global profile.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function seed(): array {
		return [
			'SupplierInvoice'   => [
				[
					'id'               => 'inv-1',
					'supplierId'       => 'sup-1',
					'administrationId' => 'adm-1',
					'matchedPoIds'     => ['po-1'],
					'costCenter'       => 'INV-CC',
					'projectCode'      => 'INV-PRJ',
					'lines'            => [
						['lineNumber' => 1, 'productCode' => 'P-1', 'quantity' => 10, 'unitPrice' => 1000, 'lineExtension' => 10000, 'vatRate' => 0.21],
					],
				],
			],
			'PurchaseOrder'     => [
				['id' => 'po-1', 'administrationId' => 'adm-1', 'costCenter' => 'CC-1', 'projectCode' => 'PRJ-1'],
				['id' => 'po-2', 'administrationId' => 'adm-1'],
				['id' => 'po-x', 'administrationId' => 'adm-2'],
			],
			'PurchaseOrderLine' => [
				[
					'id'                   => 'pol-1',
					'poId'                 => 'po-1',
					'administrationId'     => 'adm-1',
					'lineNumber'           => 1,
					'productOrServiceCode' => 'P-1',
					'quantityOrdered'      => 12,
					'unitPrice'            => 1000,
					'vatAmount'            => 2100,
					'productCategory'      => ' cat-1 ',
					'glAccount'            => '4400',
				],
			],
			'GoodsReceiptNote'  => [
				['id' => 'grn-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted'],
				['id' => 'grn-9', 'administrationId' => 'adm-1', 'poIds' => ['po-2'], 'statusCode' => 'accepted'],
			],
			'GoodsReceiptLine'  => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'administrationId' => 'adm-1', 'poLineId' => 'pol-1', 'quantityReceived' => 11, 'quantityAccepted' => 10],
			],
			'SvcReceipt'        => [],
			'SvcReceiptLine'    => [],
			'ToleranceProfile'  => [
				[
					'profileId'                   => 'TP-G',
					'scope'                       => 'global',
					'status'                      => 'active',
					'administrationId'            => 'adm-1',
					'priceToleranceAmount'        => 50,
					'priceTolerancePercentage'    => 100,
					'quantityTolerancePercentage' => 1000,
					'dateToleranceDays'           => 2,
				],
			],
		];

	}//end seed()

	/**
	 * Replace the first invoice line.
	 *
	 * @param array<string, mixed> $changes Fields to merge.
	 *
	 * @return array<string, mixed>
	 */
	private static function line(array $changes): array {
		$seed = self::seed();
		return ['invoice' => ['lines' => [array_merge($seed['SupplierInvoice'][0]['lines'][0], $changes)]]];

	}//end line()

	/**
	 * Replace the first PO line.
	 *
	 * @param array<string, mixed> $changes Fields to merge.
	 *
	 * @return array<string, mixed>
	 */
	private static function poLine(array $changes): array {
		$seed = self::seed();
		return ['replace' => ['PurchaseOrderLine' => [array_merge($seed['PurchaseOrderLine'][0], $changes)]]];

	}//end poLine()

	/**
	 * Replace the first GRN line.
	 *
	 * @param array<string, mixed> $changes Fields to merge.
	 *
	 * @return array<string, mixed>
	 */
	private static function grnLine(array $changes): array {
		$seed = self::seed();
		return ['replace' => ['GoodsReceiptLine' => [array_merge($seed['GoodsReceiptLine'][0], $changes)]]];

	}//end grnLine()

	/**
	 * Replace the goods receipts with one for po-1.
	 *
	 * @param array<string, mixed> $fields Id and status fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function grn(array $fields): array {
		return ['replace' => ['GoodsReceiptNote' => [$fields + ['administrationId' => 'adm-1', 'poIds' => ['po-1']]]]];

	}//end grn()

	/**
	 * Set the PO line's expected date and the GRN line's received date.
	 *
	 * @param string $expected Expected delivery date.
	 * @param string $received Received date.
	 *
	 * @return array<string, mixed>
	 */
	private static function delivery(string $expected, string $received): array {
		return array_merge_recursive(self::poLine(['expectedDeliveryDate' => $expected]), self::grnLine(['_receivedAt' => $received]));

	}//end delivery()

	/**
	 * Replace the tolerance profiles.
	 *
	 * @param array<int, array<string, mixed>> $profiles Profiles.
	 *
	 * @return array<string, mixed>
	 */
	private static function profiles(array $profiles): array {
		return ['replace' => ['ToleranceProfile' => $profiles]];

	}//end profiles()

	/**
	 * The scenarios.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) A scenario table.
	 */
	public static function scenarios(): array {
		$svc     = [
			'SvcReceipt'     => [['@self' => ['id' => 'svc-1'], 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted']],
			'SvcReceiptLine' => [
				['id' => 'sl-1', 'serviceReceiptId' => 'svc-1', 'administrationId' => 'adm-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10],
			],
		];
		$global  = self::seed()['ToleranceProfile'][0];
		$active  = ['status' => 'active', 'administrationId' => 'adm-1'];
		$twoLine = self::seed()['SupplierInvoice'][0]['lines'];
		$twoLine[] = ['lineNumber' => 2, 'productCode' => 'P-2', 'quantity' => 1, 'unitPrice' => 5, 'lineExtension' => 5, 'vatRate' => 0];

		return [
			'exact match'                        => [[]],
			'quantity within tolerance'          => [self::line(['quantity' => 10.5])],
			'quantity at the tolerance edge'     => [self::line(['quantity' => 11])],
			'quantity just over the edge'        => [self::line(['quantity' => 11.001])],
			'quantity under the receipt'         => [self::line(['quantity' => 8])],
			'blank administration'               => [['admin' => '']],
			'blank invoice id'                   => [['invoiceId' => '']],
			'unknown invoice'                    => [['invoiceId' => 'inv-404']],
			'invoice of another administration'  => [['admin' => 'adm-2']],
			'invoice read fails'                 => [['behaviour' => ['findAll:SupplierInvoice' => 'throw']]],
			'two purchase orders'                => [['invoice' => ['matchedPoIds' => ['po-1', 'po-2']]]],
			'duplicate and blank po ids'         => [['invoice' => ['matchedPoIds' => ['po-1', ' po-1 ', '', 7]]]],
			'foreign purchase order'             => [['invoice' => ['matchedPoIds' => ['po-x']]]],
			'unknown purchase order'             => [['invoice' => ['matchedPoIds' => ['po-404'], 'costCenter' => null]]],
			'scalar poId fallback'               => [['invoice' => ['matchedPoIds' => [], 'poId' => ' po-1 ']]],
			'purchaseOrderId fallback'           => [['invoice' => ['matchedPoIds' => 'po-2', 'purchaseOrderId' => 'po-1']]],
			'poId beats purchaseOrderId'         => [['invoice' => ['matchedPoIds' => null, 'poId' => 'po-404', 'purchaseOrderId' => 'po-1']]],
			'no po reference'                    => [['invoice' => ['matchedPoIds' => null]]],
			'invoice without id field'           => [['invoice' => ['id' => null, '@self' => ['id' => 'inv-1'], 'matchedPoIds' => null]]],
			'po read fails'                      => [['behaviour' => ['findAll:PurchaseOrder' => 'throw']]],
			'second po read fails'               => [['behaviour' => ['findAll:PurchaseOrder' => 2]]],
			'po line read fails'                 => [['behaviour' => ['findAll:PurchaseOrderLine' => 'throw']]],
			'grn read fails'                     => [['behaviour' => ['findAll:GoodsReceiptNote' => 'throw']]],
			'grn line read fails'                => [['behaviour' => ['findAll:GoodsReceiptLine' => 'throw']]],
			'svc read fails'                     => [['behaviour' => ['findAll:SvcReceipt' => 'throw']]],
			'profile read fails'                 => [['behaviour' => ['findAll:ToleranceProfile' => 'throw']]],
			'no receipt at all'                  => [['replace' => ['GoodsReceiptNote' => []]]],
			'grn only received'                  => [self::grn(['@self' => ['id' => 'grn-2'], 'statusCode' => 'received'])],
			'grn quality checked'                => [self::grn(['id' => 'grn-1', 'statusCode' => 'quality_checked'])],
			'grn without id, second accepted'    => [[
				'replace' => [
					'GoodsReceiptNote' => [
						['administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted'],
						'not-a-row',
						['id' => 'grn-1', 'administrationId' => 'adm-1', 'poIds' => 'po-1', 'statusCode' => 'received'],
						['id' => 'grn-3', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted'],
					],
				],
			],
			],
			'grn with a self id'                 => [[
				'replace' => [
					'GoodsReceiptNote' => [['@self' => ['id' => 'grn-1'], 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted']],
				],
			],
			],
			'service receipt only'               => [['replace' => array_merge(['GoodsReceiptNote' => []], $svc)]],
			'service receipt not accepted'       => [[
				'replace' => array_merge(
					['GoodsReceiptNote' => [], 'SvcReceiptLine' => []],
					['SvcReceipt' => [['id' => 'svc-2', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'quality_checked']]]
				),
			],
			],
			'service receipt only received'      => [[
				'replace' => [
					'GoodsReceiptNote' => [],
					'SvcReceipt'       => [['id' => 'svc-r', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'received']],
				],
			],
			],
			'service receipt without id'         => [[
				'replace' => [
					'GoodsReceiptNote' => [],
					'SvcReceipt'       => [
						['administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted'],
						['id' => 'svc-3', 'administrationId' => 'adm-1', 'poIds' => ['po-9'], 'statusCode' => 'accepted'],
					],
				],
			],
			],
			'grn and service receipt'            => [['replace' => $svc]],
			'two service receipts, first no id'  => [[
				'replace' => [
					'GoodsReceiptNote' => [],
					'SvcReceipt'       => [
						['administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted'],
						['id' => 'svc-4', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted'],
					],
					'SvcReceiptLine'   => [
						['id' => 'sl-4', 'serviceReceiptId' => 'svc-4', 'administrationId' => 'adm-1', 'poLineId' => 'pol-1', 'quantityAccepted' => 10],
					],
				],
			],
			],
			'receipt po ids loosely equal'       => [[
				'replace' => [
					'GoodsReceiptNote' => [['id' => 'grn-5', 'administrationId' => 'adm-1', 'poIds' => [true], 'statusCode' => 'accepted']],
					'SvcReceipt'       => [['id' => 'svc-5', 'administrationId' => 'adm-1', 'poIds' => [true], 'statusCode' => 'accepted']],
				],
			],
			],
			'svc line read fails'                => [[
				'replace'   => array_merge(['GoodsReceiptNote' => []], $svc),
				'behaviour' => ['findAll:SvcReceiptLine' => 'throw'],
			],
			],
			'two grn lines summed'               => [[
				'replace' => [
					'GoodsReceiptLine' => [
						['id' => 'grl-1', 'grnId' => 'grn-1', 'administrationId' => 'adm-1', 'poLineId' => 'pol-1', 'quantityReceived' => 4, 'quantityAccepted' => 4],
						['id' => 'grl-2', 'grnId' => 'grn-1', 'administrationId' => 'adm-1', 'poLineId' => 'pol-1', 'quantityReceived' => 6.5],
					],
				],
			],
			],
			'grn line received only'             => [self::grnLine(['quantityAccepted' => null, 'quantityReceived' => 10])],
			'grn line for another po line'       => [self::grnLine(['poLineId' => 'pol-9'])],
			'grn line missing, ordered quantity' => [array_merge(self::grnLine(['poLineId' => 'pol-9']), self::line(['quantity' => 12]))],
			'grn line read fails, ordered qty'   => [array_merge(self::line(['quantity' => 12]), ['behaviour' => ['findAll:GoodsReceiptLine' => 'throw']])],
			'price just over absolute tolerance' => [self::line(['unitPrice' => 1051])],
			'price within absolute tolerance'    => [self::line(['unitPrice' => 1050])],
			'price within percentage tolerance'  => [
				array_merge(
					self::line(['unitPrice' => 1100]),
					self::profiles([array_merge($global, ['priceToleranceAmount' => 10, 'priceTolerancePercentage' => 1000])])
				),
			],
			'price out of tolerance'             => [self::line(['unitPrice' => 1200])],
			'price below the po'                 => [self::line(['unitPrice' => 900])],
			'quantity out of tolerance'          => [self::line(['quantity' => 20])],
			'price and quantity out'             => [self::line(['quantity' => 20, 'unitPrice' => 2000])],
			'vat out of tolerance'               => [self::line(['vatRate' => 0.09])],
			'vat within tolerance'               => [self::line(['vatRate' => 0.2149])],
			'fractional quantity'                => [self::line(['quantity' => 11.9995])],
			'line matched by number'             => [self::line(['productCode' => '  '])],
			'product code beats line number'     => [self::line(['productCode' => ' P-1 ', 'lineNumber' => 7])],
			'invoice line without po line'       => [self::line(['productCode' => 'P-9', 'lineNumber' => 9])],
			'line without code or number'        => [self::line(['productCode' => null, 'lineNumber' => 0])],
			'second line unmatched'              => [['invoice' => ['lines' => $twoLine]]],
			'no invoice lines'                   => [['invoice' => ['lines' => []]]],
			'po unit price zero'                 => [self::poLine(['unitPrice' => 0])],
			'po line without id'                 => [self::poLine(['id' => null, '@self' => ['id' => 'pol-1']])],
			'expected quantity zero'             => [self::grnLine(['quantityAccepted' => 0])],
			'delivery on time'                   => [self::delivery('2026-05-01', '2026-05-01T10:00:00Z')],
			'delivery within date tolerance'     => [self::delivery('2026-05-01', '2026-05-03')],
			'delivery late'                      => [self::delivery('2026-05-01', '2026-05-09')],
			'delivery early'                     => [self::delivery('2026-05-10', '2026-05-01')],
			'delivery date unreadable'           => [self::delivery('soon', '2026-05-09')],
			'delivery date only on po'           => [self::poLine(['expectedDeliveryDate' => '2026-05-01'])],
			'no tolerance profile'               => [array_merge(self::line(['unitPrice' => 1001]), self::profiles([]))],
			'retired profile only'               => [
				array_merge(self::line(['quantity' => 10.5]), self::profiles([array_merge($global, ['status' => 'retired'])])),
			],
			'supplier profile wins'              => [
				self::profiles([$global, $active + ['id' => 'TP-S', 'scope' => 'supplier', 'scopeReference' => 'sup-1', 'quantityTolerancePercentage' => 10]]),
			],
			'category profile wins'              => [
				self::profiles([$global, $active + ['profileId' => 'TP-C', 'scope' => 'category', 'scopeReference' => 'cat-1']]),
			],
			'gl profile wins'                    => [
				self::profiles([$active + ['profileId' => 'TP-L', 'scope' => 'gl_account', 'scopeReference' => '4400', 'quantityTolerancePercentage' => 2000]]),
			],
			'profile without any id'             => [self::profiles([$active + ['scope' => 'global', 'quantityTolerancePercentage' => 2000]])],
			'category from a later po line'      => [[
				'replace' => [
					'PurchaseOrderLine' => [
						array_merge(self::seed()['PurchaseOrderLine'][0], ['productCategory' => '', 'glAccount' => ' ']),
						['id' => 'pol-2', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'lineNumber' => 2, 'productCategory' => 'cat-2', 'glAccount' => '4500'],
					],
				],
			],
			],
			'transition fails'                   => [['transition' => 'throw']],
			'exception transition fails'         => [['transition' => 'throw', 'invoice' => ['matchedPoIds' => null]]],
			'alert fails'                        => [['alert' => 'throw', 'invoice' => ['matchedPoIds' => null]]],
			'no alert service'                   => [['alert' => 'none', 'invoice' => ['matchedPoIds' => null]]],
			'saved exception without id'         => [['behaviour' => ['save:ThreeWayMatch' => 'no-id'], 'invoice' => ['matchedPoIds' => null]]],
			'saved exception with a self id'     => [['behaviour' => ['save:ThreeWayMatch' => 'self-id'], 'invoice' => ['matchedPoIds' => null]]],
			'saving the match fails'             => [['behaviour' => ['save:ThreeWayMatch' => 'throw']]],
			'saving an exception fails'          => [['behaviour' => ['save:ThreeWayMatch' => 'throw'], 'invoice' => ['matchedPoIds' => null]]],
			'blank register setting'             => [['register' => '']],
			'custom register setting'            => [['register' => 'books']],
			'invoice without administration'     => [['invoice' => ['administrationId' => null]]],
		];

	}//end scenarios()

	/**
	 * Every scenario must reproduce its golden record.
	 *
	 * @param array<string, mixed> $scenario Scenario switches.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testEvaluateMatchMatchesTheGoldenRecord(array $scenario): void {
		$name   = (string) $this->dataName();
		$actual = $this->runScenario($scenario);

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			self::$recorded[$name] = $actual;
			$this->addToAssertionCount(1);
			return;
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertSame(json_encode($golden[$name] ?? null), json_encode($actual), $name);

	}//end testEvaluateMatchMatchesTheGoldenRecord()

	/**
	 * Write the golden file in record mode.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1' && self::$recorded !== []) {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			ksort(self::$recorded);
			file_put_contents(self::GOLDEN, json_encode(self::$recorded, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		parent::tearDownAfterClass();

	}//end tearDownAfterClass()

	/**
	 * Build the store for a scenario.
	 *
	 * @param array<string, mixed> $scenario Scenario switches.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function stored(array $scenario): array {
		$stored = self::seed();
		foreach (($scenario['invoice'] ?? []) as $key => $value) {
			$stored['SupplierInvoice'][0][$key] = $value;
			if ($value === null) {
				unset($stored['SupplierInvoice'][0][$key]);
			}
		}

		foreach (($scenario['replace'] ?? []) as $schema => $rows) {
			$stored[$schema] = $rows;
		}

		foreach (['PurchaseOrderLine', 'GoodsReceiptLine'] as $schema) {
			foreach ($stored[$schema] as $i => $row) {
				$stored[$schema][$i] = array_filter($row, static fn ($value): bool => $value !== null);
			}
		}

		return $stored;

	}//end stored()

	/**
	 * Run one scenario and record everything the engine did.
	 *
	 * @param array<string, mixed> $scenario Scenario switches.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$store = new RecordingMatchObjectService(self::stored($scenario), ($scenario['behaviour'] ?? []));
		$log   = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(($scenario['register'] ?? 'shillinq'));
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$log, $level): void {
					$log[] = [$level, $message, $context];
				}
			);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$tolerance = new ToleranceProfileService(container: $container, appConfig: $appConfig, logger: $logger);

		$transitions = [];
		$invoices    = $this->createMock(SupplierInvoiceService::class);
		$invoices->method('setStatus')->willReturnCallback(
			static function (string $administrationId, string $invoiceId, string $toStatus) use (&$transitions, $scenario): array {
				$transitions[] = [$administrationId, $invoiceId, $toStatus];
				if (($scenario['transition'] ?? '') === 'throw') {
					throw new \RuntimeException('illegal transition');
				}

				return [];
			}
		);

		$alerts = [];
		$alert  = null;
		if (($scenario['alert'] ?? '') !== 'none') {
			$alert = $this->createMock(ExceptionResolutionService::class);
			$alert->method('notifyOnException')->willReturnCallback(
				static function (string $administrationId, string $matchId) use (&$alerts, $scenario): void {
					$alerts[] = [$administrationId, $matchId];
					if (($scenario['alert'] ?? '') === 'throw') {
						throw new \RuntimeException('mail down');
					}
				}
			);
		}

		$engine = new ThreeWayMatchingEngine(
			appConfig: $appConfig,
			toleranceService: $tolerance,
			invoiceService: $invoices,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($store),
			exceptionResolution: $alert,
		);

		try {
			$result = $engine->evaluateMatch(($scenario['admin'] ?? 'adm-1'), ($scenario['invoiceId'] ?? 'inv-1'));
		} catch (\Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return [
			'result'      => $result,
			'calls'       => $store->calls,
			'transitions' => $transitions,
			'alerts'      => $alerts,
			'log'         => $log,
		];

	}//end run()
}//end class
