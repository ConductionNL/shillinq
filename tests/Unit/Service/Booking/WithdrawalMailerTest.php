<?php

/**
 * Unit tests for WithdrawalMailer.
 *
 * REQ-SCX-006: the consumer receives an e-mail acknowledging the withdrawal
 * with its date and time.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-3.3
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use DateTimeImmutable;
use OCA\Shillinq\Service\Booking\WithdrawalMailer;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * M. Visser withdraws on 2026-09-24 at 16:05 Amsterdam time.
 */
final class WithdrawalMailerTest extends TestCase {

	/**
	 * What the message received.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = [];

	/**
	 * The mailer over a message that records its parts.
	 *
	 * @param mixed $send What IMailer::send answers, or a Throwable to throw.
	 *
	 * @return WithdrawalMailer
	 */
	private function mailer(mixed $send = []): WithdrawalMailer {
		$message = $this->createMock(IMessage::class);
		foreach (['setTo' => 'to', 'setSubject' => 'subject', 'setPlainBody' => 'body'] as $method => $key) {
			$message->method($method)->willReturnCallback(
				function (mixed $value) use ($key, $message): IMessage {
					$this->sent[$key] = $value;
					return $message;
				}
			);
		}

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		if ($send instanceof \Throwable) {
			$mailer->method('send')->willThrowException($send);
		} else {
			$mailer->method('send')->willReturn($send);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new WithdrawalMailer($mailer, $l10n, new NullLogger());
	}//end mailer()

	/**
	 * The withdrawn appointment.
	 *
	 * @return array<string, mixed>
	 */
	private static function appointment(): array {
		return [
			'customerEmail' => 'm.visser@example.nl',
			'serviceName' => 'Knippen en kleuren',
			'startTime' => '2026-10-15T10:00:00Z',
			'customerTimezone' => 'Europe/Amsterdam',
		];
	}//end appointment()

	/**
	 * The acknowledgement names the date and the local time of the withdrawal and the full refund.
	 *
	 * @return void
	 */
	public function testTheAcknowledgementNamesTheDateAndTime(): void {
		$this->assertTrue($this->mailer()->acknowledge(self::appointment(), new DateTimeImmutable('2026-09-24T14:05:00+00:00')));

		$this->assertSame(['m.visser@example.nl'], $this->sent['to']);
		$this->assertSame('We received your withdrawal: Knippen en kleuren', $this->sent['subject']);
		$this->assertStringContainsString('We received your withdrawal from this booking on 2026-09-24 at 16:05.', $this->sent['body']);
		$this->assertStringContainsString('Service: Knippen en kleuren', $this->sent['body']);
		$this->assertStringContainsString('refunded in full within 14 days', $this->sent['body']);
	}//end testTheAcknowledgementNamesTheDateAndTime()

	/**
	 * An unknown time zone falls back to Amsterdam.
	 *
	 * @return void
	 */
	public function testAnUnknownTimeZoneReadsAsAmsterdam(): void {
		$body = $this->mailer()->body(['customerTimezone' => 'Nowhere/Else'] + self::appointment(), new DateTimeImmutable('2026-12-01T09:00:00+00:00'));

		$this->assertStringContainsString('on 2026-12-01 at 10:00.', $body);
	}//end testAnUnknownTimeZoneReadsAsAmsterdam()

	/**
	 * No address, a refused recipient or a failing mailer answer false.
	 *
	 * @return void
	 */
	public function testNoMailIsReportedAsNotSent(): void {
		$now = new DateTimeImmutable('2026-09-24T14:05:00+00:00');

		$this->assertFalse($this->mailer()->acknowledge(['customerEmail' => ''] + self::appointment(), $now));
		$this->assertFalse($this->mailer(['m.visser@example.nl'])->acknowledge(self::appointment(), $now));
		$this->assertFalse($this->mailer(new \RuntimeException('SMTP down'))->acknowledge(self::appointment(), $now));
	}//end testNoMailIsReportedAsNotSent()
}//end class
