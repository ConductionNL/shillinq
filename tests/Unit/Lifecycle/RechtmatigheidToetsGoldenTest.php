<?php

/**
 * Golden test for RechtmatigheidGuard::canFinaliseToets.
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

use OCA\Shillinq\Lifecycle\RechtmatigheidGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result and every log record per toets.
 *
 * Regenerate with RECHTMATIGHEID_TOETS_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class RechtmatigheidToetsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/rechtmatigheid-toets.golden.json';

	public function testDecisionsMatchGolden(): void {
		$long = str_repeat('Onderbouwing ', 10);
		$toetsen = [
			'voldoet' => ['outcome' => 'voldoet'],
			'noOutcome' => [],
			'shortSubstantiation' => ['id' => 't-1', 'outcome' => 'voldoet_niet', 'substantiation' => '  kort  '],
			'multibyteShort' => ['outcome' => 'voldoet_niet', 'substantiation' => str_repeat('é', 10)],
			'noBevinding' => ['id' => 't-2', 'outcome' => 'voldoet_niet', 'substantiation' => $long, 'lawfulnessFinding' => ' '],
			'complete' => ['outcome' => 'voldoet_niet', 'substantiation' => $long, 'lawfulnessFinding' => 'b-1'],
		];
		foreach (['onzeker', 'deels'] as $outcome) {
			$toetsen['other.'.$outcome] = ['outcome' => $outcome, 'substantiation' => 'x'];
		}

		$actual = [];
		foreach ($toetsen as $name => $toets) {
			$logger = new class extends AbstractLogger {

				public array $records = [];

				public function log($level, \Stringable|string $message, array $context = []): void {
					$this->records[] = [(string) $level, (string) $message, $context];
				}
			};
			$actual[$name] = [
				'result' => (new RechtmatigheidGuard($logger))->canFinaliseToets($toets),
				'logs' => $logger->records,
			];
		}

		if (getenv('RECHTMATIGHEID_TOETS_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}
}
