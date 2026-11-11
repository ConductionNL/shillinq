<?php

/**
 * Golden test for RetainerGuard::canActivatePool.
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
 * @spec openspec/specs/retainer-billing-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RetainerGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with RETAINER_POOL_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class RetainerGuardPoolGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/retainer-guard-pool.golden.json';

	public function testDecisionsMatchGolden(): void {
		$pool = ['poolId' => 'p1', 'clientId' => 'c1', 'projectId' => 'pr1', 'periodStart' => ' 2026-01-01 ', 'periodEnd' => '2026-06-30'];
		$other = [
			'poolId' => 'p2',
			'clientId' => 'c1',
			'projectId' => 'pr1',
			'status' => 'active',
			'periodStart' => '2026-06-30',
			'periodEnd' => '2026-12-31',
		];
		$scenarios = [
			'noObject' => ['', null, []],
			'noStart' => ['p1', ['periodStart' => ''] + $pool, []],
			'reversed' => ['p1', ['periodEnd' => '2025-12-31'] + $pool, []],
			'noClient' => ['p1', ['clientId' => ''] + $pool, []],
			'clean' => ['p1', $pool, []],
			'overlapOnEdge' => ['p1', $pool, [$other]],
			'afterEnd' => ['p1', $pool, [['periodStart' => '2026-07-01'] + $other]],
			'beforeStart' => ['p1', $pool, [['periodStart' => '2025-01-01', 'periodEnd' => '2025-12-31'] + $other]],
			'self' => ['p1', $pool, [['poolId' => 'p1'] + $other]],
			'selfByArgument' => ['p9', array_diff_key($pool, ['poolId' => 1]), [['poolId' => 'p9'] + $other]],
			'archived' => ['p1', $pool, [['status' => 'archived'] + $other, ['status' => 'inactive'] + $other]],
			'draftSibling' => ['p1', $pool, [['status' => 'draft'] + $other]],
			'otherProject' => ['p1', $pool, [['projectId' => 'pr2'] + $other]],
			'noProjectBoth' => ['p1', array_diff_key($pool, ['projectId' => 1]), [array_diff_key($other, ['projectId' => 1])]],
			'idless' => ['p1', $pool, [['poolId' => ''] + $other, 'junk']],
			'openEnded' => ['p1', $pool, [['periodEnd' => ''] + $other]],
			'storeDown' => ['p1', $pool, '*throw*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$poolId, $object, $rows]) {
			$actual[$name] = $this->scenario($poolId, $object, $rows);
		}

		if (getenv('RETAINER_POOL_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param string $poolId The pool id argument.
	 * @param array<string,mixed>|null $object The in-flight pool.
	 * @param mixed $rows What the store answers ('*throw*' to fail).
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $poolId, array|null $object, mixed $rows): array {
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

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if ($this->rows === '*throw*') {
					throw new \RuntimeException('store down');
				}

				return $this->rows;
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new RetainerGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->canActivatePool($poolId, $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
