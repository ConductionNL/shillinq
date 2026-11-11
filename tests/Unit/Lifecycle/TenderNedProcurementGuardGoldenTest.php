<?php

/**
 * Golden test for the TenderNedProcurementGuard award and completion gates.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\TenderNedProcurementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with TENDERNED_GUARD_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class TenderNedProcurementGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/tenderned-procurement-guard.golden.json';

	public function testGatesMatchGolden(): void {
		$tender = ['tenderId' => 'T-1', 'commitmentId' => ' c-1 '];
		$scenarios = [
			'gunnen.noSupplier' => ['canGunnen', ['awardedSupplier' => ' '], []],
			'gunnen.zeroValue' => ['canGunnen', ['tenderId' => 'T-2', 'awardedSupplier' => 'ACME', 'contractValue' => '0'], []],
			'gunnen.ok' => ['canGunnen', ['awardedSupplier' => 'ACME', 'contractValue' => '10.5'], []],
			'afronden.noCommitment' => ['canAfronden', ['tenderId' => 'T-3'], []],
			'afronden.approved' => ['canAfronden', $tender, [['status' => 'open'], 'junk', ['status' => 'completed', 'approved' => 1]]],
			'afronden.notApproved' => ['canAfronden', $tender, [['status' => 'completed', 'approved' => false], ['status' => 'open', 'approved' => true]]],
			'afronden.none' => ['canAfronden', $tender, []],
			'afronden.nonArray' => ['canAfronden', $tender, 'x'],
			'afronden.lookupDown' => ['canAfronden', $tender, '*throw*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$method, $input, $records]) {
			$actual[$name] = $this->scenario($method, $input, $records);
		}

		if (getenv('TENDERNED_GUARD_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one gate and capture result, logs and store calls.
	 *
	 * @param string $method The gate to call.
	 * @param array<string,mixed> $tender The TenderNedProcurement.
	 * @param mixed $records What the store answers ('*throw*' to fail).
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $method, array $tender, mixed $records): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($records) {

			public array $calls = [];

			public function __construct(private mixed $records) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): mixed {
				$this->calls[] = ['findAll' => $args];
				if ($this->records === '*throw*') {
					throw new \RuntimeException('no OrderFulfilment schema');
				}

				return $this->records;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new TenderNedProcurementGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->$method($tender),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
