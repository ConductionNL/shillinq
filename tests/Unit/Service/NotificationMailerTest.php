<?php

/**
 * Unit tests for NotificationMailer.
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
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\NotificationMailer;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sends one rendered notification through IMailer.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.2
 */
#[CoversClass(NotificationMailer::class)]
final class NotificationMailerTest extends TestCase {

	/**
	 * A mail the server accepts is built from the address, subject and body and answers true.
	 *
	 * @return void
	 */
	public function testAnAcceptedMailAnswersTrue(): void {
		$message = $this->createMock(IMessage::class);
		$message->expects(self::once())->method('setTo')->with(['ann@example.com'])->willReturnSelf();
		$message->expects(self::once())->method('setSubject')->with('Tomorrow')->willReturnSelf();
		$message->expects(self::once())->method('setPlainBody')->with("See you\n")->willReturnSelf();

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->with('ann@example.com')->willReturn(true);
		$mailer->expects(self::once())->method('createMessage')->willReturn($message);
		$mailer->expects(self::once())->method('send')->with($message)->willReturn([]);

		$sent = (new NotificationMailer(mailer: $mailer, logger: $this->createMock(LoggerInterface::class)))
			->send(address: 'ann@example.com', subject: 'Tomorrow', body: "See you\n");

		self::assertTrue($sent);
	}//end testAnAcceptedMailAnswersTrue()

	/**
	 * A recipient the mail server refuses answers false with a warning.
	 *
	 * @return void
	 */
	public function testARefusedRecipientAnswersFalse(): void {
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('setPlainBody')->willReturnSelf();
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn(['ann@example.com']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('Shillinq: the mail server refused the notification mail');

		self::assertFalse((new NotificationMailer(mailer: $mailer, logger: $logger))->send(address: 'ann@example.com', subject: 's', body: 'b'));
	}//end testARefusedRecipientAnswersFalse()

	/**
	 * A mailer that throws answers false and logs the error; nothing is thrown.
	 *
	 * @return void
	 */
	public function testAThrowingMailerAnswersFalse(): void {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willThrowException(new RuntimeException('smtp down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Shillinq: notification mail could not be sent', ['exception' => 'smtp down']);

		self::assertFalse((new NotificationMailer(mailer: $mailer, logger: $logger))->send(address: 'ann@example.com', subject: 's', body: 'b'));
	}//end testAThrowingMailerAnswersFalse()

	/**
	 * An address that is not an email address never reaches the mail server.
	 *
	 * @return void
	 */
	public function testAnInvalidAddressIsNotSent(): void {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->with('+31612345678')->willReturn(false);
		$mailer->expects(self::never())->method('createMessage');
		$mailer->expects(self::never())->method('send');

		self::assertFalse((new NotificationMailer(mailer: $mailer, logger: $this->createMock(LoggerInterface::class)))->send(address: '+31612345678', subject: 's', body: 'b'));
	}//end testAnInvalidAddressIsNotSent()
}//end class
