<?php

/**
 * Golden test for IntercompanyToleranceGuard::isWithinTolerance.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\IntercompanyToleranceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with INTERCOMPANY_TOLERANCE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class IntercompanyToleranceGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/intercompany-tolerance-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$match = [
			'matchId' => 'm1',
			'relationId' => 'r1',
			'administrationId' => 'adm-1',
			'totalAmountA' => 1000.0,
			'totalAmountB' => -990.0,
			'mismatchAmount' => 10.0,
		];
		$relation = ['IntercompanyRelation' => [['relationType' => 'loan']]];
		$scenarios = [
			'oneSidedA' => [['matchStatus' => 'one-sided-A'] + $match, []],
			'oneSidedB' => [['matchStatus' => 'one-sided-B'] + $match, []],
			'perfect' => [['mismatchAmount' => 0.004] + $match, []],
			'noAdministrationDefaults' => [['administrationId' => ''] + $match, []],
			'defaultsOverAbsolute' => [['mismatchAmount' => 10.5] + $match, $relation],
			'defaultsRelativeOnly' => [['mismatchAmount' => 4.0, 'totalAmountA' => 1000.0] + $match, $relation],
			'givenPercentage' => [['mismatchAmount' => 20.0, 'mismatchPercentage' => 0.4] + $match, $relation],
			'typedRuleAbsoluteOnly' => [
				['mismatchAmount' => 3.0] + $match,
				$relation + [
					'ToleranceRule' => [
						['toleranceAbsolute' => 1],
						['relationTypeFilter' => 'loan', 'toleranceAbsolute' => 5, 'toleranceMethod' => 'absolute-only'],
					],
				],
			],
			'allTypesMinOf' => [
				['mismatchAmount' => 3.0] + $match,
				$relation + [
					'ToleranceRule' => [
						['relationTypeFilter' => 'trade'],
						[
							'relationTypeFilter' => '',
							'toleranceAbsolute' => 5,
							'toleranceRelative' => 0.1,
							'toleranceMethod' => 'min-of-absolute-relative',
						],
					],
				],
			],
			'noRelation' => [['relationId' => ''] + $match, ['ToleranceRule' => [['relationTypeFilter' => 'loan', 'toleranceAbsolute' => 100]]]],
			'zeroSides' => [['totalAmountA' => 0, 'totalAmountB' => 0, 'mismatchAmount' => 50.0] + $match, []],
			'storeDown' => [$match, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$input, $records]) {
			$actual[$name] = $this->scenario($input, $records);
		}

		if (getenv('INTERCOMPANY_TOLERANCE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $match The IntercompanyMatch.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $match, array $records): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if (isset($this->records['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				return ($this->records[$this->schema] ?? []);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new IntercompanyToleranceGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->isWithinTolerance($match),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
