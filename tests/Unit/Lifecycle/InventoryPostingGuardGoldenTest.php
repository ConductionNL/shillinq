<?php

/**
 * Golden test for the InventoryPostingGuard posting, variance and FK gates.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InventoryPostingGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with INVENTORY_POSTING_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class InventoryPostingGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/inventory-posting-guard.golden.json';

	public function testGatesMatchGolden(): void {
		$active = ['InventoryGLConfig' => [['isActive' => true]]];
		$valuation = ['id' => 'v1', 'administrationId' => 'adm-1', 'unitCost' => 12.5];
		$config = [
			'administrationId' => 'adm-1',
			'cogsAccountNumber' => '7000',
			'inventoryAssetAccountNumber' => '',
			'grIrClearingAccountNumber' => '1650',
		];
		$scenarios = [
			'post.noUnitCost' => ['canPost', ['@self' => ['id' => 'v2'], 'administrationId' => 'adm-1'], []],
			'post.noAdministration' => ['canPost', ['id' => 'v3', 'unitCost' => 0], $active],
			'post.noConfig' => ['canPost', $valuation, []],
			'post.disabled' => ['canPost', $valuation, ['InventoryGLConfig' => [['isActive' => false]]]],
			'post.activeObject' => ['canPost', $valuation, ['InventoryGLConfig' => [new \ArrayObject(['isActive' => true])]]],
			'post.active' => ['canPost', $valuation, $active],
			'post.storeDown' => ['canPost', $valuation, ['*throw*' => true]],
			'variance.blocked' => ['canPostVariance', ['id' => 'v4'], $active],
			'variance.missing' => ['canPostVariance', $valuation, $active],
			'variance.zero' => ['canPostVariance', $valuation + ['variance' => '0'], $active],
			'variance.explicit' => ['canPostVariance', $valuation + ['variance' => -3], $active],
			'variance.pair' => ['canPostVariance', $valuation + ['actualQuantity' => 7, 'bookQuantity' => '5'], $active],
			'variance.pairZero' => ['canPostVariance', $valuation + ['actualQuantity' => 5, 'bookQuantity' => 5], $active],
			'fk.noAdministration' => ['accountExists', ['cogsAccountNumber' => '7000'], []],
			'fk.allResolve' => ['accountExists', $config, ['Account' => [['accountNumber' => 'x']]]],
			'fk.dangling' => ['accountExists', $config, []],
			'fk.noFields' => ['accountExists', ['administrationId' => 'adm-1'], []],
			'fk.storeDown' => ['accountExists', $config, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$method, $input, $records]) {
			$actual[$name] = $this->scenario($method, $input, $records);
		}

		if (getenv('INVENTORY_POSTING_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one gate and capture result, logs and store calls.
	 *
	 * @param string $method The gate to call.
	 * @param array<string,mixed> $input The record passed in.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $method, array $input, array $records): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if (isset($this->records['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				return ($this->records[$this->schema] ?? []);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new InventoryPostingGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->$method($input),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
