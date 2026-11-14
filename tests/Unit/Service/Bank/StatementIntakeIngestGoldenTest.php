<?php

/**
 * Golden test for StatementIntakeService::ingest.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Bank
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\Shillinq\Service\Bank\StatementIntakeService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the result, every store call in order and every log line per scenario.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StatementIntakeIngestGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testIngestMatchesGolden(): void {
		$result = [];
		foreach ($this->scenarios() as $name => [$stored, $iban, $lines, $meta, $noId]) {
			$this->calls = [];
			$service = new StatementIntakeService(
				objectService: new DuckObjectServiceAdapter($this->store(stored: $stored, noId: $noId)),
				registerLocation: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
				logger: $this->logger(),
			);
			$out = $service->ingest('adm-1', $iban, $lines, $meta);
			$result[$name] = ['result' => $this->mask(value: $out), 'calls' => $this->mask(value: $this->calls)];
		}

		$file = __DIR__.'/fixtures/statement-intake-ingest.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('STATEMENT_INTAKE_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testIngestMatchesGolden()

	/**
	 * Scenarios: [stored rows, iban, lines, meta, statement save returns no id].
	 *
	 * @return array<string, array{0: array, 1: string, 2: list<array>, 3: array, 4: bool}>
	 */
	private function scenarios(): array {
		$stored = [
			'BankStatement' => [
				['id' => 'st-old', 'administrationId' => 'adm-1', 'bankAccountIban' => 'NL01', 'sourceBatchUri' => 'batch-old'],
				['administrationId' => 'adm-1', 'bankAccountIban' => 'NL01'],
			],
			'BankStatementLine' => [
				['id' => 'l-old', 'statementId' => 'st-old', 'endToEndRef' => ' E2E-OLD '],
				['id' => 'l-blank', 'statementId' => 'st-old', 'endToEndRef' => ''],
			],
		];
		$lines = [
			['endToEndRef' => 'E2E-OLD', 'amount' => 1, 'valueDate' => '2026-01-01T00:00:00'],
			['endToEndRef' => ' E2E-NEW ', 'amount' => 2.345, 'remittanceInfo' => ' r '],
			['endToEndRef' => 'E2E-NEW', 'amount' => 3],
			['amount' => -4.5, 'currency' => 'USD', 'raw' => ['x' => 1]],
			['endToEndRef' => '', 'amount' => 5],
		];
		$meta = ['source' => 'camt053', 'statementDate' => '2026-01-31', 'closingBalance' => '12.5', 'glAccountId' => 'gl-1'];
		return [
			'dedupAndWrite' => [$stored, 'NL01', $lines, $meta, false],
			'duplicateBatch' => [$stored, 'NL01', $lines, ['sourceBatchUri' => ' batch-old '], false],
			'newBatchUri' => [$stored, 'NL01', $lines, ['sourceBatchUri' => 'batch-new'], false],
			'noIban' => [$stored, '', $lines, [], false],
			'otherIban' => [$stored, 'NL99', $lines, ['closingBalance' => 'abc'], false],
			'statementWithoutId' => [$stored, 'NL01', $lines, [], true],
			'noLines' => [$stored, 'NL01', [], [], false],
		];

	}//end scenarios()

	/**
	 * Mask wall-clock values (importedAt, and a statementDate defaulted to now).
	 *
	 * @param mixed $value Any value.
	 *
	 * @return mixed
	 */
	private function mask(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			$isNow = ($key === 'statementDate' && is_string($item) === true && str_ends_with($item, 'T00:00:00Z') === false);
			if ($key === 'importedAt' || $isNow === true) {
				$value[$key] = '<now>';
				continue;
			}

			$value[$key] = $this->mask(value: $item);
		}

		return $value;

	}//end mask()

	/**
	 * A recording, filtering in-memory store.
	 *
	 * @param array $stored Rows keyed by schema.
	 * @param bool  $noId   Whether BankStatement saves come back without id.
	 *
	 * @return object
	 */
	private function store(array $stored, bool $noId): object {
		$test = $this;
		return new class($stored, $noId, $test) {

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Next id.
			 *
			 * @var integer
			 */
			private int $next = 1;

			/**
			 * Construct.
			 *
			 * @param array  $stored Rows.
			 * @param bool   $noId   No-id statement saves.
			 * @param object $test   Recorder.
			 */
			public function __construct(private array $stored, private bool $noId, private object $test) {
			}//end __construct()

			/**
			 * Set register.
			 *
			 * @param string $register Register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Set schema.
			 *
			 * @param string $schema Schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->test->calls[] = ['setSchema', $schema];
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find rows matching every filter.
			 *
			 * @param array $config Config.
			 *
			 * @return array
			 */
			public function findAll(array $config=[]): array {
				$this->test->calls[] = ['findAll', $this->schema, $config];
				$rows = [];
				foreach (($this->stored[$this->schema] ?? []) as $row) {
					$keep = true;
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							$keep = false;
						}
					}

					if ($keep === true) {
						$rows[] = $row;
					}
				}

				return $rows;
			}//end findAll()

			/**
			 * Save.
			 *
			 * @param array $data Body.
			 *
			 * @return array
			 */
			public function saveObject(array $data): array {
				$this->test->calls[] = ['saveObject', $this->schema, $data];
				if ($this->noId === true && $this->schema === 'BankStatement') {
					return $data;
				}

				$data['id'] = $this->schema.'-'.($this->next++);
				return $data;
			}//end saveObject()
		};

	}//end store()

	/**
	 * A logger recording into $this->calls.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {

			/**
			 * Construct.
			 *
			 * @param object $test Recorder.
			 */
			public function __construct(private object $test) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array              $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->test->calls[] = ['log', $level, (string) $message, $context];
			}//end log()
		};

	}//end logger()
}//end class
