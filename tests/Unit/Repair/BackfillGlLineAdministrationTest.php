<?php

/**
 * Unit tests for the BackfillGlLineAdministration repair step.
 *
 * Covers `glline-administration-scope` REQ-GLS-003 — the COMPLETENESS GATE that
 * decides whether `SpendAnalyticsService` may scope its category / cost-centre /
 * period aggregations on `GLLine.administrationId` at all.
 *
 * The step's whole value is that it is hostile to itself, so these tests are
 * written against that hostility rather than against its line count:
 *
 * - it RE-READS the store after writing and counts missing scopes, instead of
 *   believing the batch report it just received;
 * - it CLEARS the gate before touching a single row, so a crash, an outage or an
 *   upgrade that never reached the step all read as shut;
 * - it stores a contract VERSION rather than a boolean, so a stale proof
 *   gathered under an older writer set can never be left standing.
 *
 * Each test below is written so that removing one of those three properties
 * turns it red.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\BackfillGlLineAdministration;
use OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the gate contract of BackfillGlLineAdministration.
 *
 * The migrator is `final`, so it is used for real rather than mocked: these are
 * tests of the step's ORDERING and its treatment of the store, and a stubbed
 * migrator would let the step pass while doing the wrong thing with a real one.
 *
 * @covers \OCA\Shillinq\Repair\BackfillGlLineAdministration
 *
 * The migrator is exercised for real rather than stubbed, deliberately: it is
 * `final`, and these are tests of the STEP's ordering and its treatment of the
 * store, so a stubbed migrator would let the step pass while doing the wrong
 * thing with the real one. That collaboration is declared here because
 * PHPUnit's strict coverage config reports undeclared execution as RISKY, and
 * a risky test fails this build — the run reported
 * "Tests: 4927, Assertions: 46194, Risky: 5" with zero failures and zero
 * errors, so the suite passed and the job still went red.
 *
 * @uses \OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator
 */
final class BackfillGlLineAdministrationTest extends TestCase {

	/**
	 * The in-memory OpenRegister store double (also holds the gate + call log).
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The repair-step output double.
	 *
	 * @var IOutput
	 */
	private IOutput $output;

	/**
	 * Two GLTransaction parents, in two different administrations.
	 *
	 * @return array<int, array<string, mixed>> The parent rows.
	 */
	private function transactions(): array {
		return [
			['id' => 'tx-a', 'transactionNumber' => 'GL-2026-A', 'administrationId' => 'ADM-A'],
			['id' => 'tx-b', 'transactionNumber' => 'GL-2026-B', 'administrationId' => 'ADM-B'],
		];

	}//end transactions()

	/**
	 * Two unscoped GLLine rows, one addressing its parent by object id and one
	 * by `transactionNumber` — both idioms this repo's writers actually use.
	 *
	 * @return array<int, array<string, mixed>> The line rows.
	 */
	private function unscopedLines(): array {
		return [
			['id' => 'l1', 'transactionId' => 'tx-a', 'amount' => 10.0],
			['id' => 'l2', 'transactionId' => 'GL-2026-B', 'amount' => 20.0],
		];

	}//end unscopedLines()

