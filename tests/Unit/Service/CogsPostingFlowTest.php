<?php

/**
 * Characterisation tests for CogsPosterService::postCogs().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins the full header and line payloads, the pending-COGS clearing, the
 * missing-account and failure envelopes and their logs.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class CogsPostingFlowTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the service with accounts configured or not.
	 *
	 * @param object $objectService The duck-typed object service.
	 * @param bool   $configured    Whether the COGS and inventory accounts are set.
	 *
	 * @return CogsPosterService
	 */
	private function service(object $objectService, bool $configured): CogsPosterService {
		$config = [];
		if ($configured === true) {
			$config = [CogsPosterService::CFG_COGS_ACCOUNT => ' 7000 ', CogsPosterService::CFG_INVENTORY_ACCOUNT => '3000'];
		}

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		return new CogsPosterService(appConfig: $appConfig, logger: $this->logger, objectService: new DuckObjectServiceAdapter($objectService));

	}//end service()

	/**
	 * The outbound move under test.
	 *
	 * @return array<string,mixed>
	 */
	private function move(): array {
		return [
			'id' => 'mv-9',
			'movementNumber' => 'SM-9',
			'itemId' => 'SKU-9',
			'quantity' => 4,
			'postedAt' => '2026-05-20T10:00:00Z',
			'administrationId' => 'adm-9',
		];

	}//end move()

	/**
	 * Header and both line payloads; a pending valuation is cleared and saved.
	 *
	 * @return void
	 */
	public function testPostedPayloadsAndPendingCleared(): void {
		$os = new InMemoryObjectService();
		$result = $this->service($os, true)->postCogs($this->move(), ['id' => 'val-1', 'pendingCogs' => true], 1001);

		self::assertSame(['posted', 'transaction', 'valuation', 'cogsCents', 'message'], array_keys($result));
		self::assertSame([true, 1001, 'COGS posted'], [$result['posted'], $result['cogsCents'], $result['message']]);
		self::assertFalse($result['valuation']['pendingCogs']);
		self::assertFalse($os->dump(schema: 'InventoryValuation')[0]['pendingCogs']);

		$header = $os->dump(schema: 'GLTransaction')[0];
		$description = 'COGS — SKU-9 — 4,00 × EUR 2,5025';
		foreach ([
			'transactionNumber' => 'COGS-2026-SM-9',
			'postingDate' => '2026-05-20',
			'periodId' => '2026-Q2',
			'currency' => 'EUR',
			'description' => $description,
			'sourceReference' => 'mv-9',
			'state' => 'draft',
			'administrationId' => 'adm-9',
		] as $key => $value) {
			self::assertSame($value, $header[$key], $key);
		}

		$lines = $os->dump(schema: 'GLLine');
		foreach ([[1, '7000', 'debit'], [2, '3000', 'credit']] as $i => [$number, $account, $side]) {
			self::assertSame(
				[
					'transactionId' => $header['id'],
					'administrationId' => 'adm-9',
					'lineNumber' => $number,
					'accountNumber' => $account,
					'side' => $side,
					'amount' => 10.01,
					'currency' => 'EUR',
					'description' => $description,
				],
				array_diff_key($lines[$i], ['id' => 1])
			);
		}

	}//end testPostedPayloadsAndPendingCleared()

	/**
	 * Without accounts the valuation is marked adjusted and pending, with a
	 * warning naming the move.
	 *
	 * @return void
	 */
	public function testMissingAccounts(): void {
		$os = new InMemoryObjectService();
		$service = $this->service($os, false);
		$this->logger->expects(self::once())->method('warning')->with(
			'CogsPosterService: GL accounts not configured — marking valuation adjusted',
			['movementNumber' => 'SM-9', 'cogsAccount' => '', 'inventoryAccount' => '']
		);

		$result = $service->postCogs($this->move(), ['id' => 'val-1', 'status' => 'active'], 500);

		self::assertSame(['posted', 'valuation', 'message'], array_keys($result));
		self::assertSame('GL accounts not configured; valuation marked adjusted', $result['message']);
		self::assertSame(['adjusted', true], [$result['valuation']['status'], $result['valuation']['pendingCogs']]);
		self::assertSame([], $os->dump(schema: 'GLTransaction'));

	}//end testMissingAccounts()

	/**
	 * A failing save logs the error and marks the valuation pending.
	 *
	 * @return void
	 */
	public function testFailureMarksValuationPending(): void {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$store = new class {
			public string $schema = '';

			/** @var array<int,array<string,mixed>> */
			public array $valuations = [];

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function saveObject(array $object): array {
				if ($this->schema === 'GLLine') {
					throw new RuntimeException('ledger down');
				}

				if ($this->schema === 'InventoryValuation') {
					$this->valuations[] = $object;
				}

				$object['id'] = 'x-1';
				return $object;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$service = $this->service($store, true);
		$this->logger->expects(self::once())->method('error')->with(
			'CogsPosterService: failed to post COGS',
			['movementNumber' => 'SM-9', 'cogsCents' => 700, 'exception' => 'ledger down']
		);

		$result = $service->postCogs($this->move(), ['id' => 'val-1'], 700);

		self::assertSame(['posted', 'valuation', 'message'], array_keys($result));
		self::assertSame([false, 'COGS posting raised: ledger down'], [$result['posted'], $result['message']]);
		self::assertSame([['id' => 'val-1', 'status' => 'adjusted', 'pendingCogs' => true]], $store->valuations);

	}//end testFailureMarksValuationPending()
}//end class
