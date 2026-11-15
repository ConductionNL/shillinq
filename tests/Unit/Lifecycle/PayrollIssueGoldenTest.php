<?php

/**
 * Golden test for PayrollGuard::canIssue.
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

use OCA\Shillinq\Lifecycle\PayrollGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record, every store call and config read per payroll.
 *
 * Regenerate with PAYROLL_ISSUE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class PayrollIssueGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/payroll-issue.golden.json';

	public function testDecisionsMatchGolden(): void {
		$payroll = ['id' => 'pr-1', 'grossAmount' => 1000, 'period' => '2026-03'];
		$deductions = [
			['deductionType' => 'income-tax', 'amount' => '300'],
			['deductionType' => 'pension', 'amount' => 999],
			'junk',
			['deductionType' => 'income-tax', 'amount' => 150.5],
		];
		$scenarios = [
			'noPayroll' => ['', null, [], ''],
			'zeroGross' => ['pr-1', ['grossAmount' => 0] + $payroll, $deductions, ''],
			'underDefault' => ['pr-1', $payroll, $deductions, ''],
			'overConfigured' => ['pr-1', $payroll, $deductions, '0.4'],
			'exactConfigured' => ['pr-1', $payroll, $deductions, '0.4505'],
			'badConfig' => ['pr-1', $payroll, $deductions, '1.5'],
			'noPeriod' => ['pr-9', ['grossAmount' => 1000, 'payrollNumber' => 'P-9'], $deductions, '0.1'],
			'storeDown' => ['pr-1', $payroll, '*throw*', ''],
		];

		$actual = [];
		foreach ($scenarios as $name => [$id, $object, $rows, $ceiling]) {
			$actual[$name] = $this->scenario($id, $object, $rows, $ceiling);
		}

		if (getenv('PAYROLL_ISSUE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one payroll and capture result, logs, store calls and config reads.
	 *
	 * @param string $id The payroll id argument.
	 * @param array<string,mixed>|null $object The in-flight payroll.
	 * @param mixed $rows The deductions the store answers, or '*throw*'.
	 * @param string $ceiling The configured ceiling fraction.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $id, array|null $object, mixed $rows, string $ceiling): array {
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
		$reads = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$reads, $ceiling): string {
				$reads[] = $key;
				if ($key === 'register') {
					return '';
				}

				return $ceiling;
			}
		);
		$guard = new PayrollGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->canIssue($id, $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
			'configReads' => $reads,
		];
	}
}
