<?php

/**
 * Unit tests for FoldExpensesAndHoursIntoProject.
 *
 * Verifies that Receipt, MileageEntry and PerDiem rows become Project cost
 * lines and UrenRegistratie rows become hours lines, linked directly or
 * through their expense claim, that a line already carried is not added
 * again, that only touched projects are saved, and that failures are warned
 * about rather than thrown.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\FoldExpensesAndHoursIntoProject;
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
 * Tests for FoldExpensesAndHoursIntoProject.
 *
 * @covers \OCA\Shillinq\Repair\FoldExpensesAndHoursIntoProject
 * @uses   \OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches
 */
class FoldExpensesAndHoursIntoProjectTest extends TestCase {

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
	 * Rows findAll answers, per schema.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $rows = [];

	/**
	 * Schemas whose listing throws.
	 *
	 * @var list<string>
	 */
	private array $failing = [];

	/**
	 * Project ids whose save throws.
	 *
	 * @var list<string>
	 */
	private array $failingSaves = [];

	/**
	 * The schema.
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
	 * Every info and warning written.
	 *
	 * @var list<string>
	 */
	private array $messages = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->location = $this->createMock(RegisterLocation::class);
		$this->location->method('getRegisterSlug')->willReturn('shillinq');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->createMock(IUser::class)]);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('get')->willReturn($group);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the step over the fake rows.
	 *
	 * @return void
	 */
	private function runFold(): void {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnCallback(function (string|int $schema) use ($service) {
			$this->schema = (string)$schema;
			return $service;
		});
		$service->method('findAll')->willReturnCallback(function (array $config): array {
			if (in_array($this->schema, $this->failing, true) === true) {
				throw new RuntimeException($this->schema . ' unavailable');
			}

			return array_slice(($this->rows[$this->schema] ?? []), (int)$config['offset'], (int)$config['limit']);
		});
		$service->method('saveObject')->willReturnCallback(function (
			array $object,
			?array $extend = [],
			string|int|null $register = null,
			string|int|null $schema = null,
			?string $uuid = null,
			bool $_rbac = true,
			bool $_multitenancy = true,
		): ObjectEntityInterface {
			if (in_array($object['id'], $this->failingSaves, true) === true) {
				throw new RuntimeException('locked');
			}

			$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
			return $this->createMock(ObjectEntityInterface::class);
		});

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->messages[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->messages[] = 'warning: ' . $message;
		});

		(new FoldExpensesAndHoursIntoProject(
			settingsService: $this->location,
			groupManager: $this->groupManager,
			logger: $this->logger,
			objectService: $service,
		))->run($output);
	}//end run()

	/**
	 * The saved project with this id.
	 *
	 * @param string $id The project id.
	 *
	 * @return array<string, mixed>
	 */
	private function savedProject(string $id): array {
		foreach ($this->saved as $save) {
			if ($save['object']['id'] === $id) {
				return $save['object'];
			}
		}

		self::fail('Project ' . $id . ' was not saved');
	}//end savedProject()

	/**
	 * Get name names the sources and the target.
	 *
	 * @return void
	 */
	public function testGetNameNamesTheSourcesAndTheTarget(): void {
		$step = new FoldExpensesAndHoursIntoProject(
			settingsService: $this->location,
			groupManager: $this->groupManager,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		self::assertSame(
			'Shillinq: fold Receipt/MileageEntry/PerDiem and UrenRegistratie into Project cost/hours lines',
			$step->getName()
		);
	}//end testGetNameNamesTheSourcesAndTheTarget()

	/**
	 * No projects skips the fold.
	 *
	 * @return void
	 */
	public function testNoProjectsSkipsTheFold(): void {
		$this->rows['Receipt'] = [['id' => 'r1', 'projectId' => 'p1']];

		$this->runFold();

		self::assertSame(['info: Shillinq: no Project records — expense/hours fold skipped.'], $this->messages);
		self::assertSame([], $this->saved);
	}//end testNoProjectsSkipsTheFold()

	/**
	 * Every family is folded onto its project.
	 *
	 * @return void
	 */
	public function testEveryFamilyIsFoldedOntoItsProject(): void {
		$this->rows['engagement'] = [['id' => 'p1', 'projectNumber' => 'PRJ-1', 'costLines' => 'bad', 'hoursLines' => null]];
		$this->rows['Receipt'] = [[
			'id' => 'r1',
			'projectId' => 'PRJ-1',
			'receiptDate' => '2026-03-04T10:00:00Z',
			'vendorName' => 'NS',
			'amount' => '12.5',
			'amountInBaseCurrency' => '13.75',
			'currency' => 'EUR',
			'costCentreCode' => 'CC-1',
		]];
		$this->rows['MileageEntry'] = [
			['id' => 'm1', 'projectId' => 'p1', 'journeyDate' => '2026-03-05', 'purpose' => 'Site visit', 'totalAmount' => 21],
			['id' => 'm2', 'projectId' => 'p1', 'journeyDate' => 'not a date', 'fromLocation' => 'Almelo', 'toLocation' => 'Hengelo'],
		];
		$this->rows['PerDiem'] = [
			['id' => 'd1', 'projectId' => 'p1', 'travelStartDate' => '2026-03-06', 'country' => 'BE', 'nightCount' => 2, 'allowanceAmount' => '90'],
			['id' => 'd2', 'projectId' => 'p1', 'description' => 'Conference', 'costCentreCode' => 'CC-2'],
		];
		$this->rows['UrenRegistratie'] = [[
			'id' => 'h1',
			'projectId' => 'p1',
			'date' => '2026-03-07',
			'hours' => '7.5',
			'personId' => 'anne',
			'recognisedRate' => 85,
			'wbsoTagId' => 'w-1',
		]];

		$this->runFold();

		self::assertCount(1, $this->saved);
		self::assertSame('shillinq', $this->saved[0]['register']);
		self::assertSame('engagement', $this->saved[0]['schema']);
		self::assertFalse($this->saved[0]['_rbac']);
		self::assertFalse($this->saved[0]['_multitenancy']);
		$project = $this->savedProject('p1');
		self::assertSame(
			[
				[
					'costDate' => '2026-03-04',
					'description' => 'NS',
					'amount' => 13.75,
					'currency' => 'EUR',
					'costCentreCode' => 'CC-1',
					'sourceId' => 'r1',
					'type' => 'receipt',
				],
				[
					'costDate' => '2026-03-05',
					'description' => 'Site visit',
					'amount' => 21.0,
					'currency' => '',
					'costCentreCode' => '',
					'sourceId' => 'm1',
					'type' => 'mileage',
				],
				[
					'costDate' => '',
					'description' => 'Almelo → Hengelo',
					'amount' => 0.0,
					'currency' => '',
					'costCentreCode' => '',
					'sourceId' => 'm2',
					'type' => 'mileage',
				],
				[
					'costDate' => '2026-03-06',
					'description' => 'BE per-diem (2 nights)',
					'amount' => 90.0,
					'currency' => '',
					'costCentreCode' => '',
					'sourceId' => 'd1',
					'type' => 'perdiem',
				],
				[
					'costDate' => '',
					'description' => 'Conference',
					'amount' => 0.0,
					'currency' => '',
					'costCentreCode' => 'CC-2',
					'sourceId' => 'd2',
					'type' => 'perdiem',
				],
			],
			$project['costLines']
		);
		self::assertSame(
			[['date' => '2026-03-07', 'hours' => 7.5, 'personId' => 'anne', 'recognisedRate' => 85.0, 'wbsoTagId' => 'w-1', 'sourceId' => 'h1']],
			$project['hoursLines']
		);
		self::assertSame('info: Shillinq: expense/hours fold complete — 1 Project record(s) updated.', end($this->messages));
	}//end testEveryFamilyIsFoldedOntoItsProject()

	/**
	 * A cost row is linked through its expense claim.
	 *
	 * @return void
	 */
	public function testACostRowIsLinkedThroughItsExpenseClaim(): void {
		$this->rows['engagement'] = [['uuid' => 'p-uuid', 'code' => 'ALPHA']];
		$this->rows['ExpenseClaimEntry'] = [
			['claimNumber' => 'CL-1', 'projectId' => 'ALPHA'],
			['id' => 'cl-unlinked'],
			['id' => 'cl-elsewhere', 'projectId' => 'unknown-project'],
		];
		$this->rows['Receipt'] = [
			['uuid' => 'r1', 'claimId' => 'CL-1', 'receiptNumber' => 'BON-7'],
			['id' => 'r2', 'claimId' => 'cl-elsewhere'],
			['id' => 'r3', 'projectId' => 'nope'],
			['projectId' => 'ALPHA'],
		];

		$this->runFold();

		$project = $this->savedProject('p-uuid');
		self::assertSame(['r1'], array_column($project['costLines'], 'sourceId'));
		self::assertSame('BON-7', $project['costLines'][0]['description']);
		self::assertSame([], $project['hoursLines']);
	}//end testACostRowIsLinkedThroughItsExpenseClaim()

	/**
	 * Lines already carried are not added and an untouched project is not saved.
	 *
	 * @return void
	 */
	public function testLinesAlreadyCarriedAreNotAddedAndAnUntouchedProjectIsNotSaved(): void {
		$this->rows['engagement'] = [
			[
				'id' => 'p1',
				'costLines' => [['sourceId' => 'r1', 'type' => 'receipt']],
				'hoursLines' => [['sourceId' => 'h1']],
			],
			['id' => 'p2'],
			['name' => 'no id at all'],
		];
		$this->rows['Receipt'] = [['id' => 'r1', 'projectId' => 'p1']];
		$this->rows['UrenRegistratie'] = [
			['id' => 'h1', 'projectId' => 'p1'],
			['id' => 'h2'],
			['id' => 'h3', 'projectId' => 'unknown'],
			['projectId' => 'p1'],
		];

		$this->runFold();

		self::assertSame([], $this->saved);
		self::assertSame('info: Shillinq: expense/hours fold complete — 0 Project record(s) updated.', end($this->messages));
	}//end testLinesAlreadyCarriedAreNotAddedAndAnUntouchedProjectIsNotSaved()

	/**
	 * A failing listing is warned and the other families still fold.
	 *
	 * @return void
	 */
	public function testAFailingListingIsWarnedAndTheOtherFamiliesStillFold(): void {
		$this->rows['engagement'] = [['id' => 'p1']];
		$this->failing = ['ExpenseClaimEntry', 'Receipt', 'UrenRegistratie'];
		$this->rows['PerDiem'] = [['id' => 'd1', 'projectId' => 'p1', 'allowanceAmount' => 10]];

		$this->runFold();

		self::assertContains('warning: Shillinq: fold — failed to list Receipt: Receipt unavailable', $this->messages);
		self::assertContains('warning: Shillinq: fold — failed to list UrenRegistratie: UrenRegistratie unavailable', $this->messages);
		self::assertSame(['d1'], array_column($this->savedProject('p1')['costLines'], 'sourceId'));
	}//end testAFailingListingIsWarnedAndTheOtherFamiliesStillFold()

	/**
	 * A failing save is warned and the other projects are saved.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsWarnedAndTheOtherProjectsAreSaved(): void {
		$this->rows['engagement'] = [['id' => 'p1'], ['id' => 'p2']];
		$this->rows['UrenRegistratie'] = [['id' => 'h1', 'projectId' => 'p1'], ['id' => 'h2', 'projectId' => 'p2']];
		$this->failingSaves = ['p1'];

		$this->runFold();

		self::assertContains('warning: Shillinq: fold — failed to save Project p1: locked', $this->messages);
		self::assertSame(['p2'], array_map(static fn (array $save): string => $save['object']['id'], $this->saved));
		self::assertSame('info: Shillinq: expense/hours fold complete — 1 Project record(s) updated.', end($this->messages));
	}//end testAFailingSaveIsWarnedAndTheOtherProjectsAreSaved()

	/**
	 * Entity rows are read through their payload.
	 *
	 * @return void
	 */
	public function testEntityRowsAreReadThroughTheirPayload(): void {
		$project = $this->createMock(ObjectEntityInterface::class);
		$project->method('getObject')->willReturn(['id' => 'p1']);
		$hours = $this->createMock(ObjectEntityInterface::class);
		$hours->method('getObject')->willReturn(['id' => 'h1', 'projectId' => 'p1', 'hours' => 2]);
		$this->rows['engagement'] = [$project];
		$this->rows['UrenRegistratie'] = [$hours];

		$this->runFold();

		self::assertSame(2.0, $this->savedProject('p1')['hoursLines'][0]['hours']);
	}//end testEntityRowsAreReadThroughTheirPayload()

	/**
	 * Without an admin group the fold still runs.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminGroupTheFoldStillRuns(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$this->groupManager = $groupManager;
		$this->rows['engagement'] = [['id' => 'p1']];
		$this->rows['Receipt'] = [['id' => 'r1', 'projectId' => 'p1']];

		$this->runFold();

		self::assertCount(1, $this->saved);
	}//end testWithoutAnAdminGroupTheFoldStillRuns()

	/**
	 * With an empty admin group the fold still runs.
	 *
	 * @return void
	 */
	public function testWithAnEmptyAdminGroupTheFoldStillRuns(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);
		$this->groupManager = $groupManager;
		$this->rows['engagement'] = [['id' => 'p1']];
		$this->rows['Receipt'] = [['id' => 'r1', 'projectId' => 'p1']];

		$this->runFold();

		self::assertCount(1, $this->saved);
	}//end testWithAnEmptyAdminGroupTheFoldStillRuns()

	/**
	 * A failure is a warning not an exception.
	 *
	 * @return void
	 */
	public function testAFailureIsAWarningNotAnException(): void {
		$this->failing = ['engagement'];
		$this->logger->expects(self::once())->method('warning')
			->with('Shillinq: expense/hours fold failed', ['exception' => 'engagement unavailable']);

		$this->runFold();

		self::assertSame(['warning: Shillinq: expense/hours fold failed: engagement unavailable'], $this->messages);
	}//end testAFailureIsAWarningNotAnException()
}//end class
