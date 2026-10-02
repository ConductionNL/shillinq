<?php

/**
 * ImportBatch lifecycle steps: parse from Files, mapping, validation, dry-run.
 *
 * Runs the real ImportPipelineService and AuditfileParser on the XAF 3.2
 * fixture; every written batch and mapping row is validated with Opis against
 * the real register fragment.
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
use OCA\Shillinq\AppInfo\ImportBatchServices;
use OCA\Shillinq\Lifecycle\Action\ImportBatchAction;
use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Lifecycle\ImportReverseGuard;
use OCA\Shillinq\Service\Import\AuditfileParser;
use OCA\Shillinq\Service\Import\ImportBatchSteps;
use OCA\Shillinq\Service\Import\ImportPeriod;
use OCA\Shillinq\Service\Import\ImportPipelineService;
use OCA\Shillinq\Service\Import\ImportSourceReader;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
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
	 * A store with the target chart (RGS codes of the fixture's accounts).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$accounts = [];
		foreach (['BVorDebHad' => '1300', 'BSchSchCrd' => '1600', 'BLimKasKas' => '1000', 'BEivKap' => '0500'] as $rgs => $number) {
			$accounts[] = ['id' => 'acc-' . $number, 'administrationId' => 'adm-new', 'rgsCode' => $rgs, 'accountNumber' => $number];
		}

		$this->store = new InMemoryObjectServiceStub(['Account' => $accounts], findAllRendersEntities: true);
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

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				default => throw new \RuntimeException($id . ' not available'),
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$pipeline = new ImportPipelineService($container, $this->createMock(LoggerInterface::class), new AuditfileParser(), new ImportBatchGuard());
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
		(new ImportBatchServices())->register(context: $context);

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

		$this->assertSame(['parse' => 'parse', 'startMapping' => 'startMapping', 'validate' => 'validate', 'dryRun' => 'dryRun'], $steps);

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
