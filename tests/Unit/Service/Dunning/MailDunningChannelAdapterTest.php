<?php

/**
 * Unit tests for MailDunningChannelAdapter: a reminder goes out as a mail with
 * the invoice attached, and the run records what the mail server answered.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\DunningStageDispatcher;
use OCA\Shillinq\Service\Dunning\MailDunningChannelAdapter;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * MailDunningChannelAdapter unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MailDunningChannelAdapterTest extends TestCase {

	/**
	 * What the message double was given.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = [];

	/**
	 * The mailer double.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * Build a mailer double that records the message.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sent = ['attachments' => []];
		$message    = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(
			function (array $to) use ($message): IMessage {
				$this->sent['to'] = $to;
				return $message;
			}
		);
		$message->method('setSubject')->willReturnCallback(
			function (string $subject) use ($message): IMessage {
				$this->sent['subject'] = $subject;
				return $message;
			}
		);
		$message->method('setPlainBody')->willReturnCallback(
			function (string $body) use ($message): IMessage {
				$this->sent['body'] = $body;
				return $message;
			}
		);
		$message->method('attach')->willReturn($message);

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('createAttachment')->willReturnCallback(
			function ($data = null, $filename = null, $contentType = null): IAttachment {
				$this->sent['attachments'][] = ['content' => $data, 'filename' => $filename, 'contentType' => $contentType];
				return $this->createMock(IAttachment::class);
			}
		);
	}//end setUp()

	/**
	 * The adapter on the real PDF generator and UBL mapper.
	 *
	 * @return MailDunningChannelAdapter
	 */
	private function adapter(): MailDunningChannelAdapter {
		return new MailDunningChannelAdapter(
			mailer: $this->mailer,
			pdfGenerator: new InvoicePdfGenerator(),
			ublMapper: new ArInvoiceUblMapper(),
			logger: new NullLogger()
		);
	}//end adapter()

	/**
	 * An overdue invoice, the state the job leaves an invoice in before it
	 * chases it (design D2).
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(): array {
		return [
			'id' => 'inv-0412',
			'invoiceNumber' => '2026-0412',
			'invoiceDate' => '2026-02-10',
			'dueDate' => '2026-03-12',
			'lifecycleState' => 'overdue',
			'currency' => 'EUR',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'sellerName' => 'Adviesbureau Kade B.V.',
			'buyerName' => 'Bakkerij De Korenaar B.V.',
			'invoiceLines' => [['itemName' => 'Advies maart', 'quantity' => 1, 'unitPrice' => 1000.0, 'lineNetAmount' => 1000.0, 'vatRate' => 21]],
		];
	}//end invoice()

	/**
	 * The payload DunningStageDispatcher hands an adapter for an email stage.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function payload(array $extra = []): array {
		return array_merge(
			[
				'invoiceId' => 'inv-0412',
				'stageNr' => 3,
				'recipientEmail' => 'administratie@korenaar.nl',
				'recipientName' => 'Bakkerij De Korenaar B.V.',
				'subject' => 'Aanmaning: betaal binnen 14 dagen om incassokosten te voorkomen',
				'body' => "Geachte Bakkerij De Korenaar B.V.,\n\nFactuur 2026-0412 ...",
				'invoice' => self::invoice(),
			],
			$extra
		);
	}//end payload()

	/**
	 * REQ-RAD-003: the mail server accepts the reminder, the run reads DELIVERED,
	 * and the customer gets the subject, the body and the invoice PDF.
	 *
	 * @return void
	 */
	public function testAnAcceptedMailIsDeliveredWithTheInvoiceAttached(): void {
		$this->mailer->expects(self::once())->method('send')->willReturn([]);

		$result = $this->adapter()->send(channel: 'EMAIL', payload: self::payload());

		self::assertSame('DELIVERED', $result->deliveryStatus);
		self::assertNull($result->errorMessage);
		self::assertSame(['administratie@korenaar.nl' => 'Bakkerij De Korenaar B.V.'], $this->sent['to']);
		self::assertSame('Aanmaning: betaal binnen 14 dagen om incassokosten te voorkomen', $this->sent['subject']);
		self::assertStringStartsWith('Geachte Bakkerij', $this->sent['body']);
		self::assertCount(1, $this->sent['attachments']);
		self::assertStringStartsWith('%PDF', (string)$this->sent['attachments'][0]['content']);
		self::assertSame('application/pdf', $this->sent['attachments'][0]['contentType']);
		self::assertStringContainsString('2026-0412', (string)$this->sent['attachments'][0]['filename']);
	}//end testAnAcceptedMailIsDeliveredWithTheInvoiceAttached()

	/**
	 * A mail server that refuses the recipient gives FAILED with the reason,
	 * so the next run tries the stage again.
	 *
	 * @return void
	 */
	public function testARefusedMailFails(): void {
		$this->mailer->method('send')->willReturn(['administratie@korenaar.nl']);

		$result = $this->adapter()->send(channel: 'EMAIL', payload: self::payload());

		self::assertSame('FAILED', $result->deliveryStatus);
		self::assertStringContainsString('refused', (string)$result->errorMessage);
	}//end testARefusedMailFails()

	/**
	 * A mailer that throws (no connection) gives FAILED, never DELIVERED.
	 *
	 * @return void
	 */
	public function testAMailerErrorFails(): void {
		$this->mailer->method('send')->willThrowException(new RuntimeException('Connection refused'));

		$result = $this->adapter()->send(channel: 'EMAIL', payload: self::payload());

		self::assertSame('FAILED', $result->deliveryStatus);
		self::assertStringContainsString('Connection refused', (string)$result->errorMessage);
	}//end testAMailerErrorFails()

	/**
	 * A customer without an email address is handed to a person: MANUAL with
	 * the reason, and no mail is attempted.
	 *
	 * @return void
	 */
	public function testNoEmailAddressIsManual(): void {
		$this->mailer->expects(self::never())->method('send');

		$result = $this->adapter()->send(channel: 'EMAIL', payload: self::payload(['recipientEmail' => '']));

		self::assertSame('MANUAL', $result->deliveryStatus);
		self::assertStringContainsString('email address', (string)$result->errorMessage);
	}//end testNoEmailAddressIsManual()

	/**
	 * An invoice the PDF cannot be made for is not sent bare: FAILED.
	 *
	 * @return void
	 */
	public function testAnInvoiceThePdfCannotBeMadeForIsNotSent(): void {
		$this->mailer->expects(self::never())->method('send');

		$invoice = self::invoice();
		$invoice['lifecycleState'] = 'draft';
		$result = $this->adapter()->send(channel: 'EMAIL', payload: self::payload(['invoice' => $invoice]));

		self::assertSame('FAILED', $result->deliveryStatus);
		self::assertStringContainsString('invoice PDF', (string)$result->errorMessage);
	}//end testAnInvoiceThePdfCannotBeMadeForIsNotSent()

	/**
	 * No invoice in the payload: nothing to attach, so nothing is sent.
	 *
	 * @return void
	 */
	public function testNoInvoiceMeansNoMail(): void {
		$this->mailer->expects(self::never())->method('send');

		$payload = self::payload();
		unset($payload['invoice']);
		$result = $this->adapter()->send(channel: 'EMAIL', payload: $payload);

		self::assertSame('FAILED', $result->deliveryStatus);
		self::assertStringContainsString('invoice', (string)$result->errorMessage);
	}//end testNoInvoiceMeansNoMail()

	/**
	 * REQ-RAD-006: postal and collection-agency stages are never sent by this
	 * adapter and never read DELIVERED. Email plus registered post sends the
	 * mail and leaves the letter to a person.
	 *
	 * @return void
	 */
	public function testNonMailChannelsAreHandedToAPerson(): void {
		$this->mailer->expects(self::once())->method('send')->willReturn([]);

		foreach (['REGISTERED_POST', 'COLLECTION_AGENCY_API'] as $channel) {
			$result = $this->adapter()->send(channel: $channel, payload: self::payload());
			self::assertSame('MANUAL', $result->deliveryStatus, $channel);
		}

		$result = $this->adapter()->send(channel: 'eMAILPostRegistration', payload: self::payload());
		self::assertSame('MANUAL', $result->deliveryStatus);
		self::assertTrue($result->extras['mailSent'] ?? false);
	}//end testNonMailChannelsAreHandedToAPerson()

	/**
	 * Through the real dispatcher, a delivered and a manual run are valid
	 * DunningRun records: the register declares MANUAL.
	 *
	 * @return void
	 */
	public function testTheDispatchedRunsAreValidDunningRuns(): void {
		$this->mailer->method('send')->willReturn([]);
		$dispatcher = new DunningStageDispatcher(adapter: $this->adapter(), logger: new NullLogger());
		$base       = [
			'administrationId' => 'adm-1',
			'invoiceId' => 'inv-0412',
			'ladderId' => 'ladder-zzp-default-2026',
			'stageNr' => 1,
			'templateId' => 'tpl-dunning-stage1-nl',
			'invoiceAmount' => 1210.0,
			'executedOn' => '2026-03-19T09:00:00+00:00',
			'renderedSubject' => 'Vriendelijke herinnering: factuur 2026-0412',
			'renderedBody' => 'Beste klant',
			'recipientEmail' => 'administratie@korenaar.nl',
		];

		foreach (['EMAIL' => 'DELIVERED', 'REGISTERED_POST' => 'MANUAL'] as $channel => $status) {
			$run = $dispatcher->dispatch(record: $base + ['channel' => $channel], invoice: self::invoice());
			self::assertSame($status, $run['deliveryStatus']);
			self::assertArrayNotHasKey('invoice', $run);
			self::assertSame([], RegisterSchema::errors('DunningRun', $run), $channel);
		}
	}//end testTheDispatchedRunsAreValidDunningRuns()

	/**
	 * The adapter is the one the app binds for the interface.
	 *
	 * @return void
	 */
	public function testTheAppBindsTheMailAdapter(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/AppInfo/Application.php');
		self::assertMatchesRegularExpression(
			'/registerServiceAlias\(\s*DunningChannelAdapterInterface::class,\s*MailDunningChannelAdapter::class\s*\)/',
			$source
		);
	}//end testTheAppBindsTheMailAdapter()
}//end class
