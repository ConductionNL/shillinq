<?php

/**
 * Golden test for FiscalYearGuard::requireAllPeriodsClosedForYear.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\FiscalYearGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with FISCAL_YEAR_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class FiscalYearGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/fiscal-year-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$year = ['yearNumber' => 2026, 'administrationId' => 'adm-1'];
		$scenarios = [
			'noYear' => [['administrationId' => 'adm-1'], []],
			'noPeriods' => [$year, []],
			'nonArray' => [$year, 'x'],
			'allClosed' => [$year, [['state' => 'closed'], ['state' => 'audit-locked']]],
			'openPeriod' => [$year, [['state' => 'closed'], ['periodId' => 'P-03', 'state' => 'open'], ['state' => 'draft']]],
			'stateless' => [['yearNumber' => '2025'], [[]]],
			'storeDown' => [$year, '*throw*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$fiscalYear, $rows]) {
			$actual[$name] = $this->scenario($fiscalYear, $rows);
		}

		if (getenv('FISCAL_YEAR_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $fiscalYear The FiscalYear.
	 * @param mixed $rows What the store answers ('*throw*' to fail).
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $fiscalYear, mixed $rows): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($rows) {

			public array $calls = [];

			public function __construct(private mixed $rows) {
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
				if ($this->rows === '*throw*') {
					throw new \RuntimeException('store down');
				}

				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new FiscalYearGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->requireAllPeriodsClosedForYear($fiscalYear),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
