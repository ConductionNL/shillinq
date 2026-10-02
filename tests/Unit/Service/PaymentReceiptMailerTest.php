<?php

/**
 * Unit tests for PaymentReceiptMailer.
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
 * @spec openspec/changes/payment-request-debtor-receipt/specs/object-payment-requests/spec.md (REQ-SOPR-012)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PaymentReceiptMailer;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The debtor's receipt for a captured object payment request.
 */
class PaymentReceiptMailerTest extends TestCase {
	/**
	 * The mailer double.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * The message double.
	 *
	 * @var IMessage&MockObject
	 */
	private IMessage $message;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->message = $this->createMock(IMessage::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturn($this->message);
		$this->mailer->method('validateMailAddress')->willReturnCallback(
			static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The receipt mailer under test, with an l10n that only substitutes.
	 *
	 * @return PaymentReceiptMailer The mailer.
	 */
	private function receiptMailer(): PaymentReceiptMailer {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new PaymentReceiptMailer(mailer: $this->mailer, l10n: $l10n, logger: $this->logger);
	}//end receiptMailer()

	/**
	 * A captured object request with a debtor email.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function captured(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pr-1',
				'subjectKind' => 'object',
				'state' => 'captured',
				'requestType' => 'other',
				'amount' => 45.0,
				'currency' => 'EUR',
				'description' => 'WC26-0042 Winter Camp 2026',
				'capturedAt' => '2026-10-02T12:00:00Z',
				'settlementReference' => 'st_123',
				'debtor' => ['name' => 'J. de Vries', 'email' => 'j.devries@example.nl'],
			],
			$overrides
		);
	}//end captured()

	/**
	 * The debtor gets one mail naming what was paid, how much, when and the
	 * reference (REQ-SOPR-012).
	 *
	 * @return void
	 */
	public function testTheDebtorReceivesTheReceipt(): void {
		$body = '';
		$this->message->expects(self::once())->method('setTo')->with(['j.devries@example.nl' => 'J. de Vries']);
		$this->message->expects(self::once())->method('setSubject')->with(self::stringContains('Payment received'));
		$this->message->expects(self::once())->method('setPlainBody')->willReturnCallback(
			function (string $text) use (&$body): IMessage {
				$body = $text;
				return $this->message;
			}
		);
		$this->mailer->expects(self::once())->method('send')->with($this->message)->willReturn([]);

		self::assertTrue($this->receiptMailer()->send(request: $this->captured()));
		self::assertStringContainsString('WC26-0042 Winter Camp 2026', $body);
		self::assertStringContainsString('EUR 45,00', $body);
		self::assertStringContainsString('2026-10-02', $body);
		self::assertStringContainsString('st_123', $body);
	}//end testTheDebtorReceivesTheReceipt()

	/**
	 * No debtor email, an address the mailer refuses, or a request that is not
	 * captured: no mail (REQ-SOPR-012).
	 *
	 * @return void
	 */
	public function testNothingIsSentWithoutACapturedRequestAndAnAddress(): void {
		$this->mailer->expects(self::never())->method('send');
		$mailer = $this->receiptMailer();

		self::assertFalse($mailer->send(request: $this->captured(['debtor' => ['name' => 'J. de Vries']])));
		self::assertFalse($mailer->send(request: $this->captured(['debtor' => ['email' => 'not an address']])));
		self::assertFalse($mailer->send(request: $this->captured(['state' => 'captured_unapplied'])));
		self::assertFalse($mailer->send(request: $this->captured(['debtor' => 'j.devries@example.nl'])));
	}//end testNothingIsSentWithoutACapturedRequestAndAnAddress()

	/**
	 * A mail server failure is logged and answered false, never thrown: the
	 * payment is already booked and must stay booked (REQ-SOPR-012).
	 *
	 * @return void
	 */
	public function testAMailFailureIsLoggedNotThrown(): void {
		$this->mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects(self::once())->method('warning');

		self::assertFalse($this->receiptMailer()->send(request: $this->captured()));
	}//end testAMailFailureIsLoggedNotThrown()
}//end class
