<?php

/**
 * Golden tests for RelationBothSidesService.
 *
 * Pins the whole forCustomer() and forAdministration() answers for every
 * combination of readable sides, so a refactor cannot change which side is
 * restricted, which invoices are listed or how the totals are built.
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

use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use PHPUnit\Framework\TestCase;

/**
 * The relation answers, field by field.
 */
class RelationBothSidesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/relation-both-sides.golden.json';

	/**
	 * Every answer the scenarios give, keyed by scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function answers(): array {
		$data = RelationFixture::register();
		$data['CustomerMaster'][1]['payeeId'] = 'p-noord';
		$data['CustomerMaster'][2]['payeeId'] = 'p-west';
		$data['ARInvoice'][] = ['id' => 'ar-cn', 'administrationId' => RelationFixture::ADM, 'customerId' => 'c-zuid', 'invoiceNumber' => '2026-0400', 'invoiceDate' => '2026-05-01', 'lifecycleState' => 'issued', 'grossAmount' => 242.0, 'amountDue' => 242.0, 'invoiceType' => 'credit-note'];
		$data['SupplierInvoice'][] = ['id' => 'si-new', 'administrationId' => RelationFixture::ADM, 'supplierId' => 'p-zuid', 'invoiceNumber' => 'INK-2026-130', 'invoiceDate' => '2026-06-01', 'statusCode' => 'received', 'totalInclVat' => 100.0];
		$service = new RelationBothSidesService(RelationFixture::records($this, $data));

		$answers = [];
		foreach (['both' => [true, true], 'sentOnly' => [true, false], 'receivedOnly' => [false, true], 'neither' => [false, false]] as $name => [$sent, $received]) {
			$sides = ['sent' => $sent, 'received' => $received];
			$answers['customer.' . $name] = $service->forCustomer(RelationFixture::ADM, 'c-zuid', '2026-01-01', '2026-12-31', $sides);
			$answers['administration.' . $name] = $service->forAdministration(RelationFixture::ADM, '2026-01-01', '2026-12-31', $sides);
		}

		$answers['customer.firstHalf'] = $service->forCustomer(RelationFixture::ADM, 'c-zuid', '2026-01-01', '2026-06-30', ['sent' => true, 'received' => true]);

		return $answers;
	}

	/**
	 * The answers match the recorded golden.
	 *
	 * @return void
	 */
	public function testAnswersMatchTheGolden(): void {
		$actual = json_decode(json_encode($this->answers(), JSON_PRESERVE_ZERO_FRACTION), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}
}
