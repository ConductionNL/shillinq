<?php

/**
 * Object Request Receipt Mailer
 *
 * Mails the debtor a receipt when a payment request on an object is settled,
 * by the provider, by money recorded by hand or by the bank (REQ-ORS-005,
 * design D5). The `paymentReceived` notification tells the finance group and
 * the object's managers; the person who paid is often not a Nextcloud user,
 * so the mail goes through IMailer, as PaymentRequestActionController::send()
 * mails a payment link. The text is in the instance's default language.
 *
 * Delivery is best-effort: a failure is logged and answered false, never
 * thrown, because the request is settled and stays settled.
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
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the debtor's receipt for a settled object payment request.
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
 */
class ObjectRequestReceiptMailer {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud's mailer.
	 * @param IFactory $l10nFactory Translations, read in the instance's default language.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail the receipt for one settled object request to its debtor.
	 *
	 * @param array<string, mixed> $request The settled PaymentRequest, as saved.
	 *
	 * @return bool True when the mailer accepted the mail; false when there was
	 *              nothing to send or sending failed.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function send(array $request): bool {
		$debtor = ($request['debtor'] ?? null);
		if ((string)($request['subjectKind'] ?? '') !== 'object'
			|| (string)($request['settledAt'] ?? '') === ''
			|| is_array($debtor) === false
		) {
			return false;
		}

		$email = trim((string)($debtor['email'] ?? ''));
		if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
			return false;
		}

		$l10n = $this->l10nFactory->get('shillinq', $this->l10nFactory->findGenericLanguage('shillinq'));

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email => (string)($debtor['name'] ?? $email)]);
			$message->setSubject($l10n->t('Payment received'));
			$message->setPlainBody($this->body(request: $request, l10n: $l10n));
			$this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq: could not mail the receipt for a settled payment request',
				['request' => (string)($request['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end send()

	/**
	 * The receipt text: what was paid, how much, when, the reference and the
	 * confirmation summary.
	 *
	 * @param array<string, mixed> $request The settled PaymentRequest.
	 * @param IL10N $l10n The translations to write in.
	 *
	 * @return string The plain-text body.
	 */
	private function body(array $request, IL10N $l10n): string {
		$what = (string)($request['description'] ?? '');
		if ($what === '') {
			$what = $l10n->t('Your payment');
		}

		$amount = sprintf(
			'%s %s',
			(string)($request['currency'] ?? 'EUR'),
			number_format((float)($request['amount'] ?? 0), 2, ',', '.')
		);
		$lines = [
			$l10n->t('We received your payment.'),
			'',
			$what,
			$l10n->t('Amount: %s', [$amount]),
			$l10n->t('Paid on: %s', [substr((string)$request['settledAt'], 0, 10)]),
		];

		$reference = (string)($request['paymentReference'] ?? '');
		if ($reference !== '') {
			$lines[] = $l10n->t('Reference: %s', [$reference]);
		}

		$summary = (string)($request['confirmationSummary'] ?? '');
		if ($summary !== '') {
			$lines[] = $summary;
		}

		$lines[] = '';
		$lines[] = $l10n->t('Keep this mail as your receipt.');

		return implode("\n", $lines)."\n";
	}//end body()
}//end class
