<?php

/**
 * Unit tests for EInvoiceNotifier.
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
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Notification;

use OCA\Shillinq\Notification\EInvoiceNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * The rejection notice the Peppol listener raises is rendered, not dropped.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EInvoiceNotifierTest extends TestCase {
	/**
	 * What prepare() wrote on the notification.
	 *
	 * @var array<string,string>
	 */
	private array $parsed = [];

	/**
	 * Build the notifier over an l10n that substitutes %n$s like Nextcloud's.
	 *
	 * @return EInvoiceNotifier
	 */
	private function notifier(): EInvoiceNotifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				return vsprintf($text, (array)$parameters);
			}
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route): string {
				return ($route === 'shillinq.dashboard.page') ? 'https://cloud.example.nl/index.php/apps/shillinq/' : 'https://wrong.example/';
			}
		);

		return new EInvoiceNotifier(l10nFactory: $factory, urlGenerator: $url);
	}//end notifier()

	/**
	 * A notification as PeppolDeliveryStatusListener raises it.
	 *
	 * @param string $app The app id.
	 * @param string $subject The subject.
	 * @param array<string,string> $parameters The subject parameters.
	 *
	 * @return INotification
	 */
	private function notification(string $app, string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setLink' => 'link'] as $method => $key) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use ($key, $notification): INotification {
					$this->parsed[$key] = $value;
					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()

	/**
	 * The rejection renders with the invoice number, the reason and a link to the invoice.
	 *
	 * @return void
	 */
	public function testARejectedEInvoiceRendersWithTheReasonAndALinkToTheInvoice(): void {
		$this->notifier()->prepare(
			$this->notification(
				app: 'shillinq',
				subject: 'einvoice_delivery_rejected',
				parameters: ['invoiceNumber' => '2026-0060', 'detail' => 'Unknown recipient participant', 'invoiceId' => 'a1b2c3d4-0000-4000-8000-000000000060']
			),
			'nl'
		);

		self::assertSame('E-invoice 2026-0060 was rejected', $this->parsed['subject']);
		self::assertStringContainsString('Reason: Unknown recipient participant', $this->parsed['message']);
		self::assertSame(
			'https://cloud.example.nl/index.php/apps/shillinq/bookkeeping/accounts-receivable/a1b2c3d4-0000-4000-8000-000000000060',
			$this->parsed['link']
		);
	}//end testARejectedEInvoiceRendersWithTheReasonAndALinkToTheInvoice()

	/**
	 * Without a reason the message says so; without an invoice id there is no dead link.
	 *
	 * @return void
	 */
	public function testARejectionWithoutReasonOrIdStillRenders(): void {
		$this->notifier()->prepare(
			$this->notification(app: 'shillinq', subject: 'einvoice_delivery_rejected', parameters: ['invoiceNumber' => '2026-0061']),
			'en'
		);

		self::assertSame('E-invoice 2026-0061 was rejected', $this->parsed['subject']);
		self::assertStringContainsString('gave no reason', $this->parsed['message']);
		self::assertArrayNotHasKey('link', $this->parsed);
	}//end testARejectionWithoutReasonOrIdStillRenders()

	/**
	 * Another subject is not ours.
	 *
	 * @return void
	 */
	public function testAnotherSubjectIsRefused(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare(
			$this->notification(app: 'shillinq', subject: 'deadline_reminder', parameters: []),
			'en'
		);
	}//end testAnotherSubjectIsRefused()

	/**
	 * Another app's notification is not ours either.
	 *
	 * @return void
	 */
	public function testAnotherAppIsRefused(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare(
			$this->notification(app: 'dossiq', subject: 'einvoice_delivery_rejected', parameters: []),
			'en'
		);
	}//end testAnotherAppIsRefused()
}//end class
