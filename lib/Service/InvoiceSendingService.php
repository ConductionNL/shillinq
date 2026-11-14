<?php

/**
 * Shillinq Invoice Sending Service
 *
 * Sends an issued sales invoice to the customer the way the customer wants
 * it: by email with the PDF attached, through Peppol, or not at all because
 * it goes by post or is handed over another way. The outcome is written onto
 * the invoice's `sending` group only after the mailer or the Peppol send
 * answered.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use DateTimeZone;
use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One invoice, one send, routed by the customer's delivery method (design D3).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the send meets the PDF, the UBL, the mail, Peppol and the store.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    -- a watched and an unwatched send differ only in
 *                                                   whether a Peppol fallback mails without asking.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.1
 */
class InvoiceSendingService {

	/**
	 * The lifecycle states an invoice can be sent in.
	 */
	public const SENDABLE_STATES = ['issued', 'overdue'];

	/**
	 * The delivery methods a customer can have.
	 */
	public const METHODS = ['email', 'peppol', 'post', 'none'];

	/**
	 * The largest attachment the send puts on a mail (design, Risks).
	 */
	public const MAX_ATTACHMENT_BYTES = 10485760;

	/**
	 * The invoice was mailed or handed to Peppol.
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * Nothing was sent, by the customer's choice (post or none); the method is recorded.
	 */
	public const STATUS_RECORDED = 'recorded';

	/**
	 * The customer has no Peppol participant: the PDF can be mailed instead.
	 */
	public const STATUS_FALLBACK = 'fallback';

	/**
	 * The mail or the Peppol send failed; the error is recorded.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService         OpenRegister's object service.
	 * @param AdministrationContextService $administrationContext Whether the user may act on the administration.
	 * @param IAppConfig                   $appConfig             The register slug.
	 * @param IMailer                      $mailer                Nextcloud's mailer (design D1).
	 * @param InvoicePdfGenerator          $pdfGenerator          The PDF with the UBL embedded (design D2).
	 * @param ArInvoiceUblMapper           $ublMapper             The UBL the PDF embeds.
	 * @param EInvoiceService              $einvoice              The Peppol send.
	 * @param IL10N                        $l10n                  The mail's words.
	 * @param ITimeFactory                 $time                  The moment of the send.
	 * @param LoggerInterface              $logger                A failed send, without the mail body.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IAppConfig $appConfig,
		private readonly IMailer $mailer,
		private readonly InvoicePdfGenerator $pdfGenerator,
		private readonly ArInvoiceUblMapper $ublMapper,
		private readonly EInvoiceService $einvoice,
		private readonly IL10N $l10n,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one invoice.
	 *
	 * `$via` empty follows the customer's delivery method; `email` mails the
	 * PDF whatever the method is, which is how a user answers the Peppol
	 * fallback. `$mailOnFallback` mails at once when Peppol has no participant
	 * for the customer, for a send nobody is watching (a batch, a recurring
	 * run); a person is offered the choice instead.
	 *
	 * @param string $administrationId The administration the invoice belongs to.
	 * @param string $invoiceId        The ARInvoice id.
	 * @param string $via              `''` for the customer's method, or `email`.
	 * @param bool   $mailOnFallback   Whether a Peppol fallback mails without asking.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 *
	 * @throws OutOfBoundsException When the invoice is not found in an administration the user may act on.
	 * @throws DomainException      When the invoice is not issued or overdue.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.1
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-an-issued-invoice-is-sent-by-email-with-its-pdf-attached-req-sis-001
	 */
	public function send(string $administrationId, string $invoiceId, string $via = '', bool $mailOnFallback = false): array {
		if ($administrationId === '' || $this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			// Masked as not found (ADR-005): another administration's invoice does not exist for this user.
			throw new OutOfBoundsException('ARInvoice not found');
		}

		$invoice = $this->sendableInvoice(administrationId: $administrationId, invoiceId: $invoiceId);
		return $this->sendInvoice(invoice: $invoice, via: $via, mailOnFallback: $mailOnFallback);
	}//end send()

