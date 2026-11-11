<?php

/**
 * Golden test for the ready/failed notifications of ScheduledReportDelivery.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting\Schedule
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Schedule;

use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCP\Files\IRootFolder;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins every notification setter call, URL lookup and notify(), in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ScheduledReportNotifyGoldenTest extends TestCase {

	/**
	 * Recorded calls.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testNotificationsMatchGolden(): void {
		$result = [];
		$schedule = ['id' => 'sched-1', 'name' => 'Maandrapportage'];
		$scenarios = [
			'readyToGroupAndUser' => fn ($d) => $d->notifyReady(['group:controllers', 'user:anna', 'group:nobody'], $schedule, '2026-09', 77),
			'readyNoName' => fn ($d) => $d->notifyReady(['user:carl'], ['reportType' => 'trial-balance'], '2026', 5),
			'readyNobody' => fn ($d) => $d->notifyReady([], $schedule, '2026-09', 77),
			'failed' => fn ($d) => $d->notifyFailed('anna', $schedule, 'Docudesk is not installed.'),
			'failedNoOwner' => fn ($d) => $d->notifyFailed('', $schedule, 'x'),
			'failedNoId' => fn ($d) => $d->notifyFailed('bert', ['name' => 'R'], 'y'),
		];
		foreach ($scenarios as $name => $run) {
			$this->calls = [];
			$returned = $run($this->delivery());
			$result[$name] = ['returned' => $returned, 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/scheduled-report-notify.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('REPORT_NOTIFY_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testNotificationsMatchGolden()

	/**
	 * A delivery whose collaborators record into $this->calls.
	 *
	 * @return ScheduledReportDelivery
	 */
	private function delivery(): ScheduledReportDelivery {
		$user = fn (string $uid): IUser => $this->createConfiguredMock(IUser::class, ['getUID' => $uid]);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user('anna'), $user('bert')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			static function (string $gid) use ($group) {
				if ($gid === 'controllers') {
					return $group;
				}

				return null;
			}
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function () {
				$this->calls[] = ['createNotification'];
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $method) {
					$notification->method($method)->willReturnCallback(
						function (...$args) use ($method, $notification) {
							if ($method === 'setDateTime') {
								$args = [get_class($args[0])];
							}

							$this->calls[] = array_merge([$method], $args);
							return $notification;
						}
					);
				}

				return $notification;
			}
		);
		$notifications->method('notify')->willReturnCallback(
			function () {
				$this->calls[] = ['notify'];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			function (string $route, array $parameters=[]): string {
				$this->calls[] = ['linkToRouteAbsolute', $route, $parameters];
				return 'https://cloud.example/'.$route;
			}
		);

		return new ScheduledReportDelivery(
			$this->createMock(IRootFolder::class),
			$this->createMock(IShareManager::class),
			$groups,
			$notifications,
			$urls,
			new NullLogger()
		);

	}//end delivery()
}//end class
