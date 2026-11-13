<?php

/**
 * Unit tests for FoldDunningWriteoffIntoArInvoice.
 *
 * Verifies that each OninbaarAfschrijving row is folded onto its ARInvoice's
 * `writeOff` group with nulls pruned and the reason derived from the art. 29
 * OB declaration, that the latest DunningRun becomes the `dunning` summary,
 * that an already-folded write-off is skipped, and that a missing invoice or
 * a failing read never breaks the upgrade.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\FoldDunningWriteoffIntoArInvoice;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FoldDunningWriteoffIntoArInvoice.
 *
 * @covers \OCA\Shillinq\Repair\FoldDunningWriteoffIntoArInvoice
 * @uses   \OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class FoldDunningWriteoffIntoArInvoiceTest extends TestCase {

	/**
	 * The location.
	 *
	 * @var RegisterLocation&MockObject
	 */
	private RegisterLocation&MockObject $location;

	/**
	 * The groupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * The admin.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $admin;

	/**
	 * Rows findAll answers, per schema.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $rows = [];

	/**
	 * Invoices find answers, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $invoices = [];

	/**
	 * The schema the fake service is set to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Every saveObject call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Every filters argument findAll received, per schema.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $filtersSeen = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->location = $this->createMock(RegisterLocation::class);
		$this->location->method('getRegisterSlug')->willReturn('shillinq');
		$this->admin = $this->createMock(IUser::class);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(['not-a-user', $this->admin]);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('get')->with('admin')->willReturn($group);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * The step over a fake object service built from $this->rows and $this->invoices.
	 *
	 * @return FoldDunningWriteoffIntoArInvoice
	 */
	private function step(): FoldDunningWriteoffIntoArInvoice {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnCallback(function (string|int $schema) use ($service) {
			$this->schema = (string)$schema;
			return $service;
		});
		$service->method('findAll')->willReturnCallback(function (array $config): array {
			if ($this->schema === 'DunningRun' && isset($this->rows['DunningRun']) === false) {
				throw new RuntimeException('no dunning schema');
			}

			$this->filtersSeen[] = [$this->schema, ($config['filters'] ?? [])];
			$rows = ($this->rows[$this->schema] ?? []);
			$filters = ($config['filters'] ?? []);
			if (isset($filters['invoiceId']) === true) {
				$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['invoiceId'] ?? null) === $filters['invoiceId']));
			}

			return array_slice($rows, (int)$config['offset'], (int)$config['limit']);
		});
		$service->method('find')->willReturnCallback(function (int|string $id): ?ObjectEntityInterface {
			if ($id === 'boom') {
				throw new RuntimeException('lookup failed');
			}

			if (isset($this->invoices[$id]) === false) {
				return null;
			}

			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($this->invoices[$id]);
			return $entity;
		});
		$service->method('saveObject')->willReturnCallback(function (
			array $object,
			?array $extend = [],
			string|int|null $register = null,
			string|int|null $schema = null,
			?string $uuid = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
			bool $silent = false,
			bool $_validation = true,
			?array $uploadedFiles = null,
			?IUser $currentUser = null,
		): ObjectEntityInterface {
			$this->saved[] = [
				'object' => $object,
				'register' => $register,
				'schema' => $schema,
				'_rbac' => $_rbac,
				'_multitenancy' => $_multitenancy,
				'currentUser' => $currentUser,
			];
			return $this->createMock(ObjectEntityInterface::class);
		});

		return new FoldDunningWriteoffIntoArInvoice(
			settingsService: $this->location,
			groupManager: $this->groupManager,
			logger: $this->logger,
			objectService: $service,
		);
	}//end step()

	/**
	 * Get name names both sources and the target.
	 *
	 * @return void
	 */
	public function testGetNameNamesBothSourcesAndTheTarget(): void {
		$name = $this->step()->getName();

		self::assertStringContainsString('DunningRun', $name);
		self::assertStringContainsString('OninbaarAfschrijving', $name);
		self::assertStringContainsString('ARInvoice', $name);
	}//end testGetNameNamesBothSourcesAndTheTarget()

	/**
	 * No write offs skips the fold.
	 *
	 * @return void
	 */
	public function testNoWriteOffsSkipsTheFold(): void {
		$this->output->expects(self::once())->method('info')
			->with('Shillinq: no OninbaarAfschrijving rows — ARInvoice writeOff/dunning fold skipped.');

		$this->step()->run($this->output);

		self::assertSame([], $this->saved);
	}//end testNoWriteOffsSkipsTheFold()

	/**
	 * A write off is folded with nulls pruned and the latest dunning run.
	 *
	 * @return void
	 */
	public function testAWriteOffIsFoldedWithNullsPrunedAndTheLatestDunningRun(): void {
		$this->rows['OninbaarAfschrijving'] = [[
			'invoiceId' => 'inv-1',
			'entryId' => 'gl-9',
			'art29OBDeclaration' => 'Debiteur in FAILLISSEMENT verklaard',
			'principalDepreciated' => '121.50',
			'vatAmount' => 'n/a',
			'evidenceRef' => '',
			'vatTaxReturnPeriod' => '2026-Q3',
			'administrationId' => 'adm-1',
		]];
		$this->rows['DunningRun'] = [
			['invoiceId' => 'inv-1', 'stageNr' => 2, 'executedOn' => '2026-05-01', 'collectionCostAmount' => 40, 'ladderId' => 'old'],
			[
				'invoiceId' => 'inv-1',
				'stageNr' => 3,
				'executedOn' => '2026-04-01',
				'collectionCostAmount' => 40,
				'interestAmount' => '7.25',
				'ladderId' => 'lad-1',
			],
			['invoiceId' => 'inv-1', 'stageNr' => 3, 'executedOn' => '2026-06-01', 'collectionCostAmount' => '55', 'ladderId' => 'lad-2'],
			['invoiceId' => 'inv-other', 'stageNr' => 9, 'executedOn' => '2026-07-01'],
		];
		$this->invoices['inv-1'] = ['id' => 'inv-1', 'number' => 'F-1'];
		$this->output->expects(self::once())->method('info')
			->with('Shillinq: ARInvoice writeOff/dunning fold complete — 1 folded, 0 skipped (already folded / no link), 0 invoices missing.');

		$this->step()->run($this->output);

		self::assertCount(1, $this->saved);
		$save = $this->saved[0];
		self::assertSame(
			[
				'id' => 'inv-1',
				'number' => 'F-1',
				'writeOff' => [
					'isWrittenOff' => true,
					'writtenOffReason' => 'Faillissement',
					'art29OBDeclaration' => 'Debiteur in FAILLISSEMENT verklaard',
					'principalDepreciated' => 121.5,
					'writtenOffGLTransactionId' => 'gl-9',
					'vatRefundPeriod' => '2026-Q3',
					'administrationId' => 'adm-1',
				],
				'dunning' => [
					'currentStage' => 3,
					'nextDunningDate' => null,
					'collectionCostAmount' => 55.0,
					'interestAmount' => null,
					'activeLadderId' => 'lad-2',
				],
			],
			$save['object']
		);
		self::assertSame('shillinq', $save['register']);
		self::assertSame('ARInvoice', $save['schema']);
		self::assertFalse($save['_rbac']);
		self::assertFalse($save['_multitenancy']);
		self::assertSame($this->admin, $save['currentUser']);
		self::assertContains(['DunningRun', ['invoiceId' => 'inv-1']], $this->filtersSeen);
	}//end testAWriteOffIsFoldedWithNullsPrunedAndTheLatestDunningRun()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function declarations(): array {
		return [
			'debt restructuring' => ['Toegelaten tot de schuldsanering', 'Schuldsanering'],
			'wsnp' => ['WSNP van toepassing', 'Schuldsanering'],
			'one year compact' => ['1jaar na vervaldatum', '1jaar-onbetaald'],
			'one year spaced' => ['Meer dan 1 jaar open', '1jaar-onbetaald'],
			'a year unpaid' => ['Een jaar onbetaald gebleven', '1jaar-onbetaald'],
			'anything else' => ['Debiteur onvindbaar', 'other'],
		];
	}//end declarations()

	/**
	 * The reason is derived from the declaration.
	 *
	 * @param string $declaration The art. 29 OB declaration.
	 * @param string $reason      The reason expected.
	 *
	 * @return void
	 *
	 * @dataProvider declarations
	 */
	public function testTheReasonIsDerivedFromTheDeclaration(string $declaration, string $reason): void {
		$this->rows['OninbaarAfschrijving'] = [['invoiceId' => 'inv-1', 'art29OBDeclaration' => $declaration]];
		$this->rows['DunningRun'] = [];
		$this->invoices['inv-1'] = ['id' => 'inv-1'];

		$this->step()->run($this->output);

		self::assertSame($reason, $this->saved[0]['object']['writeOff']['writtenOffReason']);
		self::assertArrayNotHasKey('dunning', $this->saved[0]['object']);
		self::assertArrayNotHasKey('writtenOffGLTransactionId', $this->saved[0]['object']['writeOff']);
	}//end testTheReasonIsDerivedFromTheDeclaration()

	/**
	 * A failing dunning read leaves dunning out.
	 *
	 * @return void
	 */
	public function testAFailingDunningReadLeavesDunningOut(): void {
		$this->rows['OninbaarAfschrijving'] = [['invoiceId' => 'inv-1', 'art29OBDeclaration' => 'x']];
		$this->invoices['inv-1'] = ['id' => 'inv-1'];

		$this->step()->run($this->output);

		self::assertCount(1, $this->saved);
		self::assertArrayNotHasKey('dunning', $this->saved[0]['object']);
	}//end testAFailingDunningReadLeavesDunningOut()

	/**
	 * Unlinked folded and missing rows are counted not saved.
	 *
	 * @return void
	 */
	public function testUnlinkedFoldedAndMissingRowsAreCountedNotSaved(): void {
		$this->rows['OninbaarAfschrijving'] = [
			['art29OBDeclaration' => 'no invoice link'],
			['invoiceId' => 'inv-done', 'entryId' => 'gl-1'],
			['invoiceId' => 'inv-gone'],
			['invoiceId' => 'boom'],
		];
		$this->rows['DunningRun'] = [];
		$this->invoices['inv-done'] = ['id' => 'inv-done', 'writeOff' => ['isWrittenOff' => true, 'writtenOffGLTransactionId' => 'gl-1']];
		$this->output->expects(self::exactly(2))->method('warning')
			->willReturnCallback(static function (string $message): void {
				self::assertMatchesRegularExpression('/^Shillinq: ARInvoice (inv-gone|boom) for OninbaarAfschrijving not found/', $message);
			});
		$this->output->expects(self::once())->method('info')
			->with('Shillinq: ARInvoice writeOff/dunning fold complete — 0 folded, 2 skipped (already folded / no link), 2 invoices missing.');

		$this->step()->run($this->output);

		self::assertSame([], $this->saved);
	}//end testUnlinkedFoldedAndMissingRowsAreCountedNotSaved()

	/**
	 * A written off invoice with another entry is folded again.
	 *
	 * @return void
	 */
	public function testAWrittenOffInvoiceWithAnotherEntryIsFoldedAgain(): void {
		$this->rows['OninbaarAfschrijving'] = [['invoiceId' => 'inv-1', 'entryId' => 'gl-2']];
		$this->rows['DunningRun'] = [];
		$this->invoices['inv-1'] = ['id' => 'inv-1', 'writeOff' => ['isWrittenOff' => true, 'writtenOffGLTransactionId' => 'gl-1']];

		$this->step()->run($this->output);

		self::assertSame('gl-2', $this->saved[0]['object']['writeOff']['writtenOffGLTransactionId']);
	}//end testAWrittenOffInvoiceWithAnotherEntryIsFoldedAgain()

	/**
	 * Entity rows are read through their payload.
	 *
	 * @return void
	 */
	public function testEntityRowsAreReadThroughTheirPayload(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['invoiceId' => 'inv-1', 'art29OBDeclaration' => 'wsnp']);
		$this->rows['OninbaarAfschrijving'] = [$entity];
		$this->rows['DunningRun'] = [];
		$this->invoices['inv-1'] = ['id' => 'inv-1'];

		$this->step()->run($this->output);

		self::assertSame('Schuldsanering', $this->saved[0]['object']['writeOff']['writtenOffReason']);
	}//end testEntityRowsAreReadThroughTheirPayload()

	/**
	 * Without an admin the save has no acting user.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminTheSaveHasNoActingUser(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$this->groupManager = $groupManager;
		$this->rows['OninbaarAfschrijving'] = [['invoiceId' => 'inv-1']];
		$this->rows['DunningRun'] = [];
		$this->invoices['inv-1'] = ['id' => 'inv-1'];

		$this->step()->run($this->output);

		self::assertNull($this->saved[0]['currentUser']);
	}//end testWithoutAnAdminTheSaveHasNoActingUser()

	/**
	 * A failing admin lookup is logged and the fold goes on.
	 *
	 * @return void
	 */
	public function testAFailingAdminLookupIsLoggedAndTheFoldGoesOn(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willThrowException(new RuntimeException('groups down'));
		$this->groupManager = $groupManager;
		$this->rows['OninbaarAfschrijving'] = [['invoiceId' => 'inv-1']];
		$this->rows['DunningRun'] = [];
		$this->invoices['inv-1'] = ['id' => 'inv-1'];
		$this->logger->expects(self::once())->method('warning')
			->with('Shillinq: could not resolve admin IUser for ARInvoice fold', ['exception' => 'groups down']);

		$this->step()->run($this->output);

		self::assertCount(1, $this->saved);
		self::assertNull($this->saved[0]['currentUser']);
	}//end testAFailingAdminLookupIsLoggedAndTheFoldGoesOn()

	/**
	 * A failure is a warning not an exception.
	 *
	 * @return void
	 */
	public function testAFailureIsAWarningNotAnException(): void {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willThrowException(new RuntimeException('no register'));
		$this->location = $location;
		$this->output->expects(self::once())->method('warning')
			->with('Shillinq: ARInvoice writeOff/dunning fold failed: no register');
		$this->logger->expects(self::once())->method('warning')
			->with('Shillinq: ARInvoice writeOff/dunning fold failed', ['exception' => 'no register']);

		$this->step()->run($this->output);

		self::assertSame([], $this->saved);
	}//end testAFailureIsAWarningNotAnException()
}//end class
