<?php

/**
 * Unit tests for ScheduledReportNotifier: it renders only this app's two
 * scheduled-report subjects, in the recipient's language, and refuses
 * everything else so another notifier can take it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Notification
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

namespace OCA\Shillinq\Tests\Unit\Notification;

use OCA\Shillinq\Notification\ScheduledReportNotifier;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Notification\ScheduledReportNotifier
 */
class ScheduledReportNotifierTest extends TestCase {

	private IFactory&MockObject $factory;

	/**
	 * The languages asked of the factory, in order.
	 *
	 * @var array<int, string|null>
	 */
	private array $languages = [];

	private ScheduledReportNotifier $notifier;

	/**
	 * A notifier whose translator prefixes the language and fills %n$s.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->factory = $this->createMock(IFactory::class);
		$this->factory->method('get')->willReturnCallback(
			function (string $app, ?string $lang = null): IL10N {
				$this->languages[] = $lang;
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(
					static fn (string $text, array $parameters = []): string => '[' . ($lang ?? '-') . '] ' . vsprintf($text, $parameters)
				);

				return $l10n;
			}
		);
		$this->notifier = new ScheduledReportNotifier($this->factory);

	}//end setUp()

	/**
	 * A notification double with the given app, subject and parameters,
	 * recording what the notifier parses into it.
	 *
	 * @param string               $app        The app.
	 * @param string               $subject    The subject key.
	 * @param array<string, mixed> $parameters The subject parameters.
	 * @param array<string, string> $parsed    Filled with subject / message.
	 *
	 * @return INotification
	 */
	private function notification(string $app, string $subject, array $parameters, array &$parsed): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(
			static function (string $text) use ($notification, &$parsed): INotification {
				$parsed['subject'] = $text;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			static function (string $text) use ($notification, &$parsed): INotification {
				$parsed['message'] = $text;
				return $notification;
			}
		);

		return $notification;

	}//end notification()

	public function testItHasItsOwnIdAndATranslatedName(): void {
		$this->assertSame('shillinq-scheduled-reports', $this->notifier->getID());
		$this->assertSame('[-] Scheduled reports', $this->notifier->getName());

	}//end testItHasItsOwnIdAndATranslatedName()

	public function testAReadyReportNamesTheReportAndPeriodInTheRecipientsLanguage(): void {
		$parsed = [];
		$notification = $this->notification('shillinq', ScheduledReportDelivery::SUBJECT_READY, ['name' => 'Budget', 'period' => '2026-09'], $parsed);

		$this->assertSame($notification, $this->notifier->prepare($notification, 'nl'));

		$this->assertSame(['subject' => '[nl] Budget for 2026-09 is ready'], $parsed);
		$this->assertSame(['nl'], $this->languages);

	}//end testAReadyReportNamesTheReportAndPeriodInTheRecipientsLanguage()

	public function testAFailedReportCarriesItsReasonAsTheMessage(): void {
		$parsed = [];
		$notification = $this->notification('shillinq', ScheduledReportDelivery::SUBJECT_FAILED, ['name' => 'Budget', 'reason' => 'Ledger is locked.'], $parsed);

		$this->notifier->prepare($notification, 'de');

		$this->assertSame(
			['subject' => '[de] The scheduled report Budget could not be produced', 'message' => 'Ledger is locked.'],
			$parsed
		);

	}//end testAFailedReportCarriesItsReasonAsTheMessage()

	public function testMissingParametersRenderEmpty(): void {
		$parsed = [];
		$this->notifier->prepare($this->notification('shillinq', ScheduledReportDelivery::SUBJECT_FAILED, [], $parsed), 'en');

		$this->assertSame(['subject' => '[en] The scheduled report  could not be produced', 'message' => ''], $parsed);

	}//end testMissingParametersRenderEmpty()

	public function testAnotherAppsNotificationIsRefused(): void {
		$parsed = [];
		$this->expectException(UnknownNotificationException::class);

		try {
			$this->notifier->prepare($this->notification('pipelinq', ScheduledReportDelivery::SUBJECT_READY, [], $parsed), 'en');
		} finally {
			$this->assertSame([], $parsed);
			$this->assertSame([], $this->languages);
		}

	}//end testAnotherAppsNotificationIsRefused()

	public function testAnotherSubjectOfThisAppIsRefused(): void {
		$parsed = [];
		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare($this->notification('shillinq', 'einvoice_received', [], $parsed), 'en');

	}//end testAnotherSubjectOfThisAppIsRefused()
}//end class
