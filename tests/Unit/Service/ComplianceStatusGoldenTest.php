<?php

/**
 * Golden characterisation of ComplianceService.
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

use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\SettingsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins envelopes, cache traffic, queries and log lines of every path.
 */
final class ComplianceStatusGoldenTest extends TestCase {

	/**
	 * Recorded events.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the service.
	 *
	 * @param array<string,mixed> $opts cache: 'none'|'throw'|'ok'|'failing'; cached: array; or: bool; rows: list|Throwable.
	 *
	 * @return ComplianceService
	 */
	private function service(array $opts): ComplianceService {
		$events = &$this->events;
		$cachedValues = ($opts['cached'] ?? []);
		$failing = (($opts['cache'] ?? 'ok') === 'failing');
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$events, $cachedValues): mixed {
				$events[] = ['cache.get', $key];
				return ($cachedValues[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl) use (&$events, $failing): bool {
				$events[] = ['cache.set', $key, $value, $ttl];
				if ($failing === true) {
					throw new \RuntimeException('set down');
				}
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key) use (&$events, $failing): bool {
				$events[] = ['cache.remove', $key];
				if ($failing === true) {
					throw new \RuntimeException('remove down');
				}
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$mode = ($opts['cache'] ?? 'ok');
		$factory->method('isLocalCacheAvailable')->willReturnCallback(
			function () use ($mode, &$events): bool {
				$events[] = ['factory.available'];
				if ($mode === 'throw') {
					throw new \RuntimeException('factory down');
				}
				return $mode !== 'none';
			}
		);
		$factory->method('createLocal')->willReturn($cache);
		$rows = ($opts['rows'] ?? []);
		$store = new class($rows, $events) {
			public function __construct(private mixed $rows, private array &$events) {
			}

			public function setRegister(string $register): self {
				$this->events[] = ['register', $register];
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config): array {
				$this->events[] = ['find', $config];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}
				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(($opts['or'] ?? true));
		$settings->method('getRegisterSlug')->willReturn('reg');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, &$events): void {
					$events[] = [$level, $message, $context];
				}
			);
		}

		return new ComplianceService($container, $settings, $factory, $logger);

	}//end service()

	/**
	 * Every call of the grid with the events it produced.
	 *
	 * @return array<string,mixed>
	 */
	private function payloads(): array {
		$wrapped = new class {
			public function getObject(): array {
				return ['totalBudget' => '1000', 'ytdSpend' => '-250', 'complianceStatus' => 'on_track'];
			}
		};
		$serialised = new class {
			public function jsonSerialize(): array {
				return ['totalBudget' => 2000.6, 'ytdSpend' => 500, 'utilization' => '0.25'];
			}
		};
		$cases = [
			'emptyCode' => [[], ['  '], 'compute'],
			'emptyArrayCode' => [[], [['programmeCode' => 7]], 'compute'],
			'cacheHit' => [['cached' => ['shillinq-bbv-compliance:2.3.2:fy:2026' => ['status' => 'cached', 'x' => 1]]], ['2.3.2', 2026], 'compute'],
			'cacheJunk' => [['cached' => ['shillinq-bbv-compliance:2.3.2' => ['nostatus' => 1]], 'rows' => [$wrapped]], ['2.3.2'], 'compute'],
			'wrappedRow' => [['rows' => [$wrapped, 'second']], [' 2.3.2 ', 2026], 'compute'],
			'serialisedRow' => [['rows' => [$serialised]], ['1.1'], 'compute'],
			'scalarRow' => [['rows' => ['scalar']], ['1.2'], 'compute'],
			'noRows' => [['rows' => []], ['1.3'], 'compute'],
			'orDown' => [['or' => false], ['1.4'], 'compute'],
			'lookupFails' => [['rows' => new \RuntimeException('or down')], ['1.5', 2025], 'compute'],
			'arrayRecord' => [['cache' => 'none'], [['programmeCode' => 'A', 'totalBudget' => 0, 'ytdSpend' => 10, 'utilization' => 'n/a', 'complianceStatus' => '']], 'compute'],
			'floatCents' => [['cache' => 'throw'], [['programmeCode' => 'B', 'totalBudget' => INF, 'ytdSpend' => 12.5, 'complianceStatus' => 5]], 'compute'],
			'setFails' => [['cache' => 'failing'], [['programmeCode' => 'C', 'totalBudget' => '12a', 'ytdSpend' => '--3']], 'compute'],
			'invalidateFy' => [[], ['2.3.2', 2026], 'invalidate'],
			'invalidateAllFy' => [[], ['2.3.2'], 'invalidate'],
			'invalidateEmpty' => [[], [''], 'invalidate'],
			'invalidateNoCache' => [['cache' => 'none'], ['2.3.2'], 'invalidate'],
			'invalidateFails' => [['cache' => 'failing'], ['2.3.2', 2024], 'invalidate'],
		];
		$out = [];
		foreach ($cases as $label => [$opts, $args, $kind]) {
			$this->events = [];
			$service = $this->service($opts);
			$result = null;
			if ($kind === 'compute') {
				$result = $service->computeComplianceStatus(...$args);
			} else {
				$service->invalidate(...$args);
			}
			$out[$label] = ['result' => $result, 'events' => $this->events];
		}

		return $out;

	}//end payloads()

	/**
	 * Pin the grid against the golden fixture.
	 *
	 * @return void
	 */
	public function testEveryPathMatchesTheGolden(): void {
		$golden = (string)file_get_contents(__DIR__ . '/fixtures/compliance-status-golden.json');
		self::assertSame($golden, json_encode($this->payloads(), (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

	}//end testEveryPathMatchesTheGolden()
}//end class
