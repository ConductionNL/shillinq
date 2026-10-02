<?php

/**
 * Payment Receipt Mailer
 *
 * Mails the debtor a receipt when shillinq has booked a captured payment on
 * an object request (REQ-SOPR-012). The `paymentReceived` notification tells
 * the finance group and the object's managers; the person who paid was told
 * nothing. It sends through Nextcloud's IMailer, the way
 * PaymentRequestActionController::send() mails a payment link.
 *
 * Delivery is best-effort: a failure is logged and answered false, never
 * thrown, because the payment is already booked and must stay booked.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payment-request-debtor-receipt/specs/object-payment-requests/spec.md (REQ-SOPR-012)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the debtor's receipt for a captured object payment request.
 *
 * @spec openspec/changes/payment-request-debtor-receipt/specs/object-payment-requests/spec.md (REQ-SOPR-012)
 */
class PaymentReceiptMailer {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud's mailer.
	 * @param IL10N $l10n Shillinq's translations, in the instance language when nobody is signed in.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail the receipt for one captured request to its debtor.
	 *
	 * @param array<string, mixed> $request The captured PaymentRequest, as saved.
	 *
	 * @return bool True when the mailer accepted the mail; false when there was
	 *              nothing to send or sending failed.
	 *
	 * @spec openspec/changes/payment-request-debtor-receipt/specs/object-payment-requests/spec.md (REQ-SOPR-012)
	 */
	public function send(array $request): bool {
		$debtor = ($request['debtor'] ?? null);
		if (($request['state'] ?? '') !== 'captured' || is_array($debtor) === false) {
			return false;
		}

		$email = trim((string)($debtor['email'] ?? ''));
		if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email => (string)($debtor['name'] ?? $email)]);
			$message->setSubject($this->l10n->t('Payment received'));
			$message->setPlainBody($this->body(request: $request));
			$this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq: could not mail the receipt for a captured payment request',
				['request' => (string)($request['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end send()

	/**
	 * The receipt text: what was paid, how much, when, and the reference.
	 *
	 * @param array<string, mixed> $request The captured PaymentRequest.
	 *
	 * @return string The plain-text body.
	 */
	private function body(array $request): string {
		$what = (string)($request['description'] ?? '');
		if ($what === '') {
			$what = $this->l10n->t('Your payment');
		}

		$amount = sprintf(
			'%s %s',
			(string)($request['currency'] ?? 'EUR'),
			number_format((float)($request['amount'] ?? 0), 2, ',', '.')
		);
		$date = substr((string)($request['capturedAt'] ?? gmdate('Y-m-d')), 0, 10);
		$reference = (string)($request['settlementReference'] ?? ($request['paymentIntentId'] ?? ''));

		return $this->l10n->t('We received your payment.')."\n\n"
			.$what."\n"
			.$this->l10n->t('Amount: %s', [$amount])."\n"
			.$this->l10n->t('Paid on: %s', [$date])."\n"
			.$this->l10n->t('Reference: %s', [$reference])."\n\n"
			.$this->l10n->t('Keep this mail as your receipt.')."\n";
	}//end body()
}//end class
