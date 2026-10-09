<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * reporting-data-delivery REQ-RDD-002: the produced report is filed in the
 * schedule's folder, shared read-only with the recipients, and every
 * recipient, a group expanded to its members, gets one notification with a
 * link; a failed run tells the owner why. The notifier renders both.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Schedule;

use OCA\Shillinq\Notification\ScheduledReportNotifier;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledReportDeliveryTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $notified = [];

	/** @var array<int, array{type: int, with: string, permissions: int}> */
	private array $shares = [];

	/**
	 * A delivery over doubles of the Nextcloud managers.
	 *
	 * @param IRootFolder $root The files root.
	 *
	 * @return ScheduledReportDelivery The delivery.
	 */
	private function delivery(IRootFolder $root): ScheduledReportDelivery {
		$user = fn (string $uid): IUser => $this->createConfiguredMock(IUser::class, ['getUID' => $uid]);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user('anna'), $user('bert')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid) => $gid === 'controllers' ? $group : null);

		$shares = $this->createMock(IShareManager::class);
		$shares->method('newShare')->willReturnCallback(function () {
			$share = $this->createMock(IShare::class);
			$state = [];
			foreach (['setNode', 'setSharedBy', 'setShareOwner'] as $method) {
				$share->method($method)->willReturnSelf();
			}

			$share->method('setShareType')->willReturnCallback(function (int $type) use ($share, &$state) {
				$state['type'] = $type;
				return $share;
			});
			$share->method('setSharedWith')->willReturnCallback(function (string $with) use ($share, &$state) {
				$state['with'] = $with;
				return $share;
			});
			$share->method('setPermissions')->willReturnCallback(function (int $permissions) use ($share, &$state) {
				$state['permissions'] = $permissions;
				$this->shares[] = $state;
				return $share;
			});

			return $share;
		});

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(function () {
			$record = [];
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setDateTime', 'setObject'] as $method) {
				$notification->method($method)->willReturnSelf();
			}

			$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$record) {
				$record['user'] = $uid;
				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification, &$record) {
				$record['subject'] = $subject;
				$record['parameters'] = $parameters;
				return $notification;
			});
			$notification->method('setLink')->willReturnCallback(function (string $link) use ($notification, &$record) {
				$record['link'] = $link;
				$this->notified[] = $record;
				return $notification;
			});

			return $notification;
		});

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => 'https://cloud.example/' . $route . '/' . implode('/', $parameters)
		);

		return new ScheduledReportDelivery($root, $shares, $groups, $notifications, $urls, $this->createMock(LoggerInterface::class));

	}//end delivery()

	/**
	 * The report is copied into the folder and shared with the group, read only.
	 *
	 * @return void
	 */
	public function testTheReportIsFiledAndShared(): void {
		$produced = $this->createConfiguredMock(File::class, ['getName' => 'budget-vs-actual-2026-09.pdf', 'getContent' => '%PDF']);
		$copy     = $this->createConfiguredMock(File::class, ['getId' => 77, 'getPath' => '/anna/files/Rapportages/Maand/budget-vs-actual-2026-09.pdf']);
		$month    = $this->createMock(Folder::class);
		$month->method('nodeExists')->willReturn(false);
		$month->expects(self::once())->method('newFile')->with('budget-vs-actual-2026-09.pdf', '%PDF')->willReturn($copy);
		$reports = $this->createMock(Folder::class);
		$reports->method('nodeExists')->willReturn(false);
		$reports->method('newFolder')->with('Maand')->willReturn($month);
		$home = $this->createMock(Folder::class);
		$home->method('getFirstNodeById')->with(42)->willReturn($produced);
		$home->method('nodeExists')->willReturn(false);
		$home->method('newFolder')->with('Rapportages')->willReturn($reports);
		$home->method('getRelativePath')->willReturn('/Rapportages/Maand/budget-vs-actual-2026-09.pdf');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('anna')->willReturn($home);

		$filed = $this->delivery($root)->file('anna', 42, '/Rapportages/Maand', ['group:controllers', 'user:anna']);

		self::assertSame(['fileId' => 77, 'path' => '/Rapportages/Maand/budget-vs-actual-2026-09.pdf'], $filed);
		self::assertSame([['type' => IShare::TYPE_GROUP, 'with' => 'controllers', 'permissions' => Constants::PERMISSION_READ]], $this->shares);

	}//end testTheReportIsFiledAndShared()

	/**
	 * Each controller gets one notification linking to the file, and the notifier renders it.
	 *
	 * @return void
	 */
	public function testEachControllerIsNotifiedOnce(): void {
		$delivery = $this->delivery($this->createMock(IRootFolder::class));

		$sent = $delivery->notifyReady(['group:controllers', 'user:anna', 'group:nobody'], ['id' => 'sched-1', 'name' => 'Maandrapportage'], '2026-09', 77);

		self::assertSame(2, $sent);
		self::assertSame(['anna', 'bert'], array_column($this->notified, 'user'));
		self::assertSame('https://cloud.example/files.viewcontroller.showFile/77', $this->notified[0]['link']);
		self::assertSame(ScheduledReportDelivery::SUBJECT_READY, $this->notified[0]['subject']);
		self::assertSame('Maandrapportage for 2026-09 is ready', $this->render($this->notified[0]));

	}//end testEachControllerIsNotifiedOnce()

	/**
	 * A failed run tells the owner why.
	 *
	 * @return void
	 */
	public function testAFailedRunTellsTheOwner(): void {
		$this->delivery($this->createMock(IRootFolder::class))->notifyFailed('anna', ['id' => 'sched-1', 'name' => 'Maandrapportage'], 'Docudesk is not installed.');

		self::assertSame('anna', $this->notified[0]['user']);
		self::assertSame(ScheduledReportDelivery::SUBJECT_FAILED, $this->notified[0]['subject']);
		self::assertSame('Docudesk is not installed.', $this->notified[0]['parameters']['reason']);
		self::assertSame('The scheduled report Maandrapportage could not be produced', $this->render($this->notified[0]));

	}//end testAFailedRunTellsTheOwner()

	/**
	 * Render a recorded notification through the real notifier.
	 *
	 * @param array<string, mixed> $record The recorded notification.
	 *
	 * @return string The parsed subject.
	 */
	private function render(array $record): string {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$parsed       = '';
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('shillinq');
		$notification->method('getSubject')->willReturn($record['subject']);
		$notification->method('getSubjectParameters')->willReturn($record['parameters']);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use ($notification, &$parsed) {
			$parsed = $subject;
			return $notification;
		});
		$notification->method('setParsedMessage')->willReturnSelf();

		(new ScheduledReportNotifier($factory))->prepare($notification, 'en');

		return $parsed;

	}//end render()
}//end class
