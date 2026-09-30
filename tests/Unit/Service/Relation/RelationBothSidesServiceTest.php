<?php

/**
 * Tests for RelationBothSidesService (reporting-relation-both-sides REQ-RRBS-002 to 004).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Relation;

use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use PHPUnit\Framework\TestCase;

/**
 * Both sides, their totals and the report.
 */
class RelationBothSidesServiceTest extends TestCase {

	private const BOTH = ['sent' => true, 'received' => true];

	/**
	 * Scenario "The bookkeeper sees who owes whom".
	 *
	 * @return void
	 */
	public function testTheBookkeeperSeesWhoOwesWhom(): void {
		$relation = (new RelationBothSidesService(RelationFixture::records($this)))->forCustomer(RelationFixture::ADM, 'c-zuid', '2026-01-01', '2026-12-31', self::BOTH);

		$this->assertSame(['2026-0355', '2026-0310'], array_column($relation['sent']['rows'], 'number'));
		$this->assertSame(['INK-2026-118'], array_column($relation['received']['rows'], 'number'), 'a voided transaction and a supplier invoice already handed to payables are not listed');
		$this->assertSame(
			['sales' => 5445.0, 'purchases' => 2662.0, 'openReceivable' => 1210.0, 'openPayable' => 2662.0, 'net' => -1452.0],
			$relation['totals']
		);
		$this->assertSame('kvk', $relation['link']['matchedOn']);
		$this->assertSame('Reclamebureau Zuid B.V.', $relation['payee']['name']);
	}

	/**
	 * Scenario "An AR controller without purchase access": the purchase side is restricted and counts nowhere.
	 *
	 * @return void
	 */
	public function testASideTheCallerMayNotReadIsRestricted(): void {
		$relation = (new RelationBothSidesService(RelationFixture::records($this)))->forCustomer(RelationFixture::ADM, 'c-zuid', '2026-01-01', '2026-12-31', ['sent' => true, 'received' => false]);

		$this->assertTrue($relation['received']['restricted']);
		$this->assertSame([], $relation['received']['rows']);
		$this->assertSame(['sales' => 5445.0, 'purchases' => null, 'openReceivable' => 1210.0, 'openPayable' => null, 'net' => null], $relation['totals']);
	}

	/**
	 * A credit note counts negative, and a supplier invoice not yet in payables counts on the purchase side.
	 *
	 * @return void
	 */
	public function testACreditNoteCountsNegative(): void {
		$data = RelationFixture::register();
		$data['ARInvoice'][] = ['id' => 'ar-cn', 'administrationId' => RelationFixture::ADM, 'customerId' => 'c-zuid', 'invoiceNumber' => '2026-0400', 'invoiceDate' => '2026-05-01', 'lifecycleState' => 'issued', 'grossAmount' => 242.0, 'amountDue' => 242.0, 'invoiceType' => 'credit-note'];
		$data['SupplierInvoice'][] = ['id' => 'si-new', 'administrationId' => RelationFixture::ADM, 'supplierId' => 'p-zuid', 'invoiceNumber' => 'INK-2026-130', 'invoiceDate' => '2026-06-01', 'statusCode' => 'received', 'totalInclVat' => 100.0];

		$totals = (new RelationBothSidesService(RelationFixture::records($this, $data)))->forCustomer(RelationFixture::ADM, 'c-zuid', '2026-01-01', '2026-12-31', self::BOTH)['totals'];

		$this->assertSame(['sales' => 5203.0, 'purchases' => 2762.0, 'openReceivable' => 968.0, 'openPayable' => 2762.0, 'net' => -1794.0], $totals);
	}

	/**
	 * Scenario "The controller exports the year": one row per linked relation with the five amounts.
	 *
	 * @return void
	 */
	public function testTheControllerExportsTheYear(): void {
		$data = RelationFixture::register();
		$data['CustomerMaster'][1]['payeeId'] = 'p-noord';
		$data['CustomerMaster'][2]['payeeId'] = 'p-west';
		$service = new RelationBothSidesService(RelationFixture::records($this, $data));

		$report = $service->forAdministration(RelationFixture::ADM, '2026-01-01', '2026-12-31', self::BOTH);

		$this->assertSame(['Drukwerk Oost B.V.', 'Reclamebureau Zuid B.V.', 'Transport Noord B.V.'], array_column($report['relations'], 'name'));
		$this->assertSame(
			"name,sales,purchases,openReceivable,openPayable,net\n"
			. "Drukwerk Oost B.V.,0.00,0.00,0.00,0.00,0.00\n"
			. "Reclamebureau Zuid B.V.,5445.00,2662.00,1210.00,2662.00,-1452.00\n"
			. "Transport Noord B.V.,0.00,0.00,0.00,0.00,0.00\n",
			$service->toCsv($report['relations'])
		);
	}

	/**
	 * The supplier page finds its customer.
	 *
	 * @return void
	 */
	public function testTheSupplierPageFindsItsCustomer(): void {
		$service = new RelationBothSidesService(RelationFixture::records($this));

		$this->assertSame('c-zuid', $service->customerOfPayee(RelationFixture::ADM, 'p-zuid'));
		$this->assertNull($service->customerOfPayee(RelationFixture::ADM, 'p-west'));
	}
}
