<?php

/**
 * Golden test for BadoControleprotocolService::computeAggregation.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BadoControleprotocolCalculator;
use OCA\Shillinq\Service\BadoControleprotocolService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the aggregation and every store call per protocol.
 *
 * Regenerate with BADO_AGGREGATION_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class BadoControleprotocolAggregationGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/bado-controleprotocol-aggregation.golden.json';

	public function testAggregationsMatchGolden(): void {
		$records = [
			'Materialiteit' => [['scope' => 'overall', 'calculatedAmount' => '10000']],
			'ToleranceMatrix' => [
				['topic' => 'rechtmatigheid', 'reportingTolerance' => 1000, 'errorTolerance' => 5000],
				['topic' => ''],
			],
			'AuditSample' => [['id' => 's-1'], ['@self' => ['id' => 's-2']], ['note' => 'no-id']],
			'AuditFinding' => [
				['sample' => 's-1', 'topic' => 'rechtmatigheid', 'amount' => 500, 'status' => 'open'],
				['sample' => 's-2', 'topic' => 'rechtmatigheid', 'amount' => 6000, 'status' => 'agreed'],
				['sample' => 's-2', 'amount' => 20000],
				['sample' => 's-9', 'topic' => 'rechtmatigheid', 'amount' => 99999],
			],
		];
		$scenarios = [
			'withSamples' => $records,
			'noSamples' => ['AuditSample' => []] + $records,
			'noMateriality' => ['Materialiteit' => []] + $records,
		];

		$actual = [];
		foreach ($scenarios as $name => $rows) {
			$actual[$name] = $this->scenario($rows);
		}

		if (getenv('BADO_AGGREGATION_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(json_encode($golden, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual, JSON_PRESERVE_ZERO_FRACTION));
	}

	/**
	 * Aggregate one protocol and capture the result and store calls.
	 *
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $records): array {
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = [$this->schema, $args];
				return ($this->records[$this->schema] ?? []);
			}

			public function find(string $id): mixed {
				$this->calls[] = [$this->schema, 'find:'.$id];
				return null;
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$service = new BadoControleprotocolService(
			$appConfig,
			new BadoControleprotocolCalculator(),
			new NullLogger(),
			new DuckObjectServiceAdapter($store)
		);

		return [
			'aggregation' => $service->computeAggregation('cp-1'),
			'calls' => $store->calls,
		];
	}
}
