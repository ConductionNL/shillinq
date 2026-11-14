<?php

/**
 * Unit tests for the withdrawal acknowledgement mail and the signed guest link.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\CancellationService;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\WithdrawalAcknowledgementMailer;
use OCA\Shillinq\Service\WithdrawalLinkSigner;
use OCA\Shillinq\Service\WithdrawalService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/WithdrawalWithdrawTest.php';

/**
 * The acknowledgement is mailed with the moment received and the refund, and
 * the guest link opens only this appointment, only while it is withdrawable.
 *
 * @covers \OCA\Shillinq\Service\WithdrawalAcknowledgementMailer
 * @covers \OCA\Shillinq\Service\WithdrawalService
 * @uses   \OCA\Shillinq\Service\WithdrawalLinkSigner
 * @uses   \OCA\Shillinq\Service\CancellationService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class WithdrawalAcknowledgementTest extends TestCase {

	/**
	 * What the mailer double was given.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = [];

	/**
	 * The mailer double.
	 *
	 * @param bool $refuse Whether it reports a refused recipient.
	 *
	 * @return IMailer
	 */
	private function mailer(bool $refuse = false): IMailer {
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message) {
			$this->sent['to'] = $to;
			return $message;
		});
		$message->method('setSubject')->willReturnCallback(function (string $subject) use ($message) {
			$this->sent['subject'] = $subject;
			return $message;
		});
		$message->method('setPlainBody')->willReturnCallback(function (string $body) use ($message) {
			$this->sent['body'] = $body;
			return $message;
		});
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn($refuse ? ['m.visser@example.nl'] : []);
		return $mailer;
	}//end mailer()

	/**
	 * An l10n double that fills the placeholders.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}//end l10n()

	/**
	 * M. Visser's appointment, booked through the widget on 2026-09-20.
	 *
	 * @return array<string, mixed>
	 */
	private function appointment(): array {
		return [
			'@self' => ['created' => '2026-09-20T09:00:00+00:00'],
			'appointmentId' => 'apt-1',
			'serviceId' => 'svc-knippen-kleuren',
			'serviceName' => 'Knippen en kleuren',
			'customerEmail' => 'm.visser@example.nl',
			'customerTimezone' => 'Europe/Amsterdam',
			'source' => 'widget',
			'status' => 'confirmed',
			'startTime' => '2026-10-15T10:00:00Z',
			'appointmentCost' => 8500,
		];
	}//end appointment()

	/**
	 * The acknowledgement names the moment in the customer's time zone and the refund.
	 *
	 * @return void
	 */
	public function testTheAcknowledgementNamesTheMomentAndTheRefund(): void {
		$mailer = new WithdrawalAcknowledgementMailer($this->mailer(), $this->l10n(), new NullLogger());
		$now = new DateTimeImmutable('2026-09-24T14:05:00+00:00');

		$this->assertTrue($mailer->send($this->appointment(), ['refundAmount' => 2500, 'refundStatus' => 'initiated'], $now));

		$this->assertSame(['m.visser@example.nl'], $this->sent['to']);
		$this->assertSame('Your withdrawal has been received', $this->sent['subject']);
		$this->assertStringContainsString('We received your withdrawal on 2026-09-24 at 16:05 (Europe/Amsterdam).', $this->sent['body']);
		$this->assertStringContainsString('without a cancellation fee', $this->sent['body']);
		$this->assertStringContainsString('Your payment of EUR 25,00 is being refunded in full.', $this->sent['body']);

		$due = $mailer->body($this->appointment(), ['refundAmount' => 2500, 'refundStatus' => 'due', 'refundDueBy' => '2026-10-08'], $now);
		$this->assertStringContainsString('will be refunded in full, no later than 2026-10-08.', $due);
		$this->assertStringNotContainsString('EUR', $mailer->body($this->appointment(), ['refundAmount' => 0], $now));
	}//end testTheAcknowledgementNamesTheMomentAndTheRefund()

	/**
	 * No address or a refused send means false.
	 *
	 * @return void
	 */
	public function testNoAddressOrARefusedSendIsFalse(): void {
		$appointment = $this->appointment();
		unset($appointment['customerEmail']);
		$now = new DateTimeImmutable('2026-09-24T14:05:00+00:00');

		$this->assertFalse((new WithdrawalAcknowledgementMailer($this->mailer(), $this->l10n(), new NullLogger()))->send($appointment, [], $now));
		$this->assertFalse((new WithdrawalAcknowledgementMailer($this->mailer(refuse: true), $this->l10n(), new NullLogger()))->send($this->appointment(), [], $now));
	}//end testNoAddressOrARefusedSendIsFalse()

	/**
	 * A withdrawal mails the acknowledgement and records when it left.
	 *
	 * @return void
	 */
	public function testAWithdrawalIsAcknowledgedAndTheMomentRecorded(): void {
		$os = new WithdrawalObjectServiceFake();
		$os->data['Appointment']['apt-1'] = $this->appointment();
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);
		$deposits = $this->createMock(DepositPaymentAdapterInterface::class);
		$deposits->method('isDormant')->willReturn(true);
		$service = new WithdrawalService(
			l10n: $this->l10n(),
			container: $container,
			cancellation: new CancellationService(logger: new NullLogger()),
			deposits: $deposits,
			logger: new NullLogger(),
			acknowledgement: new WithdrawalAcknowledgementMailer($this->mailer(), $this->l10n(), new NullLogger()),
		);

		$outcome = $service->withdraw('apt-1', 'guest-link', new DateTimeImmutable('2026-09-24T14:05:00+00:00'));

		$this->assertTrue($outcome['withdrawn']);
		$this->assertSame('2026-09-24T14:05:00+00:00', $outcome['withdrawal']['acknowledgedAt']);
		$last = end($os->saved);
		$this->assertSame(['Withdrawal', 'wd-1'], [$last[0], $last[1]]);
		$this->assertSame('2026-09-24T14:05:00+00:00', $last[2]['acknowledgedAt']);
		$this->assertStringContainsString('We received your withdrawal on 2026-09-24 at 16:05', $this->sent['body']);
	}//end testAWithdrawalIsAcknowledgedAndTheMomentRecorded()

	/**
	 * A guest link service over the given portal page, and its signer.
	 *
	 * @param string $page The configured guest page.
	 *
	 * @return array{0: WithdrawalService, 1: WithdrawalLinkSigner}
	 */
	private function linkService(string $page): array {
		$os = new WithdrawalObjectServiceFake();
		$os->data['Service']['svc-1'] = ['serviceId' => 'svc-knippen-kleuren', 'name' => 'Knippen en kleuren'];
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret-for-the-test');
		$signer = new WithdrawalLinkSigner($config);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === WithdrawalService::GUEST_PAGE_KEY ? $page : $default)
		);
		return [new WithdrawalService(l10n: $this->l10n(), container: $container, signer: $signer, appConfig: $appConfig), $signer];
	}//end linkService()

	/**
	 * The guest link opens this appointment only, and expires with the withdrawal period.
	 *
	 * @return void
	 */
	public function testTheGuestLinkIsSignedForThisAppointmentUntilThePeriodEnds(): void {
		[$service, $signer] = $this->linkService('https://portal.example.nl/guest/withdraw');

		$link = $service->guestLinkFor($this->appointment(), new DateTimeImmutable('2026-09-20T09:05:00+00:00'));

		$this->assertNotNull($link);
		$this->assertSame('2026-10-03', $link['periodEndsOn']);
		$prefix = 'https://portal.example.nl/guest/withdraw?token=';
		$this->assertStringStartsWith($prefix, $link['url']);
		$token = rawurldecode(substr($link['url'], strlen($prefix)));
		$this->assertSame('apt-1', $signer->verify($token, new DateTimeImmutable('2026-10-03T23:00:00+00:00')));
		$this->assertNull($signer->verify($token, new DateTimeImmutable('2026-10-04T00:00:01+00:00')));
	}//end testTheGuestLinkIsSignedForThisAppointmentUntilThePeriodEnds()

	/**
	 * No link without an https page, after the period, or for a booking not made online.
	 *
	 * @return void
	 */
	public function testNoGuestLinkWhenThereIsNoRightOrNoPage(): void {
		$early = new DateTimeImmutable('2026-09-20T09:05:00+00:00');
		$this->assertNull($this->linkService('')[0]->guestLinkFor($this->appointment(), $early));
		$this->assertNull($this->linkService('http://portal.example.nl/w')[0]->guestLinkFor($this->appointment(), $early));

		[$service] = $this->linkService('https://portal.example.nl/w?lang=nl');
		$this->assertStringStartsWith('https://portal.example.nl/w?lang=nl&token=', $service->guestLinkFor($this->appointment(), $early)['url']);
		$this->assertNull($service->guestLinkFor($this->appointment(), new DateTimeImmutable('2026-10-05T09:00:00+00:00')));
		$counter = $this->appointment();
		$counter['source'] = 'counter';
		$this->assertNull($service->guestLinkFor($counter, $early));
	}//end testNoGuestLinkWhenThereIsNoRightOrNoPage()
}//end class
