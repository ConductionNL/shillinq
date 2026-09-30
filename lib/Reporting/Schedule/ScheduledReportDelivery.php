<?php

/**
 * Scheduled Report Delivery
 *
 * Delivers the file a scheduled run produced (reporting-data-delivery
 * REQ-RDD-002): files a copy in the schedule's folder in its owner's
 * storage, shares that copy read-only with each recipient, and notifies the
 * recipients with a link. A failed run notifies the owner with the reason.
 *
 * Recipients are `user:<id>` or `group:<id>`; a group is shared as a group
 * and notified member by member. An unknown recipient is skipped and logged,
 * never a reason to lose the report.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Schedule;

use DateTime;
use OCA\Shillinq\AppInfo\Application;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Files, shares and announces a scheduled report.
 */
class ScheduledReportDelivery {

	public const SUBJECT_READY = 'scheduled_report_ready';

	public const SUBJECT_FAILED = 'scheduled_report_failed';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder          $rootFolder    Nextcloud files.
	 * @param IShareManager        $shareManager  Shares the filed copy.
	 * @param IGroupManager        $groupManager  Expands a group to its members.
	 * @param INotificationManager $notifications Sends the notifications.
	 * @param IURLGenerator        $urlGenerator  Builds the file link.
	 * @param LoggerInterface      $logger        Logger.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IShareManager $shareManager,
		private readonly IGroupManager $groupManager,
		private readonly INotificationManager $notifications,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * File a copy of a produced report in the schedule's folder and share it.
	 *
	 * @param string        $ownerId    The schedule owner.
	 * @param int           $fileId     The produced file in the owner's storage.
	 * @param string        $folderPath The destination folder in the owner's files.
	 * @param array<string> $recipients user:<id> and group:<id> entries.
	 *
	 * @return array{fileId: int, path: string} The filed copy.
	 *
	 * @throws \RuntimeException When the produced file cannot be found or filed.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function file(string $ownerId, int $fileId, string $folderPath, array $recipients): array {
		$userFolder = $this->rootFolder->getUserFolder($ownerId);
		$produced   = $userFolder->getFirstNodeById($fileId);
		if ($produced instanceof File === false) {
			throw new \RuntimeException('The produced report file cannot be found.');
		}

		$folder = $this->folder(base: $userFolder, path: $folderPath);
		$name   = $produced->getName();
		if ($folder->nodeExists($name) === true) {
			$name = pathinfo($name, PATHINFO_FILENAME) . '-' . gmdate('YmdHis') . '.' . pathinfo($name, PATHINFO_EXTENSION);
		}

		$copy = $folder->newFile($name, $produced->getContent());
		foreach ($recipients as $recipient) {
			$this->share(node: $copy, ownerId: $ownerId, recipient: (string)$recipient);
		}

		return ['fileId' => (int)$copy->getId(), 'path' => $userFolder->getRelativePath($copy->getPath()) ?? $copy->getPath()];

	}//end file()

