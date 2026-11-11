<?php

/**
 * Golden test for EliminationGuard::canChangeEliminationStatus.
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

use OCA\Shillinq\Lifecycle\EliminationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with ELIMINATION_GUARD_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class EliminationGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/elimination-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$tx = ['consolidatedReportId' => 'cr-1'];
		$scenarios = [
			'noObject' => [null, []],
			'noReport' => [['consolidatedReportId' => null], []],
			'emptyReport' => [['consolidatedReportId' => ''], []],
			'reportNotFound' => [$tx, []],
			'draft' => [$tx, [['status' => 'draft']]],
			'final' => [$tx, ['junk', ['status' => 'draft'], ['status' => 'final']]],
			'finalized' => [$tx, [['status' => 'finalized']]],
			'published' => [$tx, [['status' => 'published']]],
			'archived' => [['consolidatedReportId' => 42], [['status' => 'archived']]],
			'storeDown' => [$tx, '*throw*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$object, $rows]) {
			$actual[$name] = $this->scenario($object, $rows);
		}

		if (getenv('ELIMINATION_GUARD_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed>|null $object The IntercompanyTransaction.
	 * @param mixed $rows What the store answers ('*throw*' to fail).
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array|null $object, mixed $rows): array {
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
		$guard = new EliminationGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->canChangeEliminationStatus('ict-1', $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
