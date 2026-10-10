<?php

/**
 * Unit tests for MovePendingApprovalOrdersToDraft.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\MovePendingApprovalOrdersToDraft;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Orders parked in the undeclared `pending_approval` state go back to draft.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class MovePendingApprovalOrdersToDraftTest extends TestCase {

	/**
	 * A pending order without a status and one in draft are moved; an
	 * approved order keeps its state; a rerun changes nothing.
	 *
	 * @return void
	 */
	public function testPendingOrdersReturnToDraftOnce(): void {
		$store = new InMemoryObjectServiceStub(
			[
				'PurchaseOrder' => [
					['id' => 'po-1', 'poNumber' => 'PO-2026-001', 'lifecycleState' => 'pending_approval', 'administrationId' => 'adm-1'],
					['id' => 'po-2', 'poNumber' => 'PO-2026-002', 'lifecycleState' => 'pending_approval', 'statusCode' => 'draft', 'administrationId' => 'adm-1'],
					['id' => 'po-3', 'poNumber' => 'PO-2026-003', 'statusCode' => 'approved', 'administrationId' => 'adm-1'],
				],
			]
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$step = new MovePendingApprovalOrdersToDraft($store, $settings, $this->createMock(LoggerInterface::class));

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$step->run($output);
		$step->run($output);

		$this->assertSame(
			[
				'Shillinq: 2 purchase order(s) waiting in pending_approval moved back to draft.',
				'Shillinq: 0 purchase order(s) waiting in pending_approval moved back to draft.',
			],
			$messages
		);

		foreach (['po-1', 'po-2'] as $id) {
			$order = $store->find(id: $id, schema: 'PurchaseOrder')->getObject();
			$this->assertSame('draft', $order['statusCode']);
			$this->assertNull($order['lifecycleState']);
		}

		$this->assertSame('approved', $store->find(id: 'po-3', schema: 'PurchaseOrder')->getObject()['statusCode']);
	}//end testPendingOrdersReturnToDraftOnce()
}//end class
