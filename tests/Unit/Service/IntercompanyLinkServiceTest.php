<?php

/**
 * Unit tests for IntercompanyLinkService.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\IntercompanyJournalService;
use OCA\Shillinq\Service\IntercompanyLinkService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the counter-side lookup, the mirror, the variance writes and the logs.
 */
final class IntercompanyLinkServiceTest extends TestCase {

	/**
	 * Recorded queries, saves and log lines.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the service over stored entries.
	 *
	 * @param array<int,mixed>|\Throwable $rows Stored entries (or a query failure).
	 *
	 * @return IntercompanyLinkService
	 */
	private function service(array|\Throwable $rows): IntercompanyLinkService {
		$events = &$this->events;
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnSelf();
		$store->method('findAll')->willReturnCallback(
			function (array $config) use (&$events, $rows): array {
				$events[] = ['find', $config];
				if ($rows instanceof \Throwable) {
					throw $rows;
				}
				return $rows;
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array $data) use (&$events): ObjectEntityInterface {
				$events[] = ['save', $data];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($data + ['id' => 'new-' . count($events)]);
				return $entity;
			}
		);
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

		return new IntercompanyLinkService($config, new IntercompanyJournalService(), $logger, $store);

	}//end service()

	/**
	 * Pin the counter-side choice over self, swapped, unswapped and padded rows.
	 *
	 * @return void
	 */
	public function testCounterSideLookupIsExact(): void {
		$entry = ['id' => 'e1', 'intercompanyNumber' => ' IC-1 ', 'sourceAdministrationId' => 'A', 'destinationAdministrationId' => 'B'];
		$rows = [
			'junk',
			['id' => 'e1', 'sourceAdministrationId' => 'B', 'destinationAdministrationId' => 'A'],
			['id' => 'x', 'sourceAdministrationId' => 'A', 'destinationAdministrationId' => 'B'],
			['@self' => ['id' => 'c1'], 'sourceAdministrationId' => ' B ', 'destinationAdministrationId' => 'A '],
			['id' => 'c2', 'sourceAdministrationId' => 'B', 'destinationAdministrationId' => 'A'],
		];
		self::assertSame('c1', $this->service($rows)->findCounterSide($entry)['@self']['id']);
		self::assertSame([['find', ['filters' => ['intercompanyNumber' => 'IC-1']]]], $this->events);
		self::assertNull($this->service($rows)->findCounterSide(['intercompanyNumber' => ' ']));
		$unsaved = ['intercompanyNumber' => 'IC-1', 'sourceAdministrationId' => 'B', 'destinationAdministrationId' => 'A'];
		self::assertSame('x', $this->service($rows)->findCounterSide($unsaved)['id']);

	}//end testCounterSideLookupIsExact()

	/**
	 * Pin linking: the mirror, both variance writes, the warning and the outcome.
	 *
	 * @return void
	 */
	public function testLinkingIsExactOnEveryPath(): void {
		$entry = ['id' => 'e1', 'intercompanyNumber' => 'IC-1', 'sourceAdministrationId' => 'A', 'destinationAdministrationId' => 'B', 'amount' => 100, 'description' => 'Fee', '@self' => ['x' => 1]];
		$counter = ['id' => 'c1', 'sourceAdministrationId' => 'B', 'destinationAdministrationId' => 'A', 'amount' => '99.995', 'status' => 'concept'];

		$this->events = [];
		$balanced = $this->service([$counter])->linkAndReconcile($entry);
		$first = $this->events;
		$this->events = [];
		$mirrored = $this->service([])->linkAndReconcile($entry + ['status' => ' ']);
		$second = $this->events;
		$this->events = [];
		$failed = $this->service(new RuntimeException('down'))->linkAndReconcile(['intercompanyNumber' => 'IC-2', 'amount' => 5]);
		$third = $this->events;
		$this->events = [];
		$unbalanced = $this->service([['id' => 'c9', 'sourceAdministrationId' => 'B', 'destinationAdministrationId' => 'A', 'amount' => 90]])->linkAndReconcile($entry);
		$fourth = $this->events;

		$golden = (string)file_get_contents(__DIR__ . '/fixtures/intercompany-link-golden.json');
		$actual = ['balanced' => [$balanced, $first], 'mirrored' => [$mirrored, $second], 'failed' => [$failed, $third], 'unbalanced' => [$unbalanced, $fourth]];
		self::assertSame($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('IntercompanyJournalEntry requires an intercompanyNumber to be linked');
		$this->service([])->linkAndReconcile(['intercompanyNumber' => '  ']);

	}//end testLinkingIsExactOnEveryPath()
}//end class