	/**
	 * Send one invoice for a run nobody is watching and no user signed in to,
	 * such as the recurring run (REQ-SIS-007): the caller already works inside
	 * the invoice's administration, so there is no user to check, and a Peppol
	 * fallback mails at once. The invoice is found by id, else by its number
	 * in that administration.
	 *
	 * @param string $administrationId The administration the invoice belongs to.
	 * @param string $invoiceId        The ARInvoice id, or its invoice number.
	 * @param string $via              `''` for the customer's method, or `email`.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 *
	 * @throws OutOfBoundsException When the invoice is not found in that administration.
	 * @throws DomainException      When the invoice is not issued or overdue.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-4.1
	 */
	public function sendUnattended(string $administrationId, string $invoiceId, string $via = ''): array {
		if ($administrationId === '') {
			throw new OutOfBoundsException('ARInvoice not found');
		}

		$invoice = $this->sendableInvoice(administrationId: $administrationId, invoiceId: $invoiceId);
		return $this->sendInvoice(invoice: $invoice, via: $via, mailOnFallback: true);
	}//end sendUnattended()

	/**
	 * Route a sendable invoice by its customer's delivery method and record the outcome.
	 *
	 * @param array<string, mixed> $invoice        The ARInvoice.
	 * @param string               $via            `''` or `email`.
	 * @param bool                 $mailOnFallback Whether a Peppol fallback mails without asking.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 */
	private function sendInvoice(array $invoice, string $via, bool $mailOnFallback): array {
		$customer = $this->customerOf(invoice: $invoice);

		$method = $this->methodOf(customer: $customer);
		if ($via === 'email') {
			$method = 'email';
		}

		$outcome = match ($method) {
			'peppol' => $this->viaPeppol(invoice: $invoice, customer: $customer, mailOnFallback: $mailOnFallback),
			'post' => $this->recorded(method: 'post', note: $this->l10n->t('The letter is printed and posted by hand.')),
			'none' => $this->recorded(method: 'none', note: ''),
			default => $this->viaEmail(invoice: $invoice, customer: $customer, note: ''),
		};

		if ($outcome['status'] !== self::STATUS_FALLBACK) {
			$outcome['sending'] = $this->record(invoice: $invoice, outcome: $outcome);
		}

		return $outcome;
	}//end sendInvoice()

	/**
	 * The delivery method of a customer: its own when it is one, else email.
	 *
	 * @param array<string, mixed>|null $customer The CustomerMaster, or null.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.2
	 */
	public function methodOf(?array $customer): string {
		$method = ($customer['deliveryMethod'] ?? 'email');
		if (is_string($method) === false || in_array($method, self::METHODS, true) === false) {
			return 'email';
		}

		return $method;
	}//end methodOf()

	/**
	 * The address an invoice mail goes to: the customer's invoice email, else its email.
	 *
	 * @param array<string, mixed>|null $customer The CustomerMaster, or null.
	 *
	 * @return string The address, or '' when the customer has none.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.1
	 */
	public function addressOf(?array $customer): string {
		foreach (['invoiceEmail', 'email'] as $key) {
			$address = trim((string)($customer[$key] ?? ''));
			if ($address !== '' && $this->mailer->validateMailAddress($address) === true) {
				return $address;
			}
		}

		return '';
	}//end addressOf()

	/**
	 * The invoice of this administration, when it can be sent.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The ARInvoice id, or its invoice number.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OutOfBoundsException When it is not found in the administration.
	 * @throws DomainException      When it is not issued or overdue.
	 */
	private function sendableInvoice(string $administrationId, string $invoiceId): array {
		$invoice = $this->find(schema: 'ARInvoice', id: $invoiceId, fallbackProperty: 'invoiceNumber');
		if ($invoice === null || (string)($invoice['administrationId'] ?? '') !== $administrationId) {
			throw new OutOfBoundsException('ARInvoice not found');
		}

		if (in_array((string)($invoice['lifecycleState'] ?? ''), self::SENDABLE_STATES, true) === false) {
			throw new DomainException('Only an issued invoice can be sent.');
		}

		return $invoice;
	}//end sendableInvoice()

	/**
	 * The invoice's customer in the same administration, or null.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice.
	 *
	 * @return array<string, mixed>|null
	 */
	private function customerOf(array $invoice): ?array {
		$customer = $this->find(schema: 'CustomerMaster', id: (string)($invoice['customerId'] ?? ''), fallbackProperty: 'customerId');
		if ($customer === null || (string)($customer['administrationId'] ?? '') !== (string)($invoice['administrationId'] ?? '')) {
			return null;
		}

		return $customer;
	}//end customerOf()

