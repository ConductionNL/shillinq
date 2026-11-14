<?php

/**
 * Golden test for SettlementGuard::requireSettlementClassification.
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

use OCA\Shillinq\Lifecycle\SettlementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per claim.
 *
 * Regenerate with SETTLEMENT_CLASSIFICATION_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class SettlementClassificationGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/settlement-classification.golden.json';

	public function testDecisionsMatchGolden(): void {
		$claim = ['id' => 'cl-1', 'settlementMode' => ' pass-through '];
		$complete = ['id' => 'r-1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'c-1', 'passthroughDebitAccountCode' => '1300'];
		$scenarios = [
			'noId' => [['settlementMode' => 'reimbursable'], [$complete]],
			'noReceipts' => [$claim, []],
			'complete' => [$claim, [$complete, ['settlementMode' => 'unknown'], []]],
			'mixed' => [$claim, [$complete, ['id' => 'r-2', 'settlementMode' => 'reimbursable']]],
			'claimUnclassified' => [['id' => 'cl-1'], [['settlementMode' => 'reimbursable'], $complete]],
			'missingCustomer' => [$claim, [['linkedCustomerId' => ' '] + $complete]],
			'missingAccount' => [['id' => 'cl-1'], [['id' => 'r-3', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'c-1']]],
			'nonArrayAnswer' => [$claim, 'x'],
			'storeDown' => [$claim, '*throw*'],
			'noObjectService' => [$claim, '*nocontainer*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$input, $rows]) {
			$actual[$name] = $this->scenario($input, $rows);
		}

		if (getenv('SETTLEMENT_CLASSIFICATION_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one claim and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $claim The ExpenseClaimEntry.
	 * @param mixed $rows What the store answers, '*throw*' or '*nocontainer*'.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $claim, mixed $rows): array {
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
		if ($rows === '*nocontainer*') {
			$container->method('get')->willThrowException(new \RuntimeException('no OR'));
		} else {
			$container->method('get')->willReturn($store);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new SettlementGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->requireSettlementClassification($claim),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