	/**
	 * Tell every recipient that the report is ready, with a link to it.
	 *
	 * @param array<string>        $recipients user:<id> and group:<id> entries.
	 * @param array<string, mixed> $schedule   The schedule, with its id.
	 * @param string               $period     The period reported on.
	 * @param int                  $fileId     The filed copy.
	 *
	 * @return int The number of users notified.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function notifyReady(array $recipients, array $schedule, string $period, int $fileId): int {
		$link = $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]);
		$sent = 0;
		foreach ($this->users(recipients: $recipients) as $userId) {
			$this->notify(
				userId: $userId,
				schedule: $schedule,
				subject: self::SUBJECT_READY,
				parameters: ['name' => $this->label(schedule: $schedule), 'period' => $period],
				link: $link
			);
			$sent++;
		}

		return $sent;

	}//end notifyReady()

	/**
	 * Tell the schedule's owner that a run failed and why.
	 *
	 * @param string               $ownerId  The schedule owner.
	 * @param array<string, mixed> $schedule The schedule, with its id.
	 * @param string               $reason   The reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function notifyFailed(string $ownerId, array $schedule, string $reason): void {
		if ($ownerId === '') {
			return;
		}

		$this->notify(
			userId: $ownerId,
			schedule: $schedule,
			subject: self::SUBJECT_FAILED,
			parameters: ['name' => $this->label(schedule: $schedule), 'reason' => $reason],
			link: $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page')
		);

	}//end notifyFailed()

	/**
	 * The users behind a recipient list, groups expanded, each once.
	 *
	 * @param array<string> $recipients user:<id> and group:<id> entries.
	 *
	 * @return array<string> The user ids.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function users(array $recipients): array {
		$users = [];
		foreach ($recipients as $recipient) {
			[$kind, $id] = $this->parse(recipient: (string)$recipient);
			if ($kind === 'user') {
				$users[] = $id;
				continue;
			}

			$group = null;
			if ($kind === 'group') {
				$group = $this->groupManager->get($id);
			}

			if ($group === null) {
				$this->logger->warning('ScheduledReportDelivery: unknown recipient skipped', ['recipient' => $recipient]);
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$users[] = $user->getUID();
			}
		}//end foreach

		return array_values(array_unique($users));

	}//end users()

	/**
	 * Share the filed copy read-only with one recipient.
	 *
	 * @param File   $node      The filed copy.
	 * @param string $ownerId   The owner sharing it.
	 * @param string $recipient user:<id> or group:<id>.
	 *
	 * @return void
	 */
	private function share(File $node, string $ownerId, string $recipient): void {
		[$kind, $id] = $this->parse(recipient: $recipient);
		if ($kind === '' || ($kind === 'user' && $id === $ownerId)) {
			return;
		}

		$type = IShare::TYPE_USER;
		if ($kind === 'group') {
			$type = IShare::TYPE_GROUP;
		}

		try {
			$share = $this->shareManager->newShare();
			$share->setNode($node)
				->setShareType($type)
				->setSharedWith($id)
				->setSharedBy($ownerId)
				->setShareOwner($ownerId)
				->setPermissions(Constants::PERMISSION_READ);
			$this->shareManager->createShare($share);
		} catch (Throwable $e) {
			$this->logger->warning('ScheduledReportDelivery: share not created', ['recipient' => $recipient, 'exception' => $e->getMessage()]);
		}

	}//end share()

	/**
	 * Send one notification.
	 *
	 * @param string               $userId     The user.
	 * @param array<string, mixed> $schedule   The schedule.
	 * @param string               $subject    The subject key.
	 * @param array<string, mixed> $parameters The subject parameters.
	 * @param string               $link       Where it leads.
	 *
	 * @return void
	 */
	private function notify(string $userId, array $schedule, string $subject, array $parameters, string $link): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('report_schedule', (string)($schedule['id'] ?? ''))
			->setSubject($subject, $parameters)
			->setLink($link);
		$this->notifications->notify($notification);

	}//end notify()

	/**
	 * The folder at a path under a base folder, created when missing.
	 *
	 * @param Folder $base The owner's files.
	 * @param string $path The folder path.
	 *
	 * @return Folder The folder.
	 *
	 * @throws \RuntimeException When a path segment is a file.
	 */
	private function folder(Folder $base, string $path): Folder {
		$current = $base;
		foreach (array_filter(explode('/', $path), static fn (string $s): bool => $s !== '' && $s !== '.' && $s !== '..') as $segment) {
			if ($current->nodeExists($segment) === false) {
				$current = $current->newFolder($segment);
				continue;
			}

			$node = $current->get($segment);
			if ($node instanceof Folder === false) {
				throw new \RuntimeException('The report folder path runs into a file: ' . $segment);
			}

			$current = $node;
		}

		return $current;

	}//end folder()

	/**
	 * Split a recipient into its kind and id.
	 *
	 * @param string $recipient user:<id> or group:<id>.
	 *
	 * @return array{0: string, 1: string} The kind (user, group or '') and the id.
	 */
	private function parse(string $recipient): array {
		$parts = explode(':', $recipient, 2);
		if (count($parts) !== 2 || $parts[1] === '' || in_array($parts[0], ['user', 'group'], true) === false) {
			return ['', ''];
		}

		return [$parts[0], $parts[1]];

	}//end parse()

	/**
	 * The name a notification shows for a schedule.
	 *
	 * @param array<string, mixed> $schedule The schedule.
	 *
	 * @return string The name.
	 */
	private function label(array $schedule): string {
		$name = (string)($schedule['name'] ?? '');
		if ($name !== '') {
			return $name;
		}

		return (string)($schedule['reportType'] ?? '');

	}//end label()
}//end class