	/**
	 * Mail the PDF.
	 *
	 * @param array<string, mixed>      $invoice  The ARInvoice.
	 * @param array<string, mixed>|null $customer The CustomerMaster.
	 * @param string                    $note     What the record says besides the mail, e.g. that Peppol fell back.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 */
	private function viaEmail(array $invoice, ?array $customer, string $note): array {
		$address = $this->addressOf(customer: $customer);
		if ($address === '') {
			return $this->failed(method: 'email', error: $this->l10n->t('The customer has no email address to send the invoice to.'));
		}

		$attachment = $this->pdfOf(invoice: $invoice);
		if ($attachment === null) {
			return $this->failed(method: 'email', error: $this->l10n->t('The invoice PDF could not be made.'));
		}

		if (strlen($attachment['pdf']) > self::MAX_ATTACHMENT_BYTES) {
			return $this->failed(method: 'email', error: $this->l10n->t('The invoice PDF is larger than 10 MB and is not mailed.'));
		}

		$error = $this->mail(invoice: $invoice, customer: $customer, address: $address, attachment: $attachment);
		if ($error !== '') {
			return $this->failed(method: 'email', error: $error);
		}

		return [
			'status' => self::STATUS_SENT,
			'sending' => ['sentVia' => 'email', 'sentTo' => $address, 'sentAt' => $this->now(), 'note' => $note],
			'error' => '',
		];
	}//end viaEmail()

	/**
	 * Hand the invoice to the Peppol send; without a participant, offer the
	 * mail, or mail at once when nobody can be asked.
	 *
	 * @param array<string, mixed>      $invoice        The ARInvoice.
	 * @param array<string, mixed>|null $customer       The CustomerMaster.
	 * @param bool                      $mailOnFallback Whether to mail without asking.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.2
	 */
	private function viaPeppol(array $invoice, ?array $customer, bool $mailOnFallback): array {
		try {
			$answer = $this->einvoice->sendEInvoice(
				administrationId: (string)($invoice['administrationId'] ?? ''),
				invoiceNumber: (string)($invoice['invoiceNumber'] ?? '')
			);
		} catch (Throwable $e) {
			return $this->failed(method: 'peppol', error: $e->getMessage());
		}

		if (($answer['fallback'] ?? false) !== true) {
			return [
				'status' => self::STATUS_SENT,
				'sending' => ['sentVia' => 'peppol', 'sentTo' => (string)($answer['transmissionId'] ?? ''), 'sentAt' => $this->now(), 'note' => ''],
				'error' => '',
			];
		}

		$note = $this->l10n->t('No Peppol participant was found for this customer, so the invoice was mailed.');
		if ($mailOnFallback === true) {
			return $this->viaEmail(invoice: $invoice, customer: $customer, note: $note);
		}

		return [
			'status' => self::STATUS_FALLBACK,
			'sending' => [],
			'error' => $this->l10n->t('No Peppol participant was found for this customer. Send the invoice by email instead.'),
		];
	}//end viaPeppol()

