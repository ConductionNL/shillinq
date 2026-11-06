<?php

/**
 * Payment Plan Mailer
 *
 * Mails the customer the agreed schedule when a plan is activated, and the
 * notice that the arrangement ended when a missed instalment breaks it
 * (receivables-payment-plans design.md D5, D6). It sends through Nextcloud's
 * IMailer, the way ConfirmationMailer does, because sales-invoice-sending has
 * no mail path of its own at HEAD. Delivery is best effort: send() answers
 * whether the mailer accepted the mail, and a failure is logged, not thrown.
 *
 * @category PaymentPlan
 * @package  OCA\Shillinq\PaymentPlan
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

namespace OCA\Shillinq\PaymentPlan;

use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds and sends the payment plan mails.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class PaymentPlanMailer {
	/**
	 * Constructor.
	 *
	 * @param IMailer         $mailer Nextcloud's mailer.
	 * @param IL10N           $l10n   The app's translations.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The confirmation: covered invoices, schedule, IBAN and reference.
	 *
	 * @param array<string,mixed>            $plan        The plan.
	 * @param array<int,array<string,mixed>> $instalments The schedule.
	 * @param array<int,array<string,mixed>> $invoices    The covered invoices.
	 * @param string                         $iban        The account to pay into.
	 *
	 * @return array{subject:string,body:string}
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function confirmation(array $plan, array $instalments, array $invoices, string $iban): array {
		$reference = (string)($plan['paymentReference'] ?? '');
		$lines = [
			$this->l10n->t('Dear %s,', [$this->customerName(plan: $plan)]),
			'',
			$this->l10n->t('You agreed to pay the invoices below in instalments. This is the schedule.'),
			'',
			$this->l10n->t('Invoices:'),
		];
		foreach ($invoices as $invoice) {
			$lines[] = '- ' . (string)($invoice['invoiceNumber'] ?? '') . ': ' . $this->money(amount: (float)($invoice['amountDue'] ?? 0));
		}

		$lines[] = '';
		$lines[] = $this->l10n->t('Instalments:');
		foreach ($instalments as $instalment) {
			$lines[] = sprintf(
				'%d. %s: %s',
				(int)($instalment['instalmentNumber'] ?? 0),
				(string)($instalment['dueDate'] ?? ''),
				$this->money(amount: (float)($instalment['amount'] ?? 0))
			);
		}

		$lines[] = '';
		$lines[] = $this->l10n->t('Total: %s', [$this->money(amount: (float)($plan['totalAmount'] ?? 0))]);
		$lines[] = $this->l10n->t('Pay each instalment to %1$s and state the reference %2$s.', [$iban, $reference]);
		$lines[] = $this->l10n->t('Reminders for these invoices stop while you keep to the schedule.');
		$lines[] = $this->l10n->t('If an instalment is more than %s days late, the arrangement ends.', [(string)(int)($plan['graceDays'] ?? 14)]);

		return [
			'subject' => $this->l10n->t('Payment plan %s: your schedule', [$reference]),
			'body' => implode("\n", $lines),
		];

	}//end confirmation()

	/**
	 * The notice that the arrangement ended.
	 *
	 * @param array<string,mixed> $plan   The plan.
	 * @param string              $reason Why it ended.
	 *
	 * @return array{subject:string,body:string}
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-3.1
	 */
	public function ended(array $plan, string $reason): array {
		$reference = (string)($plan['paymentReference'] ?? '');
		$lines = [
			$this->l10n->t('Dear %s,', [$this->customerName(plan: $plan)]),
			'',
			$this->l10n->t('Your payment plan %s has ended.', [$reference]) . ' ' . $reason,
			$this->l10n->t('The open amount of the invoices is due again, and reminders resume.'),
			$this->l10n->t('Paid so far: %s.', [$this->money(amount: (float)($plan['paidAmount'] ?? 0))]),
		];

		return [
			'subject' => $this->l10n->t('Payment plan %s has ended', [$reference]),
			'body' => implode("\n", $lines),
		];

	}//end ended()

	/**
	 * Send a composed mail.
	 *
	 * @param string                           $recipient The address.
	 * @param array{subject:string,body:string} $mail      The mail.
	 *
	 * @return bool Whether the mailer accepted it.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function send(string $recipient, array $mail): bool {
		if (trim($recipient) === '') {
			$this->logger->warning('PaymentPlanMailer: the customer has no email address, nothing sent');
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject($mail['subject']);
			$message->setPlainBody($mail['body']);
			$failed = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->error('PaymentPlanMailer: mail could not be sent', ['exception' => $e->getMessage()]);
			return false;
		}

		return $failed === [];

	}//end send()

	/**
	 * The customer's name on a plan.
	 *
	 * @param array<string,mixed> $plan The plan.
	 *
	 * @return string
	 */
	private function customerName(array $plan): string {
		$name = trim((string)($plan['customerName'] ?? ''));
		if ($name === '') {
			return $this->l10n->t('customer');
		}

		return $name;

	}//end customerName()

	/**
	 * An amount as EUR 1,234.56.
	 *
	 * @param float $amount The amount.
	 *
	 * @return string
	 */
	private function money(float $amount): string {
		return 'EUR ' . number_format($amount, 2, '.', ',');

	}//end money()
}//end class
