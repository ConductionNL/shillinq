<?php

/**
 * Characterisation tests for CreditScoreService refresh and evaluation.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
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

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\CreditScoreFetchAdapterInterface;
use OCA\Shillinq\Service\Dunning\CreditScoreService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the queries, fetches, saves, log lines and results of every path.
 */
final class CreditScoreRefreshGoldenTest extends TestCase {

	/**
	 * Recorded events in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $events = [];

	/**
	 * Build the service.
	 *
	 * @param mixed $rows      Rows findAll returns, or a Throwable.
	 * @param mixed $fetched   Fetch result, or a Throwable.
	 * @param mixed $saveReply saveObject reply: 'echo' (with id), a Throwable, or a literal.
	 *
	 * @return CreditScoreService
	 */
	private function service(mixed $rows, mixed $fetched, mixed $saveReply = 'echo'): CreditScoreService {
		$events = &$this->events;
		$store = new class($rows, $saveReply, $events) {
			public function __construct(private mixed $rows, private mixed $saveReply, private array &$events) {
			}

			public function setRegister(string $register): self {
				$this->events[] = ['register', $register];
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config): mixed {
				$this->events[] = ['find', $config];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}
				return $this->rows;
			}

			public function saveObject(array $object): mixed {
				$this->events[] = ['save', $object];
				if ($this->saveReply instanceof \Throwable) {
					throw $this->saveReply;
				}
				return $this->saveReply === 'echo' ? $object + ['id' => 'cs-new'] : $this->saveReply;
			}
		};
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '', 'dunning.credit_score_cache_days' => '0'][$key] ?? $default
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level, &$events): void {
					$events[] = [$level, $message];
				}
			);
		}
		$fetch = new class($fetched, $events) implements CreditScoreFetchAdapterInterface {
			public function __construct(private mixed $fetched, private array &$events) {
			}

			public function fetch(string $administrationId, string $customerId, string $provider): ?array {
				$this->events[] = ['fetch', $administrationId, $customerId, $provider];
				if ($this->fetched instanceof \Throwable) {
					throw $this->fetched;
				}
				return $this->fetched;
			}
		};

		return new CreditScoreService($container, $config, $logger, $fetch);

	}//end service()

	/**
	 * Pin the cache, fetch, normalise and persist paths event by event.
	 *
	 * @return void
	 */
	public function testRefreshPathsEventByEvent(): void {
		$today = (new \DateTimeImmutable())->format('Y-m-d');
		$filters = ['filters' => ['administrationId' => 'a', 'customerId' => 'k', 'provider' => 'P']];
		$fresh = ['scoreDate' => $today, 'score' => 8];
		$old = ['scoreDate' => '2000-01-01', 'score' => 2];

		self::assertSame($fresh, $this->service([$old, $fresh], null)->getOrRefresh('a', 'k', 'P'));
		self::assertSame([['register', 'shillinq'], ['find', $filters]], $this->events);

		$this->events = [];
		$result = $this->service([$old], ['score' => 7, 'customerId' => 'kept', 'scoreDate' => ''])->getOrRefresh('a', 'k', 'P');
		self::assertSame('kept', $result['customerId']);
		self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d$/', $result['scoreDate']);
		$saved = ['score' => 7, 'customerId' => 'kept', 'scoreDate' => $result['scoreDate'], 'administrationId' => 'a', 'provider' => 'P'];
		self::assertSame($saved + ['id' => 'cs-new'], $result);
		self::assertSame([['register', 'shillinq'], ['find', $filters], ['fetch', 'a', 'k', 'P'], ['register', 'shillinq'], ['save', $saved]], $this->events);

		$this->events = [];
		$kept = ['score' => 5, 'scoreDate' => '2026-01-02', 'provider' => 'Q'];
		self::assertSame(
			$kept + ['administrationId' => 'a', 'customerId' => 'k'],
			$this->service([], $kept, 'not-an-array')->getOrRefresh('a', 'k', 'P')
		);
		self::assertSame(
			['Shillinq: CreditScoreService::saveScore failed: down'],
			$this->messagesOf($this->service([], $kept, new \RuntimeException('down')), 'warning')
		);

		$this->events = [];
		self::assertSame($old, $this->service([$old], new \RuntimeException('boom'))->getOrRefresh('a', 'k', 'P'));
		self::assertSame(
			[
				['warning', 'Shillinq: CreditScoreFetchAdapter::fetch threw — falling back to cache: boom'],
				['info', 'Shillinq: CreditScore live refresh unavailable for k / P; using cached snapshot.'],
			],
			array_slice($this->events, 3)
		);

		$this->events = [];
		self::assertNull($this->service(new \RuntimeException('or down'), null)->getOrRefresh('a', 'k', 'P'));
		self::assertSame(['warning', 'Shillinq: CreditScoreService::latestForKlant failed: or down'], $this->events[2]);
		self::assertSame(['fetch', 'a', 'k', 'P'], $this->events[3]);

		$this->events = [];
		self::assertNull($this->service('none', null)->getOrRefresh('a', 'k', 'P'));
		self::assertSame(['fetch', 'a', 'k', 'P'], $this->events[2]);

	}//end testRefreshPathsEventByEvent()

	/**
	 * Run a refresh and return the messages logged at one level.
	 *
	 * @param CreditScoreService $service The service.
	 * @param string             $level   Log level.
	 *
	 * @return array<int,string>
	 */
	private function messagesOf(CreditScoreService $service, string $level): array {
		$this->events = [];
		$service->getOrRefresh('a', 'k', 'P');
		return array_values(array_map(static fn (array $e): string => $e[1], array_filter($this->events, static fn (array $e): bool => $e[0] === $level)));

	}//end messagesOf()

	/**
	 * Pin every invoice evaluation verdict.
	 *
	 * @return void
	 */
	public function testEvaluationVerdictsAreExact(): void {
		$service = $this->service([], null);
		$quiet = ['warning' => false, 'message' => '', 'creditLimitAdvice' => null, 'deelfacturatieAdvies' => false];
		self::assertSame($quiet, $service->evaluateForInvoice(null, 10.0));
		self::assertSame($quiet, $service->evaluateForInvoice(['score' => 3], 10.0));
		self::assertSame(array_replace($quiet, ['creditLimitAdvice' => 100.0]), $service->evaluateForInvoice(['score' => '6.5', 'creditLimitAdvice' => '100'], 100.0));
		self::assertSame(
			[
				'warning' => true,
				'message' => 'Klant k1 heeft lage creditscore (2.5 op 1-10). Overweeg vooruitbetaling of deelfacturatie.',
				'creditLimitAdvice' => null,
				'deelfacturatieAdvies' => false,
			],
			$service->evaluateForInvoice(['score' => 2.5, 'customerId' => 'k1', 'scoreScale' => '1-10'], 10.0)
		);
		self::assertSame(
			[
				'warning' => true,
				'message' => 'Klant  heeft lage creditscore (9 op ). Overweeg vooruitbetaling of deelfacturatie.',
				'creditLimitAdvice' => 50.0,
				'deelfacturatieAdvies' => true,
			],
			$service->evaluateForInvoice(['score' => 9, 'creditLimitAdvice' => 50], 50.01)
		);

	}//end testEvaluationVerdictsAreExact()
}//end class
