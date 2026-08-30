<?php

/**
 * Schemas + registers integration test for member 01 of the
 * bookkeeping-purchase-order-3way chain.
 *
 * Loads the slice-01 register fragment + seed fixtures and asserts that all
 * eight declared registers materialise with the right lifecycles and that
 * costCenter + projectCode round-trip from each PurchaseOrder down through
 * its lines, GoodsReceiptNote, SupplierInvoice and ThreeWayMatch records
 * (REQ-PO-010). No OpenRegister runtime is exercised — the fragment JSON is
 * deep-merged into the base shillinq_register.json the same way
 * SettingsService::loadRegisterConfigData() does at install time, then the
 * resulting OpenAPI components are inspected.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the eight 3-way-match registers materialise as declared and that
 * dimensional fields round-trip on the seeded records.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrder3WaySchemasIntegrationTest extends TestCase {

	/**
	 * Slug for every schema this slice declares (alphabetical by display
	 * importance; ThreeWayMatch + ToleranceProfile last because they are
	 * config / outcome rather than transactional).
	 *
	 * @var array<int,string>
	 */
	private const SCHEMAS = [
		'PurchaseOrder',
		'PurchaseOrderLine',
		'GoodsReceiptNote',
		'GoodsReceiptLine',
		'SupplierInvoice',
		'ThreeWayMatch',
		'ToleranceProfile',
		'VendorPerformance',
	];

	/**
	 * Load the base shillinq_register.json + merge every register.d/*.json
	 * fragment exactly the way SettingsService does at install time. Returns
	 * the merged OpenAPI components object.
	 *
	 * @return array<string,mixed>
	 */
	private function loadMergedComponents(): array {
		$basePath = __DIR__ . '/../../lib/Settings/shillinq_register.json';
		$baseRaw = file_get_contents($basePath);
		if ($baseRaw === false) {
			self::fail('Could not read shillinq_register.json base config.');
		}

		$base = json_decode($baseRaw, true);
		if (is_array($base) === false) {
			self::fail('shillinq_register.json base config is not valid JSON.');
		}

		$fragmentDir = __DIR__ . '/../../lib/Settings/register.d';
		$fragments = glob($fragmentDir . '/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragmentRaw = file_get_contents($fragmentPath);
			if ($fragmentRaw === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentRaw, true);
			if (is_array($fragmentData) === false) {
				continue;
			}

			$base = self::deepMerge(base: $base, overlay: $fragmentData);
		}

		return ($base['components'] ?? []);
	}//end loadMergedComponents()

	/**
	 * Deep-merge an overlay onto a base; mirror of SettingsService::deepMergeConfig
	 * (associative arrays merge by key, list arrays concatenate, scalars overwrite).
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge.
	 *
	 * @return array<mixed>
	 */
	private static function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = self::deepMerge(base: $base[$key], overlay: $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;
	}//end deepMerge()

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
	 * All eight registers must materialise after the slice-01 fragment is
	 * merged into the base config.
	 *
	 * @return void
	 */
	public function testAllEightSchemasMaterialise(): void {
		$components = $this->loadMergedComponents();
		self::assertArrayHasKey('schemas', $components, 'Merged config must expose components.schemas.');

		$schemas = $components['schemas'];
		foreach (self::SCHEMAS as $name) {
			self::assertArrayHasKey(
				$name,
				$schemas,
				'Schema ' . $name . ' should be declared by the slice-01 fragment.'
			);
			self::assertSame(
				$name,
				($schemas[$name]['slug'] ?? null),
				'Schema ' . $name . ' should declare a matching slug.'
			);
			self::assertSame(
				'object',
				($schemas[$name]['type'] ?? null),
				'Schema ' . $name . ' should be an object.'
			);
		}

	}//end testAllEightSchemasMaterialise()

	/**
	 * PurchaseOrder lifecycle: every state in the chain + the cancelled
	 * terminal exists, plus the chain transitions.
	 *
	 * @return void
	 */
	public function testPurchaseOrderLifecycleDeclared(): void {
		$components = $this->loadMergedComponents();
		$po = $components['schemas']['PurchaseOrder'];
		self::assertArrayHasKey('x-openregister-lifecycle', $po);

		$lifecycle = $po['x-openregister-lifecycle'];
		self::assertSame('statusCode', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		$expected = [
			'draft',
			'approved',
			'sent',
			'partial_received',
			'fully_received',
			'invoiced',
			'closed',
			'cancelled',
		];
		foreach ($expected as $state) {
			self::assertArrayHasKey(
				$state,
				$lifecycle['states'],
				'PurchaseOrder lifecycle must declare state ' . $state . '.'
			);
		}

		// Spot-check a few transitions on the happy path.
		self::assertArrayHasKey('approve', $lifecycle['transitions']);
		self::assertArrayHasKey('send', $lifecycle['transitions']);
		self::assertArrayHasKey('receivePartial', $lifecycle['transitions']);
		self::assertArrayHasKey('invoice', $lifecycle['transitions']);
		self::assertArrayHasKey('close', $lifecycle['transitions']);

	}//end testPurchaseOrderLifecycleDeclared()

	/**
	 * GoodsReceiptNote lifecycle: draft → received → quality_checked →
	 * accepted → rejected.
	 *
	 * @return void
	 */
	public function testGoodsReceiptNoteLifecycleDeclared(): void {
		$components = $this->loadMergedComponents();
		$grn = $components['schemas']['GoodsReceiptNote'];
		$lifecycle = $grn['x-openregister-lifecycle'];
		self::assertSame('statusCode', $lifecycle['field']);

		foreach (['draft', 'received', 'quality_checked', 'accepted', 'rejected'] as $state) {
			self::assertArrayHasKey(
				$state,
				$lifecycle['states'],
				'GoodsReceiptNote lifecycle must declare state ' . $state . '.'
			);
		}

	}//end testGoodsReceiptNoteLifecycleDeclared()

	/**
	 * SupplierInvoice lifecycle: received → matching → matched → exception →
	 * approved → paid → rejected.
	 *
	 * @return void
	 */
	public function testSupplierInvoiceLifecycleDeclared(): void {
		$components = $this->loadMergedComponents();
		$invoice = $components['schemas']['SupplierInvoice'];
		$lifecycle = $invoice['x-openregister-lifecycle'];
		self::assertSame('received', $lifecycle['initialState']);

		foreach (['received', 'matching', 'matched', 'exception', 'approved', 'paid', 'rejected'] as $state) {
			self::assertArrayHasKey(
				$state,
				$lifecycle['states'],
				'SupplierInvoice lifecycle must declare state ' . $state . '.'
			);
		}

	}//end testSupplierInvoiceLifecycleDeclared()

	/**
	 * ThreeWayMatch matchStatus carries the full enum the matching engine
	 * (member 06) and exception workflow (member 08) will read.
	 *
	 * @return void
	 */
	public function testThreeWayMatchStatusEnum(): void {
		$components = $this->loadMergedComponents();
		$match = $components['schemas']['ThreeWayMatch'];
		self::assertArrayHasKey('matchStatus', $match['properties']);

		$enum = $match['properties']['matchStatus']['enum'];
		foreach ([
			'auto_approved',
			'within_tolerance',
			'exception_price',
			'exception_quantity',
			'exception_missing_grn',
			'exception_missing_po',
			'fraud_alert',
		] as $value
		) {
			self::assertContains(
				$value,
				$enum,
				'ThreeWayMatch.matchStatus must enumerate ' . $value . '.'
			);
		}

	}//end testThreeWayMatchStatusEnum()

	/**
	 * Dimensional fields (costCenter + projectCode) must be declared on
	 * PurchaseOrder, PurchaseOrderLine, GoodsReceiptNote, SupplierInvoice
	 * and ThreeWayMatch so dimensional reporting can run end-to-end (REQ-PO-010).
	 *
	 * @return void
	 */
	public function testDimensionalFieldsOnAllRecords(): void {
		$components = $this->loadMergedComponents();
		$carriers = [
			'PurchaseOrder',
			'PurchaseOrderLine',
			'GoodsReceiptNote',
			'SupplierInvoice',
			'ThreeWayMatch',
		];

		foreach ($carriers as $schemaName) {
			$properties = $components['schemas'][$schemaName]['properties'];
			self::assertArrayHasKey(
				'costCenter',
				$properties,
				$schemaName . ' must declare costCenter for dimensional reporting (REQ-PO-010).'
			);
			self::assertArrayHasKey(
				'projectCode',
				$properties,
				$schemaName . ' must declare projectCode for dimensional reporting (REQ-PO-010).'
			);
		}

	}//end testDimensionalFieldsOnAllRecords()

	/**
	 * Money fields on the order, line, and invoice are integer cents per
	 * ADR-022 so arithmetic stays exact.
	 *
	 * @return void
	 */
	public function testMoneyFieldsAreIntegerCents(): void {
		$components = $this->loadMergedComponents();
		$cases = [
			['PurchaseOrder', 'totalExclVat'],
			['PurchaseOrder', 'totalVat'],
			['PurchaseOrder', 'totalInclVat'],
			['PurchaseOrderLine', 'unitPrice'],
			['PurchaseOrderLine', 'lineTotal'],
			['PurchaseOrderLine', 'vatAmount'],
			['SupplierInvoice', 'totalExclVat'],
			['SupplierInvoice', 'totalVat'],
			['SupplierInvoice', 'totalInclVat'],
		];

		foreach ($cases as [$schema, $field]) {
			self::assertSame(
				'integer',
				($components['schemas'][$schema]['properties'][$field]['type'] ?? null),
				$schema . '.' . $field . ' must be integer cents (ADR-022).'
			);
		}

	}//end testMoneyFieldsAreIntegerCents()

	/**
	 * Seed-data round-trip: every PO's costCenter + projectCode also appear
	 * on the descending lines, GRNs, supplier invoices and matches.
	 *
	 * @return void
	 */
	public function testDimensionalFieldsRoundTripInSeed(): void {
		$seed = $this->seed();
		$poByNum = [];
		foreach ($seed['PurchaseOrder'] as $po) {
			$poByNum[$po['poNumber']] = $po;
		}

		// PO lines inherit costCenter + projectCode from their parent PO.
		foreach ($seed['PurchaseOrderLine'] as $line) {
			$parent = ($poByNum[$line['poId']] ?? null);
			self::assertNotNull($parent, 'PO line ' . $line['poId'] . ' must reference a known PO.');
			self::assertSame(
				$parent['costCenter'],
				($line['costCenter'] ?? null),
				'Line under ' . $line['poId'] . ' must carry the parent PO costCenter.'
			);
			self::assertSame(
				$parent['projectCode'],
				($line['projectCode'] ?? null),
				'Line under ' . $line['poId'] . ' must carry the parent PO projectCode.'
			);
		}

		// GRNs inherit dimensions from their first PO reference.
		foreach ($seed['GoodsReceiptNote'] as $grn) {
			$firstPo = ($grn['poIds'][0] ?? null);
			$parent = ($poByNum[$firstPo] ?? null);
			self::assertNotNull($parent, 'GRN ' . $grn['grnNumber'] . ' must reference a known PO.');
			self::assertSame(
				$parent['costCenter'],
				$grn['costCenter'],
				'GRN ' . $grn['grnNumber'] . ' must carry the parent PO costCenter.'
			);
			self::assertSame(
				$parent['projectCode'],
				$grn['projectCode'],
				'GRN ' . $grn['grnNumber'] . ' must carry the parent PO projectCode.'
			);
		}

		// Three-way matches inherit dimensions from the first matched PO.
		foreach ($seed['ThreeWayMatch'] as $match) {
			$firstPo = ($match['matchedPoIds'][0] ?? null);
			$parent = ($poByNum[$firstPo] ?? null);
			self::assertNotNull($parent, 'Match ' . $match['invoiceId'] . ' must reference a known PO.');
			self::assertSame(
				$parent['costCenter'],
				$match['costCenter'],
				'Match on ' . $match['invoiceId'] . ' must carry the matched PO costCenter.'
			);
			self::assertSame(
				$parent['projectCode'],
				$match['projectCode'],
				'Match on ' . $match['invoiceId'] . ' must carry the matched PO projectCode.'
			);
		}

	}//end testDimensionalFieldsRoundTripInSeed()

	/**
	 * Seed lifecycle states must be members of the declared lifecycle on
	 * each register.
	 *
	 * @return void
	 */
	public function testSeedLifecycleStatesAreDeclared(): void {
		$components = $this->loadMergedComponents();
		$seed = $this->seed();

		$poStates = array_keys($components['schemas']['PurchaseOrder']['x-openregister-lifecycle']['states']);
		foreach ($seed['PurchaseOrder'] as $po) {
			self::assertContains($po['statusCode'], $poStates, 'PO ' . $po['poNumber'] . ' carries a known status.');
		}

		$grnStates = array_keys($components['schemas']['GoodsReceiptNote']['x-openregister-lifecycle']['states']);
		foreach ($seed['GoodsReceiptNote'] as $grn) {
			self::assertContains($grn['statusCode'], $grnStates, 'GRN ' . $grn['grnNumber'] . ' carries a known status.');
		}

		$invStates = array_keys($components['schemas']['SupplierInvoice']['x-openregister-lifecycle']['states']);
		foreach ($seed['SupplierInvoice'] as $invoice) {
			self::assertContains(
				$invoice['statusCode'],
				$invStates,
				'Invoice ' . $invoice['invoiceNumber'] . ' carries a known status.'
			);
		}

		$matchStates = ($components['schemas']['ThreeWayMatch']['properties']['matchStatus']['enum'] ?? []);
		foreach ($seed['ThreeWayMatch'] as $match) {
			self::assertContains(
				$match['matchStatus'],
				$matchStates,
				'Match on ' . $match['invoiceId'] . ' carries a known matchStatus.'
			);
		}

	}//end testSeedLifecycleStatesAreDeclared()

	/**
	 * Seed fixture cardinalities match the design.md seed-data section.
	 *
	 * @return void
	 */
	public function testSeedCardinalitiesMatchDesign(): void {
		$seed = $this->seed();
		self::assertCount(3, $seed['PurchaseOrder']);
		self::assertCount(3, $seed['PurchaseOrderLine']);
		self::assertCount(2, $seed['GoodsReceiptNote']);
		self::assertCount(2, $seed['GoodsReceiptLine']);
		self::assertCount(2, $seed['SupplierInvoice']);
		self::assertCount(2, $seed['ThreeWayMatch']);
		self::assertCount(3, $seed['ToleranceProfile']);
		self::assertCount(2, $seed['VendorPerformance']);

	}//end testSeedCardinalitiesMatchDesign()
}//end class
