<?php

/**
 * Multi-PO consolidated invoice -> per-trio ThreeWayMatch records
 * integration test for slice 07 of the bookkeeping-purchase-order-3way
 * chain.
 *
 * Wires {@see MultiPoConsolidationService} on top of an in-memory
 * OpenRegister ObjectService stub seeded with a single SupplierInvoice
 * whose lines span several POs/GRNs of the same supplier. The test then:
 *
 *  1. runs consolidateInvoice() to fan out the unambiguous lines + flag
 *     the ambiguous ones + raise an exception_missing_po for an orphan
 *     line — the "mixed outcomes" path the slice-07 spec calls for;
 *  2. resolves the one ambiguous line through
 *     disambiguateAmbiguousMatches() and asserts the chosen trio
 *     materialises as a ThreeWayMatch carrying the disambiguationChoice
 *     snapshot.
 *
 * The slice-07 integration target is "multi-PO consolidated invoice ->
 * per-trio ThreeWayMatch records with mixed outcomes". This file is the
 * integration test the slice-07 tasks call for.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md#tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\MultiPoConsolidationService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration: maand-factuur covering multiple POs -> per-trio
 * ThreeWayMatch records with mixed outcomes (auto / pending /
 * exception_missing_po) and operator-resolved disambiguation.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MultiPoConsolidationIntegrationTest extends TestCase {

	/**
	 * Captured ObjectService saves, populated by the service under test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Build an in-memory OR ObjectService stub seeded with the supplied
	 * schema=>rows map. Captures saves into $this->saved for assertions.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 *
	 * @return object
	 */
	private function objectServiceStub(array $data): object {
		return new class($data, $this->saved) {
			/**
			 * Schema rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Reference to the test's $saved buffer.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Seed.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Equality-filter find.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Persist + capture.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'tw-' . $this->idCounter;
				}

				$rows = ($this->data[$this->schema] ?? []);
				$updated = false;
				foreach ($rows as $i => $row) {
					if (($row['id'] ?? null) === $object['id']) {
						$this->data[$this->schema][$i] = $object;
						$updated = true;
						break;
					}
				}

				if ($updated === false) {
					$this->data[$this->schema][] = $object;
				}

				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end objectServiceStub()

	/**
	 * Multi-PO consolidated maand-factuur -> per-trio ThreeWayMatch
	 * records with mixed outcomes + disambiguation resolution.
	 *
	 * @return void
	 */
	public function testConsolidatedInvoiceFansOutToPerTrioMatchesWithMixedOutcomes(): void {
		// Three invoice lines covering one supplier across multiple POs:
		// - Line 1: COFFEE-PRO-1 — TWO supplier POs match (ambiguous).
		// - Line 2: COFFEE-PRO-2 — exactly one PO+GRN match (auto).
		// - Line 3: COFFEE-MYSTERY — no PO at all (exception).
		$data = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-maand-07',
					'invoiceNumber' => 'INV-MAAND-2026-07',
					'supplierId' => 'sup-1',
					'invoiceDate' => '2026-07-31',
					'currency' => 'EUR',
					'statusCode' => 'received',
					'administrationId' => 'adm-1',
					'lines' => [
						[
							'lineNumber' => 1,
							'productCode' => 'COFFEE-PRO-1',
							'description' => 'Coffee Pro 1 (consolidated)',
							'quantity' => 4.0,
							'unitPrice' => 200000,
						],
						[
							'lineNumber' => 2,
							'productCode' => 'COFFEE-PRO-2',
							'description' => 'Coffee Pro 2',
							'quantity' => 1.0,
							'unitPrice' => 350000,
						],
						[
							'lineNumber' => 3,
							'productCode' => 'COFFEE-MYSTERY',
							'description' => 'Coffee Mystery (unordered)',
							'quantity' => 1.0,
							'unitPrice' => 999900,
						],
					],
				],
			],
			'PurchaseOrder' => [
				[
					'id' => 'po-1',
					'supplierId' => 'sup-1',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'po-2',
					'supplierId' => 'sup-1',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'po-3',
					'supplierId' => 'sup-1',
					'administrationId' => 'adm-1',
				],
			],
			'PurchaseOrderLine' => [
				// Two PO lines for COFFEE-PRO-1 in window -> ambiguous.
				[
					'id' => 'pol-1',
					'poId' => 'po-1',
					'productOrServiceCode' => 'COFFEE-PRO-1',
					'expectedDeliveryDate' => '2026-07-10',
					'unitPrice' => 200000,
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'pol-2',
					'poId' => 'po-2',
					'productOrServiceCode' => 'COFFEE-PRO-1',
					'expectedDeliveryDate' => '2026-07-22',
					'unitPrice' => 200000,
					'administrationId' => 'adm-1',
				],
				// Exactly one PO line for COFFEE-PRO-2 -> unambiguous.
				[
					'id' => 'pol-3',
					'poId' => 'po-3',
					'productOrServiceCode' => 'COFFEE-PRO-2',
					'expectedDeliveryDate' => '2026-07-15',
					'unitPrice' => 350000,
					'administrationId' => 'adm-1',
				],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'poIds' => ['po-1'], 'administrationId' => 'adm-1'],
				['id' => 'grn-2', 'poIds' => ['po-2'], 'administrationId' => 'adm-1'],
				['id' => 'grn-3', 'poIds' => ['po-3'], 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'administrationId' => 'adm-1'],
				['id' => 'grl-2', 'grnId' => 'grn-2', 'poLineId' => 'pol-2', 'administrationId' => 'adm-1'],
				['id' => 'grl-3', 'grnId' => 'grn-3', 'poLineId' => 'pol-3', 'administrationId' => 'adm-1'],
			],
			'ThreeWayMatch' => [],
		];

		$stub = $this->objectServiceStub(data: $data);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $admin): bool => ($admin === 'adm-1')
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);

		$supplierInvoiceService = new SupplierInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$service = new MultiPoConsolidationService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			supplierInvoiceService: $supplierInvoiceService,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// Phase 1 — consolidation fan-out.
		$results = $service->consolidateInvoice(
			administrationId: 'adm-1',
			invoiceId: 'inv-maand-07'
		);

		self::assertCount(3, $results);

		$byLine = [];
		foreach ($results as $result) {
			$byLine[$result['invoiceLineNumber']] = $result;
		}

		self::assertSame('pending', $byLine[1]['status']);
		self::assertSame(2, $byLine[1]['candidateCount']);
		self::assertNull($byLine[1]['matchId']);

		self::assertSame('auto', $byLine[2]['status']);
		self::assertSame(1, $byLine[2]['candidateCount']);
		self::assertNotNull($byLine[2]['matchId']);

		self::assertSame('exception', $byLine[3]['status']);
		self::assertSame(0, $byLine[3]['candidateCount']);
		self::assertNotNull($byLine[3]['matchId']);

		// Two ThreeWayMatch persisted so far (auto + exception).
		$matchSaves = $this->matchSaves();
		self::assertCount(2, $matchSaves);

		$statuses = array_map(
			static fn (array $save): string => (string)$save['object']['matchStatus'],
			$matchSaves
		);
		sort($statuses);
		self::assertSame(['auto_approved', 'exception_missing_po'], $statuses);

		$autoMatch = $this->matchByStatus(needle: 'auto_approved');
		self::assertSame('inv-maand-07', $autoMatch['invoiceId']);
		self::assertSame(2, $autoMatch['invoiceLineNumber']);
		self::assertSame('pol-3', $autoMatch['poLineId']);
		self::assertSame('grl-3', $autoMatch['grnLineId']);
		self::assertSame('COFFEE-PRO-2', $autoMatch['productCode']);
		self::assertSame(['po-3'], $autoMatch['matchedPoIds']);
		self::assertSame(['grn-3'], $autoMatch['matchedGrnIds']);
		self::assertNull($autoMatch['disambiguationChoice']);

		$missingMatch = $this->matchByStatus(needle: 'exception_missing_po');
		self::assertSame(3, $missingMatch['invoiceLineNumber']);
		self::assertSame([], $missingMatch['matchedPoIds']);
		self::assertSame([], $missingMatch['matchedGrnIds']);
		self::assertNull($missingMatch['poLineId']);
		self::assertNull($missingMatch['grnLineId']);

		// Phase 2 — operator resolves the ambiguous line.
		$resolved = $service->disambiguateAmbiguousMatches(
			administrationId: 'adm-1',
			invoiceId: 'inv-maand-07',
			invoiceLineNumber: 1,
			chosenPoLineId: 'pol-2',
			chosenGrnLineId: 'grl-2'
		);

		self::assertSame('inv-maand-07', $resolved['invoiceId']);
		self::assertSame(1, $resolved['invoiceLineNumber']);
		self::assertSame('pol-2', $resolved['poLineId']);
		self::assertSame('grl-2', $resolved['grnLineId']);
		self::assertSame('auto_approved', $resolved['matchStatus']);
		self::assertSame(['po-2'], $resolved['matchedPoIds']);
		self::assertSame(['grn-2'], $resolved['matchedGrnIds']);

		self::assertIsArray($resolved['disambiguationChoice']);
		self::assertSame(2, $resolved['disambiguationChoice']['candidateCount']);
		self::assertSame('pol-2', $resolved['disambiguationChoice']['chosenPoLineId']);
		self::assertSame('grl-2', $resolved['disambiguationChoice']['chosenGrnLineId']);
		self::assertSame(['pol-1'], $resolved['disambiguationChoice']['rejectedPoLineIds']);
		self::assertSame('alice', $resolved['disambiguationChoice']['chosenBy']);

		// Now three ThreeWayMatch records: one per invoice line.
		$allMatches = $this->matchSaves();
		self::assertCount(3, $allMatches);

		$lineToStatus = [];
		foreach ($allMatches as $save) {
			$lineToStatus[(int)$save['object']['invoiceLineNumber']] = (string)$save['object']['matchStatus'];
		}

		ksort($lineToStatus);
		self::assertSame(
			[
				1 => 'auto_approved',
				2 => 'auto_approved',
				3 => 'exception_missing_po',
			],
			$lineToStatus,
			'Each invoice line ends up with its own ThreeWayMatch — mixed outcomes per slice-07 design D9'
		);

		// Slice-07 inline projection — every match is mirrored onto the
		// SupplierInvoice document so the maand-factuur records its PO
		// links inline (REQ-PO3W-007).
		$invoiceSaves = array_values(
			array_filter(
				$this->saved,
				static fn (array $save): bool => $save['schema'] === 'SupplierInvoice'
			)
		);
		self::assertNotEmpty($invoiceSaves, 'Inline projection must save the invoice');

		$latestInvoice = end($invoiceSaves)['object'];
		// Auto match touches po-3, exception touches none, disambiguation
		// touches po-2 — the operator picked pol-2.
		sort($latestInvoice['matchedPoIds']);
		self::assertSame(['po-2', 'po-3'], $latestInvoice['matchedPoIds']);
		self::assertNotEmpty($latestInvoice['consolidatedAt']);

		$linesByNumber = [];
		foreach ($latestInvoice['lines'] as $line) {
			$linesByNumber[$line['lineNumber']] = $line;
		}

		// Line 1 — operator-resolved trio (pol-2, grl-2).
		self::assertSame('pol-2', $linesByNumber[1]['linkedPoLineId']);
		self::assertSame('po-2', $linesByNumber[1]['linkedPoId']);
		self::assertSame('grl-2', $linesByNumber[1]['linkedGrnLineId']);
		self::assertNotEmpty($linesByNumber[1]['linkedMatchId']);

		// Line 2 — auto_approved trio (pol-3, grl-3).
		self::assertSame('pol-3', $linesByNumber[2]['linkedPoLineId']);
		self::assertSame('po-3', $linesByNumber[2]['linkedPoId']);
		self::assertSame('grl-3', $linesByNumber[2]['linkedGrnLineId']);
		self::assertNotEmpty($linesByNumber[2]['linkedMatchId']);

		// Line 3 — exception_missing_po: no PO/GRN links, but matchId is
		// recorded so the UI surfaces the exception without traversing
		// ThreeWayMatch records.
		self::assertNull($linesByNumber[3]['linkedPoLineId']);
		self::assertNull($linesByNumber[3]['linkedPoId']);
		self::assertNull($linesByNumber[3]['linkedGrnLineId']);
		self::assertNotEmpty($linesByNumber[3]['linkedMatchId']);

	}//end testConsolidatedInvoiceFansOutToPerTrioMatchesWithMixedOutcomes()

	/**
	 * Filter the captured saves down to ThreeWayMatch writes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function matchSaves(): array {
		return array_values(
			array_filter(
				$this->saved,
				static fn (array $save): bool => $save['schema'] === 'ThreeWayMatch'
			)
		);

	}//end matchSaves()

	/**
	 * Locate the first ThreeWayMatch save whose matchStatus matches.
	 *
	 * @param string $needle matchStatus.
	 *
	 * @return array<string,mixed>
	 */
	private function matchByStatus(string $needle): array {
		foreach ($this->matchSaves() as $save) {
			if ((string)$save['object']['matchStatus'] === $needle) {
				return $save['object'];
			}
		}

		self::fail('No ThreeWayMatch with matchStatus ' . $needle);

	}//end matchByStatus()
}//end class
