<?php

/**
 * Unit tests for ObjectRequestReceiptMailer.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ObjectRequestReceiptMailer;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins who gets a receipt, the exact message and the failure log.
 */
final class ObjectRequestReceiptMailerTest extends TestCase {

	/**
	 * Recorded mailer, translation and log calls.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the mailer.
	 *
	 * @param boolean $sendFails Whether IMailer::send throws.
	 *
	 * @return ObjectRequestReceiptMailer
	 */
	private function mailer(bool $sendFails = false): ObjectRequestReceiptMailer {
		$events = &$this->events;
		$message = $this->createMock(IMessage::class);
		foreach (['setTo', 'setSubject', 'setPlainBody'] as $setter) {
			$message->method($setter)->willReturnCallback(
				function (mixed $value) use ($setter, &$events, $message): IMessage {
					$events[] = [$setter, $value];
					return $message;
				}
			);
		}
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(
			function (string $email) use (&$events): bool {
				$events[] = ['validate', $email];
				return str_contains($email, '@');
			}
		);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturnCallback(
			function () use (&$events, $sendFails): array {
				$events[] = ['send'];
				if ($sendFails === true) {
					throw new \RuntimeException('smtp down');
				}
				return [];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			function (string $text, array $parameters = []) use (&$events): string {
				$events[] = ['t', $text];
				return '[' . vsprintf($text, $parameters) . ']';
			}
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('findGenericLanguage')->willReturn('nl');
		$factory->method('get')->willReturnCallback(
			function (string $app, string $lang) use (&$events, $l10n): IL10N {
				$events[] = ['l10n', $app, $lang];
				return $l10n;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$events): void {
				$events[] = ['warning', $message, $context];
			}
		);

		return new ObjectRequestReceiptMailer($mailer, $factory, $logger);

	}//end mailer()

	/**
	 * Pin who is mailed, the exact message, the translation order and the failure path.
	 *
	 * @return void
	 */
	public function testReceiptsAreExact(): void {
		$request = [
			'id' => 'pr-1', 'subjectKind' => 'object', 'settledAt' => '2026-03-04T10:00:00Z', 'debtor' => ['email' => ' jan@example.org ', 'name' => 'Jan'],
			'description' => 'Leges', 'currency' => 'EUR', 'amount' => 1234.5, 'paymentReference' => 'REF-9', 'confirmationSummary' => 'Paid by iDEAL',
		];
		$refusals = [
			['subjectKind' => 'invoice'] + $request,
			['settledAt' => ''] + $request,
			['debtor' => 'jan'] + $request,
			['debtor' => ['email' => '  ']] + $request,
			['debtor' => ['email' => 'not-an-address']] + $request,
		];
		foreach ($refusals as $index => $refused) {
			$this->events = [];
			self::assertFalse($this->mailer()->send($refused), 'refusal ' . $index);
			self::assertSame([], array_filter($this->events, static fn (array $e): bool => $e[0] !== 'validate'), 'refusal ' . $index);
		}

		$this->events = [];
		self::assertTrue($this->mailer()->send($request));
		$body = "[We received your payment.]\n\nLeges\n[Amount: EUR 1.234,50]\n[Paid on: 2026-03-04]\n[Reference: REF-9]\nPaid by iDEAL\n\n[Keep this mail as your receipt.]\n";
		self::assertSame(
			[
				['validate', 'jan@example.org'],
				['l10n', 'shillinq', 'nl'],
				['setTo', ['jan@example.org' => 'Jan']],
				['t', 'Payment received'],
				['setSubject', '[Payment received]'],
				['t', 'We received your payment.'],
				['t', 'Amount: %s'],
				['t', 'Paid on: %s'],
				['t', 'Reference: %s'],
				['t', 'Keep this mail as your receipt.'],
				['setPlainBody', $body],
				['send'],
			],
			$this->events
		);

		$this->events = [];
		$sparse = ['debtor' => ['email' => 'a@b.c'], 'description' => '', 'paymentReference' => '', 'confirmationSummary' => '', 'amount' => '7'] + $request;
		unset($sparse['currency']);
		self::assertFalse($this->mailer(true)->send($sparse));
		self::assertSame(['setTo', ['a@b.c' => 'a@b.c']], $this->events[2]);
		self::assertSame(['setPlainBody', "[We received your payment.]\n\n[Your payment]\n[Amount: EUR 7,00]\n[Paid on: 2026-03-04]\n\n[Keep this mail as your receipt.]\n"], $this->events[10]);
		self::assertSame(['warning', 'Shillinq: could not mail the receipt for a settled payment request', ['request' => 'pr-1', 'exception' => 'smtp down']], $this->events[12]);

	}//end testReceiptsAreExact()
}//end class
