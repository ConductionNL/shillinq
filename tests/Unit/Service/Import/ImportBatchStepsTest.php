<?php

/**
 * ImportBatch lifecycle steps: parse from Files, mapping, validation, dry-run,
 * post and reverse.
 *
 * Runs the real ImportPipelineService and AuditfileParser on the XAF 3.2
 * fixture; every written batch, mapping row, journal entry and customer is
 * validated with Opis against the real register fragment, and the journal
 * entries are posted through the real JournalEntryGuard (balance, control
 * accounts, blocked combinations).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use DomainException;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\AppInfo\GuardTagServices;
use OCA\Shillinq\Lifecycle\Action\ImportBatchAction;
use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Lifecycle\ImportReverseGuard;
use OCA\Shillinq\Lifecycle\JournalEntryGuard;
use OCA\Shillinq\Lifecycle\PostingRestrictionGuard;
use OCA\Shillinq\Service\Import\AuditfileParser;
use OCA\Shillinq\Service\Import\ImportBatchSteps;
use OCA\Shillinq\Service\Import\ImportPeriod;
use OCA\Shillinq\Service\Import\ImportPipelineService;
use OCA\Shillinq\Service\Import\ImportPosting;
use OCA\Shillinq\Service\Import\ImportPostingPayloads;
use OCA\Shillinq\Service\Import\ImportSourceReader;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
final class ImportBatchStepsTest extends TestCase {

	private const PATH = '/Migrations/auditfile-2025.xaf';

	private InMemoryObjectServiceStub $store;

	private string $xaf = '';

	/**
	 * Every object the code under test saved, in order.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * A store with the target chart (RGS codes of the fixture's accounts).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$accounts = [];
		$control  = ['1300' => 'receivables', '1600' => 'payables'];
		foreach (['BVorDebHad' => '1300', 'BSchSchCrd' => '1600', 'BLimKasKas' => '1000', 'BEivKap' => '0500'] as $rgs => $number) {
			$accounts[] = [
				'id' => 'acc-' . $number,
				'administrationId' => 'adm-new',
				'rgsCode' => $rgs,
				'accountNumber' => $number,
				'controlAccountFor' => ($control[$number] ?? null),
			];
		}

		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(['Account' => $accounts], $this->saved, findAllRendersEntities: true);
		$this->xaf   = (string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/import/sample-xaf-3.2.xml');
	}//end setUp()

	/**
	 * A draft batch that links the fixture in the owner's Files.
	 *
	 * @param string $status The batch status.
	 *
	 * @return array<string,mixed>
	 */
	private function batch(string $status): array {
		return [
			'id' => 'batch-1',
			'administrationId' => 'adm-new',
			'sourceSystem' => 'snelstart',
			'sourceFiles' => [['path' => self::PATH, 'kind' => 'xaf']],
			'migrationDate' => '2026-01-01',
			'scope' => ['chartOfAccounts' => true, 'openingBalance' => true, 'openItems' => true, 'relations' => true],
			'owner' => 'ruben',
			'status' => $status,
		];
	}//end batch()

	/**
	 * The action over the real pipeline, reading Files through a fake root folder.
	 *
	 * @return ImportBatchAction
	 */
	private function action(): ImportBatchAction {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->xaf);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			static function (string $path) use ($file): File {
				if ($path !== self::PATH) {
					throw new NotFoundException($path);
				}

				return $file;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('ruben')->willReturn($folder);

		$engine    = $this->engine();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === ObjectTransitionRunner::ENGINE_CLASS);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				ObjectTransitionRunner::ENGINE_CLASS => $engine,
				default => throw new \RuntimeException($id . ' not available'),
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$posting  = new ImportPosting(
			objectService: $this->store,
			transitions: new ObjectTransitionRunner(container: $container),
			payloads: new ImportPostingPayloads(),
			settings: $settings,
			logger: $this->createMock(LoggerInterface::class)
		);
		$pipeline = new ImportPipelineService($container, $this->createMock(LoggerInterface::class), new AuditfileParser(), new ImportBatchGuard(), $posting);
		$steps    = new ImportBatchSteps(
			pipeline: $pipeline,
			reader: new ImportSourceReader(rootFolder: $root, userSession: $this->createMock(IUserSession::class)),
			period: new ImportPeriod(objectService: $this->store, settings: $settings),
			objectService: $this->store,
			settings: $settings
		);

		return new ImportBatchAction(steps: $steps);
	}//end action()

	/**
	 * The transition engine: the declared JournalEntry lifecycle, behind the real posting guard.
	 *
	 * @return object
	 */
	private function engine(): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$guard = new JournalEntryGuard(
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->store,
			new PostingRestrictionGuard($appConfig, $this->store, $l10n)
		);

		return new class ($this->store, $guard, new LifecycleFaithfulTransitionEngine($this->store, ['JournalEntry'])) {
			/**
			 * Constructor.
			 *
			 * @param InMemoryObjectServiceStub         $store The store.
			 * @param JournalEntryGuard                 $guard The real posting guard.
			 * @param LifecycleFaithfulTransitionEngine $inner The declared lifecycle.
			 */
			public function __construct(
				private readonly InMemoryObjectServiceStub $store,
				private readonly JournalEntryGuard $guard,
				private readonly LifecycleFaithfulTransitionEngine $inner,
			) {
			}

			/**
			 * Refuse what the guard refuses, then apply the declared transition.
			 *
			 * @param string              $objectId The entry.
			 * @param string              $action   The transition.
			 * @param array<string,mixed> $data     Extra data.
			 *
			 * @return object
			 */
			public function transition(string $objectId, string $action, array $data = []): object {
				$entry = $this->store->find($objectId, schema: 'JournalEntry');
				if ($entry !== null && $this->guard->canPost($entry->getObject()) !== true) {
					throw new \RuntimeException('The journal entry ' . $objectId . ' cannot be posted.');
				}

				return $this->inner->transition($objectId, $action, $data);
			}
		};
	}//end engine()

	/**
	 * Run parse, mapping, validation and the dry-run.
	 *
	 * @param ImportBatchAction   $action The action.
	 * @param array<string,mixed> $batch  The draft batch.
	 *
	 * @return array<string,mixed> The batch in state dry_run_complete.
	 */
	private function throughDryRun(ImportBatchAction $action, array $batch): array {
		$staged    = $this->step(action: $action, batch: $batch, to: 'parsing', step: 'parse');
		$mapping   = $this->step(action: $action, batch: $staged, to: 'mapping', step: 'startMapping');
		$validated = $this->step(action: $action, batch: $mapping, to: 'validated', step: 'validate');
		$this->assertSame('validated', $validated['status']);

		return $this->step(action: $action, batch: $validated, to: 'dry_run_complete', step: 'dryRun');
	}//end throughDryRun()

	/**
	 * The rows the store holds for a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema): array {
		return array_map(
			static fn ($row): array => $row->getObject(),
			$this->store->setSchema($schema)->findAll()
		);
	}//end rows()

	/**
	 * Every journal entry and customer the code saved is one the register accepts.
	 *
	 * @return void
	 */
	private function assertEverySavedRecordIsValid(): void {
		$checked = 0;
		foreach ($this->saved as $save) {
			if (in_array($save['schema'], ['JournalEntry', 'CustomerMaster'], true) === false) {
				continue;
			}

			$checked++;
			$this->assertSame([], RegisterSchema::errors($save['schema'], $save['object']), 'a saved ' . $save['schema']);
		}

		$this->assertGreaterThan(0, $checked, 'the post saved no journal entry or customer');
	}//end assertEverySavedRecordIsValid()

	/**
	 * Run one step the way OpenRegister does: on the batch with the transition applied.
	 *
	 * @param ImportBatchAction   $action The action.
	 * @param array<string,mixed> $batch  The batch before.
	 * @param string              $to     The transition's target state.
	 * @param string              $step   The declared step.
	 *
	 * @return array<string,mixed> The batch to save, checked against the real fragment.
	 */
	private function step(ImportBatchAction $action, array $batch, string $to, string $step): array {
		$saved = $action->execute(array_merge($batch, ['status' => $to]), $batch, ['step' => $step], ImportBatchAction::class);
		$this->assertSame([], RegisterSchema::errors('ImportBatch', $saved), 'the batch the ' . $step . ' step saves');

		return $saved;
	}//end step()

	/**
	 * Parse reads the linked file as the owner, stages it and moves on to staged.
	 *
	 * @return void
	 */
	public function testParseStagesTheLinkedAuditfileAndMovesToStaged(): void {
		$staged = $this->step(action: $this->action(), batch: $this->batch(status: 'draft'), to: 'parsing', step: 'parse');

		$this->assertSame('staged', $staged['status']);
		$this->assertSame(4, $staged['stagedCounts']['ledgerAccounts']);
		$this->assertCount(4, $staged['stagingPayload']['openingBalances']);
		$this->assertSame(64, strlen($staged['idempotencyKey']));
		$this->assertArrayNotHasKey('sourceXaf', $staged);
	}//end testParseStagesTheLinkedAuditfileAndMovesToStaged()

	/**
	 * A file the owner cannot read refuses parse with its name (REQ-AIW-002).
	 *
	 * @return void
	 */
	public function testAFileTheOwnerCannotReadRefusesParseWithItsName(): void {
		$batch = $this->batch(status: 'draft');
		$batch['sourceFiles'] = [['path' => '/Migrations/missing.xaf', 'kind' => 'xaf']];

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('The file /Migrations/missing.xaf cannot be read.');
		$this->action()->execute(array_merge($batch, ['status' => 'parsing']), $batch, ['step' => 'parse'], ImportBatchAction::class);
	}//end testAFileTheOwnerCannotReadRefusesParseWithItsName()

	/**
	 * A file that is not an auditfile refuses parse.
	 *
	 * @return void
	 */
	public function testAFileThatIsNotAnAuditfileRefusesParse(): void {
		$this->xaf = 'Datum;Omschrijving;Bedrag';
		$batch     = $this->batch(status: 'draft');

		$this->expectException(DomainException::class);
		$this->action()->execute(array_merge($batch, ['status' => 'parsing']), $batch, ['step' => 'parse'], ImportBatchAction::class);
	}//end testAFileThatIsNotAnAuditfileRefusesParse()

	/**
	 * Parse, map, validate and dry-run a balanced auditfile.
	 *
	 * @return void
	 */
	public function testABalancedImportValidatesAndTheDryRunShowsTheOpeningBalance(): void {
		$action = $this->action();
		$staged = $this->step(action: $action, batch: $this->batch(status: 'draft'), to: 'parsing', step: 'parse');
		$mapping = $this->step(action: $action, batch: $staged, to: 'mapping', step: 'startMapping');

		$rows = $this->store->setSchema('ImportMapping')->findAll(['filters' => ['batchReference' => 'batch-1']]);
		$this->assertCount(4, $rows);
		foreach ($rows as $row) {
			$this->assertSame([], RegisterSchema::errors('ImportMapping', $row->getObject()), 'a saved mapping row');
		}

		$validated = $this->step(action: $action, batch: $mapping, to: 'validated', step: 'validate');
		$this->assertSame('validated', $validated['status']);
		$this->assertTrue($validated['validationReport']['valid']);

		$dryRun = $this->step(action: $action, batch: $validated, to: 'dry_run_complete', step: 'dryRun');
		$lines  = $dryRun['dryRunReport']['openingJournal']['lines'];
		$this->assertCount(4, $lines);
		$this->assertSame(30000.0, array_sum(array_column($lines, 'debit')));
		$this->assertSame(30000.0, array_sum(array_column($lines, 'credit')));
		$this->assertSame(['0500', '1000', '1300', '1600'], $this->sorted(array_column($lines, 'targetAccount')));
	}//end testABalancedImportValidatesAndTheDryRunShowsTheOpeningBalance()

	/**
	 * An unbalanced opening entry moves the batch to validation failed, naming it.
	 *
	 * @return void
	 */
	public function testAnUnbalancedOpeningEntryFailsValidation(): void {
		$this->xaf = str_replace('<amnt>5800.00</amnt>', '<amnt>5900.00</amnt>', $this->xaf);
		$action    = $this->action();
		$staged    = $this->step(action: $action, batch: $this->batch(status: 'draft'), to: 'parsing', step: 'parse');
		$mapping   = $this->step(action: $action, batch: $staged, to: 'mapping', step: 'startMapping');

		$failed = $this->step(action: $action, batch: $mapping, to: 'validated', step: 'validate');

		$this->assertSame('validation_failed', $failed['status']);
		$this->assertSame(['opening-journal-unbalanced'], array_column($failed['validationReport']['findings'], 'code'));
	}//end testAnUnbalancedOpeningEntryFailsValidation()

	/**
	 * A migration date in a closed year fails validation.
	 *
	 * @return void
	 */
	public function testAMigrationDateInAClosedYearFailsValidation(): void {
		$this->store->setSchema('FiscalYear')->saveObject(
			['administrationId' => 'adm-new', 'yearNumber' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'closed']
		);
		$action  = $this->action();
		$staged  = $this->step(action: $action, batch: $this->batch(status: 'draft'), to: 'parsing', step: 'parse');
		$mapping = $this->step(action: $action, batch: $staged, to: 'mapping', step: 'startMapping');

		$failed = $this->step(action: $action, batch: $mapping, to: 'validated', step: 'validate');

		$this->assertSame('validation_failed', $failed['status']);
		$this->assertContains('period-closed', array_column($failed['validationReport']['findings'], 'code'));
	}//end testAMigrationDateInAClosedYearFailsValidation()

	/**
	 * Posting writes and posts the balanced opening entry and the customer (REQ-AIW-001).
	 *
	 * @return void
	 */
	public function testPostingPostsTheOpeningEntryAndWritesTheCustomer(): void {
		$action = $this->action();
		$dryRun = $this->throughDryRun(action: $action, batch: $this->batch(status: 'draft'));

		$posted = $this->step(action: $action, batch: $dryRun, to: 'posting', step: 'post');

		$this->assertSame('posted', $posted['status']);
		$this->assertEverySavedRecordIsValid();

		$entries = $this->rows(schema: 'JournalEntry');
		$this->assertCount(1, $entries);
		$entry = $entries[0];
		$this->assertSame($posted['postingRefs']['openingJournalId'], $entry['id']);
		$this->assertSame('posted', $entry['state']);
		$this->assertSame('2026-01-01', $entry['entryDate']);
		$this->assertSame('import', $entry['sourceApp']);
		$sums = ['debit' => 0.0, 'credit' => 0.0];
		foreach ($entry['lines'] as $line) {
			$sums[$line['side']] += $line['amount'];
		}

		$this->assertSame(['debit' => 30000.0, 'credit' => 30000.0], $sums);
		$this->assertSame(['0500', '1000', '1300', '1600'], $this->sorted(array_column($entry['lines'], 'accountNumber')));

		$customers = $this->rows(schema: 'CustomerMaster');
		$this->assertCount(1, $customers);
		$this->assertSame('D001', $customers[0]['customerId']);
		$this->assertSame('facturen@acme.nl', $customers[0]['email']);
		$this->assertSame([$customers[0]['id']], $posted['postingRefs']['masterIds']);
		$this->assertContains('supplier-not-imported', array_column($posted['postingReport']['findings'], 'code'));
	}//end testPostingPostsTheOpeningEntryAndWritesTheCustomer()

	/**
	 * A customer that already exists is linked, not written again.
	 *
	 * @return void
	 */
	public function testAnExistingCustomerIsLinkedNotDuplicated(): void {
		$this->store->setSchema('CustomerMaster')->saveObject(
			['customerId' => 'K-17', 'legalName' => 'Acme BV', 'email' => 'facturen@acme.nl', 'administrationId' => 'adm-new', 'lifecycleState' => 'active']
		);
		$action = $this->action();

		$posted = $this->step(action: $action, batch: $this->throughDryRun(action: $action, batch: $this->batch(status: 'draft')), to: 'posting', step: 'post');

		$this->assertSame('posted', $posted['status']);
		$this->assertCount(1, $this->rows(schema: 'CustomerMaster'));
		$this->assertSame([], $posted['postingRefs']['masterIds']);
		$this->assertCount(1, $posted['postingRefs']['linkedMasterIds']);
	}//end testAnExistingCustomerIsLinkedNotDuplicated()

	/**
	 * An opening entry the books refuse fails the post and leaves nothing written.
	 *
	 * @return void
	 */
	public function testARefusedOpeningEntryFailsThePostAndLeavesNothingWritten(): void {
		$this->store->setSchema('PostingRestriction')->saveObject(
			['administrationId' => 'adm-new', 'accountPattern' => '1000', 'reason' => 'Cash is closed', 'lifecycleState' => 'active']
		);
		$action = $this->action();

		$failed = $this->step(action: $action, batch: $this->throughDryRun(action: $action, batch: $this->batch(status: 'draft')), to: 'posting', step: 'post');

		$this->assertSame('posting_failed', $failed['status']);
		$findings = $failed['postingReport']['findings'];
		$this->assertSame('posting-refused', $findings[array_key_last($findings)]['code']);
		$this->assertStringContainsString('Cash is closed', $findings[array_key_last($findings)]['message']);
		$this->assertSame([], $this->rows(schema: 'JournalEntry'));
		$this->assertSame([], $this->rows(schema: 'CustomerMaster'));
	}//end testARefusedOpeningEntryFailsThePostAndLeavesNothingWritten()

	/**
	 * A second batch with the same files and mappings is refused, naming the first (REQ-AIW-001).
	 *
	 * @return void
	 */
	public function testTheSameImportIsNotPostedTwice(): void {
		$action = $this->action();
		$first  = $this->step(action: $action, batch: $this->throughDryRun(action: $action, batch: $this->batch(status: 'draft')), to: 'posting', step: 'post');
		$this->store->setSchema('ImportBatch')->saveObject($first);

		$second       = $this->batch(status: 'draft');
		$second['id'] = 'batch-2';
		$ready        = $this->throughDryRun(action: $action, batch: $second);

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('batch-1');
		$action->execute(array_merge($ready, ['status' => 'posting']), $ready, ['step' => 'post'], ImportBatchAction::class);
	}//end testTheSameImportIsNotPostedTwice()

	/**
	 * Reversing posts the reversing entry and removes the customers the import wrote (REQ-AIW-003).
	 *
	 * @return void
	 */
	public function testReversingPostsTheReversingEntryAndRemovesTheImportedCustomer(): void {
		$this->store->setSchema('FiscalYear')->saveObject(
			['administrationId' => 'adm-new', 'yearNumber' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'open']
		);
		$action = $this->action();
		$posted = $this->step(action: $action, batch: $this->throughDryRun(action: $action, batch: $this->batch(status: 'draft')), to: 'posting', step: 'post');

		$reversed = $this->step(action: $action, batch: $posted, to: 'reversed', step: 'reverse');

		$this->assertSame('reversed', $reversed['status']);
		$this->assertEverySavedRecordIsValid();
		$entries = $this->rows(schema: 'JournalEntry');
		$this->assertCount(2, $entries);
		$reversal = $entries[1];
		$this->assertSame($reversed['postingRefs']['reversal']['reversingJournalId'], $reversal['id']);
		$this->assertSame('posted', $reversal['state']);
		$opening = [];
		foreach ($entries[0]['lines'] as $line) {
			$opening[$line['accountNumber']] = $line['side'];
		}

		foreach ($reversal['lines'] as $line) {
			$this->assertNotSame($opening[$line['accountNumber']], $line['side'], 'account ' . $line['accountNumber'] . ' is reversed');
		}

		$this->assertSame([], $this->rows(schema: 'CustomerMaster'));
	}//end testReversingPostsTheReversingEntryAndRemovesTheImportedCustomer()

	/**
	 * Every guard tag and action the ImportBatch lifecycle declares resolves.
	 *
	 * @return void
	 */
	public function testTheDeclaredGuardsAndActionsResolve(): void {
		$factories = [];
		$context   = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $tag, callable $factory) use (&$factories): void {
				$factories[$tag] = $factory;
			}
		);
		(new GuardTagServices())->register(context: $context);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$c = $this->createMock(\OCP\AppFramework\IAppContainer::class);
		$c->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				ImportBatchGuard::class => new ImportBatchGuard(),
				LoggerInterface::class => $this->createMock(LoggerInterface::class),
				ImportReverseGuard::class => new ImportReverseGuard(
					new ImportBatchGuard(),
					new ImportPeriod(objectService: $this->store, settings: $settings),
					$this->createMock(LoggerInterface::class)
				),
			}
		);

		$lifecycle = RegisterSchema::schema('ImportBatch')['x-openregister-lifecycle'];
		$steps     = [];
		foreach ($lifecycle['transitions'] as $name => $transition) {
			if (isset($transition['requires']) === true) {
				$this->assertArrayHasKey($transition['requires'], $factories, $name . ' requires an unregistered guard');
				$this->assertInstanceOf(LifecycleGuardInterface::class, $factories[$transition['requires']]($c));
			}

			foreach (($transition['actions'] ?? []) as $declared) {
				$this->assertSame(ImportBatchAction::class, $declared['action']);
				$steps[$name] = $declared['actionParameters']['step'];
			}
		}

		$this->assertSame(
			['parse' => 'parse', 'startMapping' => 'startMapping', 'validate' => 'validate', 'dryRun' => 'dryRun', 'post' => 'post', 'reverse' => 'reverse'],
			$steps
		);

		$parse = $factories['OCA\Shillinq\Lifecycle\ImportBatchGuard::canParse']($c);
		$this->assertTrue($parse->check(array_merge($this->batch(status: 'parsing')), 'parse', 'ruben')->isAllowed());
		$this->assertFalse($parse->check(['administrationId' => 'adm-new', 'status' => 'parsing'], 'parse', 'ruben')->isAllowed());

		$this->store->setSchema('FiscalYear')->saveObject(
			['administrationId' => 'adm-new', 'yearNumber' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'open']
		);
		$reverse = $factories['OCA\Shillinq\Lifecycle\ImportBatchGuard::canReverse']($c);
		$this->assertTrue($reverse->check($this->batch(status: 'reversed'), 'reverse', 'ruben')->isAllowed());
	}//end testTheDeclaredGuardsAndActionsResolve()

	/**
	 * Sorted copy.
	 *
	 * @param array<int,mixed> $values Values.
	 *
	 * @return array<int,mixed>
	 */
	private function sorted(array $values): array {
		sort($values);
		return $values;
	}//end sorted()
}//end class