	/**
	 * Build the step around an in-memory store seeded with the given rows.
	 *
	 * The store models the three things the step's contract turns on: reads are
	 * SERVED FROM CURRENT STATE (so a write is visible to the next read), every
	 * read and write is appended to an ordered call log (so "cleared first" is
	 * assertable rather than assumed), and `$store->onRead` lets a test act
	 * BETWEEN the write and the proof re-read — which is where a concurrent
	 * `GLLine` writer would land in production.
	 *
	 * @param array<int, array<string, mixed>> $glLines The seeded GLLine rows.
	 * @param array<int, array<string, mixed>> $glTransactions The seeded GLTransaction rows.
	 *
	 * @return BackfillGlLineAdministration The step under test.
	 */
	private function makeStep(array $glLines, array $glTransactions): BackfillGlLineAdministration {
		$this->store = new class($glLines, $glTransactions) {

			/**
			 * Rows by schema slug — the live store, mutated by saveObject().
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $rows;

			/**
			 * The app-config gate value, as the step last left it.
			 *
			 * @var string
			 */
			public string $gate = '';

			/**
			 * Recorded saveObject() calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Ordered log of every gate write, read and save.
			 *
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * How many read passes each schema has served.
			 *
			 * @var array<string, int>
			 */
			public array $readCounts = [];

			/**
			 * Collected IOutput::info() messages.
			 *
			 * @var array<int, string>
			 */
			public array $info = [];

			/**
			 * Collected IOutput::warning() messages.
			 *
			 * @var array<int, string>
			 */
			public array $warnings = [];

			/**
			 * Optional hook run at the start of each read pass.
			 *
			 * Receives the schema slug, the 1-based pass number for that schema
			 * and this store, and may mutate it or throw.
			 *
			 * @var (callable(string, int, object): void)|null
			 */
			public $onRead = null;

			/**
			 * Optional hook run before each save; may throw to model a write failure.
			 *
			 * @var (callable(array, object): void)|null
			 */
			public $onSave = null;

			/**
			 * The schema selected through the fluent setter.
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $glLines The seeded GLLine rows.
			 * @param array<int, array<string, mixed>> $glTransactions The seeded GLTransaction rows.
			 */
			public function __construct(array $glLines, array $glTransactions) {
				$this->rows = [
					GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE => array_values($glLines),
					GlLineAdministrationBackfillMigrator::SCHEMA_GL_TRANSACTION => array_values($glTransactions),
				];

			}//end __construct()

			/**
			 * Fluent register setter (the store is single-register).
			 *
			 * @param string $register The register slug (unused).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;

			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;

				return $this;

			}//end setSchema()

			/**
			 * Serve one page from the CURRENT state of the store.
			 *
			 * `offset === 0` starts a new read pass, which is where the
			 * per-schema counter advances and `onRead` fires.
			 *
			 * @param array<string, mixed> $config The findAll options.
			 * @param string $register The register slug (unused).
			 * @param string $schema The schema slug.
			 * @param bool $_rbac RBAC flag (unused — a migration reads everything).
			 * @param bool $_multitenancy Multi-tenancy flag (unused).
			 *
			 * @return array<int, array<string, mixed>> The page.
			 */
			public function findAll(
				array $config = [],
				string $register = '',
				string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$slug = ($schema !== '' ? $schema : $this->schema);
				$offset = (int)($config['offset'] ?? 0);

				if ($offset === 0) {
					$this->readCounts[$slug] = (($this->readCounts[$slug] ?? 0) + 1);
					$this->calls[] = 'read:' . $slug . '#' . $this->readCounts[$slug];

					if ($this->onRead !== null) {
						($this->onRead)($slug, $this->readCounts[$slug], $this);
					}
				}

				$page = array_values($this->rows[$slug] ?? []);
				if ($offset > 0) {
					$page = array_slice($page, $offset);
				}

				$limit = ($config['limit'] ?? null);
				if ($limit !== null) {
					$page = array_slice($page, 0, (int)$limit);
				}

				return array_values($page);

			}//end findAll()

			/**
			 * Persist a row in place, keyed by its object id.
			 *
			 * @param array<string, mixed> $object The payload being saved.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param string|null $uuid The explicit object UUID.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multi-tenancy flag.
			 *
			 * @return array<string, mixed> The saved payload.
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$slug = ($schema !== '' ? $schema : $this->schema);
				$objectId = ($uuid ?? (string)($object['id'] ?? ''));

				$this->calls[] = 'save:' . $slug . ':' . $objectId;

				if ($this->onSave !== null) {
					($this->onSave)($object, $this);
				}

				$this->saves[] = [
					'object' => $object,
					'register' => $register,
					'schema' => $slug,
					'uuid' => $uuid,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
				];

				foreach (($this->rows[$slug] ?? []) as $index => $row) {
					if ((string)($row['id'] ?? '') === $objectId) {
						$this->rows[$slug][$index] = $object;

						return $object;
					}
				}

				$this->rows[$slug][] = $object;

				return $object;

			}//end saveObject()
		};

		$store = $this->store;

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getRegisterSlug')->willReturn('shillinq');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use ($store): bool {
				if ($key === GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY) {
					$store->gate = $value;
					$store->calls[] = 'gate:' . ($value === '' ? '<cleared>' : $value);
				}

				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false) use ($store): string {
				if ($key === GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY) {
					return $store->gate;
				}

				return $default;
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function ($message) use ($store): void {
				$store->info[] = (string)$message;
			}
		);
		$output->method('warning')->willReturnCallback(
			static function ($message) use ($store): void {
				$store->warnings[] = (string)$message;
			}
		);
		$this->output = $output;

		return new BackfillGlLineAdministration(
			settingsService: $settingsService,
			migrator: new GlLineAdministrationBackfillMigrator(),
			appConfig: $appConfig,
			logger: $this->createMock(\Psr\Log\LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end makeStep()

	/**
	 * Assert some warning message contains the given fragment.
	 *
	 * @param string $needle The fragment to look for.
	 *
	 * @return void
	 */
	private function assertWarned(string $needle): void {
		foreach ($this->store->warnings as $warning) {
			if (str_contains($warning, $needle) === true) {
				self::assertStringContainsString($needle, $warning);

				return;
			}
		}

		self::fail(
			'Expected a warning containing "' . $needle . '", got: '
			. (($this->store->warnings === []) ? '(no warnings at all)' : implode(' | ', $this->store->warnings))
		);

	}//end assertWarned()

	/**
	 * The step names itself well enough to be recognised in `occ` output.
	 *
	 * @return void
	 */
	public function testNameIdentifiesTheSchemaAndTheSource(): void {
		$name = $this->makeStep([], [])->getName();

		self::assertStringContainsString('GLLine', $name);
		self::assertStringContainsString('GLTransaction', $name);

	}//end testNameIdentifiesTheSchemaAndTheSource()

	/**
	 * BEHAVIOUR 1. A clean backfill writes every unscoped row from its own
	 * parent and then OPENS the gate — and the value it stores is the contract
	 * VERSION, never a boolean-ish "yes".
	 *
	 * The version matters because the gate's claim is about the CODE as well as
	 * the data: bumping `GATE_CONTRACT_VERSION` when a new `GLLine` writer
	 * appears has to invalidate every deployment's stored proof, and a stored
	 * `true`/`1` would keep answering yes on evidence gathered before that
	 * writer existed.
	 *
	 * @return void
	 */
	public function testCleanBackfillOpensTheGateWithTheContractVersion(): void {
		$step = $this->makeStep($this->unscopedLines(), $this->transactions());

		$step->run($this->output);

		// Each line got ITS OWN parent's administration, not a shared default.
		self::assertCount(2, $this->store->saves);
		self::assertSame('ADM-A', $this->store->saves[0]['object']['administrationId']);
		self::assertSame('ADM-B', $this->store->saves[1]['object']['administrationId']);

		self::assertSame(
			GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION,
			$this->store->gate,
			'The gate must hold the contract version the reader compares against.'
		);
		self::assertNotContains(
			$this->store->gate,
			['1', 'true', 'yes', 'on', ''],
			'The gate is a VERSION, not a boolean: a truthy flag cannot be invalidated by a contract bump.'
		);

	}//end testCleanBackfillOpensTheGateWithTheContractVersion()

	/**
	 * BEHAVIOUR 2. A batch that aborts — one line whose parent cannot answer for
	 * it — writes NOTHING, leaves the store byte-identical, REVOKES any standing
	 * proof, and does not blow up the upgrade it runs inside.
	 *
	 * The revocation arm is the one that matters most: an instance that was
	 * proven complete yesterday and has since gained an unresolvable `GLLine`
	 * must lose its proof today, or `SpendAnalyticsService` keeps filtering on a
	 * property that row does not carry and quietly drops it from every total.
	 *
	 * The abort is also reported in its own words ("aborted … source data left
	 * intact") rather than as a generic failure, because that is what tells an
	 * admin the ledger was NOT left half-written.
	 *
	 * @return void
	 */
	public function testAbortedBatchWritesNothingAndRevokesTheGate(): void {
		$lines = [
			['id' => 'l1', 'transactionId' => 'tx-a'],
			['id' => 'l2', 'transactionId' => 'tx-vanished'],
		];
		$step = $this->makeStep($lines, $this->transactions());

		// This deployment had already been proven complete.
		$this->store->gate = GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION;

		$step->run($this->output);

		self::assertSame(
			[],
			$this->store->saves,
			'An aborted batch must not write even the rows that resolved cleanly.'
		);
		self::assertSame(
			$lines,
			$this->store->rows[GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE],
			'The source rows must be left intact.'
		);
		self::assertSame('', $this->store->gate, 'An abort must revoke the standing proof.');

		// Reported AS AN ABORT, not as a generic failure. The distinction is the
		// whole operator-facing signal: "aborted … source data left intact" says
		// the ledger was not touched, where "failed" leaves an admin unable to
		// tell whether it was left half-written. Asserted as a PREFIX, because
		// the generic handler re-emits this very exception message inside its
		// own "… backfill failed: …" wrapper and a substring match cannot tell
		// the two paths apart.
		self::assertCount(1, $this->store->warnings);
		self::assertStringStartsWith(
			'Shillinq: GLLine administration backfill aborted:',
			$this->store->warnings[0]
		);
		self::assertStringContainsString('source data left intact', $this->store->warnings[0]);

	}//end testAbortedBatchWritesNothingAndRevokesTheGate()

	/**
	 * BEHAVIOUR 3. THE CRASH-READS-AS-SHUT CONTROL. The gate is cleared BEFORE
	 * the first row is read, so a step that dies part-way leaves it shut even
	 * though it was open when the step started.
	 *
	 * Asserted on the ORDERED call log rather than on the end state alone: an
	 * implementation that cleared the gate in a `finally` would produce the same
	 * final value here while still leaving the gate open for the whole duration
	 * of a run that hangs rather than throws.
	 *
	 * @return void
	 */
	public function testTheGateIsClearedBeforeAnyRowIsRead(): void {
		$step = $this->makeStep($this->unscopedLines(), $this->transactions());

		// A previous run had proven completeness.
		$this->store->gate = GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION;

		// OpenRegister goes away the moment this run touches the store.
		$this->store->onRead = static function (string $schema, int $pass, object $store): void {
			throw new RuntimeException('OpenRegister is unavailable');
		};

		$step->run($this->output);

		self::assertSame('', $this->store->gate, 'A crashed run must leave the gate shut.');
		self::assertSame(
			'gate:<cleared>',
			$this->store->calls[0],
			'Clearing the gate must be the FIRST thing the step does.'
		);
		self::assertStringStartsWith(
			'read:',
			$this->store->calls[1],
			'…and the first read must come after it.'
		);
		$this->assertWarned('failed');

	}//end testTheGateIsClearedBeforeAnyRowIsRead()

	/**
	 * BEHAVIOUR 4. THE CENTRAL CONTROL: the proof is a RE-READ OF THE STORE, not
	 * the batch's own report.
	 *
	 * The batch here succeeds completely and really does write both of its rows.
	 * Between that write and the proof re-read, another writer inserts an
	 * unscoped `GLLine` — precisely the case the step's docblock claims to
	 * cover. The store therefore still holds a row without a scope, and the gate
	 * must stay SHUT despite a clean report, because switching the
	 * `administrationId` filter on now would silently drop that row from every
	 * category / cost-centre / period total.
	 *
	 * @return void
	 */
	public function testGateStaysShutWhenTheStoreStillHoldsAnUnscopedRowAfterASuccessfulBatch(): void {
		$step = $this->makeStep($this->unscopedLines(), $this->transactions());

		$this->store->onRead = static function (string $schema, int $pass, object $store): void {
			if ($schema === GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE && $pass === 2) {
				$store->rows[GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE][] = [
					'id' => 'l3-arrived-late',
					'transactionId' => 'tx-a',
				];
			}
		};

		$step->run($this->output);

		// The batch itself was a total success and wrote both of its own rows…
		self::assertCount(2, $this->store->saves);
		self::assertSame('ADM-A', $this->store->saves[0]['object']['administrationId']);

		// …and the store WAS re-read after those writes, not merely trusted.
		self::assertSame(
			2,
			$this->store->readCounts[GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE],
			'The step must read GLLine a second time, after writing, to prove completeness.'
		);
		$lastSave = array_key_last(array_filter($this->store->calls, static fn (string $c): bool => str_starts_with($c, 'save:')));
		$proofRead = array_search('read:GLLine#2', $this->store->calls, true);
		self::assertGreaterThan($lastSave, $proofRead, 'The proof re-read must happen AFTER the writes.');

		// …yet the gate stays shut, because the store disagrees with the report.
		self::assertSame(
			'',
			$this->store->gate,
			'A clean batch report is not proof; only a zero-missing re-read is.'
		);
		$this->assertWarned('stays CLOSED');

	}//end testGateStaysShutWhenTheStoreStillHoldsAnUnscopedRowAfterASuccessfulBatch()

	/**
	 * BEHAVIOUR 4, second route. A row whose WRITE fails leaves that row
	 * unscoped in the store, so the re-read finds it and the gate stays shut —
	 * even though the batch classified every row successfully.
	 *
	 * @return void
	 */
	public function testGateStaysShutWhenOneWriteFails(): void {
		$step = $this->makeStep($this->unscopedLines(), $this->transactions());

		$this->store->onSave = static function (array $object, object $store): void {
			if (($object['id'] ?? '') === 'l2') {
				throw new RuntimeException('write conflict');
			}
		};

		$step->run($this->output);

		self::assertCount(1, $this->store->saves, 'Only the row that wrote cleanly is recorded.');
		self::assertSame('', $this->store->gate);
		$this->assertWarned('stays CLOSED');

	}//end testGateStaysShutWhenOneWriteFails()

	/**
	 * BEHAVIOUR 5. A stale proof stored under an OLDER contract version is never
	 * left standing.
	 *
	 * `SpendAnalyticsService` accepts the gate only on an exact match with
	 * `GATE_CONTRACT_VERSION`, so bumping that constant is what invalidates
	 * every deployment's stored proof when a new `GLLine` writer appears. That
	 * only holds if this step never leaves the old value in place: here the run
	 * cannot re-prove completeness, and the stale `v0` must be gone rather than
	 * merely "not updated".
	 *
	 * @return void
	 */
	public function testStaleProofFromAnOlderContractVersionIsDestroyedWhenItCannotBeReproven(): void {
		$step = $this->makeStep(
			[['id' => 'l1', 'transactionId' => 'tx-vanished']],
			$this->transactions()
		);

		$this->store->gate = 'v0';

		$step->run($this->output);

		self::assertNotSame('v0', $this->store->gate, 'A proof from an older contract must not survive.');
		self::assertNotSame(
			GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION,
			$this->store->gate,
			'…and it certainly must not be promoted to the current contract.'
		);
		self::assertSame('', $this->store->gate);

	}//end testStaleProofFromAnOlderContractVersionIsDestroyedWhenItCannotBeReproven()

	/**
	 * BEHAVIOUR 5, happy arm. When completeness IS re-proven, the stale value is
	 * replaced by the CURRENT contract version rather than left as it was.
	 *
	 * @return void
	 */
	public function testStaleProofIsReplacedByTheCurrentContractVersionOnACleanRun(): void {
		$step = $this->makeStep($this->unscopedLines(), $this->transactions());

		$this->store->gate = 'v0';

		$step->run($this->output);

		self::assertSame(GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION, $this->store->gate);

	}//end testStaleProofIsReplacedByTheCurrentContractVersionOnACleanRun()

	/**
	 * A row the migrator stamped but which carries no object id cannot be
	 * written as an update, so it is skipped with a warning — and, because it
	 * stays unscoped in the store, the re-read refuses to open the gate.
	 *
	 * @return void
	 */
	public function testRowWithoutAnObjectIdIsSkippedAndKeepsTheGateShut(): void {
		$step = $this->makeStep(
			[['transactionId' => 'tx-a', 'amount' => 10.0]],
			$this->transactions()
		);

		$step->run($this->output);

		self::assertSame([], $this->store->saves);
		self::assertSame('', $this->store->gate);
		$this->assertWarned('no object id');

	}//end testRowWithoutAnObjectIdIsSkippedAndKeepsTheGateShut()

	/**
	 * A re-run over an already-scoped ledger writes nothing and re-affirms the
	 * same gate value — the idempotency this step relies on to be safe to wire
	 * into every upgrade.
	 *
	 * @return void
	 */
	public function testReRunWritesNothingAndReAffirmsTheGate(): void {
		$step = $this->makeStep(
			[
				['id' => 'l1', 'transactionId' => 'tx-a', 'administrationId' => 'ADM-A'],
				['id' => 'l2', 'transactionId' => 'tx-b', 'administrationId' => 'ADM-B'],
			],
			$this->transactions()
		);

		$step->run($this->output);

		self::assertSame([], $this->store->saves);
		self::assertSame(GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION, $this->store->gate);

	}//end testReRunWritesNothingAndReAffirmsTheGate()

	/**
	 * A register holding no `GLLine` rows at all is vacuously complete: there is
	 * no row the filter could silently exclude, so the gate opens.
	 *
	 * Documented deliberately, because "zero rows" is the case a future reader
	 * is most likely to mistake for "nothing was measured".
	 *
	 * @return void
	 */
	public function testAnEmptyLedgerIsVacuouslyCompleteAndOpensTheGate(): void {
		$step = $this->makeStep([], $this->transactions());

		$step->run($this->output);

		self::assertSame([], $this->store->saves);
		self::assertSame(GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION, $this->store->gate);

	}//end testAnEmptyLedgerIsVacuouslyCompleteAndOpensTheGate()
}//end class
