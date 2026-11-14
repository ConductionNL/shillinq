<?php

/**
 * Unit tests for the refusals and empty answers of the relation services.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Relation;

use DomainException;
use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use OCA\Shillinq\Service\Relation\RelationLinkService;
use PHPUnit\Framework\TestCase;

/**
 * Linking refuses what it cannot do; reads answer nothing for what is not there.
 *
 * @covers \OCA\Shillinq\Service\Relation\RelationLinkService
 * @covers \OCA\Shillinq\Service\Relation\RelationBothSidesService
 * @covers \OCA\Shillinq\Service\Relation\RelationRecords
 * @uses   \OCA\Shillinq\Service\Relation\RelationReportQuery
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class RelationGuardsTest extends TestCase {

	/**
	 * Each refused link, and why.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function refusedLinks(): array {
		return [
			'unknown supplier' => ['c-noord', 'p-gone', 'kvk', 'Not found'],
			'unknown customer' => ['c-gone', 'p-noord', 'kvk', 'Not found'],
			'customer of another administration' => ['c-elsewhere', 'p-noord', 'kvk', 'Not found'],
			'matched on something else' => ['c-noord', 'p-noord', 'email', 'A link is matched on kvk, vat or manual'],
			'customer linked to another supplier' => ['c-zuid', 'p-noord', 'kvk', 'This customer is already linked to another supplier'],
		];
	}//end refusedLinks()

	/**
	 * A link to something missing, or matched on an unknown basis, is refused
	 * and nothing is saved.
	 *
	 * @param string $customer  The customer.
	 * @param string $payee     The supplier.
	 * @param string $matchedOn The basis.
	 * @param string $message   The refusal.
	 *
	 * @dataProvider refusedLinks
	 *
	 * @return void
	 */
	public function testARefusedLinkSavesNothing(string $customer, string $payee, string $matchedOn, string $message): void {
		$saved = [];
		$service = new RelationLinkService(RelationFixture::records($this, null, $saved));

		try {
			$service->link(RelationFixture::ADM, $customer, $payee, 'petra', $matchedOn);
			$this->fail('Expected a refusal');
		} catch (DomainException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame([], $saved);
	}//end testARefusedLinkSavesNothing()

	/**
	 * Dismissing without a supplier, or unlinking an unknown customer, is refused.
	 *
	 * @return void
	 */
	public function testDismissAndUnlinkRefuseWhatIsNotThere(): void {
		$service = new RelationLinkService(RelationFixture::records($this));

		try {
			$service->dismiss(RelationFixture::ADM, 'c-noord', '');
			$this->fail('Expected a refusal');
		} catch (DomainException $e) {
			$this->assertSame('Not found', $e->getMessage());
		}

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Not found');
		$service->unlink(RelationFixture::ADM, 'c-gone');
	}//end testDismissAndUnlinkRefuseWhatIsNotThere()

	/**
	 * The customer linked to a supplier is found; no supplier finds none.
	 *
	 * @return void
	 */
	public function testCustomerOfPayee(): void {
		$service = new RelationBothSidesService(RelationFixture::records($this));

		$this->assertSame('c-zuid', $service->customerOfPayee(RelationFixture::ADM, 'p-zuid'));
		$this->assertNull($service->customerOfPayee(RelationFixture::ADM, 'p-west'));
		$this->assertNull($service->customerOfPayee(RelationFixture::ADM, ''));
	}//end testCustomerOfPayee()

	/**
	 * A customer linked to no supplier has an empty received side.
	 *
	 * @return void
	 */
	public function testAnUnlinkedCustomerReceivedNothing(): void {
		$relation = (new RelationBothSidesService(RelationFixture::records($this)))
			->forCustomer(RelationFixture::ADM, 'c-oost', '2026-01-01', '2026-12-31', ['sent' => true, 'received' => true]);

		$this->assertSame(['id' => '', 'name' => ''], $relation['payee']);
		$this->assertSame(
			['restricted' => false, 'rows' => [], 'more' => false, 'invoiced' => 0.0, 'open' => 0.0],
			$relation['received']
		);
	}//end testAnUnlinkedCustomerReceivedNothing()

	/**
	 * The CSV leaves missing cells empty, formats amounts, and quotes text
	 * with commas, quotes or newlines.
	 *
	 * @return void
	 */
	public function testCsvCells(): void {
		$csv = (new RelationBothSidesService(RelationFixture::records($this)))->toCsv(
			[
				['name' => 'Zuid, "de" B.V.', 'sales' => 1210, 'purchases' => 2662.5, 'net' => -1452.5],
				['name' => "Twee\nregels", 'openReceivable' => 0.0],
			]
		);

		$this->assertSame(
			"name,sales,purchases,openReceivable,openPayable,net\n"
			. "\"Zuid, \"\"de\"\" B.V.\",1210.00,2662.50,,,-1452.50\n"
			. "\"Twee\nregels\",,,0.00,,\n",
			$csv
		);
	}//end testCsvCells()
}//end class
