<?php

/**
 * Golden test for the ProgrammabegrotingGuard behandelen and vaststellen gates.
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

use OCA\Shillinq\Lifecycle\ProgrammabegrotingGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with PROGRAMMABEGROTING_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class ProgrammabegrotingGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/programmabegroting-guard.golden.json';

	public function testGatesMatchGolden(): void {
		$types = [
			'lokaleHeffingen',
			'weerstandsvermogenRisicobeheersing',
			'onderhoudKapitaalgoederen',
			'financiering',
			'bedrijfsvoering',
			'verbondenPartijen',
			'grondbeleid',
		];
		$complete = [];
		foreach ($types as $type) {
			$complete[] = ['type' => $type, 'narrative' => 'Toelichting '.$type];
		}

		$sixOnly = array_slice($complete, 0, 6);
		$oneSilent = $complete;
		$oneSilent[3]['narrative'] = '  ';
		$budget = ['id' => 'pb-1', 'nominalDevelopment' => 0, 'determinationDecision' => 'RB-2026-1'];
		$scenarios = [
			'behandelen.complete' => ['canBehandelen', 'pb-1', $budget, ['Paragraaf' => array_merge($complete, ['junk'])]],
			'behandelen.missing' => ['canBehandelen', 'pb-1', $budget, ['Paragraaf' => $sixOnly]],
			'behandelen.noNominale' => ['canBehandelen', 'pb-1', ['nominalDevelopment' => ''] + $budget, ['Paragraaf' => $complete]],
			'behandelen.silentOk' => ['canBehandelen', 'pb-1', $budget, ['Paragraaf' => $oneSilent]],
			'behandelen.byLookup' => ['canBehandelen', 'pb-1', null, ['Programmabegroting' => [$budget], 'Paragraaf' => $complete]],
			'behandelen.noBudget' => ['canBehandelen', '', null, []],
			'vaststellen.complete' => ['canVaststellen', 'pb-1', $budget, ['Paragraaf' => $complete]],
			'vaststellen.silent' => ['canVaststellen', 'pb-1', $budget, ['Paragraaf' => $oneSilent]],
			'vaststellen.missing' => ['canVaststellen', 'pb-1', $budget, ['Paragraaf' => $sixOnly]],
			'vaststellen.noDecision' => ['canVaststellen', 'pb-1', ['determinationDecision' => ' '] + $budget, ['Paragraaf' => $complete]],
			'vaststellen.idFromObject' => ['canVaststellen', 'other', $budget, ['Paragraaf' => $complete]],
			'vaststellen.idFromArgument' => ['canVaststellen', 'pb-9', array_diff_key($budget, ['id' => 1]), ['Paragraaf' => $complete]],
			'vaststellen.storeDown' => ['canVaststellen', 'pb-1', $budget, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$method, $id, $object, $records]) {
			$actual[$name] = $this->scenario($method, $id, $object, $records);
		}

		if (getenv('PROGRAMMABEGROTING_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one gate and capture result, logs and store calls.
	 *
	 * @param string $method The gate.
	 * @param string $id The budget id argument.
	 * @param array<string,mixed>|null $object The in-flight budget.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $method, string $id, array|null $object, array $records): array {
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
		$guard = new ProgrammabegrotingGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->$method($id, $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
