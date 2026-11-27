<?php

/**
 * Unit tests for ResetPendingApprovalPurchaseOrders.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Repair\ResetPendingApprovalPurchaseOrders;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * In-memory PurchaseOrder store for the repair step.
 */
final class PendingApprovalOrderStore {

	/**
	 * Stored rows by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $rows = [];

	/**
	 * Saves made, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $saved = [];

	/**
	 * Whether reads fail.
	 *
	 * @var bool
	 */
	public bool $down = false;

	/**
	 * No-op register selection.
	 *
	 * @param string|int $register The register.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		return $this;
	}//end setRegister()

	/**
	 * No-op schema selection.
	 *
	 * @param string|int $schema The schema.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * Rows matching the filters.
	 *
	 * @param array<string,mixed> $config The query.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findAll(array $config = []): array {
		if ($this->down === true) {
			throw new RuntimeException('PurchaseOrder schema is not imported yet');
		}

		$matches = [];
		foreach ($this->rows as $uuid => $row) {
			foreach (($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$matches[] = $row + ['id' => $uuid];
		}

		return array_slice($matches, (int) ($config['offset'] ?? 0), (int) ($config['limit'] ?? count($matches)));
	}//end findAll()

	/**
	 * Record and apply a save.
	 *
	 * @param array<string,mixed> $object        The object.
	 * @param ObjectSaveOptions   $options       The save options.
	 * @param bool                $_rbac         RBAC flag.
	 * @param bool                $_multitenancy Multitenancy flag.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
		$uuid          = (string) $options->uuid;
		$this->saved[] = ['object' => $object, 'uuid' => $uuid, 'rbac' => $_rbac, 'schema' => $options->target->schema];
		$this->rows[$uuid] = $object;
		return $object + ['id' => $uuid];
	}//end saveObject()
}//end class

/**
 * The repair step moves only pending-approval orders, once.
 *
 * @covers \OCA\Shillinq\Repair\ResetPendingApprovalPurchaseOrders
 */
final class ResetPendingApprovalPurchaseOrdersTest extends TestCase {

	/**
	 * The step over a store.
	 *
	 * @param PendingApprovalOrderStore $store The store.
	 *
	 * @return ResetPendingApprovalPurchaseOrders
	 */
	private function step(PendingApprovalOrderStore $store): ResetPendingApprovalPurchaseOrders {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new ResetPendingApprovalPurchaseOrders(
			settingsService: $settings,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end step()

	/**
	 * A pending-approval order loses the undeclared field and starts in draft; a rerun saves nothing.
	 *
	 * @return void
	 */
	public function testAPendingApprovalOrderMovesToDraftOnce(): void {
		$store       = new PendingApprovalOrderStore();
		$store->rows = [
			'po-old' => ['poNumber' => 'PO-2026-001', 'lifecycleState' => 'pending_approval', 'approvalChain' => [['role' => 'teamleider', 'decision' => 'pending']]],
			'po-draft' => ['poNumber' => 'PO-2026-002', 'statusCode' => 'draft'],
			'po-sent' => ['poNumber' => 'PO-2026-003', 'statusCode' => 'sent'],
		];
		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))->method('info')->with(self::stringContains('moved to draft'));

		$this->step(store: $store)->run(output: $output);

		self::assertCount(1, $store->saved);
		self::assertSame('po-old', $store->saved[0]['uuid']);
		self::assertSame('PurchaseOrder', $store->saved[0]['schema']);
		self::assertFalse($store->saved[0]['rbac']);
		self::assertSame('draft', $store->saved[0]['object']['statusCode']);
		self::assertArrayNotHasKey('lifecycleState', $store->saved[0]['object']);
		self::assertArrayNotHasKey('id', $store->saved[0]['object']);
		self::assertSame([['role' => 'teamleider', 'decision' => 'pending']], $store->saved[0]['object']['approvalChain'], 'the history stays');
		self::assertSame('sent', $store->rows['po-sent']['statusCode']);

		$this->step(store: $store)->run(output: $output);
		self::assertCount(1, $store->saved, 'a second run wrote again');
	}//end testAPendingApprovalOrderMovesToDraftOnce()

	/**
	 * A statusCode already set is kept; only the undeclared field goes.
	 *
	 * @return void
	 */
	public function testASetStatusCodeIsKept(): void {
		$store       = new PendingApprovalOrderStore();
		$store->rows = ['po-mixed' => ['lifecycleState' => 'pending_approval', 'statusCode' => 'approved']];

		$this->step(store: $store)->run(output: $this->createStub(IOutput::class));

		self::assertSame(['statusCode' => 'approved'], $store->saved[0]['object']);
	}//end testASetStatusCodeIsKept()

	/**
	 * A failing read warns instead of blocking the upgrade, and saves nothing.
	 *
	 * @return void
	 */
	public function testAFailingReadWarnsInsteadOfBlockingTheUpgrade(): void {
		$store       = new PendingApprovalOrderStore();
		$store->down = true;
		$output      = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('not imported yet'));

		$this->step(store: $store)->run(output: $output);

		self::assertSame([], $store->saved);
		self::assertStringContainsString('draft', $this->step(store: $store)->getName());
	}//end testAFailingReadWarnsInsteadOfBlockingTheUpgrade()
}//end class
