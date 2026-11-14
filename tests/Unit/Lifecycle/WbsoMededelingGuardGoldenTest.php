<?php

/**
 * Golden test for WbsoMededelingGuard::canSubmit.
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

use OCA\Shillinq\Lifecycle\WbsoMededelingGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per mededeling.
 *
 * Regenerate with WBSO_MEDEDELING_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class WbsoMededelingGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/wbso-mededeling-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$mededeling = ['decisionNumber' => 'B-1', 'administrationId' => 'adm-1', 'realisedSoHours' => '120.005'];
		$granted = ['decisionNumber' => 'B-1', 'administrationId' => 'adm-1', 'state' => 'granted', 'grantedSoHours' => 120.01];
		$scenarios = [
			'noObject' => [null, []],
			'noDecisionNumber' => [['decisionNumber' => ''] + $mededeling, [$granted]],
			'noRealised' => [array_diff_key($mededeling, ['realisedSoHours' => 1]), [$granted]],
			'zeroRealised' => [['realisedSoHours' => 0] + $mededeling, [$granted]],
			'atCeiling' => [$mededeling, [$granted]],
			'overCeiling' => [['realisedSoHours' => 120.02] + $mededeling, [$granted]],
			'expired' => [$mededeling, [['state' => 'expired'] + $granted]],
			'otherAdministrationRow' => [$mededeling, ['junk', ['administrationId' => 'adm-2'] + $granted]],
			'noDecision' => [$mededeling, []],
			'storeDown' => [$mededeling, '*throw*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$object, $rows]) {
			$actual[$name] = $this->scenario($object, $rows);
		}

		if (getenv('WBSO_MEDEDELING_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one mededeling and capture result, logs and store calls.
	 *
	 * @param array<string,mixed>|null $object The WbsoMededeling.
	 * @param mixed $rows The beschikkingen the store answers, or '*throw*'.
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
		$guard = new WbsoMededelingGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->canSubmit('wm-1', $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
