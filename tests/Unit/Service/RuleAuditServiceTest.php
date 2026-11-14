<?php

/**
 * Unit tests for RuleAuditService.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RuleAuditService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the audit report, the queries and the log lines over the real rule engine.
 */
final class RuleAuditServiceTest extends TestCase {

	/**
	 * Recorded queries and log lines.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the service; `$failing` names schemas whose query throws.
	 *
	 * @param array<int,string> $failing Failing schemas.
	 *
	 * @return RuleAuditService
	 */
	private function service(array $failing): RuleAuditService {
		$events = &$this->events;
		$state = ['schema' => ''];
		$serialised = new class {
			public function jsonSerialize(): array {
				return ['id' => 'ser', 'name' => 'Serialised'];
			}
		};
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$state, $store): ObjectServiceInterface {
				$state['schema'] = $schema;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use (&$state, &$events, $failing, $serialised): array {
				$events[] = ['find', $state['schema'], $config];
				if (in_array($state['schema'], $failing, true) === true) {
					throw new \RuntimeException('down');
				}
				if ($state['schema'] === 'GLLine') {
					$key = $config['filters']['transactionId'];
					return [['id' => 'l-' . $key, 'side' => 'debit', 'amount' => 10], ['id' => 'shared', 'side' => 'credit', 'amount' => 10, 'from' => $key], 'scalar'];
				}
				return [[], ['id' => $state['schema'] . '-1', 'transactionNumber' => 'T-1', 'name' => 'x', 'amount' => 0], $serialised, 'scalar'];
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$events): void {
				$events[] = ['warning', $message];
			}
		);

		return new RuleAuditService($config, $logger, $store);

	}//end service()

	/**
	 * Pin the full report and event sequence against the golden fixture.
	 *
	 * @return void
	 */
	public function testAuditMatchesTheGolden(): void {
		$actual = [];
		foreach (['plain' => [[], []], 'jurisdiction' => [['jurisdiction' => 'NL'], []], 'failing' => [[], ['GLTransaction', 'GLLine']]] as $label => [$context, $failing]) {
			$this->events = [];
			$actual[$label] = ['report' => $this->service($failing)->audit($context), 'events' => $this->events];
		}

		$golden = (string)file_get_contents(__DIR__ . '/fixtures/rule-audit-golden.json');
		self::assertSame($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

	}//end testAuditMatchesTheGolden()
}//end class
