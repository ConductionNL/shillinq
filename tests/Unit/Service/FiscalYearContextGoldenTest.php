<?php

/**
 * Golden characterisation of FiscalYearContextService.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins windows over every start, the administration lookup paths and the
 * access refusals.
 */
final class FiscalYearContextGoldenTest extends TestCase {

	/**
	 * Recorded lookups, access checks and log lines.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the service.
	 *
	 * @param array<string,mixed> $byUuid  find() answers by id (a Throwable throws).
	 * @param array<string,mixed> $byCode  findAll() answers by administrationCode.
	 * @param boolean             $noStore Whether the ObjectService is unavailable.
	 *
	 * @return FiscalYearContextService
	 */
	private function service(array $byUuid, array $byCode, bool $noStore = false): FiscalYearContextService {
		$events = &$this->events;
		$store = new class($byUuid, $byCode, $events) {
			public function __construct(private array $byUuid, private array $byCode, private array &$events) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function getRegister(): string {
				return 'shillinq';
			}

			public function getSchema(): string {
				return 'Administration';
			}

			public function find(string $id, mixed ...$rest): mixed {
				$this->events[] = ['find', $id];
				$found = ($this->byUuid[$id] ?? new \RuntimeException('not a uuid'));
				if ($found instanceof \Throwable) {
					throw $found;
				}
				return $found;
			}

			public function findAll(array $config): array {
				$this->events[] = ['findAll', $config];
				return ($this->byCode[$config['filters']['administrationCode']] ?? []);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($noStore === true) {
			$container->method('get')->willThrowException(new \RuntimeException('no OR'));
		} else {
			$container->method('get')->willReturn($store);
		}
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $id) use (&$events): bool {
				$events[] = ['canAccess', $id];
				return $id !== 'foreign';
			}
		);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'code-1']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, &$events): void {
					$events[] = [$level, $message, $context];
				}
			);
		}

		return new FiscalYearContextService($container, $context, $config, $logger);

	}//end service()

	/**
	 * Pin fiscalYearFor and windowForFiscalYear over every start shape and
	 * boundary reference date.
	 *
	 * @return void
	 */
	public function testWindowsOverEveryStart(): void {
		$service = $this->service([], []);
		$starts = [
			'none' => [],
			'jan1' => ['fiscalYearStartMonth' => 1, 'fiscalYearStartDay' => 1],
			'jan15' => ['fiscalYearStartMonth' => 1, 'fiscalYearStartDay' => 15],
			'jul1' => ['fiscalYearStartMonth' => 7],
			'jul1str' => ['fiscalYearStartMonth' => '7', 'fiscalYearStartDay' => '01'],
			'padded' => ['fiscalYearStartMonth' => '07', 'fiscalYearStartDay' => '31'],
			'badMonth' => ['fiscalYearStartMonth' => 13, 'fiscalYearStartDay' => 0],
			'badStr' => ['fiscalYearStartMonth' => '-4', 'fiscalYearStartDay' => '32'],
			'floats' => ['fiscalYearStartMonth' => 4.0, 'fiscalYearStartDay' => 'x'],
			'dec31' => ['fiscalYearStartMonth' => 12, 'fiscalYearStartDay' => 31],
		];
		$dates = ['2026-01-01', '2026-01-14', '2026-01-15', '2026-06-30', '2026-07-01', '2026-07-31', '2026-12-31'];
		$actual = [];
		foreach ($starts as $label => $administration) {
			foreach ($dates as $date) {
				$year = $service->fiscalYearFor($administration, new DateTimeImmutable($date . ' 12:00:00'));
				$actual[$label][$date] = [$year, $service->windowForFiscalYear($administration, $year)];
			}
		}

		$golden = (string)file_get_contents(__DIR__ . '/fixtures/fiscal-year-windows-golden.json');
		self::assertSame($golden, json_encode($actual, JSON_PRETTY_PRINT) . "\n");

	}//end testWindowsOverEveryStart()

	/**
	 * Pin the active window over every lookup path, and the refusals.
	 *
	 * @return void
	 */
	public function testActiveWindowOverEveryLookupPath(): void {
		$wrapped = new class {
			public function getObject(): array {
				return ['fiscalYearStartMonth' => 7];
			}
		};
		$emptyWrap = new class {
			public function getObject(): array {
				return [];
			}
		};
		$byUuid = ['uuid-1' => ['fiscalYearStartMonth' => 4], 'uuid-2' => $wrapped, 'uuid-3' => [], 'uuid-4' => $emptyWrap];
		$byCode = ['code-1' => [[], 'scalar', ['fiscalYearStartMonth' => 10]], 'uuid-3' => [$wrapped], 'empty' => [[]]];
		$now = new DateTimeImmutable('2026-08-15 09:00:00');
		$actual = [];
		foreach (['uuid-1', 'uuid-2', 'uuid-3', 'uuid-4', 'code-1', 'empty', 'unknown', 'foreign', ''] as $id) {
			$this->events = [];
			$actual[$id] = ['result' => $this->service($byUuid, $byCode)->resolveActiveWindow($id, $now), 'events' => $this->events];
		}
		$this->events = [];
		$actual['noStore'] = ['result' => $this->service([], [], true)->resolveActiveWindow('uuid-1', $now), 'events' => $this->events];
		$this->events = [];
		$actual['default'] = ['result' => $this->service($byUuid, $byCode)->resolveDefaultWindow($now), 'events' => $this->events];

		$golden = (string)file_get_contents(__DIR__ . '/fixtures/fiscal-year-active-golden.json');
		self::assertSame($golden, json_encode($actual, JSON_PRETTY_PRINT) . "\n");

	}//end testActiveWindowOverEveryLookupPath()
}//end class
