<?php

/**
 * Golden test for ViesOutageRetryJob::run.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Shillinq\BackgroundJob\ViesOutageRetryJob;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\ViesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins which outages are skipped, escalated or re-validated, and every call and log line in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ViesOutageRetryJobGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run the job.
	 *
	 * @param mixed $argument   The job argument.
	 * @param bool  $notifyFails Whether delivering a notification throws.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument, bool $notifyFails=false): void {
		$filing = $this->createMock(IcpFilingService::class);
		$filing->method('pendingOutages')->willReturnCallback(
			function (string $administrationId): array {
				$this->calls[] = ['pendingOutages', $administrationId];
				return [
					['vatId' => '', 'escalate' => true, 'ageDays' => 20],
					['vatId' => 'NL1', 'escalate' => true, 'ageDays' => 15],
					['vatId' => 'DE2', 'escalate' => false, 'ageDays' => 3],
					['vatId' => 'BE3', 'escalate' => false, 'ageDays' => 1],
				];
			}
		);
		$vies = $this->createMock(ViesService::class);
		$vies->method('validate')->willReturnCallback(
			function (string $administrationId, string $vatId): array {
				$this->calls[] = ['validate', $administrationId, $vatId];
				return ['outage' => ($vatId === 'BE3'), 'valid' => true];
			}
		);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $notification): INotification {
					$this->calls[] = array_merge([$setter], $args);
					return $notification;
				}
			);
		}

		$notification->method('setDateTime')->willReturnCallback(
			function (\DateTimeInterface $when) use ($notification): INotification {
				$this->calls[] = ['setDateTime', ($when instanceof DateTime)];
				return $notification;
			}
		);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function () use ($notification): INotification {
				$this->calls[] = ['createNotification'];
				return $notification;
			}
		);
		$notifications->method('notify')->willReturnCallback(
			function (INotification $given) use ($notifyFails): void {
				$this->calls[] = ['notify'];
				if ($notifyFails === true) {
					throw new RuntimeException('push down');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$job = new ViesOutageRetryJob($this->createMock(ITimeFactory::class), $filing, $vies, $notifications, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * Without an administration the job does nothing.
	 *
	 * @return void
	 */
	public function testNoAdministration(): void {
		foreach ([null, 'a1', [], ['administrationId' => ''], ['administrationId' => null]] as $argument) {
			$this->runJob(argument: $argument);
		}

		self::assertSame(expected: [], actual: $this->calls);
	}//end testNoAdministration()

	/**
	 * A blank VAT-ID is skipped, an old outage escalated, a fresh one re-validated and a definitive answer logged.
	 *
	 * @return void
	 */
	public function testRetries(): void {
		$this->runJob(argument: ['administrationId' => 7]);
		$escalation = [
			['createNotification'],
			['setApp', 'shillinq'],
			['setUser', '7'],
			['setDateTime', true],
			['setObject', 'icp_vies_outage', 'NL1'],
			['setSubject', 'icp.vies.outage_escalated', ['vatId' => 'NL1', 'ageDays' => 15]],
			['notify'],
		];
		$info     = ['info', 'ViesOutageRetryJob: VIES returned a definitive answer for a pending VAT-ID', ['administrationId' => '7', 'valid' => true]];
		$expected = array_merge([['pendingOutages', '7']], $escalation, [['validate', '7', 'DE2'], $info, ['validate', '7', 'BE3']]);
		self::assertSame(expected: $expected, actual: $this->calls);
		$this->calls = [];
		$this->runJob(argument: ['administrationId' => 'a1'], notifyFails: true);
		$warning = ['warning', 'ViesOutageRetryJob: failed to deliver outage escalation', ['administrationId' => 'a1', 'exception' => 'push down']];
		self::assertSame(expected: $warning, actual: $this->calls[8]);
		self::assertSame(expected: ['validate', 'a1', 'DE2'], actual: $this->calls[9]);
	}//end testRetries()
}//end class
