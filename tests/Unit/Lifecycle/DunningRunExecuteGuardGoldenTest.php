<?php

/**
 * Golden test for DunningRunExecuteGuard::canExecute.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\DunningRunExecuteGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with DUNNING_EXECUTE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class DunningRunExecuteGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/dunning-run-execute-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$grace = 'U heeft 14 DAGEN om de factuur alsnog te voldoen.';
		$scenarios = [
			'runMissing' => [[], 'shillinq'],
			'noInvoice' => [['DunningRun' => [['stageNr' => 1]]], 'shillinq'],
			'paused' => [['DunningRun' => [['invoiceId' => 'inv-1']], 'DunningPauseDispute' => [['id' => 'p1']]], 'custom'],
			'stageOne' => [['DunningRun' => [['invoiceId' => 'inv-1', 'stageNr' => '1']]], ''],
			'stageThreeWithGrace' => [['DunningRun' => [['invoiceId' => 'inv-1', 'stageNr' => 3, 'renderedBody' => $grace]]], 'shillinq'],
			'stageThreeWithoutGrace' => [['DunningRun' => [['invoiceId' => 'inv-1', 'stageNr' => '3', 'renderedBody' => 'Betaal nu.']]], 'shillinq'],
			'stageThreeNoBody' => [['DunningRun' => [['invoiceId' => 'inv-1', 'stageNr' => 3]]], 'shillinq'],
			'stringRun' => [['DunningRun' => ['run-as-string']], 'shillinq'],
			'storeDown' => [['*throw*' => true], 'shillinq'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$records, $register]) {
			$actual[$name] = $this->scenario($records, $register);
		}

		if (getenv('DUNNING_EXECUTE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $records Records per schema.
	 * @param string $register The configured register slug.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $records, string $register): array {
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
		$appConfig->method('getValueString')->willReturn($register);
		$guard = new DunningRunExecuteGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->canExecute('run-1'),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
