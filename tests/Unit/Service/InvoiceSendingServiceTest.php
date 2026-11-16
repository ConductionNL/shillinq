<?php

/**
 * Tests for InvoiceSendingService (sales-invoice-sending tasks 2.1, 2.2).
 *
 * The PDF generator, the UBL mapper and the e-invoice send are the real
 * classes over an in-memory store; only the mailer and the Peppol port are
 * doubles, the two places the send leaves the process.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use DomainException;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\EInvoice\EInvoiceValidationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\InvoiceSendingService;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The send by delivery method, and what it records on the invoice.
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-an-issued-invoice-is-sent-by-email-with-its-pdf-attached-req-sis-001
 */
class InvoiceSendingServiceTest extends TestCase {

	private IMailer&MockObject $mailer;

	/**
	 * What the mail carried: to, subject, attachment name and type.
	 *
	 * @var array<string, mixed>
	 */
	private array $mailed = [];

	/**
	 * The saves the store saw.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * What the mailer's send answers: the refused addresses, or a throwable.
	 *
	 * @var array<int, string>|RuntimeException
	 */
	private array|RuntimeException $mailAnswer = [];

	protected function setUp(): void {
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('validateMailAddress')->willReturnCallback(static fn (string $address): bool => str_contains($address, '@'));
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message) {
			$this->mailed['to'] = $to;
			return $message;
		});
		$message->method('setSubject')->willReturnCallback(function (string $subject) use ($message) {
			$this->mailed['subject'] = $subject;
			return $message;
		});
		$message->method('setPlainBody')->willReturnCallback(function (string $body) use ($message) {
			$this->mailed['body'] = $body;
			return $message;
		});
		$message->method('attach')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('createAttachment')->willReturnCallback(function (string $data, string $name, string $type) {
			$this->mailed['attachment'] = ['name' => $name, 'type' => $type, 'isPdf' => str_starts_with($data, '%PDF')];
			return $this->createMock(IAttachment::class);
		});
		$this->mailer->method('send')->willReturnCallback(function () {
			if ($this->mailAnswer instanceof RuntimeException) {
				throw $this->mailAnswer;
			}

			$this->mailed['sent'] = true;
			return $this->mailAnswer;
		});
	}//end setUp()

	public function testAnIssuedInvoiceIsMailedWithItsPdfToTheInvoiceAddressAndRecorded(): void {
		$service = $this->service(customer: ['deliveryMethod' => 'email', 'invoiceEmail' => 'facturen@dekorenaar.example', 'email' => 'info@dekorenaar.example']);

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame(InvoiceSendingService::STATUS_SENT, $outcome['status']);
		$this->assertSame(['facturen@dekorenaar.example' => 'Bakkerij De Korenaar B.V.'], $this->mailed['to']);
		$this->assertSame(['name' => 'Factuur-2026-0412.pdf', 'type' => 'application/pdf', 'isPdf' => true], $this->mailed['attachment']);
		$this->assertSame('Invoice 2026-0412', $this->mailed['subject']);
		$this->assertStringContainsString('EUR 1.210,00', $this->mailed['body']);
		$sending = $this->lastSaved()['sending'];
		$this->assertSame('email', $sending['sentVia']);
		$this->assertSame('facturen@dekorenaar.example', $sending['sentTo']);
		$this->assertSame('2026-10-01T08:30:00Z', $sending['sentAt']);
		$this->assertSame(1, $sending['attempts']);
		$this->assertSame('', $sending['lastError']);
	}//end testAnIssuedInvoiceIsMailedWithItsPdfToTheInvoiceAddressAndRecorded()

	public function testWithoutAnInvoiceAddressTheCustomersEmailIsUsedAndAnOverdueInvoiceCanBeSent(): void {
		$service = $this->service(customer: ['email' => 'info@dekorenaar.example'], invoice: ['lifecycleState' => 'overdue']);

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame(InvoiceSendingService::STATUS_SENT, $outcome['status']);
		$this->assertSame(['info@dekorenaar.example'], array_keys($this->mailed['to']));
		$this->assertSame('overdue', $this->lastSaved()['lifecycleState'], 'the send does not touch the lifecycle');
	}//end testWithoutAnInvoiceAddressTheCustomersEmailIsUsedAndAnOverdueInvoiceCanBeSent()

	public function testADraftInvoiceIsRefusedAndNothingIsMailedOrSaved(): void {
		$service = $this->service(customer: ['email' => 'info@dekorenaar.example'], invoice: ['lifecycleState' => 'draft']);
		$this->mailer->expects($this->never())->method('send');

		try {
			$service->send(administrationId: 'adm-1', invoiceId: 'inv-1');
			$this->fail('a draft was sent');
		} catch (DomainException $e) {
			$this->assertSame('Only an issued invoice can be sent.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testADraftInvoiceIsRefusedAndNothingIsMailedOrSaved()

	public function testAnotherAdministrationsInvoiceIsNotFound(): void {
		$service = $this->service(customer: ['email' => 'a@b.example'], invoice: ['administrationId' => 'adm-2']);
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(OutOfBoundsException::class);
		$service->send(administrationId: 'adm-1', invoiceId: 'inv-1');
	}//end testAnotherAdministrationsInvoiceIsNotFound()

	public function testAnAdministrationTheUserMayNotActOnIsNotFound(): void {
		$service = $this->service(customer: ['email' => 'a@b.example'], accessible: []);
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(OutOfBoundsException::class);
		$service->send(administrationId: 'adm-1', invoiceId: 'inv-1');
	}//end testAnAdministrationTheUserMayNotActOnIsNotFound()

	public function testAMailFailureIsRecordedWithItsReasonAndNoSentAt(): void {
		$this->mailAnswer = new RuntimeException('Connection refused');
		$service = $this->service(customer: ['email' => 'info@dekorenaar.example']);

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame(InvoiceSendingService::STATUS_FAILED, $outcome['status']);
		$this->assertSame('The mail could not be sent: Connection refused', $outcome['error']);
		$sending = $this->lastSaved()['sending'];
		$this->assertArrayNotHasKey('sentAt', $sending);
		$this->assertSame('The mail could not be sent: Connection refused', $sending['lastError']);
		$this->assertSame(1, $sending['attempts']);
	}//end testAMailFailureIsRecordedWithItsReasonAndNoSentAt()

	public function testARefusedRecipientIsAFailureAndAnEarlierSendKeepsItsMoment(): void {
		$this->mailAnswer = ['info@dekorenaar.example'];
		$earlier = ['sentVia' => 'email', 'sentTo' => 'old@dekorenaar.example', 'sentAt' => '2026-09-01T10:00:00Z', 'attempts' => 1];
		$service = $this->service(customer: ['email' => 'info@dekorenaar.example'], invoice: ['sending' => $earlier]);

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame(InvoiceSendingService::STATUS_FAILED, $outcome['status']);
		$sending = $this->lastSaved()['sending'];
		$this->assertSame('2026-09-01T10:00:00Z', $sending['sentAt']);
		$this->assertSame(2, $sending['attempts']);
		$this->assertSame('The mail server refused the address info@dekorenaar.example.', $sending['lastError']);
	}//end testARefusedRecipientIsAFailureAndAnEarlierSendKeepsItsMoment()

	public function testACustomerWithoutAnEmailAddressFailsBeforeAnyMail(): void {
		$service = $this->service(customer: ['email' => '', 'invoiceEmail' => 'not-an-address']);
		$this->mailer->expects($this->never())->method('createMessage');

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame([InvoiceSendingService::STATUS_FAILED, 'The customer has no email address to send the invoice to.'], [$outcome['status'], $outcome['error']]);
	}//end testACustomerWithoutAnEmailAddressFailsBeforeAnyMail()

	/**
	 * REQ-SIS-002: a post customer is not mailed and the method is recorded.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.2
	 */
	public function testAPostCustomerIsNotMailedAndThePostIsRecorded(): void {
		$service = $this->service(customer: ['deliveryMethod' => 'post', 'email' => 'linde@example.org']);
		$this->mailer->expects($this->never())->method('send');

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame(InvoiceSendingService::STATUS_RECORDED, $outcome['status']);
		$sending = $this->lastSaved()['sending'];
		$this->assertSame(['post', null, 'The letter is printed and posted by hand.'], [$sending['sentVia'], $sending['sentAt'], $sending['note']]);
	}//end testAPostCustomerIsNotMailedAndThePostIsRecorded()

	/**
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.2
	 */
	public function testANoneCustomerIsNotMailedAndAnUnknownMethodReadsAsEmail(): void {
		$none = $this->service(customer: ['deliveryMethod' => 'none', 'email' => 'x@example.org']);
		$this->assertSame('none', $none->send(administrationId: 'adm-1', invoiceId: 'inv-1')['sending']['sentVia']);
		$this->assertArrayNotHasKey('sent', $this->mailed);

		$this->assertSame('email', $none->methodOf(customer: ['deliveryMethod' => 'carrier-pigeon']));
		$this->assertSame('email', $none->methodOf(customer: null));
	}//end testANoneCustomerIsNotMailedAndAnUnknownMethodReadsAsEmail()

	/**
	 * REQ-SIS-002: a Peppol customer without a participant is offered the mail,
	 * nothing is recorded, and choosing email mails it.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.2
	 */
	public function testAPeppolCustomerWithoutAParticipantIsOfferedTheMailAndEmailSendsIt(): void {
		$service = $this->service(customer: ['deliveryMethod' => 'peppol', 'email' => 'facturen@voorbeeld.example'], participant: null);

		$offered = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame(InvoiceSendingService::STATUS_FALLBACK, $offered['status']);
		$this->assertSame('No Peppol participant was found for this customer. Send the invoice by email instead.', $offered['error']);
		$this->assertSame([], $this->saved, 'an offer records nothing');
		$this->assertArrayNotHasKey('sent', $this->mailed);

		$mailed = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1', via: 'email');

		$this->assertSame(InvoiceSendingService::STATUS_SENT, $mailed['status']);
		$this->assertSame('email', $this->lastSaved()['sending']['sentVia']);
	}//end testAPeppolCustomerWithoutAParticipantIsOfferedTheMailAndEmailSendsIt()

	/**
	 * A send nobody watches mails at once on the Peppol fallback and says so.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.2
	 */
	public function testAnUnwatchedSendMailsOnThePeppolFallbackAndNotesIt(): void {
		$service = $this->service(customer: ['deliveryMethod' => 'peppol', 'email' => 'facturen@voorbeeld.example'], participant: null);

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1', mailOnFallback: true);

		$this->assertSame(InvoiceSendingService::STATUS_SENT, $outcome['status']);
		$sending = $this->lastSaved()['sending'];
		$this->assertSame('email', $sending['sentVia']);
		$this->assertSame('No Peppol participant was found for this customer, so the invoice was mailed.', $sending['note']);
	}//end testAnUnwatchedSendMailsOnThePeppolFallbackAndNotesIt()

	/**
	 * A Peppol customer with a participant goes through Peppol and is not mailed.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.2
	 */
	public function testAPeppolCustomerWithAParticipantIsSentThroughPeppol(): void {
		$service = $this->service(customer: ['deliveryMethod' => 'peppol', 'email' => 'x@example.org'], participant: '0190:00000000000000000000');
		$this->mailer->expects($this->never())->method('send');

		$outcome = $service->send(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertSame(InvoiceSendingService::STATUS_SENT, $outcome['status']);
		$this->assertSame('peppol', $outcome['sending']['sentVia']);
		$this->assertStringStartsWith('urn:uuid:', $outcome['sending']['sentTo']);
	}//end testAPeppolCustomerWithAParticipantIsSentThroughPeppol()

	/**
	 * A run nobody signed in to sends without a user check, finds the invoice
	 * by its number, and refuses an invoice of another administration.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-4.1
	 */
	public function testAnUnattendedSendNeedsNoUserAndFindsTheInvoiceByNumber(): void {
		$service = $this->service(customer: ['email' => 'info@dekorenaar.example'], accessible: []);

		$outcome = $service->sendUnattended(administrationId: 'adm-1', invoiceId: '2026-0412', via: 'email');

		$this->assertSame(InvoiceSendingService::STATUS_SENT, $outcome['status']);
		$this->assertSame('email', $this->lastSaved()['sending']['sentVia']);

		$this->expectException(OutOfBoundsException::class);
		$service->sendUnattended(administrationId: 'adm-2', invoiceId: '2026-0412');
	}//end testAnUnattendedSendNeedsNoUserAndFindsTheInvoiceByNumber()

	/**
	 * The service over the in-memory store, with one invoice and its customer.
	 *
	 * @param array<string, mixed> $customer    The customer's own fields.
	 * @param array<string, mixed> $invoice     Overrides of the invoice.
	 * @param array<int, string>   $accessible  The administrations the user may act on.
	 * @param string|null          $participant The Peppol participant the port finds.
	 *
	 * @return InvoiceSendingService
	 */
	private function service(array $customer, array $invoice = [], array $accessible = ['adm-1'], ?string $participant = null): InvoiceSendingService {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(
			data: [
				'ARInvoice' => [array_merge(self::invoice(), $invoice)],
				'CustomerMaster' => [array_merge(['id' => 'cus-1', 'customerId' => 'C-001', 'administrationId' => 'adm-1', 'legalName' => 'Bakkerij De Korenaar B.V.'], $customer)],
			],
			saveSink: $this->saved
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => in_array($id, $accessible, true));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T10:30:00+02:00'));
		$port = $this->port(participant: $participant);
		$vies = $this->createMock(ViesService::class);
		$vies->method('validate')->willReturn(['valid' => true, 'outage' => false]);

		return new InvoiceSendingService(
			objectService: $store,
			administrationContext: $context,
			appConfig: $appConfig,
			mailer: $this->mailer,
			pdfGenerator: new InvoicePdfGenerator(),
			ublMapper: new ArInvoiceUblMapper(),
			einvoice: new EInvoiceService(
				appConfig: $appConfig,
				administrationContext: $context,
				logger: new NullLogger(),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				ublMapper: new ArInvoiceUblMapper(),
				pdfGenerator: new InvoicePdfGenerator(),
				validationService: new EInvoiceValidationService(vies: $vies, peppolPort: $port),
				objectService: $store,
				peppolPort: $port,
			),
			l10n: $l10n,
			time: $time,
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * A Peppol port that finds the given participant and accepts every submit.
	 *
	 * @param string|null $participant The participant, or null for none.
	 *
	 * @return PeppolTransmissionPortInterface
	 */
	private function port(?string $participant): PeppolTransmissionPortInterface {
		return new class($participant) implements PeppolTransmissionPortInterface {
			/**
			 * @param string|null $participant The participant.
			 */
			public function __construct(private readonly ?string $participant) {
			}

			/**
			 * @inheritDoc
			 */
			public function lookupParticipant(string $administrationId, string $partyId): ?string {
				unset($administrationId, $partyId);
				return $this->participant;
			}

			/**
			 * @inheritDoc
			 */
			public function submit(string $participantId, string $documentType, string $payloadFileUri): string {
				unset($documentType, $payloadFileUri);
				return 'urn:uuid:' . substr(hash('sha256', $participantId), 0, 32);
			}
		};
	}//end port()

	/**
	 * An issued invoice that passes the e-invoice validation.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(): array {
		return [
			'id' => 'inv-1',
			'invoiceNumber' => '2026-0412',
			'customerId' => 'cus-1',
			'administrationId' => 'adm-1',
			'invoiceDate' => '2026-10-01',
			'dueDate' => '2026-10-15',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'currency' => 'EUR',
			'lifecycleState' => 'issued',
			'deliveryStatus' => 'not-sent',
			'sellerName' => 'Adviesbureau Kade B.V.',
			'sellerVatId' => 'NL809876543B01',
			'buyerVatId' => 'NL001234567B01',
			'buyerLegalRegId' => '12340001',
		];
	}//end invoice()

	/**
	 * The invoice as last saved.
	 *
	 * @return array<string, mixed>
	 */
	private function lastSaved(): array {
		$this->assertNotSame([], $this->saved, 'the invoice was saved');
		return end($this->saved)['object'];
	}//end lastSaved()
}//end class
