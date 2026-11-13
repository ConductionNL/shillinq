<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use OCA\Shillinq\PaymentPlan\PaymentPlanMailer;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sending a payment-plan mail: nothing without an address, false on any failure.
 */
#[CoversClass(PaymentPlanMailer::class)]
final class PaymentPlanMailerSendTest extends TestCase {

	/** @var IMailer&MockObject */
	private IMailer $mailer;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->mailer = $this->createMock(IMailer::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function send(string $recipient): bool {
		return (new PaymentPlanMailer($this->mailer, $this->createMock(IL10N::class), $this->logger))
			->send($recipient, ['subject' => 'Uw betalingsregeling', 'body' => 'Termijn 1: 403,33']);
	}

	public function testABlankAddressSendsNothing(): void {
		$this->mailer->expects($this->never())->method('createMessage');
		$this->logger->expects($this->once())->method('warning')
			->with('PaymentPlanMailer: the customer has no email address, nothing sent');

		$this->assertFalse($this->send('   '));
	}

	public function testTheMessageCarriesTheAddressSubjectAndBody(): void {
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setTo')->with(['klant@example.nl'])->willReturnSelf();
		$message->expects($this->once())->method('setSubject')->with('Uw betalingsregeling')->willReturnSelf();
		$message->expects($this->once())->method('setPlainBody')->with('Termijn 1: 403,33')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->with($message)->willReturn([]);

		$this->assertTrue($this->send('klant@example.nl'));
	}

	public function testARejectedRecipientIsNotSent(): void {
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('setPlainBody')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn(['klant@example.nl']);

		$this->assertFalse($this->send('klant@example.nl'));
	}

	public function testAMailerFailureIsLoggedAndNotSent(): void {
		$this->mailer->method('createMessage')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects($this->once())->method('error')
			->with('PaymentPlanMailer: mail could not be sent', ['exception' => 'smtp down']);

		$this->assertFalse($this->send('klant@example.nl'));
	}
}
