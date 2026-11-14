<?php

/**
 * Unit tests for AlignPurchaseOrderStatusCode.
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
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\AlignPurchaseOrderStatusCode;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A PurchaseOrder store with equality filters, paging and captured saves.
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- _rbac/_multitenancy mirror OR's API.
 */
final class AlignPurchaseOrderStore {
	/**
	 * Rows by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every save.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null, rbac: bool}>
	 */
	public array $saved = [];

	/**
	 * Make reads throw.
	 *
	 * @var bool
	 */
	public bool $down = false;

	/**
	 * @param string|int $register Unused.
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		return $this;
	}//end setRegister()

	/**
	 * @param string|int $schema Unused.
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Filters, limit and offset.
	 * @return array<int, array<string, mixed>>
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

		return array_slice($matches, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? count($matches)));
	}//end findAll()

	/**
	 * @param array<string, mixed> $object The object.
	 * @param array|null $extend Unused.
	 * @param mixed $register Unused.
	 * @param mixed $schema Unused.
	 * @param string|null $uuid The uuid to update.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Unused.
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saved[] = ['object' => $object, 'uuid' => $uuid, 'rbac' => $_rbac];
		$this->rows[(string)$uuid] = $object;

		return $object + ['id' => $uuid];
	}//end saveObject()
}//end class

/**
 * An order sent under the old field gets the declared state once.
 *
 * @covers \OCA\Shillinq\Repair\AlignPurchaseOrderStatusCode
 * @uses \OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches
 * @uses \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-1.1
 */
final class AlignPurchaseOrderStatusCodeTest extends TestCase {

	/**
	 * The step over a store.
	 *
	 * @param AlignPurchaseOrderStore $store The store.
	 *
	 * @return AlignPurchaseOrderStatusCode
	 */
	private function step(AlignPurchaseOrderStore $store): AlignPurchaseOrderStatusCode {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new AlignPurchaseOrderStatusCode(
			settingsService: $settings,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end step()

	/**
	 * Only the approved order the old field marked sent moves, unscoped, once.
	 *
	 * @return void
	 */
	public function testTheSeedOrderMovesOnce(): void {
		$store = new AlignPurchaseOrderStore();
		$store->rows = [
			'po-31' => ['poNumber' => 'PO-2026-031', 'statusCode' => 'approved', 'lifecycleState' => 'sent'],
			'po-32' => ['poNumber' => 'PO-2026-032', 'statusCode' => 'approved'],
			'po-33' => ['poNumber' => 'PO-2026-033', 'statusCode' => 'draft', 'lifecycleState' => 'sent'],
			'po-34' => ['poNumber' => 'PO-2026-034', 'statusCode' => 'approved', 'lifecycleState' => 'pending_approval'],
		];
		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))->method('info')->willReturnCallback(
			static function (string $message): void {
				self::assertMatchesRegularExpression('/^Shillinq: [01] purchase order\(s\) moved to the declared state sent\.$/', $message);
			}
		);

		$this->step(store: $store)->run(output: $output);

		self::assertCount(1, $store->saved);
		self::assertSame('po-31', $store->saved[0]['uuid']);
		self::assertFalse($store->saved[0]['rbac']);
		self::assertSame('sent', $store->saved[0]['object']['statusCode']);
		self::assertArrayNotHasKey('id', $store->saved[0]['object']);
		self::assertSame('PO-2026-031', $store->saved[0]['object']['poNumber']);
		self::assertSame('draft', $store->rows['po-33']['statusCode']);

		$this->step(store: $store)->run(output: $output);
		self::assertCount(1, $store->saved, 'a second run wrote again');
	}//end testTheSeedOrderMovesOnce()

	/**
	 * A failing read warns and never blocks the upgrade.
	 *
	 * @return void
	 */
	public function testAFailingReadWarnsInsteadOfBlockingTheUpgrade(): void {
		$store = new AlignPurchaseOrderStore();
		$store->down = true;
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('not imported yet'));

		$this->step(store: $store)->run(output: $output);

		self::assertSame([], $store->saved);
		self::assertStringContainsString('purchase orders', $this->step(store: $store)->getName());
	}//end testAFailingReadWarnsInsteadOfBlockingTheUpgrade()
}//end class
