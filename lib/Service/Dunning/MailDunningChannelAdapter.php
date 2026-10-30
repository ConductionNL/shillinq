<?php

/**
 * Sends an email dunning stage through Nextcloud's mailer, with the invoice
 * attached, and hands every other channel to a person.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
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

namespace OCA\Shillinq\Service\Dunning;

use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The channel adapter the app binds (REQ-RAD-003, REQ-RAD-006, design D6).
 *
 * EMAIL: the rendered subject and body go to the customer's invoice address
 * with the invoice PDF attached. DELIVERED only when the mail server accepted
 * the message; FAILED with the reason otherwise, so the next run tries again.
 * No email address: MANUAL. Registered post and the collection agency are
 * MANUAL: a person sends them. Email plus registered post sends the mail and
 * leaves the letter MANUAL. No channel but a mail ever reads DELIVERED.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.2
 */
final class MailDunningChannelAdapter implements DunningChannelAdapterInterface {

	/**
	 * The channel that is only a mail.
	 *
	 * @var string
	 */
	private const CHANNEL_EMAIL = 'EMAIL';

	/**
	 * The channel that is a mail plus a registered letter.
	 *
	 * @var string
	 */
	private const CHANNEL_EMAIL_AND_POST = 'eMAILPostRegistration';

	/**
	 * Construct the adapter.
	 *
	 * @param IMailer             $mailer       Nextcloud's mailer.
	 * @param InvoicePdfGenerator $pdfGenerator The invoice PDF.
	 * @param ArInvoiceUblMapper  $ublMapper    The UBL the PDF embeds.
	 * @param LoggerInterface     $logger       Logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly InvoicePdfGenerator $pdfGenerator,
		private readonly ArInvoiceUblMapper $ublMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one stage through its channel.
	 *
	 * @param string              $channel The stage's channel.
	 * @param array<string,mixed> $payload subject, body, recipientEmail,
	 *                                     recipientName, invoice and the run context.
	 *
	 * @return DunningChannelSendResult The outcome.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.2
	 */
	public function send(string $channel, array $payload): DunningChannelSendResult {
		if ($channel !== self::CHANNEL_EMAIL && $channel !== self::CHANNEL_EMAIL_AND_POST) {
			return new DunningChannelSendResult(
				channel: $channel,
				deliveryStatus: 'MANUAL',
				errorMessage: 'This stage goes by ' . $channel . ': a person sends it.'
			);
		}

		$mail = $this->sendMail(payload: $payload);
		if ($channel === self::CHANNEL_EMAIL || $mail['status'] === 'FAILED') {
			return new DunningChannelSendResult(channel: $channel, deliveryStatus: $mail['status'], errorMessage: $mail['error']);
		}

		return new DunningChannelSendResult(
			channel: $channel,
			deliveryStatus: 'MANUAL',
			extras: ['mailSent' => $mail['status'] === 'DELIVERED'],
			errorMessage: 'The registered letter of this stage is sent by a person.'
		);
	}//end send()

	/**
	 * Send the mail of a stage.
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return array{status: string, error: string|null} DELIVERED, FAILED or MANUAL.
	 */
	private function sendMail(array $payload): array {
		$email = trim((string)($payload['recipientEmail'] ?? ''));
		if ($email === '') {
			return ['status' => 'MANUAL', 'error' => 'The customer has no email address: send this reminder by hand.'];
		}

		$attachment = $this->invoicePdf(invoice: $payload['invoice'] ?? null);
		if ($attachment === null) {
			return ['status' => 'FAILED', 'error' => 'The invoice PDF could not be made, so the reminder was not sent.'];
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email => (string)($payload['recipientName'] ?? $email)]);
			$message->setSubject((string)($payload['subject'] ?? ''));
			$message->setPlainBody((string)($payload['body'] ?? ''));
			$message->attach($this->mailer->createAttachment($attachment['pdf'], $attachment['filename'], 'application/pdf'));
			$failed = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: dunning mail not sent', ['invoiceId' => ($payload['invoiceId'] ?? null), 'exception' => $e->getMessage()]);
			return ['status' => 'FAILED', 'error' => 'The mail could not be sent: ' . $e->getMessage()];
		}

		if ($failed !== []) {
			return ['status' => 'FAILED', 'error' => 'The mail server refused the recipient.'];
		}

		return ['status' => 'DELIVERED', 'error' => null];
	}//end sendMail()

	/**
	 * The invoice PDF, with its UBL embedded, as the customer received it.
	 *
	 * An overdue invoice is an issued invoice past its due date; the UBL
	 * mapper renders issued invoices only (for Peppol), so the copy is made
	 * from the invoice as issued.
	 *
	 * @param mixed $invoice The ARInvoice.
	 *
	 * @return array{filename: string, pdf: string}|null The PDF, or null when it cannot be made.
	 */
	private function invoicePdf(mixed $invoice): ?array {
		if (is_array($invoice) === false || (string)($invoice['invoiceNumber'] ?? '') === '') {
			return null;
		}

		$asIssued = $invoice;
		if (($asIssued['lifecycleState'] ?? '') === 'overdue') {
			$asIssued['lifecycleState'] = ArInvoiceUblMapper::REQUIRED_LIFECYCLE_STATE;
		}

		try {
			$hybrid = $this->pdfGenerator->generateHybridPdf(
				invoice: $asIssued,
				lines: (array)($asIssued['invoiceLines'] ?? []),
				ublXml: $this->ublMapper->toNlciusXml(arInvoice: $asIssued)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: dunning invoice PDF not made', ['invoiceNumber' => $invoice['invoiceNumber'], 'exception' => $e->getMessage()]);
			return null;
		}

		return ['filename' => $hybrid['filename'], 'pdf' => $hybrid['pdf']];
	}//end invoicePdf()
}//end class
