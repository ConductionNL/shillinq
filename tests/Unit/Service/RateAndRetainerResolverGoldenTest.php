<?php

/**
 * Golden characterisation of RateCardResolver and RetainerResolver.
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

use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RetainerResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins queries, log lines and results, including the tenant refusals.
 */
final class RateAndRetainerResolverGoldenTest extends TestCase {

	/**
	 * Recorded queries and log lines.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the container, config and logger over rows per schema.
	 *
	 * @param array<string,mixed> $rows Schema => rows (a Throwable throws).
	 *
	 * @return array{0:ContainerInterface,1:IAppConfig,2:LoggerInterface}
	 */
	private function deps(array $rows): array {
		$events = &$this->events;
		$store = new class($rows, $events) {
			private string $schema = '';

			public function __construct(private array $rows, private array &$events) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config): mixed {
				$this->events[] = ['find', $this->schema, $config['filters']];
				$rows = ($this->rows[$this->schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}
				if (is_array($rows) === false) {
					return $rows;
				}
				return array_values(
					array_filter($rows, static fn (array $row): bool => array_intersect_assoc($config['filters'], $row) === $config['filters'])
				);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level, &$events): void {
					$events[] = [$level, $message];
				}
			);
		}

		return [$container, $config, $logger];

	}//end deps()

	/**
	 * Run calls and collect each result with its events.
	 *
	 * @param callable                          $build Builds the resolver from deps.
	 * @param array<string,mixed>               $rows  Rows per schema.
	 * @param array<string,array<int,string>>   $calls Label => arguments.
	 * @param string                            $method The resolver method.
	 *
	 * @return array<string,mixed>
	 */
	private function resolveAll(callable $build, array $rows, array $calls, string $method): array {
		$out = [];
		foreach ($calls as $label => $args) {
			$this->events = [];
			$resolver = $build(...$this->deps($rows));
			$out[$label] = ['result' => $resolver->$method(...$args), 'events' => $this->events];
		}

		return $out;

	}//end run()

	/**
	 * Pin RateCardResolver over records, cards, fallbacks and refusals.
	 *
	 * @return void
	 */
	public function testRateResolutionIsExact(): void {
		$rows = [
			'RateRecord' => [
				['rateCardId' => 'rc', 'resourceType' => 'sr', 'administrationId' => 'a1', 'effectiveDate' => '2026-01-01', 'hourlyRate' => 95.5, 'currency' => 'USD', 'version' => 'v2'],
				['rateCardId' => 'rc', 'resourceType' => 'sr', 'administrationId' => 'a1', 'effectiveDate' => '2026-03-01', 'expiresAt' => '2026-03-31', 'rateCents' => 12000],
				['rateCardId' => 'rc', 'resourceType' => 'sr', 'administrationId' => 'a1', 'effectiveDate' => '2026-03-01', 'rateCents' => 99999],
				['rateCardId' => 'rc', 'resourceType' => 'sr', 'administrationId' => 'a1', 'effectiveDate' => '2026-09-01', 'rateCents' => 1],
				['rateCardId' => 'rc', 'resourceType' => 'jr', 'administrationId' => 'a1', 'rateCardVersion' => 'rv'],
				['rateCardId' => 'rc', 'resourceType' => 'sr', 'administrationId' => 'a2', 'effectiveDate' => '2026-01-01', 'rateCents' => 777],
			],
			'RateCard' => [
				['scheduleId' => 'card-s', 'administrationId' => 'a1', 'defaultHourlyRate' => '80'],
				['id' => 'card-i', 'administrationId' => 'a1', 'hourlyRate' => 70, 'currency' => 'GBP', 'version' => 'v9'],
				['id' => 'card-x', 'administrationId' => 'a1'],
				['scheduleId' => 'card-s', 'administrationId' => 'a2', 'defaultHourlyRate' => 1],
			],
		];
		$build = static fn (ContainerInterface $c, IAppConfig $a, LoggerInterface $l): RateCardResolver => new RateCardResolver($c, $a, $l);
		$actual = $this->resolveAll(
			$build,
			$rows,
			[
				'march' => ['rc', 'sr', '2026-03-15', 'a1'],
				'april' => ['rc', 'sr', '2026-04-01', 'a1'],
				'february' => ['rc', 'sr', '2026-02-01', 'a1'],
				'undated' => ['rc', 'jr', '2026-02-01', 'a1'],
				'cardBySchedule' => ['card-s', 'sr', '2026-02-01', 'a1'],
				'cardById' => ['card-i', 'sr', '2026-02-01', 'a1'],
				'cardDefault' => ['card-x', 'sr', '2026-02-01', 'a1'],
				'foreignRecord' => ['rc', 'sr', '2026-02-01', 'a3'],
				'unscoped' => ['rc', 'sr', '2026-02-01', ''],
			],
			'resolveRate'
		);
		$actual += $this->resolveAll($build, ['RateRecord' => new \RuntimeException('down'), 'RateCard' => 'not-a-list'], ['failing' => ['rc', 'sr', '2026-02-01', 'a1']], 'resolveRate');

		$golden = (string)file_get_contents(__DIR__ . '/fixtures/rate-resolver-golden.json');
		self::assertSame($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

	}//end testRateResolutionIsExact()

	/**
	 * Pin RetainerResolver over versions, fallbacks and refusals.
	 *
	 * @return void
	 */
	public function testRetainerResolutionIsExact(): void {
		$rows = [
			'RetainerSchedule' => [
				['scheduleId' => 's', 'administrationId' => 'a1', 'effectiveDate' => '2026-01-01', 'monthlyAmount' => 1500.5, 'label' => 'Basis'],
				['scheduleId' => 's', 'administrationId' => 'a1', 'effectiveDate' => '2026-03-01', 'endDate' => '2026-03-31', 'monthlyAmount' => 2000, 'overageHoursThreshold' => '10', 'overageHourlyRate' => '85.25'],
				['scheduleId' => 's', 'administrationId' => 'a1', 'effectiveDate' => '2026-03-01', 'monthlyAmount' => 1],
				['scheduleId' => 'u', 'administrationId' => 'a1'],
				['scheduleId' => 's', 'administrationId' => 'a2', 'monthlyAmount' => 9],
			],
		];
		$build = static fn (ContainerInterface $c, IAppConfig $a, LoggerInterface $l): RetainerResolver => new RetainerResolver($c, $a, $l);
		$actual = $this->resolveAll(
			$build,
			$rows,
			[
				'march' => ['s', '2026-03-15', 'a1'],
				'april' => ['s', '2026-04-01', 'a1'],
				'february' => ['s', '2026-02-01', 'a1'],
				'before' => ['s', '2025-12-01', 'a1'],
				'undated' => ['u', '2026-02-01', 'a1'],
				'foreign' => ['s', '2026-02-01', 'a3'],
				'unscoped' => ['s', '2026-02-01', ''],
			],
			'resolveRetainerAmount'
		);
		$actual += $this->resolveAll($build, ['RetainerSchedule' => new \RuntimeException('down')], ['failing' => ['s', '2026-02-01', 'a1']], 'resolveRetainerAmount');

		$golden = (string)file_get_contents(__DIR__ . '/fixtures/retainer-resolver-golden.json');
		self::assertSame($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

	}//end testRetainerResolutionIsExact()
}//end class
