<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Filing a scheduled report, the edges: a produced file that is gone, a name
 * already taken in the folder, folders that already exist, a folder path that
 * runs into a file, malformed recipients, and a share the share manager
 * refuses, which is logged without losing the filed copy.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Schedule;

use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery
 */
class ScheduledReportDeliveryFilingTest extends TestCase {

	/**
	 * Shares handed to createShare(): type, with, permissions.
	 *
	 * @var array<int, array{type: int, with: string, permissions: int}>
	 */
	private array $created = [];

	/**
	 * Recipients whose share the share manager refuses.
	 *
	 * @var array<int, string>
	 */
	private array $refuse = [];

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A delivery over the given root and a recording share manager.
	 *
	 * @param IRootFolder $root The files root.
	 *
	 * @return ScheduledReportDelivery The delivery.
	 */
	private function delivery(IRootFolder $root): ScheduledReportDelivery {
		$shares = $this->createMock(IShareManager::class);
		$shares->method('newShare')->willReturnCallback(function (): IShare {
			$state = ['type' => -1, 'with' => '', 'permissions' => -1];
			$share = $this->createMock(IShare::class);
			$share->method('setNode')->willReturnSelf();
			$share->method('setSharedBy')->willReturnSelf();
			$share->method('setShareOwner')->willReturnSelf();
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
				return $share;
			});
			$share->method('getSharedWith')->willReturnCallback(function () use (&$state): string {
				return $state['with'];
			});
			$share->method('getShareType')->willReturnCallback(function () use (&$state): int {
				return $state['type'];
			});
			$share->method('getPermissions')->willReturnCallback(function () use (&$state): int {
				return $state['permissions'];
			});

			return $share;
		});
		$shares->method('createShare')->willReturnCallback(function (IShare $share): IShare {
			if (in_array($share->getSharedWith(), $this->refuse, true) === true) {
				throw new \RuntimeException('sharing is disabled for ' . $share->getSharedWith());
			}

			$this->created[] = ['type' => $share->getShareType(), 'with' => $share->getSharedWith(), 'permissions' => $share->getPermissions()];

			return $share;
		});

		$this->logger = $this->createMock(LoggerInterface::class);

		return new ScheduledReportDelivery(
			$root,
			$shares,
			$this->createMock(IGroupManager::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(IURLGenerator::class),
			$this->logger
		);

	}//end delivery()

	/**
	 * A root whose owner folder holds the produced file (or not).
	 *
	 * @param Folder    $home     The owner's files.
	 * @param File|null $produced The produced file, or null when gone.
	 *
	 * @return IRootFolder The root.
	 */
	private function root(Folder $home, ?File $produced): IRootFolder {
		$home->method('getFirstNodeById')->with(42)->willReturn($produced);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('anna')->willReturn($home);

		return $root;

	}//end root()

	/**
	 * The produced report.
	 *
	 * @return File The file.
	 */
	private function produced(): File {
		return $this->createConfiguredMock(File::class, ['getName' => 'report.pdf', 'getContent' => '%PDF-1.7']);

	}//end produced()

	/**
	 * The filed copy.
	 *
	 * @return File The copy.
	 */
	private function copy(): File {
		return $this->createConfiguredMock(File::class, ['getId' => 77, 'getPath' => '/anna/files/Reports/report.pdf']);

	}//end copy()

	/**
	 * A produced file that cannot be found is refused before anything is filed.
	 *
	 * @return void
	 */
	public function testAMissingProducedFileIsRefused(): void {
		$home = $this->createMock(Folder::class);
		$home->expects(self::never())->method('newFolder');
		$home->expects(self::never())->method('newFile');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The produced report file cannot be found.');

		$this->delivery($this->root($home, null))->file('anna', 42, '/Reports', ['user:bert']);

	}//end testAMissingProducedFileIsRefused()

	/**
	 * A node that is a folder, not a file, is refused the same way.
	 *
	 * @return void
	 */
	public function testAProducedNodeThatIsNotAFileIsRefused(): void {
		$home = $this->createMock(Folder::class);
		$home->method('getFirstNodeById')->willReturn($this->createMock(Folder::class));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The produced report file cannot be found.');

		$this->delivery($root)->file('anna', 42, '/Reports', []);

	}//end testAProducedNodeThatIsNotAFileIsRefused()

	/**
	 * A name already taken gets a UTC timestamp suffix, keeping its extension.
	 *
	 * @return void
	 */
	public function testATakenNameGetsATimestampSuffix(): void {
		$name = '';
		$reports = $this->createMock(Folder::class);
		$reports->method('nodeExists')->with('report.pdf')->willReturn(true);
		$reports->expects(self::once())->method('newFile')->willReturnCallback(
			function (string $path, $content) use (&$name): File {
				$name = $path;
				self::assertSame('%PDF-1.7', $content);

				return $this->copy();
			}
		);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->with('Reports')->willReturn(true);
		$home->method('get')->with('Reports')->willReturn($reports);
		$home->method('getRelativePath')->willReturn('/Reports/x.pdf');

		$this->delivery($this->root($home, $this->produced()))->file('anna', 42, 'Reports', []);

		self::assertMatchesRegularExpression('/^report-\d{14}\.pdf$/', $name);

	}//end testATakenNameGetsATimestampSuffix()

	/**
	 * Existing folders are reused, missing ones created, and empty, `.` and
	 * `..` segments are ignored rather than followed.
	 *
	 * @return void
	 */
	public function testExistingFoldersAreReusedAndDotSegmentsIgnored(): void {
		$month = $this->createMock(Folder::class);
		$month->method('nodeExists')->willReturn(false);
		$month->expects(self::once())->method('newFile')->with('report.pdf', '%PDF-1.7')->willReturn($this->copy());
		$reports = $this->createMock(Folder::class);
		$reports->method('nodeExists')->with('Month')->willReturn(false);
		$reports->expects(self::once())->method('newFolder')->with('Month')->willReturn($month);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->with('Reports')->willReturn(true);
		$home->expects(self::never())->method('newFolder');
		$home->expects(self::once())->method('get')->with('Reports')->willReturn($reports);
		$home->method('getRelativePath')->with('/anna/files/Reports/report.pdf')->willReturn('/Reports/Month/report.pdf');

		$filed = $this->delivery($this->root($home, $this->produced()))->file('anna', 42, '/./Reports//../Month/', []);

		self::assertSame(['fileId' => 77, 'path' => '/Reports/Month/report.pdf'], $filed);

	}//end testExistingFoldersAreReusedAndDotSegmentsIgnored()

	/**
	 * A folder path that runs into a file is refused, naming the segment.
	 *
	 * @return void
	 */
	public function testAFolderPathThroughAFileIsRefused(): void {
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->with('Reports')->willReturn(true);
		$home->method('get')->with('Reports')->willReturn($this->createMock(File::class));
		$home->expects(self::never())->method('newFile');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The report folder path runs into a file: Reports');

		$this->delivery($this->root($home, $this->produced()))->file('anna', 42, '/Reports/Month', []);

	}//end testAFolderPathThroughAFileIsRefused()

	/**
	 * Malformed recipients and the owner get no share; a user and a group do,
	 * read only and with the right share type.
	 *
	 * @return void
	 */
	public function testOnlyWellFormedRecipientsOtherThanTheOwnerAreShared(): void {
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(false);
		$home->method('newFile')->willReturn($this->copy());
		$home->method('getRelativePath')->willReturn('/report.pdf');

		$this->delivery($this->root($home, $this->produced()))->file(
			'anna',
			42,
			'',
			['bogus', 'user:', 'team:finance', 'user:anna', 'user:bert', 'group:controllers', 'group:']
		);

		self::assertSame(
			[
				['type' => IShare::TYPE_USER, 'with' => 'bert', 'permissions' => Constants::PERMISSION_READ],
				['type' => IShare::TYPE_GROUP, 'with' => 'controllers', 'permissions' => Constants::PERMISSION_READ],
			],
			$this->created
		);

	}//end testOnlyWellFormedRecipientsOtherThanTheOwnerAreShared()

	/**
	 * A refused share is logged and the other recipients are still shared;
	 * the filed copy is returned, falling back to its absolute path when it
	 * has no path relative to the owner's files.
	 *
	 * @return void
	 */
	public function testARefusedShareIsLoggedAndTheRestStillShared(): void {
		$this->refuse = ['bert'];
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(false);
		$home->method('newFile')->willReturn($this->copy());
		$home->method('getRelativePath')->willReturn(null);
		$root = $this->root($home, $this->produced());
		$delivery = $this->delivery($root);
		$this->logger->expects(self::once())->method('warning')->with(
			'ScheduledReportDelivery: share not created',
			['recipient' => 'user:bert', 'exception' => 'sharing is disabled for bert']
		);

		$filed = $delivery->file('anna', 42, '', ['user:bert', 'user:carl']);

		self::assertSame(['fileId' => 77, 'path' => '/anna/files/Reports/report.pdf'], $filed);
		self::assertSame([['type' => IShare::TYPE_USER, 'with' => 'carl', 'permissions' => Constants::PERMISSION_READ]], $this->created);

	}//end testARefusedShareIsLoggedAndTheRestStillShared()
}//end class
