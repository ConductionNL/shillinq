<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\TimeIntakeService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An in-memory object store that records every call TimeIntakeService makes.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RecordingIntakeObjectService {

	/**
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	private string $schema = '';

	private int $nextId = 1;

	/**
	 * @param array<string, array<int, array<string, mixed>>> $stored       Rows keyed by schema.
	 * @param array<string, string>                           $saveBehaviour Schema => 'no-id' | 'self-id' | 'throw'.
	 */
	public function __construct(
		public array $stored,
		private array $saveBehaviour,
	) {

	}//end __construct()

	public function setRegister(string $register): self {
		$this->calls[] = ['setRegister', $register];
		return $this;

	}//end setRegister()

	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Find configuration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$this->calls[] = ['findAll', $this->schema, $config];
		if (($this->saveBehaviour['findAll:' . $this->schema] ?? '') === 'throw') {
			throw new \RuntimeException('store down');
		}

		$result = [];
		foreach (($this->stored[$this->schema] ?? []) as $row) {
			$match = true;
			foreach ((array) ($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) != $value) {
					$match = false;
				}
			}

			if ($match === true) {
				$result[] = $row;
			}
		}

		return $result;

	}//end findAll()

	/**
	 * @param string $id Object id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id): ?array {
		$this->calls[] = ['find', $this->schema, $id];
		foreach (($this->stored[$this->schema] ?? []) as $row) {
			if (($row['id'] ?? null) === $id) {
				return $row;
			}
		}

		return null;

	}//end find()

	/**
	 * @param array<string, mixed> $data Object body.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $data): array {
		if (isset($data['receivedAt']) === true) {
			$data['receivedAt'] = '(now)';
		}

		$this->calls[] = ['saveObject', $this->schema, $data];
		$behaviour = ($this->saveBehaviour[$this->schema] ?? '');
		if ($behaviour === 'no-id') {
			return $data;
		}

		$id = $this->schema . '-' . ($this->nextId++);
		if ($behaviour === 'self-id') {
			$saved          = $data;
			$saved['@self'] = ['id' => $id];
			$this->stored[$this->schema][] = $saved;
			return $saved;
		}

		$data['id'] = $id;
		$this->stored[$this->schema][] = $data;
		return $data;

	}//end saveObject()
}//end class

/**
 * Golden record of TimeIntakeService::ingest.
 *
 * Each scenario runs ingest against a recording object store and a stubbed
 * invoice drafter, and compares the result (or the exception class and
 * message), every store call in order, every drafted invoice request and
 * every logged error with
 * tests/Unit/Service/fixtures/time-intake-ingest.golden.json.
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @covers \OCA\Shillinq\Service\TimeIntakeService
 *
 * @uses \OCA\Shillinq\Request\InvoiceGenerationRequest
 *
 * @spec openspec/specs/time-expense-invoice-intake/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TimeIntakeServiceIngestGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/time-intake-ingest.golden.json';

	/**
	 * @var array<string, mixed>
	 */
	private static array $recorded = [];

	/**
	 * A valid two-entry batch.
	 *
	 * @return array<string, mixed>
	 */
	private static function body(): array {
		return [
			'batchId'         => 'B1',
			'organisationRef' => 'cust-1',
			'currency'        => 'EUR',
			'billingModel'    => 't_and_m',
			'period'          => ['start' => '2026-06-01', 'end' => '2026-06-30'],
			'rateCardId'      => null,
			'projectRef'      => 'proj-1',
			'notes'           => 'June',
			'entries'         => [
				[
					'externalId'  => 'e-1',
					'date'        => '2026-06-03',
					'minutes'     => 120,
					'description' => 'Review',
					'hourlyRate'  => 150.0,
					'rateRef'     => null,
					'projectRef'  => null,
				],
				[
					'externalId'  => 'e-2',
					'date'        => '2026-06-05',
					'minutes'     => 50,
					'description' => '',
					'hourlyRate'  => 100.0,
					'rateRef'     => null,
					'projectRef'  => 'proj-2',
				],
			],
			'expenses'        => [['amount' => 1]],
		];

	}//end body()

	/**
	 * The store rows every scenario starts from.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function seed(): array {
		return [
			'CustomerMaster' => [
				['id' => 'cm-1', 'customerId' => 'cust-1', 'administrationId' => 'adm-1'],
			],
			'RateCard'       => [
				['id' => 'rc-1', 'administrationId' => 'adm-1', 'hourlyRate' => 80.0, 'name' => 'Standard'],
			],
		];

	}//end seed()

	/**
	 * The scenarios: body changes, extra store rows, save behaviour, drafter outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		$body = self::body();

		$withEntry = static function (int $index, array $changes) use ($body): array {
			$changed = $body;
			$changed['entries'][$index] = array_merge($changed['entries'][$index], $changes);
			return $changed;
		};

		return [
			'new batch'                        => [['body' => $body]],
			'replay of the same batch'         => [['body' => $body, 'twice' => true]],
			'same batch id, other payload'     => [['body' => $body, 'twice' => $withEntry(0, ['minutes' => 999])]],
			'fixed-fee model'                  => [['body' => array_merge($body, ['billingModel' => 'fixed_fee'])]],
			'unknown customer'                 => [['body' => array_merge($body, ['organisationRef' => 'cust-9'])]],
			'other administration customer'    => [['body' => $body, 'admin' => 'adm-2']],
			'entry billed under another batch' => [[
				'body'  => $body,
				'extra' => ['UrenRegistratie' => [['id' => 'u-9', 'administrationId' => 'adm-1', 'externalId' => 'e-2', 'sourceBatchId' => 'B0']]],
			],
			],
			'entry with a blank source batch'  => [[
				'body'  => $body,
				'extra' => ['UrenRegistratie' => [['id' => 'u-9', 'administrationId' => 'adm-1', 'externalId' => 'e-1']]],
			],
			],
			'same-batch retry reuses rows'     => [[
				'body'  => $body,
				'extra' => [
					'UrenRegistratie' => [
						['id' => 'u-1', 'administrationId' => 'adm-1', 'externalId' => 'e-1', 'sourceBatchId' => 'B1'],
						['@self' => ['id' => 'u-2'], 'administrationId' => 'adm-1', 'externalId' => 'e-2', 'sourceBatchId' => 'B1'],
					],
				],
			],
			],
			'same-batch row without an id'     => [[
				'body'  => $body,
				'extra' => ['UrenRegistratie' => [['administrationId' => 'adm-1', 'externalId' => 'e-1', 'sourceBatchId' => 'B1']]],
			],
			],
			'zero minutes on the second entry' => [['body' => $withEntry(1, ['minutes' => 0])]],
			'text minutes'                     => [['body' => $withEntry(0, ['minutes' => 'ten'])]],
			'fractional minutes'               => [['body' => $withEntry(0, ['minutes' => 0.5])]],
			'unknown rate ref, no rate'        => [['body' => $withEntry(1, ['hourlyRate' => null, 'rateRef' => 'rc-404'])]],
			'known rate ref, no rate'          => [['body' => $withEntry(0, ['hourlyRate' => null, 'rateRef' => 'rc-1'])]],
			'bad minutes before a bad rate'    => [['body' => $withEntry(0, ['minutes' => -5, 'hourlyRate' => null, 'rateRef' => 'rc-404'])]],
			'request rate card known'          => [['body' => array_merge($body, ['rateCardId' => 'rc-1'])]],
			'request rate card unknown'        => [['body' => array_merge($body, ['rateCardId' => 'rc-404'])]],
			'saved row without an id'          => [['body' => $body, 'save' => ['UrenRegistratie' => 'no-id']]],
			'saved rows with a self id'        => [['body' => $body, 'save' => ['UrenRegistratie' => 'self-id', 'TimeIntakeBatch' => 'self-id']]],
			'store lookup fails'               => [['body' => $body, 'save' => ['findAll:UrenRegistratie' => 'throw']]],
			'drafting fails'                   => [['body' => $body, 'draft' => 'throw']],
			'draft with a self id, no number'  => [['body' => $body, 'draft' => ['@self' => ['id' => 'inv-7']]]],
			'draft without an id'              => [['body' => $body, 'draft' => ['invoiceNumber' => 'X-1']]],
			'malformed body'                   => [['body' => array_merge($body, ['entries' => []])]],
			'no project on the batch'          => [['body' => array_merge($body, ['projectRef' => null])]],
			'body cannot set the acting user or tenant' => [[
				'body' => array_merge($body, ['personId' => 'mallory', 'administrationId' => 'adm-2', 'scope' => ['personId' => 'mallory']]),
			],
			],
		];

	}//end scenarios()

	/**
	 * Every hours row carries the session user and tenant, whatever the
	 * request body claims.
	 *
	 * @return void
	 */
	public function testBodyCannotSetTheActingUserOrTenant(): void {
		$body   = array_merge(self::body(), ['personId' => 'mallory', 'administrationId' => 'adm-2', 'scope' => ['personId' => 'mallory']]);
		$actual = $this->runScenario(['body' => $body]);

		$rows = array_values(
			array_filter(
				$actual['calls'],
				static fn (array $call): bool => $call[0] === 'saveObject' && $call[1] === 'UrenRegistratie'
			)
		);
		$this->assertCount(2, $rows);
		foreach ($rows as $call) {
			$this->assertSame('alice', $call[2]['personId']);
			$this->assertSame('adm-1', $call[2]['administrationId']);
		}

	}//end testBodyCannotSetTheActingUserOrTenant()

	/**
	 * One scenario matches the record.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testIngestMatchesTheGoldenRecord(array $scenario): void {
		$name   = (string) $this->dataName();
		$actual = $this->runScenario($scenario);

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			self::$recorded[$name] = $actual;
			$this->addToAssertionCount(1);
			return;
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertSame(json_encode($golden[$name] ?? null), json_encode($actual), $name);

	}//end testIngestMatchesTheGoldenRecord()

	/**
	 * A batch that repeats an externalId is refused before any write, also
	 * when the repeat differs only in surrounding whitespace.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function repeatedIds(): array {
		return [
			'same id'         => ['e-1'],
			'padded same id'  => [' e-1 '],
		];

	}//end repeatedIds()

	/**
	 * A repeated externalId writes nothing and drafts nothing.
	 *
	 * @param string $repeat The externalId of the repeated entry.
	 *
	 * @return void
	 */
	#[DataProvider('repeatedIds')]
	public function testARepeatedEntryInOneBatchIsRefused(string $repeat): void {
		$body = self::body();
		$body['entries'][1]['externalId'] = $repeat;
		$actual = $this->runScenario(['body' => $body]);

		$this->assertSame(
			['threw' => \RuntimeException::class, 'message' => 'externalId "e-1" appears more than once in this batch.'],
			$actual['results'][0]
		);
		$this->assertSame([], $actual['drafts']);
		$saves = array_filter($actual['calls'], static fn (array $call): bool => $call[0] === 'saveObject');
		$this->assertSame([], array_values($saves));

	}//end testARepeatedEntryInOneBatchIsRefused()

	/**
	 * Write the record after a recording run.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1' && self::$recorded !== []) {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			ksort(self::$recorded);
			file_put_contents(self::GOLDEN, json_encode(self::$recorded, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		parent::tearDownAfterClass();

	}//end tearDownAfterClass()

	/**
	 * Run ingest once (or twice) for a scenario and capture what it did.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$stored = self::seed();
		foreach (($scenario['extra'] ?? []) as $schema => $rows) {
			$stored[$schema] = array_merge(($stored[$schema] ?? []), $rows);
		}

		$store  = new RecordingIntakeObjectService($stored, ($scenario['save'] ?? []));
		$drafts = [];
		$errors = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message) use (&$errors): void {
				$errors[] = $message;
			}
		);
		$drafter = $this->createMock(InvoiceGenerationService::class);
		$draft   = ($scenario['draft'] ?? ['id' => 'inv-1', 'invoiceNumber' => 'BIL-1']);
		$drafter->method('draftInvoice')->willReturnCallback(
			static function ($request) use (&$drafts, $draft): array {
				$drafts[] = get_object_vars($request);
				if ($draft === 'throw') {
					throw new \LogicException('drafter broke');
				}

				return $draft;
			}
		);

		$service = new TimeIntakeService($appConfig, $logger, $drafter, objectService: new DuckObjectServiceAdapter($store));
		$admin   = (string) ($scenario['admin'] ?? 'adm-1');
		$results = [];
		$bodies  = [$scenario['body']];
		if (isset($scenario['twice']) === true) {
			$bodies[] = ($scenario['twice'] === true ? $scenario['body'] : $scenario['twice']);
		}

		foreach ($bodies as $body) {
			try {
				$results[] = $service->ingest($admin, 'alice', $body);
			} catch (\Throwable $e) {
				$results[] = ['threw' => get_class($e), 'message' => $e->getMessage()];
			}
		}

		return [
			'results' => $results,
			'calls'   => $store->calls,
			'drafts'  => $drafts,
			'errors'  => $errors,
		];

	}//end runScenario()
}//end class
