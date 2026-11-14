<?php

/**
 * Edge tests for the both-sides relation report: the open amount of a partly
 * paid invoice, the row cap, an unknown customer, and records whose payload
 * carries no id (reporting-relation-both-sides).
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Relation;

use DomainException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use OCA\Shillinq\Service\Relation\RelationRecords;
use PHPUnit\Framework\TestCase;

/**
 * Edges of RelationBothSidesService and RelationRecords.
 *
 * @covers \OCA\Shillinq\Service\Relation\RelationBothSidesService
 * @covers \OCA\Shillinq\Service\Relation\RelationRecords
 * @uses   \OCA\Shillinq\Service\Relation\RelationReportQuery
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class RelationReportEdgesTest extends TestCase {

	private const BOTH = ['sent' => true, 'received' => true];

	/**
	 * The relation of Reclamebureau Zuid for 2026 over the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data Rows per schema.
	 *
	 * @return array<string, mixed>
	 */
	private function relation(array $data): array {
		return (new RelationBothSidesService(RelationFixture::records($this, $data)))
			->forCustomer(RelationFixture::ADM, 'c-zuid', '2026-01-01', '2026-12-31', self::BOTH);
	}

	/**
	 * An open invoice is open for its amount due when that is known, else for its full amount.
	 *
	 * @return void
	 */
	public function testAPartlyPaidInvoiceIsOpenForItsAmountDue(): void {
		$data = RelationFixture::register();
		$data['ARInvoice'][1]['amountDue'] = 210.0;
		$data['ARInvoice'][] = [
			'id' => 'ar-0360', 'administrationId' => RelationFixture::ADM, 'customerId' => 'c-zuid', 'invoiceNumber' => '2026-0360',
			'invoiceDate' => '2026-04-20', 'lifecycleState' => 'overdue', 'grossAmount' => 300.0, 'invoiceType' => 'standard',
		];

		$relation = $this->relation($data);

		$open = array_column($relation['sent']['rows'], 'open', 'number');
		$this->assertSame(210.0, $open['2026-0355']);
		$this->assertSame(300.0, $open['2026-0360']);
		$this->assertSame(0.0, $open['2026-0310']);
		$this->assertSame(510.0, $relation['totals']['openReceivable']);
	}

	/**
	 * A side lists at most MAX_ROWS rows, newest first, says when there are more, and totals every row.
	 *
	 * @return void
	 */
	public function testASideIsCappedAtMaxRowsAndSaysWhenThereAreMore(): void {
		$data = RelationFixture::register();
		$data['ARInvoice'] = [];
		for ($day = 1; $day <= RelationBothSidesService::MAX_ROWS; $day++) {
			$data['ARInvoice'][] = [
				'id' => 'ar-' . $day, 'administrationId' => RelationFixture::ADM, 'customerId' => 'c-zuid', 'invoiceNumber' => sprintf('N-%03d', $day),
				'invoiceDate' => sprintf('2026-%02d-%02d', (int)ceil($day / 28), ((($day - 1) % 28) + 1)), 'lifecycleState' => 'paid', 'grossAmount' => 10.0,
			];
		}

		$exactly = $this->relation($data)['sent'];
		$this->assertCount(RelationBothSidesService::MAX_ROWS, $exactly['rows']);
		$this->assertFalse($exactly['more'], 'exactly MAX_ROWS rows is not more');

		$data['ARInvoice'][] = [
			'id' => 'ar-oldest', 'administrationId' => RelationFixture::ADM, 'customerId' => 'c-zuid', 'invoiceNumber' => 'N-OLD',
			'invoiceDate' => '2026-01-01', 'lifecycleState' => 'paid', 'grossAmount' => 10.0,
		];
		$capped = $this->relation($data)['sent'];

		$this->assertCount(RelationBothSidesService::MAX_ROWS, $capped['rows']);
		$this->assertTrue($capped['more']);
		$this->assertNotContains('N-OLD', array_column($capped['rows'], 'number'), 'the oldest row falls off the list');
		$this->assertSame('N-050', $capped['rows'][0]['number'], 'newest first');
		$this->assertSame(510.0, $capped['invoiced'], 'the total counts every row, listed or not');
	}

	/**
	 * A customer that does not exist, or lives in another administration, is not found.
	 *
	 * @return void
	 */
	public function testAnUnknownOrForeignCustomerIsNotFound(): void {
		$service = new RelationBothSidesService(RelationFixture::records($this));

		foreach (['c-nobody', 'c-elsewhere', ''] as $customerId) {
			try {
				$service->forCustomer(RelationFixture::ADM, $customerId, '2026-01-01', '2026-12-31', self::BOTH);
				$this->fail($customerId . ' must not be found');
			} catch (DomainException $e) {
				$this->assertSame('Not found', $e->getMessage(), $customerId);
			}
		}
	}

	/**
	 * A record whose payload carries no id gets the id it was asked for; one that is not an object reads as absent.
	 *
	 * @return void
	 */
	public function testOneFillsAMissingIdAndRefusesANonObject(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['administrationId' => RelationFixture::ADM, 'legalName' => 'Zonder Id B.V.']);
		$scalar = $this->createMock(ObjectEntityInterface::class);
		$scalar->method('jsonSerialize')->willReturn('broken');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturnCallback(
			static function ($id) use ($entity, $scalar): ObjectEntityInterface {
				if ($id === 'c-noid') {
					return $entity;
				}

				return $scalar;
			}
		);
		$location = $this->getMockBuilder(RegisterLocation::class)->disableOriginalConstructor()->getMock();
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$records = new RelationRecords($objects, $location);

		$this->assertSame(
			['administrationId' => RelationFixture::ADM, 'legalName' => 'Zonder Id B.V.', 'id' => 'c-noid'],
			$records->one('CustomerMaster', RelationFixture::ADM, 'c-noid')
		);
		$this->assertNull($records->one('CustomerMaster', RelationFixture::ADM, 'c-broken'));
	}
}
