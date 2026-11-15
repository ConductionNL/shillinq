<?php

/**
 * Golden test for StockMoveReasonGuard::requireReasonOnPost.
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

use OCA\Shillinq\Lifecycle\StockMoveReasonGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every config read per move.
 *
 * Regenerate with STOCK_MOVE_REASON_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class StockMoveReasonGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/stock-move-reason-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$scenarios = [
			'missing' => [['movementNumber' => 'SM-1'], ''],
			'nullReason' => [['movementReason' => null], ''],
			'blank' => [['movementReason' => '  ', 'movementNumber' => 'SM-2'], ''],
			'standard' => [['movementReason' => ' damaged '], ''],
			'unknownNoAdministration' => [['movementReason' => 'gift'], 'gift'],
			'customAllowed' => [['movementReason' => 'gift', 'administrationId' => 'adm-1'], ' gift , ,promo'],
			'customAbsent' => [['movementReason' => 'gift', 'administrationId' => 'adm-1'], ''],
			'numericAdministration' => [['movementReason' => 'promo', 'administrationId' => 7], 'promo'],
			'configDown' => [['movementReason' => 'gift', 'administrationId' => 'adm-1'], '*throw*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$move, $config]) {
			$actual[$name] = $this->scenario($move, $config);
		}

		if (getenv('STOCK_MOVE_REASON_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one move and capture result, logs and config reads.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 * @param string $config The configured extra codes, or '*throw*'.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $move, string $config): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$reads = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$reads, $config): string {
				$reads[] = $key;
				if ($config === '*throw*') {
					throw new \RuntimeException('config down');
				}

				return $config;
			}
		);

		return [
			'result' => (new StockMoveReasonGuard($appConfig, $logger))->requireReasonOnPost($move),
			'logs' => $logger->records,
			'configReads' => $reads,
		];
	}
}
