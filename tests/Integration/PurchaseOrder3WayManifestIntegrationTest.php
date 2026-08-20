<?php

/**
 * Manifest + seed-queryability integration test for member 01 of the
 * bookkeeping-purchase-order-3way chain.
 *
 * Asserts that src/manifest.json exposes the five navigation entries
 * (Purchase Orders, Goods Receipts, Supplier Invoices, 3-way Matches,
 * Match Exceptions) declared by slice-01, each wired to an index and a
 * detail page reading the right register/schema, and that the seeded
 * records the integration tests rely on are queryable by their declared
 * primary identifiers (poNumber, grnNumber, invoiceNumber, etc.).
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-01-schemas-and-registers/tasks.md#manifest-navigation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the 5 navigation entries reach index/detail pages and the seeded
 * records are queryable by primary identifier.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrder3WayManifestIntegrationTest extends TestCase {

	/**
	 * Required navigation entries declared by slice-01. Each maps a menu
	 * route id to the (index page id, detail page id, register schema)
	 * triple that page resolves to.
	 *
	 * @var array<string,array{0:string,1:string,2:string}>
	 */
	private const NAV_ENTRIES = [
		'PurchaseOrders' => ['PurchaseOrders', 'PurchaseOrderDetail', 'PurchaseOrder'],
		'GoodsReceipts' => ['GoodsReceipts', 'GoodsReceiptDetail', 'GoodsReceiptNote'],
		'SupplierInvoices' => ['SupplierInvoices', 'SupplierInvoiceDetail', 'SupplierInvoice'],
		'ThreeWayMatches' => ['ThreeWayMatches', 'ThreeWayMatchDetail', 'ThreeWayMatch'],
		'ThreeWayMatchExceptions' => ['ThreeWayMatchExceptions', 'ThreeWayMatchDetail', 'ThreeWayMatch'],
	];

	/**
	 * Read and decode src/manifest.json once.
	 *
	 * @return array<string,mixed>
	 */
	private function manifest(): array {
		$path = __DIR__ . '/../../src/manifest.json';
		$raw = file_get_contents($path);
		if ($raw === false) {
			self::fail('Could not read src/manifest.json.');
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			self::fail('src/manifest.json is not valid JSON.');
		}

		return $data;
	}//end manifest()

	/**
	 * Recursively flatten a menu tree into a flat list of leaf items
	 * (each carrying its route + id).
	 *
	 * @param array<int,array<string,mixed>> $items Menu children.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function flattenMenu(array $items): array {
		$flat = [];
		foreach ($items as $item) {
			if (isset($item['route']) === true) {
				$flat[] = $item;
			}

			if (isset($item['children']) === true && is_array($item['children']) === true) {
				$flat = array_merge($flat, $this->flattenMenu($item['children']));
			}
		}

		return $flat;
	}//end flattenMenu()

	/**
	 * Index pages keyed by page id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function pagesById(): array {
		$manifest = $this->manifest();
		$byId = [];
		foreach ($manifest['pages'] as $page) {
			$byId[$page['id']] = $page;
		}

		return $byId;
	}//end pagesById()

	/**
	 * Load the slice-01 seed fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function seed(): array {
		$path = __DIR__ . '/../fixtures/PurchaseOrder3WayMatchSeedData.json';
		$raw = file_get_contents($path);
		if ($raw === false) {
			self::fail('Could not read PurchaseOrder3WayMatchSeedData fixture.');
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			self::fail('PurchaseOrder3WayMatchSeedData fixture is not valid JSON.');
		}

		return $data;
	}//end seed()

	/**
	 * Each of the 5 nav entries is declared in the menu tree.
	 *
	 * @return void
	 */
	public function testFiveNavigationEntriesPresentInMenu(): void {
		$manifest = $this->manifest();
		$leaves = $this->flattenMenu($manifest['menu']);
		$routes = array_column($leaves, 'route');

		foreach (array_keys(self::NAV_ENTRIES) as $route) {
			self::assertContains(
				$route,
				$routes,
				'Menu must include navigation entry for ' . $route . '.'
			);
		}

	}//end testFiveNavigationEntriesPresentInMenu()

	/**
	 * Each nav entry resolves to its declared index page, and each index
	 * page declares the right detail-route id pointing at a detail page on
	 * the same schema.
	 *
	 * @return void
	 */
	public function testEachNavEntryResolvesToIndexAndDetail(): void {
		$pages = $this->pagesById();

		foreach (self::NAV_ENTRIES as $route => [$indexId, $detailId, $schemaSlug]) {
			self::assertArrayHasKey($indexId, $pages, 'Index page ' . $indexId . ' must exist.');
			self::assertArrayHasKey($detailId, $pages, 'Detail page ' . $detailId . ' must exist.');

			$indexPage = $pages[$indexId];
			$detailPage = $pages[$detailId];

			self::assertSame('index', $indexPage['type'], 'Page ' . $indexId . ' must be an index.');
			self::assertSame('detail', $detailPage['type'], 'Page ' . $detailId . ' must be a detail.');
			self::assertSame(
				$schemaSlug,
				($indexPage['config']['schema'] ?? null),
				'Page ' . $indexId . ' must read register schema ' . $schemaSlug . '.'
			);
			self::assertSame(
				$schemaSlug,
				($detailPage['config']['schema'] ?? null),
				'Page ' . $detailId . ' must read register schema ' . $schemaSlug . '.'
			);
		}

	}//end testEachNavEntryResolvesToIndexAndDetail()

	/**
	 * The Exceptions index page is a filtered ThreeWayMatch view restricted
	 * to matchStatus ∈ exception_* + fraud_alert.
	 *
	 * @return void
	 */
	public function testExceptionsIndexFiltersOnExceptionMatchStatuses(): void {
		$pages = $this->pagesById();
		$excPage = $pages['ThreeWayMatchExceptions'];
		$filter = ($excPage['config']['filter']['matchStatus']['in'] ?? null);

		self::assertIsArray($filter, 'Exceptions index must declare an in-filter on matchStatus.');
		foreach ([
			'exception_price',
			'exception_quantity',
			'exception_missing_grn',
			'exception_missing_po',
			'fraud_alert',
		] as $expected
		) {
			self::assertContains(
				$expected,
				$filter,
				'Exceptions filter must include ' . $expected . '.'
			);
		}

	}//end testExceptionsIndexFiltersOnExceptionMatchStatuses()

	/**
	 * Each index page declares at least one sortable column on its primary
	 * identifier (poNumber / grnNumber / invoiceNumber / invoiceId) so the
	 * operator can find seeded records.
	 *
	 * @return void
	 */
	public function testSeedRecordsAreQueryableByPrimaryIdentifier(): void {
		$pages = $this->pagesById();
		$expectKey = [
			'PurchaseOrders' => 'poNumber',
			'GoodsReceipts' => 'grnNumber',
			'SupplierInvoices' => 'invoiceNumber',
			'ThreeWayMatches' => 'invoiceId',
			'ThreeWayMatchExceptions' => 'invoiceId',
		];

		foreach ($expectKey as $pageId => $primaryKey) {
			$columns = ($pages[$pageId]['config']['columns'] ?? []);
			$keys = array_column($columns, 'key');
			self::assertContains(
				$primaryKey,
				$keys,
				'Index page ' . $pageId . ' must expose column ' . $primaryKey . ' so seeded records are queryable.'
			);
		}

	}//end testSeedRecordsAreQueryableByPrimaryIdentifier()

	/**
	 * Seeded records carry the primary identifiers the index pages query
	 * on, so a UI sort+search round-trip finds them.
	 *
	 * @return void
	 */
	public function testSeedRecordsCarryQueryableIdentifiers(): void {
		$seed = $this->seed();

		$expectedPoNumbers = ['PO-2026-0001', 'PO-2026-0002', 'PO-2026-0003'];
		$actualPoNumbers = array_column($seed['PurchaseOrder'], 'poNumber');
		sort($actualPoNumbers);
		self::assertSame($expectedPoNumbers, $actualPoNumbers);

		$expectedGrnNumbers = ['GRN-2026-0011', 'GRN-2026-0012'];
		$actualGrnNumbers = array_column($seed['GoodsReceiptNote'], 'grnNumber');
		sort($actualGrnNumbers);
		self::assertSame($expectedGrnNumbers, $actualGrnNumbers);

		$invoiceNumbers = array_column($seed['SupplierInvoice'], 'invoiceNumber');
		self::assertContains('INV-ERS-2026-00445', $invoiceNumbers);
		self::assertContains('INV-NL-2026-18547', $invoiceNumbers);

		$toleranceProfileIds = array_column($seed['ToleranceProfile'], 'profileId');
		self::assertContains('TP-GLOBAL-DEFAULT', $toleranceProfileIds);
		self::assertContains('TP-SUPPLIER-NieuweLeverancierBV', $toleranceProfileIds);
		self::assertContains('TP-CATEGORY-ElectricalEquipment', $toleranceProfileIds);

	}//end testSeedRecordsCarryQueryableIdentifiers()

	/**
	 * Each navigation entry must be reachable via the existing Inkoop menu
	 * branch — slice-01 deliberately groups every 3-way-match nav under
	 * Inkoop rather than creating a new top-level menu.
	 *
	 * @return void
	 */
	public function testNewEntriesLiveUnderInkoopMenu(): void {
		$manifest = $this->manifest();
		$inkoop = null;
		foreach ($manifest['menu'] as $top) {
			if (($top['id'] ?? null) === 'Inkoop') {
				$inkoop = $top;
				break;
			}
		}

		self::assertNotNull($inkoop, 'Manifest must declare a top-level Inkoop menu.');
		$childIds = array_column(($inkoop['children'] ?? []), 'id');
		foreach (array_keys(self::NAV_ENTRIES) as $route) {
			self::assertContains(
				$route,
				$childIds,
				'Inkoop menu must include child ' . $route . '.'
			);
		}

	}//end testNewEntriesLiveUnderInkoopMenu()
}//end class
