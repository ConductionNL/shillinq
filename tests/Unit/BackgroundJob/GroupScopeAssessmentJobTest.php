<?php

/**
 * Unit tests for GroupScopeAssessmentJob: which groups it assesses and when
 * it tells the owner.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\GroupScopeAssessmentJob;
use OCA\Shillinq\Service\Cbcr\GroupScopeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The weekly scope job over a mocked scope service and notification manager.
 */
#[CoversClass(GroupScopeAssessmentJob::class)]
final class GroupScopeAssessmentJobTest extends TestCase {
	/**
	 * Notifications sent: user, object id and subject parameters.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sent = [];

	/**
	 * The job over the given scope service, at 2026-11-14 10:00 UTC.
	 *
	 * @param GroupScopeService $scope  The scope service.
	 * @param LoggerInterface   $logger The logger.
	 *
	 * @return GroupScopeAssessmentJob
	 */
	private function job(GroupScopeService $scope, LoggerInterface $logger): GroupScopeAssessmentJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-11-14T10:00:00+00:00'))->getTimestamp());

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$state        = [];
				$notification = $this->createMock(INotification::class);
				foreach (['setApp' => 'app', 'setUser' => 'user', 'setDateTime' => 'at'] as $method => $key) {
					$notification->method($method)->willReturnCallback(
						static function ($value) use (&$state, $key, $notification): INotification {
							$state[$key] = $value;
							return $notification;
						}
					);
				}

				$notification->method('setObject')->willReturnCallback(
					static function (string $type, string $id) use (&$state, $notification): INotification {
						$state['object'] = $type . '/' . $id;
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					static function (string $subject, array $parameters) use (&$state, $notification): INotification {
						$state['subject']    = $subject;
						$state['parameters'] = $parameters;
						return $notification;
					}
				);
				// Read the state when asked, not when the mock is built.
				$read = static function (string $key, mixed $default) use (&$state): mixed {
					return ($state[$key] ?? $default);
				};
				$notification->method('getUser')->willReturnCallback(static fn (): string => (string)$read('user', ''));
				$notification->method('getSubjectParameters')->willReturnCallback(static fn (): array => (array)$read('parameters', []));
				$notification->method('getObjectId')->willReturnCallback(static fn (): string => (string)$read('object', ''));
				$notification->method('getApp')->willReturnCallback(static fn (): string => (string)$read('app', ''));

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = [
					'app' => $notification->getApp(),
					'user' => $notification->getUser(),
					'object' => $notification->getObjectId(),
					'parameters' => $notification->getSubjectParameters(),
				];
			}
		);

		return new GroupScopeAssessmentJob($time, $scope, $manager, $logger);
	}//end job()

	/**
	 * Run the job's protected run().
	 *
	 * @param GroupScopeAssessmentJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(GroupScopeAssessmentJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Each due group is assessed for the current year as system; only the one
	 * whose outcome changed tells its owner, and one without an owner is logged.
	 *
	 * @return void
	 */
	public function testDueGroupsAreAssessedAndAChangedOutcomeTellsTheOwner(): void {
		$scope = $this->createMock(GroupScopeService::class);
		$scope->expects($this->once())->method('dueUltimateParents')->with(2026)->willReturn(
			[
				['id' => 'upe-changed', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'responsibleOwner' => 'tax.director'],
				['id' => 'upe-same', 'entityName' => 'Stabiel B.V.', 'responsibleOwner' => 'controller'],
				['id' => 'upe-first', 'entityName' => 'Nieuw B.V.', 'responsibleOwner' => 'controller'],
				['id' => 'upe-orphan', 'entityName' => 'Zonder Eigenaar B.V.'],
			]
		);
		$outcomes = [
			'upe-changed' => ['cbcrInScope' => true, 'pillar2InScope' => false, 'previous' => ['cbcrInScope' => false, 'pillar2InScope' => false]],
			'upe-same' => ['cbcrInScope' => true, 'pillar2InScope' => true, 'previous' => ['cbcrInScope' => true, 'pillar2InScope' => true]],
			'upe-first' => ['cbcrInScope' => true, 'pillar2InScope' => false, 'previous' => null],
			'upe-orphan' => ['cbcrInScope' => false, 'pillar2InScope' => true, 'previous' => ['cbcrInScope' => false, 'pillar2InScope' => false]],
		];
		$assessed = [];
		$scope->method('assess')->willReturnCallback(
			static function (string $upeId, int $fiscalYear, array $entered, string $actor, DateTimeImmutable $now) use (&$assessed, $outcomes): array {
				$assessed[] = [$upeId, $fiscalYear, $entered, $actor, $now->format('c')];
				return $outcomes[$upeId];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'GroupScopeAssessmentJob: the scope changed but the group has no responsible owner to tell',
			['upeId' => 'upe-orphan']
		);

		self::runJob($this->job($scope, $logger));

		$this->assertSame(
			[
				['upe-changed', 2026, [], 'system', '2026-11-14T10:00:00+00:00'],
				['upe-same', 2026, [], 'system', '2026-11-14T10:00:00+00:00'],
				['upe-first', 2026, [], 'system', '2026-11-14T10:00:00+00:00'],
				['upe-orphan', 2026, [], 'system', '2026-11-14T10:00:00+00:00'],
			],
			$assessed
		);
		$this->assertSame(
			[
				[
					'app' => 'shillinq',
					'user' => 'tax.director',
					'object' => 'group_scope_assessment/upe-changed:2026',
					'parameters' => ['group' => 'Voorbeeld Groep Holding B.V.', 'year' => 2026, 'cbcrInScope' => true, 'pillar2InScope' => false],
				],
			],
			$this->sent
		);
	}//end testDueGroupsAreAssessedAndAChangedOutcomeTellsTheOwner()

	/**
	 * A failing assessment is logged and the next group still runs; a failing list stops the run.
	 *
	 * @return void
	 */
	public function testFailuresAreLoggedWithoutStoppingOtherGroups(): void {
		$scope = $this->createMock(GroupScopeService::class);
		$scope->method('dueUltimateParents')->willReturn(
			[['id' => 'upe-broken'], ['id' => 'upe-ok', 'responsibleOwner' => 'tax.director', 'entityName' => 'OK B.V.']]
		);
		$scope->method('assess')->willReturnCallback(
			static function (string $upeId): array {
				if ($upeId === 'upe-broken') {
					throw new RuntimeException('register down');
				}

				return ['cbcrInScope' => true, 'pillar2InScope' => true, 'previous' => ['cbcrInScope' => true, 'pillar2InScope' => false]];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'GroupScopeAssessmentJob: assessing a group failed',
			['upeId' => 'upe-broken', 'exception' => 'register down']
		);

		self::runJob($this->job($scope, $logger));
		$this->assertSame(['tax.director'], array_column($this->sent, 'user'));

		$listing = $this->createMock(GroupScopeService::class);
		$listing->method('dueUltimateParents')->willThrowException(new RuntimeException('no register'));
		$listing->expects($this->never())->method('assess');
		$listLogger = $this->createMock(LoggerInterface::class);
		$listLogger->expects($this->once())->method('warning')->with(
			'GroupScopeAssessmentJob: listing the groups to assess failed',
			['exception' => 'no register']
		);
		self::runJob($this->job($listing, $listLogger));
	}//end testFailuresAreLoggedWithoutStoppingOtherGroups()

	/**
	 * The job runs weekly.
	 *
	 * @return void
	 */
	public function testTheJobRunsWeekly(): void {
		$job = $this->job($this->createMock(GroupScopeService::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(604800, $job->getInterval());
	}//end testTheJobRunsWeekly()
}//end class
