<?php

/**
 * Unit tests for ConfirmationMailer.
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
 * @spec openspec/specs/bookings-confirm-flow/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Booking\TimezoneResolver;
use OCA\Shillinq\Service\ConfirmationMailer;
use OCA\Shillinq\Service\IcsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The confirmation email leaves through Nextcloud's mailer, and send() says
 * true only when it did (issue #1680).
 */
final class ConfirmationMailerTest extends TestCase {
	/**
	 * Nextcloud's mailer, as the mailer under test sees it.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * The message the mailer handed out.
	 *
	 * @var IMessage&MockObject
	 */
	private IMessage $message;

	/**
	 * What the message was given: to, subject, body, attachments.
	 *
	 * @var array<string,mixed>
	 */
	private array $sent = ['attachments' => []];

	/**
	 * Wire a mailer double that records what it is given.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->message = $this->createMock(IMessage::class);
		$this->message->method('setTo')->willReturnCallback(
			function (array $to): IMessage {
				$this->sent['to'] = $to;
				return $this->message;
			}
		);
		$this->message->method('setSubject')->willReturnCallback(
			function (string $subject): IMessage {
				$this->sent['subject'] = $subject;
				return $this->message;
			}
		);
		$this->message->method('setPlainBody')->willReturnCallback(
			function (string $body): IMessage {
				$this->sent['body'] = $body;
				return $this->message;
			}
		);
		$this->message->method('attach')->willReturnCallback(
			function (IAttachment $attachment): IMessage {
				$this->sent['attached'][] = $attachment;
				return $this->message;
			}
		);

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturn($this->message);
		$this->mailer->method('createAttachment')->willReturnCallback(
			function ($data = null, $filename = null, $contentType = null): IAttachment {
				$this->sent['attachments'][] = ['content' => $data, 'filename' => $filename, 'contentType' => $contentType];
				return $this->createMock(IAttachment::class);
			}
		);
	}//end setUp()

	/**
	 * Build the mailer from its own constructor, the way the DI container does.
	 *
	 * Every dependency is resolved by type, so the test does not pin the
	 * constructor. integriq's real CallService has call() and callAsync() but
	 * no send(), so a container lookup finds nothing that sends mail.
	 *
	 * @return ConfirmationMailer
	 */
	private function makeMailer(): ConfirmationMailer {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.nl/index.php/apps/shillinq/confirm');

		// IcsService is final: a real one over mocked dependencies.
		$ics = new IcsService(
			new TimezoneResolver($this->createMock(IConfig::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('integriq CallService has no send()'));

		$byType = [
			IMailer::class => $this->mailer,
			IURLGenerator::class => $url,
			IcsService::class => $ics,
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
			ContainerInterface::class => $container,
		];

		$arguments = [];
		$constructor = (new ReflectionClass(ConfirmationMailer::class))->getConstructor();
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			self::assertArrayHasKey($type->getName(), $byType, 'No test double for ' . $type->getName());
			$arguments[$parameter->getName()] = $byType[$type->getName()];
		}

		return new ConfirmationMailer(...$arguments);
	}//end makeMailer()

	/**
	 * A demo appointment fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function appointment(): array {
		return [
			'appointmentNumber' => 'APT-1',
			'serviceName' => 'Consult',
			'location' => 'Stadskantoor',
			'customerEmail' => 'jan@example.nl',
			'customerTimezone' => 'Europe/Amsterdam',
			'startTime' => '2026-05-22T12:30:00Z',
			'endTime' => '2026-05-22T13:00:00Z',
		];
	}//end appointment()

	/**
	 * An appointment without an email is skipped and nothing is mailed.
	 *
	 * @return void
	 */
	public function testSendSkipsWhenNoEmail(): void {
		$this->mailer->expects($this->never())->method('send');
		$appointment = $this->appointment();
		unset($appointment['customerEmail']);

		self::assertFalse($this->makeMailer()->send(appointment: $appointment, rawToken: 'tok'));
	}//end testSendSkipsWhenNoEmail()

	/**
	 * The confirmation leaves through IMailer with the details, the link, the
	 * time zone and the ICS attachment, and send() says true.
	 *
	 * @return void
	 */
	public function testTheConfirmationIsMailedThroughNextcloudsMailerWithTheIcsAttached(): void {
		$this->mailer->expects($this->once())->method('send')->with($this->message)->willReturn([]);

		$result = $this->makeMailer()->send(appointment: $this->appointment(), rawToken: 'rawtok123');

		self::assertTrue($result);
		self::assertSame(['jan@example.nl'], $this->sent['to']);
		self::assertStringContainsString('Consult', $this->sent['subject']);
		self::assertStringContainsString('token=rawtok123', $this->sent['body']);
		self::assertStringContainsString('Europe/Amsterdam', $this->sent['body']);
		self::assertStringContainsString('Stadskantoor', $this->sent['body']);
		self::assertCount(1, $this->sent['attachments']);
		self::assertSame('appointment.ics', $this->sent['attachments'][0]['filename']);
		self::assertSame('text/calendar; charset=utf-8', $this->sent['attachments'][0]['contentType']);
		self::assertStringContainsString('BEGIN:VCALENDAR', $this->sent['attachments'][0]['content']);
		self::assertCount(1, $this->sent['attached']);
	}//end testTheConfirmationIsMailedThroughNextcloudsMailerWithTheIcsAttached()

	/**
	 * A mailer that throws means nothing was sent: send() says false.
	 *
	 * @return void
	 */
	public function testSendReturnsFalseWhenTheMailerFails(): void {
		$this->mailer->expects($this->once())->method('send')->willThrowException(new \RuntimeException('SMTP down'));

		self::assertFalse($this->makeMailer()->send(appointment: $this->appointment(), rawToken: 'tok'));
	}//end testSendReturnsFalseWhenTheMailerFails()

	/**
	 * A recipient the mail server refused was not mailed: send() says false.
	 *
	 * @return void
	 */
	public function testSendReturnsFalseWhenTheRecipientIsRefused(): void {
		$this->mailer->expects($this->once())->method('send')->willReturn(['jan@example.nl']);

		self::assertFalse($this->makeMailer()->send(appointment: $this->appointment(), rawToken: 'tok'));
	}//end testSendReturnsFalseWhenTheRecipientIsRefused()
}//end class
