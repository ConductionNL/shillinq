<?php

/**
 * Golden test for WBSOExportValidationGuard::requireEligibleEntries.
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\WBSOExportValidationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with WBSO_EXPORT_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class WBSOExportValidationGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/wbso-export-validation-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$export = ['administrationId' => 'adm-1', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-03-31'];
		$tagged = [
			['id' => 'u1', 'wbsoTagId' => 't1', 'activityCodeId' => 'A1'],
			['id' => 'u2', 'wbsoTagId' => 't1', 'activityCodeId' => 'A2'],
		];
		$scenarios = [
			'noScope' => [[], ['UrenRegistratie' => []]],
			'onlyStart' => [['periodStart' => '2026-01-01'], ['UrenRegistratie' => []]],
			'nonArrayEntries' => [$export, ['UrenRegistratie' => 'x']],
			'untagged' => [$export, ['UrenRegistratie' => [$tagged[0], ['id' => 'u3', 'wbsoTagId' => 't1', 'activityCodeId' => '']]]],
			'noTag' => [$export, ['UrenRegistratie' => [['activityCodeId' => 'A1']]]],
			'allAllowed' => [$export, ['UrenRegistratie' => $tagged, 'WBSOActivityCode' => [['isAllowed' => true]]]],
			'codeUnknown' => [$export, ['UrenRegistratie' => $tagged]],
			'codeNotAllowed' => [$export, ['UrenRegistratie' => $tagged, 'WBSOActivityCode' => [['isAllowed' => false]]]],
			'codeFlagMissing' => [$export, ['UrenRegistratie' => $tagged, 'WBSOActivityCode' => [['code' => 'A1']]]],
			'codeLookupDown' => [$export, ['UrenRegistratie' => $tagged, '*throwOn*' => 'WBSOActivityCode']],
			'entriesDown' => [$export, ['*throwOn*' => 'UrenRegistratie']],
		];

		$actual = [];
		foreach ($scenarios as $name => [$export, $records]) {
			$actual[$name] = $this->scenario($export, $records);
		}

		if (getenv('WBSO_EXPORT_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $export The WBSOExportLog object.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $export, array $records): array {
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

			public function findAll(array $args = []): mixed {
				$this->calls[] = ['findAll' => $args];
				if (($this->records['*throwOn*'] ?? '') === $this->schema) {
					throw new \RuntimeException('store down');
				}

				return ($this->records[$this->schema] ?? []);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('wbso-reg');
		$guard = new WBSOExportValidationGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->requireEligibleEntries($export),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
