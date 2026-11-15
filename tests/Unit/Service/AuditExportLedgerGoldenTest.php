<?php

/**
 * Golden test for AuditExportService::buildLedger.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AuditExportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the sorted event ledger and summary for bundles covering every
 * event branch of every record type.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AuditExportLedgerGoldenTest extends TestCase {

	/**
	 * Every ledger matches the golden fixture.
	 *
	 * @return void
	 */
	public function testLedgersMatchGolden(): void {
		// No collaborator is read by buildLedger(), so none is wired.
		$service = (new ReflectionClass(AuditExportService::class))->newInstanceWithoutConstructor();
		$result = [];
		foreach ($this->bundles() as $name => $bundle) {
			$result[$name] = $service->buildLedger($bundle);
		}

		$file = __DIR__.'/fixtures/audit-export-ledger.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('AUDIT_LEDGER_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testLedgersMatchGolden()

	/**
	 * The bundles, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function bundles(): array {
		$full = [
			'invoice' => [
				'id' => 'si-1',
				'invoiceNumber' => 'INV-1',
				'supplierId' => 'sup-1',
				'totalInclVat' => '12100',
				'currency' => 'USD',
				'peppolReceivedAt' => '2027-01-10T09:00:00Z',
				'statusCode' => 'approved',
			],
			'purchaseOrders' => [
				[
					'id' => 'po-1',
					'poNumber' => 'PO-1',
					'requesterId' => 'alice',
					'supplierId' => 'sup-1',
					'totalAmount' => 100,
					'costCenter' => 'CC1',
					'createdAt' => '2027-01-01T08:00:00Z',
					'approvalChain' => [
						['decision' => 'approved', 'decidedAt' => '2027-01-02T08:00:00Z', 'userId' => 'bob', 'comment' => 'ok'],
						['decision' => 'pending', 'userId' => 'carol'],
						['decision' => '', 'userId' => 'dave'],
						'not an entry',
						['decision' => 'rejected', 'userId' => 'erin'],
					],
					'peppolSentAt' => '2027-01-03T08:00:00Z',
					'peppolMessageId' => 'msg-1',
				],
				['@self' => ['id' => 'po-2'], 'approvalChain' => [['decision' => 'approved', 'userId' => 'x']]],
				['id' => ' ', 'createdAt' => '2027-01-01T08:00:00Z', 'approvalChain' => 'nope'],
			],
			'goodsReceiptNotes' => [
				[
					'id' => 'grn-1',
					'grnNumber' => 'GRN-1',
					'receivedAt' => '2027-01-05T08:00:00Z',
					'receivedBy' => 'frank',
					'photos' => ['a', 'b'],
					'statusCode' => 'accepted',
					'qualityCheckPassed' => 1,
				],
				['@self' => ['id' => 'grn-2'], 'statusCode' => 'draft', 'receivedAt' => '2027-01-06T08:00:00Z'],
				['id' => 'grn-3', 'statusCode' => 'rejected'],
			],
			'threeWayMatches' => [
				[
					'id' => 'm-1',
					'createdAt' => '2027-01-10T09:00:00Z',
					'matchStatus' => 'diverged',
					'matchedPoIds' => ['po-1'],
					'matchedGrnIds' => 'grn-1',
					'divergenceDetails' => [['field' => 'qty']],
					'resolvedAt' => '2027-01-11T09:00:00Z',
					'resolvedBy' => 'gina',
					'resolutionAction' => 'accept',
				],
				['@self' => ['id' => 'm-2'], 'resolvedAt' => '2027-01-01T08:00:00Z'],
			],
		];
		return [
			'full' => $full,
			'empty' => ['invoice' => [], 'purchaseOrders' => [], 'goodsReceiptNotes' => [], 'threeWayMatches' => []],
			'invoiceStatusOnly' => [
				'invoice' => ['@self' => ['id' => 'si-2'], 'statusCode' => 'new'],
				'purchaseOrders' => [],
				'goodsReceiptNotes' => [],
				'threeWayMatches' => [],
			],
		];

	}//end bundles()
}//end class