	/**
	 * Mail the invoice with its PDF, and answer the error or ''.
	 *
	 * The transport stays in this one method so a later move to another
	 * channel is one edit (design D1).
	 *
	 * @param array<string, mixed>               $invoice    The ARInvoice.
	 * @param array<string, mixed>|null          $customer   The CustomerMaster.
	 * @param string                             $address    The address.
	 * @param array{filename: string, pdf: string} $attachment The PDF.
	 *
	 * @return string The error, or '' when the mailer accepted the message.
	 */
	private function mail(array $invoice, ?array $customer, string $address, array $attachment): string {
		$number = (string)($invoice['invoiceNumber'] ?? '');
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$address => (string)($customer['legalName'] ?? $address)]);
			$message->setSubject($this->l10n->t('Invoice %s', [$number]));
			$message->setPlainBody(
				$this->l10n->t(
					"Dear customer,\n\nAttached is invoice %1\$s of %2\$s %3\$s, due on %4\$s.\n\nKind regards",
					[
						$number,
						(string)($invoice['currency'] ?? 'EUR'),
						number_format((float)($invoice['grossAmount'] ?? 0), 2, ',', '.'),
						(string)($invoice['dueDate'] ?? ''),
					]
				)
			);
			$message->attach($this->mailer->createAttachment($attachment['pdf'], $attachment['filename'], 'application/pdf'));
			$refused = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: invoice mail not sent', ['invoiceNumber' => $number, 'exception' => $e->getMessage()]);
			return $this->l10n->t('The mail could not be sent: %s', [$e->getMessage()]);
		}

		if ($refused !== []) {
			return $this->l10n->t('The mail server refused the address %s.', [$address]);
		}

		return '';
	}//end mail()

	/**
	 * The invoice as a PDF named `Factuur-<invoiceNumber>.pdf`, with its UBL
	 * embedded (design D2), or null when it cannot be made.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice.
	 *
	 * @return array{filename: string, pdf: string}|null
	 */
	private function pdfOf(array $invoice): ?array {
		// The UBL mapper renders issued invoices only; an overdue invoice is an
		// issued invoice past its due date, so its copy is made as issued.
		$asIssued = $invoice;
		$asIssued['lifecycleState'] = ArInvoiceUblMapper::REQUIRED_LIFECYCLE_STATE;
		try {
			$hybrid = $this->pdfGenerator->generateHybridPdf(
				invoice: $asIssued,
				lines: (array)($asIssued['invoiceLines'] ?? []),
				ublXml: $this->ublMapper->toNlciusXml(arInvoice: $asIssued)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: invoice PDF not made', ['invoiceNumber' => ($invoice['invoiceNumber'] ?? ''), 'exception' => $e->getMessage()]);
			return null;
		}

		return ['filename' => 'Factuur-' . (string)($invoice['invoiceNumber'] ?? '') . '.pdf', 'pdf' => (string)$hybrid['pdf']];
	}//end pdfOf()

	/**
	 * Write the outcome onto the invoice's `sending` group: on success the
	 * method, the address and the moment; on failure one more attempt and the
	 * error, keeping an earlier successful send's moment.
	 *
	 * @param array<string, mixed>                                          $invoice The ARInvoice.
	 * @param array{status: string, sending: array<string, mixed>, error: string} $outcome The outcome.
	 *
	 * @return array<string, mixed> The `sending` group as stored.
	 */
	private function record(array $invoice, array $outcome): array {
		$sending = (array)($invoice['sending'] ?? []);
		$sending['attempts'] = ((int)($sending['attempts'] ?? 0) + 1);
		$sending['lastError'] = $outcome['error'];
		if ($outcome['status'] !== self::STATUS_FAILED) {
			$sending = array_merge($sending, $outcome['sending']);
			$sending['lastError'] = '';
		}

		$invoice['sending'] = $sending;
		try {
			$this->objectService
				->setRegister($this->register())
				->setSchema('ARInvoice')
				->saveObject($invoice);
		} catch (Throwable $e) {
			// The mail went out; the record of it did not. Say so in the log, the answer still tells the user.
			$this->logger->error(
				'Shillinq: invoice send not recorded',
				['invoiceNumber' => ($invoice['invoiceNumber'] ?? ''), 'exception' => $e->getMessage()]
			);
		}

		return $sending;
	}//end record()

	/**
	 * A send by post or none: nothing is mailed, the method is recorded.
	 *
	 * @param string $method `post` or `none`.
	 * @param string $note   What the user is told.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 */
	private function recorded(string $method, string $note): array {
		return [
			'status' => self::STATUS_RECORDED,
			'sending' => ['sentVia' => $method, 'sentTo' => '', 'sentAt' => null, 'note' => $note],
			'error' => '',
		];
	}//end recorded()

	/**
	 * A failed send.
	 *
	 * @param string $method The method tried.
	 * @param string $error  Why it failed.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 */
	private function failed(string $method, string $error): array {
		return ['status' => self::STATUS_FAILED, 'sending' => ['triedVia' => $method], 'error' => $error];
	}//end failed()

	/**
	 * One record by id, or by a property when the id is no object id.
	 *
	 * @param string      $schema           The schema.
	 * @param string      $id               The id.
	 * @param string|null $fallbackProperty The property to match when the id is not an object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(string $schema, string $id, ?string $fallbackProperty = null): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$scoped = $this->objectService->setRegister($this->register())->setSchema($schema);
			return ObjectIdentifier::findOne(scoped: $scoped, id: $id, fallbackProperty: $fallbackProperty);
		} catch (Throwable $e) {
			return null;
		}
	}//end find()

	/**
	 * The moment now, ISO 8601 in UTC.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->time->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
